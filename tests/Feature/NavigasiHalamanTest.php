<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Navigasi halaman harus memakai kelas CSS aplikasi ini.
 *
 * `$complaints->links()` memakai view pagination bawaan Laravel yang ditulis
 * untuk Tailwind. Aplikasi ini tidak memuat Tailwind, jadi kelasnya tidak
 * berarti apa-apa: di layar 390px bloknya memuai jadi 755px tinggi dengan
 * ikon panah 215×215px, nomor halaman tersusun ke bawah satu per baris, dan
 * teksnya berbahasa Inggris — pada satu-satunya jalan menuju halaman 2.
 * (API-38 #2)
 */
class NavigasiHalamanTest extends TestCase
{
    use RefreshDatabase;

    private function daftar(int $jumlah = 25): string
    {
        for ($i = 0; $i < $jumlah; $i++) {
            $complaint = new Complaint([
                'channel' => 'wa_cc', 'reporter_name' => 'Pelapor '.$i, 'category' => 'kurang_bersih',
                'bobot' => 'sedang', 'layanan' => 'kiloan', 'description' => 'x',
            ]);
            $complaint->ticket_number = Complaint::nextTicketNumber();
            $complaint->status = 'open';
            $complaint->created_at = now();
            $complaint->applySla();
            $complaint->save();
        }

        $user = User::create([
            'name' => 'Supervisor', 'email' => 'sv'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'supervisor',
        ]);

        return $this->actingAs($user)->get('/complaints')->assertOk()->getContent();
    }

    public function test_view_pagination_aplikasi_yang_dipakai(): void
    {
        $this->assertStringContainsString('<nav class="pager"', $this->daftar());
    }

    public function test_tombolnya_memakai_kelas_yang_memang_ada_di_aplikasi(): void
    {
        $html = $this->daftar();

        $this->assertMatchesRegularExpression('/<a class="btn ghost" href="[^"]*page=2/', $html);
        $this->assertStringNotContainsString(
            'flex items-center',
            $html,
            'Kelas Tailwind kembali masuk ke halaman yang tidak memuat Tailwind.'
        );
    }

    public function test_teksnya_bahasa_indonesia(): void
    {
        $html = $this->daftar();
        $rapat = preg_replace('/\s+/', ' ', $html);

        $this->assertStringContainsString('Sebelumnya', $html);
        $this->assertStringContainsString('Berikutnya', $html);
        $this->assertStringContainsString('Menampilkan 1–20 dari 25 complaint', $rapat);

        foreach (['Previous', 'Next', 'Showing', 'results'] as $inggris) {
            $this->assertStringNotContainsString($inggris, $html, 'Teks '.$inggris.' kembali muncul.');
        }
    }

    /**
     * Tombol melayang "Catat Complaint" berposisi tetap di dasar layar. Tanpa
     * ruang bawah, selalu ada satu kartu complaint di baliknya pada setiap
     * posisi gulir — dan jempol yang mengarah ke kartu paling bawah justru
     * menekan "Catat Complaint". (API-38 #3)
     */
    public function test_ruang_bawah_di_hp_cukup_untuk_tombol_melayang(): void
    {
        $css = file_get_contents(resource_path('views/layouts/app.blade.php'));

        preg_match_all('/main\{padding:[^}]*\}/', $css, $m);

        $this->assertNotEmpty($m[0], 'Aturan padding main hilang.');

        // Aturan main{} TERAKHIR yang menang. Dulu aturan .fab menyetel
        // padding-bottom:104px, lalu blok media di bawahnya mengembalikannya
        // ke 60px — dan tombolnya kembali menimpa data.
        $terakhir = end($m[0]);

        $this->assertMatchesRegularExpression(
            '/padding:22px 16px 104px/',
            $terakhir,
            'Aturan main terakhir menyisakan ruang bawah kurang dari tinggi tombol melayang.'
        );
    }
}
