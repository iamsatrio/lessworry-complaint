<?php

namespace App\Services;

/**
 * Menerjemahkan nama baris layanan NEVIRA jadi kolom `layanan` sistem ini.
 * (API-51)
 *
 * Kasir memilih layanan sendiri dari dropdown, padahal notanya sudah tahu
 * jawabannya: baris yang dikeluhkan membawa namanya. Terjemahan ini yang
 * mengisikannya — sebagai isian awal yang tetap bisa disunting, bukan
 * sebagai keputusan.
 *
 * Yang tidak cocok pulang null. Menebak layanan yang salah lebih buruk
 * daripada membiarkan kolomnya kosong: laporan per layanan dibaca seolah
 * isinya dipastikan orang.
 */
class LayananNota
{
    /** @return string|null kunci config('complaint.layanan'), atau null */
    public static function dariNama(?string $nama): ?string
    {
        if (blank($nama)) {
            return null;
        }

        $cari = mb_strtolower($nama);

        foreach ((array) config('complaint.layanan_dari_nevira') as $potongan => $layanan) {
            if (! str_contains($cari, (string) $potongan)) {
                continue;
            }

            // Potongan yang menunjuk kunci yang tidak ada di
            // config('complaint.layanan') dilewati, bukan dipakai untuk
            // menghentikan pencarian. Dulu ia membalas null seketika: satu
            // salah tulis di config mematikan setiap potongan sesudahnya
            // untuk nama yang sama, dan tidak ada jejaknya di mana pun.
            // (API-58, catatan kecil)
            if (array_key_exists($layanan, (array) config('complaint.layanan'))) {
                return (string) $layanan;
            }
        }

        return null;
    }
}
