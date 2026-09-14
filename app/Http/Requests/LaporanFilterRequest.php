<?php

namespace App\Http\Requests;

use App\Models\Outlet;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saringan halaman Laporan: rentang tanggal dan outlet. (API-62 nomor 3)
 *
 * Dipakai halaman DAN ekspornya, supaya keduanya tidak bisa lagi menyaring
 * dengan aturan yang berbeda-beda.
 */
class LaporanFilterRequest extends FormRequest
{
    /**
     * Saringan outlet ditegakkan DI SINI, sebelum controller berjalan —
     * bukan dengan menyembunyikan pilihannya di halaman.
     *
     * Outlet yang tidak ada dan outlet yang tidak boleh dilihat dijawab
     * SAMA: ditolak. Membedakan keduanya membuat kasir bisa menghitung ada
     * berapa outlet di jaringan dengan mencoba id satu per satu — dan jumlah
     * outlet itu sendiri informasi yang tidak boleh disimpulkan dari halaman
     * ini (lihat GrafikLaporan).
     *
     * Ditolak, bukan diam-diam dikosongkan: permintaan yang dibiarkan lewat
     * lalu dikembalikan "semua outlet" memperlihatkan lebih banyak daripada
     * yang diminta, dan tidak ada satu pun tanda bahwa saringannya diabaikan.
     */
    public function authorize(): bool
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

        return $outlet !== null && $this->user()->can('view', $outlet);
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            // Keberadaannya TIDAK diperiksa di sini: `exists` akan membalas
            // 422 untuk id yang tidak ada dan 403 untuk id outlet orang lain,
            // dan selisih dua kode itu sudah cukup untuk memetakan jaringan.
            // authorize() di atas menolak keduanya dengan jawaban yang sama.
            'outlet' => ['nullable', 'integer'],
            // `satuan` sengaja TIDAK divalidasi di sini. Nilai yang tidak
            // dikenali diperlakukan sebagai "tidak memilih" oleh
            // SaringanLaporan, bukan sebagai galat: ini saringan tampilan,
            // bukan data yang disimpan, dan menolak seluruh halaman karena
            // satu potongan URL yang salah ketik tidak menolong siapa pun.
        ];
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
