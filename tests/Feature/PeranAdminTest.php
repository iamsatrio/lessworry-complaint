<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
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
        $this->assertSame(7, User::count());

        $this->assertSame(3, User::where('role', 'admin')->count());
        $this->assertSame(1, User::where('role', 'supervisor')->count());
        $this->assertSame(1, User::where('role', 'kasir')->count());
        $this->assertSame(2, User::where('role', 'divisi')->count());

        // Nol Customer Care memang isi daftar yang ditetapkan satrio, bukan
        // baris yang tertinggal. Dikunci di sini supaya kalau kelak ada yang
        // menambahkannya, itu keputusan yang terlihat — bukan kekeliruan yang
        // lolos diam-diam. Akibat operasionalnya diangkat di API-36.
        $this->assertSame(0, User::where('role', 'customer_care')->count());
    }

    public function test_tiga_akun_bersama_memakai_domain_getnada(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['kasir@getnada.com', 'produksi@getnada.com', 'kurir@getnada.com'] as $email) {
            $this->assertDatabaseHas('users', ['email' => $email, 'is_active' => true]);
        }

        // Alamat versi lamanya tidak boleh tertinggal sebagai akun kedua.
        foreach (['kasir@lessworry.id', 'produksi@lessworry.id', 'kurir@lessworry.id'] as $email) {
            $this->assertDatabaseMissing('users', ['email' => $email]);
        }
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

        $this->assertSame(7, User::count());
        $this->assertSame($hashLama, $satrio->fresh()->password);
    }

    public function test_kasir_seeder_terikat_pada_satu_outlet(): void
    {
        $this->seed(DatabaseSeeder::class);

        $kasir = User::where('email', 'kasir@getnada.com')->first();

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

        // Tsulasa cuti panjang; admin mencabut aksesnya sementara.
        $tsulasa = User::where('email', 'tsulasa@lessworry.id')->first();
        $tsulasa->forceFill(['is_active' => false])->save();

        $this->seed(DatabaseSeeder::class);

        $this->assertFalse(
            (bool) $tsulasa->fresh()->is_active,
            'Deploy menghidupkan kembali akun yang sengaja dimatikan.'
        );
    }

    /* ---------- Mesin yang memuat 11 akun versi lama (API-36) ---------- */

    /**
     * Keadaan yang ditinggalkan seeder PR #4: sebelas akun, semuanya
     * `@lessworry.id`, semuanya sudah mengganti password sementaranya
     * dengan password pilihan sendiri.
     *
     * Passwordnya sengaja BUKAN `password` yang bocor. Kalau helper ini
     * memakai password bocor, pemeriksaan di bawah lolos lewat jalur yang
     * salah: `Hash::check` di seeder akan menerbitkan ulang password setiap
     * akun yang disebut daftar, dan yang dibuang pun ikut tertutup tanpa
     * `matikanDemoLama` melakukan apa pun.
     */
    private const PASSWORD_LAMA = 'PasswordLama123';

    /** Empat orang yang dibuang dari daftar. */
    private const DIBUANG = [
        'samsuri@lessworry.id',
        'arifin@lessworry.id',
        'adhyasta@lessworry.id',
        'audry@lessworry.id',
    ];

    /** Tiga alamat yang orangnya tetap tapi domainnya berganti. */
    private const BERGANTI_DOMAIN = [
        'kasir@lessworry.id',
        'produksi@lessworry.id',
        'kurir@lessworry.id',
    ];

    private function seederSebelasAkun(): void
    {
        $tebet = Outlet::firstOrCreate(['nevira_outlet_id' => '118'], ['name' => 'Tebet']);

        foreach ([
            ['Satrio Wibowo', 'satrio@lessworry.id', 'admin', null, null],
            ['Ainul Ghozi', 'ghozi@lessworry.id', 'admin', null, null],
            ['Eric', 'eric@lessworry.id', 'admin', null, null],
            ['Tsulasa', 'tsulasa@lessworry.id', 'supervisor', null, null],
            ['Samsuri', 'samsuri@lessworry.id', 'supervisor', null, null],
            ['Audry', 'audry@lessworry.id', 'customer_care', null, null],
            ['Adhyasta Dwi Yudistira', 'adhyasta@lessworry.id', 'customer_care', null, null],
            ['Arifin', 'arifin@lessworry.id', 'divisi', 'produksi', null],
            ['Kasir', 'kasir@lessworry.id', 'kasir', null, $tebet->id],
            ['Produksi', 'produksi@lessworry.id', 'divisi', 'produksi', null],
            ['Kurir', 'kurir@lessworry.id', 'divisi', 'kurir', null],
        ] as [$nama, $email, $peran, $divisi, $outletId]) {
            User::create([
                'name' => $nama, 'email' => $email, 'password' => self::PASSWORD_LAMA,
                'role' => $peran, 'division' => $divisi, 'outlet_id' => $outletId,
                'is_active' => true, 'must_change_password' => false,
            ]);
        }
    }

    public function test_empat_akun_yang_dibuang_dimatikan_bukan_dilewati(): void
    {
        $this->seederSebelasAkun();
        $this->seed(DatabaseSeeder::class);

        foreach (self::DIBUANG as $email) {
            $user = User::where('email', $email)->first();

            $this->assertNotNull($user, $email.' dihapus — jejak audit complaint yang disentuhnya ikut hilang.');
            $this->assertFalse((bool) $user->is_active, $email.' masih hidup padahal sudah dibuang dari daftar.');
        }
    }

    public function test_tiga_alamat_lama_yang_berganti_domain_ikut_dimatikan(): void
    {
        $this->seederSebelasAkun();
        $this->seed(DatabaseSeeder::class);

        foreach (self::BERGANTI_DOMAIN as $email) {
            $user = User::where('email', $email)->first();

            $this->assertNotNull($user, $email.' dihapus — jejak auditnya ikut hilang.');
            $this->assertFalse(
                (bool) $user->is_active,
                $email.' tertinggal hidup dengan alamat lama, jadi akun kedua yang tidak dipegang siapa pun.'
            );
        }

        // Orangnya tetap ada, lewat alamat barunya.
        foreach (['kasir@getnada.com', 'produksi@getnada.com', 'kurir@getnada.com'] as $email) {
            $this->assertTrue((bool) User::where('email', $email)->first()?->is_active, $email.' tidak terbuat.');
        }
    }

    public function test_password_ketujuh_akun_lama_dibuang(): void
    {
        $this->seederSebelasAkun();
        $this->seed(DatabaseSeeder::class);

        foreach ([...self::DIBUANG, ...self::BERGANTI_DOMAIN] as $email) {
            $this->assertFalse(
                Hash::check(self::PASSWORD_LAMA, User::where('email', $email)->first()->password),
                $email.' masih memegang password lamanya.'
            );
        }
    }

    public function test_tidak_satu_pun_akun_lama_masih_bisa_masuk(): void
    {
        // Tujuh percobaan login; throttle:5,1 akan menolak yang keenam dan
        // ketujuh karena lajunya, bukan karena passwordnya salah — dan itu
        // membuat pemeriksaan ini lolos tanpa membuktikan apa pun.
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->seederSebelasAkun();
        $this->seed(DatabaseSeeder::class);

        foreach ([...self::DIBUANG, ...self::BERGANTI_DOMAIN] as $email) {
            $this->post('/login', ['email' => $email, 'password' => self::PASSWORD_LAMA]);
            $this->assertFalse(auth()->check(), $email.' masih menerima password lamanya.');
            $this->post('/logout');
        }
    }

    public function test_akun_yang_tetap_dipakai_tidak_ikut_terbawa(): void
    {
        $this->seederSebelasAkun();
        $this->seed(DatabaseSeeder::class);

        // Empat orang yang bertahan memakai alamat yang sama, dan sudah
        // memilih passwordnya sendiri. Seeder tidak boleh menyentuhnya.
        foreach (['satrio@lessworry.id', 'ghozi@lessworry.id', 'eric@lessworry.id', 'tsulasa@lessworry.id'] as $email) {
            $user = User::where('email', $email)->first();

            $this->assertTrue((bool) $user->is_active, $email.' ikut dimatikan padahal masih dipakai.');
            $this->assertTrue(Hash::check(self::PASSWORD_LAMA, $user->password), 'Password '.$email.' disetel ulang.');
            $this->assertFalse((bool) $user->must_change_password);
        }

        // Empat lama yang bertahan + tiga alamat getnada yang baru.
        $this->assertSame(7, User::where('is_active', true)->count());
    }

    /* ---------- Gerbang pengelolaan, diperiksa di sisi server ---------- */

    public function test_supervisor_ditolak_di_seluruh_rute_pengelolaan_pengguna(): void
    {
        $supervisor = $this->userAs('supervisor');
        $korban = $this->userAs('kasir');

        // Menyembunyikan menunya tidak menjaga apa pun — tiap rute dikirimi
        // permintaan langsung, termasuk yang tidak punya tautan di tampilan.
        $rute = [
            ['get', '/users'],
            ['get', '/users/create'],
            ['post', '/users'],
            ['get', '/users/'.$korban->id.'/edit'],
            ['put', '/users/'.$korban->id],
            ['post', '/users/'.$korban->id.'/reset-password'],
        ];

        foreach ($rute as [$metode, $jalur]) {
            $this->actingAs($supervisor)->{$metode}($jalur, [
                'name' => 'Coba', 'email' => 'coba@lessworry.id', 'role' => 'admin',
            ])->assertForbidden(strtoupper($metode).' '.$jalur.' tidak dijaga di sisi server.');
        }

        $this->assertSame('kasir', $korban->fresh()->role);
        $this->assertDatabaseMissing('users', ['email' => 'coba@lessworry.id']);
    }

    /**
     * Halaman pengelolaan divisi BELUM ADA, dan issue ini sengaja tidak
     * membuatnya. Yang dikunci di sini hanya gerbangnya: kalau kelak halaman
     * itu dibuat, ia bertanya ke `canManageDivisions()`, dan jawabannya untuk
     * supervisor adalah tidak. (API-36)
     */
    public function test_hanya_admin_yang_memegang_pengelolaan_divisi(): void
    {
        $this->assertTrue($this->userAs('admin')->canManageDivisions());

        foreach (['supervisor', 'customer_care', 'kasir', 'divisi'] as $peran) {
            $this->assertFalse(
                $this->userAs($peran)->canManageDivisions(),
                $peran.' bisa mengubah daftar divisi — itu memutus jalur penerusan complaint.'
            );
        }
    }
}
