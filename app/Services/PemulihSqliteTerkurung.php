<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Menjalankan dump SQLite di dalam kurungan, bukan di proses aplikasi. (API-27)
 *
 * ALASANNYA, dan ini yang membedakannya dari penyaring teks:
 *
 * Penyaring isi dump sudah ditembus dua kali. Pertama dengan memindahkan
 * `ATTACH` ke baris yang sama sesudah titik koma; kedua dengan menyorongnya
 * lewat batas 256 karakter memakai spasi. Keduanya bentuk yang berbeda dari
 * satu hal yang sama: selama keamanannya diputuskan oleh seberapa pintar
 * pembaca teksnya, akan selalu ada bentuk berikutnya — komentar, DELIMITER,
 * penyandian lain.
 *
 * Kurungan ini tidak membaca dumpnya sama sekali. Restore dijalankan di
 * proses PHP terpisah dengan `open_basedir` dikunci ke satu direktori
 * sementara yang isinya cuma database tujuan. `ATTACH DATABASE
 * '/var/www/care/database/produksi.sqlite'` di dalam dump gagal dengan
 * "not authorized" — bukan karena ada yang mengenalinya, tapi karena
 * prosesnya memang tidak bisa membuka berkas itu.
 *
 * Yang tersisa untuk dilakukan dump di dalam sana: menulis ke database
 * sementaranya sendiri, yang dihapus sesudahnya.
 *
 * Batasnya, ditulis apa adanya: `open_basedir` mengunci akses BERKAS. Kalau
 * suatu hari dumpnya bisa membuka soket atau memanggil program lain, kurungan
 * ini tidak menahannya. SQLite tanpa ekstensi yang dimuat sengaja tidak punya
 * cara melakukan itu, dan pemuatan ekstensi tidak dinyalakan di sini.
 */
class PemulihSqliteTerkurung
{
    /**
     * @param  iterable<string>  $potongan  isi dump SQL
     * @return int jumlah baris tabel complaints di dalam dump
     *
     * @throws RuntimeException kalau dumpnya gagal dipulihkan
     */
    public function pulihkan(iterable $potongan, bool $simpan = false): int
    {
        $kurungan = $this->buatKurungan();
        $db = $kurungan.'/pulihan.sqlite';

        try {
            $proses = new Process(
                [
                    PHP_BINARY,
                    // Inti kurungannya. Semua akses berkas subproses ini
                    // dibatasi ke direktori sementara di atas.
                    '-d', 'open_basedir='.$kurungan,
                    // Peringatan open_basedir tidak boleh mengotori stdout —
                    // stdout hanya berisi jumlah barisnya.
                    '-d', 'display_errors=stderr',
                    '-d', 'error_reporting='.E_ALL,
                    // Ekstensi SQLite tidak dimuat dari dump.
                    '-d', 'sqlite3.extension_dir=',
                    '-r', $this->skrip(),
                ],
                $kurungan,
                ['LW_DB' => $db],
                null,
                (float) config('backup.timeout')
            );

            // Symfony hanya menerima string, Traversable, atau stream —
            // array biasa ditolak, dan potongan dump datang sebagai keduanya.
            $proses->setInput((static function () use ($potongan) {
                yield from $potongan;
            })());
            $proses->run();

            if ($proses->getExitCode() === 3) {
                throw new RuntimeException('Backup dipulihkan, tapi tabel complaints tidak ada di dalamnya.');
            }

            if (! $proses->isSuccessful()) {
                throw new RuntimeException(
                    'Restore gagal (kode '.$proses->getExitCode().'). '.$this->ringkas($proses->getErrorOutput())
                );
            }

            $keluaran = trim($proses->getOutput());

            if (! ctype_digit($keluaran)) {
                throw new RuntimeException('Restore tidak mengembalikan jumlah baris yang bisa dibaca.');
            }

            return (int) $keluaran;
        } finally {
            if ($simpan) {
                // Dibiarkan atas permintaan --keep-temp; jalannya penelusuran.
            } else {
                $this->bersihkan($kurungan);
            }
        }
    }

    /**
     * Skrip subproses. Sengaja pendek: makin sedikit yang berjalan di dalam
     * kurungan, makin sedikit yang perlu dipercaya.
     */
    private function skrip(): string
    {
        return <<<'PHP'
        $db = getenv("LW_DB");
        $pdo = new PDO("sqlite:".$db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec(stream_get_contents(STDIN));
        $ada = (int) $pdo->query("select count(*) from sqlite_master where type='table' and name='complaints'")->fetchColumn();
        if ($ada === 0) { exit(3); }
        echo (int) $pdo->query("select count(*) from complaints")->fetchColumn();
        PHP;
    }

    private function buatKurungan(): string
    {
        $dir = sys_get_temp_dir().'/lw-verify-'.bin2hex(random_bytes(6));

        if (! @mkdir($dir, 0700) && ! is_dir($dir)) {
            throw new RuntimeException('Direktori kurungan tidak bisa dibuat.');
        }

        return (string) realpath($dir);
    }

    private function bersihkan(string $dir): void
    {
        foreach ((array) scandir($dir) as $nama) {
            if (is_string($nama) && $nama !== '.' && $nama !== '..') {
                @unlink($dir.'/'.$nama);
            }
        }

        @rmdir($dir);
    }

    /**
     * Pesan galat dari subproses boleh memuat path berkas di dalam dump.
     * Dipendekkan, dan tidak pernah dipakai untuk memutuskan apa pun.
     */
    private function ringkas(string $stderr): string
    {
        try {
            $bersih = trim((string) preg_replace('/\s+/', ' ', $stderr));
        } catch (Throwable) {
            $bersih = '';
        }

        return $bersih === '' ? '' : mb_substr($bersih, 0, 300);
    }
}
