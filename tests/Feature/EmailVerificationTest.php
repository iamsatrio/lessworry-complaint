<?php

namespace Tests\Feature;

use App\Mail\VerifikasiEmail;
use App\Models\User;
use App\Models\UserAudit;
use App\Services\PengirimVerifikasiEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Verifikasi email sebelum ganti password. (API-35)
 *
 * Yang dijaga test ini bukan hanya "gerbangnya menahan", tapi juga yang lebih
 * mahal kalau salah: bahwa selalu ADA jalan masuk. Alamat email yang tidak
 * pernah ada akan mengunci orang selamanya kalau ketiga jalan keluar —
 * penandaan manual, penggantian alamat, dan perintah shell — tidak bekerja.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    /** Kelas ini menyetel sendiri keadaan verifikasinya. */
    protected bool $verifikasiOtomatis = false;

    private function pengguna(array $atribut = []): User
    {
        return User::create(array_merge([
            'name' => 'Audry',
            'email' => 'audry@lessworry.id',
            'password' => 'rahasia123',
            'role' => 'customer_care',
            'must_change_password' => true,
        ], $atribut));
    }

    private function admin(): User
    {
        $admin = User::create([
            'name' => 'Satrio', 'email' => 'satrio@lessworry.id',
            'password' => 'rahasia123', 'role' => 'admin',
        ]);

        $admin->markEmailAsVerified();

        return $admin;
    }

    /* ---------- 1. Gerbang berdiri di depan gerbang ganti password ---------- */

    public function test_login_pertama_diarahkan_ke_verifikasi_bukan_ganti_password(): void
    {
        Mail::fake();
        $this->pengguna();

        $this->post('/login', ['email' => 'audry@lessworry.id', 'password' => 'rahasia123'])
            ->assertRedirect('/verifikasi-email');

        Mail::assertSent(VerifikasiEmail::class);
    }

    public function test_membuka_halaman_password_langsung_tetap_dipantulkan(): void
    {
        $user = $this->pengguna();

        $this->actingAs($user)->get('/password')->assertRedirect('/verifikasi-email');
        $this->actingAs($user)->put('/password', [
            'current_password' => 'rahasia123',
            'password' => 'passwordbaru9', 'password_confirmation' => 'passwordbaru9',
        ])->assertRedirect('/verifikasi-email');

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_halaman_lain_juga_tertahan(): void
    {
        $user = $this->pengguna();

        foreach (['/dashboard', '/complaints', '/reports'] as $url) {
            $this->actingAs($user)->get($url)->assertRedirect('/verifikasi-email');
        }
    }

    public function test_halaman_verifikasi_menyamarkan_alamatnya(): void
    {
        $user = $this->pengguna();

        $response = $this->actingAs($user)->get('/verifikasi-email');

        $response->assertOk();
        $response->assertSee('a****y@lessworry.id');
        // Alamat penuh tidak boleh terpampang di layar outlet.
        $response->assertDontSee('audry@lessworry.id');
    }

    public function test_halaman_verifikasi_tidak_menawarkan_menu_yang_memantul_balik(): void
    {
        $user = $this->pengguna();

        $response = $this->actingAs($user)->get('/verifikasi-email');

        $response->assertDontSee('Papan Kerja');
        $response->assertDontSee('Catat Complaint');
        // Keluar harus tetap ada — itu satu-satunya jalan keluar dari halaman ini.
        $response->assertSee('Keluar');
    }

    /* ---------- 2. Membuka tautan ---------- */

    public function test_membuka_tautan_memverifikasi_lalu_mengantar_ke_ganti_password(): void
    {
        $user = $this->pengguna();
        $tautan = app(PengirimVerifikasiEmail::class)->tautan($user);

        $this->actingAs($user)->get($tautan)->assertRedirect(route('password.edit'));

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->actingAs($user->fresh())->get('/password')->assertOk();
    }

    /* ---------- 3. Sekali pakai ---------- */

    public function test_tautan_yang_sama_dibuka_kedua_kalinya_ditolak_dengan_pesan_jelas(): void
    {
        $user = $this->pengguna();
        $tautan = app(PengirimVerifikasiEmail::class)->tautan($user);

        $this->actingAs($user)->get($tautan);
        $waktuVerifikasi = $user->fresh()->email_verified_at;

        $kedua = $this->actingAs($user->fresh())->get($tautan);

        $kedua->assertRedirect(route('password.edit'));
        $kedua->assertSessionHas('warning');
        $this->assertStringContainsString('sudah dipakai', (string) session('warning'));
        // Bukan halaman galat, dan tidak menulis ulang waktu verifikasinya.
        $this->assertEquals($waktuVerifikasi, $user->fresh()->email_verified_at);
    }

    /* ---------- 4. Umur 60 menit ---------- */

    public function test_tautan_berumur_61_menit_ditolak(): void
    {
        $user = $this->pengguna();
        $tautan = app(PengirimVerifikasiEmail::class)->tautan($user);

        $this->travel(61)->minutes();

        $response = $this->actingAs($user)->get($tautan);

        $response->assertRedirect('/verifikasi-email');
        $response->assertSessionHasErrors('kirim');
        $this->assertNull($user->fresh()->email_verified_at);

        $this->travelBack();
    }

    public function test_tautan_berumur_59_menit_masih_berlaku(): void
    {
        $user = $this->pengguna();
        $tautan = app(PengirimVerifikasiEmail::class)->tautan($user);

        $this->travel(59)->minutes();

        $this->actingAs($user)->get($tautan)->assertRedirect(route('password.edit'));
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->travelBack();
    }

    /* ---------- 5. Alamat diganti admin ---------- */

    public function test_tautan_lama_mati_setelah_alamat_email_diganti_admin(): void
    {
        $admin = $this->admin();
        $user = $this->pengguna();
        $tautan = app(PengirimVerifikasiEmail::class)->tautan($user);

        $this->actingAs($admin)->put('/users/'.$user->id, [
            'name' => $user->name, 'email' => 'audry.baru@lessworry.id', 'role' => $user->role,
        ])->assertRedirect('/users');

        $response = $this->actingAs($user->fresh())->get($tautan);

        $response->assertRedirect('/verifikasi-email');
        $response->assertSessionHasErrors('kirim');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_mengganti_alamat_mereset_verifikasi_dan_tercatat(): void
    {
        $admin = $this->admin();
        $user = $this->pengguna();
        $user->markEmailAsVerified();

        $this->actingAs($admin)->put('/users/'.$user->id, [
            'name' => $user->name, 'email' => 'audry.baru@lessworry.id', 'role' => $user->role,
        ]);

        $this->assertNull($user->fresh()->email_verified_at);

        $jejak = UserAudit::where('user_id', $user->id)->where('action', 'email_diubah')->first();
        $this->assertNotNull($jejak);
        $this->assertSame($admin->id, $jejak->actor_id);
        $this->assertStringContainsString('audry@lessworry.id', (string) $jejak->detail);
    }

    public function test_permintaan_tanpa_kolom_email_tidak_menyentuh_alamatnya(): void
    {
        $admin = $this->admin();
        $user = $this->pengguna();
        $user->markEmailAsVerified();

        $this->actingAs($admin)->put('/users/'.$user->id, [
            'name' => 'Audry Baru', 'role' => $user->role,
        ])->assertRedirect('/users');

        $user->refresh();
        $this->assertSame('audry@lessworry.id', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    /* ---------- 6. Batas kirim ulang ---------- */

    public function test_kirim_ulang_keempat_dalam_sepuluh_menit_ditolak(): void
    {
        Mail::fake();
        $user = $this->pengguna();

        for ($i = 1; $i <= 3; $i++) {
            $this->actingAs($user)->post('/verifikasi-email/kirim-ulang')
                ->assertSessionHasNoErrors();
        }

        $keempat = $this->actingAs($user)->post('/verifikasi-email/kirim-ulang');

        $keempat->assertSessionHasErrors('kirim');
        Mail::assertSentCount(3);
    }

    /* ---------- 7. Penandaan manual oleh admin ---------- */

    public function test_admin_menandai_terverifikasi_dan_alasannya_tercatat(): void
    {
        $admin = $this->admin();
        $user = $this->pengguna(['name' => 'Kasir', 'email' => 'kasir@lessworry.id']);

        $this->actingAs($admin)->post('/users/'.$user->id.'/verifikasi-email', [
            'reason' => 'Akun bersama kasir outlet, tidak punya kotak surat sendiri.',
        ])->assertRedirect('/users/'.$user->id.'/edit');

        $this->assertNotNull($user->fresh()->email_verified_at);

        $jejak = UserAudit::where('user_id', $user->id)
            ->where('action', 'email_diverifikasi_manual')->first();

        $this->assertNotNull($jejak);
        $this->assertSame($admin->id, $jejak->actor_id);
        $this->assertStringContainsString('Akun bersama', (string) $jejak->reason);
        $this->assertNotNull($jejak->created_at);
    }

    public function test_penandaan_tanpa_alasan_ditolak(): void
    {
        $admin = $this->admin();
        $user = $this->pengguna();

        $this->actingAs($admin)->post('/users/'.$user->id.'/verifikasi-email', [])
            ->assertSessionHasErrors('reason');

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame(0, UserAudit::where('user_id', $user->id)->count());
    }

    public function test_bukan_admin_tidak_bisa_menandai_terverifikasi(): void
    {
        $supervisor = $this->pengguna([
            'name' => 'Tsulasa', 'email' => 'tsulasa@lessworry.id',
            'role' => 'supervisor', 'must_change_password' => false,
        ]);
        $supervisor->markEmailAsVerified();
        $user = $this->pengguna();

        $this->actingAs($supervisor->fresh())->post('/users/'.$user->id.'/verifikasi-email', [
            'reason' => 'Coba-coba melewati pengaman.',
        ])->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    /* ---------- 8. Jalan pulih lewat shell ---------- */

    public function test_pulihkan_admin_ikut_menandai_terverifikasi(): void
    {
        $user = $this->pengguna(['name' => 'Ghozi', 'email' => 'ghozi@lessworry.id', 'role' => 'kasir']);

        $this->artisan('lessworry:pulihkan-admin', ['email' => 'ghozi@lessworry.id'])
            ->assertExitCode(0);

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('admin', $user->role);

        // Dan akun itu benar-benar bisa masuk sampai halaman ganti password.
        $this->actingAs($user)->get('/password')->assertOk();

        $this->assertSame(
            1,
            UserAudit::where('user_id', $user->id)->where('action', 'email_diverifikasi_konsol')->count()
        );
    }

    public function test_pulihkan_admin_tidak_menulis_ulang_verifikasi_yang_sudah_ada(): void
    {
        $user = $this->pengguna();
        $user->markEmailAsVerified();
        $waktu = $user->fresh()->email_verified_at;

        $this->travel(5)->minutes();
        $this->artisan('lessworry:pulihkan-admin', ['email' => $user->email])->assertExitCode(0);
        $this->travelBack();

        $this->assertEquals($waktu, $user->fresh()->email_verified_at);
        $this->assertSame(0, UserAudit::where('user_id', $user->id)->count());
    }

    /* ---------- 9. SMTP mati tidak mengunci orang ---------- */

    public function test_pengiriman_gagal_membalas_halaman_wajar_tanpa_pesan_smtp_mentah(): void
    {
        config(['mail.default' => 'mailer-yang-tidak-ada']);
        $user = $this->pengguna();

        $this->actingAs($user);

        // Halaman verifikasi tetap terbuka — bukan 500, bukan halaman putih.
        $halaman = $this->get('/verifikasi-email');
        $halaman->assertOk();
        $halaman->assertDontSee('mailer-yang-tidak-ada');

        $response = $this->from('/verifikasi-email')->post('/verifikasi-email/kirim-ulang');

        $response->assertRedirect('/verifikasi-email');
        $response->assertSessionHasErrors('kirim');

        // Kegagalannya dikatakan apa adanya, dan pesan galat mentah dari
        // lapisan surat — yang bisa memuat nama host dan kredensial — tidak
        // ikut terbawa ke layar orang.
        $pesan = implode(' ', session('errors')->get('kirim'));

        $this->assertStringContainsString('Surat gagal dikirim', $pesan);
        $this->assertStringContainsString('hubungi Admin', $pesan);
        $this->assertStringNotContainsString('mailer-yang-tidak-ada', $pesan);
        $this->assertStringNotContainsString('is not defined', $pesan);
    }

    public function test_login_tetap_berhasil_walau_surat_gagal_dikirim(): void
    {
        config(['mail.default' => 'mailer-yang-tidak-ada']);
        $this->pengguna();

        $this->post('/login', ['email' => 'audry@lessworry.id', 'password' => 'rahasia123'])
            ->assertRedirect('/verifikasi-email');

        $this->assertAuthenticated();
    }

    /* ---------- 10. Yang sudah selesai tidak melihatnya lagi ---------- */

    public function test_pengguna_yang_sudah_terverifikasi_dan_sudah_ganti_password_tidak_melihat_halaman_verifikasi(): void
    {
        $user = $this->pengguna(['must_change_password' => false]);
        $user->markEmailAsVerified();

        $this->actingAs($user->fresh())->get('/verifikasi-email')->assertRedirect(route('dashboard'));
    }

    public function test_login_pengguna_terverifikasi_langsung_ke_dashboard(): void
    {
        Mail::fake();
        $user = $this->pengguna(['must_change_password' => false]);
        $user->markEmailAsVerified();

        $this->post('/login', ['email' => 'audry@lessworry.id', 'password' => 'rahasia123'])
            ->assertRedirect(route('dashboard'));

        Mail::assertNothingSent();
    }

    /* ---------- Isi suratnya ---------- */

    public function test_surat_menyebut_nama_dan_memberi_tahu_harus_lapor_kalau_tidak_merasa_meminta(): void
    {
        Mail::fake();
        $user = $this->pengguna();

        $this->actingAs($user)->post('/verifikasi-email/kirim-ulang');

        Mail::assertSent(VerifikasiEmail::class, function (VerifikasiEmail $mail) use ($user) {
            $isi = $mail->render();

            return $mail->hasTo($user->email)
                && str_contains($isi, 'Audry')
                && str_contains($isi, 'tidak merasa meminta')
                && str_contains($isi, '60 menit');
        });
    }

    /* ---------- 11. Halaman tidak mengaku mengirim yang tidak dikirim (API-47) ---------- */

    /**
     * `log` dan `array` menerima surat lalu tidak mengantarkannya ke mana pun.
     * Halaman yang tetap bilang "dikirim" membuat orang menunggu surat yang
     * tidak akan pernah datang, lalu mencari kesalahan di tempat yang salah.
     */
    public function test_mailer_log_halaman_menyatakan_surat_tidak_dikirim_dan_menyebut_berkas_lognya(): void
    {
        config(['mail.default' => 'log']);
        $user = $this->pengguna();

        $halaman = $this->actingAs($user)->get('/verifikasi-email');

        $halaman->assertOk();
        $halaman->assertSee('Surat tidak dikirim ke mana pun');
        $halaman->assertSee('storage/logs/laravel.log');

        // Dan TIDAK berpura-pura mengirim.
        $halaman->assertDontSee('Tautan verifikasi dikirim ke');
    }

    public function test_mailer_array_diperlakukan_sama_dengan_log(): void
    {
        config(['mail.default' => 'array']);
        $user = $this->pengguna();

        $this->actingAs($user)->get('/verifikasi-email')
            ->assertOk()
            ->assertSee('Surat tidak dikirim ke mana pun');
    }

    /**
     * Menampilkan tautannya di layar akan menghapus gerbangnya begitu polanya
     * tersalin ke produksi. Halamannya menyebut DI MANA tautannya bisa dicari,
     * bukan tautannya sendiri.
     */
    public function test_tautan_verifikasi_tidak_pernah_ditampilkan_di_halaman(): void
    {
        config(['mail.default' => 'log']);
        $user = $this->pengguna();

        $isi = $this->actingAs($user)->get('/verifikasi-email')->getContent();

        $this->assertStringNotContainsString('signature=', $isi);
        $this->assertStringNotContainsString(sha1($user->email), $isi);
        $this->assertStringNotContainsString('/verifikasi-email/'.$user->id.'/', $isi);
    }

    /** Pesan kirim ulang pun tidak boleh mengulang kebohongan yang sama. */
    public function test_kirim_ulang_dengan_mailer_log_tidak_mengaku_mengirim(): void
    {
        config(['mail.default' => 'log']);
        $user = $this->pengguna();

        $this->actingAs($user)->from('/verifikasi-email')->post('/verifikasi-email/kirim-ulang')
            ->assertRedirect('/verifikasi-email');

        $pesan = session('status');

        $this->assertStringContainsString('storage/logs/laravel.log', $pesan);
        $this->assertStringNotContainsString('dikirim ulang ke', $pesan);
    }

    /** Kriteria 3: kalau suratnya benar-benar terkirim, kalimatnya tidak berubah. */
    public function test_surat_yang_benar_benar_terkirim_kalimatnya_tidak_berubah(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
        $user = $this->pengguna();

        $halaman = $this->actingAs($user)->get('/verifikasi-email');

        $halaman->assertOk();
        $halaman->assertSee('Tautan verifikasi dikirim ke');
        $halaman->assertDontSee('Surat tidak dikirim ke mana pun');
        $halaman->assertDontSee('storage/logs/laravel.log');

        $this->from('/verifikasi-email')->post('/verifikasi-email/kirim-ulang');

        $this->assertStringContainsString('dikirim ulang ke', (string) session('status'));
    }

    /**
     * Kegagalan kirim yang sesungguhnya masuk log server dengan tingkat
     * `error` — di situlah pesan SMTP mentahnya boleh ada, dan hanya di situ.
     */
    public function test_kegagalan_kirim_dicatat_sebagai_error_bukan_hanya_ditampilkan(): void
    {
        config(['mail.default' => 'mailer-yang-tidak-ada']);
        Log::spy();
        $user = $this->pengguna();

        $this->actingAs($user)->post('/verifikasi-email/kirim-ulang');

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $pesan, array $konteks = []) => str_contains($pesan, 'Gagal mengirim email verifikasi')
                && ($konteks['user_id'] ?? null) === $user->id)
            ->once();
    }

    /**
     * Kriteria 2 dengan bentuk kegagalan yang sesungguhnya: transport SMTP
     * yang melempar. Pesan mentahnya memuat nama host DAN nama pengguna —
     * persis yang tidak boleh sampai ke layar siapa pun.
     */
    public function test_smtp_yang_menolak_tidak_membocorkan_host_atau_pengguna_ke_layar(): void
    {
        $mentah = 'Connection to "smtp.rahasia-lessworry.id:587" failed: '
            .'authentication failed for user "surat@lessworry.id"';

        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('to')->andThrow(new TransportException($mentah));
        Log::spy();

        $user = $this->pengguna();

        $response = $this->actingAs($user)->from('/verifikasi-email')
            ->post('/verifikasi-email/kirim-ulang');

        $response->assertRedirect('/verifikasi-email');
        $response->assertSessionHasErrors('kirim');

        $pesan = implode(' ', session('errors')->get('kirim'));

        $this->assertStringContainsString('Surat gagal dikirim', $pesan);
        $this->assertStringContainsString('hubungi Admin', $pesan);

        foreach (['smtp.rahasia-lessworry.id', 'surat@lessworry.id', 'authentication failed', '587'] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, $pesan, 'Bocor ke layar: '.$rahasia);
        }

        // Yang mentah itu boleh ada di satu tempat saja: log server.
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $p, array $k = []) => str_contains($p, 'Gagal mengirim email verifikasi')
                && str_contains((string) ($k['error'] ?? ''), 'smtp.rahasia-lessworry.id'))
            ->once();
    }

    /** Halaman yang menyusul setelah login gagal-kirim ikut mengatakannya. */
    public function test_halaman_verifikasi_menampilkan_kegagalan_kirim_dari_login(): void
    {
        config(['mail.default' => 'smtp']);
        $user = $this->pengguna();

        $halaman = $this->actingAs($user)
            ->withSession(['kirim_gagal' => true])
            ->get('/verifikasi-email');

        $halaman->assertOk();
        $halaman->assertSee('Surat gagal dikirim');
    }
}
