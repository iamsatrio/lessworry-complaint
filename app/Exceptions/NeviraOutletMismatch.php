<?php

namespace App\Exceptions;

/**
 * Nota milik outlet lain, atau outlet petugas belum dipetakan ke NEVIRA.
 *
 * Dibalas sebagai pesan biasa, bukan 403: petugas boleh saja salah ketik,
 * dan yang penting datanya tidak ikut keluar.
 */
class NeviraOutletMismatch extends NeviraException {}
