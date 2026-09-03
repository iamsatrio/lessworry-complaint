<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Pembatas laju /health yang tidak bergantung pada database. (API-27)
 *
 * Middleware `throttle` bawaan memakai cache store bawaan, dan store bawaan
 * produksi adalah `database`. Rute yang gunanya justru melaporkan database
 * yang mati tidak boleh menyentuh database sebelum sampai ke controllernya.
 *
 * Kalau cachenya sendiri tidak terbaca, permintaan tetap diteruskan: gagal
 * membatasi laju jauh lebih murah daripada membuat /health bisu justru saat
 * ada yang rusak.
 */
class BatasiLajuHealth
{
    public function handle(Request $request, Closure $next): Response
    {
        $kunci = 'health:rate:'.sha1((string) $request->ip());
        $batas = (int) config('health.rate_limit');

        try {
            $pembatas = new RateLimiter(Cache::store(config('health.cache_store')));

            if ($pembatas->tooManyAttempts($kunci, $batas)) {
                // Tanpa keterangan apa pun — rutenya terbuka tanpa autentikasi.
                return response()->json(['status' => 'error'], 429)
                    ->header('Retry-After', (string) $pembatas->availableIn($kunci))
                    ->header('Cache-Control', 'no-store, private');
            }

            $pembatas->hit($kunci, 60);
        } catch (Throwable) {
            return $next($request);
        }

        return $next($request);
    }
}
