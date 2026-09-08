<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Gerbang verifikasi email, berdiri DI DEPAN gerbang ganti password. (API-35)
 *
 * Password sementara dibuat sistem lalu disampaikan orang ke orang — lewat
 * chat, kadang grup. Siapa pun yang membacanya di perjalanan itu bisa
 * mendahului pemiliknya: masuk, ganti password, dan akun itu jadi miliknya.
 * Selama email belum terverifikasi, password sementara saja tidak cukup untuk
 * sampai ke halaman ganti password.
 *
 * Urutannya: login -> verifikasi email -> ganti password -> pakai sistem.
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if ($user && ! $user->hasVerifiedEmail() && ! $request->routeIs('verification.*', 'logout')) {
            // Pesan yang sedang dalam perjalanan ikut dibawa. Login menaruh
            // kabar "surat gagal dikirim" di flash session lalu mengarahkan
            // ke halaman yang diminta sebelumnya; tanpa reflash, pesan itu
            // mati di pantulan ini dan orang melihat halaman verifikasi yang
            // seolah-olah baik-baik saja.
            $request->session()->reflash();

            return redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
