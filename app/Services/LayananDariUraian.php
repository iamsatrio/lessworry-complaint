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
 * 4. **Baris ambigu tidak dipindah.** Kata kunci yang baru muncul setelah
 *    klausa pertama menahan barisnya di `satuan_non_cloth`, bukan sekadar
 *    memberinya tanda. Lihat `ambigu()` untuk alasannya; aturannya tinggal
 *    DI SINI, bukan di perintah pembetulan saja, supaya jalur impor ikut
 *    mematuhinya tanpa menyalin apa pun. (Keputusan Modrić 10 Sep 2026)
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
     * `\w*` pada karpet menampung "karpetnya"/"gordennya". Daftar sepatu
     * tidak memakainya: "sandal" berimbuhan tidak muncul di data, sementara
     * `\w*` di sana akan menarik kata lain yang berawalan sama.
     *
     * TAPI pengecualian tas memakainya, dan itu bukan ketidakkonsistenan:
     * `\btas\b(?!\s+(?:laundry|cuci)\b)` bocor pada bentuk berakhiran. `\b`
     * sesudah `laundry`/`cuci` menuntut kata itu berhenti di situ, jadi
     * "tas laundrynya hilang", "Tas cucian belum kembali", dan "tas cucinya
     * sobek" lolos dari pengecualian dan pindah ke sepatu_tas — mengubah
     * keluhan tentang WADAH jadi keluhan tentang jasa cuci tas, persis
     * pembalikan makna yang seluruh komentar di atas ada untuk mencegahnya.
     * Akhiran `-nya` dan `-an` adalah cara paling wajar menulisnya.
     *
     * Arah kedua `\w*` berlawanan, dan itu yang membuat keduanya aman:
     * pada karpet ia MELEBARKAN yang cocok, pada tas ia MELEBARKAN yang
     * dikecualikan. Melebarkan pengecualian hanya bisa membuat lebih sedikit
     * baris berpindah, tidak pernah lebih banyak. (Tinjauan PR #22)
     *
     * @var array<string,string>
     */
    private const POLA = [
        'karpet_gorden' => '/\b(?:karpet|gorden|gordyn|vitrase|tirai)\w*/iu',
        'sepatu_tas' => '/\b(?:sepatu|sneakers?|sandal|sendal|heels|koper|ransel)\b|\btas\b(?!\s+(?:laundry|cuci)\w*)/iu',
    ];

    /** Nilai layanan yang bisa dihasilkan kelas ini. @return list<string> */
    public static function tujuan(): array
    {
        return array_keys(self::POLA);
    }

    /**
     * Layanan yang BOLEH diisikan untuk uraian ini, atau null.
     *
     * Yang tidak cocok pulang null dan baris tetap `satuan_non_cloth`. Nilai
     * yang salah diam-diam lebih buruk daripada nilai lama yang terlalu kasar:
     * yang kasar masih jujur, yang salah dibaca seolah dipastikan orang.
     *
     * Baris ambigu juga pulang null, dan itu aturan yang sama — bukan
     * pengecualian terhadapnya. `ambigu` berarti TIDAK PASTI, dan menaruh
     * nilai yang tidak pasti ke `karpet_gorden` berarti menyuntikkan satu
     * baris meragukan ke dalam bucket 12 baris yang justru dibangun issue ini
     * untuk dipercaya. Yang mau tahu bahwa barisnya cocok tapi ditahan
     * memanggil `periksa()`; yang mau tahu nilai apa yang boleh disimpan
     * memanggil ini.
     */
    public static function tebak(?string $uraian): ?string
    {
        $temu = self::periksa($uraian);

        return $temu === null || $temu['ambigu'] ? null : $temu['layanan'];
    }

    /**
     * Apa yang cocok dengan uraian ini, apa adanya — termasuk baris yang
     * ditahan `ambigu`.
     *
     * Kata yang cocok dan letaknya ikut supaya laporan kering bisa
     * menerangkan KENAPA sebuah baris terpilih, bukan cuma bahwa ia terpilih.
     * `ambigu` ikut supaya baris yang ditahan masih bisa DICETAK: ditahan
     * tanpa terlihat sama saja dengan hilang, dan orang yang membaca laporan
     * itulah yang berhak memutuskan barisnya.
     *
     * Perhatikan bedanya dengan `tebak()`: yang ini menjawab "apa yang
     * cocok", yang itu menjawab "apa yang boleh disimpan". Baris ambigu
     * menjawab kedua pertanyaan itu secara berbeda, dan justru di situlah
     * gunanya dua metode.
     *
     * @return array{layanan:string,kata:string,posisi:int,ambigu:bool}|null
     */
    public static function periksa(?string $uraian): ?array
    {
        if (blank($uraian)) {
            return null;
        }

        foreach (self::POLA as $layanan => $pola) {
            if (preg_match($pola, $uraian, $m, PREG_OFFSET_CAPTURE) === 1) {
                $posisi = (int) $m[0][1];

                return [
                    'layanan' => $layanan,
                    'kata' => (string) $m[0][0],
                    'posisi' => $posisi,
                    'ambigu' => self::ambigu((string) $uraian, $posisi),
                ];
            }
        }

        return null;
    }

    /**
     * Kata kuncinya muncul SETELAH klausa pertama — barisnya ditahan.
     *
     * Tim menulis barangnya lebih dulu: "Karpet bau apek", "Gorden sobek",
     * "Handle koper tidak dibersihkan". Kalau kata kuncinya baru muncul
     * setelah koma atau titik, keluhannya biasanya tentang hal lain dan
     * barang itu cuma disebut sambil lalu — persis kasus "Miss komunikasi
     * antara kasir dan customer, kasir menginfokan penyelesaian bedcover dan
     * karpet…", yang isinya salah paham, bukan karpetnya.
     *
     * Ini PENYARING, bukan sekadar penanda. Sebelumnya baris bertanda tetap
     * ikut dipindah dan penandanya cuma dicetak; itu membuat perintah
     * menuliskan nilai yang sudah diputuskan tidak tepat, dan membuat jalur
     * impor — yang tidak mencetak apa pun — mengisinya diam-diam. Sekarang
     * `tebak()` menahannya, jadi kedua jalur sepakat tanpa menyalin aturan.
     * (Keputusan Modrić 10 Sep 2026)
     *
     * Proksinya memang bukan alasan aslinya — yang sebenarnya terjadi pada
     * baris itu adalah satu nota berisi dua layanan, dan kolomnya bernilai
     * tunggal. Proksi yang terlalu lebar akan diam-diam menahan baris karpet
     * sungguhan. Diukur, bukan dikira-kira: atas 60 baris yang cocok di 545
     * complaint nyata, yang tertandai **tepat satu** — baris itu. Dan arah
     * gagalnya aman: yang tertahan tetap `satuan_non_cloth`, nilai lama yang
     * kasar tapi jujur, dan ia TERCETAK di laporan kering, bukan hilang.
     *
     * `substr` biasa, bukan `mb_substr`: `$posisi` dari preg_match adalah
     * offset byte, dan yang dicari cuma tanda baca ASCII.
     */
    public static function ambigu(string $uraian, int $posisi): bool
    {
        return preg_match('/[,.;]/', substr($uraian, 0, $posisi)) === 1;
    }
}
