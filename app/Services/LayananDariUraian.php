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
 * 2. **Uraian yang menyebut `tas laundry`/`tas cuci` menahan SELURUH
 *    BARISNYA.** Tas laundry itu WADAH tempat cucian datang dan pulang —
 *    tasnya memang milik pelanggan, tapi bukan tasnya yang dicuci. Yang
 *    dibeli pelanggan Kiloan: pakaiannya, per kilo. Bandingkan dengan 47
 *    baris yang memang pindah ke `sepatu_tas` — "Tas belum bersih", "tas
 *    carrer kurang bersih dibagian dalam" — di situ tasnya YANG DIKERJAKAN.
 *    Bedanya bukan siapa pemiliknya; bedanya apakah barang itu objek jasanya.
 *    Menahan per-BARIS, bukan per-kemunculan kata: lihat `POLA_WADAH` untuk
 *    kalimat yang membuktikan kenapa lookahead tidak cukup.
 * 3. **Semua pencocokan memakai batas kata.** `tas` tanpa `\b` mencocokkan
 *    "batas", "pantas", dan "tastes" — dan itu merusak diam-diam.
 * 4. **Yang ditahan tidak dipindah, dan alasannya ikut.** Dua alasan sejauh
 *    ini — `TAHAN_AMBIGU` dan `TAHAN_WADAH` — dan keduanya menahan barisnya
 *    di `satuan_non_cloth`, bukan sekadar memberinya tanda. Aturannya tinggal
 *    DI SINI, bukan di perintah pembetulan saja, supaya jalur impor ikut
 *    mematuhinya tanpa menyalin apa pun. (Keputusan Modrić 10–11 Sep 2026)
 */
final class LayananDariUraian
{
    /**
     * Layanan yang boleh dipertajam. Baris ber-layanan apa pun selain ini
     * tidak pernah disentuh, apa pun isi uraiannya.
     */
    public const ASAL = 'satuan_non_cloth';

    /**
     * Kata kunci yang cocok LEBIH DULU, berurutan. Urutannya bagian dari
     * aturannya, bukan selera:
     *
     * 1. **karpet/gorden** — nilainya per kejadian jauh terbesar, jadi salah
     *    pindah ke arah itu lebih mudah terlihat di laporan daripada
     *    tenggelam di antara 47 baris sepatu.
     * 2. **sepatu/koper/ransel/…** — kata yang tidak pernah dua arti. Barang
     *    yang disebut eksplisit menang atas wadahnya: "Sepatu kotor, tas
     *    laundry juga hilang" tetap `sepatu_tas`, karena sepatunya memang
     *    yang dicuci dan kantongnya cuma ikut disebut.
     * 3. **`tas` telanjang** — paling akhir, dan hanya kalau barisnya tidak
     *    menyebut wadah sama sekali. Lihat `POLA_WADAH`.
     *
     * `\w*` pada karpet menampung "karpetnya"/"gordennya". Daftar sepatu
     * tidak memakainya: "sandal" berimbuhan tidak muncul di data, sementara
     * `\w*` di sana akan menarik kata lain yang berawalan sama.
     */
    private const POLA_KARPET = '/\b(?:karpet|gorden|gordyn|vitrase|tirai)\w*/iu';

    private const POLA_SEPATU = '/\b(?:sepatu|sneakers?|sandal|sendal|heels|koper|ransel)\b/iu';

    private const POLA_TAS = '/\btas\b/iu';

    /**
     * Uraian yang menyebut WADAH cucian. Menahan SELURUH BARISNYA, bukan satu
     * kemunculan kata.
     *
     * Bentuk sebelumnya lookahead per-kemunculan —
     * `\btas\b(?!\s+(?:laundry|cuci)\w*)` — dan itu bocor pada kalimat yang
     * menyebut `tas` dua kali:
     *
     *   "Tas laundry gak dikembalikan, tas nya hilang"  -> sepatu_tas
     *   "Tas laundry dan tas customer tertukar"          -> sepatu_tas
     *
     * `tas` yang kedua adalah kemunculan `\btas\b` yang sah, bukan sisa
     * lookahead — jadi menulis ulang pengecualiannya tidak menutup apa pun,
     * termasuk bentuk "buang dulu frasanya, cocokkan sisanya" yang tercatat di
     * API-76 nomor 1. Yang harus ditahan bukan kemunculannya, melainkan
     * BARISNYA: kalau sebuah keluhan menyebut tas laundry, keluhan itu tentang
     * wadah, dan jasa yang dibeli tetap Kiloan. (Diukur Modrić 11 Sep 2026;
     * di 545 baris nyata nol baris berubah karenanya.)
     *
     * `\w*` di ujung menampung "tas laundrynya", "Tas cucian", "tas cucinya" —
     * cara paling wajar menulisnya. Melebarkan penahan hanya bisa membuat
     * lebih sedikit baris berpindah, tidak pernah lebih banyak.
     */
    private const POLA_WADAH = '/\btas\s+(?:laundry|cuci)\w*/iu';

    /** Alasan penahanan: kata kuncinya di luar klausa pertama. */
    public const TAHAN_AMBIGU = 'ambigu';

    /** Alasan penahanan: uraiannya tentang wadah cucian, bukan barang yang dicuci. */
    public const TAHAN_WADAH = 'wadah';

    /** Nilai layanan yang bisa dihasilkan kelas ini. @return list<string> */
    public static function tujuan(): array
    {
        return ['karpet_gorden', 'sepatu_tas'];
    }

    /** Kalimat yang menerangkan sebuah kode penahanan kepada orang. */
    public static function alasanTahan(string $kode): string
    {
        return match ($kode) {
            self::TAHAN_AMBIGU => 'kata kuncinya di luar klausa pertama',
            self::TAHAN_WADAH => 'uraiannya tentang tas laundry — wadah, bukan barang yang dicuci',
            default => $kode,
        };
    }

    /**
     * Layanan yang BOLEH diisikan untuk uraian ini, atau null.
     *
     * Yang tidak cocok pulang null dan baris tetap `satuan_non_cloth`. Nilai
     * yang salah diam-diam lebih buruk daripada nilai lama yang terlalu kasar:
     * yang kasar masih jujur, yang salah dibaca seolah dipastikan orang.
     *
     * Baris yang DITAHAN juga pulang null, dan itu aturan yang sama — bukan
     * pengecualian terhadapnya. Ditahan berarti TIDAK PASTI atau SALAH OBJEK,
     * dan menaruh nilai begitu ke `karpet_gorden`/`sepatu_tas` berarti
     * menyuntikkan baris meragukan ke dalam bucket yang justru dibangun issue
     * ini untuk dipercaya. Yang mau tahu bahwa barisnya cocok tapi ditahan
     * memanggil `periksa()`; yang mau tahu nilai apa yang boleh disimpan
     * memanggil ini.
     *
     * INI SATU-SATUNYA tempat keputusan "boleh dipindah?" diambil. Perintah
     * pembetulan dan pemeta impor membacanya di sini, tidak memeriksa
     * `tahan` sendiri — kalau kelak ada alasan penahanan ketiga, keduanya
     * mengikutinya tanpa disunting.
     */
    public static function tebak(?string $uraian): ?string
    {
        $temu = self::periksa($uraian);

        return $temu === null || $temu['tahan'] !== null ? null : $temu['layanan'];
    }

    /**
     * Apa yang cocok dengan uraian ini, apa adanya — termasuk baris yang
     * ditahan.
     *
     * Kata yang cocok dan letaknya ikut supaya laporan kering bisa
     * menerangkan KENAPA sebuah baris terpilih, bukan cuma bahwa ia terpilih.
     * `tahan` ikut supaya baris yang ditahan masih bisa DICETAK beserta
     * alasannya: ditahan tanpa terlihat sama saja dengan hilang, dan orang
     * yang membaca laporan itulah yang berhak memutuskan barisnya.
     *
     * Perhatikan bedanya dengan `tebak()`: yang ini menjawab "apa yang
     * cocok", yang itu menjawab "apa yang boleh disimpan". Baris yang ditahan
     * menjawab kedua pertanyaan itu secara berbeda, dan justru di situlah
     * gunanya dua metode.
     *
     * @return array{layanan:string,kata:string,posisi:int,tahan:?string}|null
     */
    public static function periksa(?string $uraian): ?array
    {
        if (blank($uraian)) {
            return null;
        }

        $teks = (string) $uraian;

        // Urutannya yang menentukan hasilnya; lihat komentar pada POLA_*.
        // Wadah diperiksa SESUDAH sepatu dan karpet: barang yang disebut
        // eksplisit menang atas kantong yang cuma ikut disebut.
        foreach ([
            ['karpet_gorden', self::POLA_KARPET, false],
            ['sepatu_tas', self::POLA_SEPATU, false],
            ['sepatu_tas', self::POLA_TAS, true],
        ] as [$layanan, $pola, $periksaWadah]) {
            if (preg_match($pola, $teks, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $posisi = (int) $m[0][1];

            return [
                'layanan' => $layanan,
                'kata' => (string) $m[0][0],
                'posisi' => $posisi,
                'tahan' => match (true) {
                    $periksaWadah && self::wadah($teks) => self::TAHAN_WADAH,
                    self::ambigu($teks, $posisi) => self::TAHAN_AMBIGU,
                    default => null,
                },
            ];
        }

        return null;
    }

    /**
     * Uraian ini bicara tentang WADAH cucian, bukan barang yang dicuci.
     *
     * Berlaku atas SELURUH baris, bukan atas satu kemunculan kata — itu
     * bedanya dengan lookahead yang dipakai sebelumnya, dan itu satu-satunya
     * bentuk yang menutup kalimat bertas-dua. Lihat `POLA_WADAH`.
     */
    public static function wadah(string $uraian): bool
    {
        return preg_match(self::POLA_WADAH, $uraian) === 1;
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
