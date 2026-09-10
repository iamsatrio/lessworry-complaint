<?php

namespace Tests\Unit;

use App\Services\LaporanImpor;
use PHPUnit\Framework\TestCase;

/**
 * Angka-angka yang jadi dasar keputusan orang. (API-28 bagian 4)
 *
 * Diuji terpisah dari perintahnya: yang penting di sini bukan impornya
 * berjalan, melainkan bahwa laporan tidak pernah membulatkan ke arah yang
 * membuat sistem terlihat lebih baik daripada datanya.
 */
class LaporanImporTest extends TestCase
{
    private function laporan(): LaporanImpor
    {
        return new LaporanImpor('uji', '/tmp/uji.csv', false);
    }

    public function test_porsi_pelaku_di_bawah_ambang_dikatakan_di_bawah(): void
    {
        $laporan = $this->laporan();
        $laporan->totalBaris = 545;
        $laporan->barisDiimpor = 88;
        $laporan->pelakuDiimpor = 14;

        // 15,9% — ambang API-24 adalah 25%. Kalimatnya harus menyebut itu
        // apa adanya; keputusan mencabut fitur pelacakan pelaku bergantung
        // pada kalimat ini dibaca benar.
        $this->assertSame(15.9, round($laporan->persenPelaku(), 1));
        $this->assertStringContainsString('**di bawah** ambang', $laporan->render('2026-09-05 00:00:00'));
    }

    public function test_porsi_pelaku_dihitung_atas_baris_yang_diimpor_bukan_seluruh_berkas(): void
    {
        $laporan = $this->laporan();
        // Seluruh berkas 40%, tapi yang diimpor cuma 10% — yang menentukan
        // keputusan adalah baris yang benar-benar masuk ke sistem.
        $laporan->totalBaris = 100;
        $laporan->pelakuTotal = 40;
        $laporan->barisDiimpor = 10;
        $laporan->pelakuDiimpor = 1;

        $isi = $laporan->render('2026-09-05 00:00:00');

        $this->assertSame(10.0, round($laporan->persenPelaku(), 1));
        $this->assertStringContainsString('**di bawah** ambang', $isi);
        $this->assertStringContainsString('| Seluruh berkas | 40 | 100 | 40,0% |', $isi);
    }

    public function test_porsi_pelaku_di_atas_ambang_tidak_dikatakan_di_bawah(): void
    {
        $laporan = $this->laporan();
        $laporan->totalBaris = 100;
        $laporan->barisDiimpor = 100;
        $laporan->pelakuDiimpor = 40;

        $this->assertStringNotContainsString('**di bawah** ambang', $laporan->render('2026-09-05 00:00:00'));
    }

    public function test_baris_yang_dilewati_karena_tua_punya_angkanya_sendiri(): void
    {
        $laporan = new LaporanImpor('uji', '/tmp/uji.csv', false, '2026-05-16');
        $laporan->totalBaris = 545;
        $laporan->dilewatiTua = 457;
        $laporan->barisDiimpor = 88;
        $laporan->masuk = 88;

        $isi = $laporan->render('2026-09-05 00:00:00');

        // Tanpa angka ini, "88 masuk" dari berkas 545 baris terbaca seolah
        // berkasnya memang hanya berisi 88.
        $this->assertStringContainsString('| Dibaca dari berkas | 545 |', $isi);
        $this->assertStringContainsString('| Dilewati (lebih tua dari tanggal potong) | 457 |', $isi);
        $this->assertStringContainsString('| Lolos tanggal potong | 88 |', $isi);
        $this->assertStringContainsString('`2026-05-16` (inklusif; baris lebih tua dilewati)', $isi);
    }

    public function test_tanpa_tanggal_potong_dikatakan_tanpa_batas(): void
    {
        $isi = $this->laporan()->render('2026-09-05 00:00:00');

        $this->assertStringContainsString('Tanggal potong: tidak ada — seluruh berkas diimpor', $isi);
    }

    public function test_bentuk_nomor_nota_dipilah_bukan_dianggap_seragam(): void
    {
        $laporan = $this->laporan();

        foreach (['316', '2138 (Juli)', '929/1', '', '-', '1559 Des'] as $nota) {
            $laporan->catatNota($nota);
        }

        $laporan->totalBaris = 6;
        $laporan->barisDiimpor = 6;

        $this->assertSame(1, $laporan->nota['angka']);
        $this->assertSame(1, $laporan->nota['angka_bulan']);
        $this->assertSame(1, $laporan->nota['angka_sub']);
        $this->assertSame(1, $laporan->nota['kosong']);
        // `-` dan `1559 Des` sama-sama tidak terbaca sebagai nomor nota.
        $this->assertSame(2, $laporan->nota['tidak_terbaca']);
        $this->assertSame(50.0, round($laporan->persenNotaTakTerpakai(), 1));
    }

    public function test_sebaran_yang_tidak_cocok_dikatakan_tidak_cocok(): void
    {
        $laporan = $this->laporan();
        $laporan->sebaranCsv = ['2026-01' => 33, '2026-02' => 25];
        $laporan->sebaranDb = ['2026-01' => 33, '2026-02' => 24];

        // Satu baris hilang diam-diam adalah persis yang bagian ini cari.
        $this->assertStringContainsString('**Tidak cocok** — selisih mutlak 1 baris.', $laporan->render('x'));
    }
}
