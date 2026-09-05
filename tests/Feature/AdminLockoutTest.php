<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Sistem tidak boleh bisa mengunci dirinya sendiri. (API-14 #1)
 *
 * Pengaman lama hanya menyala saat is_active dimatikan, jadi admin
 * aktif terakhir cukup menyimpan dirinya sebagai kasir — lewat form Ubah
 * Pengguna biasa, bukan request buatan — dan pengelolaan pengguna beku
 * untuk semua orang tanpa jalan pulih.
 */
class AdminLockoutTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $name = 'Admin'): User
    {
        return User::create([
            'name' => $name, 'email' => 'sv'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'admin',
        ]);
    }

    private function payload(User $user, array $override = []): array
    {
        return array_merge([
            'name' => $user->name,
            'role' => $user->role,
            'outlet_id' => $user->outlet_id,
            'division' => $user->division,
            'is_active' => 1,
        ], $override);
    }

    private function adminAktif(): int
    {
        return User::where('role', 'admin')->where('is_active', true)->count();
    }

    public function test_admin_aktif_terakhir_tidak_bisa_menurunkan_perannya_sendiri(): void
    {
        $sv = $this->admin();

        $this->actingAs($sv)
            ->put('/users/'.$sv->id, $this->payload($sv, ['role' => 'kasir']))
            ->assertSessionHasErrors('role');

        $this->assertSame('admin', $sv->fresh()->role);
        $this->assertSame(1, $this->adminAktif());
    }

    public function test_admin_tidak_bisa_menurunkan_admin_aktif_terakhir_lainnya(): void
    {
        $a = $this->admin('A');
        $b = $this->admin('B');

        // A menurunkan B: masih sisa satu, boleh.
        $this->actingAs($a)->put('/users/'.$b->id, $this->payload($b, ['role' => 'kasir']))
            ->assertSessionHasNoErrors();

        // A menurunkan dirinya sendiri: tidak ada lagi yang tersisa.
        $this->actingAs($a)->put('/users/'.$a->id, $this->payload($a, ['role' => 'customer_care']))
            ->assertSessionHasErrors('role');

        $this->assertSame(1, $this->adminAktif());
    }

    public function test_penonaktifan_admin_terakhir_tetap_tertahan(): void
    {
        $a = $this->admin('A');
        $b = $this->admin('B');

        // Masih ada A: menonaktifkan B boleh.
        $this->actingAs($a)->put('/users/'.$b->id, $this->payload($b, ['is_active' => 0]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->adminAktif());

        // A tinggal sendiri: penonaktifannya harus tertahan. Dicoba oleh
        // admin lain yang baru diaktifkan lagi supaya bukan penonaktifan
        // diri sendiri yang menahannya.
        $b->forceFill(['is_active' => true])->save();
        $this->actingAs($b)->put('/users/'.$b->id, $this->payload($b, ['role' => 'kasir']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->adminAktif());

        $this->actingAs($a)->put('/users/'.$a->id, $this->payload($a, ['is_active' => 0]))
            ->assertSessionHasErrors('is_active');

        $this->assertSame(1, $this->adminAktif());
    }

    public function test_menurunkan_peran_saat_masih_ada_admin_lain_tetap_boleh(): void
    {
        $a = $this->admin('A');
        $b = $this->admin('B');

        $this->actingAs($a)->put('/users/'.$b->id, $this->payload($b, ['role' => 'kasir']))
            ->assertSessionHasNoErrors();

        $this->assertSame('kasir', $b->fresh()->role);
        $this->assertSame(1, $this->adminAktif());
    }

    public function test_perintah_pemulihan_mengangkat_admin_saat_semua_terkunci(): void
    {
        $sv = $this->admin();
        // Kunci lewat basis data, meniru keadaan yang sudah terlanjur terjadi.
        $sv->forceFill(['role' => 'kasir'])->save();

        $this->assertSame(0, $this->adminAktif());

        Artisan::call('lessworry:pulihkan-admin', ['email' => $sv->email]);

        $sv->refresh();

        $this->assertSame('admin', $sv->role);
        $this->assertTrue($sv->is_active);
        $this->assertSame(1, $this->adminAktif());
    }

    public function test_perintah_pemulihan_menolak_email_yang_tidak_ada(): void
    {
        $kode = Artisan::call('lessworry:pulihkan-admin', ['email' => 'tidakada@lessworry.id']);

        $this->assertSame(1, $kode);
    }

    public function test_perintah_pemulihan_bisa_menyetel_password_sementara(): void
    {
        $sv = $this->admin();
        $lama = $sv->password;

        Artisan::call('lessworry:pulihkan-admin', [
            'email' => $sv->email, '--reset-password' => true,
        ]);

        $sv->refresh();

        $this->assertNotSame($lama, $sv->password);
        $this->assertTrue($sv->must_change_password);
        // Password tidak pernah tersimpan terbaca.
        $this->assertStringStartsWith('$2y$', $sv->password);
    }
}
