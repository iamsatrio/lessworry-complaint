<?php

namespace App\Services;

/**
 * Menarik `Sepatu & Tas` dan `Karpet & Gorden` keluar dari `Satuan Non Cloth`,
 * dibaca dari uraian keluhannya. (API-59)
 *
 * SATU-SATUNYA tempat kata kuncinya ditulis. Perintah `complaint:betulkan-layanan`
 * dan `PemetaBarisImpor` sama-sama memanggil kelas ini, bukan menyalin polanya
 * masing-masing: kalau keduanya punya salinan sendiri, impor ulang berkas yang
 * sama akan mengembalikan baris ke `satuan_non_cloth` begitu salah satunya
 * disunting, dan tidak ada yang tahu sampai laporannya dibaca.
 *
 * Aturan yang menentukan seluruh kelas ini: **`layanan` adalah jasa yang
 * DIBELI pelanggan, bukan barang yang kebetulan disebut dalam keluhan.**
 * Kata kunci di bawah cuma alat untuk menebak jasanya dari uraian; begitu
 * alat itu dibaca sebagai "sebut barangnya, dapat layanannya", ia mulai
 * memindahkan baris yang salah.
 *
 * Tiga aturan turunannya, dan tak satu pun kehati-hatian berlebihan:
 *
 * 1. **Hanya baris yang sekarang bernilai `satuan_non_cloth`.** Yang memanggil
 *    kelas ini bertanggung jawab menyaringnya; `self::ASAL` yang menyebut
 *    nilainya supaya penyaringnya tidak ditulis ulang sebagai teks lepas.
 * 2. **`tas laundry` dan `tas cuci` DIKECUALIKAN.** Tas laundry itu WADAH
 *    tempat cucian datang dan pulang — tasnya memang milik pelanggan, tapi
 *    bukan tasnya yang dicuci. Yang dibeli pelanggan Kiloan: pakaiannya, per
 *    kilo. Bandingkan dengan 47 baris yang memang pindah ke `sepatu_tas` —
 *    "Tas belum bersih", "tas carrer kurang bersih dibagian dalam" — di situ
 *    tasnya YANG DIKERJAKAN. Bedanya bukan siapa pemiliknya; bedanya apakah
 *    barang itu objek jasanya. Empat baris "Tas laundry gak dikembalikan" di
 *    data nyata semuanya ber-layanan Kiloan, dan memindahkannya mengubah
 *    keluhan tentang wadah jadi keluhan tentang layanan cuci tas.
 * 3. **Semua pencocokan memakai batas kata.** `tas` tanpa `\b` mencocokkan
 *    "batas", "pantas", dan "tastes" — dan itu merusak diam-diam.
 */
final class LayananDariUraian
{
    /**
     * Layanan yang boleh dipertajam. Baris ber-layanan apa pun selain ini
     * tidak pernah disentuh, apa pun isi uraiannya.
     */
    public const ASAL = 'satuan_non_cloth';

    /**
     * Kata kunci per layanan tujuan. Urutannya menentukan pemenang kalau
     * sebuah uraian menyebut keduanya; karpet lebih dulu karena nilainya per
     * kejadian jauh lebih besar, jadi salah pindah ke arah itu lebih mudah
     * terlihat di laporan daripada tenggelam di antara 47 baris sepatu.
     *
     * `\w*` pada karpet menampung "karpetnya"/"gordennya". Sepatu tidak
     * memakainya: "sandal" berimbuhan tidak muncul di data, sementara `\w*`
     * di sana akan menarik kata lain yang berawalan sama.
     *
     * @var array<string,string>
     */
    private const POLA = [
        'karpet_gorden' => '/\b(?:karpet|gorden|gordyn|vitrase|tirai)\w*/iu',
        'sepatu_tas' => '/\b(?:sepatu|sneakers?|sandal|sendal|heels|koper|ransel)\b|\btas\b(?!\s+(?:laundry|cuci)\b)/iu',
    ];

    /** Nilai layanan yang bisa dihasilkan kelas ini. @return list<string> */
    public static function tujuan(): array
    {
        return array_keys(self::POLA);
    }

    /**
     * Layanan yang cocok dengan uraian ini, atau null kalau tidak ada.
     *
     * Yang tidak cocok pulang null dan baris tetap `satuan_non_cloth`. Nilai
     * yang salah diam-diam lebih buruk daripada nilai lama yang terlalu kasar:
     * yang kasar masih jujur, yang salah dibaca seolah dipastikan orang.
     */
    public static function tebak(?string $uraian): ?string
    {
        return self::periksa($uraian)['layanan'] ?? null;
    }

    /**
     * Sama seperti `tebak()`, tapi ikut membawa kata yang cocok dan letaknya —
     * dua-duanya dipakai laporan kering supaya orang yang membacanya tahu
     * KENAPA sebuah baris terpilih, bukan cuma bahwa ia terpilih.
     *
     * @return array{layanan:string,kata:string,posisi:int}|null
     */
    public static function periksa(?string $uraian): ?array
    {
        if (blank($uraian)) {
            return null;
        }

        foreach (self::POLA as $layanan => $pola) {
            if (preg_match($pola, $uraian, $m, PREG_OFFSET_CAPTURE) === 1) {
                return ['layanan' => $layanan, 'kata' => (string) $m[0][0], 'posisi' => (int) $m[0][1]];
            }
        }

        return null;
    }

    /**
     * Kata kuncinya muncul SETELAH klausa pertama — perlu dibaca orang
     * sebelum barisnya dipindah.
     *
     * Tim menulis barangnya lebih dulu: "Karpet bau apek", "Handle koper
     * tidak dibersihkan". Kalau kata kuncinya baru muncul setelah koma atau
     * titik, keluhannya biasanya tentang hal lain dan barang itu cuma
     * disebut sambil lalu — persis kasus "Miss komunikasi antara kasir dan
     * customer, kasir menginfokan penyelesaian bedcover dan karpet…", yang
     * isinya salah paham, bukan karpetnya.
     *
     * Ini penanda untuk dibaca, BUKAN penyaring: baris bertanda tetap ikut
     * dipindah kalau perintahnya dijalankan dengan `--tulis`. Yang memutuskan
     * orang, setelah membacanya.
     *
     * `substr` biasa, bukan `mb_substr`: `$posisi` dari preg_match adalah
     * offset byte, dan yang dicari cuma tanda baca ASCII.
     */
    public static function ambigu(string $uraian, int $posisi): bool
    {
        return preg_match('/[,.;]/', substr($uraian, 0, $posisi)) === 1;
    }
}
