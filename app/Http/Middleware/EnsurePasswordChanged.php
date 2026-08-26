<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Akun yang baru dibuat supervisor memakai password sementara. Sampai
 * password itu diganti, pengguna tidak boleh ke mana-mana selain halaman
 * ganti password. (API-14)
 *
 * Tanpa ini kolom must_change_password hanya jadi catatan tanpa akibat,
 * dan password sementara akan hidup selamanya di outlet.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if ($user && $user->must_change_password && ! $request->routeIs('password.*', 'logout')) {
            return redirect()->route('password.edit');
        }

        return $next($request);
    }
}
