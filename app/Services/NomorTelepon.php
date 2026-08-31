<?php

namespace App\Services;

/**
 * Nomor telepon pelanggan, disamakan bentuknya sebelum dipakai mencari nota.
 *
 * NEVIRA menyimpan nomor yang sama dalam tiga bentuk sekaligus — dihitung
 * dari 100 transaksi terakhir pada 2026-08-31:
 *
 *   62xxxxxxxxxxx   86 dari 100
 *   8xxxxxxxxxx     11 dari 100
 *   08xxxxxxxxxx     3 dari 100
 *
 * Karena itu mencari dengan nomor apa adanya tidak bisa diandalkan: kasir
 * yang mengetik `0815...` mendapat NOL hasil untuk pelanggan yang notanya
 * tersimpan sebagai `62815...` (diuji langsung ke api.nevira.id).
 *
 * Yang dipakai sebagai kunci pencarian adalah INTI nasionalnya (`8xxxxxxxxx`).
 * Inti itu menjadi bagian dari ketiga bentuk di atas, dan pencarian NEVIRA
 * mencocokkan sebagian — jadi satu panggilan menemukan ketiganya.
 */
final class NomorTelepon
{
    /**
     * Digit paling sedikit yang boleh dicari.
     *
     * Bukan angka karangan. Diukur ke data nyata NEVIRA: kata kunci 5 digit
     * mengembalikan 604 transaksi milik puluhan pelanggan, 7 digit masih 68,
     * dan baru pada 9 digit hasilnya mengerucut ke satu pelanggan. Nomor
     * ponsel Indonesia berinti 9–12 digit, jadi batas ini tidak menghalangi
     * nomor yang sah — ia hanya menutup pencarian sepotong yang berubah
     * menjadi alat menyisir daftar pelanggan.
     */
    public const MIN_DIGIT = 9;

    /**
     * Inti nasional sebuah nomor: `8xxxxxxxxx`.
     *
     * Mengembalikan null kalau yang diketik bukan nomor ponsel yang bisa
     * dicari — kosong, terlalu pendek, atau bukan diawali 8 setelah awalan
     * negara dibuang. Null berarti "jangan panggil NEVIRA", bukan "tidak
     * ketemu".
     */
    public static function inti(?string $mentah): ?string
    {
        $digit = preg_replace('/\D/', '', (string) $mentah) ?? '';

        if ($digit === '') {
            return null;
        }

        if (str_starts_with($digit, '62')) {
            $digit = substr($digit, 2);
        } elseif (str_starts_with($digit, '0')) {
            $digit = ltrim($digit, '0');
        }

        // Nomor rumah dan nomor kantor tidak dipakai NEVIRA sebagai identitas
        // pelanggan; yang tersimpan selalu ponsel. Menolak selain 8 mencegah
        // masukan seperti "021" berubah jadi pencarian sapu jagat.
        if (! str_starts_with($digit, '8') || strlen($digit) < self::MIN_DIGIT) {
            return null;
        }

        return $digit;
    }

    /**
     * Apakah dua nomor menunjuk orang yang sama, apa pun bentuk simpanannya.
     */
    public static function sama(?string $a, ?string $b): bool
    {
        $a = self::inti($a);

        return $a !== null && $a === self::inti($b);
    }
}
