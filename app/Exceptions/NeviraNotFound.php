<?php

namespace App\Exceptions;

/**
 * Nomor nota yang diketik petugas tidak ada di NEVIRA.
 *
 * Pesannya boleh memuat nomor nota itu sendiri — itu milik pelanggan dan
 * memang dipegang petugas, bukan pengenal internal NEVIRA.
 */
class NeviraNotFound extends NeviraException {}
