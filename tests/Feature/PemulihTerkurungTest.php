<?php

namespace Tests\Feature;

use App\Services\PemulihSqliteTerkurung;
use RuntimeException;
use Tests\TestCase;

/**
 * Kurungan restore SQLite. (API-27)
 *
 * Test di sini sengaja TIDAK memakai pemindai teks sama sekali — dumpnya
 * diberikan langsung ke pemulihnya. Itu intinya: kalau pemindainya suatu hari
 * ditembus lagi oleh bentuk yang belum terpikir, yang tersisa adalah lapis ini,
 * dan lapis ini harus berdiri sendiri.
 */
class PemulihTerkurungTest extends TestCase
{
    public function test_memulihkan_dump_yang_wajar_dan_menghitung_barisnya(): void
    {
        $baris = (new PemulihSqliteTerkurung)->pulihkan([
            'CREATE TABLE complaints (id integer);',
            'INSERT INTO complaints VALUES (1);INSERT INTO complaints VALUES (2);',
        ]);

        $this->assertSame(2, $baris);
    }

    public function test_attach_ke_berkas_di_luar_kurungan_gagal_dan_berkasnya_tidak_tersentuh(): void
    {
        // Berkas ini berdiri di luar kurungan. Dump di bawah mencoba
        // menempelkannya lalu menulis ke sana — persis serangan yang dipakai
        // menembus pemindai teks dua kali.
        $korban = storage_path('app/korban-terkurung-'.uniqid().'.sqlite');
        file_put_contents($korban, '');

        try {
            $this->expectException(RuntimeException::class);

            try {
                (new PemulihSqliteTerkurung)->pulihkan([
                    'CREATE TABLE complaints (id integer);',
                    "ATTACH DATABASE '{$korban}' AS korban;",
                    'CREATE TABLE korban.bukti (x text);',
                    "INSERT INTO korban.bukti VALUES ('ditulis');",
                ]);
            } finally {
                // Yang menahan bukan pengenalan pola: prosesnya memang tidak
                // bisa membuka berkas itu.
                $this->assertSame('', (string) file_get_contents($korban));
                @unlink($korban);
            }
        } finally {
        }
    }

    public function test_dump_tidak_bisa_membaca_berkas_di_luar_kurungan(): void
    {
        // readfile lewat SQL tidak ada, tapi ATTACH ke berkas yang SUDAH berisi
        // database tetap cara membaca. Ditolak juga.
        $lain = storage_path('app/sumber-'.uniqid().'.sqlite');
        $pdo = new \PDO('sqlite:'.$lain);
        $pdo->exec('CREATE TABLE rahasia (isi text); INSERT INTO rahasia VALUES (\'x\');');
        unset($pdo);

        try {
            $this->expectException(RuntimeException::class);

            (new PemulihSqliteTerkurung)->pulihkan([
                "CREATE TABLE complaints (id integer);ATTACH DATABASE '{$lain}' AS lain;"
                .'INSERT INTO complaints SELECT rowid FROM lain.rahasia;',
            ]);
        } finally {
            @unlink($lain);
        }
    }

    public function test_dump_tanpa_tabel_complaints_dilaporkan_apa_adanya(): void
    {
        try {
            (new PemulihSqliteTerkurung)->pulihkan(['CREATE TABLE lain (id integer);']);
            $this->fail('Seharusnya gagal: tabel complaints tidak ada.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tabel complaints tidak ada', $e->getMessage());
        }
    }

    public function test_dump_yang_rusak_gagal_tanpa_menyisakan_direktori_kurungan(): void
    {
        $sebelum = glob(sys_get_temp_dir().'/lw-verify-*') ?: [];

        try {
            (new PemulihSqliteTerkurung)->pulihkan(['ini bukan sql sama sekali']);
            $this->fail('Seharusnya gagal.');
        } catch (RuntimeException) {
            // diharapkan
        }

        $this->assertSame($sebelum, glob(sys_get_temp_dir().'/lw-verify-*') ?: []);
    }
}
