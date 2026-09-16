<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

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

        // `routeIs(...)` di sini TIDAK pernah bernilai penting hari ini:
        // rute `verification.*` dan `logout` duduk di grup yang tidak memakai
        // alias `email.verified`, jadi middleware ini tidak pernah berjalan di
        // atasnya. Dipertahankan dengan sengaja, bukan karena terlewat.
        // (Keputusan API-37 nomor 4)
        //
        // Yang dijaganya satu kegagalan tertentu: begitu ada yang memasang
        // `email.verified` pada grup yang memuat rute verifikasi — sengaja,
        // atau karena menyalin daftar middleware grup di bawahnya — tanpa
        // syarat ini halaman verifikasi memantul ke dirinya sendiri, dan
        // SETIAP akun yang belum terverifikasi terkunci tanpa jalan keluar
        // selain shell. Biaya syarat ini satu pemanggilan; biaya salahnya
        // seluruh tim tidak bisa masuk.
        //
        // Supaya ia tidak diam-diam jadi hidup dan berubah arti,
        // GerbangVerifikasiEmailTest menuntut rute verifikasi TIDAK memakai
        // alias ini. Kalau kelak itu berubah, test itu yang bicara lebih dulu.
        if ($user && ! $user->hasVerifiedEmail() && ! $request->routeIs('verification.*', 'logout')) {
            // Pesan yang sedang dalam perjalanan ikut dibawa. Login menaruh
            // kabar "surat gagal dikirim" di flash session lalu mengarahkan
            // ke halaman yang diminta sebelumnya; tanpa reflash, pesan itu
            // mati di pantulan ini dan orang melihat halaman verifikasi yang
            // seolah-olah baik-baik saja.
            Session::reflash();

            return redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
