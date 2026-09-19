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

    /* ---------- 1a. Tabel penyensoran: satu tempat, semua kasusnya ---------- */

    /**
     * Enam kasus penyensoran, berdampingan, lewat jalur log yang sungguhan.
     *
     * Penyensoran ini sudah tiga kali diperbaiki sepotong-sepotong — API-37,
     * API-120, API-122 — dan tiap kali perbaikannya melebarkan atau
     * menyempitkan pola tanpa ada satu tempat yang memperlihatkan akibatnya
     * pada kasus-kasus lain. Tabel ini tempat itu. Lintasan keempat, kalau
     * suatu hari perlu, dibuka dengan menambah baris di sini lebih dulu.
     *
     * Tiap baris melewati `kirim()` yang sebenarnya, bukan fungsi
     * penyensornya langsung: yang menentukan aman-tidaknya adalah apa yang
     * benar-benar ditulis `Log::error`, bukan keluaran satu lintasan.
     *
     * @return array<string, array{pesan: string, harapan: string}>
     */
    public static function kasusPenyensoran(): array
    {
        return [
            // 1. Alamat email tersensor PENUH. (API-121)
            'alamat email' => [
                'pesan' => 'Address penerima@example.test was rejected',
                'harapan' => 'Address [email-disensor] was rejected',
            ],

            // 2. Password ber-`@` tersensor SELURUHNYA. (API-120)
            'password ber-@' => [
                'pesan' => 'Failed: smtp://smtpuser:p@ssw0rd@smtp.example.test:587 unreachable',
                'harapan' => 'Failed: smtp://[kredensial-disensor]@smtp.example.test:587 unreachable',
            ],

            // 3. Password ber-`/` tersensor SELURUHNYA. (API-122)
            'password ber-/' => [
                'pesan' => 'smtp://user:p@ss/w0rd@smtp.example.test gagal',
                'harapan' => 'smtp://[kredensial-disensor]@smtp.example.test gagal',
            ],

            // 4. Password BERSPASI tersensor SELURUHNYA. (API-122)
            'password berspasi' => [
                'pesan' => 'smtp://user:pa ss@smtp.example.test gagal',
                'harapan' => 'smtp://[kredensial-disensor]@smtp.example.test gagal',
            ],

            // 5. Baris TANPA kredensial IKUT tertelan — harga yang dipilih
            //    sadar, bukan cacat yang terlewat. (API-124)
            //
            // `mail.example` nama host, `abc` teks galat, `:` di antaranya
            // bukan pemisah password. Lintasan kedua menyeberangi spasi
            // sampai `@` milik alamat di ujung kalimat dan menelan ketiganya
            // jadi satu penanda kredensial, padahal tidak ada kredensial di
            // baris ini. Nama host hilang dari log.
            //
            // Itu dipilih karena bentuk ini TIDAK BISA dibedakan dari
            // `smtp://budi.santoso:pa ss@host` — nama pengguna SMTP lazim
            // bertitik karena biasanya ia alamat surel. Percobaan pertama
            // API-124 melarang titik di nama pengguna supaya baris ini utuh;
            // harganya kredensial bertitik + password berspasi lolos utuh ke
            // log. Dari dua kegagalan itu hanya satu yang boleh dipilih, dan
            // kehilangan nama host bukan kebocoran.
            //
            // Jarak alamatnya SENGAJA dua spasi dari `:`. Lintasan kedua
            // dibatasi paling tiga spasi (API-122), jadi kalimat yang lebih
            // panjang tidak tertelan karena kebetulan terlalu jauh. Baris ini
            // harus berada di dalam jangkauan itu supaya yang diuji
            // perilakunya, bukan jaraknya.
            'baris tanpa kredensial' => [
                'pesan' => 'smtp://mail.example:abc gagal penerima@example.test',
                'harapan' => 'smtp://[kredensial-disensor]@example.test',
            ],

            // 6. Kredensial DAN alamat di satu kalimat: dua penanda yang
            //    BERBEDA, dan nama host tetap terbaca di antaranya. Kalau
            //    salah satu lintasan meluber ke wilayah lintasan lain, yang
            //    muncul di sini satu penanda dua kali — bukan satu dari
            //    masing-masing.
            'kredensial dan alamat bersama' => [
                'pesan' => 'Gagal: smtp://user:pa ss@smtp.example.test - alamat penerima@example.test ditolak',
                'harapan' => 'Gagal: smtp://[kredensial-disensor]@smtp.example.test - alamat [email-disensor] ditolak',
            ],
        ];
    }

    /**
     * Tabel di atas, dijalankan lewat `kirim()` yang sebenarnya.
     *
     * Satu pengguna per baris: `RateLimiter` memakai kunci per-pengguna, jadi
     * enam baris atas satu pengguna akan kena batas 3 dan baris keempat
     * seterusnya tidak pernah sampai ke `Log::error`.
     *
     * Nilai di tabel semuanya karangan: alamat memakai TLD `.test` yang
     * dicadangkan RFC 2606, dan tidak satu pun password di situ pernah
     * dipakai di mana pun.
     */
    public function test_tabel_penyensoran_pesan_galat_yang_masuk_log(): void
    {
        $kasus = self::kasusPenyensoran();

        $ditulis = [];

        Log::shouldReceive('error')->andReturnUsing(function ($pesan, $konteks = []) use (&$ditulis) {
            $ditulis[] = $konteks;
        });

        Mail::shouldReceive('to->send')->andThrowExceptions(array_map(
            fn (array $baris) => new TransportException($baris['pesan']),
            array_values($kasus)
        ));

        $pengguna = [];

        foreach (array_keys($kasus) as $i => $nama) {
            $user = User::create([
                'name' => 'Staf '.$i,
                'email' => 'staf'.$i.'@example.test',
                'password' => 'secret123',
                'role' => 'kasir',
            ]);

            $pengguna[$nama] = $user;

            app(PengirimVerifikasiEmail::class)->kirim($user, 'permintaan');
        }

        $this->assertCount(count($kasus), $ditulis,
            'Tidak semua baris tabel sampai ke Log::error.');

        foreach (array_values($kasus) as $i => $baris) {
            $nama = array_keys($kasus)[$i];
            $konteks = $ditulis[$i];

            // Kasus 1-5: bentuk pesannya, persis.
            $this->assertSame($baris['harapan'], $konteks['error'], $nama);

            // Kriteria 4: yang membuat entri ini berguna tetap utuh di SETIAP
            // baris. Ini juga yang membuat penyensoran penuh cukup — siapa
            // yang gagal dikirimi sudah terjawab `user_id` di baris yang sama.
            $this->assertSame($pengguna[$nama]->id, $konteks['user_id'], $nama);
            $this->assertSame(TransportException::class, $konteks['jenis'], $nama);
            $this->assertArrayHasKey('kode', $konteks, $nama);
        }

        // Tidak ada satu pun alamat atau potongan password yang tersisa di
        // seluruh keluaran, dilihat sekaligus.
        $seluruhnya = json_encode($ditulis);

        foreach (['penerima@', 'staf0@', 'ssw0rd', 'ss/w0rd', 'pa ss', 'smtpuser'] as $potongan) {
            $this->assertStringNotContainsString($potongan, (string) $seluruhnya,
                'Potongan yang seharusnya tersensor lolos ke log: '.$potongan);
        }

        // Nama host tetap terbaca pada DSN yang bentuknya jelas — itu
        // satu-satunya petunjuk yang tersisa, dan lintasan kredensial tidak
        // boleh meluber melewatinya.
        $this->assertStringContainsString('smtp.example.test', (string) $seluruhnya);
    }

    /**
     * Batas yang diketahui, dikunci supaya terlihat. (API-121, API-124)
     *
     * Test ini TIDAK menyatakan keduanya benar. Ia menyatakan keduanya
     * diketahui: keluaran di bawah yang akan ditemukan orang di
     * `storage/logs`, dan alasan tiap batas ada di docblock fungsinya. Kalau
     * salah satunya ditutup nanti, test ini yang berubah — bukan sesuatu yang
     * diam-diam bergeser.
     */
    public function test_batas_penyensoran_yang_diketahui(): void
    {
        // `host:teks` dan `pengguna:password` tidak bisa dibedakan sebelum
        // spasi pertama. Yang dipilih saat ambigu: SENSOR. Nama pengguna
        // bertitik dengan password berspasi tersensor penuh — kalau baris
        // pertama ini suatu hari berubah jadi `budi.santoso:pa ...`, yang
        // terjadi kebocoran kredensial, bukan pergeseran gaya keluaran.
        $this->assertSame(
            'smtp://[kredensial-disensor]@smtp.example.test',
            PengirimVerifikasiEmail::amanUntukLog('smtp://budi.santoso:pa ss@smtp.example.test')
        );
        $this->assertSame(
            'smtp://[kredensial-disensor]@smtp.example.test',
            PengirimVerifikasiEmail::amanUntukLog('smtp://budi.santoso:rahasia@smtp.example.test')
        );

        // Sisi harga dari pilihan yang sama: baris tanpa kredensial ikut
        // tersensor dan nama host-nya hilang. Dikunci supaya terlihat — kalau
        // suatu hari ada pembeda yang sah (misalnya daftar host dari
        // konfigurasi), baris inilah yang berubah.
        $this->assertSame(
            'smtp://[kredensial-disensor]@example.test',
            PengirimVerifikasiEmail::amanUntukLog('smtp://mail.example:abc gagal penerima@example.test')
        );

        // Penanda yang muncul harus `[kredensial-disensor]`, bukan
        // `[email-disensor]`: baris yang ditandai sebagai alamat tersensor
        // tidak akan diperiksa lagi oleh siapa pun.
        $this->assertStringNotContainsString(
            '[email-disensor]',
            PengirimVerifikasiEmail::amanUntukLog('smtp://budi.santoso:pa ss@smtp.example.test')
        );

        // Domain tanpa titik bukan alamat yang dikenali. Syarat titik itu yang
        // menjaga penyensoran alamat tidak menelan `user@host` di tengah
        // kalimat galat. Alamat anggota tim selalu berdomain bertitik —
        // kolom `email` divalidasi — jadi jalur yang jadi alasan API-121
        // tertutup penuh.
        $this->assertSame(
            'mailer@localhost gagal',
            PengirimVerifikasiEmail::amanUntukLog('mailer@localhost gagal')
        );
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

        // Alamat email bukan kredensial, jadi lintasan INI tidak menyentuhnya
        // — dan itu yang dikunci di sini, di lapisan yang benar.
        //
        // Sampai API-121 assertion ini memakai `tanpaKredensial()` untuk
        // menyatakan alamatnya boleh lewat ke log. Yang berubah bukan
        // lapisan ini melainkan apa yang boleh sampai ke log: alamat anggota
        // tim data pribadi, dan `tanpaAlamatEmail()` yang membuangnya
        // sesudah lintasan ini. Bentuk yang benar-benar ditulis ke log ada
        // di `kasusPenyensoran()`, baris `alamat email`.
        $this->assertSame(
            'Address penerima@example.test was rejected',
            PengirimVerifikasiEmail::tanpaKredensial('Address penerima@example.test was rejected')
        );
        $this->assertSame(
            'Address [email-disensor] was rejected',
            PengirimVerifikasiEmail::amanUntukLog('Address penerima@example.test was rejected')
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

        // DSN dan alamat di satu kalimat. Assertion ini ditambahkan PR #56
        // untuk mengunci alamatnya tetap utuh saat DSN di kalimat yang sama
        // tersensor. API-121 membalik bagian itu — alamat anggota tim tidak
        // boleh masuk log — tapi apa yang sebenarnya dijaga tidak berubah:
        // penyensoran kredensial tidak boleh meluber. Dua penanda yang
        // BERBEDA membuktikannya; kalau pola kredensial menelan alamatnya,
        // yang muncul `[kredensial-disensor]` dua kali.
        $this->assertSame(
            'Gagal mengirim ke [email-disensor] lewat smtp://[kredensial-disensor]@smtp.example.test',
            PengirimVerifikasiEmail::amanUntukLog(
                'Gagal mengirim ke penerima@example.test lewat smtp://u:p@ssw0rd@smtp.example.test'
            )
        );

        // Tidak ada potongan password yang tersisa di keluaran mana pun.
        foreach ([
            'Failed: smtp://smtpuser:p@ssw0rd@smtp.lessworry.id:587 unreachable',
            'Gagal mengirim ke penerima@example.test lewat smtp://u:p@ssw0rd@smtp.example.test',
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
     * bergeser dari "kurang menyensor" ke "menelan alamat penerima".
     *
     * Yang diuji di sini `tanpaKredensial()` SENDIRIAN, dan itu sengaja:
     * pola kredensial tidak boleh meluber melewati batas DSN. Sejak API-121
     * alamat penerima memang tidak lagi sampai ke log — `tanpaAlamatEmail()`
     * yang membuangnya sesudah lintasan ini — tapi ia harus dibuang oleh
     * lintasan yang BENAR. Kalau pola kredensial yang menelannya, nama host
     * ikut hilang, dan itu regresi meski lognya terlihat sama amannya.
     * Bentuk yang benar-benar ditulis ke log ada di `kasusPenyensoran()`.
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

    /**
     * Tanda baca kalimat sesudah DSN tidak boleh membatalkan penyensoran. (API-122)
     *
     * Kegagalan yang ditemukan Maldini di gerbang merge, 19 Sep 2026. Kedua
     * pagar di lintasan kedua memakai daftar pembatas `[\s/,)"]`. Daftar itu
     * memuat tanda baca yang kebetulan terpikir, bukan aturan; `!` `;` `?`
     * `>` `]` tidak ada di dalamnya. Akibatnya DSN yang diakhiri salah satu
     * tanda itu — bentuk yang lazim di pesan galat dan di kutipan log — lolos
     * utuh, nama pengguna dan password sekaligus.
     *
     * Satu baris per tanda, supaya yang berikutnya tahu tanda mana yang
     * dikunci dan tidak mengira daftarnya masih bisa dipendekkan.
     */
    public function test_penyensoran_tidak_dibatalkan_tanda_baca_sesudah_host(): void
    {
        foreach ([
            'smtp://user:pa ss@smtp.lessworry.id!',
            'smtp://user:pa ss@smtp.lessworry.id;',
            'smtp://user:pa ss@smtp.lessworry.id?',
            'smtp://user:pa ss@smtp.lessworry.id>',
            'smtp://user:pa ss@smtp.lessworry.id]',
        ] as $pesan) {
            $keluaran = PengirimVerifikasiEmail::tanpaKredensial($pesan);
            $tanda = substr($pesan, -1);

            $this->assertSame(
                'smtp://[kredensial-disensor]@smtp.lessworry.id'.$tanda,
                $keluaran,
                $pesan
            );

            $this->assertStringNotContainsString('user', $keluaran, $pesan);
            $this->assertStringNotContainsString('pa ss', $keluaran, $pesan);
        }

        // Nomor port di antara host dan tanda bacanya tidak mengubah apa pun.
        foreach (['!', ';', '?', '>', ']'] as $tanda) {
            $pesan = 'Gagal: smtp://user:my secret pass@smtp.lessworry.id:587'.$tanda;

            $this->assertSame(
                'Gagal: smtp://[kredensial-disensor]@smtp.lessworry.id:587'.$tanda,
                PengirimVerifikasiEmail::tanpaKredensial($pesan),
                $pesan
            );
        }
    }

    /**
     * Tanda baca sesudah nomor port tidak boleh membuat port dibaca password. (API-122)
     *
     * Sisi sebaliknya dari daftar pembatas yang sama, dan lebih berbahaya
     * daripada terlihat: `smtp://mail.example:587!` tidak memuat kredensial
     * sama sekali, tapi pagar port menolak mengenali `587` sebagai port karena
     * `!` bukan anggota daftar. Lintasan kedua lalu menyeberang sampai `@`
     * pada alamat penerima, membuang nama host DAN alamatnya, lalu menstempel
     * `[kredensial-disensor]` di atasnya. Barisnya tampak sudah ditangani
     * padahal yang dibuang justru satu-satunya keterangan yang berguna.
     */
    public function test_penyensoran_mengenali_port_meski_diikuti_tanda_baca(): void
    {
        foreach (['!', ';', '?', '>', ']'] as $tanda) {
            foreach ([
                'smtp://mail.example:587'.$tanda,
                'smtp://mail.example:587'.$tanda.' untuk budi@lessworry.id',
                'Gagal: smtp://mail.example:587'.$tanda.' hubungi budi@lessworry.id',
            ] as $pesan) {
                $this->assertSame(
                    $pesan,
                    PengirimVerifikasiEmail::tanpaKredensial($pesan),
                    'DSN tanpa kredensial ikut tersensor: '.$pesan
                );
            }
        }
    }

    /**
     * Satu kata di antara host dan alamat penerima. (API-126)
     *
     * Yang membuat `test_penyensoran_tidak_menelan_alamat_penerima_atau_host`
     * lulus di `7cac3e2` bukan pagarnya, tapi jumlah kata kalimatnya: contoh
     * `... - alamat budi@...` butuh EMPAT spasi untuk sampai ke alamatnya,
     * jadi ia jatuh di luar batas tiga spasi. Ganti `- alamat` jadi satu kata
     * dan pola yang sama menelan nama host DAN alamat penerima sekaligus, lalu
     * menstempel barisnya `[kredensial-disensor]` sehingga tampak sudah
     * ditangani dengan benar.
     *
     * Sebabnya: `{0,3}?` memang lazy pada JUMLAH pengulangan, tapi
     * `[^\s\r\n]*` di dalam tiap pengulangan greedy. PCRE menambah pengulangan
     * sebelum memundurkan bintang di pengulangan sebelumnya, jadi yang
     * terpilih `@` TERJAUH yang masih terjangkau — bukan yang pertama.
     *
     * Nol sampai dua kata dikunci satu per satu. Di tiga spasi ke atas polanya
     * menolak karena batas jangkauan, dan test yang lulus karena batas itu
     * tidak membuktikan apa pun soal `@` mana yang dipilih.
     */
    public function test_penyensoran_menyisakan_host_meski_satu_kata_memisah_alamat(): void
    {
        foreach ([
            // Nol kata: alamatnya langsung sesudah host.
            'budi@lessworry.id',
            // Satu kata — bentuk yang gagal di `7cac3e2`.
            'hubungi budi@lessworry.id',
            // Dua kata, masih di dalam jangkauan tiga spasi.
            'kirim ke budi@lessworry.id',
        ] as $ekor) {
            $pesan = 'smtp://user:pa ss@smtp.lessworry.id '.$ekor;

            $keluaran = PengirimVerifikasiEmail::tanpaKredensial($pesan);

            $this->assertSame(
                'smtp://[kredensial-disensor]@smtp.lessworry.id '.$ekor,
                $keluaran,
                $pesan
            );

            // Kredensialnya tetap hilang — perbaikannya tidak menukar satu
            // kesalahan dengan kesalahan yang lebih buruk.
            $this->assertStringNotContainsString('user', $keluaran, $pesan);
            $this->assertStringNotContainsString('pa ss', $keluaran, $pesan);
        }

        // Password yang memuat `@` DAN spasi, dengan satu kata sebelum alamat.
        $this->assertSame(
            'smtp://[kredensial-disensor]@smtp.host hubungi budi@lessworry.id',
            PengirimVerifikasiEmail::tanpaKredensial(
                'smtp://user:p@ s@smtp.host hubungi budi@lessworry.id'
            )
        );
    }

    /**
     * Password berspasi yang juga memuat `@` tetap tersensor PENUH. (API-126)
     *
     * Pagar arah sebaliknya untuk test di atas, dan alasan perbaikannya bukan
     * dua karakter. Membuat bintangnya lazy saja memilih `@` PERTAMA yang
     * lolos lookahead; pada `pa ss@w0rd@host` `@` pertama itu ada di TENGAH
     * password, dan `w0rd` tertinggal di log. Diukur di korpus 15.552 bentuk:
     * lazy saja membocorkan 1.728 bentuk yang pola greedy sensor.
     *
     * Yang menahannya `@` di kelas pembatas sesudah host — host yang langsung
     * diikuti `@` bukan host, jadi kandidatnya ditolak dan pola memundur ke
     * `@` berikutnya. Kedua perubahan itu sepasang; melepas salah satunya
     * mengembalikan salah satu dari dua kesalahan.
     */
    public function test_penyensoran_tidak_berhenti_di_at_tengah_password_berspasi(): void
    {
        foreach ([
            'smtp://user:pa ss@w0rd@smtp.lessworry.id' => ['w0rd', 'pa ss'],
            'smtp://user:pa ss@w0rd@smtp.lessworry.id:587' => ['w0rd', 'pa ss'],
            'smtp://user:a b@c@smtp.lessworry.id' => ['b@c', 'a b'],
            'Gagal: smtp://user:pa ss@w0rd@smtp.lessworry.id hubungi budi@lessworry.id' => ['w0rd', 'pa ss'],
        ] as $pesan => $potongan) {
            $keluaran = PengirimVerifikasiEmail::tanpaKredensial($pesan);

            // Nama host tetap ada: sensornya penuh, bukan menelan.
            $this->assertStringContainsString('smtp.lessworry.id', $keluaran, $pesan);

            foreach ($potongan as $bagian) {
                $this->assertStringNotContainsString(
                    $bagian,
                    $keluaran,
                    'Sebagian password lolos ke keluaran: '.$pesan
                );
            }
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
