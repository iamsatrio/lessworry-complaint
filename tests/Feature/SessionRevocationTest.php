<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Ganti password harus benar-benar memutus sesi di perangkat lain.
 * (API-14 #2)
 *
 * Auth::logoutOtherDevices() mandul tanpa middleware AuthenticateSession di
 * tumpukan: hash password di sesi lain tidak pernah dibandingkan dengan yang
 * baru, jadi sesi itu hidup terus. Klaim "mengganti password memutus sesi di
 * perangkat lain" pernah ditulis sebagai selesai padahal tidak berlaku.
 */
class SessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    private function kasir(): User
    {
        return User::create([
            'name' => 'Kasir', 'email' => 'k'.uniqid().'@lessworry.id',
            'password' => 'lama12345', 'role' => 'kasir',
        ]);
    }

    public function test_middleware_pemeriksa_sesi_terpasang_di_rute_terlindungi(): void
    {
        foreach (['dashboard', 'complaints.index', 'password.edit'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

            $this->assertContains('auth.session', $middleware,
                'Rute '.$name.' tidak memeriksa hash password di sesi, jadi '
                .'Auth::logoutOtherDevices() tidak berlaku untuknya.');
        }
    }

    public function test_sesi_dengan_hash_password_basi_ditolak(): void
    {
        $user = $this->kasir();

        $this->actingAs($user)
            ->withSession(['password_hash_web' => Hash::make('password-lama-yang-sudah-diganti')])
            ->get('/dashboard')
            ->assertRedirect('/login');
    }

    public function test_ganti_password_memutus_sesi_di_perangkat_lain(): void
    {
        $user = $this->kasir();
        $hashLama = $user->password;

        // Perangkat A: sesi hidup, hash password tersimpan di sesinya.
        $this->actingAs($user)->get('/dashboard')->assertOk();

        // Perangkat B: pemiliknya mengganti password.
        $this->actingAs($user->fresh())->put('/password', [
            'current_password' => 'lama12345',
            'password' => 'baru123456',
            'password_confirmation' => 'baru123456',
        ])->assertRedirect(route('dashboard'));

        $this->assertNotSame($hashLama, $user->fresh()->password);

        // Perangkat A masih memegang hash lama.
        $this->actingAs($user->fresh())
            ->withSession(['password_hash_web' => $hashLama])
            ->get('/dashboard')
            ->assertRedirect('/login');
    }

    public function test_reset_password_oleh_supervisor_memutus_sesi_pegawai(): void
    {
        $supervisor = User::create([
            'name' => 'SV', 'email' => 'sv'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'supervisor',
        ]);
        $pegawai = $this->kasir();
        $hashLama = $pegawai->password;

        $this->actingAs($pegawai)->get('/dashboard')->assertOk();

        // Perangkat supervisor adalah sesi lain: jangan bawa hash milik
        // pegawai dari permintaan di atas.
        $this->flushSession();

        $this->actingAs($supervisor)->post('/users/'.$pegawai->id.'/reset-password')
            ->assertRedirect(route('users.index'));

        // Pegawai yang baru keluar tetap memegang sesi lamanya di HP-nya.
        $this->actingAs($pegawai->fresh())
            ->withSession(['password_hash_web' => $hashLama])
            ->get('/dashboard')
            ->assertRedirect('/login');
    }

    public function test_sesi_yang_hashnya_masih_cocok_tidak_diganggu(): void
    {
        $user = $this->kasir();

        $this->actingAs($user)
            ->withSession(['password_hash_web' => $user->password])
            ->get('/dashboard')
            ->assertOk();
    }
}
