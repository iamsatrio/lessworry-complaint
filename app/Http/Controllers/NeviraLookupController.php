<?php

namespace App\Http\Controllers;

use App\Services\NeviraClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Dipakai form intake: begitu petugas mengisi ID transaksi, data order
 * ditarik untuk dicocokkan sebelum complaint disimpan. (API-7)
 */
class NeviraLookupController extends Controller
{
    public function __invoke(Request $request, NeviraClient $nevira): JsonResponse
    {
        $request->validate(['id' => ['required', 'string', 'max:64']]);

        if (! $nevira->isConfigured()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Integrasi NEVIRA belum dikonfigurasi. Complaint tetap bisa disimpan tanpa tautan order.',
            ], 200);
        }

        try {
            $summary = $nevira->summarizeTransaction($nevira->transaction($request->string('id')));

            return response()->json(['ok' => true, 'data' => $summary]);
        } catch (Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => 'Tidak bisa mengambil data dari NEVIRA: '.$e->getMessage()
                    .' — complaint tetap bisa disimpan, tautan bisa diperbaiki nanti.',
            ], 200);
        }
    }
}
