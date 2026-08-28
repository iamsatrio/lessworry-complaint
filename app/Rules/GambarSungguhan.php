<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Berkasnya harus benar-benar gambar. (API-20)
 *
 * Ekstensi dan Content-Type keduanya datang dari klien dan bisa berbohong:
 * berkas PHP bernama bukti.jpg dengan Content-Type image/jpeg lolos
 * pemeriksaan yang hanya membaca keduanya. Yang diperiksa di sini isinya —
 * berkas dibaca dan dicoba dikenali sebagai gambar.
 */
class GambarSungguhan implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('Berkas :attribute gagal diunggah. Coba lagi.');

            return;
        }

        $isi = @file_get_contents($value->getRealPath());

        if ($isi === false || @getimagesizefromstring($isi) === false) {
            $fail('Berkas :attribute bukan gambar. Unggah foto (JPG, PNG, atau WebP).');
        }
    }
}
