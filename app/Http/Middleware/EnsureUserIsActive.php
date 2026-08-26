<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Akun yang dinonaktifkan setelah login harus langsung kehilangan akses,
 * bukan menunggu sesinya habis sendiri. (API-14)
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check() && ! Auth::user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => 'Akun ini sudah dinonaktifkan.']);
        }

        return $next($request);
    }
}
