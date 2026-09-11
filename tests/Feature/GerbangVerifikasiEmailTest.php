<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PengirimVerifikasiEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Tiga temuan tinjauan PR #9 yang menuntut test sendiri. (API-37 nomor 1, 2, 3)
 */
class GerbangVerifikasiEmailTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'admin'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'admin',
        ]);
    }

    /* ---------- 1. Kredensial SMTP tidak masuk log ---------- */

    /**
     * Gagal sebelum perbaikannya: `$e->getMessage()` masuk log apa adanya,
     * dan pesan dari DSN yang salah bentuk membawa DSN utuh berikut
     * passwordnya.
     */
    public function test_password_smtp_tidak_ikut_tertulis_ke_log(): void
    {
        $ditulis = [];

        Log::shouldReceive('error')->andReturnUsing(function ($pesan, $konteks = []) use (&$ditulis) {
            $ditulis[] = $pesan.' '.json_encode($konteks);
        });

        Mail::shouldReceive('to->send')->andThrow(
            new TransportException('Could not connect to smtp://budi:rahasia123@mail.example:587')
        );

        $user = User::create([
            'name' => 'Budi', 'email' => 'budi@lessworry.id',
            'password' => 'secret123', 'role' => 'kasir',
        ]);

        app(PengirimVerifikasiEmail::class)->kirim($user, 'permintaan');

        $log = implode("\n", $ditulis);

        $this->assertNotSame('', $log, 'Kegagalan pengiriman tidak tercatat sama sekali.');
        $this->assertStringNotContainsString('rahasia123', $log,
            'Password SMTP tertulis ke log. Aturan repositori ini tidak mengecualikan log server.');
        $this->assertStringNotContainsString('budi:rahasia123', $log);

        // Nama host sengaja dibiarkan: itu yang berguna saat menelusuri.
        $this->assertStringContainsString('mail.example', $log,
            'Nama host ikut terbuang — galatnya jadi tidak bisa ditelusuri.');
    }

    /**
     * Penyensorannya diuji langsung juga, supaya bentuk masukan yang lain
     * tidak lolos hanya karena jalur pengirimnya kebetulan tidak melewatinya.
     */
    public function test_penyensoran_membuang_kredensial_dan_menyisakan_host(): void
    {
        $this->assertSame(
            'Could not connect to smtp://[kredensial-disensor]@mail.example:587',
            PengirimVerifikasiEmail::tanpaKredensial('Could not connect to smtp://budi:rahasia123@mail.example:587')
        );

        // Pesan tanpa kredensial tidak berubah.
        $this->assertSame(
            'Connection refused to mail.example:587',
            PengirimVerifikasiEmail::tanpaKredensial('Connection refused to mail.example:587')
        );

        // Alamat email di dalam pesan bukan kredensial, dan tidak ikut disensor.
        $this->assertSame(
            'Address budi@lessworry.id was rejected',
            PengirimVerifikasiEmail::tanpaKredensial('Address budi@lessworry.id was rejected')
        );
    }

    /* ---------- 2. Ganti huruf besar-kecil alamat ---------- */

    /**
     * Gagal sebelum perbaikannya: fill() menulis nilai mentah, jadi kolomnya
     * berubah, `sha1($user->email)` bergeser, dan tautan verifikasi yang
     * sudah beredar mati diam-diam.
     */
    public function test_tautan_verifikasi_tetap_berlaku_setelah_alamat_diganti_huruf_besar_kecil(): void
    {
        $user = User::create([
            'name' => 'Budi', 'email' => 'budi@lessworry.id',
            'password' => 'secret123', 'role' => 'kasir',
        ]);

        $tautan = app(PengirimVerifikasiEmail::class)->tautan($user);

        $this->actingAs($this->admin())->put('/users/'.$user->id, [
            'name' => 'Budi', 'email' => 'Budi@LessWorry.id', 'role' => 'kasir',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        // Alamatnya disimpan huruf kecil, jadi hash-nya tidak bergeser.
        $this->assertSame('budi@lessworry.id', $user->fresh()->email);

        $this->actingAs($user->fresh())->get($tautan)->assertRedirect();
        $this->assertNotNull($user->fresh()->email_verified_at,
            'Tautan yang sudah beredar mati hanya karena huruf besar-kecil alamatnya diganti.');
    }

    public function test_alamat_baru_disimpan_huruf_kecil_saat_akun_dibuat(): void
    {
        $this->actingAs($this->admin())
            ->post('/users', ['name' => 'Budi', 'email' => 'Budi@LessWorry.id', 'role' => 'kasir'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'budi@lessworry.id']);
        $this->assertDatabaseMissing('users', ['email' => 'Budi@LessWorry.id']);
    }

    /* ---------- 3. Gerbangnya tetap terpasang ---------- */

    /**
     * `TestCase::$verifikasiOtomatis` menandai setiap pengguna terverifikasi,
     * jadi tidak ada satu pun test perilaku yang menangkap rute baru yang
     * lupa memakai `email.verified`. Ini penggantinya, pola yang sama dengan
     * NeviraChokePointTest: dibaca dari daftar rute, bukan dari permintaan.
     *
     * Gagal begitu ada rute di grup `password.changed` yang tidak ikut
     * `email.verified` — yaitu rute yang bisa dibuka akun yang emailnya belum
     * terbukti dipegang pemiliknya.
     */
    public function test_setiap_rute_di_belakang_password_changed_juga_memakai_email_verified(): void
    {
        $bolong = [];

        foreach (Route::getRoutes() as $rute) {
            $middleware = $rute->gatherMiddleware();

            if (! in_array('password.changed', $middleware, true)) {
                continue;
            }

            if (! in_array('email.verified', $middleware, true)) {
                $bolong[] = $rute->uri();
            }
        }

        $this->assertSame([], $bolong,
            'Rute ini bisa dibuka akun yang emailnya belum terverifikasi: '.implode(', ', $bolong));
    }

    /**
     * Gerbangnya benar-benar ada, bukan cuma namanya terdaftar: sedikitnya
     * satu rute inti memakainya. Tanpa penegasan ini, membuang `email.verified`
     * dari SELURUH rute akan membuat test di atas hijau — nol rute
     * `password.changed` yang bolong karena nol rute yang diperiksa.
     */
    public function test_rute_inti_memang_berada_di_belakang_gerbangnya(): void
    {
        foreach (['dashboard', 'complaints.index', 'users.index', 'password.edit'] as $nama) {
            $rute = Route::getRoutes()->getByName($nama);

            $this->assertNotNull($rute, 'Rute '.$nama.' hilang.');
            $this->assertContains('email.verified', $rute->gatherMiddleware(),
                'Rute '.$nama.' tidak lagi di belakang gerbang verifikasi email.');
        }
    }

    /**
     * Sisi sebaliknya, dan ini yang menjaga keputusan API-37 nomor 4: rute
     * verifikasi sendiri TIDAK boleh memakai alias ini. Syarat `routeIs()` di
     * EnsureEmailVerified dipertahankan sebagai jaring pengaman untuk keadaan
     * ini; kalau keadaannya benar-benar terjadi, yang bicara lebih dulu harus
     * test ini, bukan seluruh tim yang tidak bisa masuk.
     */
    public function test_rute_verifikasi_dan_logout_tidak_berada_di_belakang_gerbangnya(): void
    {
        foreach (['verification.notice', 'verification.send', 'verification.verify', 'logout'] as $nama) {
            $rute = Route::getRoutes()->getByName($nama);

            $this->assertNotNull($rute, 'Rute '.$nama.' hilang.');
            $this->assertNotContains('email.verified', $rute->gatherMiddleware(),
                'Rute '.$nama.' digerbangi verifikasi email — halaman verifikasi memantul ke dirinya sendiri.');
        }
    }
}
