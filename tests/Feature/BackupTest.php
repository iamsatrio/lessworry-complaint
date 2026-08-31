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
}
