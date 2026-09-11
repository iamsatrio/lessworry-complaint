<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\MenyaringOutlet;
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
     * Aturan saringan outlet — termasuk alasan ia ditolak dan tidak
     * dikosongkan — ada di trait-nya, dipakai bersama Dashboard Operations
     * (API-72). Yang di sini tinggal rentang tanggalnya.
     */
    use MenyaringOutlet;

    public function authorize(): bool
    {
        return $this->outletDalamWewenang();
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            // Keberadaannya TIDAK diperiksa di sini: `exists` akan membalas
            // 422 untuk id yang tidak ada dan 403 untuk id outlet orang lain,
            // dan selisih dua kode itu sudah cukup untuk memetakan jaringan.
            // outletDalamWewenang() menolak keduanya dengan jawaban yang sama.
            'outlet' => ['nullable', 'integer'],
        ];
    }
}
