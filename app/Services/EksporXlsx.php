<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rekap laporan sebagai `.xlsx`. (API-62 nomor 4)
 *
 * ALASANNYA BUKAN KENYAMANAN. CSV yang ada rusak saat dibuka Excel dengan
 * wilayah Indonesia: Excel id-ID membaca titik sebagai pemisah desimal dan
 * menebak sendiri bentuk tanggalnya, jadi `30.881.208` bisa terbaca
 * `30,881208` dan tanggal bisa tertukar hari-bulannya. Itu bukan
 * ketidaknyamanan — itu angka yang berubah diam-diam di berkas yang dipakai
 * mengambil keputusan.
 *
 * `.xlsx` menyimpan tipe tiap selnya, jadi tidak ada yang perlu ditebak:
 * angka tetap angka, tanggal tetap tanggal, dan nomor telepon tetap teks
 * lengkap dengan nol di depannya.
 *
 * Kolomnya sama persis dengan CSV — keduanya membaca RekapEkspor.
 */
final class EksporXlsx
{
    /**
     * Format rupiah Excel. `#.##0` dalam notasi Indonesia ditulis `#,##0` di
     * berkas xlsx: pemisah di dalam berkas SELALU koma dan titik, dan Excel
     * yang menukarnya sesuai wilayah pembacanya. Menulis `#.##0` di sini
     * justru menghasilkan angka yang salah di mesin Indonesia.
     */
    private const FORMAT_RUPIAH = '"Rp" #,##0';

    /** Tanggal dan jam, urutan hari-bulan-tahun seperti kebiasaan di sini. */
    private const FORMAT_TANGGAL = 'dd/mm/yyyy hh:mm';

    public function __construct(private readonly RekapEkspor $rekap) {}

    public function unduh(): StreamedResponse
    {
        $rekap = $this->rekap;

        return response()->streamDownload(function () use ($rekap) {
            $writer = new Writer;
            $writer->openToFile('php://output');

            $tebal = (new Style)->setFontBold();
            $rupiah = (new Style)->setFormat(self::FORMAT_RUPIAH);
            $tanggal = (new Style)->setFormat(self::FORMAT_TANGGAL);

            $writer->addRow(Row::fromValues($rekap->judulKolom(), $tebal));

            $tipe = $rekap->tipeKolom();

            foreach ($rekap->baris() as $baris) {
                $sel = [];

                foreach ($baris as $i => $nilai) {
                    $sel[] = $this->sel($nilai, $tipe[$i] ?? RekapEkspor::TEKS, $rupiah, $tanggal);
                }

                $writer->addRow(new Row($sel));
            }

            $writer->close();
        }, $rekap->namaBerkas().'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Satu sel, tipenya ditentukan kolomnya — BUKAN ditebak dari nilainya.
     *
     * Sengaja tidak memakai `Cell::fromValue()`: helper itu mengubah teks yang
     * diawali `=` menjadi RUMUS. Nama pelapor `=cmd|...` yang tersimpan
     * sebagai rumus adalah lubang penyuntikan rumus yang sudah dikenal, dan
     * di berkas yang dikirim ke ponsel orang lain itu bukan risiko teoretis.
     * Kolom teks di sini selalu jadi teks, apa pun huruf pertamanya.
     */
    private function sel(mixed $nilai, string $tipe, Style $rupiah, Style $tanggal): Cell
    {
        if ($nilai === null || $nilai === '') {
            return new EmptyCell(null, null);
        }

        return match ($tipe) {
            RekapEkspor::TANGGAL => $nilai instanceof Carbon
                ? new DateTimeCell($nilai->toDateTime(), $tanggal)
                : new StringCell((string) $nilai, null),
            RekapEkspor::RUPIAH => new NumericCell((int) $nilai, $rupiah),
            RekapEkspor::ANGKA => new NumericCell((int) $nilai, null),
            default => new StringCell((string) $nilai, null),
        };
    }
}
