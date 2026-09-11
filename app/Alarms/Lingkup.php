<?php

namespace App\Alarms;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cakupan sebuah pembacaan alarm: siapa yang membaca, dan outlet mana yang
 * sedang dipilihnya.
 *
 * Ini SATU-SATUNYA jalan sebuah alarm mengambil complaint, dan itu bukan soal
 * kerapian. Kalau tiap alarm menulis kuerinya sendiri, kebocoran cakupan di
 * salah satunya tidak akan terlihat — halaman complaint tetap benar, papan
 * kerja tetap benar, dan yang bocor cuma angka di dashboard yang tidak ada
 * pembandingnya. Itu jenis celah yang paling lama tidak ketahuan. (API-72)
 */
final class Lingkup
{
    public function __construct(
        public readonly User $user,
        public readonly ?Outlet $outlet = null,
    ) {}

    /**
     * Complaint yang boleh dilihat pembaca ini.
     *
     * @return Builder<Complaint>
     */
    public function complaints(): Builder
    {
        $query = Complaint::query()->visibleTo($this->user);

        // Saringan outlet DITUMPUK di atas cakupan peran, tidak menggantikannya.
        // Wewenang atas outlet ini sudah ditolak AlarmFilterRequest sebelum
        // controller berjalan; urutan di sini yang membuat kesalahan di sana
        // tidak berubah jadi kebocoran.
        return $this->outlet === null
            ? $query
            : $query->where('outlet_id', $this->outlet->id);
    }
}
