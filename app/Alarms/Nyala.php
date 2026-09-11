<?php

namespace App\Alarms;

/**
 * Isi sebuah alarm yang MENYALA.
 *
 * Alarm padam tidak diwakili kelas ini — `Alarm::periksa()` mengembalikan
 * null. Papan hanya menampilkan yang menyala, dan "padam" tidak punya isi
 * untuk dibawa.
 */
final class Nyala
{
    /**
     * @param  int  $jumlah  Seluruh yang memenuhi keadaan, bukan sebanyak baris di $daftar.
     * @param  string  $ringkasan  Satu kalimat: berapa, dan yang terlama berapa lama.
     * @param  list<array{id:int,tiket:string,outlet:string,umur:string}>  $daftar  Terburuk lebih dulu, dipotong beberapa baris.
     * @param  list<array{outlet:string,jumlah:int}>  $perOutlet  Terbanyak lebih dulu.
     */
    public function __construct(
        public readonly int $jumlah,
        public readonly string $ringkasan,
        public readonly array $daftar = [],
        public readonly array $perOutlet = [],
    ) {}

    /** Berapa baris yang tidak ikut ditampilkan di $daftar. */
    public function sisa(): int
    {
        return max(0, $this->jumlah - count($this->daftar));
    }
}
