<?php

namespace App\Alarms;

use App\Models\Complaint;
use App\Models\Outlet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Bentuk isi alarm yang berdiri di atas tabel complaint: beberapa baris
 * terburuk, dan sebarannya per outlet.
 *
 * Ditulis sekali di sini, bukan di tiap alarm. Dua alarm complaint pertama
 * sudah menuntut potongan yang sama persis, dan yang ketiga (nota terlambat)
 * akan menuntutnya lagi — tiga tempat yang mengelompokkan per outlet dengan
 * caranya masing-masing berarti tiga tempat yang bisa salah sendiri-sendiri.
 */
final class RingkasanComplaint
{
    /**
     * Berapa baris tiket yang ikut ditampilkan di kartu alarm.
     *
     * Lima, bukan seluruhnya: kartu yang memuat 40 baris menuntut digulir, dan
     * ukuran yang menentukan rancangan halaman ini adalah "hari normal selesai
     * dalam 30 detik" (API-71 alur 6). Jumlah seluruhnya tetap disebut di
     * angka besar, jadi yang dipotong tidak hilang diam-diam.
     */
    public const BARIS = 5;

    /**
     * @param  Builder<Complaint>  $query
     * @param  string  $urutkan  Kolom yang menentukan "terburuk lebih dulu" — naik.
     * @param  callable(Complaint):string  $umur
     * @return list<array{id:int,tiket:string,outlet:string,umur:string}>
     */
    public static function daftar(Builder $query, string $urutkan, callable $umur): array
    {
        $rows = $query->clone()->with('outlet')->orderBy($urutkan)->take(self::BARIS)->get();

        return array_values($rows->map(fn (Complaint $complaint): array => [
            'id' => $complaint->id,
            'tiket' => $complaint->ticket_number,
            // Complaint tanpa outlet memang ada — impor data lama menyimpan
            // baris yang nama outletnya tidak punya padanan. (API-28)
            'outlet' => $complaint->outlet->name ?? 'Tanpa outlet',
            'umur' => $umur($complaint),
        ])->all());
    }

    /**
     * Sebaran per outlet, terbanyak lebih dulu.
     *
     * Dihitung dari id outlet yang ikut dalam kueri yang SAMA dengan angka
     * besarnya, bukan dari kueri sendiri: satu jalur data berarti satu jalur
     * wewenang. Nama outlet diambil hanya untuk id yang benar-benar muncul —
     * daftar outlet yang lengkap membocorkan jumlah outlet jaringan tanpa satu
     * baris data pun ikut keluar. (Alasan yang sama ada di GrafikLaporan.)
     *
     * @param  Builder<Complaint>  $query
     * @return list<array{outlet:string,jumlah:int}>
     */
    public static function perOutlet(Builder $query): array
    {
        /** @var Collection<int,mixed> $mentah */
        $mentah = $query->clone()->toBase()->pluck('outlet_id');

        // 0 mewakili "tanpa outlet": kunci null tidak bisa dipakai countBy.
        $jumlah = $mentah
            ->map(fn ($id): int => $id === null ? 0 : (int) $id)
            ->countBy()
            ->sortDesc();

        $nama = Outlet::query()
            ->whereIn('id', $jumlah->keys()->filter()->all())
            ->pluck('name', 'id');

        $hasil = [];

        foreach ($jumlah as $outletId => $total) {
            $hasil[] = [
                'outlet' => $outletId === 0
                    ? 'Tanpa outlet'
                    : (string) ($nama[$outletId] ?? 'Outlet '.$outletId),
                'jumlah' => (int) $total,
            ];
        }

        return $hasil;
    }
}
