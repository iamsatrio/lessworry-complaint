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

    public function test_hanya_admin_yang_bisa_membuka_pengelolaan_pengguna(): void
    {
        foreach (['kasir', 'customer_care', 'divisi'] as $role) {
            $this->actingAs($this->userAs($role))->get('/users')->assertForbidden();
        }

        $this->actingAs($this->userAs('admin'))->get('/users')->assertOk();
    }

    public function test_bukan_admin_tidak_bisa_membuat_pengguna(): void
    {
        $this->actingAs($this->userAs('customer_care'))->post('/users', [
            'name' => 'Penyusup', 'email' => 'x@lessworry.id', 'role' => 'admin',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'x@lessworry.id']);
    }

    /* ---------- Pembuatan akun ---------- */

    public function test_pengguna_baru_dibuat_dengan_password_sementara_yang_wajib_diganti(): void
    {
        $admin = $this->userAs('admin');
        $outlet = Outlet::create(['name' => 'Outlet A']);

        $response = $this->actingAs($admin)->post('/users', [
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
        $admin = $this->userAs('admin');
        $existing = $this->userAs('kasir');

        $this->actingAs($admin)->post('/users', [
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
            'current_password' => 'secret123',
            'password' => 'rahasiabaru9',
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
            'current_password' => 'salah',
            'password' => 'rahasiabaru9',
            'password_confirmation' => 'rahasiabaru9',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(password_verify('secret123', $user->fresh()->password));
    }

    public function test_password_baru_tidak_boleh_sama_dengan_yang_lama(): void
    {
        $user = $this->userAs('kasir');

        $this->actingAs($user)->put('/password', [
            'current_password' => 'secret123',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertSessionHasErrors('password');
    }

    public function test_password_baru_harus_memenuhi_syarat_minimum(): void
    {
        $user = $this->userAs('kasir');

        $this->actingAs($user)->put('/password', [
            'current_password' => 'secret123',
            'password' => 'pendek',
            'password_confirmation' => 'pendek',
        ])->assertSessionHasErrors('password');
    }

    /* ---------- Menonaktifkan akun ---------- */

    public function test_admin_tidak_bisa_menonaktifkan_akunnya_sendiri(): void
    {
        $admin = $this->userAs('admin');

        $this->actingAs($admin)->put('/users/'.$admin->id, [
            'name' => $admin->name, 'role' => 'admin', 'is_active' => 0,
        ])->assertSessionHasErrors('is_active');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_admin_aktif_terakhir_tidak_bisa_dinonaktifkan(): void
    {
        $satu = $this->userAs('admin');
        $dua = $this->userAs('admin');

        // Masih ada dua supervisor: menonaktifkan salah satunya boleh.
        $this->actingAs($satu)->put('/users/'.$dua->id, [
            'name' => $dua->name, 'role' => 'admin', 'is_active' => 0,
        ])->assertRedirect('/users');

        $this->assertFalse($dua->fresh()->is_active);

        // Tersisa satu: sistem menolak, supaya tidak ada yang terkunci di luar.
        $tiga = $this->userAs('admin');
        $tiga->update(['is_active' => false]);

        $this->actingAs($satu)->put('/users/'.$satu->id, [
            'name' => $satu->name, 'role' => 'admin', 'is_active' => 0,
        ])->assertSessionHasErrors('is_active');

        $this->assertTrue($satu->fresh()->is_active);
    }

    public function test_reset_password_membuat_password_lama_tidak_berlaku(): void
    {
        $admin = $this->userAs('admin');
        $kasir = $this->userAs('kasir');

        $this->actingAs($admin)
            ->post('/users/'.$kasir->id.'/reset-password')
            ->assertSessionHas('temporary_password');

        $kasir->refresh();

        $this->assertFalse(password_verify('secret123', $kasir->password));
        $this->assertTrue($kasir->must_change_password);
    }

    public function test_akun_tidak_pernah_bisa_dihapus(): void
    {
        $admin = $this->userAs('admin');
        $kasir = $this->userAs('kasir');

        $this->actingAs($admin)->delete('/users/'.$kasir->id)->assertStatus(405);
        $this->assertDatabaseHas('users', ['id' => $kasir->id]);
    }

    /* ---------- is_active yang hilang tidak boleh mematikan akun (API-14 #9) ---------- */

    public function test_request_tanpa_is_active_tidak_menonaktifkan_akun(): void
    {
        $admin = User::create([
            'name' => 'SV', 'email' => 'sv'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'admin',
        ]);

        $kasir = User::create([
            'name' => 'Kasir', 'email' => 'k'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'kasir',
        ]);

        $this->assertTrue($kasir->is_active);

        // Kolom is_active sengaja tidak dikirim — nilai yang tidak dikirim
        // berarti "jangan diubah", bukan "matikan".
        $this->actingAs($admin)->put('/users/'.$kasir->id, [
            'name' => 'Kasir', 'role' => 'kasir',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($kasir->fresh()->is_active, 'akun mati diam-diam karena kolomnya tidak dikirim');
    }

    public function test_request_tanpa_is_active_tidak_menghidupkan_akun_nonaktif(): void
    {
        $admin = User::create([
            'name' => 'SV', 'email' => 'sv'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'admin',
        ]);

        $kasir = User::create([
            'name' => 'Kasir', 'email' => 'k'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'kasir',
        ]);
        $kasir->forceFill(['is_active' => false])->save();

        $this->actingAs($admin)->put('/users/'.$kasir->id, [
            'name' => 'Kasir', 'role' => 'kasir',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($kasir->fresh()->is_active, '"jangan diubah" tidak boleh berarti "hidupkan"');
    }

    /* ---------- Tabrakan huruf besar-kecil ---------- */

    /**
     * Alamat yang sama dengan huruf berbeda ditolak 422, bukan 500.
     *
     * Alamat email disimpan huruf kecil, tapi normalisasinya dulu berjalan
     * SESUDAH validate(). Akibatnya `Rule::unique` memeriksa `BUDI@...` —
     * yang memang belum ada — lalu baris yang disimpan `budi@...` menabrak
     * indeks unik di basis data. Yang sampai ke admin: 500, halaman galat
     * server, tanpa satu kata pun tentang alamat yang sudah dipakai.
     * (Temuan tinjauan PR #34.)
     */
    public function test_email_yang_sama_beda_huruf_ditolak_dengan_pesan_bukan_500(): void
    {
        $admin = $this->userAs('admin');

        $this->actingAs($admin)->post('/users', [
            'name' => 'Budi', 'email' => 'budi@lessworry.id', 'role' => 'kasir',
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/users', [
            'name' => 'Budi Lagi', 'email' => 'BUDI@lessworry.id', 'role' => 'kasir',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, User::where('email', 'budi@lessworry.id')->count());
        $this->assertSame(0, User::where('email', 'BUDI@lessworry.id')->count());
    }

    public function test_email_berspasi_pun_ditangkap_validasi_bukan_basis_data(): void
    {
        $admin = $this->userAs('admin');

        $this->actingAs($admin)->post('/users', [
            'name' => 'Budi', 'email' => 'budi2@lessworry.id', 'role' => 'kasir',
        ])->assertSessionHasNoErrors();

        // Alamat yang ditempel dari chat sering membawa spasi di ujungnya.
        $this->actingAs($admin)->post('/users', [
            'name' => 'Budi Lagi', 'email' => '  Budi2@lessworry.id  ', 'role' => 'kasir',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, User::where('email', 'budi2@lessworry.id')->count());
    }

    public function test_ubah_email_ke_milik_orang_lain_beda_huruf_ditolak_422(): void
    {
        $admin = $this->userAs('admin');

        $budi = User::create([
            'name' => 'Budi', 'email' => 'budi3@lessworry.id',
            'password' => 'secret123', 'role' => 'kasir',
        ]);

        $sari = User::create([
            'name' => 'Sari', 'email' => 'sari@lessworry.id',
            'password' => 'secret123', 'role' => 'kasir',
        ]);

        $this->actingAs($admin)->put('/users/'.$sari->id, [
            'name' => 'Sari', 'role' => 'kasir', 'email' => 'BUDI3@lessworry.id',
        ])->assertSessionHasErrors('email');

        $this->assertSame('sari@lessworry.id', $sari->fresh()->email);
        $this->assertSame('budi3@lessworry.id', $budi->fresh()->email);
    }

    /**
     * Alamat sendiri dengan huruf berbeda BUKAN tabrakan — `ignore($user->id)`
     * tetap berlaku sesudah urutannya dibalik.
     */
    public function test_membetulkan_huruf_alamat_sendiri_tetap_boleh(): void
    {
        $admin = $this->userAs('admin');

        $kasir = User::create([
            'name' => 'Kasir', 'email' => 'Kasir.Huruf@lessworry.id',
            'password' => 'secret123', 'role' => 'kasir',
        ]);

        $this->actingAs($admin)->put('/users/'.$kasir->id, [
            'name' => 'Kasir', 'role' => 'kasir', 'email' => 'KASIR.HURUF@lessworry.id',
        ])->assertSessionHasNoErrors();

        $this->assertSame('kasir.huruf@lessworry.id', $kasir->fresh()->email);
    }

    public function test_is_active_yang_dikirim_tetap_berlaku(): void
    {
        $admin = User::create([
            'name' => 'SV', 'email' => 'sv'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'admin',
        ]);

        $kasir = User::create([
            'name' => 'Kasir', 'email' => 'k'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'kasir',
        ]);

        $this->actingAs($admin)->put('/users/'.$kasir->id, [
            'name' => 'Kasir', 'role' => 'kasir', 'is_active' => 0,
        ])->assertSessionHasNoErrors();

        $this->assertFalse($kasir->fresh()->is_active);
    }
}
