<?php

namespace App\Http\Controllers;

use App\Services\NeviraClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Pemeriksaan satu nota sebelum complaint disimpan. (API-7)
 *
 * Endpoint ini menyentuh data pelanggan di sistem lain, jadi dibatasi
 * berlapis:
 *
 *  - hanya peran yang memang mencatat complaint (kasir, customer care,
 *    supervisor). Divisi tidak berkepentingan dan ditolak.
 *  - hanya nomor nota yang cocok PERSIS. Sebelumnya pencarian sebagian
 *    diterima, sehingga mengetik "INV" saja mengembalikan order pelanggan
 *    mana pun — endpoint ini praktis jadi alat menyisir basis data NEVIRA.
 *  - kasir dibatasi pada outletnya sendiri.
 *  - nama karyawan hanya untuk peran yang memang boleh melihatnya.
 *  - id internal NEVIRA tidak pernah ikut ke browser.
 */
class NeviraLookupController extends Controller
{
    public function __invoke(Request $request, NeviraClient $nevira): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->canCreateComplaint(), 403);

        $request->validate([
            'id' => ['required', 'string', 'min:6', 'max:64'],
        ], [], ['id' => 'nomor nota']);

        $nota = trim((string) $request->string('id'));

        if (! $nevira->isConfigured()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Integrasi NEVIRA belum dikonfigurasi. Complaint tetap bisa disimpan tanpa tautan order.',
            ]);
        }

        try {
            $resolved = $nevira->resolveTransaction($nota);
            $summary  = $nevira->summarizeTransaction($resolved['payload']);
        } catch (Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => 'Tidak bisa mengambil data dari NEVIRA: '.$e->getMessage()
                    .' — complaint tetap bisa disimpan, tautan bisa diperbaiki nanti.',
            ]);
        }

        // Kasir hanya boleh memeriksa nota outletnya sendiri.
        if ($user->isKasir() && ! $this->milikOutletKasir($user, $summary)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Nota ini bukan milik outletmu. Minta Customer Care yang menanganinya.',
            ]);
        }

        return response()->json([
            'ok'   => true,
            'data' => $this->untukPeran($summary, $user),
        ]);
    }

    private function milikOutletKasir($user, array $summary): bool
    {
        $outletKasir = $user->outlet?->nevira_outlet_id;

        // Outlet yang belum dipetakan ke NEVIRA tidak bisa diperiksa
        // kepemilikannya. Tolak daripada meloloskan diam-diam.
        if (blank($outletKasir) || blank($summary['outlet_id'] ?? null)) {
            return false;
        }

        return (string) $outletKasir === (string) $summary['outlet_id'];
    }

    /**
     * Buang apa pun yang tidak berhak dilihat peran ini.
     */
    private function untukPeran(array $summary, $user): array
    {
        $aman = [
            'invoice'        => $summary['invoice'] ?? null,
            'outlet_name'    => $summary['outlet_name'] ?? null,
            'status'         => $summary['status'] ?? null,
            'payment_status' => $summary['payment_status'] ?? null,
            'grand_total'    => $summary['grand_total'] ?? null,
            'customer_name'  => $summary['customer_name'] ?? null,
            'customer_phone' => $summary['customer_phone'] ?? null,
            'created_at'     => $summary['created_at'] ?? null,
        ];

        // Nama karyawan menyangkut penilaian kerja orang.
        if ($user->canSeeStaffAttribution()) {
            $aman['cashier_name'] = $summary['cashier_name'] ?? null;
        }

        return $aman;
    }
}
