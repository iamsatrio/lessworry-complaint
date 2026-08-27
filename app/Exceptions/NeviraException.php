<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Dasar semua kegagalan jalur NEVIRA.
 *
 * Dua pesan, sengaja dipisah:
 *
 *  - getMessage()  — detail teknis (path, id internal, status HTTP). Hanya
 *                    untuk log dan pengembang.
 *  - userMessage() — yang boleh sampai ke layar petugas dan ke kolom
 *                    nevira_sync_error. Tidak pernah memuat pengenal
 *                    internal NEVIRA.
 *
 * Pemisahan ini ada karena pesan mentah pernah bocor apa adanya ke halaman
 * complaint lewat nevira_sync_error, lengkap dengan id internal di dalamnya.
 */
abstract class NeviraException extends RuntimeException
{
    public function userMessage(): string
    {
        return $this->getMessage();
    }
}
