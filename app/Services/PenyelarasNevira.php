<?php

namespace App\Services;

use App\Exceptions\NeviraAccessDenied;
use App\Exceptions\NeviraException;
use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;

/**
 * Menarik data order NEVIRA ke dalam satu complaint.
 *
 * Dipakai tiga jalur — form intake, penautan menyusul, dan tarik ulang —
 * yang sekarang duduk di controller berbeda. Karena itu ia berdiri sendiri:
 * tiga salinan logika sinkron adalah tiga tempat untuk salah.
 *
 * Semua pemeriksaan akses ada di NeviraGate, bukan di sini — itu inti
 * perbaikan API-8 T1.
 */
class PenyelarasNevira
{
    public function __construct(private NeviraGate $nevira) {}

    /**
     * Penolakan yang bersifat wewenang dilempar ke atas sebagai 403; sisanya
     * dicatat sebagai kegagalan sinkron supaya complaint tetap hidup walau
     * NEVIRA menolak atau mati. (API-8, API-10)
     */
    public function selaraskan(Complaint $complaint, User $user): void
    {
        try {
            $resolved = $this->nevira->resolve($user, $complaint->nevira_transaction_number);
            $summary = $resolved['summary'];

            // Perjalanan kurir ditarik terpisah: detail transaksi tidak
            // membawa nama kurirnya. Gagal di sini tidak membatalkan sinkron
            // order — data kurir sifatnya pelengkap.
            if (filled($summary['invoice'])) {
                $summary['deliveries'] = $this->nevira->deliveries($summary['invoice']);
            }

            $complaint->forceFill([
                // Id internal disimpan untuk panggilan API berikutnya, dan
                // tidak pernah dirender ke halaman mana pun.
                'nevira_transaction_id' => $resolved['id'],
                'nevira_transaction_number' => $resolved['number'] ?? $complaint->nevira_transaction_number,
                'nevira_snapshot' => $summary,
                'nevira_customer_id' => $summary['customer_id'] ?? null,
                'nevira_synced_at' => now(),
                'nevira_sync_error' => null,
            ])->save();

            $this->isiPelapor($complaint, $summary);
            $this->isiOutlet($complaint, $summary);
        } catch (NeviraAccessDenied) {
            // abort() di dalam service memang tidak lazim, tapi ini menjaga
            // perilaku yang sudah diuji: peran yang tidak berhak menerima
            // 403, bukan complaint yang tersimpan dengan catatan kegagalan.
            abort(403);
        } catch (NeviraException $e) {
            $complaint->forceFill([
                'nevira_sync_error' => mb_substr($e->userMessage(), 0, 190),
            ])->save();
        }
    }

    /**
     * Isi identitas pelapor dari pelanggan pada nota — hanya kolom yang
     * masih kosong. Yang sudah diketik petugas tidak pernah ditimpa:
     * pelapor bisa saja bukan pemilik order, misalnya yang mengantarkan.
     *
     * @param  array<string,mixed>  $summary
     */
    private function isiPelapor(Complaint $complaint, array $summary): void
    {
        $isi = [];

        if (blank($complaint->reporter_name) && filled($summary['customer_name'] ?? null)) {
            $isi['reporter_name'] = $summary['customer_name'];
        }

        if (blank($complaint->reporter_phone) && filled($summary['customer_phone'] ?? null)) {
            $isi['reporter_phone'] = $summary['customer_phone'];
        }

        if ($isi) {
            $complaint->forceFill($isi)->save();
        }
    }

    /**
     * Tentukan outlet complaint dari outlet pada nota.
     *
     * Kasir tetap terkunci ke outletnya sendiri (diputuskan saat intake),
     * jadi ini hanya mengisi yang belum ditentukan — biasanya complaint dari
     * Customer Care, yang tidak tahu outlet mana sebelum notanya dicek.
     *
     * @param  array<string,mixed>  $summary
     */
    private function isiOutlet(Complaint $complaint, array $summary): void
    {
        if (filled($complaint->outlet_id)) {
            return;
        }

        $idNevira = $summary['outlet_id'] ?? null;

        if (blank($idNevira)) {
            return;
        }

        $outlet = Outlet::where('nevira_outlet_id', (string) $idNevira)->first();

        if ($outlet) {
            $complaint->forceFill(['outlet_id' => $outlet->id])->save();
        }
    }
}
