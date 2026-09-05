<?php

namespace App\Console\Commands;

use App\Services\BerkasBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Dump database terkompresi, dengan simpanan bergilir. (API-27)
 *
 * Yang membuat perintah ini ada: kalau database hilang, semua complaint
 * hilang. Cron mysqldump satu baris di panduan deploy sudah bisa gagal diam-
 * diam — pipa ke gzip menyembunyikan status keluar mysqldump, jadi berkas
 * 20 byte berisi pesan galat pun terlihat seperti backup yang berhasil.
 *
 * Karena itu di sini:
 *   - status keluar mysqldump diperiksa, bukan status gzip;
 *   - dump ditulis ke berkas sementara lalu di-rename; berkas bernama benar
 *     berarti dumpnya selesai, tidak pernah setengah jadi;
 *   - rotasi hanya berjalan SETELAH dump baru berhasil;
 *   - kegagalan dicatat sebagai error, tidak diam.
 *
 * Kredensial tidak pernah masuk argumen proses — `ps` bisa dibaca pengguna
 * lain di server yang sama. Password dititipkan lewat berkas defaults
 * sementara berizin 600 yang dihapus setelah selesai.
 */
class BackupDatabase extends Command
{
    protected $signature = 'backup:database
        {--keep= : Berapa dump terakhir yang disimpan (bawaan: config backup.keep)}';

    protected $description = 'Dump database ke berkas terkompresi dan buang dump yang sudah terlalu tua';

    /** Umur berkas .tmp-* yang sudah pasti bukan milik proses yang berjalan. */
    private const UMUR_SISA_JAM = 6;

    public function handle(BerkasBackup $berkas): int
    {
        try {
            $dir = $berkas->direktori();
        } catch (Throwable $e) {
            return $this->gagal('Direktori backup tidak siap: '.$e->getMessage());
        }

        // Dua jadwal yang tumpang tindih tidak boleh menulis berkas yang sama.
        // Yang kalah lomba keluar tanpa berbuat apa-apa — bukan galat: dump
        // yang sedang berjalan tetap menghasilkan berkas hari itu.
        $kunci = fopen($dir.'/.lock', 'c');

        if ($kunci === false) {
            return $this->gagal('Kunci backup tidak bisa dibuat di '.$dir);
        }

        if (! flock($kunci, LOCK_EX | LOCK_NB)) {
            fclose($kunci);
            $this->warn('Backup lain sedang berjalan. Dilewati.');

            return self::SUCCESS;
        }

        try {
            return $this->jalankan($berkas, $dir);
        } catch (Throwable $e) {
            return $this->gagal($e->getMessage());
        } finally {
            flock($kunci, LOCK_UN);
            fclose($kunci);
        }
    }

    private function jalankan(BerkasBackup $berkas, string $dir): int
    {
        $this->sapuSisaDump($dir);

        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new \RuntimeException('Driver "'.$driver.'" belum didukung backup:database.');
        }

        $tujuan = $this->namaBerkas($dir);

        // Ditulis dengan nama sementara. Pemantau dan perintah verify hanya
        // melihat nama yang cocok pola, jadi berkas setengah jadi tidak
        // pernah terbaca sebagai backup.
        $sementara = $dir.'/.tmp-'.getmypid().'-'.uniqid().'.gz';

        try {
            $driver === 'sqlite'
                ? $this->dumpSqlite($sementara)
                : $this->dumpMysql($sementara);

            if (! is_file($sementara) || filesize($sementara) === 0) {
                throw new \RuntimeException('Dump menghasilkan berkas kosong.');
            }

            if (! rename($sementara, $tujuan)) {
                throw new \RuntimeException('Dump tidak bisa dipindahkan ke '.basename($tujuan));
            }
        } catch (Throwable $e) {
            @unlink($sementara);

            return $this->gagal($e->getMessage());
        }

        @chmod($tujuan, 0640);

        $this->info('Backup dibuat: '.basename($tujuan).' ('.$this->ukuran($tujuan).')');

        $this->rotasi($berkas);

        return self::SUCCESS;
    }

    /**
     * mysqldump -> gzip, tanpa shell.
     *
     * Keluaran dialirkan sedikit-sedikit ke gzip supaya dump besar tidak
     * pernah utuh di memori, dan stderr dikumpulkan terpisah supaya pesan
     * galat tidak ikut masuk ke berkas dump.
     */
    private function dumpMysql(string $tujuan): void
    {
        $c = DB::connection()->getConfig();

        // Berkas tujuan dibuka lebih dulu: kalau direktorinya tidak bisa
        // ditulis, tidak ada gunanya membuat berkas kredensial sementara.
        $gz = $this->buka($tujuan);
        $defaults = app(BerkasBackup::class)->defaultsMysql($c);

        $perintah = array_values(array_filter([
            (string) config('backup.mysqldump'),
            '--defaults-extra-file='.$defaults,
            '--single-transaction',   // konsisten tanpa mengunci tabel InnoDB
            '--quick',
            '--no-tablespaces',       // tanpa ini butuh privilege PROCESS
            '--skip-lock-tables',
            '--default-character-set='.($c['charset'] ?? 'utf8mb4'),
            // Sengaja TANPA --databases: dumpnya tidak memuat CREATE DATABASE
            // / USE, jadi bisa dipulihkan ke database mana pun — itu yang
            // membuat backup:verify bisa memulihkan ke database sementara.
            (string) $c['database'],
        ], fn ($bagian) => $bagian !== ''));

        $stderr = '';

        try {
            $proses = new Process($perintah, base_path(), null, null, (float) config('backup.timeout'));
            $proses->run(function (string $jenis, string $potongan) use ($gz, &$stderr) {
                if ($jenis === Process::OUT) {
                    $this->tulis($gz, $potongan);
                } else {
                    $stderr .= $potongan;
                }
            });
        } finally {
            gzclose($gz);
            @unlink($defaults);
        }

        // Status keluar mysqldump, bukan gzip. Inilah yang hilang pada
        // `mysqldump | gzip > file` di cron.
        if (! $proses->isSuccessful()) {
            throw new \RuntimeException(
                'mysqldump keluar dengan kode '.$proses->getExitCode().'. '.$this->ringkas($stderr)
            );
        }
    }

    /**
     * SQLite: dump SQL disusun di PHP, bukan lewat binari sqlite3.
     *
     * Dua alasan. Pertama, sqlite3 belum tentu terpasang di mesin yang
     * menjalankan aplikasi. Kedua, `VACUUM INTO` — cara bawaan SQLite membuat
     * salinan — ditolak kalau dipanggil di dalam transaksi, dan itu membuat
     * backup gagal justru pada pemanggil yang sedang membuka transaksi.
     *
     * Keluarannya SQL biasa, sama seperti keluaran mysqldump, sehingga
     * backup:verify memulihkannya dengan cara yang sama: dijalankan.
     */
    private function dumpSqlite(string $tujuan): void
    {
        $pdo = DB::connection()->getPdo();
        $gz = $this->buka($tujuan);

        try {
            $this->tulis($gz, "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n");

            $objek = $pdo->query(
                "select type, name, sql from sqlite_master
                 where sql is not null and name not like 'sqlite_%'
                 order by case type when 'table' then 1 when 'view' then 2 else 3 end, name"
            )->fetchAll(PDO::FETCH_ASSOC);

            // Tabel dan isinya lebih dulu; indeks dan trigger belakangan, biar
            // pemulihan tidak memelihara indeks sambil memuat jutaan baris.
            foreach ($objek as $baris) {
                if ($baris['type'] !== 'table') {
                    continue;
                }

                $this->tulis($gz, $baris['sql'].";\n");
                $this->tulisIsiTabel($gz, $pdo, (string) $baris['name']);
            }

            foreach ($objek as $baris) {
                if ($baris['type'] !== 'table') {
                    $this->tulis($gz, $baris['sql'].";\n");
                }
            }

            $this->tulis($gz, "COMMIT;\n");
        } finally {
            gzclose($gz);
        }
    }

    /** @param  resource  $gz */
    private function tulisIsiTabel($gz, PDO $pdo, string $tabel): void
    {
        $kutip = fn (string $pengenal) => '"'.str_replace('"', '""', $pengenal).'"';

        $baris = $pdo->query('select * from '.$kutip($tabel));

        while (($isi = $baris->fetch(PDO::FETCH_ASSOC)) !== false) {
            $kolom = implode(', ', array_map($kutip, array_keys($isi)));
            $nilai = implode(', ', array_map(fn ($n) => $this->literal($pdo, $n), array_values($isi)));

            $this->tulis($gz, 'INSERT INTO '.$kutip($tabel).' ('.$kolom.') VALUES ('.$nilai.");\n");
        }
    }

    /** Nilai apa pun jadi literal SQL yang aman, termasuk isi biner. */
    private function literal(PDO $pdo, mixed $nilai): string
    {
        if ($nilai === null) {
            return 'NULL';
        }

        if (is_int($nilai) || is_float($nilai)) {
            return (string) $nilai;
        }

        if (is_bool($nilai)) {
            return $nilai ? '1' : '0';
        }

        $teks = (string) $nilai;

        // Lampiran dan kolom biner tidak selamat lewat kutip biasa.
        return mb_check_encoding($teks, 'UTF-8')
            ? $pdo->quote($teks)
            : "X'".bin2hex($teks)."'";
    }

    /** @param  resource  $gz */
    private function tulis($gz, string $potongan): void
    {
        if (gzwrite($gz, $potongan) === false) {
            throw new \RuntimeException('Gagal menulis berkas backup.');
        }
    }

    /**
     * Buang berkas sementara yang ditinggalkan proses yang mati di tengah dump.
     *
     * Berkas `.tmp-*` tidak cocok pola rotasi — memang disengaja, supaya dump
     * setengah jadi tidak pernah terbaca sebagai backup — tapi akibatnya
     * rotasi juga tidak pernah membuangnya. Server yang di-reboot saat dump
     * berjalan meninggalkan satu berkas berukuran penuh, selamanya.
     *
     * Batas umurnya jauh lebih panjang daripada dump terlama yang masuk akal,
     * supaya berkas milik proses yang MASIH berjalan tidak ikut terbawa.
     * Dijalankan di bawah kunci yang sama dengan dumpnya.
     */
    private function sapuSisaDump(string $dir): void
    {
        $kedaluwarsa = now()->subHours(self::UMUR_SISA_JAM)->getTimestamp();

        foreach ((array) scandir($dir) as $nama) {
            if (! is_string($nama) || ! str_starts_with($nama, '.tmp-')) {
                continue;
            }

            $path = $dir.'/'.$nama;

            if (! is_file($path) || (int) filemtime($path) > $kedaluwarsa) {
                continue;
            }

            if (@unlink($path)) {
                $this->line('  sapu     '.$nama.' (sisa dump yang tidak selesai)');
            }
        }

        // Berkas .lock sengaja TIDAK ikut disapu: ukurannya nol, flock-nya
        // sudah dilepas kernel saat prosesnya mati, dan menghapusnya justru
        // membuat dua proses bisa memegang "kunci" pada inode yang berbeda.
    }

    /** Buang dump paling tua, hanya di dalam direktori backup. */
    private function rotasi(BerkasBackup $berkas): void
    {
        $simpan = (int) ($this->option('keep') ?? config('backup.keep'));

        if ($simpan < 1) {
            $this->warn('backup.keep < 1 — rotasi dilewati supaya tidak ada backup yang terhapus semua.');

            return;
        }

        $lama = array_slice($berkas->daftar(), $simpan);

        foreach ($lama as $path) {
            // Penjaga terakhir: pola nama sudah dicek saat pendaftaran, ini
            // memastikan pathnya juga benar-benar di dalam direktori backup.
            if (! $berkas->didalam($path)) {
                continue;
            }

            if (@unlink($path)) {
                $this->line('  buang    '.basename($path));
            } else {
                $this->warn('  gagal menghapus '.basename($path));
            }
        }

        $this->info('Tersimpan: '.count($berkas->daftar()).' backup (batas '.$simpan.').');
    }

    private function namaBerkas(string $dir): string
    {
        $waktu = now();

        // Di bawah kunci ini praktis tidak terjadi, tapi satu detik yang sama
        // tidak boleh berarti satu berkas ditimpa.
        do {
            $path = $dir.'/db-'.$waktu->format('Y-m-d-His').'.sql.gz';
            $waktu = $waktu->addSecond();
        } while (file_exists($path));

        return $path;
    }

    /** @return resource */
    private function buka(string $path)
    {
        $gz = gzopen($path, 'wb6');

        if ($gz === false) {
            throw new \RuntimeException('Berkas backup tidak bisa dibuat: '.basename($path));
        }

        return $gz;
    }

    private function ukuran(string $path): string
    {
        $b = (int) filesize($path);

        return $b >= 1048576
            ? round($b / 1048576, 1).' MB'
            : round($b / 1024, 1).' KB';
    }

    /**
     * Pesan galat mysqldump boleh memuat nama pengguna dan host database.
     * Dipendekkan dan tidak pernah membawa password — password hanya ada di
     * berkas defaults, tidak pernah di argumen atau di stderr.
     */
    private function ringkas(string $stderr): string
    {
        $bersih = trim(preg_replace('/\s+/', ' ', $stderr) ?? '');

        return $bersih === '' ? '' : mb_substr($bersih, 0, 300);
    }

    private function gagal(string $pesan): int
    {
        // Backup yang gagal diam-diam sama saja dengan tidak punya backup.
        Log::error('Backup database gagal: '.$pesan);
        $this->error('Backup gagal: '.$pesan);

        return self::FAILURE;
    }
}
