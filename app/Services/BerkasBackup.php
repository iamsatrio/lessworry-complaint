<?php

namespace App\Services;

use RuntimeException;

/**
 * Aturan main direktori backup — dipakai bersama oleh backup:database dan
 * backup:verify. (API-27)
 *
 * Alasan dipisah: rotasi menghapus berkas, dan penghapusan tidak boleh
 * bergantung pada pola nama yang ditulis ulang di dua tempat. Satu tempat,
 * satu pola.
 */
class BerkasBackup
{
    /*
    | Hanya berkas yang cocok pola ini yang dianggap milik sistem backup, dan
    | hanya berkas ini yang boleh dihapus rotasi. Berkas lain yang kebetulan
    | ada di direktori yang sama — dump manual, catatan, apa pun — dibiarkan.
    */
    public const POLA = '/^db-\d{4}-\d{2}-\d{2}-\d{6}\.sql\.gz$/';

    public function direktori(): string
    {
        $dir = (string) config('backup.path');

        if ($dir === '') {
            throw new RuntimeException('backup.path kosong.');
        }

        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new RuntimeException('Direktori backup tidak bisa dibuat: '.$dir);
        }

        $nyata = realpath($dir);

        if ($nyata === false) {
            throw new RuntimeException('Direktori backup tidak bisa dibaca: '.$dir);
        }

        return $nyata;
    }

    /**
     * Daftar dump, terbaru lebih dulu.
     *
     * Diurutkan berdasar NAMA, bukan mtime: nama memuat cap waktu pembuatan,
     * sedangkan mtime bisa berubah karena disalin, dipulihkan, atau disentuh
     * rsync — dan urutan yang salah berarti rotasi menghapus yang salah.
     *
     * @return array<int,string> path absolut
     */
    public function daftar(): array
    {
        $dir = $this->direktori();
        $berkas = [];

        foreach ((array) scandir($dir) as $nama) {
            if (is_string($nama) && preg_match(self::POLA, $nama) && is_file($dir.'/'.$nama)) {
                $berkas[] = $nama;
            }
        }

        rsort($berkas, SORT_STRING);

        return array_map(fn ($nama) => $dir.'/'.$nama, $berkas);
    }

    public function terbaru(): ?string
    {
        return $this->daftar()[0] ?? null;
    }

    /**
     * Pastikan path benar-benar berada di dalam direktori backup.
     *
     * Ini penjaga terakhir sebelum unlink(). Tanpa ini, satu kesalahan pola
     * atau satu symlink cukup untuk menghapus berkas di luar direktori ini.
     */
    public function didalam(string $path): bool
    {
        $nyata = realpath($path);

        if ($nyata === false) {
            return false;
        }

        return str_starts_with($nyata, $this->direktori().DIRECTORY_SEPARATOR)
            && preg_match(self::POLA, basename($nyata)) === 1;
    }

    /**
     * Berkas defaults MySQL sementara berisi kredensial.
     *
     * Password TIDAK boleh masuk argumen proses: `ps` bisa dibaca pengguna
     * lain di server yang sama, dan `mysqldump -pRAHASIA` menampilkannya di
     * sana. Berkas ini dibuat berizin 600 lebih dulu, baru diisi, dan
     * dihapus oleh pemanggilnya setelah proses selesai.
     */
    public function defaultsMysql(array $c): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lwbk');

        if ($path === false) {
            throw new RuntimeException('Berkas kredensial sementara tidak bisa dibuat.');
        }

        chmod($path, 0600);

        $baris = ['[client]'];

        foreach ([
            'host' => $c['host'] ?? null,
            'port' => $c['port'] ?? null,
            'user' => $c['username'] ?? null,
            'password' => $c['password'] ?? null,
            'socket' => $c['unix_socket'] ?? null,
        ] as $kunci => $nilai) {
            if (filled($nilai)) {
                $baris[] = $kunci.'="'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $nilai).'"';
            }
        }

        file_put_contents($path, implode("\n", $baris)."\n");

        return $path;
    }
}
