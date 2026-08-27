<?php

namespace App\Exceptions;

/**
 * NEVIRA membalas selain 2xx, atau tidak membalas sama sekali.
 *
 * Detail teknis (path lengkap, yang memuat id internal transaksi) disimpan
 * terpisah dan tidak pernah ikut ke layar.
 */
class NeviraRequestFailed extends NeviraException
{
    public function __construct(string $technical, private readonly string $ringkas)
    {
        parent::__construct($technical);
    }

    public function userMessage(): string
    {
        return $this->ringkas;
    }
}
