<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Isi sebuah rekap yang bisa diunduh: kolomnya, tipenya, barisnya, dan nama
 * berkasnya. (API-43)
 *
 * Penulisnya — EksporCsv dan EksporXlsx — tidak perlu tahu rekap apa yang
 * sedang ditulisnya. Itu yang membuat rekap kedua (biaya per complaint di
 * halaman Kerugian) tidak menuntut penulis CSV kedua, dan tidak ada dua
 * penulis yang bisa berbeda diam-diam dalam hal yang sama.
 *
 * Nilai baris ditulis APA ADANYA: tanggal tetap Carbon, angka tetap int.
 * Yang mengubahnya jadi teks adalah penulis formatnya — itu yang membuat
 * `.xlsx` menyimpan tanggal sebagai tanggal sementara CSV tetap menulis
 * bentuk yang sama seperti sebelumnya.
 */
interface IsiRekap
{
    /** Kolom yang isinya teks apa adanya — termasuk yang KELIHATAN angka. */
    public const TEKS = 'teks';

    /** Bilangan bulat: boleh dijumlah dan dirata-rata di lembar sebarnya. */
    public const ANGKA = 'angka';

    /** Bilangan bulat rupiah; di `.xlsx` diberi format mata uang. */
    public const RUPIAH = 'rupiah';

    /** Tanggal dan jam sungguhan, bukan teks yang berbentuk tanggal. */
    public const TANGGAL = 'tanggal';

    /** @return list<array{judul:string,tipe:string}> */
    public function kolom(): array;

    /** @return list<string> */
    public function judulKolom(): array;

    /** @return list<string> */
    public function tipeKolom(): array;

    /** @return iterable<list<Carbon|int|string|null>> */
    public function baris(): iterable;

    /** Nama berkasnya, tanpa ekstensi. */
    public function namaBerkas(): string;
}
