<?php

namespace App\Http\Controllers;

use App\Models\Complaint;
use App\Services\JejakComplaint;
use App\Services\PenyelarasNevira;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tautan complaint ke order NEVIRA: memasang, membetulkan, menarik ulang.
 *
 * Form intake menjanjikan complaint boleh disimpan tanpa nomor order dan
 * ditautkan menyusul — janji itu perlu ada tempatnya. Ini juga jalan untuk
 * membetulkan nomor yang salah ketik.
 */
class ComplaintLinkController extends Controller
{
    public function __construct(
        private PenyelarasNevira $penyelaras,
        private JejakComplaint $jejak,
    ) {}

    /**
     * Isi complaint tidak pernah diubah diam-diam: setiap perubahan tautan
     * tercatat di riwayat beserta nomor lama dan barunya.
     */
    public function update(Request $request, Complaint $complaint)
    {
        // Menautkan berarti menarik data pelanggan dari NEVIRA. Peran yang
        // tidak mencatat complaint tidak berkepentingan dengan itu.
        $this->authorize('link', $complaint);

        $user = $request->user();

        $data = $request->validate([
            'nevira_transaction_number' => ['nullable', 'string', 'max:64'],
            'nota_exemption' => ['nullable', Rule::in(array_keys(config('complaint.nota_exemptions')))],
        ], [], ['nevira_transaction_number' => 'nomor nota']);

        $new = trim((string) ($data['nevira_transaction_number'] ?? ''));
        $old = (string) $complaint->nevira_transaction_number;

        if ($new === $old) {
            return back()->with('status', 'Nomor order tidak berubah.');
        }

        $this->lepasTautanLama($complaint, $new, $data['nota_exemption'] ?? null);
        $this->jejak->tautanOrder($complaint, $user, $old, $new);

        if ($new === '') {
            return back()->with('status', 'Tautan order dilepas.');
        }

        $this->penyelaras->selaraskan($complaint, $user);
        $gagal = $complaint->fresh()->nevira_sync_error;

        return back()->with('status', $gagal
            ? 'Nomor order disimpan, tapi datanya belum bisa ditarik: '.$gagal
            : 'Complaint tertaut ke order '.$new.'.');
    }

    /** Coba tautkan ulang ke NEVIRA (dipakai saat sinkron pertama gagal). */
    public function resync(Request $request, Complaint $complaint)
    {
        $this->authorize('link', $complaint);

        $this->penyelaras->selaraskan($complaint, $request->user());

        return back()->with('status', $complaint->nevira_sync_error
            ? 'Sinkron NEVIRA gagal: '.$complaint->nevira_sync_error
            : 'Data NEVIRA berhasil ditarik.');
    }

    /**
     * Buang jejak order lama sebelum yang baru ditarik.
     *
     * Snapshot lama milik order lain — kalau tertinggal, halaman complaint
     * menampilkan data pelanggan yang bukan miliknya.
     */
    private function lepasTautanLama(Complaint $complaint, string $baru, ?string $pengecualian): void
    {
        $complaint->forceFill([
            'nevira_transaction_number' => $baru !== '' ? $baru : null,
            'nevira_transaction_id' => null,
            'nota_exemption' => $baru !== '' ? null : ($pengecualian ?? $complaint->nota_exemption),
            'nevira_snapshot' => null,
            'nevira_customer_id' => null,
            'nevira_synced_at' => null,
            'nevira_sync_error' => null,
        ])->save();
    }
}
