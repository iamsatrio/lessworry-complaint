<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rekap laporan sebagai CSV.
 *
 * TETAP ADA dan sengaja TIDAK BERUBAH satu byte pun oleh API-62 nomor 4:
 * CSV yang dipakai alat lain tidak boleh berubah bentuk karena ada format
 * kedua di sebelahnya. Ia juga satu-satunya format di sini yang tidak
 * menuntut satu pun pustaka.
 *
 * Yang tidak bisa diperbaikinya: Excel dengan wilayah Indonesia menebak
 * sendiri arti titik dan bentuk tanggal di dalam CSV, dan tebakannya kadang
 * salah. Itu alasan `.xlsx` ada — lihat EksporXlsx.
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

            foreach ($rekap->baris() as $baris) {
                fputcsv($out, array_map(
                    // Tanggal ditulis 'Y-m-d H:i', persis seperti sebelumnya.
                    fn ($nilai) => $nilai instanceof Carbon ? $nilai->format('Y-m-d H:i') : $nilai,
                    $baris,
                ));
            }

            fclose($out);
        }, $rekap->namaBerkas().'.csv', ['Content-Type' => 'text/csv']);
    }
}
