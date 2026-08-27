<?php

namespace App\Exceptions;

/**
 * Peran ini memang tidak berkepentingan dengan data order NEVIRA.
 * Jawabannya 403, bukan pesan halus — ini soal wewenang, bukan gangguan.
 */
class NeviraAccessDenied extends NeviraException {}
