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

    /**
     * Password yang memuat `@` harus tersensor SELURUHNYA. (API-120)
     *
     * Pola sebelumnya berhenti di `@` pertama, jadi `p@ssw0rd` hanya tersensor
     * sampai `p` dan `ssw0rd` tetap masuk log. Nilai di bawah karangan.
     */
    public function test_penyensoran_membuang_password_yang_memuat_at(): void
    {
        $this->assertSame(
            'Failed: smtp://[kredensial-disensor]@smtp.lessworry.id:587 unreachable',
            PengirimVerifikasiEmail::tanpaKredensial(
                'Failed: smtp://smtpuser:p@ssw0rd@smtp.lessworry.id:587 unreachable'
            )
        );

        // Password tanpa `@` tetap tersensor seperti sebelumnya.
        $this->assertSame(
            'smtp://[kredensial-disensor]@smtp.lessworry.id:587',
            PengirimVerifikasiEmail::tanpaKredensial('smtp://user:simple@smtp.lessworry.id:587')
        );

        // Alamat email di kalimat yang sama tidak ikut tersensor, sementara
        // DSN-nya tersensor penuh.
        $this->assertSame(
            'Gagal mengirim ke budi@lessworry.id lewat smtp://[kredensial-disensor]@smtp.lessworry.id',
            PengirimVerifikasiEmail::tanpaKredensial(
                'Gagal mengirim ke budi@lessworry.id lewat smtp://u:p@ssw0rd@smtp.lessworry.id'
            )
        );

        // Tidak ada potongan password yang tersisa di keluaran mana pun.
        foreach ([
            'Failed: smtp://smtpuser:p@ssw0rd@smtp.lessworry.id:587 unreachable',
            'Gagal mengirim ke budi@lessworry.id lewat smtp://u:p@ssw0rd@smtp.lessworry.id',
        ] as $pesan) {
            $this->assertStringNotContainsString(
                'ssw0rd',
                PengirimVerifikasiEmail::tanpaKredensial($pesan),
                'Sebagian password lolos ke keluaran.'
            );
        }
    }

    /**
     * Password yang memuat `/` atau spasi harus tersensor SELURUHNYA. (API-122)
     *
     * Gagal dengan pola sebelumnya: `[^\s/]` membatasi bagian password juga,
     * jadi password ber-`/` hanya tersensor sampai potongan pertama, dan
     * password berspasi tidak cocok sama sekali — seluruh DSN lolos utuh ke
     * log tanpa satu pun tanda bahwa penyensoran gagal. Nilai di bawah
     * karangan.
     */
    public function test_penyensoran_membuang_password_ber_garis_miring_dan_berspasi(): void
    {
        // Password ber-`/`. Pola lama menyisakan `ss/w0rd` di log.
        $this->assertSame(
            'smtp://[kredensial-disensor]@smtp.lessworry.id',
            PengirimVerifikasiEmail::tanpaKredensial('smtp://user:p@ss/w0rd@smtp.lessworry.id')
        );

        // Password berspasi. Pola lama tidak cocok sama sekali.
        $this->assertSame(
            'smtp://[kredensial-disensor]@smtp.lessworry.id',
            PengirimVerifikasiEmail::tanpaKredensial('smtp://user:pa ss@smtp.lessworry.id')
        );

        // Spasi lebih dari satu, dan `/` bersama spasi.
        $this->assertSame(
            'smtp://[kredensial-disensor]@smtp.lessworry.id',
            PengirimVerifikasiEmail::tanpaKredensial('smtp://user:my secret pass@smtp.lessworry.id')
        );
        $this->assertSame(
            'smtp://[kredensial-disensor]@smtp.lessworry.id',
            PengirimVerifikasiEmail::tanpaKredensial('smtp://user:pa ss/w0rd@smtp.lessworry.id')
        );

        // `@` dan spasi sekaligus: pola lama menyisakan ` s` di log.
        $this->assertSame(
            'smtp://[kredensial-disensor]@smtp.host',
            PengirimVerifikasiEmail::tanpaKredensial('smtp://user:p@ s@smtp.host')
        );

        // Tidak ada potongan password yang tersisa di keluaran mana pun.
        foreach ([
            'smtp://user:p@ss/w0rd@smtp.lessworry.id' => ['w0rd', 'ss/'],
            'smtp://user:pa ss@smtp.lessworry.id' => ['pa ss', 'ss@smtp'],
            'smtp://user:my secret pass@smtp.lessworry.id' => ['secret', 'pass@'],
            'smtp://user:p@ s@smtp.host' => [' s@'],
        ] as $pesan => $potongan) {
            $keluaran = PengirimVerifikasiEmail::tanpaKredensial($pesan);

            foreach ($potongan as $bagian) {
                $this->assertStringNotContainsString(
                    $bagian,
                    $keluaran,
                    'Sebagian password lolos ke keluaran: '.$pesan
                );
            }
        }
    }

    /**
     * Pagar arah sebaliknya. (API-122)
     *
     * Melepas pembatas `/` membuat polanya lebih longgar, jadi risikonya
     * bergeser dari "kurang menyensor" ke "menelan alamat penerima". Alamat
     * penerima bukan kredensial: kalau ia hilang dari log, itu regresi meski
     * lognya terlihat lebih aman.
     */
    public function test_penyensoran_tidak_menelan_alamat_penerima_atau_host(): void
    {
        // Alamat surel di kalimat yang sama, DSN-nya berpassword spasi.
        $this->assertSame(
            'Gagal: smtp://[kredensial-disensor]@smtp.lessworry.id - alamat budi@lessworry.id ditolak',
            PengirimVerifikasiEmail::tanpaKredensial(
                'Gagal: smtp://user:pa ss@smtp.lessworry.id - alamat budi@lessworry.id ditolak'
            )
        );

        // Alamat surel SESUDAH DSN-nya, tanpa spasi di kredensial.
        $this->assertSame(
            'smtp://[kredensial-disensor]@host lalu ke budi@lessworry.id',
            PengirimVerifikasiEmail::tanpaKredensial('smtp://u:p@host lalu ke budi@lessworry.id')
        );

        // DSN tanpa kredensial tidak berubah — `:587` nomor port, bukan
        // password, meski ada alamat surel di kalimat yang sama.
        $this->assertSame(
            'smtp://mail.example:587',
            PengirimVerifikasiEmail::tanpaKredensial('smtp://mail.example:587')
        );
        $this->assertSame(
            'Could not connect to smtp://mail.example:587 for user budi@lessworry.id',
            PengirimVerifikasiEmail::tanpaKredensial(
                'Could not connect to smtp://mail.example:587 for user budi@lessworry.id'
            )
        );

        // Penyensoran tidak melompati baris.
        $this->assertSame(
            "smtp://mail.example:587\nAddress budi@lessworry.id rejected",
            PengirimVerifikasiEmail::tanpaKredensial(
                "smtp://mail.example:587\nAddress budi@lessworry.id rejected"
            )
        );

        // Jalur pada DSN yang sah tetap terbaca.
        $this->assertSame(
            'smtp://[kredensial-disensor]@host/path',
            PengirimVerifikasiEmail::tanpaKredensial('smtp://user:pass@host/path')
        );
    }

    /**
     * Password yang memuat `,`, `)`, atau `"` harus tersensor PENUH. (API-122)
     *
     * Gagal dengan percobaan pertama API-122: ketiga karakter itu dipakai
     * sebagai pembatas kelas, jadi polanya putus dan DSN lolos utuh — termasuk
     * nama penggunanya. Pola sebelum API-122 sudah menutup ketiganya, jadi ini
     * pagar regresi, bukan fitur baru. Ketiganya lazim di password buatan
     * generator. Nilai di bawah karangan.
     */
    public function test_penyensoran_membuang_password_ber_koma_kurung_dan_kutip(): void
    {
        foreach ([
            'smtp://user:pa,ss@smtp.lessworry.id',
            'smtp://user:pa)ss@smtp.lessworry.id',
            'smtp://user:pa"ss@smtp.lessworry.id',
            'smtp://user:pa, ss@smtp.lessworry.id',
        ] as $pesan) {
            $keluaran = PengirimVerifikasiEmail::tanpaKredensial($pesan);

            $this->assertSame('smtp://[kredensial-disensor]@smtp.lessworry.id', $keluaran, $pesan);

            // Nama pengguna ikut kredensial, bukan cuma passwordnya.
            $this->assertStringNotContainsString('user', $keluaran, $pesan);
            $this->assertStringNotContainsString('pa', $keluaran, $pesan);
        }
    }

    /**
     * `:` diikuti teks non-angka tidak boleh menelan host dan alamat. (API-122)
     *
     * Gagal dengan percobaan pertama API-122: pagar port hanya menahan angka,
     * jadi `scheme://host:teks` membuat lintasan kedua menyeberangi spasi
     * sampai `@` mana pun yang diikuti host masuk akal — dan yang ia temukan
     * alamat penerima. Tidak ada kredensial yang bocor di situ; yang hilang
     * justru nama host dan isi pesan galatnya, dua hal yang sengaja disisakan.
     */
    public function test_penyensoran_tidak_menelan_kalimat_saat_titik_dua_diikuti_teks(): void
    {
        foreach ([
            'Failed to connect to smtp://mail.example: timeout - beritahu budi@lessworry.id',
            'smtp://mail.example:sesuatu gagal kirim ke budi@lessworry.id',
            'smtp://mail.example:587000 gagal kirim ke budi@lessworry.id',
            // Nama pengguna DSN tidak pernah memuat `/`: yang ini JALUR.
            'Connection to https://api.nevira.id/v1:abc failed contact budi@lessworry.id',
            'GET https://api.nevira.id/v1/outlets:ok gagal budi@lessworry.id',
        ] as $pesan) {
            $this->assertSame(
                $pesan,
                PengirimVerifikasiEmail::tanpaKredensial($pesan),
                'Teks di luar kredensial ikut tertelan: '.$pesan
            );
        }
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
