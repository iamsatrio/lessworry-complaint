<?php

namespace Tests\Feature;

use App\Models\Complaint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * backup:database dan backup:verify. (API-27)
 *
 * Suite ini berjalan di atas SQLite, jadi yang diuji adalah jalur SQLite-nya.
 * Yang berlaku untuk kedua driver diuji di sini juga: penamaan, rotasi, batas
 * direktori, dan penolakan menghapus saat dump gagal.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('app/testing-backup-'.uniqid());
        mkdir($this->dir, 0750, true);
        config(['backup.path' => $this->dir, 'backup.keep' => 7]);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir.'/{,.}[!.,!..]*', GLOB_BRACE) as $berkas) {
            if (is_string($berkas) && is_file($berkas)) {
                @unlink($berkas);
            }
        }

        @rmdir($this->dir);

        parent::tearDown();
    }

    private function complaint(string $deskripsi = 'x'): Complaint
    {
        $complaint = new Complaint([
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor', 'category' => 'hasil_cuci',
            'priority' => 'medium', 'description' => $deskripsi,
        ]);
        $complaint->ticket_number = Complaint::nextTicketNumber();
        $complaint->created_at = now();
        $complaint->applySla();
        $complaint->save();

        return $complaint;
    }

    /** @return array<int,string> nama berkas backup, terbaru lebih dulu */
    private function berkas(): array
    {
        $nama = array_values(array_filter(
            (array) scandir($this->dir),
            fn ($n) => is_string($n) && preg_match('/^db-.*\.gz$/', $n)
        ));

        rsort($nama, SORT_STRING);

        return $nama;
    }

    private function palsukanBackupLama(int $jumlah): void
    {
        for ($i = 1; $i <= $jumlah; $i++) {
            file_put_contents(
                $this->dir.'/db-2020-01-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'-020000.sql.gz',
                gzencode('backup lama')
            );
        }
    }

    public function test_menghasilkan_berkas_terkompresi_yang_tidak_kosong(): void
    {
        $this->complaint();

        $this->artisan('backup:database')->assertSuccessful();

        $berkas = $this->berkas();
        $this->assertCount(1, $berkas);

        $path = $this->dir.'/'.$berkas[0];
        $this->assertGreaterThan(0, filesize($path));

        // Benar-benar gzip, bukan berkas biasa bernama .gz.
        $this->assertSame("\x1f\x8b", substr((string) file_get_contents($path), 0, 2));
        $this->assertNotFalse(gzopen($path, 'rb'));
    }

    public function test_verify_melaporkan_jumlah_baris_complaints_yang_sama(): void
    {
        $this->complaint('satu');
        $this->complaint('dua');
        $this->complaint('tiga');

        $this->artisan('backup:database')->assertSuccessful();

        $this->artisan('backup:verify')
            ->expectsOutputToContain('Baris complaints di backup : 3')
            ->expectsOutputToContain('Baris complaints sekarang  : 3')
            ->expectsOutputToContain('Cocok.')
            ->assertSuccessful();
    }

    public function test_verify_tidak_menyentuh_database_asli(): void
    {
        $this->complaint();
        $this->artisan('backup:database')->assertSuccessful();

        $sebelum = Complaint::pluck('ticket_number')->all();

        $this->artisan('backup:verify')->assertSuccessful();

        $this->assertSame($sebelum, Complaint::pluck('ticket_number')->all());
    }

    public function test_verify_menolak_berkas_di_luar_direktori_backup(): void
    {
        $luar = storage_path('app/bukan-backup.sql.gz');
        file_put_contents($luar, gzencode('apa saja'));

        try {
            $this->artisan('backup:verify', ['file' => $luar])
                ->expectsOutputToContain('bukan backup di direktori backup')
                ->assertFailed();
        } finally {
            @unlink($luar);
        }
    }

    public function test_verify_menolak_backup_yang_isinya_rusak(): void
    {
        // Berkas bernama benar, ukurannya wajar, tapi isinya bukan database.
        // Persis kasus yang tidak bisa dibedakan tanpa memulihkannya.
        file_put_contents($this->dir.'/db-2030-01-01-020000.sql.gz', gzencode(str_repeat('bukan database', 500)));

        $this->artisan('backup:verify')->assertFailed();
    }

    public function test_sembilan_backup_lama_menyisakan_tepat_tujuh_yang_terbaru(): void
    {
        $this->palsukanBackupLama(9);
        $this->complaint();

        $this->artisan('backup:database')->assertSuccessful();

        $berkas = $this->berkas();

        $this->assertCount(7, $berkas);

        // Yang tersisa harus yang TERBARU: dump yang baru dibuat, lalu enam
        // tanggal tertinggi dari yang lama.
        $this->assertStringStartsWith('db-'.now()->format('Y-m-d'), $berkas[0]);
        $this->assertContains('db-2020-01-09-020000.sql.gz', $berkas);
        $this->assertNotContains('db-2020-01-01-020000.sql.gz', $berkas);
        $this->assertNotContains('db-2020-01-03-020000.sql.gz', $berkas);
    }

    public function test_rotasi_tidak_menyentuh_berkas_lain_di_direktori_backup(): void
    {
        $this->palsukanBackupLama(9);

        $catatan = $this->dir.'/CATATAN.txt';
        $manual = $this->dir.'/dump-manual-sebelum-migrasi.sql.gz';
        file_put_contents($catatan, 'jangan dihapus');
        file_put_contents($manual, gzencode('dump manual'));

        $this->artisan('backup:database')->assertSuccessful();

        $this->assertFileExists($catatan);
        $this->assertFileExists($manual);
    }

    public function test_rotasi_tidak_menyentuh_apa_pun_di_luar_direktori_backup(): void
    {
        $this->palsukanBackupLama(9);

        $luar = storage_path('app/db-2019-01-01-020000.sql.gz');
        file_put_contents($luar, gzencode('di luar direktori backup'));

        try {
            $this->artisan('backup:database')->assertSuccessful();
            $this->assertFileExists($luar);
        } finally {
            @unlink($luar);
        }
    }

    public function test_dump_yang_gagal_tidak_menghapus_backup_lama(): void
    {
        $this->palsukanBackupLama(9);

        // Driver yang belum didukung: dumpnya gagal sebelum satu byte pun
        // ditulis. Rotasi tidak boleh berjalan — kalau berjalan, backup yang
        // masih baik ikut terbuang justru saat backup baru tidak ada.
        config(['database.default' => 'pgsql']);

        Log::spy();

        try {
            $this->artisan('backup:database')->assertFailed();
        } finally {
            // Dikembalikan sebelum tearDown: RefreshDatabase membatalkan
            // transaksinya lewat koneksi bawaan.
            config(['database.default' => 'sqlite']);
        }

        $this->assertCount(9, $this->berkas());

        // Gagal diam-diam sama saja dengan tidak punya backup.
        Log::shouldHaveReceived('error')->once();
    }

    public function test_dump_setengah_jadi_tidak_pernah_bernama_seperti_backup(): void
    {
        $this->complaint();
        $this->artisan('backup:database')->assertSuccessful();

        // Tidak ada sisa berkas sementara: yang bernama db-*.gz sudah selesai
        // ditulis, dan yang belum selesai tidak pernah dibaca sebagai backup.
        $sisa = array_values(array_filter(
            (array) scandir($this->dir),
            fn ($n) => is_string($n) && str_starts_with($n, '.tmp-')
        ));

        $this->assertSame([], $sisa);
    }

    public function test_backup_kedua_yang_berjalan_bersamaan_tidak_merusak_berkas(): void
    {
        $this->complaint();

        // Meniru proses lain yang sedang memegang kunci.
        $kunci = fopen($this->dir.'/.lock', 'c');
        flock($kunci, LOCK_EX);

        try {
            $this->artisan('backup:database')
                ->expectsOutputToContain('Backup lain sedang berjalan')
                ->assertSuccessful();

            $this->assertSame([], $this->berkas());
        } finally {
            flock($kunci, LOCK_UN);
            fclose($kunci);
        }
    }

    public function test_keep_nol_tidak_menghapus_semua_backup(): void
    {
        $this->palsukanBackupLama(9);

        $this->artisan('backup:database', ['--keep' => 0])->assertSuccessful();

        // 9 lama + 1 baru. Salah ketik di .env tidak boleh berarti kehilangan
        // seluruh riwayat backup.
        $this->assertCount(10, $this->berkas());
    }

    /* ---------- Dump tidak dipercaya isinya (temuan T2 di PR #2) ---------- */

    private function tulisBackup(string $nama, string $sql): string
    {
        $path = $this->dir.'/'.$nama;
        file_put_contents($path, gzencode($sql));

        return $path;
    }

    public function test_verify_menolak_dump_yang_memuat_use(): void
    {
        // Persis bentuk keluaran `mysqldump --databases` — cara paling umum
        // orang membuat dump manual. Klien mysql mematuhi USE, jadi tanpa
        // penolakan ini restore pindah ke database produksi dan menimpanya.
        $this->tulisBackup('db-2030-01-01-020000.sql.gz', <<<'SQL'
        -- MySQL dump 10.13
        USE `lessworry_care`;
        CREATE TABLE `complaints` (`id` int);
        SQL);

        $this->artisan('backup:verify')
            ->expectsOutputToContain('USE')
            ->assertFailed();
    }

    public function test_verify_menolak_dump_yang_memuat_create_database(): void
    {
        $this->tulisBackup('db-2030-01-02-020000.sql.gz', <<<'SQL'
        CREATE DATABASE /*!32312 IF NOT EXISTS*/ `lessworry_care`;
        CREATE TABLE `complaints` (`id` int);
        SQL);

        $this->artisan('backup:verify')
            ->expectsOutputToContain('CREATE DATABASE')
            ->assertFailed();
    }

    public function test_verify_menolak_attach_dan_tidak_menyentuh_berkas_yang_dituju(): void
    {
        // Padanan USE di SQLite: ATTACH menempelkan berkas database lain,
        // dan baris berikutnya menulis ke sana.
        $sasaran = storage_path('app/sasaran-'.uniqid().'.sqlite');
        file_put_contents($sasaran, '');

        $this->tulisBackup('db-2030-01-03-020000.sql.gz', <<<SQL
        CREATE TABLE complaints (id integer);
        ATTACH DATABASE '{$sasaran}' AS korban;
        CREATE TABLE korban.dirusak (id integer);
        SQL);

        try {
            $this->artisan('backup:verify')
                ->expectsOutputToContain('ATTACH DATABASE')
                ->assertFailed();

            // Ditolak SEBELUM satu byte pun dijalankan.
            $this->assertSame('', (string) file_get_contents($sasaran));
        } finally {
            @unlink($sasaran);
        }
    }

    public function test_dump_buatan_sendiri_tidak_pernah_memuat_perintah_yang_ditolak(): void
    {
        $this->complaint();
        $this->artisan('backup:database')->assertSuccessful();

        $isi = (string) file_get_contents('compress.zlib://'.$this->dir.'/'.$this->berkas()[0]);

        foreach (['USE `', 'CREATE DATABASE', 'CREATE SCHEMA', 'ATTACH DATABASE'] as $terlarang) {
            $this->assertStringNotContainsStringIgnoringCase($terlarang, $isi);
        }

        // Dan dump buatan sendiri tetap lolos pemeriksaannya.
        $this->artisan('backup:verify')->assertSuccessful();
    }

    /* ---------- Sisa berkas sementara ---------- */

    public function test_sisa_dump_yang_ditinggalkan_proses_mati_disapu(): void
    {
        $lama = $this->dir.'/.tmp-999-abandoned.gz';
        file_put_contents($lama, 'dump yang tidak selesai');
        touch($lama, now()->subHours(9)->getTimestamp());

        $baru = $this->dir.'/.tmp-1000-berjalan.gz';
        file_put_contents($baru, 'dump proses lain yang masih berjalan');

        $this->complaint();
        $this->artisan('backup:database')->assertSuccessful();

        $this->assertFileDoesNotExist($lama);

        // Yang baru dibiarkan: bisa jadi milik proses yang sedang menulis.
        $this->assertFileExists($baru);

        // Kunci tidak ikut disapu — menghapusnya membuat dua proses bisa
        // memegang kunci pada inode yang berbeda.
        $this->assertFileExists($this->dir.'/.lock');
    }

    public function test_verify_menolak_attach_yang_disembunyikan_sebaris_dan_tidak_menulis_ke_korban(): void
    {
        // Bentuk persis yang menembus versi per baris: ATTACH ada di baris yang
        // sama, sesudah titik koma. Dijalankan sampai menulis ke berkas
        // database di luar direktori backup, dan perintahnya keluar dengan
        // exit code 0 — terbaca sebagai verifikasi yang berhasil.
        $korban = storage_path('app/korban-'.uniqid().'.sqlite');
        file_put_contents($korban, '');

        $this->tulisBackup('db-2030-01-04-020000.sql.gz',
            "CREATE TABLE complaints (id INTEGER);\n"
            ."INSERT INTO complaints VALUES (1);ATTACH DATABASE '{$korban}' AS korban;"
            .'CREATE TABLE korban.bukti (x TEXT);'
            ."INSERT INTO korban.bukti VALUES ('ditulis');"
        );

        try {
            $this->artisan('backup:verify')
                ->expectsOutputToContain('ATTACH DATABASE')
                ->assertFailed();

            $this->assertSame('', (string) file_get_contents($korban));
        } finally {
            @unlink($korban);
        }
    }

    public function test_verify_menolak_attach_di_belakang_komentar_blok(): void
    {
        $korban = storage_path('app/korban-'.uniqid().'.sqlite');
        file_put_contents($korban, '');

        $this->tulisBackup('db-2030-01-05-020000.sql.gz',
            "CREATE TABLE complaints (id INTEGER);\n"
            ."/* x */ATTACH DATABASE '{$korban}' AS korban;"
            .'CREATE TABLE korban.bukti (x TEXT);'
        );

        try {
            $this->artisan('backup:verify')
                ->expectsOutputToContain('ATTACH DATABASE')
                ->assertFailed();

            $this->assertSame('', (string) file_get_contents($korban));
        } finally {
            @unlink($korban);
        }
    }

    public function test_verify_tetap_menerima_dump_yang_datanya_memuat_kata_terlarang(): void
    {
        // Complaint yang isinya menyebut ATTACH DATABASE tidak boleh membuat
        // backupnya sendiri gagal diverifikasi.
        $this->complaint("pelanggan menulis: ';ATTACH DATABASE ini;'");

        $this->artisan('backup:database')->assertSuccessful();
        $this->artisan('backup:verify')->assertSuccessful();
    }

    public function test_verify_menolak_attach_yang_disorong_lewat_batas_baca_dengan_spasi(): void
    {
        // Batas baca pemindai dulu bisa dipenuhi lebih dulu oleh 300 spasi,
        // sampai kata perintahnya tidak pernah ikut terbaca. Diuji lewat
        // perintah sungguhan, bukan cuma di unit test pemindainya.
        $korban = storage_path('app/korban-'.uniqid().'.sqlite');
        file_put_contents($korban, '');

        $this->tulisBackup('db-2030-01-06-020000.sql.gz',
            "CREATE TABLE complaints (id INTEGER);\n"
            .'INSERT INTO complaints VALUES (1);'.str_repeat(' ', 300)
            ."ATTACH DATABASE '{$korban}' AS korban;"
            .'CREATE TABLE korban.bukti (x TEXT);'
        );

        try {
            $this->artisan('backup:verify')
                ->expectsOutputToContain('ATTACH DATABASE')
                ->assertFailed();

            $this->assertSame('', (string) file_get_contents($korban));
        } finally {
            @unlink($korban);
        }
    }

    public function test_verify_menolak_attach_yang_disorong_dengan_baris_baru(): void
    {
        $korban = storage_path('app/korban-'.uniqid().'.sqlite');
        file_put_contents($korban, '');

        $this->tulisBackup('db-2030-01-07-020000.sql.gz',
            "CREATE TABLE complaints (id INTEGER);\n"
            .'INSERT INTO complaints VALUES (1);'.str_repeat("\n", 300)
            ."ATTACH DATABASE '{$korban}' AS korban;"
        );

        try {
            $this->artisan('backup:verify')->assertFailed();
            $this->assertSame('', (string) file_get_contents($korban));
        } finally {
            @unlink($korban);
        }
    }

    public function test_verify_di_mysql_menolak_jalan_tanpa_koneksi_pemulihan_terpisah(): void
    {
        $this->tulisBackup('db-2030-01-08-020000.sql.gz', 'CREATE TABLE complaints (id INTEGER);');

        config(['backup.verify_connection' => null, 'database.default' => 'mysql']);

        try {
            // Gagal ke arah aman: memulihkan dengan pengguna aplikasi berarti
            // dump yang menulis ke `produksi.complaints` dengan nama lengkap
            // tidak ditahan oleh apa pun — --one-database hanya mengikuti USE.
            $this->artisan('backup:verify')
                ->expectsOutputToContain('BACKUP_VERIFY_CONNECTION')
                ->assertFailed();
        } finally {
            config(['database.default' => 'sqlite']);
        }
    }

    public function test_verify_di_mysql_menolak_koneksi_yang_penggunanya_sama_dengan_aplikasi(): void
    {
        $this->tulisBackup('db-2030-01-09-020000.sql.gz', 'CREATE TABLE complaints (id INTEGER);');

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.username' => 'care',
            'database.connections.mysql_verify.username' => 'care',
            'backup.verify_connection' => 'mysql_verify',
        ]);

        try {
            // Nama koneksi yang berbeda tapi pengguna yang sama = hak akses
            // yang sama = tidak ada pemisahan sama sekali.
            $this->artisan('backup:verify')
                ->expectsOutputToContain('pengguna database yang sama')
                ->assertFailed();
        } finally {
            config(['database.default' => 'sqlite']);
        }
    }
}
