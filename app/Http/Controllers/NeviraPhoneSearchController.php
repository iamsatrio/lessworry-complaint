<?php

namespace App\Http\Controllers;

use App\Exceptions\NeviraAccessDenied;
use App\Exceptions\NeviraException;
use App\Services\NeviraGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cari nota lewat nomor telepon pelanggan. (API-26)
 *
 * Nomor nota berbentuk INV/118/1787749345365/1 — 24 karakter yang diketik
 * tangan sambil pelanggan menunggu, dan satu digit salah berarti mengulang
 * dari awal. Pelanggan hampir selalu bisa menyebutkan nomor teleponnya.
 *
 * Sama seperti NeviraLookupController, tidak ada aturan akses yang tinggal
 * di sini: peran, batas laju, lingkup outlet, dan panjang minimum nomor
 * semuanya ditegakkan NeviraGate.
 */
class NeviraPhoneSearchController extends Controller
{
    public function __invoke(Request $request, NeviraGate $gate): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            // Cara mempersempit kalau notanya lebih dari yang ditampilkan.
            // Dikirim berpasangan; satu tanggal saja tidak menyaring apa pun
            // di NEVIRA dan hanya membuat hasilnya membingungkan.
            'from'  => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to'    => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
        ], [], [
            'phone' => 'nomor telepon',
            'from'  => 'tanggal awal',
            'to'    => 'tanggal akhir',
        ]);

        if (! $gate->isConfigured()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Integrasi NEVIRA belum dikonfigurasi. Ketik nomor notanya langsung.',
            ]);
        }

        try {
            $hasil = $gate->cariNotaLewatTelepon(
                $user,
                trim((string) $data['phone']),
                $data['from'] ?? null,
                $data['to'] ?? null,
            );
        } catch (NeviraAccessDenied) {
            abort(403);
        } catch (NeviraException $e) {
            return response()->json(['ok' => false, 'message' => $e->userMessage()]);
        }

        if ($hasil['rows'] === []) {
            return response()->json([
                'ok'      => false,
                'message' => 'Tidak ada nota atas nomor itu'
                    .(filled($data['from'] ?? null) ? ' pada rentang tanggal itu' : '')
                    .'. Periksa nomornya, atau ketik nomor notanya langsung.',
            ]);
        }

        return response()->json([
            'ok'    => true,
            'data'  => $hasil['rows'],
            'lebih' => $hasil['lebih'],
        ]);
    }
}
