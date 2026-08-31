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
 * Pulihkan dump terakhir ke database sementara, lalu hitung isinya. (API-27)
 *
 * Inilah yang membedakan backup dari berkas yang diasumsikan benar. Berkas
 * .gz berukuran wajar bisa saja berisi dump yang terpotong di tengah tabel;
 * satu-satunya cara tahu adalah memulihkannya.
 *
 * Database sementara selalu dibuang di akhir, berhasil atau gagal. Database
 * asli tidak pernah disentuh — perintah ini hanya membaca darinya untuk
 * pembanding jumlah baris.
 */
class VerifyBackup extends Command
{
    protected $signature = 'backup:verify
        {file? : Berkas backup yang diuji (bawaan: yang terbaru)}
        {--keep-temp : Jangan buang database sementaranya (untuk menelusuri masalah)}';

    protected $description = 'Pulihkan backup terakhir ke database sementara dan hitung baris complaints';

    public function handle(BerkasBackup $berkas): int
    {
        try {
            $path = $this->argument('file')
                ? $this->pilih($berkas, (string) $this->argument('file'))
                : $berkas->terbaru();
        } catch (Throwable $e) {
            return $this->gagal($e->getMessage());
        }

        if ($path === null) {
            return $this->gagal('Tidak ada berkas backup di '.config('backup.path').'. Jalankan `php artisan backup:database` dulu.');
        }

        $this->line('Menguji: '.basename($path).' ('.round((int) filesize($path) / 1024, 1).' KB)');

        try {
            // Dipilih dari driver yang sedang dipakai, bukan dari nama
            // berkas: dump keduanya sama-sama SQL biasa.
            $baris = DB::connection()->getDriverName() === 'sqlite'
                ? $this->pulihkanSqlite($path)
                : $this->pulihkanMysql($path, $berkas);
        } catch (Throwable $e) {
            return $this->gagal($e->getMessage());
        }

        $hidup = (int) DB::table('complaints')->count();

        $this->newLine();
        $this->info('Baris complaints di backup : '.$baris);
        $this->info('Baris complaints sekarang  : '.$hidup);

        if ($baris === $hidup) {
            $this->info('Cocok. Backup bisa dipulihkan.');
        } else {
            // Bukan kegagalan: dump yang diambil dini hari memang tertinggal
            // dari database yang terus dipakai. Yang gawat adalah selisih yang
            // tidak masuk akal — itu dibaca manusia, bukan diputuskan di sini.
            $this->warn('Berbeda '.abs($hidup - $baris).' baris. Wajar kalau backupnya lebih tua dari perubahan hari ini.');
        }

        return self::SUCCESS;
    }

    private function pilih(BerkasBackup $berkas, string $file): string
    {
        $path = str_contains($file, DIRECTORY_SEPARATOR)
            ? $file
            : rtrim($berkas->direktori(), '/').'/'.$file;

        if (! $berkas->didalam($path)) {
            throw new \RuntimeException('Berkas itu bukan backup di direktori backup: '.$file);
        }

        return (string) realpath($path);
    }

    /**
     * SQLite: dump dijalankan ke berkas database baru yang kosong, bukan ke
     * berkas yang sedang dipakai aplikasi.
     *
     * Dumpnya dibaca utuh — jalur SQLite dipakai untuk pengembangan lokal,
     * bukan untuk basis data produksi yang besar. Jalur MySQL tetap dialirkan
     * sedikit-sedikit.
     */
    private function pulihkanSqlite(string $path): int
    {
        $temp = tempnam(sys_get_temp_dir(), 'lwvf');

        if ($temp === false) {
            throw new \RuntimeException('Berkas sementara tidak bisa dibuat.');
        }

        try {
            $sql = '';

            foreach ($this->potongan($path) as $potongan) {
                $sql .= $potongan;
            }

            $pdo = new PDO('sqlite:'.$temp, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec($sql);

            $ada = $pdo->query("select count(*) from sqlite_master where type='table' and name='complaints'")
                ->fetchColumn();

            if ((int) $ada === 0) {
                throw new \RuntimeException('Backup dipulihkan, tapi tabel complaints tidak ada di dalamnya.');
            }

            return (int) $pdo->query('select count(*) from complaints')->fetchColumn();
        } finally {
            if ($this->option('keep-temp')) {
                $this->comment('Database sementara dibiarkan: '.$temp);
            } else {
                @unlink($temp);
            }
        }
    }

    /**
     * Dipulihkan ke database baru bernama <db>_verify_<acak>, lalu dibuang.
     * Database asli tidak pernah jadi tujuan restore.
     */
    private function pulihkanMysql(string $path, BerkasBackup $berkas): int
    {
        $c = DB::connection()->getConfig();
        $asli = (string) $c['database'];

        $temp = mb_substr($asli, 0, 40).'_verify_'.bin2hex(random_bytes(4));

        if (! preg_match('/^[A-Za-z0-9_]+$/', $temp)) {
            throw new \RuntimeException('Nama database sementara tidak layak dipakai.');
        }

        DB::statement('CREATE DATABASE `'.$temp.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $defaults = null;

        try {
            $defaults = $berkas->defaultsMysql($c);

            $proses = new Process([
                (string) config('backup.mysql'),
                '--defaults-extra-file='.$defaults,
                '--default-character-set='.($c['charset'] ?? 'utf8mb4'),
                $temp,
            ], base_path(), null, null, (float) config('backup.timeout'));

            $proses->setInput($this->potongan($path));
            $proses->run();

            if (! $proses->isSuccessful()) {
                throw new \RuntimeException(
                    'Restore gagal (kode '.$proses->getExitCode().'). '
                    .mb_substr(trim(preg_replace('/\s+/', ' ', $proses->getErrorOutput()) ?? ''), 0, 300)
                );
            }

            $ada = DB::selectOne(
                'select count(*) as n from information_schema.tables where table_schema = ? and table_name = ?',
                [$temp, 'complaints']
            );

            if ((int) ($ada->n ?? 0) === 0) {
                throw new \RuntimeException('Backup dipulihkan, tapi tabel complaints tidak ada di dalamnya.');
            }

            $hitung = DB::selectOne('select count(*) as n from `'.$temp.'`.`complaints`');

            return (int) ($hitung->n ?? 0);
        } finally {
            if ($defaults !== null) {
                @unlink($defaults);
            }

            if ($this->option('keep-temp')) {
                $this->comment('Database sementara dibiarkan: '.$temp);
            } else {
                // Hanya database yang dibuat perintah ini beberapa baris di
                // atas. Namanya tidak pernah datang dari luar.
                DB::statement('DROP DATABASE IF EXISTS `'.$temp.'`');
            }
        }
    }

    /**
     * Isi berkas .gz, dibaca sedikit-sedikit. Dump besar tidak pernah utuh
     * di memori.
     *
     * @return \Generator<int,string>
     */
    private function potongan(string $path): \Generator
    {
        $gz = gzopen($path, 'rb');

        if ($gz === false) {
            throw new \RuntimeException('Berkas backup tidak bisa dibuka: '.basename($path));
        }

        try {
            while (! gzeof($gz)) {
                $potongan = gzread($gz, 1 << 20);

                if ($potongan === false) {
                    throw new \RuntimeException('Berkas backup rusak atau tidak selesai ditulis.');
                }

                if ($potongan !== '') {
                    yield $potongan;
                }
            }
        } finally {
            gzclose($gz);
        }
    }

    private function gagal(string $pesan): int
    {
        Log::error('Verifikasi backup gagal: '.$pesan);
        $this->error('Verifikasi gagal: '.$pesan);

        return self::FAILURE;
    }
}
