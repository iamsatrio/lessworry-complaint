<?php

namespace App\Services;

use App\Models\Complaint;

/**
 * Aturan membaca nilai biaya complaint, di satu tempat. (API-43)
 *
 * Empat aturan yang kalau berbeda antar halaman akan membuat dua halaman
 * melaporkan dua kerugian yang berbeda dari data yang sama:
 *
 * - kapan sebuah complaint dianggap PUNYA nilai biaya;
 * - kalimat cakupan yang wajib menempel pada setiap total biaya;
 * - ambang cakupan yang disebut "rendah";
 * - median, yang dipakai berdampingan dengan rata-rata.
 *
 * GrafikLaporan dan RekapKerugian sama-sama membaca dari sini. Halaman
 * Kerugian yang menghitung sendiri apa artinya "tercatat" adalah halaman yang
 * suatu hari akan berselisih dengan grafik di sebelahnya, dan yang mengalah
 * selalu yang lebih jarang dibuka.
 */
final class NilaiBiaya
{
    /**
     * Cakupan di bawah persen ini ditandai. Di bawahnya, sebuah total lebih
     * banyak mengukur ketekunan pengisian kolom daripada kerugiannya.
     */
    public const AMBANG_RENDAH = 50;

    /**
     * Complaint ini punya nilai biaya yang benar-benar dicatat?
     *
     * Kolomnya `unsignedBigInteger default 0` dan tidak nullable, jadi basis
     * data TIDAK BISA membedakan "kompensasi Rp 0" dari "tidak pernah diisi" —
     * impor data lama pun menulis 0 untuk sel kosong. Selama kolomnya masih
     * begitu, nol dibaca sebagai TIDAK TERCATAT: tidak ikut dijumlah, tidak
     * ikut jadi pembagi rata-rata maupun median, dan tidak dihitung sebagai
     * cakupan. Menghitungnya sebagai Rp 0 akan menarik turun setiap rata-rata
     * dengan angka yang tidak pernah ada orang yang mencatatnya.
     */
    public static function tercatat(Complaint $complaint): bool
    {
        return (int) $complaint->compensation_amount > 0;
    }

    /** Cakupan yang wajib menempel pada setiap total biaya. */
    public static function cakupanTeks(int $terisi, int $total): string
    {
        return 'dari '.$terisi.' dari '.$total.' complaint yang punya nilai biaya';
    }

    /**
     * Cakupan dalam persen, atau null kalau tidak ada complaint sama sekali.
     * Nol complaint bukan cakupan 0% — cakupannya TIDAK ADA.
     */
    public static function persen(int $terisi, int $total): ?int
    {
        return $total === 0 ? null : (int) round($terisi / $total * 100);
    }

    /** Cakupan yang cukup rendah untuk ditandai. Null tidak pernah rendah. */
    public static function rendah(?int $persen): bool
    {
        return $persen !== null && $persen < self::AMBANG_RENDAH;
    }

    public static function rupiah(int $nilai): string
    {
        return 'Rp '.number_format($nilai, 0, ',', '.');
    }

    /**
     * Median, bukan rata-rata: satu kasus Rp 3.330.000 menarik rata-rata dan
     * membuat bulan yang baik terlihat buruk.
     *
     * @param  list<float>  $nilai  tidak boleh kosong
     */
    public static function median(array $nilai): float
    {
        sort($nilai);
        $n = count($nilai);
        $tengah = intdiv($n, 2);

        return $n % 2 === 1
            ? $nilai[$tengah]
            : ($nilai[$tengah - 1] + $nilai[$tengah]) / 2;
    }
}
