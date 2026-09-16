<?php

namespace Tests\Unit;

use App\Models\Tagihan;
use App\Services\PeriodeTagihan;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Aritmetika jatuh tempo tagihan. (API-73 kriteria 3)
 *
 * Kalender adalah tempat bug yang paling tidak berbunyi: yang salah tidak
 * melempar, ia hanya menerbitkan alarm sehari lebih cepat atau sebulan lebih
 * lambat, dan tidak ada yang punya pembandingnya.
 *
 * Tidak menyentuh database — Tagihan dipakai sebagai wadah nilai saja.
 */
class JatuhTempoTagihanTest extends TestCase
{
    private function tagihan(int $hari, ?int $bulan = null): Tagihan
    {
        $tagihan = new Tagihan([
            'nama' => 'Uji',
            'jatuh_tempo_hari' => $hari,
            'jatuh_tempo_bulan' => $bulan,
            'pengulangan' => $bulan === null ? 'bulanan' : 'tahunan',
        ]);

        return $tagihan;
    }

    /* ---------- Kriteria 3 ---------- */

    public function test_tanggal_31_di_februari_jatuh_ke_hari_terakhir(): void
    {
        $tagihan = $this->tagihan(31);

        $this->assertSame(
            '2026-02-28',
            $tagihan->jatuhTempoDi(CarbonImmutable::parse('2026-02-10'))->toDateString(),
            'Februari 2026 tidak punya tanggal 31 — jatuh temponya hari terakhir.'
        );
    }

    public function test_tanggal_31_di_februari_kabisat_jatuh_ke_29(): void
    {
        $this->assertSame(
            '2028-02-29',
            $this->tagihan(31)->jatuhTempoDi(CarbonImmutable::parse('2028-02-10'))->toDateString(),
            'Tahun kabisat punya 29 Februari, dan itu hari terakhirnya.'
        );
    }

    public function test_tanggal_31_di_april_jatuh_ke_30(): void
    {
        $this->assertSame(
            '2026-04-30',
            $this->tagihan(31)->jatuhTempoDi(CarbonImmutable::parse('2026-04-01'))->toDateString()
        );
    }

    /**
     * Pemotongan tidak boleh MENURUN: kalau Februari memotong 31 jadi 28, dan
     * Maret menghitungnya dari 28 itu, deretnya bergeser turun dan tidak
     * pernah kembali ke 31. Inilah alasan `jatuh_tempo_hari` disimpan apa
     * adanya, bukan sudah dipotong.
     */
    public function test_deret_bulanan_kembali_ke_31_sesudah_februari(): void
    {
        $tagihan = $this->tagihan(31);

        $kursor = $tagihan->jatuhTempoDi(CarbonImmutable::parse('2026-01-15'));
        $deret = [$kursor->toDateString()];

        for ($i = 0; $i < 4; $i++) {
            $kursor = $tagihan->jatuhTempoSetelah($kursor);
            $deret[] = $kursor->toDateString();
        }

        $this->assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'],
            $deret
        );
    }

    public function test_deret_mundur_juga_tidak_bergeser(): void
    {
        $tagihan = $this->tagihan(31);

        $kursor = $tagihan->jatuhTempoDi(CarbonImmutable::parse('2026-05-15'));
        $deret = [$kursor->toDateString()];

        for ($i = 0; $i < 4; $i++) {
            $kursor = $tagihan->jatuhTempoSebelum($kursor);
            $deret[] = $kursor->toDateString();
        }

        $this->assertSame(
            ['2026-05-31', '2026-04-30', '2026-03-31', '2026-02-28', '2026-01-31'],
            $deret
        );
    }

    public function test_desember_ke_januari_menyeberang_tahun(): void
    {
        $tagihan = $this->tagihan(5);

        $this->assertSame(
            '2027-01-05',
            $tagihan->jatuhTempoSetelah(CarbonImmutable::parse('2026-12-05'))->toDateString()
        );

        $this->assertSame(
            '2025-12-05',
            $tagihan->jatuhTempoSebelum(CarbonImmutable::parse('2026-01-05'))->toDateString()
        );
    }

    /* ---------- Tahunan ---------- */

    public function test_tagihan_tahunan_memakai_bulannya_sendiri(): void
    {
        $tagihan = $this->tagihan(15, 3);

        $this->assertSame(
            '2026-03-15',
            $tagihan->jatuhTempoDi(CarbonImmutable::parse('2026-09-30'))->toDateString(),
            'Tagihan tahunan jatuh di bulannya sendiri, bukan di bulan yang sedang berjalan.'
        );

        $this->assertSame(
            '2027-03-15',
            $tagihan->jatuhTempoSetelah(CarbonImmutable::parse('2026-03-15'))->toDateString()
        );
    }

    public function test_tagihan_tahunan_29_februari_jatuh_ke_28_di_tahun_biasa(): void
    {
        $tagihan = $this->tagihan(29, 2);

        $this->assertSame('2028-02-29', $tagihan->jatuhTempoDi(CarbonImmutable::parse('2028-06-01'))->toDateString());
        $this->assertSame('2029-02-28', $tagihan->jatuhTempoSetelah(CarbonImmutable::parse('2028-02-29'))->toDateString());
    }

    /* ---------- Kunci periode ---------- */

    public function test_periode_satu_bentuk_untuk_bulanan_dan_tahunan(): void
    {
        $this->assertSame('2026-02', Tagihan::periode(CarbonImmutable::parse('2026-02-28')));
        $this->assertSame('2026-03', Tagihan::periode(CarbonImmutable::parse('2026-03-15')));
    }

    public function test_keterangan_menyebut_berapa_hari(): void
    {
        $this->assertSame('Telat 5 hari', PeriodeTagihan::keterangan(-5));
        $this->assertSame('Telat 1 hari', PeriodeTagihan::keterangan(-1));
        $this->assertSame('Hari ini', PeriodeTagihan::keterangan(0));
        $this->assertSame('Besok', PeriodeTagihan::keterangan(1));
        $this->assertSame('3 hari lagi', PeriodeTagihan::keterangan(3));
    }
}
