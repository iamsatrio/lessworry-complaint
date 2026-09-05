<?php

namespace App\Http\Controllers;

use App\Services\PemeriksaKesehatan;
use Illuminate\Http\JsonResponse;

/**
 * GET /health — dibaca pemantau, tanpa autentikasi. (API-27)
 *
 * Kode statusnya yang penting: 200 kalau semuanya hidup, 503 kalau ada yang
 * tidak. Pemantau apa pun bisa membaca itu tanpa memahami isi JSON-nya.
 */
class HealthController extends Controller
{
    public function __invoke(PemeriksaKesehatan $pemeriksa): JsonResponse
    {
        $hasil = $pemeriksa->periksa();

        return response()
            ->json($hasil, $hasil['status'] === 'ok' ? 200 : 503)
            // Tanpa ini proxy atau CDN bisa menyimpan jawaban "ok" dan terus
            // menyajikannya setelah sistemnya mati.
            ->header('Cache-Control', 'no-store, private');
    }
}
