<?php

namespace Tests\Feature;

use App\Services\PengirimVerifikasiEmail;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * API-127 — satu pintu penyensoran log, dijaga mesin. (lanjutan tinjauan PR #60)
 *
 * `PengirimVerifikasiEmail` punya tiga fungsi penyensor: `tanpaKredensial()`
 * membuang kredensial, `tanpaAlamatEmail()` membuang alamat email, dan
 * `amanUntukLog()` menjalankan keduanya dengan urutan yang benar. Aturannya:
 * apa pun yang masuk log lewat `amanUntukLog()`, bukan salah satu lintasannya
 * sendirian. Separuh penyensoran bukan penyensoran — lintasan kredensial
 * sendirian meloloskan alamat email anggota tim ke `storage/logs` (itu persis
 * bug API-121), lintasan alamat sendirian meloloskan kredensialnya.
 *
 * Sampai issue ini aturan itu hanya dijaga docblock di
 * `PengirimVerifikasiEmail::tanpaKredensial()`. Docblock tidak gagal di CI.
 *
 * CAKUPANNYA KODE PRODUKSI, BUKAN `tests/`, dan itu sengaja:
 * `test_penyensoran_membuang_kredensial_dan_menyisakan_host` memanggil kedua
 * lintasan langsung supaya ketahuan kalau lintasan kredensial meluber ke
 * wilayah alamat — pengujian lapisan satu per satu justru yang menjaga
 * urutannya benar. Yang dilarang pemanggil produksi.
 *
 * YANG DIPERIKSA LEBIH KETAT DARIPADA "menulis ke log": setiap pemanggilan
 * lintasan tunggal dari kode produksi dihitung pelanggaran, mau hasilnya masuk
 * log atau tidak. Menelusuri aliran data dari hasil penyensoran sampai
 * `Log::error()` menuntut analisis yang tidak bisa dilakukan test ini dengan
 * jujur — dan tidak ada kegunaan lain untuk lintasan tunggal di produksi.
 * Kalau suatu hari ada, lonjakannya kelihatan di sini dan keputusannya
 * ditulis, bukan diam-diam lolos.
 *
 * Padanannya di repositori ini: `tests/Feature/NeviraChokePointTest.php`
 * menjaga "semua akses NEVIRA dari jalur HTTP lewat satu gerbang" dengan cara
 * yang sama.
 */
class PenyensoranSatuPintuTest extends TestCase
{
    /** Lintasan yang tidak boleh dipanggil sendirian dari kode produksi. */
    private const LINTASAN = ['tanpaKredensial', 'tanpaAlamatEmail'];

    /** Satu-satunya fungsi yang boleh memanggil keduanya. */
    private const GERBANG = 'amanUntukLog';

    /** Berkas tempat gerbangnya didefinisikan — di luar itu tidak ada kecualian. */
    private const BERKAS_GERBANG = 'app/Services/PengirimVerifikasiEmail.php';

    /* ---------- Aturan yang dijaga ---------- */

    public function test_tidak_ada_kode_produksi_yang_memakai_lintasan_tunggal(): void
    {
        $pelanggaran = $this->pelanggaranDiKodeProduksi();

        $this->assertSame([], $pelanggaran,
            'Kode produksi ini memanggil lintasan penyensoran tunggal, bukan '
            .PengirimVerifikasiEmail::class.'::'.self::GERBANG.'() — '
            .'separuh penyensoran bukan penyensoran: '.implode('; ', $pelanggaran));
    }

    /* ---------- Pendeteksinya sendiri harus terbukti bisa merah ---------- */

    /**
     * Pengaman yang tidak bisa merah adalah docblock dengan biaya CI.
     *
     * Test di atas hijau juga kalau pendeteksinya rusak dan selalu mengembalikan
     * daftar kosong — token yang salah dibaca, direktori yang salah ditelusuri,
     * pengecualian yang ketelan. Empat test di bawah menembak pendeteksinya
     * dengan sumber buatan: yang melanggar HARUS tertangkap, yang sah HARUS
     * lewat.
     */
    public function test_pendeteksi_menangkap_pemanggil_lintasan_tunggal(): void
    {
        $pelanggaran = $this->pelanggaranDalamPhp(<<<'PHP'
        <?php
        class PemanggilNakal
        {
            public function catat(string $pesan): void
            {
                Log::error('gagal', ['error' => PengirimVerifikasiEmail::tanpaKredensial($pesan)]);
            }
        }
        PHP, 'buatan.php', self::GERBANG);

        $this->assertCount(1, $pelanggaran,
            'pendeteksi melewatkan pemanggil lintasan tunggal di luar gerbang');
        $this->assertStringContainsString('catat', $pelanggaran[0]);
        $this->assertStringContainsString('tanpaKredensial', $pelanggaran[0]);
    }

    public function test_pendeteksi_menangkap_lintasan_alamat_juga(): void
    {
        // Arah sebaliknya, dan ia sama buruknya: alamat tersensor, kredensial
        // yang lolos. Sebuah pendeteksi yang hanya hafal satu nama lintasan
        // meloloskan separuh aturannya.
        $pelanggaran = $this->pelanggaranDalamPhp(<<<'PHP'
        <?php
        class PemanggilNakal
        {
            public function catat(string $pesan): void
            {
                Log::error(PengirimVerifikasiEmail::tanpaAlamatEmail($pesan));
            }
        }
        PHP, 'buatan.php', self::GERBANG);

        $this->assertCount(1, $pelanggaran);
        $this->assertStringContainsString('tanpaAlamatEmail', $pelanggaran[0]);
    }

    public function test_pendeteksi_membiarkan_gerbang_dan_deklarasi_lintasannya(): void
    {
        // Bentuk yang sah, dan ada tiga hal berbeda di dalamnya yang tidak
        // boleh dihitung pelanggaran: nama lintasan di DOCBLOCK, nama lintasan
        // di DEKLARASI fungsinya sendiri, dan pemanggilan keduanya DI DALAM
        // gerbang. Pendeteksi yang menghitung salah satu dari ketiganya
        // membuat berkas yang sudah benar jadi merah, dan pengaman yang merah
        // pada kode yang benar akan dimatikan orang, bukan diperbaiki.
        $pelanggaran = $this->pelanggaranDalamPhp(<<<'PHP'
        <?php
        class Gerbang
        {
            /** Jangan memanggil `tanpaKredensial()` langsung untuk sesuatu yang masuk log. */
            public static function amanUntukLog(string $pesan): string
            {
                return self::tanpaAlamatEmail(self::tanpaKredensial($pesan));
            }

            public static function tanpaAlamatEmail(string $pesan): string
            {
                return $pesan;
            }

            public static function tanpaKredensial(string $pesan): string
            {
                return $pesan;
            }
        }
        PHP, 'buatan.php', self::GERBANG);

        $this->assertSame([], $pelanggaran,
            'pendeteksi merah pada bentuk yang sah: '.implode('; ', $pelanggaran));
    }

    public function test_pendeteksi_menangkap_rujukan_lewat_nama_dalam_teks(): void
    {
        // `[$kelas, 'tanpaKredensial']` dan `call_user_func('...::tanpaKredensial')`
        // memanggil lintasannya tanpa satu pun token pemanggilan. Tanpa
        // pemeriksaan ini, aturannya bisa dilewati dengan menaruh namanya di
        // dalam petik.
        $pelanggaran = $this->pelanggaranDalamPhp(<<<'PHP'
        <?php
        class PemanggilNakal
        {
            public function catat(string $pesan): void
            {
                $sensor = [PengirimVerifikasiEmail::class, 'tanpaKredensial'];
                Log::error($sensor($pesan));
            }
        }
        PHP, 'buatan.php', self::GERBANG);

        $this->assertCount(1, $pelanggaran);
        $this->assertStringContainsString('tanpaKredensial', $pelanggaran[0]);
    }

    public function test_pendeteksi_menangkap_pemakaian_di_blade(): void
    {
        // Berkas blade bukan PHP utuh, jadi `token_get_all()` tidak membacanya
        // sebagai kode. Tidak ada alasan sah sebuah tampilan menyensor pesan
        // galat — kalau namanya muncul di situ, itu pelanggaran apa pun
        // bentuknya.
        $pelanggaran = $this->pelanggaranDalamBlade(
            "<p>{{ \App\Services\PengirimVerifikasiEmail::tanpaKredensial(\$pesan) }}</p>\n",
            'buatan.blade.php'
        );

        $this->assertCount(1, $pelanggaran);
        $this->assertStringContainsString('tanpaKredensial', $pelanggaran[0]);
    }

    public function test_pendeteksi_benar_benar_membaca_berkas_gerbang(): void
    {
        // Pengaman yang menelusuri direktori yang salah selalu hijau, dan
        // pendeteksi yang hanya terbukti di atas sumber buatan belum terbukti
        // di atas berkas nyata. Dua hal dibuktikan di sini sekaligus:
        // penelusurannya SAMPAI ke berkas gerbang, dan pendeteksinya BISA
        // MELIHAT pemanggilan lintasan di dalamnya.
        $this->assertContains(self::BERKAS_GERBANG, $this->berkasProduksi(),
            'berkas gerbang tidak ikut ditelusuri — penelusuran kode produksi tidak sampai ke sana');

        // Kecualiannya dimatikan (`null`), jadi yang dilaporkan justru dua
        // pemanggilan sah di dalam `amanUntukLog()`. Daftar kosong di sini
        // berarti pendeteksinya buta pada berkas produksi — dan pendeteksi
        // buta membuat test aturan di atas hijau selamanya, apa pun isinya.
        $terlihat = $this->pelanggaranDalamPhp(
            (string) file_get_contents(base_path(self::BERKAS_GERBANG)),
            self::BERKAS_GERBANG,
            null
        );

        $this->assertNotEmpty($terlihat,
            'pendeteksi tidak melihat satu pun pemanggilan lintasan di '.self::BERKAS_GERBANG);

        foreach ($terlihat as $baris) {
            $this->assertStringContainsString(self::GERBANG.'()', $baris,
                'pemanggilan lintasan di luar '.self::GERBANG.'() — seharusnya sudah merah di test aturan: '.$baris);
        }

        foreach (self::LINTASAN as $lintasan) {
            $this->assertNotEmpty(
                array_filter($terlihat, fn (string $b): bool => str_contains($b, $lintasan)),
                $lintasan.' tidak terlihat sama sekali oleh pendeteksi — separuh aturannya tidak dijaga'
            );
        }
    }

    /* ---------- Pendeteksi ---------- */

    /**
     * Jalur relatif setiap berkas kode produksi, bukan `tests/`.
     *
     * `bootstrap/cache` dan `storage` dilewati: isinya kode hasil bangkitan
     * (rute yang di-cache, tampilan blade yang sudah dikompilasi), salinan
     * dari berkas yang sudah ditelusuri di atas. Ikut menelusurinya membuat
     * satu pelanggaran dilaporkan dua kali, dan bisa merah pada sisa cache
     * yang sudah tidak mewakili kode di repositori.
     *
     * @return list<string>
     */
    private function berkasProduksi(): array
    {
        $jalur = [];

        foreach (['app', 'routes', 'config', 'database', 'bootstrap', 'resources/views'] as $akar) {
            $absolut = base_path($akar);

            if (! is_dir($absolut)) {
                continue;
            }

            $berkas = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolut, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($berkas as $file) {
                if (! $file instanceof \SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                // Jalur relatif, bukan basename: `Admin/ProbeController.php` dan
                // `Api/ProbeController.php` adalah dua berkas dengan satu basename.
                $relatif = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                $relatif = str_replace(DIRECTORY_SEPARATOR, '/', $relatif);

                if (str_starts_with($relatif, 'bootstrap/cache/')) {
                    continue;
                }

                $jalur[] = $relatif;
            }
        }

        sort($jalur);

        return $jalur;
    }

    /**
     * @return list<string>
     */
    private function pelanggaranDiKodeProduksi(): array
    {
        $pelanggaran = [];

        foreach ($this->berkasProduksi() as $relatif) {
            $kode = (string) file_get_contents(base_path($relatif));

            if (! $this->memuatNamaLintasan($kode)) {
                continue;
            }

            $pelanggaran = array_merge($pelanggaran, str_ends_with($relatif, '.blade.php')
                ? $this->pelanggaranDalamBlade($kode, $relatif)
                // Gerbangnya hanya boleh ada di berkas yang mendefinisikannya.
                // Sebuah `amanUntukLog()` kedua di berkas lain yang memanggil
                // satu lintasan saja adalah pelanggaran yang sama, cuma
                // bernama benar.
                : $this->pelanggaranDalamPhp($kode, $relatif,
                    $relatif === self::BERKAS_GERBANG ? self::GERBANG : null));
        }

        sort($pelanggaran);

        return $pelanggaran;
    }

    private function memuatNamaLintasan(string $teks): bool
    {
        foreach (self::LINTASAN as $lintasan) {
            if (str_contains($teks, $lintasan)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Setiap penyebutan nama lintasan di kode PHP, kecuali yang sah.
     *
     * Dibaca lewat `token_get_all()`, bukan regex atas teks mentah, karena
     * docblock di `PengirimVerifikasiEmail` menyebut nama kedua lintasan
     * belasan kali untuk MENJELASKAN aturan ini. Regex tidak bisa membedakan
     * penjelasan dari pemanggilan; token bisa.
     *
     * @param  string|null  $fungsiYangBoleh  nama fungsi yang boleh memanggil lintasan di berkas ini
     * @return list<string>
     */
    private function pelanggaranDalamPhp(string $kode, string $label, ?string $fungsiYangBoleh): array
    {
        $pelanggaran = [];

        /** @var list<array{string, int}> $tumpukan */
        $tumpukan = [];
        $kedalaman = 0;
        $menungguNama = false;
        $menungguBody = null;

        foreach (token_get_all($kode) as $token) {
            if (is_string($token)) {
                if ($token === '{') {
                    $kedalaman++;

                    if ($menungguBody !== null) {
                        $tumpukan[] = [$menungguBody, $kedalaman];
                        $menungguBody = null;
                    }
                } elseif ($token === '}') {
                    $ujung = end($tumpukan);

                    if ($ujung !== false && $ujung[1] === $kedalaman) {
                        array_pop($tumpukan);
                    }

                    $kedalaman--;
                } elseif ($token === ';') {
                    // `abstract function f(): string;` — deklarasi tanpa body.
                    // Tanpa ini, body fungsi BERIKUTNYA dicatat dengan namanya.
                    $menungguBody = null;
                } elseif ($token === '(') {
                    $menungguNama = false;
                }

                continue;
            }

            [$id, $teks, $baris] = [$token[0], $token[1], $token[2]];

            if ($id === T_COMMENT || $id === T_DOC_COMMENT || $id === T_WHITESPACE) {
                continue;
            }

            if ($id === T_FUNCTION) {
                // Closure tidak punya nama; kalau T_STRING tidak datang sebelum
                // `(`, body-nya tercatat sebagai `{closure}`.
                $menungguNama = true;
                $menungguBody = '{closure}';

                continue;
            }

            if ($menungguNama) {
                if ($id === T_STRING) {
                    // Nama di DEKLARASI, bukan pemanggilan — `tanpaKredensial`
                    // di `public static function tanpaKredensial(...)` berhenti
                    // di sini dan tidak pernah dihitung pelanggaran.
                    $menungguBody = $teks;
                    $menungguNama = false;
                }

                continue;
            }

            $menyebut = $id === T_STRING
                ? in_array($teks, self::LINTASAN, true)
                : (($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE)
                    && $this->memuatNamaLintasan($teks));

            if (! $menyebut) {
                continue;
            }

            $fungsi = $this->fungsiTerdekatBernama($tumpukan);

            if ($fungsiYangBoleh !== null && $fungsi === $fungsiYangBoleh) {
                continue;
            }

            $pelanggaran[] = $label.':'.$baris.' — '.$fungsi.'() memakai '.trim($teks, '\'"');
        }

        return $pelanggaran;
    }

    /**
     * Nama fungsi terdekat yang PUNYA nama.
     *
     * Frame `{closure}` dilewati supaya `array_map(function (...) {...})` di
     * dalam gerbang tetap dihitung sebagai "di dalam gerbang". Tanpa ini,
     * merapikan gerbangnya dengan satu closure membuat pengaman ini merah
     * pada kode yang tidak berubah artinya.
     *
     * @param  list<array{string, int}>  $tumpukan
     */
    private function fungsiTerdekatBernama(array $tumpukan): string
    {
        foreach (array_reverse($tumpukan) as [$nama, $_]) {
            if ($nama !== '{closure}') {
                return $nama;
            }
        }

        return '{teratas}';
    }

    /**
     * Berkas blade: nama lintasan muncul di mana pun = pelanggaran.
     *
     * @return list<string>
     */
    private function pelanggaranDalamBlade(string $kode, string $label): array
    {
        $pelanggaran = [];

        foreach (preg_split('/\R/', $kode) ?: [] as $nomor => $baris) {
            foreach (self::LINTASAN as $lintasan) {
                if (str_contains($baris, $lintasan)) {
                    $pelanggaran[] = $label.':'.($nomor + 1).' — tampilan memakai '.$lintasan;
                }
            }
        }

        return $pelanggaran;
    }
}
