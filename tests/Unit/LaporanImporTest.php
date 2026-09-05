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
        $laporan->baris2026 = 237;
        $laporan->pelaku2026 = 34;

        // 14,3% — ambang API-24 adalah 25%. Kalimatnya harus menyebut itu
        // apa adanya; keputusan mencabut fitur pelacakan pelaku bergantung
        // pada kalimat ini dibaca benar.
        $this->assertSame(14.3, round($laporan->persenPelaku2026(), 1));
        $this->assertStringContainsString('**di bawah** ambang', $laporan->render('2026-09-05 00:00:00'));
    }

    public function test_porsi_pelaku_di_atas_ambang_tidak_dikatakan_di_bawah(): void
    {
        $laporan = $this->laporan();
        $laporan->totalBaris = 100;
        $laporan->baris2026 = 100;
        $laporan->pelaku2026 = 40;

        $this->assertStringNotContainsString('**di bawah** ambang', $laporan->render('2026-09-05 00:00:00'));
    }

    public function test_bentuk_nomor_nota_dipilah_bukan_dianggap_seragam(): void
    {
        $laporan = $this->laporan();

        foreach (['316', '2138 (Juli)', '929/1', '', '-', '1559 Des'] as $nota) {
            $laporan->catatNota($nota);
        }

        $laporan->totalBaris = 6;

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
