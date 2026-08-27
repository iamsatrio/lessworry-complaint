<?php

namespace App\Exceptions;

/**
 * Batas laju per pengguna, dihitung untuk SEMUA jalur yang menyentuh NEVIRA
 * — bukan per rute. Batas yang dipasang per rute hanya memindahkan celahnya
 * ke pintu sebelah.
 */
class NeviraRateLimited extends NeviraException
{
    public function __construct(public readonly int $availableIn)
    {
        parent::__construct('Batas laju NEVIRA terlampaui, coba lagi dalam '.$availableIn.' detik.');
    }

    public function userMessage(): string
    {
        return 'Terlalu banyak pemeriksaan nota dalam semenit. Tunggu '.$this->availableIn.' detik lalu coba lagi.';
    }
}
