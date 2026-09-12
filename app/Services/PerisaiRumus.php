<?php

namespace App\Services;

/**
 * Aturan tunggal: nilai teks mana yang bisa dibaca program lembar sebar
 * sebagai RUMUS, bukan sebagai tulisan. (API-77)
 *
 * Aturannya ada satu di sini karena cara menutupnya berbeda di tiap format,
 * dan yang berbeda diam-diam adalah yang lebih jarang dibuka:
 *
 * - `.xlsx` menutupnya lewat TIPE sel — kolom teks selalu `StringCell`, apa
 *   pun huruf pertamanya, jadi tidak ada yang perlu ditandai (EksporXlsx);
 * - CSV tidak punya tipe sel. Satu-satunya keterangan yang bisa dititipkan
 *   ke dalam berkasnya adalah penanda di depan nilainya sendiri — itu
 *   `untukCsv()` di bawah.
 *
 * Yang tidak boleh punya dua jawaban adalah DAFTAR awalannya, dan daftar itu
 * hanya ditulis sekali: `AWALAN_RUMUS`. Uji kedua pengekspor membacanya dari
 * sini, jadi awalan yang ditambahkan nanti langsung diuji di kedua jalur.
 */
final class PerisaiRumus
{
    /**
     * Huruf pertama yang memicu penafsiran rumus.
     *
     * `=` rumus biasa; `+` dan `-` rumus gaya Lotus yang masih diterima Excel;
     * `@` pemanggilan fungsi gaya lama. Tab dan carriage return ikut karena
     * keduanya bisa mendahului salah satu di atas dan tetap lolos dibaca —
     * "\t=1+1" tetap jadi rumus.
     *
     * @var list<string>
     */
    public const AWALAN_RUMUS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Petik satu: penanda "sel ini teks" yang memang disediakan format lembar
     * sebar untuk keperluan ini. Excel dan Google Sheets memakannya sebagai
     * penanda dan menampilkan sisanya apa adanya — itu sebabnya penanda ini
     * dipilih, bukan tab atau spasi yang ikut terbaca sebagai isi sel.
     */
    private const PENANDA_TEKS = "'";

    /** Apakah nilai ini akan dibaca sebagai rumus kalau ditulis telanjang. */
    public static function berbahaya(mixed $nilai): bool
    {
        return is_string($nilai)
            && $nilai !== ''
            && in_array($nilai[0], self::AWALAN_RUMUS, true);
    }

    /**
     * Nilai teks yang aman ditulis ke sel CSV.
     *
     * Tulisannya tidak diubah — tidak ada huruf yang dibuang atau diganti.
     * Pelapor bernama `-Andi` tetap `-Andi`; yang ditambahkan hanya penanda
     * teks di depannya, dan penanda itu bukan bagian dari nilainya.
     *
     * HANYA untuk kolom teks. Kolom angka tidak boleh lewat sini: `-5000`
     * yang ditandai teks berhenti jadi bilangan, dan kolom yang berhenti jadi
     * bilangan tidak bisa dijumlah lagi.
     */
    public static function untukCsv(mixed $nilai): mixed
    {
        return self::berbahaya($nilai) ? self::PENANDA_TEKS.$nilai : $nilai;
    }
}
