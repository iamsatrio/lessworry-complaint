<?php

namespace Tests\Unit;

use App\Services\PemindaiDumpSql;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pemindai isi dump. (API-27)
 *
 * Versi pertamanya memotong per baris dan ditembus dengan memindahkan satu
 * perintah ke baris sebelumnya. Test di sini menahan bentuk-bentuk itu, dan
 * sekaligus menjaga supaya penyaringnya tidak jadi rewel terhadap dump yang
 * sah — penyaring yang menolak dump asli akan dimatikan orang, dan itu lebih
 * buruk daripada tidak punya penyaring.
 */
class PemindaiDumpSqlTest extends TestCase
{
    private function tolak(string $sql, string $harapan, bool $mysql = false): void
    {
        try {
            (new PemindaiDumpSql($mysql))->periksa([$sql]);
            $this->fail('Seharusnya ditolak: '.$harapan);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($harapan, $e->getMessage());
        }
    }

    private function terima(string $sql, bool $mysql = false): void
    {
        (new PemindaiDumpSql($mysql))->periksa([$sql]);
        $this->assertTrue(true);
    }

    /* ---------- Bentuk yang menembus versi per baris ---------- */

    public function test_attach_sebaris_sesudah_titik_koma_ditolak(): void
    {
        // Bentuk persis yang dipakai Buffon menulis ke berkas database di luar
        // direktori backup, dan lolos dari versi per baris.
        $this->tolak(
            "INSERT INTO complaints VALUES (1);ATTACH DATABASE '/tmp/korban.sqlite' AS korban;",
            'ATTACH DATABASE'
        );
    }

    public function test_attach_di_belakang_komentar_blok_ditolak(): void
    {
        $this->tolak("/* x */ATTACH DATABASE '/tmp/korban.sqlite' AS korban;", 'ATTACH DATABASE');
    }

    public function test_use_sebaris_sesudah_titik_koma_ditolak(): void
    {
        // Di SQLite ini ditolak parser SQLite juga, tapi di MySQL dulunya
        // lolos pemeriksaan isi dan hanya tersisa --one-database.
        $this->tolak('INSERT INTO t VALUES (1);USE lessworry_care;', 'USE', mysql: true);
    }

    public function test_use_di_belakang_komentar_baris_ditolak(): void
    {
        $this->tolak("-- dump harian\nUSE `lessworry_care`;", 'USE', mysql: true);
    }

    public function test_use_di_dalam_komentar_versi_mysql_ditolak(): void
    {
        // `/*!40000 ... */` bukan komentar bagi MySQL — isinya dijalankan.
        $this->tolak('/*!40000 USE `lessworry_care`*/;', 'USE', mysql: true);
    }

    public function test_create_database_dengan_indentasi_dan_baris_baru_ditolak(): void
    {
        $this->tolak("\n\n    CREATE\n  DATABASE `lessworry_care`;", 'CREATE DATABASE', mysql: true);
    }

    public function test_pernyataan_terakhir_tanpa_titik_koma_tetap_diperiksa(): void
    {
        $this->tolak("SELECT 1;\nATTACH DATABASE '/tmp/korban.sqlite' AS korban", 'ATTACH DATABASE');
    }

    public function test_drop_dan_detach_ikut_ditolak(): void
    {
        $this->tolak('DROP DATABASE lessworry_care;', 'DROP DATABASE', mysql: true);
        $this->tolak('DETACH DATABASE korban;', 'DETACH DATABASE');
    }

    /* ---------- Dump yang sah tidak boleh ditolak ---------- */

    public function test_perintah_terlarang_di_dalam_string_bukan_alarm(): void
    {
        // Isi complaint bisa memuat apa saja. Kalau ini ditolak, penyaringnya
        // menolak dump asli — dan penyaring yang begitu akan dimatikan orang.
        $this->terima("INSERT INTO complaints (description) VALUES ('pelanggan menulis: ;ATTACH DATABASE ini;');");
    }

    public function test_kutip_ganda_di_dalam_string_tidak_membuat_pemindai_salah_langkah(): void
    {
        $this->terima("INSERT INTO t VALUES ('bu ''Sari'' komplain');ATTACH_LOG_OK;");
    }

    public function test_backslash_escape_mysql_tidak_membuat_pemindai_salah_langkah(): void
    {
        // mysqldump meng-escape kutip sebagai \\'. Kalau tidak dipahami,
        // pemindai keluar dari string terlalu cepat dan menolak dump asli.
        $this->terima("INSERT INTO t VALUES ('bu Sari\\'s baju');\nINSERT INTO t VALUES (2);", mysql: true);
    }

    public function test_backslash_di_akhir_string_sqlite_tidak_menelan_pernyataan_berikutnya(): void
    {
        // Di SQLite `\` bukan escape: string ini SELESAI di kutip berikutnya.
        // Kalau dianggap escape, ATTACH sesudahnya tertelan sebagai isi string
        // dan lolos.
        $this->tolak(
            "INSERT INTO t VALUES ('C:\\');ATTACH DATABASE '/tmp/korban.sqlite' AS korban;",
            'ATTACH DATABASE'
        );
    }

    public function test_kata_terlarang_di_tengah_pernyataan_bukan_alarm(): void
    {
        $this->terima('CREATE TABLE use_database (id integer);');
        $this->terima('INSERT INTO t (attach_count) VALUES (3);');
    }

    public function test_dump_yang_dipotong_di_tengah_pernyataan_tetap_terbaca(): void
    {
        // Dump dibaca sepotong-sepotong 1 MB. Perintah terlarang bisa jatuh
        // persis di sambungan dua potongan.
        $pemindai = new PemindaiDumpSql(false);

        $this->expectException(RuntimeException::class);

        $pemindai->periksa(['SELECT 1;AT', 'TACH DATA', "BASE '/tmp/korban.sqlite' AS korban;"]);
    }

    public function test_komentar_yang_terpotong_di_sambungan_tetap_komentar(): void
    {
        $pemindai = new PemindaiDumpSql(false);
        $pemindai->periksa(['SELECT 1; /', "* ATTACH DATABASE 'x' AS y; */", 'SELECT 2;']);

        $this->assertTrue(true);
    }
}
