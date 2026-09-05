<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seluruh antarmuka aplikasi ini berbahasa Indonesia dan nama kolomnya sudah
 * Indonesia, tapi tidak ada direktori `lang/` sama sekali — jadi setiap aturan
 * validasi yang pesannya tidak ditulis tangan keluar setengah Inggris: "The
 * nomor nota field must be at least 6 characters."
 *
 * Yang paling merugikan muncul pada login pertama pegawai baru, saat password
 * sementara wajib diganti: kesan pertamanya terhadap sistem ini adalah pesan
 * galat setengah Inggris. (API-38 #8)
 */
class BahasaValidasiTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role): User
    {
        $outlet = Outlet::create(['name' => 'Outlet Uji', 'is_active' => true]);

        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role,
            'outlet_id' => $role === 'kasir' ? $outlet->id : null,
        ]);
    }

    /**
     * Bawaannya `id`, bukan `en`: server yang `.env`-nya lupa menyetel
     * APP_LOCALE tidak boleh membalas pesan setengah Inggris ke kasir.
     */
    public function test_locale_bawaan_indonesia(): void
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

    public function test_kolom_wajib_di_form_intake_bahasa_indonesia(): void
    {
        $errors = $this->actingAs($this->userAs('kasir'))
            ->from('/complaints/create')
            ->post('/complaints', ['channel' => 'kasir', 'nota_exemption' => 'belum_terbit'])
            ->assertSessionHasErrors('category')
            ->getSession()->get('errors')->getBag('default');

        $this->assertSame('Kolom kategori wajib diisi.', $errors->first('category'));
        $this->assertSame('Kolom isi keluhan wajib diisi.', $errors->first('description'));
    }
}
