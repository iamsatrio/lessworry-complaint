<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\PembayaranTagihan;
use App\Models\Tagihan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Nilai pengguna tidak boleh berakhir di dalam literal string JavaScript.
 * (API-118)
 *
 * Blade meng-escape untuk HTML. Di dalam atribut `onsubmit` itu tidak cukup:
 * parser HTML men-decode entitasnya KEMBALI sebelum isinya diserahkan ke JS.
 * `&#039;` jadi `'`, dan literal `confirm('...')` jebol di situ.
 *
 * Karena itu test ini tidak memeriksa tampilan. `assertSee` akan lulus pada
 * kode yang bocor maupun yang aman — yang salah bukan apa yang terlihat,
 * melainkan di konteks mana escape-nya dilakukan. Yang diperiksa: sumber JS
 * hasil decode, persis seperti yang akan dijalankan peramban.
 */
class KonteksEscapeJsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Nama yang menutup literalnya, menyisipkan panggilan baru, lalu
     * mengomentari sisa barisnya supaya sintaksnya tetap sah.
     */
    private const NAKAL = "Listrik'); alert(1); //</script>";

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-01 03:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    /**
     * Ambil setiap atribut `onsubmit`, decode seperti parser HTML, lalu
     * pastikan sumber JS-nya tidak disusun dari nilai pengguna sama sekali.
     *
     * Bentuk yang diterima cuma satu: membaca teksnya dari atribut data.
     * Di situ `{{ }}` bekerja, karena konteksnya memang HTML.
     */
    private function assertOnsubmitTidakMerakitJs(string $html, string $halaman): void
    {
        preg_match_all('/onsubmit="([^"]*)"/', $html, $cocok);

        $this->assertNotEmpty(
            $cocok[1],
            "Tidak ada atribut onsubmit di {$halaman} — pola penangkapnya tidak lagi cocok, "
            .'bukan halamannya yang bersih.'
        );

        foreach ($cocok[1] as $mentah) {
            // Bukan teks mentah ini yang dieksekusi, melainkan hasil decode-nya.
            $js = html_entity_decode($mentah, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $this->assertStringNotContainsString(
                "'); ",
                $js,
                "Literal string JS di onsubmit {$halaman} tertutup lebih awal oleh nilai pengguna: {$js}"
            );

            $this->assertMatchesRegularExpression(
                '/^return confirm\(this\.dataset\.[A-Za-z][A-Za-z0-9]*\)$/',
                $js,
                "onsubmit di {$halaman} masih merakit sendiri sumber JS-nya: {$js}"
            );
        }
    }

    /**
     * Teksnya harus tetap sampai ke pengguna. Tanpa ini, menghapus confirm-nya
     * ikut membuat test di atas hijau.
     */
    private function assertTeksKonfirmasiMasihAda(string $html, string $halaman): void
    {
        preg_match_all('/data-konfirmasi="([^"]*)"/', $html, $cocok);

        $this->assertNotEmpty($cocok[1], "Tidak ada teks konfirmasi tersisa di {$halaman}.");

        $adaNamanya = false;

        foreach ($cocok[1] as $mentah) {
            $teks = html_entity_decode($mentah, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (str_contains($teks, self::NAKAL)) {
                $adaNamanya = true;
            }
        }

        $this->assertTrue(
            $adaNamanya,
            "Teks konfirmasi di {$halaman} tidak lagi menyebut nama yang ditanyakan."
        );
    }

    public function test_daftar_pengguna_tidak_menaruh_nama_di_dalam_string_js(): void
    {
        User::create([
            'name' => self::NAKAL, 'email' => 'nakal@lessworry.id',
            'password' => 'secret123', 'role' => 'kasir',
        ]);

        $html = $this->actingAs($this->userAs('admin'))->get('/users')->assertOk()->getContent();

        $this->assertOnsubmitTidakMerakitJs($html, '/users');
        $this->assertTeksKonfirmasiMasihAda($html, '/users');
    }

    public function test_ubah_pengguna_tidak_menaruh_nama_di_dalam_string_js(): void
    {
        $target = User::create([
            'name' => self::NAKAL, 'email' => 'nakal2@lessworry.id',
            'password' => 'secret123', 'role' => 'kasir',
        ]);

        // Blok verifikasi manual hanya dirender selama emailnya belum terverifikasi.
        $this->assertFalse($target->hasVerifiedEmail());

        $html = $this->actingAs($this->userAs('admin'))
            ->get('/users/'.$target->id.'/edit')->assertOk()->getContent();

        $this->assertOnsubmitTidakMerakitJs($html, '/users/{id}/edit');
        $this->assertTeksKonfirmasiMasihAda($html, '/users/{id}/edit');
    }

    public function test_daftar_tagihan_tidak_menaruh_nama_di_dalam_string_js(): void
    {
        $admin = $this->userAs('admin');

        $tagihan = Tagihan::create([
            'nama' => self::NAKAL,
            'jumlah' => 1200000,
            'pengulangan' => 'bulanan',
            'jatuh_tempo_hari' => 5,
        ]);

        // Dua onsubmit di halaman ini, dan yang satu hanya muncul kalau ada
        // penandaan yang bisa dibatalkan.
        PembayaranTagihan::create([
            'tagihan_id' => $tagihan->id,
            'periode' => '2026-10',
            'user_id' => $admin->id,
            'ditandai_pada' => Carbon::now(),
        ]);

        $html = $this->actingAs($admin)->get('/tagihan')->assertOk()->getContent();

        $this->assertStringContainsString('Batalkan penandaan terakhir', $html);

        $this->assertOnsubmitTidakMerakitJs($html, '/tagihan');
        $this->assertTeksKonfirmasiMasihAda($html, '/tagihan');
    }
}
