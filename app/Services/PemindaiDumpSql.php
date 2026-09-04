<?php

namespace App\Services;

use RuntimeException;

/**
 * Menolak dump yang bisa memindahkan pemulihan keluar dari database
 * sementara. (API-27)
 *
 * `backup:verify` memulihkan berkas yang isinya TIDAK dipercaya: apa pun yang
 * diletakkan di direktori backup dengan nama yang cocok pola akan diambil.
 * Klien mysql mematuhi `USE` di dalam dump, dan `PDO::exec()` menjalankan
 * `ATTACH DATABASE` apa adanya — keduanya cukup untuk menulis ke database
 * produksi lewat perintah yang dokumentasinya menyebutnya aman.
 *
 * Versi pertama pemindai ini memotong masukan per BARIS dan menambatkan
 * polanya ke awal baris. Itu bisa dilewati, dan sudah dibuktikan sampai
 * menulis ke berkas database di luar direktori backup:
 *
 *   INSERT INTO t VALUES (1);ATTACH DATABASE '/tmp/korban.sqlite' AS korban;
 *   /-* x *-/ATTACH DATABASE '/tmp/korban.sqlite' AS korban;
 *
 * Pernyataan SQL tidak harus satu per baris. Karena itu pemindai ini memotong
 * per PERNYATAAN, bukan per baris: titik koma di luar string mengakhiri satu
 * pernyataan, komentar dibuang, dan isi string dilewati sama sekali.
 *
 * Melewati isi string mengerjakan dua hal sekaligus: baris data yang memuat
 * ";ATTACH ..." di dalam tanda kutip tidak jadi alarm palsu, dan penyerang
 * tidak bisa bersembunyi di baliknya.
 *
 * Ini lapis PERTAMA. Lapis kedua tidak bergantung pada pemindai ini sama
 * sekali: klien mysql dipanggil dengan `--one-database`. Penyaring teks
 * dipakai untuk menolak lebih awal dengan pesan yang jelas, bukan sebagai
 * satu-satunya pengaman.
 */
class PemindaiDumpSql
{
    /**
     * Pernyataan yang tidak pernah muncul di dump buatan `backup:database` —
     * mysqldump sengaja dipanggil TANPA --databases justru supaya begitu.
     *
     * Ditambatkan ke awal PERNYATAAN, sesudah komentar dan spasi dibuang.
     *
     * @var array<string,string>
     */
    private const TERLARANG = [
        '/^USE\s/i' => 'USE',
        '/^CREATE\s+(DATABASE|SCHEMA)\b/i' => 'CREATE DATABASE',
        '/^DROP\s+(DATABASE|SCHEMA)\b/i' => 'DROP DATABASE',
        '/^ALTER\s+(DATABASE|SCHEMA)\b/i' => 'ALTER DATABASE',
        '/^ATTACH\b/i' => 'ATTACH DATABASE',
        '/^DETACH\b/i' => 'DETACH DATABASE',
    ];

    /**
     * Yang dicocokkan hanya awal pernyataan, jadi tidak ada gunanya menyimpan
     * seluruh isi INSERT yang panjang.
     */
    private const BATAS_AWAL = 256;

    private string $state = 'kode';

    /** Tanda kutip yang sedang menutup string berjalan. */
    private string $penutupString = "'";

    /** Awal pernyataan yang sedang dikumpulkan, tanpa komentar dan isi string. */
    private string $awal = '';

    /**
     * @param  bool  $gayaMysql  MySQL memakai `\` sebagai escape di dalam string
     *                           dan `#` sebagai komentar; SQLite tidak. Ditentukan
     *                           dari driver yang akan MENJALANKAN dumpnya, bukan
     *                           dari tebakan atas isinya.
     */
    public function __construct(private bool $gayaMysql) {}

    /**
     * @param  iterable<string>  $potongan  isi dump, dibaca sedikit-sedikit
     *
     * @throws RuntimeException kalau ada pernyataan terlarang
     */
    public function periksa(iterable $potongan): void
    {
        $sisa = '';

        foreach ($potongan as $bagian) {
            $sisa = $this->proses($sisa.$bagian);
        }

        // Sisa terakhir tidak punya karakter sesudahnya lagi: diproses tanpa
        // menunggu, lalu pernyataan terakhir yang tidak diakhiri titik koma
        // ikut diuji.
        $this->proses($sisa, terakhir: true);
        $this->uji($this->awal);
    }

    /** @return string potongan ekor yang belum bisa diputuskan tanpa karakter berikutnya */
    private function proses(string $teks, bool $terakhir = false): string
    {
        $n = strlen($teks);
        $i = 0;

        while ($i < $n) {
            $c = $teks[$i];
            $tersedia = $n - $i;

            // Beberapa keputusan butuh karakter sesudahnya: `--`, `/*`, `/*!`,
            // `''`, `\'`, `*/`. Kalau karakternya belum tiba, ekornya
            // dikembalikan dan disambung dengan potongan berikutnya.
            $butuh = $this->butuhBerapa($c);

            if (! $terakhir && $butuh > $tersedia) {
                return substr($teks, $i);
            }

            $berikut = $tersedia > 1 ? $teks[$i + 1] : '';

            $i += match ($this->state) {
                'kode' => $this->diKode($c, $berikut, $teks, $i),
                'versi' => $this->diVersi($c),
                'baris' => $this->diKomentarBaris($c),
                'blok' => $this->diKomentarBlok($c, $berikut),
                default => $this->diString($c, $berikut),
            };
        }

        return '';
    }

    private function butuhBerapa(string $c): int
    {
        return match (true) {
            // `/*!` perlu tiga karakter untuk dibedakan dari `/*` biasa.
            $this->state === 'kode' && $c === '/' => 3,
            $this->state === 'kode' && $c === '-' => 2,
            $this->state === 'blok' && $c === '*' => 2,
            // Di dalam string: `''` (kutip ganda) dan `\'` (escape MySQL).
            $this->state === 'string' && ($c === $this->penutupString || $c === '\\') => 2,
            default => 1,
        };
    }

    private function diKode(string $c, string $berikut, string $teks, int $i): int
    {
        if ($c === '-' && $berikut === '-') {
            $this->state = 'baris';

            return 2;
        }

        if ($this->gayaMysql && $c === '#') {
            $this->state = 'baris';

            return 1;
        }

        if ($c === '/' && $berikut === '*') {
            // `/*!40000 ... */` BUKAN komentar bagi MySQL: isinya dijalankan.
            // Kalau diperlakukan sebagai komentar, `/*!USE prod*/` lolos.
            // Penanda versinya ikut dibuang supaya polanya tetap menambat ke
            // kata perintahnya.
            if (($teks[$i + 2] ?? '') === '!') {
                $this->state = 'versi';

                return 3;
            }

            $this->state = 'blok';

            return 2;
        }

        if ($c === "'" || $c === '"' || $c === '`') {
            $this->state = 'string';
            $this->penutupString = $c;

            return 1;
        }

        if ($c === ';') {
            $this->uji($this->awal);
            $this->awal = '';

            return 1;
        }

        // Isi string tidak pernah sampai ke sini — itu yang membuat data
        // pelanggan tidak bisa memicu alarm palsu, dan tidak bisa dipakai
        // bersembunyi.
        if (strlen($this->awal) < self::BATAS_AWAL) {
            $this->awal .= $c;
        }

        return 1;
    }

    private function diVersi(string $c): int
    {
        // Angka versi sesudah `/*!` dilewati; sisanya kode biasa.
        if (! ctype_digit($c)) {
            $this->state = 'kode';

            return 0;
        }

        return 1;
    }

    private function diKomentarBaris(string $c): int
    {
        if ($c === "\n") {
            $this->state = 'kode';
        }

        return 1;
    }

    private function diKomentarBlok(string $c, string $berikut): int
    {
        if ($c === '*' && $berikut === '/') {
            $this->state = 'kode';

            return 2;
        }

        return 1;
    }

    private function diString(string $c, string $berikut): int
    {
        // MySQL: `\'` adalah kutip yang di-escape. SQLite tidak mengenal escape
        // backslash, dan menganggapnya di sana akan membuat string yang
        // berakhir dengan `\` terbaca seolah belum selesai — pernyataan
        // sesudahnya ikut tertelan, dan itu justru jalan lolos.
        if ($this->gayaMysql && $c === '\\') {
            return 2;
        }

        if ($c === $this->penutupString) {
            // Kutip ganda di dalam string berarti satu kutip harfiah.
            if ($berikut === $this->penutupString) {
                return 2;
            }

            $this->state = 'kode';
        }

        return 1;
    }

    private function uji(string $pernyataan): void
    {
        $pernyataan = ltrim($pernyataan);

        if ($pernyataan === '') {
            return;
        }

        foreach (self::TERLARANG as $pola => $nama) {
            if (preg_match($pola, $pernyataan)) {
                throw new RuntimeException(
                    'Dump ini memuat pernyataan "'.$nama.'" — pernyataan itu bisa memindahkan '
                    .'pemulihan ke database lain, termasuk database produksi. Ditolak sebelum dijalankan. '
                    .'Dump dari backup:database tidak pernah memuatnya.'
                );
            }
        }
    }
}
