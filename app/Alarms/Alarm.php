<?php

namespace App\Alarms;

/**
 * Satu jenis alarm di Dashboard Operations. (API-72)
 *
 * Kerangkanya sengaja setipis ini: alarm hanya menjawab "keadaannya terpenuhi
 * atau tidak, dan apa isinya". Yang TIDAK diketahuinya — siapa yang sudah
 * memegangnya, kapan penandaan itu kedaluwarsa, bagaimana ia dirender —
 * dipegang PapanAlarm dan tampilannya, satu kali untuk semua alarm.
 *
 * Jenis alarm berikutnya (tagihan bulanan, saldo koin NEVIRA, nota terlambat)
 * mendaftar dengan menambah satu baris di `config/complaint.php` →
 * `alarms.terdaftar`, bukan dengan menulis ulang papannya.
 */
interface Alarm
{
    /**
     * Kunci tetap alarm ini. Penandaan "sudah saya tangani" disimpan atas
     * nama kunci ini, jadi MENGUBAHNYA SETELAH DIPAKAI membuat penandaan yang
     * sudah ada menunjuk alarm yang tidak ada lagi — alarmnya menyala kembali
     * seolah tidak pernah dipegang siapa pun.
     */
    public function kunci(): string;

    public function judul(): string;

    /**
     * Satu baris: apa yang harus DILAKUKAN pembacanya.
     *
     * Bukan pengulangan keadaan. Pembaca dashboard ini pagi-pagi butuh tahu
     * harus menelepon siapa atau membuka halaman apa — bukan penjelasan
     * kembali tentang angka yang sudah dilihatnya di atas.
     */
    public function tindakan(): string;

    /** Keadaan sekarang. Null berarti PADAM — alarm padam tidak ditampilkan. */
    public function periksa(Lingkup $lingkup): ?Nyala;
}
