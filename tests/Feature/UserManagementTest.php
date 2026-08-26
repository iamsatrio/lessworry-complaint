<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role, ?Outlet $outlet = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role, 'outlet_id' => $outlet?->id,
        ]);
    }

    /* ---------- Siapa boleh mengelola ---------- */

    public function test_hanya_supervisor_yang_bisa_membuka_pengelolaan_pengguna(): void
    {
        foreach (['kasir', 'customer_care', 'divisi'] as $role) {
            $this->actingAs($this->userAs($role))->get('/users')->assertForbidden();
        }

        $this->actingAs($this->userAs('supervisor'))->get('/users')->assertOk();
    }

    public function test_bukan_supervisor_tidak_bisa_membuat_pengguna(): void
    {
        $this->actingAs($this->userAs('customer_care'))->post('/users', [
            'name' => 'Penyusup', 'email' => 'x@lessworry.id', 'role' => 'supervisor',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'x@lessworry.id']);
    }

    /* ---------- Pembuatan akun ---------- */

    public function test_pengguna_baru_dibuat_dengan_password_sementara_yang_wajib_diganti(): void
    {
        $supervisor = $this->userAs('supervisor');
        $outlet = Outlet::create(['name' => 'Outlet A']);

        $response = $this->actingAs($supervisor)->post('/users', [
            'name' => 'Kasir Baru', 'email' => 'kasirbaru@lessworry.id',
            'role' => 'kasir', 'outlet_id' => $outlet->id,
        ]);

        $response->assertRedirect('/users');
        $response->assertSessionHas('temporary_password');

        $user = User::where('email', 'kasirbaru@lessworry.id')->first();

        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->is_active);
        $this->assertSame($outlet->id, $user->outlet_id);

        // Password sementara harus benar-benar berlaku, dan tersimpan sebagai hash.
        $plain = session('temporary_password')['password'];
        $this->assertNotSame($plain, $user->password);
        $this->assertTrue(password_verify($plain, $user->password));
    }

    public function test_email_tidak_boleh_kembar(): void
    {
        $supervisor = $this->userAs('supervisor');
        $existing = $this->userAs('kasir');

        $this->actingAs($supervisor)->post('/users', [
            'name' => 'Duplikat', 'email' => $existing->email, 'role' => 'kasir',
        ])->assertSessionHasErrors('email');
    }

    /* ---------- Password sementara wajib diganti ---------- */

    public function test_pengguna_dengan_password_sementara_dipaksa_ke_halaman_ganti_password(): void
    {
        $user = $this->userAs('customer_care');
        $user->update(['must_change_password' => true]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/password');
        $this->actingAs($user)->get('/complaints')->assertRedirect('/password');

        // Halaman ganti password sendiri tetap terbuka, begitu juga keluar.
        $this->actingAs($user)->get('/password')->assertOk();
    }

    public function test_mengganti_password_membuka_kembali_akses(): void
    {
        $user = $this->userAs('kasir');
        $user->update(['must_change_password' => true]);

        $this->actingAs($user)->put('/password', [
            'current_password'      => 'secret123',
            'password'              => 'rahasiabaru9',
            'password_confirmation' => 'rahasiabaru9',
        ])->assertRedirect('/dashboard');

        $user->refresh();

        $this->assertFalse($user->must_change_password);
        $this->assertTrue(password_verify('rahasiabaru9', $user->password));
    }

    public function test_ganti_password_ditolak_kalau_password_sekarang_salah(): void
    {
        $user = $this->userAs('kasir');

        $this->actingAs($user)->put('/password', [
            'current_password'      => 'salah',
            'password'              => 'rahasiabaru9',
            'password_confirmation' => 'rahasiabaru9',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(password_verify('secret123', $user->fresh()->password));
    }

    public function test_password_baru_tidak_boleh_sama_dengan_yang_lama(): void
    {
        $user = $this->userAs('kasir');

        $this->actingAs($user)->put('/password', [
            'current_password'      => 'secret123',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertSessionHasErrors('password');
    }

    public function test_password_baru_harus_memenuhi_syarat_minimum(): void
    {
        $user = $this->userAs('kasir');

        $this->actingAs($user)->put('/password', [
            'current_password'      => 'secret123',
            'password'              => 'pendek',
            'password_confirmation' => 'pendek',
        ])->assertSessionHasErrors('password');
    }

    /* ---------- Menonaktifkan akun ---------- */

    public function test_supervisor_tidak_bisa_menonaktifkan_akunnya_sendiri(): void
    {
        $supervisor = $this->userAs('supervisor');

        $this->actingAs($supervisor)->put('/users/'.$supervisor->id, [
            'name' => $supervisor->name, 'role' => 'supervisor', 'is_active' => 0,
        ])->assertSessionHasErrors('is_active');

        $this->assertTrue($supervisor->fresh()->is_active);
    }

    public function test_supervisor_aktif_terakhir_tidak_bisa_dinonaktifkan(): void
    {
        $satu = $this->userAs('supervisor');
        $dua  = $this->userAs('supervisor');

        // Masih ada dua supervisor: menonaktifkan salah satunya boleh.
        $this->actingAs($satu)->put('/users/'.$dua->id, [
            'name' => $dua->name, 'role' => 'supervisor', 'is_active' => 0,
        ])->assertRedirect('/users');

        $this->assertFalse($dua->fresh()->is_active);

        // Tersisa satu: sistem menolak, supaya tidak ada yang terkunci di luar.
        $tiga = $this->userAs('supervisor');
        $tiga->update(['is_active' => false]);

        $this->actingAs($satu)->put('/users/'.$satu->id, [
            'name' => $satu->name, 'role' => 'supervisor', 'is_active' => 0,
        ])->assertSessionHasErrors('is_active');

        $this->assertTrue($satu->fresh()->is_active);
    }

    public function test_reset_password_membuat_password_lama_tidak_berlaku(): void
    {
        $supervisor = $this->userAs('supervisor');
        $kasir = $this->userAs('kasir');

        $this->actingAs($supervisor)
            ->post('/users/'.$kasir->id.'/reset-password')
            ->assertSessionHas('temporary_password');

        $kasir->refresh();

        $this->assertFalse(password_verify('secret123', $kasir->password));
        $this->assertTrue($kasir->must_change_password);
    }

    public function test_akun_tidak_pernah_bisa_dihapus(): void
    {
        $supervisor = $this->userAs('supervisor');
        $kasir = $this->userAs('kasir');

        $this->actingAs($supervisor)->delete('/users/'.$kasir->id)->assertStatus(405);
        $this->assertDatabaseHas('users', ['id' => $kasir->id]);
    }
}
