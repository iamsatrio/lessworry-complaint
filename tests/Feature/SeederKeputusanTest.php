<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserAudit;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Dua keputusan API-57, sebagaimana dijatuhkan di API-131.
 *
 * Keputusan pertama pernah berbunyi sebaliknya: peran di daftar seeder adalah
 * deklarasi yang berlaku tiap deploy, dan pengembaliannya cukup dicatat di
 * jejak audit. API-131 membalikkannya, dan alasannya bukan selera:
 *
 * 1. `role` hanya disetel saat akun DIBUAT, sama seperti `is_active`. Keduanya
 *    satu-satunya cara mencabut akses di sistem yang tidak pernah menghapus
 *    akun — melindungi satu saja berarti pagar yang ada pintunya. Penurunan
 *    peran yang sengaja tidak boleh dibatalkan deploy; mengubah peran akun
 *    yang sudah ada tetap bisa lewat halaman Pengguna, dan itu berjejak.
 * 2. `kasir@`, `produksi@`, dan `kurir@` di `lessworry.id` TIDAK lagi diblokir
 *    permanen. Ketiganya alamat kerja yang paling mungkin dipilih Admin, dan
 *    memblokirnya berarti akun kasir sungguhan mati tiap deploy. Yang tetap
 *    mati di alamat itu hanya akun yang masih memegang password bocor.
 *    Yang `@getnada.com` tetap diblokir tanpa syarat.
 */
class SeederKeputusanTest extends TestCase
{
    use RefreshDatabase;

    /* ---------- 1. Peran dilindungi seperti `is_active` ---------- */

    public function test_peran_yang_diturunkan_tidak_dikembalikan_seeder(): void
    {
        $this->seed(DatabaseSeeder::class);

        $tsulasa = User::where('email', 'tsulasa@lessworry.id')->firstOrFail();
        $this->assertSame('admin', $tsulasa->role);

        // Admin mencabut hak admin lewat halaman Pengguna — orangnya pindah
        // tugas, dan itu keputusan manusia yang berumur.
        $tsulasa->forceFill(['role' => 'supervisor'])->save();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            'supervisor',
            $tsulasa->fresh()->role,
            'Deploy mengembalikan peran tertinggi di sistem tanpa ada yang memutuskan begitu.'
        );
    }

    /**
     * Perlindungannya tentang MENIMPA, bukan tentang berhenti membuat: akun
     * yang belum ada tetap dibuat dengan peran dari daftar.
     */
    public function test_akun_yang_belum_ada_tetap_dibuat_dengan_peran_dari_daftar(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame('admin', User::where('email', 'tsulasa@lessworry.id')->firstOrFail()->role);
        $this->assertSame('customer_care', User::where('email', 'care@lessworry.id')->firstOrFail()->role);
    }

    /**
     * Jejak `peran_disetel_ulang_seeder` ada karena seeder dulu menimpa peran.
     * Sekarang ia tidak menimpanya, jadi tidak ada lagi yang perlu dicatat —
     * baris jejak baru berarti penimpaannya kembali lewat pintu lain.
     */
    public function test_seeder_tidak_menulis_jejak_pengembalian_peran(): void
    {
        $this->seed(DatabaseSeeder::class);

        User::where('email', 'tsulasa@lessworry.id')->firstOrFail()
            ->forceFill(['role' => 'supervisor'])->save();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            0,
            UserAudit::where('action', 'peran_disetel_ulang_seeder')->count(),
            'Seeder masih menimpa peran — jejaknya buktinya.'
        );
    }

    /* ---------- 2. Tiga alamat kerja keluar dari daftar blokir ---------- */

    /**
     * Buffon menjalankan akibat versi lama, bukan menduganya: Admin membuat
     * akun Kasir Tebet dengan alamat yang README-nya sendiri anjurkan, deploy
     * berikutnya mematikannya, dan kasirnya baru tahu pagi berikutnya.
     */
    public function test_akun_kasir_di_alamat_kerja_tetap_hidup_dan_passwordnya_utuh(): void
    {
        $this->seed(DatabaseSeeder::class);

        $kasir = User::create([
            'name' => 'Kasir Tebet', 'email' => 'kasir@lessworry.id',
            'password' => 'PasswordPilihanSendiri123', 'role' => 'kasir',
            'is_active' => true, 'must_change_password' => false,
        ]);
        $hashSebelum = $kasir->password;

        $this->seed(DatabaseSeeder::class);

        $kasir = $kasir->fresh();

        $this->assertTrue((bool) $kasir->is_active, 'Kasir Tebet mati pada deploy berikutnya.');
        $this->assertSame($hashSebelum, $kasir->password, 'Password kasir dibuang oleh seeder.');
        $this->assertFalse((bool) $kasir->must_change_password);
        $this->assertSame('kasir', $kasir->role);
    }

    /** Ketiganya, bukan cuma `kasir@` — ketiganya sama-sama alamat kerja. */
    public function test_ketiga_alamat_kerja_tidak_lagi_dimatikan_seeder(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach ([
            ['produksi@lessworry.id', 'divisi', 'produksi'],
            ['kurir@lessworry.id', 'divisi', 'kurir'],
        ] as [$email, $peran, $divisi]) {
            User::create([
                'name' => 'Akun '.$divisi, 'email' => $email,
                'password' => 'PasswordPilihanSendiri123', 'role' => $peran,
                'division' => $divisi, 'is_active' => true, 'must_change_password' => false,
            ]);
        }

        $this->seed(DatabaseSeeder::class);

        foreach (['produksi@lessworry.id', 'kurir@lessworry.id'] as $email) {
            $user = User::where('email', $email)->firstOrFail();

            $this->assertTrue((bool) $user->is_active, $email.' mati pada deploy berikutnya.');
            $this->assertTrue(
                Hash::check('PasswordPilihanSendiri123', $user->password),
                'Password '.$email.' dibuang oleh seeder.'
            );
        }
    }

    /**
     * Yang dicabut tiga alamat, bukan aturan password bocornya. Akun demo
     * seeder paling awal memakai alamat yang sama dan password harfiah
     * `password` yang ada di riwayat commit publik — ia tetap harus mati,
     * karena yang berbahaya passwordnya, bukan alamatnya.
     */
    public function test_akun_lama_di_alamat_itu_tetap_mati_kalau_password_bocornya_masih_berlaku(): void
    {
        $lama = User::create([
            'name' => 'Kasir Pusat', 'email' => 'kasir@lessworry.id',
            'password' => 'password', 'role' => 'kasir', 'is_active' => true,
        ]);

        $this->seed(DatabaseSeeder::class);

        $lama = $lama->fresh();

        $this->assertNotNull($lama, 'Akun dihapus — jejak audit complaint yang disentuhnya ikut hilang.');
        $this->assertFalse((bool) $lama->is_active, 'Akun berpassword bocor masih hidup.');
        $this->assertFalse(Hash::check('password', $lama->password), 'Password bocor masih berlaku.');
    }

    /** `getnada.com` kotak surat publik: diblokir tanpa syarat, apa pun passwordnya. */
    public function test_alamat_getnada_tetap_diblokir_tanpa_syarat(): void
    {
        $user = User::create([
            'name' => 'Kasir', 'email' => 'kasir@getnada.com',
            'password' => 'PasswordPilihanSendiri123', 'role' => 'kasir', 'is_active' => true,
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertFalse((bool) $user->fresh()->is_active);
        $this->assertFalse(Hash::check('PasswordPilihanSendiri123', $user->fresh()->password));
    }

    /**
     * README-nya yang menyuruh Admin memakai alamat kerja, jadi ia tidak boleh
     * lagi memuat larangan yang dulu membantahnya sendiri.
     */
    public function test_readme_tidak_lagi_melarang_ketiga_alamat_kerja(): void
    {
        $readme = (string) file_get_contents(base_path('README.md'));

        $this->assertStringNotContainsString('Jangan memakai `kasir@lessworry.id`', $readme,
            'README masih melarang alamat yang sudah tidak diblokir seeder.');

        // Bunyi peringatan lama, kata per kata. Yang dicari larangannya yang
        // masih berlaku — menyebut blokir itu sebagai riwayat justru benar.
        $this->assertStringNotContainsString('diblokir permanen: seeder', $readme,
            'README masih menyatakan alamat kerja diblokir tiap kali seeder jalan.');

        $this->assertStringContainsString('API-131', $readme,
            'README belum menyebut keputusan yang mencabut blokirnya.');
    }
}
