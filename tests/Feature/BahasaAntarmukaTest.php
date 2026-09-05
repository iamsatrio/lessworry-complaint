<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seluruh antarmuka aplikasi ini berbahasa Indonesia dan nama kolomnya sudah
 * Indonesia, tapi tanpa lang/id setiap aturan validasi yang pesannya tidak
 * ditulis tangan keluar setengah Inggris: "The nomor nota field must be at
 * least 6 characters." Yang paling merugikan muncul pada login pertama pegawai
 * baru, saat password sementara harus diganti. (API-38 #8)
 *
 * Ikut di sini: halaman 403 yang dulu putih polos tanpa jalan kembali
 * (API-38 #9), dan navigasi yang tetap menawarkan tiga pintu terkunci selama
 * password wajib diganti (API-38 #12).
 */
class BahasaAntarmukaTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role, bool $wajibGanti = false): User
    {
        $outlet = Outlet::create(['name' => 'Outlet Uji', 'is_active' => true]);

        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role,
            'outlet_id' => $role === 'kasir' ? $outlet->id : null,
            'must_change_password' => $wajibGanti,
        ]);
    }

    public function test_locale_bawaan_indonesia_meski_env_lupa_disetel(): void
    {
        $this->assertSame('id', config('app.locale'));
        $this->assertFileExists(lang_path('id/validation.php'));
    }

    public function test_pesan_panjang_password_bahasa_indonesia(): void
    {
        $this->actingAs($this->userAs('kasir'))
            ->from('/password')
            ->put('/password', [
                'current_password' => 'secret123',
                'password' => 'abc',
                'password_confirmation' => 'abc',
            ])
            ->assertSessionHasErrors([
                'password' => 'Kolom password baru harus diisi setidaknya 8 karakter.',
            ]);
    }

    public function test_pesan_password_tanpa_angka_bahasa_indonesia(): void
    {
        $errors = $this->actingAs($this->userAs('kasir'))
            ->from('/password')
            ->put('/password', [
                'current_password' => 'secret123',
                'password' => 'abcdefghij',
                'password_confirmation' => 'abcdefghij',
            ])
            ->assertSessionHasErrors('password')
            ->getSession()->get('errors')->getBag('default')->get('password');

        $this->assertContains('Kolom password baru harus memuat setidaknya satu angka.', $errors);
    }

    public function test_pesan_nomor_nota_terlalu_pendek_bahasa_indonesia(): void
    {
        $this->actingAs($this->userAs('kasir'))
            ->getJson('/nevira/lookup?id=abc')
            ->assertStatus(422)
            ->assertJsonPath('errors.id.0', 'Kolom nomor nota harus diisi setidaknya 6 karakter.');
    }

    public function test_halaman_403_memakai_layout_aplikasi_dan_bahasa_indonesia(): void
    {
        $this->actingAs($this->userAs('kasir'))
            ->get('/users')
            ->assertStatus(403)
            ->assertSee('Halaman ini tidak terbuka untuk peranmu')
            ->assertSee('Kembali ke Papan Kerja')
            ->assertSee('Less Worry')
            ->assertDontSee('Forbidden');
    }

    /** Pembatasannya sendiri benar dan harus tetap berlaku. */
    public function test_kasir_tetap_tidak_boleh_membuka_daftar_pengguna(): void
    {
        $this->actingAs($this->userAs('kasir'))->get('/users')->assertStatus(403);
    }

    public function test_navigasi_disembunyikan_selama_password_wajib_diganti(): void
    {
        $html = $this->actingAs($this->userAs('customer_care', wajibGanti: true))
            ->get('/password')->assertOk()->getContent();

        $this->assertStringNotContainsString('>Papan Kerja</a>', $html);
        $this->assertStringNotContainsString('>Laporan</a>', $html);
        $this->assertStringNotContainsString('>Dashboard</a>', $html);
        $this->assertStringContainsString('Ganti password dulu sebelum memakai sistem', $html);
        // Satu-satunya pintu yang memang terbuka.
        $this->assertStringContainsString('Keluar', $html);
    }

    public function test_navigasi_kembali_setelah_password_diganti(): void
    {
        $html = $this->actingAs($this->userAs('customer_care'))
            ->get('/password')->assertOk()->getContent();

        $this->assertStringContainsString('>Papan Kerja</a>', $html);
    }
}
