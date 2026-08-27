<?php

namespace App\Services;

use App\Exceptions\NeviraNotFound;
use App\Exceptions\NeviraRequestFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            throw new NeviraRequestFailed(
                'Kredensial NEVIRA belum diisi. Set NEVIRA_EMAIL dan NEVIRA_PASSWORD di .env',
                'Integrasi NEVIRA belum dikonfigurasi.'
            );
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
            throw new NeviraRequestFailed(
                'Login NEVIRA gagal (HTTP '.$response->status().')',
                'Tidak bisa masuk ke NEVIRA saat ini.'
            );
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new NeviraRequestFailed(
                'Login NEVIRA tidak mengembalikan access_token',
                'Tidak bisa masuk ke NEVIRA saat ini.'
            );
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
            // Detail teknis hanya ke log — tidak pernah ke layar. $path memuat
            // id internal transaksi, dan pesan mentah pernah bocor apa adanya
            // lewat nevira_sync_error. Query sengaja tidak ikut dicatat: di
            // situ ada nomor nota pelanggan. (API-8 T3)
            Log::warning('NEVIRA request gagal', [
                'path'   => $path,
                'status' => $response->status(),
            ]);

            throw new NeviraRequestFailed(
                'NEVIRA '.$path.' balas HTTP '.$response->status(),
                'NEVIRA membalas HTTP '.$response->status().'. Complaint tetap tersimpan; coba tarik ulang setelah NEVIRA pulih.'
            );
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
     * Ambil transaksi dari apa pun yang dipegang petugas.
     *
     * Yang tercetak di struk pelanggan adalah nomor nota
     * (mis. INV/118/1787749345365/1), sedangkan endpoint detail menuntut
     * id_transaction numerik. Memasukkan nomor nota ke endpoint detail
     * membalas 404 "Url Not Found" — terbaca seperti order tidak ada,
     * padahal formatnya yang salah.
     *
     * Karena itu masukan non-numerik dicari dulu lewat keyword untuk
     * mendapat id_transaction, baru detailnya diambil.
     *
     * Hanya menerima nomor nota yang COCOK PERSIS. Pencarian sebagian
     * ditolak: endpoint ini bukan alat menyisir basis data NEVIRA.
     *
     * @return array{id:string,number:?string,payload:array}
     */
    public function resolveTransaction(string $input): array
    {
        $input = trim($input);

        if ($input === '') {
            throw new NeviraNotFound('Nomor nota kosong.');
        }

        if (ctype_digit($input)) {
            $payload = $this->transaction($input);
            $data = $payload['data'] ?? [];

            return [
                'id'      => $input,
                'number'  => $data['transaction_number'] ?? null,
                'payload' => $payload,
            ];
        }

        $matches = $this->searchTransactions($input, 10);

        // Wajib cocok persis. Pencarian sebagian akan mengembalikan order
        // milik pelanggan lain yang kebetulan mirip, dan itu membuat
        // endpoint ini bisa dipakai memancing data.
        $exact = collect($matches)->firstWhere('transaction_number', $input);

        if (! $exact) {
            throw new NeviraNotFound('Nota "'.$input.'" tidak ditemukan di NEVIRA.');
        }

        $id = (string) ($exact['id_transaction'] ?? '');

        if ($id === '') {
            throw new NeviraNotFound('NEVIRA menemukan nota itu tapi tidak menyertakan id_transaction.');
        }

        return ['id' => $id, 'number' => $exact['transaction_number'] ?? $input, 'payload' => $this->transaction($id)];
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

    /**
     * Daftar outlet NEVIRA. Dipakai untuk memetakan outlet di sistem ini
     * ke id outlet NEVIRA, supaya complaint bisa menentukan outletnya
     * sendiri dari nota.
     */
    public function outlets(int $limit = 200): array
    {
        $payload = $this->get('/outlet', ['limit' => $limit]);

        return $payload['data'] ?? $payload;
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
            // id_transaction sengaja TIDAK ikut: itu pengenal internal
            // NEVIRA dan tidak punya keperluan di sisi tampilan.
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
