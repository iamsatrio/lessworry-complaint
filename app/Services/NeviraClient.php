<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Klien NEVIRA POS — HANYA BACA.
 *
 * Batasan keras (API-2): sistem complaint tidak pernah menulis, mengubah,
 * atau menghapus data di NEVIRA. Kelas ini sengaja hanya mengekspos GET.
 *
 * Autentikasi (diverifikasi dari koleksi Postman "less-worry BE" dan diuji
 * langsung ke api.nevira.id pada 2026-08-26):
 *
 *   POST /api/login  {email, password}  ->  {access_token, user_data}
 *   lalu setiap request membawa header:  Authorization: <token>
 *
 * PENTING: token dikirim MENTAH, TANPA awalan "Bearer". Memakai
 * "Bearer <token>" membuat server membalas 500 Server Error — bukan 401 —
 * sehingga mudah disalahartikan sebagai parameter kurang. Karena itu kelas
 * ini menyusun header Authorization sendiri, bukan lewat withToken().
 *
 * Token berupa JWT HS256 dengan masa berlaku 24 jam.
 */
class NeviraClient
{
    private const TOKEN_CACHE_KEY = 'nevira.access_token';

    public function isConfigured(): bool
    {
        return config('nevira.enabled')
            && filled(config('nevira.email'))
            && filled(config('nevira.password'));
    }

    public function token(bool $forceRefresh = false): string
    {
        if ($forceRefresh) {
            Cache::forget(self::TOKEN_CACHE_KEY);
        }

        return Cache::remember(
            self::TOKEN_CACHE_KEY,
            now()->addMinutes(config('nevira.token_ttl_minutes')),
            fn () => $this->login()
        );
    }

    private function login(): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Kredensial NEVIRA belum diisi. Set NEVIRA_EMAIL dan NEVIRA_PASSWORD di .env');
        }

        $response = Http::timeout(config('nevira.timeout'))
            ->acceptJson()
            ->asJson()
            ->post($this->url(config('nevira.login_endpoint')), [
                'email'    => config('nevira.email'),
                'password' => config('nevira.password'),
            ]);

        if (! $response->successful()) {
            // Jangan pernah log body request — berisi password.
            Log::warning('NEVIRA login gagal', ['status' => $response->status()]);
            throw new RuntimeException('Login NEVIRA gagal (HTTP '.$response->status().')');
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Login NEVIRA tidak mengembalikan access_token');
        }

        return $token;
    }

    /**
     * GET terautentikasi. Sekali retry saat token ditolak.
     *
     * NEVIRA membalas 401 dengan {"msg":"Please provide access token!"} kalau
     * header hilang, tapi 500 kalau formatnya salah — keduanya diperlakukan
     * sebagai kemungkinan token basi supaya satu kali refresh tetap dicoba.
     */
    private function get(string $path, array $query = []): array
    {
        $attempt = fn (string $token) => Http::timeout(config('nevira.timeout'))
            ->acceptJson()
            ->withHeaders(['Authorization' => $token])
            ->get($this->url($path), $query);

        $response = $attempt($this->token());

        if (in_array($response->status(), [401, 500], true)) {
            $response = $attempt($this->token(forceRefresh: true));
        }

        if (! $response->successful()) {
            throw new RuntimeException('NEVIRA '.$path.' balas HTTP '.$response->status());
        }

        return (array) $response->json();
    }

    /** Memastikan token masih hidup. Dipakai untuk pemeriksaan koneksi. */
    public function me(): array
    {
        return $this->get('/me');
    }

    /**
     * Detail satu transaksi — dasar penautan complaint ke order.
     *
     * Respons dibungkus dalam kunci "data".
     */
    public function transaction(string $transactionId): array
    {
        return $this->get('/transactions/'.rawurlencode($transactionId));
    }

    /**
     * Cari transaksi berdasarkan nomor struk, untuk petugas yang hanya
     * memegang nomor nota dan bukan ID internal.
     */
    public function searchTransactions(string $keyword, int $limit = 5): array
    {
        $payload = $this->get('/transactions', ['keyword' => $keyword, 'limit' => $limit]);

        return $payload['data'] ?? [];
    }

    /**
     * Perjalanan kurir untuk satu nomor nota.
     *
     * Detail transaksi memang membawa `delivery_transactions`, tapi tanpa
     * objek kurirnya — hanya `id_user_courier`. Daftar pengantaran yang
     * disaring dengan nomor nota mengembalikan baris yang sudah lengkap
     * dengan nama dan NIP kurir, jadi itu yang dipakai.
     */
    public function deliveries(string $transactionNumber, int $limit = 20): array
    {
        $payload = $this->get('/deliveries-transactions', [
            'keyword' => $transactionNumber,
            'limit'   => $limit,
        ]);

        return $payload['data'] ?? [];
    }

    /** @return array<int,array<string,mixed>> */
    public function summarizeDeliveries(array $rows): array
    {
        return collect($rows)
            ->sortBy(fn ($row) => $row['delivery_date'] ?? '')
            ->map(function ($row) {
                $courier = $row['courier'] ?? [];
                $status  = $row['status'] ?? null;

                return [
                    'id'            => $row['id_deliveries_transaction'] ?? null,
                    'date'          => $row['delivery_date'] ?? null,
                    'status_code'   => $status,
                    'status'        => config('nevira.delivery_status.'.$status, 'Kode '.$status),
                    'cancel_reason' => ($status === 6 && filled($row['cancel_type'] ?? null))
                        ? config('nevira.delivery_cancel_type.'.$row['cancel_type'], $row['cancel_type'])
                        : null,
                    'courier_name'  => $courier['username'] ?? null,
                    'courier_nip'   => $courier['nip'] ?? null,
                    'courier_id'    => $row['id_user_courier'] ?? null,
                    'queue_no'      => $row['queue_no'] ?? null,
                    'distance'      => $row['distance'] ?? null,
                    'notes'         => $row['notes'] ?? null,
                    'courier_notes' => $row['notes_courier'] ?? null,
                    'proof_count'   => count($row['proof_images'] ?? []),
                    'updated_at'    => $row['updated_at'] ?? null,
                ];
            })
            ->values()->all();
    }

    public function customer(string $customerId): array
    {
        return $this->get('/customer/'.rawurlencode($customerId));
    }

    /**
     * Ringkas payload transaksi jadi bentuk yang dipakai tampilan complaint.
     *
     * Nama field mengikuti skema NEVIRA yang sudah dipastikan, bukan tebakan.
     */
    public function summarizeTransaction(array $payload): array
    {
        $d = $payload['data'] ?? $payload;

        if (isset($d[0]) && is_array($d[0])) {
            $d = $d[0];
        }

        $customer = $d['customer'] ?? [];
        $outlet   = $d['outlet'] ?? [];

        return [
            'transaction_id'  => $d['id_transaction'] ?? null,
            'invoice'         => $d['transaction_number'] ?? null,
            'order_type'      => $d['order_type'] ?? null,
            'status'          => $d['status'] ?? null,
            'payment_status'  => $d['payment_status'] ?? null,
            'progress'        => $d['progress_percentage'] ?? null,
            'grand_total'     => $d['grand_total'] ?? null,
            'customer_id'     => $d['id_customer'] ?? ($customer['id_customer'] ?? null),
            'customer_name'   => $customer['customer_name'] ?? null,
            'customer_phone'  => $customer['phone'] ?? null,
            'outlet_id'       => $d['id_outlet'] ?? null,
            'outlet_name'     => $d['outlet_name'] ?? ($outlet['outlet_name'] ?? null),
            'cashier_name'    => $d['cashier']['username'] ?? null,
            'cashier_id'      => $d['id_cashier'] ?? null,
            'cashier_nip'     => $d['cashier']['nip'] ?? null,

            // Jejak produksi: siapa mengerjakan tahap apa, berapa lama.
            // Dipakai untuk menelusuri complaint hasil cuci sampai ke tahapnya.
            'processes'       => collect($d['services'] ?? [])
                ->flatMap(fn ($service) => collect($service['processes'] ?? [])
                    ->map(fn ($p) => [
                        'stage'        => $p['process_name'] ?? null,
                        'staff_id'     => $p['id_staff'] ?? null,
                        'staff_name'   => $p['staff_name'] ?? null,
                        'staff_nip'    => $p['nip'] ?? null,
                        'status'       => $p['status'] ?? null,
                        'started_at'   => $p['started_at'] ?? null,
                        'completed_at' => $p['completed_at'] ?? null,
                        'duration'     => $p['total_duration'] ?? null,
                        'notes'        => $p['notes'] ?? null,
                    ]))
                ->values()->all(),
            'services'        => collect($d['services'] ?? [])
                ->map(fn ($s) => [
                    'name'     => $s['service']['service_name'] ?? ($s['service_number'] ?? null),
                    'quantity' => $s['quantity'] ?? null,
                    'status'   => $s['status'] ?? null,
                    'notes'    => $s['notes'] ?? null,
                ])->all(),
            'created_at'      => $d['created_at'] ?? null,
            'estimated_done'  => $d['estimated_completion_date'] ?? null,
            'completed_at'    => $d['completion_date'] ?? null,
        ];
    }

    private function url(string $path): string
    {
        return rtrim((string) config('nevira.base_url'), '/').'/'.ltrim($path, '/');
    }
}
