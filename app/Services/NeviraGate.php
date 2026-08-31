<?php

namespace App\Services;

use App\Exceptions\NeviraAccessDenied;
use App\Exceptions\NeviraInputRejected;
use App\Exceptions\NeviraOutletMismatch;
use App\Exceptions\NeviraRateLimited;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
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

    /** Nota terbaru yang ditawarkan ke petugas dalam satu pencarian. (API-26) */
    public const NOTA_PER_PENCARIAN = 5;

    /**
     * Baris yang ditarik dari NEVIRA sebelum disaring.
     *
     * Lebih besar dari yang ditampilkan karena pencarian NEVIRA mencocokkan
     * SEBAGIAN: nomor pelanggan lain yang kebetulan memuat potongan yang sama
     * ikut terbawa, dan baris itu dibuang di sini. Tanpa cadangan ini,
     * penyaringan bisa menyisakan kurang dari lima padahal notanya ada.
     */
    private const AMBIL_SEBELUM_DISARING = 30;

    /**
     * Cari nota lewat nomor telepon pelanggan, supaya petugas memilih
     * alih-alih mengetik 24 karakter sambil pelanggan menunggu. (API-26)
     *
     * Lewat gerbang yang sama seperti jalur nota — peran, batas laju, dan
     * lingkup outlet berlaku persis sama. Ini pintu baru ke data yang sama,
     * jadi ia tidak boleh punya aturan sendiri.
     *
     * Dua pengaman yang khas jalur ini:
     *
     *   1. Panjang minimum. Pencarian NEVIRA mencocokkan sebagian, jadi
     *      kata kunci pendek mengembalikan transaksi milik puluhan pelanggan.
     *      Lihat NomorTelepon::MIN_DIGIT.
     *   2. Cocok persis pada nomornya. Yang dikembalikan hanya nota yang
     *      nomor pelanggannya benar-benar sama dengan yang dicari. Tanpa ini
     *      kotak pencarian berubah jadi alat menyisir daftar pelanggan
     *      NEVIRA — persis yang dicegah aturan cocok-persis pada nomor nota.
     *
     * @return array{rows:array<int,array<string,mixed>>,lebih:bool}
     *
     * @throws \App\Exceptions\NeviraException
     */
    public function cariNotaLewatTelepon(
        User $user,
        string $telepon,
        ?string $dari = null,
        ?string $sampai = null,
    ): array {
        $this->pastikanBoleh($user);

        $inti = NomorTelepon::inti($telepon);

        if ($inti === null) {
            throw new NeviraInputRejected(
                'Masukkan nomor telepon pelanggan selengkapnya (contoh: 081234567890). '
                .'Potongan nomor tidak dicari — hasilnya akan berisi pelanggan lain.'
            );
        }

        $outletKasir = $this->outletKasirAtauTolak($user);

        $this->pastikanBelumMelewatiBatas($user);

        $halaman = $this->client->searchTransactionsPage(
            $inti,
            self::AMBIL_SEBELUM_DISARING,
            $dari,
            $sampai,
        );

        $cocok = collect($halaman['data'])
            ->filter(fn ($row) => is_array($row)
                && NomorTelepon::sama($inti, $row['customer']['phone'] ?? null))
            // Kasir hanya boleh nota outletnya sendiri — aturan yang sama
            // dengan pastikanOutletCocok(), hanya di sini ia menyaring daftar
            // alih-alih menolak satu nota.
            ->filter(fn ($row) => $outletKasir === null
                || (string) ($row['id_outlet'] ?? '') === $outletKasir)
            ->values();

        return [
            'rows'  => $this->client->summarizeSearchRows($cocok->take(self::NOTA_PER_PENCARIAN)->all()),
            'lebih' => $cocok->count() > self::NOTA_PER_PENCARIAN,
        ];
    }

    /**
     * Id outlet NEVIRA yang boleh dilihat pengguna ini, atau null kalau ia
     * boleh melihat semua outlet.
     *
     * Kasir yang outletnya belum dipetakan tidak bisa dibandingkan sama
     * sekali — ditolak, bukan diloloskan dengan daftar kosong yang terbaca
     * seperti "pelanggan ini tidak punya nota".
     */
    private function outletKasirAtauTolak(User $user): ?string
    {
        if (! $user->isKasir()) {
            return null;
        }

        $outlet = $user->outlet?->nevira_outlet_id;

        if (blank($outlet)) {
            throw new NeviraOutletMismatch(
                'Outletmu belum dipetakan ke NEVIRA, jadi nota tidak bisa dicari dari sini. '
                .'Minta supervisor menjalankan pemetaan outlet.'
            );
        }

        return (string) $outlet;
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

    /**
     * Daftar karyawan satu outlet, untuk memilih pelaku complaint. (API-19)
     *
     * Lewat gerbang yang sama seperti data order — bukan pintu baru:
     *
     *   1. peran      — nama dan NIP karyawan bukan konsumsi kasir.
     *   2. batas laju — dihitung di jatah yang sama dengan panggilan lain.
     *   3. cache      — daftar karyawan hampir tidak berubah, sementara
     *                   halaman complaint dibuka berkali-kali. Tanpa cache,
     *                   membuka satu complaint lima kali menghabiskan
     *                   seperempat jatah panggilan menit itu.
     *
     * Kegagalan mengembalikan daftar kosong, bukan melempar: daftar ini
     * mempercepat pemilihan, dan halaman complaint tetap harus terbuka
     * walau NEVIRA sedang mati. Isian bebas tetap tersedia.
     *
     * @return array<int,array{staff_id:?string,name:string,nip:?string,role_id:mixed}>
     */
    public function outletStaff(User $user, ?string $neviraOutletId): array
    {
        $this->pastikanBolehLihatKaryawan($user);

        if (blank($neviraOutletId) || ! $this->isConfigured()) {
            return [];
        }

        $kunci = 'nevira.outlet_staff.'.$neviraOutletId;
        $tersimpan = Cache::get($kunci);

        if (is_array($tersimpan)) {
            return $tersimpan;
        }

        try {
            $this->pastikanBelumMelewatiBatas($user);
            $staff = $this->client->summarizeStaff($this->client->usersByOutlet((string) $neviraOutletId));
        } catch (Throwable) {
            return [];
        }

        Cache::put($kunci, $staff, now()->addMinutes((int) config('nevira.outlet_staff_ttl_minutes')));

        return $staff;
    }

    private function pastikanBoleh(User $user): void
    {
        if (! $user->canCreateComplaint()) {
            throw new NeviraAccessDenied('Peran '.$user->role.' tidak berkepentingan dengan data order NEVIRA.');
        }
    }

    private function pastikanBolehLihatKaryawan(User $user): void
    {
        if (! $user->canSeeStaffAttribution()) {
            throw new NeviraAccessDenied('Peran '.$user->role.' tidak berkepentingan dengan data karyawan.');
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
