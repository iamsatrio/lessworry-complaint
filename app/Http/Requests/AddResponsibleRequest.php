<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Aturan penetapan pelaku complaint. (API-19) */
class AddResponsibleRequest extends FormRequest
{
    /** Lihat catatan di StoreComplaintRequest::authorize(). */
    public function authorize(): bool
    {
        return $this->user()->can('manageResponsible', $this->route('complaint'));
    }

    public function rules(): array
    {
        $peranSah = array_keys(config('complaint.responsible_roles'));

        return [
            'pelaku' => ['required_without:manual_nama', 'array'],
            'pelaku.*' => ['string', 'max:190'],
            'peran' => ['array'],
            'peran.*' => [Rule::in($peranSah)],
            'manual_nama' => ['nullable', 'string', 'max:120'],
            'manual_nip' => ['nullable', 'string', 'max:40'],
            'manual_peran' => ['nullable', Rule::in($peranSah)],
            // Alasan wajib, tanpa pengecualian. Menunjuk orang tanpa alasan
            // tidak bisa ditinjau ulang dan menempel di catatan kerjanya.
            'alasan' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'pelaku.required_without' => 'Pilih siapa yang terlibat, atau tulis namanya di isian bebas.',
            'alasan.required' => 'Tulis alasannya. Menunjuk orang tanpa alasan tidak bisa ditinjau ulang.',
        ];
    }

    public function attributes(): array
    {
        return [
            'pelaku' => 'pelaku',
            'alasan' => 'alasan',
            'manual_nama' => 'nama karyawan',
        ];
    }
}
