<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rekap laporan sebagai CSV.
 *
 * Bentuknya sengaja tidak berubah oleh API-62 nomor 4: CSV yang dipakai alat
 * lain tidak boleh berubah karena ada format kedua di sebelahnya. Ia juga
 * satu-satunya format di sini yang tidak menuntut satu pun pustaka.
 *
 * Yang tidak bisa diperbaikinya: Excel dengan wilayah Indonesia menebak
 * sendiri arti titik dan bentuk tanggal di dalam CSV, dan tebakannya kadang
 * salah. Itu alasan `.xlsx` ada — lihat EksporXlsx.
 *
 * Yang BISA diperbaikinya, dan sudah: nilai teks yang diawali `=`, `+`, `-`,
 * atau `@` tidak lagi lolos telanjang ke berkasnya. (API-77)
 */
final class EksporCsv
{
    public function __construct(private readonly RekapEkspor $rekap) {}

    public function unduh(): StreamedResponse
    {
        $rekap = $this->rekap;

        return response()->streamDownload(function () use ($rekap) {
            $out = fopen('php://output', 'w');

            fputcsv($out, $rekap->judulKolom());

            $tipe = $rekap->tipeKolom();

            foreach ($rekap->baris() as $baris) {
                $sel = [];

                foreach ($baris as $i => $nilai) {
                    $sel[] = $this->sel($nilai, $tipe[$i] ?? RekapEkspor::TEKS);
                }

                fputcsv($out, $sel);
            }

            fclose($out);
        }, $rekap->namaBerkas().'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Satu sel, bentuknya ditentukan TIPE kolomnya — sumber yang sama persis
     * dipakai EksporXlsx untuk memilih tipe selnya.
     *
     * Kolom angka ditulis telanjang seperti sebelumnya, termasuk yang
     * bernilai negatif: `-5000` di kolom Kompensasi memang harus dibaca
     * sebagai bilangan, dan menandainya teks justru merusak rekapnya.
     * Yang dijaga PerisaiRumus adalah kolom teks — di situlah nama pelapor
     * dan uraian keluhan berada, dan hanya di situ isinya ditulis orang luar.
     */
    private function sel(mixed $nilai, string $tipe): mixed
    {
        return match ($tipe) {
            // Tanggal ditulis 'Y-m-d H:i', persis seperti sebelumnya.
            RekapEkspor::TANGGAL => $nilai instanceof Carbon
                ? $nilai->format('Y-m-d H:i')
                : PerisaiRumus::untukCsv($nilai),
            RekapEkspor::ANGKA, RekapEkspor::RUPIAH => $nilai,
            default => PerisaiRumus::untukCsv($nilai),
        };
    }
}
