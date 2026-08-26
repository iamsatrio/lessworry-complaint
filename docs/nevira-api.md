# Integrasi NEVIRA POS

Diverifikasi dari koleksi Postman `less-worry BE` dan diuji langsung ke `api.nevira.id` pada 2026-08-26.

## Autentikasi

```
POST /api/login
{ "email": "...", "password": "..." }

200 -> { "access_token": "<JWT>", "user_data": { ... } }
```

Setiap request berikutnya membawa:

```
Authorization: <access_token>
```

**Token dikirim mentah, tanpa awalan `Bearer`.**

Ini bukan detail gaya. Memakai `Authorization: Bearer <token>` membuat server membalas **500 Server Error**, bukan 401 — sehingga mudah disalahartikan sebagai parameter yang kurang. Sudah dikunci oleh test `test_token_dikirim_tanpa_awalan_bearer`.

Token berupa JWT HS256, berlaku **24 jam**, payload berisi `uid`, `iss`, `type`.

Endpoint pendukung: `POST /api/refresh_token`, `GET /api/me`, `POST /api/signout` (khusus signout, token dikirim di header `access_token`, bukan `Authorization`).

## Endpoint yang dipakai sistem complaint

Sistem ini **hanya membaca**. Tidak ada endpoint tulis yang dipanggil.

| Kegunaan | Endpoint |
|---|---|
| Detail order | `GET /api/transactions/{id}` |
| Cari order | `GET /api/transactions?keyword=&limit=` |
| Detail pelanggan | `GET /api/customer/{id}` |
| Cek token hidup | `GET /api/me` |

Catatan: endpoint detail adalah `/api/transactions/{id}` (jamak). Tidak ada `/api/transaction/detail/{id}`.

## Bentuk respons

`GET /api/transactions/{id}` membungkus hasil dalam kunci `data`:

```
data.id_transaction              int
data.transaction_number          string   <- nomor struk
data.order_type                  REGULAR | ...
data.status                      ORDER | PROCESSING | READY_FOR_PICKUP | COMPLETED | VOID | LATE | DEADLINE
data.payment_status              PAID | UNPAID
data.progress_percentage         int
data.subtotal / tax / grand_total
data.pickup_fee / delivery_fee / other_fees
data.estimated_completion_date   ISO8601
data.completion_date             ISO8601 | null
data.id_outlet, data.outlet_name
data.customer { id_customer, customer_name, phone, email, address, city, ... }
data.outlet   { id_outlet, outlet_name, address, phone, city, ... }
data.cashier  { id_user, username, ... }
data.services []  { service_number, quantity, price, total, status, notes, processes[], media[] }
data.payments []  { payment_method, amount, change_amount, payment_proof }
data.media    []  { media_type, media_path, media_purpose }
data.promos   []  { promo_name, promo_type, value_type, value }
```

`GET /api/transactions` dan `GET /api/customer` mengembalikan paginasi Laravel: `{ current_page, data: [...] }`.

`GET /api/customer/{id}` mengembalikan objek langsung, **tanpa** pembungkus `data`.

## Kredensial

Lewat environment variable saja — `NEVIRA_EMAIL`, `NEVIRA_PASSWORD`. Lihat `.env.example`.

Gunakan **service account** khusus integrasi dengan hak baca secukupnya. Jangan memakai akun pribadi: kalau orangnya ganti password, integrasi mati, dan hak aksesnya jauh lebih luas daripada yang dibutuhkan.
