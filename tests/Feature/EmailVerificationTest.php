<?php

namespace Tests\Feature;

use App\Mail\VerifikasiEmail;
use App\Models\User;
use App\Models\UserAudit;
use App\Services\PengirimVerifikasiEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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
}
