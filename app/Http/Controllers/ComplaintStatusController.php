<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateComplaintStatusRequest;
use App\Models\Complaint;
use App\Models\User;
use App\Services\JejakComplaint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Perubahan status complaint, beserta kompensasinya.
 *
 * Berdiri sendiri karena tiga pengaman berbeda bertemu di satu aksi ini —
 * penanda versi (API-8 T6), wewenang menutup, dan batas kompensasi dua arah
 * (API-14 #10) — dan ketiganya lebih mudah dibaca kalau tidak berdesakan
 * dengan pencatatan complaint baru.
 */
class ComplaintStatusController extends Controller
{
    public function __construct(private JejakComplaint $jejak) {}

    /** Ubah status, dengan pencatatan riwayat dan penegakan wewenang. */
    public function update(UpdateComplaintStatusRequest $request, Complaint $complaint)
    {
        $user = $request->user();
        $data = $request->validated();

        $sekarang = (int) $complaint->compensation_amount;
        $diminta = $request->has('compensation_amount')
            ? (int) ($data['compensation_amount'] ?? 0)
            : $sekarang;

        $tolakan = $this->tolakPerubahanStatus($complaint, $user, $data, $sekarang, $diminta);

        if ($tolakan) {
            return $tolakan;
        }

        $this->terapkanStatus($complaint, $user, $data, $sekarang, $diminta);

        return back()->with('status', 'Status diperbarui: '.$complaint->statusLabel());
    }

    /**
     * Alasan-alasan perubahan status ditolak. null berarti boleh lanjut.
     *
     * @param  array<string,mixed>  $data
     */
    private function tolakPerubahanStatus(
        Complaint $complaint,
        User $user,
        array $data,
        int $sekarang,
        int $diminta,
    ): ?RedirectResponse {
        if ((int) $data['lock_version'] !== (int) $complaint->lock_version) {
            // withInput() penting: petugas sudah mengetik resolusi panjang,
            // dan menghukumnya dengan mengosongkan form membuat pengaman ini
            // dibenci lalu diakali. (API-8 T6)
            return back()->withInput()->withErrors([
                'lock_version' => 'Complaint ini sudah diubah orang lain sejak halaman ini dibuka — '
                    .'sekarang berstatus '.$complaint->statusLabel().'. Muat ulang halamannya, '
                    .'baca perubahannya, lalu simpan lagi kalau masih perlu.',
            ]);
        }

        if ($this->menutup($data['status']) && ! $user->canResolve()) {
            return back()->withErrors(['status' => 'Peranmu tidak berwenang menutup complaint.']);
        }

        return $this->tolakKompensasi($user, $sekarang, $diminta);
    }

    /**
     * Batas wewenang kompensasi berlaku DUA ARAH.
     *
     * Batas atas sudah lama dijaga; yang tidak adalah penurunan — kasir bisa
     * memangkas angka yang sudah disetujui supervisor jadi 1. Yang menentukan
     * bukan arah perubahannya, tapi apakah KEDUA nilai ada di dalam
     * wewenangnya. (API-14 #10)
     */
    private function tolakKompensasi(User $user, int $sekarang, int $diminta): ?RedirectResponse
    {
        if ($diminta === $sekarang) {
            return null;
        }

        $batas = $user->compensationLimit();

        if ($diminta > $batas) {
            return back()->withErrors([
                'compensation_amount' => 'Nilai kompensasi melebihi batas wewenang '.$user->roleLabel()
                    .' (Rp '.number_format($batas, 0, ',', '.').'). Naikkan ke supervisor.',
            ]);
        }

        if ($sekarang > $batas) {
            return back()->withErrors([
                'compensation_amount' => 'Kompensasi Rp '.number_format($sekarang, 0, ',', '.')
                    .' disetujui di atas batas wewenang '.$user->roleLabel()
                    .'. Hanya yang berwenang di angka itu yang boleh mengubahnya.',
            ]);
        }

        return null;
    }

    /** @param  array<string,mixed>  $data */
    private function terapkanStatus(
        Complaint $complaint,
        User $user,
        array $data,
        int $sekarang,
        int $diminta,
    ): void {
        $from = $complaint->status;
        $closing = $this->menutup($data['status']);

        DB::transaction(function () use ($complaint, $data, $user, $from, $closing, $diminta, $sekarang) {
            $complaint->status = $data['status'];
            $complaint->lock_version = (int) $complaint->lock_version + 1;
            $complaint->resolution = $data['resolution'] ?? $complaint->resolution;
            $complaint->root_cause = $data['root_cause'] ?? $complaint->root_cause;

            // Nilai yang sama artinya tidak ada perubahan; 0 yang dikirim
            // sengaja memang mengosongkan kompensasi.
            $complaint->compensation_amount = $diminta;

            if ($complaint->first_response_at === null) {
                $complaint->first_response_at = now();
            }

            // Complaint yang dibuka kembali kehilangan stempel selesainya —
            // kalau tidak, lama penyelesaiannya terhitung dari penutupan yang
            // sudah dibatalkan.
            $complaint->resolved_at = $closing
                ? ($complaint->resolved_at ?? now())
                : null;

            $complaint->save();

            $this->jejak->statusBerubah($complaint, $user, $from, $complaint->status, $data['note'] ?? null);

            if ($diminta !== $sekarang) {
                $this->jejak->kompensasiBerubah($complaint, $user, $sekarang, $diminta);
            }
        });
    }

    private function menutup(string $status): bool
    {
        return in_array($status, ['selesai', 'ditolak'], true);
    }
}
