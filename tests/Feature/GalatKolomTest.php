<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Galat form tidak pernah sampai ke kolomnya.
 *
 * Sebelum ini, satu-satunya penanda galat adalah ringkasan di puncak halaman.
 * Terukur di 390px pada halaman complaint: ringkasan di y=152, select alasan
 * penutupan yang menyebabkannya di y=4312 — petugas membaca "Sebutkan
 * complaint ini ditutup karena selesai atau ditolak", lalu menggulir empat
 * layar menebak kolom mana yang dimaksud.
 *
 * NN/g menyebutnya langsung: ringkasan validasi tidak boleh dipakai sebagai
 * satu-satunya penanda galat. Yang dijaga di sini ada tiga: pesan ada di
 * kolomnya, kalimatnya SAMA PERSIS dengan yang di ringkasan (aturan GOV.UK
 * Error Summary), dan kontrolnya menunjuk pesan itu lewat aria-describedby.
 * (API-86 #1, #2)
 */
class GalatKolomTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    private function complaint(string $bobot = 'sedang'): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'P', 'category' => 'kurang_bersih',
            'bobot' => $bobot, 'layanan' => 'kiloan', 'status' => 'open', 'description' => 'x',
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->created_at = now();
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    /** Pesan di kolom dan pesan di ringkasan harus kalimat yang sama. */
    private function assertPesanSama(string $html, string $idKolom): string
    {
        preg_match('/<p class="err-field" id="'.preg_quote($idKolom, '/').'-error">(.*?)<\/p>/s', $html, $kolom);

        $this->assertNotEmpty($kolom, "Tidak ada pesan galat di kolom #$idKolom.");

        $pesan = trim($kolom[1]);

        preg_match('/<div class="err" id="galat-ringkas".*?<\/ul>/s', $html, $ringkas);
        $this->assertNotEmpty($ringkas, 'Ringkasan galat hilang.');
        $this->assertStringContainsString(
            $pesan,
            $ringkas[0],
            "Kalimat di kolom #$idKolom berbeda dari kalimat di ringkasan."
        );

        return $pesan;
    }

    /* ---------- 1. Penanda galat di tingkat kolom ---------- */

    public function test_alasan_penutupan_yang_kosong_dilaporkan_di_kolomnya(): void
    {
        $cc = $this->userAs('customer_care');
        $complaint = $this->complaint();

        $html = $this->actingAs($cc)
            ->from('/complaints/'.$complaint->id)
            ->followingRedirects()
            ->post('/complaints/'.$complaint->id.'/status', [
                'status' => 'close',
                'close_reason' => '',
                'lock_version' => $complaint->lock_version,
            ])
            ->assertOk()
            ->getContent();

        $this->assertPesanSama($html, 'cr');

        // Kontrolnya menunjuk pesannya, bukan sekadar berdiri di dekatnya.
        $this->assertMatchesRegularExpression(
            '/<select id="cr"[^>]*aria-invalid="true"[^>]*aria-describedby="cr-error"/s',
            $html,
            'Select alasan penutupan tidak menunjuk pesan galatnya.'
        );
    }

    public function test_kolom_intake_yang_kosong_dilaporkan_di_kolomnya_masing_masing(): void
    {
        $kasir = $this->userAs('kasir', Outlet::create(['name' => 'Outlet Uji', 'is_active' => true]));

        $html = $this->actingAs($kasir)
            ->from('/complaints/create')
            ->followingRedirects()
            ->post('/complaints', ['nevira_transaction_number' => 'INV/1/2/3'])
            ->assertOk()
            ->getContent();

        foreach (['cat', 'bob', 'lay', 'desc', 'rn'] as $idKolom) {
            $this->assertPesanSama($html, $idKolom);
        }
    }

    public function test_kolom_yang_benar_tidak_ikut_ditandai_salah(): void
    {
        $kasir = $this->userAs('kasir', Outlet::create(['name' => 'Outlet Uji', 'is_active' => true]));

        $html = $this->actingAs($kasir)
            ->from('/complaints/create')
            ->followingRedirects()
            ->post('/complaints', [
                'nevira_transaction_number' => 'INV/1/2/3',
                'reporter_name' => 'Pelapor Uji',
            ])->assertOk()->getContent();

        $this->assertStringNotContainsString('id="rn-error"', $html,
            'Kolom nama pelapor yang sudah benar ikut ditandai salah.');
        $this->assertMatchesRegularExpression('/<input id="rn"(?![^>]*aria-invalid)/', $html);
    }

    public function test_password_yang_ditolak_dilaporkan_di_kolomnya(): void
    {
        $user = $this->userAs('kasir');

        $html = $this->actingAs($user)
            ->from('/password')
            ->followingRedirects()
            ->put('/password', [
                'current_password' => 'secret123',
                'password' => 'pendek1',
                'password_confirmation' => 'pendek1',
            ])->assertOk()->getContent();

        $this->assertPesanSama($html, 'np');
    }

    /* ---------- 2. Ringkasan diumumkan dan menerima fokus ---------- */

    public function test_ringkasan_galat_diumumkan_dan_menerima_fokus(): void
    {
        $kasir = $this->userAs('kasir', Outlet::create(['name' => 'Outlet Uji', 'is_active' => true]));

        $html = $this->actingAs($kasir)->from('/complaints/create')
            ->followingRedirects()->post('/complaints', [])->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<div class="err" id="galat-ringkas"[^>]*role="alert"[^>]*tabindex="-1"/',
            $html,
            'Ringkasan galat kembali jadi div polos: pembaca layar tidak mengumumkan apa pun.'
        );
        $this->assertStringContainsString(
            "document.getElementById('galat-ringkas').focus()",
            $html,
            'Fokus tidak dipindahkan ke ringkasan, jadi penyimpanan yang gagal lewat tanpa tanda.'
        );
    }

    public function test_butir_ringkasan_menautkan_ke_kolomnya(): void
    {
        $kasir = $this->userAs('kasir', Outlet::create(['name' => 'Outlet Uji', 'is_active' => true]));

        $html = $this->actingAs($kasir)->from('/complaints/create')
            ->followingRedirects()->post('/complaints', [])->assertOk()->getContent();

        preg_match('/<div class="err" id="galat-ringkas".*?<\/ul>/s', $html, $ringkas);

        foreach (['cat', 'bob', 'lay', 'desc', 'rn'] as $idKolom) {
            $this->assertStringContainsString('href="#'.$idKolom.'"', $ringkas[0],
                "Butir ringkasan untuk #$idKolom tidak menautkan ke kolomnya.");
        }
    }

    /**
     * Halaman tanpa galat tidak boleh menyisakan skrip yang memanggil elemen
     * yang tidak ada — itu menghentikan SELURUH skrip di halaman itu.
     */
    public function test_halaman_tanpa_galat_tidak_menyisakan_skrip_ringkasan(): void
    {
        $kasir = $this->userAs('kasir', Outlet::create(['name' => 'Outlet Uji', 'is_active' => true]));

        $html = $this->actingAs($kasir)->get('/complaints/create')->assertOk()->getContent();

        $this->assertStringNotContainsString('galat-ringkas', $html);
    }

    /* ---------- 3. Ruang gulir untuk fokus di balik tombol melayang ---------- */

    public function test_gulir_fokus_menyisakan_ruang_untuk_tombol_melayang(): void
    {
        $css = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertMatchesRegularExpression(
            '/html\{scroll-padding-bottom:(\d+)px\}/',
            $css,
            'Fokus keyboard kembali mendarat pas di tepi bawah viewport — tepat di balik .fab.'
        );

        preg_match('/html\{scroll-padding-bottom:(\d+)px\}/', $css, $m);
        $this->assertGreaterThanOrEqual(65, (int) $m[1],
            'Ruangnya lebih kecil dari tinggi tombol melayang (49px) ditambah jaraknya dari dasar (16px).');

        // Aturannya harus berada di dalam media query yang sama dengan .fab:
        // di desktop tombolnya tidak ada, jadi ruangnya juga tidak perlu ada.
        preg_match('/@media\(max-width:820px\)\{\s*\.fab\{.*?\n\}/s', $css, $blok);
        $this->assertNotEmpty($blok, 'Blok media .fab tidak ditemukan.');
        $this->assertStringContainsString('scroll-padding-bottom', $blok[0],
            'scroll-padding-bottom dipasang di luar media query .fab.');
    }
}
