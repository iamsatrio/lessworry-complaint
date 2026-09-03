<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Peran Admin dipisah dari Supervisor.
 *
 * Supervisor memimpin pekerjaan lapangan: melihat seluruh outlet, menutup
 * complaint apa pun bobotnya, menyetujui kompensasi tanpa batas. Yang tidak
 * dipegangnya: membuat akun, mengubah peran orang, menonaktifkan orang.
 *
 * Pemisahan itu hanya berarti kalau dijaga di sisi server, dan kalau
 * wewenang operasional supervisor tidak ikut hilang saat dipisah.
 */
class PeranAdminTest extends TestCase
{
    use RefreshDatabase;

    private function userAs(string $role): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => $role,
        ]);
    }

    /* ---------- Pemisahan wewenang ---------- */

    public function test_supervisor_tidak_lagi_bisa_mengelola_pengguna(): void
    {
        $supervisor = $this->userAs('supervisor');

        $this->assertFalse($supervisor->canManageUsers());
        $this->actingAs($supervisor)->get('/users')->assertForbidden();
    }

    public function test_supervisor_tidak_bisa_membuat_akun_lewat_permintaan_langsung(): void
    {
        // Menyembunyikan menunya tidak cukup — dikirim langsung ke server pun ditolak.
        $this->actingAs($this->userAs('supervisor'))->post('/users', [
            'name' => 'Akun Selundupan', 'email' => 'selundupan@lessworry.id', 'role' => 'admin',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'selundupan@lessworry.id']);
    }

    public function test_admin_bisa_mengelola_pengguna(): void
    {
        $admin = $this->userAs('admin');

        $this->assertTrue($admin->canManageUsers());
        $this->actingAs($admin)->get('/users')->assertOk();
    }

    /* ---------- Wewenang operasional supervisor tidak ikut hilang ---------- */

    public function test_supervisor_tetap_memegang_wewenang_lapangannya(): void
    {
        $supervisor = $this->userAs('supervisor');

        $this->assertTrue($supervisor->seesAllOutlets());
        $this->assertTrue($supervisor->canResolve());
        $this->assertTrue($supervisor->canCreateComplaint());
        $this->assertTrue($supervisor->canAssignResponsibility());
        $this->assertTrue($supervisor->canSeeStaffAttribution());
        $this->assertSame(PHP_INT_MAX, $supervisor->compensationLimit());
    }

    public function test_admin_memegang_wewenang_lapangan_yang_sama(): void
    {
        $admin = $this->userAs('admin');

        $this->assertTrue($admin->seesAllOutlets());
        $this->assertTrue($admin->canResolve());
        $this->assertTrue($admin->canCreateComplaint());
        $this->assertSame(PHP_INT_MAX, $admin->compensationLimit());
    }

    public function test_admin_melihat_complaint_outlet_mana_pun(): void
    {
        $complaint = new Complaint([
            'channel' => 'kasir', 'reporter_name' => 'Pelapor', 'category' => 'keterlambatan',
            'priority' => 'medium', 'description' => 'x',
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->status = 'baru';
        $complaint->applySla();
        $complaint->save();

        $this->assertTrue($this->userAs('admin')->canView($complaint));
    }

    public function test_label_peran_terbaca_walau_divisi_belum_diisi(): void
    {
        // Halaman Pengguna memanggil roleLabel() untuk tiap baris; null di
        // sini membuat seluruh halaman balas 500.
        $this->assertSame('Admin', $this->userAs('admin')->roleLabel());
        $this->assertSame('Produksi / Kurir', $this->userAs('divisi')->roleLabel());
    }

    /* ---------- Seeder ---------- */

    public function test_seeder_membuat_akun_tim_tanpa_complaint_karangan(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Complaint::count(), 'Seeder tidak boleh membuat complaint karangan.');
        $this->assertSame(11, User::count());

        $this->assertSame(3, User::where('role', 'admin')->count());
        $this->assertSame(2, User::where('role', 'supervisor')->count());
        $this->assertSame(2, User::where('role', 'customer_care')->count());
        $this->assertSame(1, User::where('role', 'kasir')->count());
        $this->assertSame(3, User::where('role', 'divisi')->count());
    }

    public function test_semua_akun_seeder_wajib_ganti_password_saat_pertama_masuk(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            0,
            User::where('must_change_password', false)->count(),
            'Ada akun yang bisa dipakai tanpa mengganti password lebih dulu.'
        );
    }

    public function test_seeder_dijalankan_dua_kali_tidak_menggandakan_atau_menyetel_ulang_password(): void
    {
        $this->seed(DatabaseSeeder::class);

        $satrio = User::where('email', 'satrio@lessworry.id')->first();
        $hashLama = $satrio->password;

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(11, User::count());
        $this->assertSame($hashLama, $satrio->fresh()->password);
    }

    public function test_kasir_seeder_terikat_pada_satu_outlet(): void
    {
        $this->seed(DatabaseSeeder::class);

        $kasir = User::where('email', 'kasir@lessworry.id')->first();

        $this->assertNotNull($kasir->outlet_id, 'Kasir tanpa outlet tidak bisa melihat complaint mana pun.');
        $this->assertSame('Tebet', $kasir->outlet->name);
    }
}
