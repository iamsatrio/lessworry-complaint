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
 * Autentikasi: POST /login mengembalikan Sanctum bearer token, dipakai di
 * header Authorization. Token di-cache supaya tidak login tiap request.
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

    /**
     * Ambil bearer token, login kalau cache kosong.
     */
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
     * GET terautentikasi. Sekali retry saat 401 (token kedaluwarsa).
     */
    private function get(string $path, array $query = []): array
    {
        $attempt = fn (string $token) => Http::timeout(config('nevira.timeout'))
            ->acceptJson()
            ->withToken($token)
            ->get($this->url($path), $query);

        $response = $attempt($this->token());

        if ($response->status() === 401) {
            $response = $attempt($this->token(forceRefresh: true));
        }

        if (! $response->successful()) {
            throw new RuntimeException('NEVIRA '.$path.' balas HTTP '.$response->status());
        }

        return (array) $response->json();
    }

    /**
     * Detail satu transaksi NEVIRA — dasar penautan complaint ke order.
     */
    public function transaction(string $transactionId): array
    {
        return $this->get('/transaction/detail/'.rawurlencode($transactionId));
    }

    public function customer(string $customerId): array
    {
        return $this->get('/customer/'.rawurlencode($customerId));
    }

    /**
     * Ringkas payload NEVIRA jadi bentuk yang dipakai tampilan complaint.
     *
     * Struktur respons NEVIRA belum sepenuhnya terdokumentasi (lihat API-3 —
     * /transactions masih balas 500 tanpa parameter yang tepat), jadi
     * pemetaan ini defensif: setiap field dicari di beberapa kemungkinan nama
     * dan kembali null kalau tidak ada.
     */
    public function summarizeTransaction(array $payload): array
    {
        $data = $payload['data'] ?? $payload;

        if (isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        return [
            'transaction_id' => $this->pick($data, ['id_transaction', 'id_transaksi', 'id', 'transaction_id']),
            'invoice'        => $this->pick($data, ['invoice', 'no_invoice', 'nota', 'no_nota', 'code']),
            'customer_name'  => $this->pick($data, ['customer_name', 'nama_customer', 'nama_pelanggan', 'name']),
            'customer_id'    => $this->pick($data, ['id_customer', 'customer_id']),
            'customer_phone' => $this->pick($data, ['phone', 'no_hp', 'telepon', 'customer_phone']),
            'outlet_name'    => $this->pick($data, ['outlet_name', 'nama_outlet', 'outlet']),
            'outlet_id'      => $this->pick($data, ['id_outlet', 'outlet_id']),
            'total'          => $this->pick($data, ['grand_total', 'total', 'total_bayar']),
            'status'         => $this->pick($data, ['status', 'status_transaksi', 'transaction_status']),
            'created_at'     => $this->pick($data, ['created_at', 'tanggal', 'date']),
        ];
    }

    private function pick(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('nevira.base_url'), '/').'/'.ltrim($path, '/');
    }
}
