<?php

namespace App\Http\Controllers;

use App\Exceptions\NeviraAccessDenied;
use App\Exceptions\NeviraException;
use App\Models\Outlet;
use App\Services\NeviraGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pemeriksaan satu nota sebelum complaint disimpan. (API-7)
 *
 * Controller ini TIDAK lagi menyimpan aturan aksesnya sendiri. Semua
 * pemeriksaan — peran, batas laju, lingkup outlet, cocok-persis — ada di
 * NeviraGate, supaya berlaku sama untuk rute mana pun yang menyentuh NEVIRA.
 * Yang tersisa di sini hanya penyaringan kolom per peran, karena itu memang
 * urusan tampilan.
 */
class NeviraLookupController extends Controller
{
    public function __invoke(Request $request, NeviraGate $gate): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'id' => ['required', 'string', 'min:6', 'max:64'],
        ], [], ['id' => 'nomor nota']);

        if (! $gate->isConfigured()) {
            return response()->json([
                'ok' => false,
                'message' => 'Integrasi NEVIRA belum dikonfigurasi. Complaint tetap bisa disimpan tanpa tautan order.',
            ]);
        }

        try {
            $resolved = $gate->resolve($user, trim((string) $request->string('id')));
        } catch (NeviraAccessDenied) {
            abort(403);
        } catch (NeviraException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->userMessage(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'data' => $this->untukPeran($resolved['summary'], $user),
        ]);
    }

    /**
     * Buang apa pun yang tidak berhak dilihat peran ini.
     */
    /**
     * Petakan outlet NEVIRA pada nota ke outlet di sistem ini.
     *
     * Yang dikembalikan id LOKAL, supaya form bisa memilih pilihannya tanpa
     * pengenal sistem lain ikut ke browser.
     */
    private function outletLokal(array $summary): ?int
    {
        $idNevira = $summary['outlet_id'] ?? null;

        if (blank($idNevira)) {
            return null;
        }

        return Outlet::where('nevira_outlet_id', (string) $idNevira)->value('id');
    }

    private function untukPeran(array $summary, $user): array
    {
        $aman = [
            'invoice' => $summary['invoice'] ?? null,
            'outlet_name' => $summary['outlet_name'] ?? null,
            // Id outlet LOKAL, bukan id NEVIRA — supaya form bisa memilih
            // pilihannya tanpa pengenal sistem lain ikut ke browser.
            'outlet_id' => $this->outletLokal($summary),
            'status' => $summary['status'] ?? null,
            'payment_status' => $summary['payment_status'] ?? null,
            'grand_total' => $summary['grand_total'] ?? null,
            'customer_name' => $summary['customer_name'] ?? null,
            'customer_phone' => $summary['customer_phone'] ?? null,
            'created_at' => $summary['created_at'] ?? null,
        ];

        // Nama karyawan menyangkut penilaian kerja orang.
        if ($user->canSeeStaffAttribution()) {
            $aman['cashier_name'] = $summary['cashier_name'] ?? null;
        }

        return $aman;
    }
}
