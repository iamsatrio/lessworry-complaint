<?php

namespace App\Http\Requests;

use App\Rules\GambarSungguhan;
use App\Services\PenyimpanFoto;
use Illuminate\Foundation\Http\FormRequest;

/** Aturan catatan penanganan beserta foto buktinya. (API-20) */
class AddComplaintNoteRequest extends FormRequest
{
    /** Lihat catatan di StoreComplaintRequest::authorize(). */
    public function authorize(): bool
    {
        return $this->user()->can('addNote', $this->route('complaint'));
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:2000'],
            'photos' => ['array', 'max:'.PenyimpanFoto::PER_CATATAN],
            // Isi berkas yang menentukan, bukan ekstensinya: aturan image
            // membaca berkasnya, dan PenyimpanFoto memeriksanya sekali lagi.
            // HEIC sengaja tidak diterima — gd tidak bisa membacanya, jadi
            // ia akan tersimpan apa adanya berikut EXIF-nya.
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.PenyimpanFoto::MAKS_KB, new GambarSungguhan],
        ];
    }

    public function messages(): array
    {
        return [
            'photos.max' => 'Maksimal '.PenyimpanFoto::PER_CATATAN.' foto per catatan.',
            'photos.*.image' => 'Berkas :position bukan gambar. Unggah foto (JPG, PNG, atau WebP).',
            'photos.*.mimes' => 'Berkas :position bukan gambar. Unggah foto (JPG, PNG, atau WebP).',
            'photos.*.max' => 'Foto :position lebih dari '.PenyimpanFoto::maksMb().' MB.',
        ];
    }

    public function attributes(): array
    {
        return ['photos' => 'foto'];
    }
}
