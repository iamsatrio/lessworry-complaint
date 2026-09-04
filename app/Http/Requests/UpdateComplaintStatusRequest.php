<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Aturan perubahan status complaint. */
class UpdateComplaintStatusRequest extends FormRequest
{
    /** Lihat catatan di StoreComplaintRequest::authorize(). */
    public function authorize(): bool
    {
        return $this->user()->can('updateStatus', $this->route('complaint'));
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_keys(config('complaint.statuses')))],
            // Tiket Close harus menyebut alasannya. Tanpa ini laporan tidak
            // bisa lagi memisahkan yang selesai dari yang ditolak — kemampuan
            // yang dulu dipegang status "Ditolak". (API-18 #6)
            'close_reason' => [
                Rule::requiredIf(fn () => $this->input('status') === 'close'),
                'nullable',
                Rule::in(array_keys(config('complaint.close_reasons'))),
            ],
            'pause_reason' => ['nullable', Rule::in(array_keys(config('complaint.pause_reasons')))],
            'tindak_lanjut' => ['nullable', Rule::in(array_keys(config('complaint.tindak_lanjut')))],
            // Versi yang dilihat petugas saat halaman dibuka. Tanpa ini,
            // penyimpanan dari halaman basi menimpa keputusan orang lain
            // tanpa peringatan ke siapa pun. (API-8 T6)
            'lock_version' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:2000'],
            'resolution' => ['nullable', 'string', 'max:5000'],
            'root_cause' => ['nullable', 'string', 'max:2000'],
            'compensation_amount' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'lock_version.required' => 'Muat ulang halaman complaint ini sebelum menyimpan.',
            'close_reason.required' => 'Sebutkan complaint ini ditutup karena selesai atau ditolak.',
        ];
    }

    public function attributes(): array
    {
        return [
            'lock_version' => 'penanda versi',
            'close_reason' => 'alasan penutupan',
        ];
    }
}
