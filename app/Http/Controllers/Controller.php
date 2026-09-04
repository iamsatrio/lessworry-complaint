<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /** Supaya $this->authorize() memanggil ComplaintPolicy. */
    use AuthorizesRequests;
}
