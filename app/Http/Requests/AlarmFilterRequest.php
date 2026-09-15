<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\MenyaringOutlet;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saringan Dashboard Operations: satu outlet, atau seluruh cakupan pembacanya.
 * (API-72)
 *
 * Wewenang membuka halamannya sendiri tidak diperiksa di sini — itu pekerjaan
 * middleware `can:dashboard.view` di rutenya, yang sudah berjalan sebelum
 * request ini dibentuk. Yang dijawab di sini hanya outlet mana yang boleh
 * diminta oleh orang yang sudah lolos gerbang itu.
 */
class AlarmFilterRequest extends FormRequest
{
    use MenyaringOutlet;

    public function authorize(): bool
    {
        return $this->outletDalamWewenang();
    }

    public function rules(): array
    {
        return [
            // Keberadaannya TIDAK diperiksa dengan `exists`: itu membalas 422
            // untuk id yang tidak ada dan 403 untuk id outlet orang lain, dan
            // selisih dua kode itu sudah cukup untuk memetakan jaringan.
            'outlet' => ['nullable', 'integer'],
        ];
    }
}
