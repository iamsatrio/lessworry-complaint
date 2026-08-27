<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Larang browser menyimpan halaman milik pengguna yang sudah masuk.
 *
 * Tanpa ini, tombol Kembali dan cache halaman menyajikan ulang form lama
 * beserta token CSRF-nya yang sudah basi. Petugas mengisi form itu, menekan
 * Simpan, dan mendapat "Page Expired" — isiannya hilang tanpa penjelasan.
 *
 * Juga mencegah halaman berisi data pelanggan tertinggal di cache perangkat
 * outlet yang dipakai bergantian.
 */
class NoStoreForAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->user()) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }
}
