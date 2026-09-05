<?php

namespace App\Console\Commands;

use App\Services\BerkasBackup;
use App\Services\PemindaiDumpSql;
use App\Services\PemulihSqliteTerkurung;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            // Isinya diperiksa SEBELUM satu byte pun dijalankan.
            $this->tolakYangBisaKeluarDatabaseSementara($path);

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
     * SQLite: restore dijalankan di proses terkurung, bukan di proses ini.
     *
     * Yang menahan `ATTACH DATABASE '/var/www/care/database/produksi.sqlite'`
     * bukan pemindai teksnya, melainkan `open_basedir` di subproses: berkas
     * itu memang tidak bisa dibuka dari dalam sana. Lihat
     * PemulihSqliteTerkurung.
     */
    private function pulihkanSqlite(string $path): int
    {
        return (new PemulihSqliteTerkurung)->pulihkan(
            $this->potongan($path),
            simpan: (bool) $this->option('keep-temp'),
        );
    }

    /**
     * Dipulihkan ke database baru bernama <db>_verify_<acak>, lalu dibuang.
     * Database asli tidak pernah jadi tujuan restore.
     */
    private function pulihkanMysql(string $path, BerkasBackup $berkas): int
    {
        $koneksi = $this->koneksiPemulihan();
        $c = DB::connection($koneksi)->getConfig();
        $asli = (string) DB::connection()->getConfig()['database'];

        $temp = mb_substr($asli, 0, 40).'_verify_'.bin2hex(random_bytes(4));

        if (! preg_match('/^[A-Za-z0-9_]+$/', $temp)) {
            throw new \RuntimeException('Nama database sementara tidak layak dipakai.');
        }

        DB::connection($koneksi)->statement(
            'CREATE DATABASE `'.$temp.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        $defaults = null;

        try {
            $defaults = $berkas->defaultsMysql($c);

            $proses = new Process([
                (string) config('backup.mysql'),
                '--defaults-extra-file='.$defaults,
                '--default-character-set='.($c['charset'] ?? 'utf8mb4'),
                // Klien mysql MEMATUHI `USE` di dalam dump. Tanpa ini, dump
                // yang dibuat `mysqldump --databases` — cara paling umum orang
                // membuat dump manual — memindahkan restore ke database
                // produksi dan menimpanya. Lapis kedua di atas pemeriksaan isi
                // di tolakYangBisaKeluarDatabaseSementara().
                '--one-database',
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

            $ada = DB::connection($koneksi)->selectOne(
                'select count(*) as n from information_schema.tables where table_schema = ? and table_name = ?',
                [$temp, 'complaints']
            );

            if ((int) ($ada->n ?? 0) === 0) {
                throw new \RuntimeException('Backup dipulihkan, tapi tabel complaints tidak ada di dalamnya.');
            }

            $hitung = DB::connection($koneksi)->selectOne('select count(*) as n from `'.$temp.'`.`complaints`');

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
                DB::connection($koneksi)->statement('DROP DATABASE IF EXISTS `'.$temp.'`');
            }
        }
    }

    /**
     * Koneksi yang dipakai MEMULIHKAN — wajib berbeda dari koneksi aplikasi.
     *
     * Ini pengaman utama jalur MySQL, dan sengaja tidak bertumpu pada
     * pembacaan isi dump sama sekali. Alasannya: `--one-database` hanya
     * mengikuti pernyataan `USE`. Dump yang menulis dengan nama database
     * lengkap —
     *
     *     INSERT INTO lessworry_care.complaints VALUES (...);
     *
     * — tidak disentuh olehnya. Yang benar-benar menahan tulisan seperti itu
     * cuma satu hal: pengguna database yang menjalankan restore tidak punya
     * hak tulis di database produksi.
     *
     * Karena itu perintah ini MENOLAK BERJALAN kalau koneksi terpisahnya belum
     * disiapkan, alih-alih diam-diam memakai pengguna aplikasi. Gagal ke arah
     * aman: verify yang menolak jalan bisa diperbaiki dalam lima menit;
     * complaint produksi yang tertimpa tidak bisa dikembalikan.
     */
    private function koneksiPemulihan(): string
    {
        $nama = (string) config('backup.verify_connection');

        if ($nama === '') {
            throw new \RuntimeException(
                'backup:verify di MySQL butuh koneksi database terpisah yang TIDAK punya hak tulis '
                .'di database produksi. Set BACKUP_VERIFY_CONNECTION (mis. mysql_verify) beserta '
                .'DB_VERIFY_USERNAME dan DB_VERIFY_PASSWORD. Langkah lengkapnya di '
                .'docs/deploy-care-lessworry.md.'
            );
        }

        if (! is_array(config('database.connections.'.$nama))) {
            throw new \RuntimeException('Koneksi database "'.$nama.'" tidak ada di config/database.php.');
        }

        $pemulih = DB::connection($nama)->getConfig();
        $aplikasi = DB::connection()->getConfig();

        // Pengguna yang sama berarti hak akses yang sama, dan itu berarti tidak
        // ada pemisahan sama sekali — hanya nama koneksi yang berbeda.
        if (($pemulih['username'] ?? null) === ($aplikasi['username'] ?? null)) {
            throw new \RuntimeException(
                'Koneksi "'.$nama.'" memakai pengguna database yang sama dengan aplikasi. '
                .'Pemisahannya harus pada penggunanya, bukan pada nama koneksinya: buat pengguna '
                .'tersendiri yang hanya punya hak di database <db>_verify_%.'
            );
        }

        return $nama;
    }

    /**
     * Tolak dump yang bisa memindahkan pemulihan keluar dari database
     * sementara — sebelum satu byte pun dijalankan.
     *
     * Pemindaiannya per PERNYATAAN, bukan per baris: pernyataan SQL tidak
     * harus satu per baris, dan versi pertama pemeriksaan ini bisa dilewati
     * hanya dengan menaruh `ATTACH` sesudah titik koma di baris yang sama.
     * Lihat PemindaiDumpSql.
     *
     * Ini lapis pertama, dipakai supaya penolakannya datang lebih awal dengan
     * pesan yang jelas. Lapis kedua ada di pulihkanMysql(): `--one-database`,
     * yang tidak bergantung pada pemindai ini sama sekali.
     */
    private function tolakYangBisaKeluarDatabaseSementara(string $path): void
    {
        // Gaya escape ditentukan dari driver yang akan MENJALANKAN dumpnya,
        // bukan dari tebakan atas isi berkasnya.
        $pemindai = new PemindaiDumpSql(DB::connection()->getDriverName() !== 'sqlite');

        $pemindai->periksa($this->potongan($path));
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
