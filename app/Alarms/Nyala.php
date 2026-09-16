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
     * Tiga kolom yang dirender kartu alarm, dan tiga kunci baris yang
     * mengisinya: `tiket` · `outlet` · `umur`.
     *
     * Namanya datang dari dua alarm complaint yang pertama, tapi artinya
     * umum: `tiket` label baris, `umur` kolom waktunya. Alarm yang barisnya
     * bukan complaint — tagihan bulanan, saldo koin — mengganti JUDUL
     * kolomnya lewat $kolom, bukan bentuk barisnya. Satu bentuk baris berarti
     * satu kartu yang merender semuanya. (API-73)
     */
    public const KOLOM_COMPLAINT = ['Tiket', 'Outlet', 'Lama'];

    /**
     * @param  int  $jumlah  Seluruh yang memenuhi keadaan, bukan sebanyak baris di $daftar.
     * @param  string  $ringkasan  Satu kalimat: berapa, dan yang terlama berapa lama.
     * @param  list<array{id:int,tiket:string,outlet:string,umur:string,tautan:string}>  $daftar  Terburuk lebih dulu, dipotong beberapa baris.
     * @param  list<array{outlet:string,jumlah:int}>  $perOutlet  Terbanyak lebih dulu.
     * @param  array{0:string,1:string,2:string}  $kolom  Judul ketiga kolom baris.
     * @param  string|null  $tautanSemua  Ke mana "dan N lagi" mengantar. Null berarti jumlahnya disebut tanpa tautan.
     */
    public function __construct(
        public readonly int $jumlah,
        public readonly string $ringkasan,
        public readonly array $daftar = [],
        public readonly array $perOutlet = [],
        public readonly array $kolom = self::KOLOM_COMPLAINT,
        public readonly ?string $tautanSemua = null,
    ) {}

    /** Berapa baris yang tidak ikut ditampilkan di $daftar. */
    public function sisa(): int
    {
        return max(0, $this->jumlah - count($this->daftar));
    }
}
