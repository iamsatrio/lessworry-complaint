<?php

namespace App\Http\Requests\Concerns;

use App\Models\Outlet;
use App\Models\User;

/**
 * Saringan `?outlet=` yang menyangkut wewenang, dipakai halaman Laporan
 * (API-62 nomor 3) dan Dashboard Operations (API-72).
 *
 * Berdiri sebagai satu berkas karena aturannya bukan validasi biasa: dua
 * halaman yang menuliskannya sendiri-sendiri berarti dua tempat yang bisa
 * berbeda diam-diam, dan yang berbeda diam-diam adalah yang lebih jarang
 * dibuka. Halaman Laporan sudah membuktikan pola itu sekali — ekspornya dulu
 * menyaring dengan aturannya sendiri.
 */
trait MenyaringOutlet
{
    /**
     * Wewenang atas outlet yang diminta — dijawab SEBELUM controller berjalan,
     * bukan dengan menyembunyikan pilihannya di halaman.
     *
     * Outlet yang tidak ada dan outlet yang tidak boleh dilihat dijawab SAMA:
     * ditolak. Membedakan keduanya membuat kasir bisa menghitung ada berapa
     * outlet di jaringan dengan mencoba id satu per satu — dan jumlah outlet
     * itu sendiri informasi yang tidak boleh disimpulkan dari halaman ini.
     *
     * Ditolak, bukan diam-diam dikosongkan: permintaan yang dibiarkan lewat
     * lalu dikembalikan "semua outlet" memperlihatkan LEBIH BANYAK daripada
     * yang diminta, dan tidak ada satu pun tanda bahwa saringannya diabaikan.
     */
    protected function outletDalamWewenang(): bool
    {
        $id = $this->input('outlet');

        // Tidak menyaring outlet sama sekali — cakupan bawaannya sudah dijaga
        // Complaint::scopeVisibleTo.
        if ($id === null || $id === '') {
            return true;
        }

        // Bukan angka: itu bentuk yang salah, bukan wewenang yang kurang.
        // Dibiarkan lewat supaya rules() yang menjawabnya sebagai 422.
        if (! is_numeric($id)) {
            return true;
        }

        $outlet = Outlet::find((int) $id);

        /** @var User|null $user */
        $user = $this->user();

        return $outlet !== null && $user !== null && $user->can('view', $outlet);
    }

    /** Outlet yang diminta, atau null kalau saringannya "semua outlet". */
    public function outletDiminta(): ?Outlet
    {
        $id = $this->input('outlet');

        if ($id === null || $id === '' || ! is_numeric($id)) {
            return null;
        }

        return Outlet::find((int) $id);
    }
}
