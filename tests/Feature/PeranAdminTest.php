<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
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

    /* ---------- Mesin yang sudah pernah memakai seeder lama ---------- */

    /**
     * Seeder lama membuat empat akun berpassword harfiah `password`, dan
     * sengaja tidak menandai must_change_password. Tiga emailnya dipakai
     * ulang oleh daftar baru.
     */
    private function seederLama(): void
    {
        $tebet = Outlet::firstOrCreate(
            ['nevira_outlet_id' => '118'], ['name' => 'Tebet']
        );

        User::create(['name' => 'Satrio Wibowo', 'email' => 'satrio@lessworry.id',
            'password' => 'password', 'role' => 'supervisor']);
        User::create(['name' => 'Customer Care', 'email' => 'cc@lessworry.id',
            'password' => 'password', 'role' => 'customer_care']);
        User::create(['name' => 'Kasir Pusat', 'email' => 'kasir@lessworry.id',
            'password' => 'password', 'role' => 'kasir', 'outlet_id' => $tebet->id]);
        User::create(['name' => 'Divisi Produksi', 'email' => 'produksi@lessworry.id',
            'password' => 'password', 'role' => 'divisi', 'division' => 'produksi']);
        User::create(['name' => 'Kasir Cabang (baru)', 'email' => 'kasirbaru@lessworry.id',
            'password' => 'password', 'role' => 'kasir', 'outlet_id' => $tebet->id,
            'must_change_password' => true]);
    }

    public function test_akun_seeder_lama_diperbaiki_bukan_dilewati(): void
    {
        $this->seederLama();
        $this->seed(DatabaseSeeder::class);

        $satrio = User::where('email', 'satrio@lessworry.id')->first();

        $this->assertSame('admin', $satrio->role, 'Pemilik sistem tetap terkunci dari pengelolaan pengguna.');
        $this->assertTrue((bool) $satrio->must_change_password);
        $this->assertSame(3, User::where('role', 'admin')->where('is_active', true)->count());
    }

    public function test_password_seeder_lama_tidak_lagi_tembus(): void
    {
        $this->seederLama();
        $this->seed(DatabaseSeeder::class);

        // Password harfiah `password` ada di riwayat commit publik.
        foreach (['satrio@lessworry.id', 'kasir@lessworry.id', 'produksi@lessworry.id'] as $email) {
            $this->post('/login', ['email' => $email, 'password' => 'password']);
            $this->assertFalse(auth()->check(), 'Akun '.$email.' masih menerima password yang bocor.');
            $this->post('/logout');
        }
    }

    public function test_akun_demo_lama_yang_tidak_dipakai_lagi_dimatikan(): void
    {
        $this->seederLama();
        $this->seed(DatabaseSeeder::class);

        foreach (['cc@lessworry.id', 'kasirbaru@lessworry.id'] as $email) {
            $user = User::where('email', $email)->first();

            $this->assertNotNull($user, 'Akun demo dihapus — jejak audit ikut hilang.');
            $this->assertFalse((bool) $user->is_active, $email.' masih hidup.');

            $this->post('/login', ['email' => $email, 'password' => 'password']);
            $this->assertGuest();
            $this->post('/logout');
        }
    }

    public function test_tidak_ada_akun_tanpa_wajib_ganti_password_setelah_perbaikan(): void
    {
        $this->seederLama();
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::where('must_change_password', false)->count());
    }

    /* ---------- Admin ikut terhitung sebagai petugas ---------- */

    public function test_admin_bisa_ditugasi_sebuah_complaint(): void
    {
        $this->assertContains('admin', User::peranBisaDitugasi());
    }

    /* ---------- Himpunan keadaan bersejarah, bukan satu keadaan ---------- */

    /**
     * Seeder PALING AWAL (fa9f725) memberi password bocor SEKALIGUS menandai
     * wajib-ganti. Perantara `! must_change_password` melewatkan akun itu,
     * lalu menaikkannya jadi admin — password bocor pada peran tertinggi.
     */
    private function seederPalingAwal(): void
    {
        $tebet = Outlet::firstOrCreate(
            ['nevira_outlet_id' => '118'], ['name' => 'Tebet']
        );

        foreach ([
            ['Satrio Wibowo', 'satrio@lessworry.id', 'supervisor', null],
            ['Customer Care', 'cc@lessworry.id', 'customer_care', null],
            ['Kasir Pusat', 'kasir@lessworry.id', 'kasir', $tebet->id],
            ['Divisi Produksi', 'produksi@lessworry.id', 'divisi', null],
        ] as [$nama, $email, $peran, $outlet]) {
            User::create([
                'name' => $nama, 'email' => $email, 'password' => 'password',
                'role' => $peran, 'outlet_id' => $outlet,
                'division' => $peran === 'divisi' ? 'produksi' : null,
                'must_change_password' => true,
            ]);
        }
    }

    public function test_password_bocor_ditutup_walau_akunnya_sudah_wajib_ganti(): void
    {
        $this->seederPalingAwal();
        $this->seed(DatabaseSeeder::class);

        foreach (['satrio@lessworry.id', 'kasir@lessworry.id', 'produksi@lessworry.id'] as $email) {
            $this->post('/login', ['email' => $email, 'password' => 'password']);
            $this->assertFalse(
                auth()->check(),
                $email.' masih menerima password bocor — dan sekarang perannya lebih tinggi.'
            );
            $this->post('/logout');
        }
    }

    public function test_seeder_tidak_menghapus_password_yang_sudah_dipilih_sendiri(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Tsulasa menuruti perintah sistem dan mengganti passwordnya.
        $tsulasa = User::where('email', 'tsulasa@lessworry.id')->first();
        $tsulasa->forceFill([
            'password' => 'PasswordSaya123',
            'must_change_password' => false,
        ])->save();

        // Deploy berikutnya menjalankan `migrate --seed` lagi.
        $this->seed(DatabaseSeeder::class);

        $this->post('/login', ['email' => 'tsulasa@lessworry.id', 'password' => 'PasswordSaya123']);
        $this->assertTrue(auth()->check(), 'Seeder menghapus password yang sudah dipilih sendiri.');
        $this->assertFalse((bool) $tsulasa->fresh()->must_change_password);
    }

    public function test_seeder_tidak_menghidupkan_lagi_akun_yang_sengaja_dinonaktifkan(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Samsuri berhenti bekerja; admin mencabut aksesnya.
        $samsuri = User::where('email', 'samsuri@lessworry.id')->first();
        $samsuri->forceFill(['is_active' => false])->save();

        $this->seed(DatabaseSeeder::class);

        $this->assertFalse(
            (bool) $samsuri->fresh()->is_active,
            'Deploy menghidupkan kembali akun yang sengaja dimatikan.'
        );
    }
}
