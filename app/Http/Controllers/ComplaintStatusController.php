<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateComplaintStatusRequest;
use App\Models\Complaint;
use App\Models\User;
use App\Services\JejakComplaint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Perubahan status complaint, beserta kompensasi dan jedanya.
 *
 * Berdiri sendiri karena lima pengaman berbeda bertemu di satu aksi ini —
 * penanda versi (API-8 T6), wewenang menutup, wewenang menjeda, wewenang
 * membuka kembali, dan batas kompensasi dua arah (API-14 #10) — dan semuanya
 * lebih mudah dibaca kalau tidak berdesakan dengan pencatatan complaint baru.
 *
 * Pembagian tempatnya: syarat yang hanya bergantung pada PENGGUNA dan
 * COMPLAINT ada di ComplaintPolicy (close, pause, reopen). Syarat yang
 * bergantung pada ANGKA YANG DIKIRIM form tetap di sini — batas kompensasi
 * tidak bisa dijawab tanpa melihat payload-nya.
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

        // Form yang tidak mengirim kolom ini sama sekali tidak sedang
        // mengubah jedanya. Yang memindahkan tiket keluar dari Handling selalu
        // melanjutkannya kembali — termasuk saat menutup.
        $mintaJeda = $data['status'] === 'handling'
            ? ($request->has('pause_reason') ? ($data['pause_reason'] ?? null) : $complaint->pause_reason)
            : null;

        $tolakan = $this->tolakPerubahanStatus($request, $complaint, $user, $data, $sekarang, $diminta, $mintaJeda);

        if ($tolakan) {
            return $tolakan;
        }

        $this->terapkanStatus($request, $complaint, $user, $data, $sekarang, $diminta, $mintaJeda);

        return back()->with('status', 'Status diperbarui: '.$complaint->fresh()->statusDisplay());
    }

    /**
     * Alasan-alasan perubahan status ditolak. null berarti boleh lanjut.
     *
     * @param  array<string,mixed>  $data
     */
    private function tolakPerubahanStatus(
        UpdateComplaintStatusRequest $request,
        Complaint $complaint,
        User $user,
        array $data,
        int $sekarang,
        int $diminta,
        ?string $mintaJeda,
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

        $closing = $this->menutup($data['status']);
        $reopening = $complaint->status === 'close' && ! $closing;

        if ($closing && ! $user->can('close', $complaint)) {
            return back()->withInput()->withErrors(['status' => $user->isKasir()
                ? 'Kasir hanya boleh menutup complaint berbobot Ringan. Complaint ini '
                    .$complaint->bobotLabel().' — teruskan ke Customer Care.'
                : 'Peranmu tidak berwenang menutup complaint.']);
        }

        // Membuka kembali tiket yang sudah ditutup memakai wewenang yang sama
        // dengan menutupnya. Tanpa ini, kasir tidak boleh MENUTUP complaint
        // Berat tapi boleh MEMBATALKAN penutupan supervisor — dan blok
        // penerapan mengosongkan resolved_at, jadi waktu penyelesaian yang
        // "selalu dihitung sistem" hilang permanen. (Review PR #1 temuan A)
        if ($reopening && ! $user->can('reopen', $complaint)) {
            return back()->withInput()->withErrors(['status' => $user->isKasir()
                ? 'Kasir hanya boleh membuka kembali complaint berbobot Ringan. Complaint ini '
                    .$complaint->bobotLabel().' dan sudah ditutup — mintakan ke Customer Care.'
                : 'Peranmu tidak berwenang membuka kembali complaint yang sudah ditutup.']);
        }

        if ($tolakJeda = $this->tolakJeda($request, $complaint, $user, $data, $mintaJeda)) {
            return $tolakJeda;
        }

        return $this->tolakKompensasi($user, $sekarang, $diminta, $closing, $reopening);
    }

    /**
     * Jeda hanya masuk akal pada tiket yang sedang ditangani, dan memulainya
     * adalah wewenang. (Review PR #1 temuan B)
     *
     * @param  array<string,mixed>  $data
     */
    private function tolakJeda(
        UpdateComplaintStatusRequest $request,
        Complaint $complaint,
        User $user,
        array $data,
        ?string $mintaJeda,
    ): ?RedirectResponse {
        // Tiket Open yang belum dipegang siapa pun tidak sedang menunggu
        // pelanggan.
        if ($request->filled('pause_reason') && $data['status'] !== 'handling') {
            return back()->withInput()->withErrors([
                'pause_reason' => 'Jeda hanya berlaku untuk complaint yang sedang ditangani (Handling).',
            ]);
        }

        // Yang diperiksa MEMULAI jeda, bukan mempertahankan jeda yang sudah
        // berjalan: kasir yang menambahkan catatan pada tiket Berat yang
        // dijeda Customer Care tidak sedang menjalankan wewenang itu, dan
        // menolaknya di sini hanya akan mengunci tiketnya dari semua orang.
        $memulaiJeda = $mintaJeda !== null && $complaint->paused_at === null;

        if ($memulaiJeda && ! $user->can('pause', $complaint)) {
            return back()->withInput()->withErrors(['pause_reason' => $user->isKasir()
                ? 'Kasir hanya boleh menjeda complaint berbobot Ringan. Complaint ini '
                    .$complaint->bobotLabel().' — jeda menghentikan hitungan SLA, jadi '
                    .'yang memutuskan Customer Care.'
                : 'Peranmu tidak berwenang menjeda hitungan SLA.']);
        }

        return null;
    }

    /**
     * Batas wewenang kompensasi berlaku DUA ARAH, dan juga saat tiketnya
     * ditutup atau dibuka kembali.
     *
     * Batas atas sudah lama dijaga; yang tidak adalah penurunan — kasir bisa
     * memangkas angka yang sudah disetujui supervisor jadi 1. Yang menentukan
     * bukan arah perubahannya, tapi apakah KEDUA nilai ada di dalam
     * wewenangnya. (API-14 #10)
     *
     * Pemeriksaan penutupan berdiri terpisah karena ia berlaku meski angkanya
     * TIDAK berubah: tanpa itu, kasir menutup complaint Ringan berkompensasi
     * Rp 200.000 hanya dengan tidak mengirim field kompensasi. (API-25 #5)
     */
    private function tolakKompensasi(
        User $user,
        int $sekarang,
        int $diminta,
        bool $closing,
        bool $reopening,
    ): ?RedirectResponse {
        $batas = $user->compensationLimit();

        if (($closing || $reopening) && $diminta > $batas) {
            return back()->withInput()->withErrors([
                'compensation_amount' => 'Complaint ini berkompensasi Rp '.number_format($diminta, 0, ',', '.')
                    .', di atas batas wewenang '.$user->roleLabel()
                    .' (Rp '.number_format($batas, 0, ',', '.').'). '
                    .($closing ? 'Penutupannya' : 'Pembukaannya kembali').' naik ke yang berwenang di angka itu.',
            ]);
        }

        if ($diminta === $sekarang) {
            return null;
        }

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
        UpdateComplaintStatusRequest $request,
        Complaint $complaint,
        User $user,
        array $data,
        int $sekarang,
        int $diminta,
        ?string $mintaJeda,
    ): void {
        $from = $complaint->status;
        $closing = $this->menutup($data['status']);
        $reopening = $complaint->status === 'close' && ! $closing;
        // Dibaca sebelum blok di bawah mengosongkannya, supaya angka yang
        // dibatalkan tetap punya jejak di riwayat.
        $resolvedSebelumnya = $complaint->resolved_at;

        DB::transaction(function () use (
            $request, $complaint, $data, $user, $from, $closing, $reopening,
            $diminta, $sekarang, $mintaJeda, $resolvedSebelumnya
        ) {
            $complaint->status = $data['status'];
            $complaint->lock_version = (int) $complaint->lock_version + 1;
            $complaint->resolution = $data['resolution'] ?? $complaint->resolution;
            $complaint->root_cause = $data['root_cause'] ?? $complaint->root_cause;
            $complaint->close_reason = $closing ? $data['close_reason'] : null;

            if ($request->has('tindak_lanjut')) {
                $complaint->tindak_lanjut = $data['tindak_lanjut'] ?? null;
            }

            // Jeda dan lanjut. Menutup complaint selalu mengakhiri jeda —
            // tiket yang sudah Close tidak sedang menunggu siapa pun.
            $jeda = null;

            if ($mintaJeda !== null && ! $closing) {
                $sudahDijeda = $complaint->paused_at !== null;
                $complaint->pause($mintaJeda);
                $jeda = $sudahDijeda ? null : 'mulai';
            } elseif ($complaint->paused_at !== null) {
                $jeda = 'lanjut:'.$complaint->resume();
            }

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

            if ($reopening && $resolvedSebelumnya !== null) {
                $this->jejak->penutupanDibatalkan($complaint, $user, $resolvedSebelumnya);
            }

            if ($jeda === 'mulai') {
                $this->jejak->slaDijeda($complaint, $user);
            } elseif ($jeda !== null && str_starts_with($jeda, 'lanjut:')) {
                $this->jejak->slaDilanjutkan($complaint, $user, (int) substr($jeda, 7));
            }

            if ($diminta !== $sekarang) {
                $this->jejak->kompensasiBerubah($complaint, $user, $sekarang, $diminta);
            }
        });
    }

    private function menutup(string $status): bool
    {
        return $status === 'close';
    }
}
