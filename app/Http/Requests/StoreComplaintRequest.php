<?php

namespace App\Http\Requests;

use App\Models\Complaint;
use App\Rules\GambarSungguhan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Aturan form intake complaint. (API-7) */
class StoreComplaintRequest extends FormRequest
{
    /**
     * Wewenang diperiksa DI SINI, bukan di controller.
     *
     * FormRequest menjalankan authorize() sebelum rules(), persis seperti
     * abort_unless dulu berdiri sebelum $request->validate(). Kalau
     * pemeriksaannya dipindah ke badan controller, peran yang tidak berhak
     * akan menerima 422 berisi pesan validasi lebih dulu — bocor kecil, dan
     * perubahan perilaku yang tidak diminta.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Complaint::class);
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(array_keys(config('complaint.channels')))],
            'reporter_name' => ['required', 'string', 'max:120'],
            'reporter_phone' => ['nullable', 'string', 'max:30'],
            'nevira_transaction_number' => ['required_without:nota_exemption', 'nullable', 'string', 'max:64'],
            'nota_exemption' => ['required_without:nevira_transaction_number', 'nullable', Rule::in(array_keys(config('complaint.nota_exemptions')))],
            'outlet_id' => ['nullable', 'exists:outlets,id'],
            'category' => ['required', Rule::in(array_keys(config('complaint.categories')))],
            'sub_category' => ['nullable', 'string', 'max:120'],
            'priority' => ['required', Rule::in(array_keys(config('complaint.priorities')))],
            // Batas panjang: tanpa ini 2 juta karakter tersimpan utuh dan
            // ikut termuat di papan kerja maupun halaman detail. (API-8 T8)
            'description' => ['required', 'string', 'max:5000'],
            'attachments.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', new GambarSungguhan],
        ];
    }

    public function messages(): array
    {
        return [
            'nevira_transaction_number.required_without' => 'Isi nomor nota NEVIRA, atau pilih alasan kenapa complaint ini tidak punya nota.',
            'nota_exemption.required_without' => 'Pilih alasan kenapa complaint ini tidak punya nomor nota.',
        ];
    }

    public function attributes(): array
    {
        return [
            'nevira_transaction_number' => 'nomor nota',
            'nota_exemption' => 'alasan tanpa nota',
        ];
    }
}
