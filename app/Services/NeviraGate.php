<?php

namespace App\Services;

use App\Exceptions\NeviraAccessDenied;
use App\Exceptions\NeviraInputRejected;
use App\Exceptions\NeviraOutletMismatch;
use App\Exceptions\NeviraRateLimited;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Satu-satunya pintu ke data order NEVIRA. (API-8 T1)
 *
 * Sebelumnya seluruh pengaman duduk di NeviraLookupController, sehingga
 * `POST /complaints` dan `PUT /complaints/{id}/link` — yang sama-sama menarik
 * data pelanggan dari NEVIRA — melewatinya begitu saja. Celahnya tidak
 * tertutup, hanya pindah pintu.
 *
 * Karena itu pemeriksaan tidak lagi ditempel per rute. Semuanya di sini,
 * dan tidak ada controller yang boleh memegang NeviraClient langsung
 * (dijaga oleh NeviraChokePointTest):
 *
 *   1. peran      — hanya yang memang mencatat complaint. Divisi ditolak.
 *   2. batas laju — 20 panggilan per pengguna per menit, dihitung untuk
 *                   SEMUA jalur digabung, bukan per rute.
 *   3. lingkup    — kasir hanya boleh nota outletnya sendiri.
 *
 * Aturan cocok-persis ditegakkan NeviraClient::resolveTransaction().
 */
class NeviraGate
{
    /** Panggilan NEVIRA per pengguna per menit, digabung lintas rute. */
    public const PER_MINUTE = 20;

    public function __construct(private NeviraClient $client) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * Tarik satu order NEVIRA atas nama seorang pengguna.
     *
     * @return array{id:string,number:?string,summary:array}
     *
     * @throws \App\Exceptions\NeviraException
     */
    public function resolve(User $user, string $input): array
    {
        $this->pastikanBoleh($user);
        $this->pastikanBentukNota($input);
        $this->pastikanBelumMelewatiBatas($user);

        $resolved = $this->client->resolveTransaction($input);
        $summary  = $this->client->summarizeTransaction($resolved['payload']);

        $this->pastikanOutletCocok($user, $summary);

        return [
            'id'      => $resolved['id'],
            'number'  => $resolved['number'],
            'summary' => $summary,
        ];
    }

    /**
     * Perjalanan kurir untuk order yang SUDAH lolos resolve().
     *
     * Dipisah karena kegagalannya tidak boleh menjatuhkan sinkron order —
     * data kurir sifatnya pelengkap.
     */
    public function deliveries(string $transactionNumber): array
    {
        try {
            return $this->client->summarizeDeliveries($this->client->deliveries($transactionNumber));
        } catch (Throwable) {
            return [];
        }
    }

    private function pastikanBoleh(User $user): void
    {
        if (! $user->canCreateComplaint()) {
            throw new NeviraAccessDenied('Peran '.$user->role.' tidak berkepentingan dengan data order NEVIRA.');
        }
    }

    /**
     * Yang dipegang petugas adalah nomor nota di struk, bukan id internal
     * NEVIRA. Masukan yang seluruhnya angka ditembakkan langsung ke endpoint
     * detail dan karena itu melewati aturan cocok-persis — jadi nomor bisa
     * dicoba satu per satu. Tidak ada alasan menerimanya dari antarmuka.
     * (API-8 T4)
     */
    private function pastikanBentukNota(string $input): void
    {
        if (ctype_digit(trim($input))) {
            throw new NeviraInputRejected(
                'Masukkan nomor nota seperti tertulis di struk (contoh: INV/118/1787749345365/1), bukan angka saja.'
            );
        }
    }

    private function pastikanBelumMelewatiBatas(User $user): void
    {
        $key = 'nevira:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            throw new NeviraRateLimited(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * Kasir hanya boleh melihat nota outletnya sendiri.
     *
     * Outlet yang belum dipetakan ke NEVIRA tidak bisa dibandingkan sama
     * sekali — ditolak, bukan diloloskan diam-diam.
     */
    private function pastikanOutletCocok(User $user, array $summary): void
    {
        if (! $user->isKasir()) {
            return;
        }

        $outletKasir = $user->outlet?->nevira_outlet_id;
        $outletNota  = $summary['outlet_id'] ?? null;

        if (blank($outletKasir) || blank($outletNota) || (string) $outletKasir !== (string) $outletNota) {
            throw new NeviraOutletMismatch('Nota ini bukan milik outletmu. Minta Customer Care yang menanganinya.');
        }
    }
}
