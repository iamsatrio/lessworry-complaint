<?php

namespace App\Alarms;

use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\Tagihan;
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

    /**
     * Tagihan AKTIF yang boleh dilihat pembaca ini. (API-73)
     *
     * Alasannya sama dengan complaints(): satu jalur, supaya kebocoran cakupan
     * tidak bisa bersembunyi di alarm yang menulis kuerinya sendiri.
     *
     * Tagihan tingkat jaringan IKUT TERBAWA meski saringan outlet sedang
     * menyala. Sewa kantor pusat dan langganan perangkat lunak berlaku untuk
     * semua outlet; membuangnya saat seseorang menyaring satu outlet membuat
     * alarmnya padam untuk tagihan yang jatuh tempo hari itu juga — dan alarm
     * yang padam terbaca sebagai "tidak ada yang perlu dibayar", bukan sebagai
     * "sedang disaring".
     *
     * Yang nonaktif tidak pernah ikut: menonaktifkan tagihan memang berarti
     * memadamkan alarmnya, dan riwayat pembayarannya tetap terbaca di halaman
     * Tagihan.
     *
     * @return Builder<Tagihan>
     */
    public function tagihan(): Builder
    {
        $query = Tagihan::query()->visibleTo($this->user)->where('is_active', true);

        if ($this->outlet === null) {
            return $query;
        }

        $outletId = $this->outlet->id;

        return $query->where(function (Builder $q) use ($outletId): void {
            $q->whereNull('outlet_id')->orWhere('outlet_id', $outletId);
        });
    }
}
