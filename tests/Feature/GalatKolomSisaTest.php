<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lanjutan GalatKolomTest. Di sana tiga form yang paling sering ditolak
 * validasi sudah melaporkan galatnya di kolomnya; tiga view sisanya belum —
 * halaman masuk dan dua halaman pengelolaan pengguna.
 *
 * Kriteria API-88 Bagian B mengukurnya begini: setiap view yang punya
 * <input>/<select>/<textarea> ber-`required` harus punya `@error`. Test
 * terakhir di kelas ini menjalankan pengukuran itu sebagai test, supaya view
 * berkolom berikutnya tidak bisa masuk tanpa penanda galatnya.
 *
 * Halaman masuk layak disebut sendiri: di situlah pegawai baru pertama kali
 * bertemu sistem ini, dan di situ pula galat paling sering terjadi.
 */
class GalatKolomSisaTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
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

    /** Kontrolnya menunjuk pesannya, bukan cuma berdiri di sebelahnya. */
    private function assertKontrolMenunjukPesan(string $html, string $idKontrol): void
    {
        preg_match('/<(?:input|select|textarea)[^>]*\bid="'.preg_quote($idKontrol, '/').'"[^>]*>/s', $html, $m);

        $this->assertNotEmpty($m, "Kontrol #$idKontrol tidak ditemukan.");
        $this->assertStringContainsString('aria-invalid="true"', $m[0],
            "Kontrol #$idKontrol tidak ditandai salah.");
        $this->assertStringContainsString('aria-describedby="'.$idKontrol.'-error"', $m[0],
            "Kontrol #$idKontrol tidak menunjuk pesan galatnya.");
    }

    /* ---------- Halaman masuk ---------- */

    public function test_galat_masuk_sampai_ke_kolom_email(): void
    {
        $html = $this->from('/login')->followingRedirects()
            ->post('/login', ['email' => 'bukan@ada.id', 'password' => 'salah123'])
            ->assertOk()->getContent();

        $this->assertPesanSama($html, 'email');
        $this->assertKontrolMenunjukPesan($html, 'email');
    }

    /**
     * Password yang salah dilaporkan atas nama `email` — sistem sengaja tidak
     * memberi tahu mana dari keduanya yang salah. Kolom password karena itu
     * TIDAK boleh ikut ditandai merah: menandainya mengaku bahwa emailnya
     * benar, dan itu membocorkan alamat mana yang terdaftar.
     */
    public function test_kolom_password_tidak_ikut_ditandai_saat_galat_atas_nama_email(): void
    {
        $html = $this->from('/login')->followingRedirects()
            ->post('/login', ['email' => 'bukan@ada.id', 'password' => 'salah123'])
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('id="password-error"', $html,
            'Kolom password ditandai salah padahal galatnya dilaporkan atas nama email.');
    }

    /* ---------- Tambah pengguna ---------- */

    public function test_galat_tambah_pengguna_sampai_ke_kolomnya(): void
    {
        $html = $this->actingAs($this->userAs('admin'))
            ->from('/users/create')->followingRedirects()
            ->post('/users', ['name' => '', 'email' => 'bukan-email', 'role' => 'kasir'])
            ->assertOk()->getContent();

        $this->assertPesanSama($html, 'name');
        $this->assertKontrolMenunjukPesan($html, 'name');
        $this->assertPesanSama($html, 'email');
        $this->assertKontrolMenunjukPesan($html, 'email');
    }

    /* ---------- Tandai terverifikasi ---------- */

    /**
     * Halaman Ubah Pengguna memuat dua form. Galat dari form verifikasi harus
     * mendarat di kolom `reason` milik form itu, bukan tersesat ke form ubah
     * data akun di atasnya.
     */
    public function test_alasan_verifikasi_yang_kosong_dilaporkan_di_kolomnya(): void
    {
        $admin = $this->userAs('admin');
        $kasir = $this->userAs('kasir', Outlet::create(['name' => 'Outlet Uji', 'is_active' => true]));

        $html = $this->actingAs($admin)
            ->from('/users/'.$kasir->id.'/edit')->followingRedirects()
            ->post('/users/'.$kasir->id.'/verifikasi-email', ['reason' => ''])
            ->assertOk()->getContent();

        $this->assertPesanSama($html, 'reason');
        $this->assertKontrolMenunjukPesan($html, 'reason');
    }

    /* ---------- Tagihan bulanan ---------- */

    /**
     * Dua view tagihan masuk `main` lewat PR #43, sesudah cabang ini ditulis.
     * Keduanya punya kolom wajib dan tidak punya satu pun `@error` — ketahuan
     * oleh pengukuran cakupan di bawah saat cabang ini direbase.
     */
    public function test_galat_tagihan_sampai_ke_kolomnya(): void
    {
        $admin = $this->userAs('admin');

        $html = $this->actingAs($admin)
            ->from('/tagihan/baru')->followingRedirects()
            ->post('/tagihan', [
                'nama' => '',
                'pengulangan' => 'tahunan',
                'jatuh_tempo_hari' => '99',
                'jatuh_tempo_bulan' => '',
            ])->assertOk()->getContent();

        foreach (['nama', 'jatuh_tempo_hari', 'jatuh_tempo_bulan'] as $idKolom) {
            $this->assertPesanSama($html, $idKolom);
            $this->assertKontrolMenunjukPesan($html, $idKolom);
        }
    }

    public function test_galat_ambang_pengingat_sampai_ke_kolomnya(): void
    {
        $admin = $this->userAs('admin');

        $html = $this->actingAs($admin)
            ->from('/tagihan')->followingRedirects()
            ->post('/tagihan/ambang', ['ambang_hari' => '0'])
            ->assertOk()->getContent();

        $this->assertPesanSama($html, 'ambang_hari');
        $this->assertKontrolMenunjukPesan($html, 'ambang_hari');
    }

    /* ---------- Pengukuran cakupan ---------- */

    /**
     * Kriteria API-88 Bagian B, dijalankan apa adanya: view yang punya kolom
     * wajib tapi tidak punya satu pun `@error` adalah view yang galatnya
     * hanya hidup sebagai daftar di puncak halaman.
     */
    public function test_setiap_view_berkolom_wajib_punya_penanda_galat(): void
    {
        $berkolomWajib = [];
        $berpenanda = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($iterator as $berkas) {
            if (! $berkas->isFile() || ! str_ends_with($berkas->getFilename(), '.blade.php')) {
                continue;
            }

            $isi = file_get_contents($berkas->getPathname());
            $nama = str_replace(resource_path('views').'/', '', $berkas->getPathname());

            if (preg_match('/<(input|select|textarea)[^>]*\brequired\b/s', $isi)) {
                $berkolomWajib[] = $nama;
            }
            if (str_contains($isi, '@error')) {
                $berpenanda[] = $nama;
            }
        }

        sort($berkolomWajib);

        $this->assertNotEmpty($berkolomWajib, 'Pola pencarinya tidak lagi cocok — tidak satu pun view berkolom wajib ditemukan.');

        $this->assertSame([], array_values(array_diff($berkolomWajib, $berpenanda)),
            'View di atas punya kolom wajib tapi galatnya tidak pernah sampai ke kolomnya.');
    }
}
