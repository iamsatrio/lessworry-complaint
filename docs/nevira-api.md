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
| Perjalanan kurir | `GET /api/deliveries-transactions?keyword=<nomor_nota>` |
| Daftar outlet | `GET /api/outlet?limit=` |
| Karyawan satu outlet | `GET /api/user/by-outlet/{id_outlet}` |

Detail transaksi memang memuat `delivery_transactions`, tapi **tanpa objek kurirnya** — hanya `id_user_courier`. Daftar pengantaran yang disaring dengan nomor nota mengembalikan baris lengkap dengan `courier{username,nip,phone}`, jadi itu yang dipakai.

## Kode status pengantaran

Diambil dari peta di back office NEVIRA sendiri, bukan tebakan:

| Kode | Arti |
|---|---|
| 1 | Siap Diantar |
| 2 | Diantar |
| 3 | Siap Dijemput |
| 4 | Dijemput |
| 5 | Tiba di Outlet |
| 6 | Batal |
| 7 | Selesai |
| 71 | Selesai Diantar |
| 73 | Selesai Dijemput |

Saat status 6, `cancel_type` menjelaskan sebabnya: `SYSTEM`, `COURIER`, `COURIER_RESCHEDULE`.

Kolom `type` (1 atau 2) **belum diketahui artinya** — peta labelnya tidak ditemukan di bundle back office, jadi sengaja tidak ditampilkan daripada ditebak.

Catatan: endpoint detail adalah `/api/transactions/{id}` (jamak). Tidak ada `/api/transaction/detail/{id}`.

### Nomor nota bukan id_transaction

Yang tercetak di struk pelanggan adalah **nomor nota** (`transaction_number`), mis. `INV/118/1787749345365/1`. Endpoint detail hanya menerima **`id_transaction` numerik**.

Memasukkan nomor nota ke endpoint detail membalas:

```
GET /api/transactions/INV%2F118%2F1787749345365%2F1
404 {"msg":"Url Not Found","code":404}
```

404 itu terbaca seperti "order tidak ada", padahal ordernya ada — formatnya yang salah.

Jalan yang benar untuk nomor nota:

```
GET /api/transactions?keyword=INV/118/1787749345365/1   -> data[0].id_transaction
GET /api/transactions/31242                             -> detail
```

`NeviraClient::resolveTransaction()` menerima keduanya: masukan numerik langsung ke detail, masukan non-numerik dicari dulu. Id numerik hasil pencarian disimpan supaya penarikan berikutnya tidak mencari ulang.

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

### Satu nota bisa berisi banyak baris layanan

`data.services` adalah DAFTAR, dan tiap barisnya punya `processes[]` sendiri. Nota 31033 berisi
sepuluh `Bedding - Sprei (King)` — sepuluh baris, sepuluh rantai pengerjaan, bukan satu barang
yang dikerjakan sepuluh kali.

Karena itu `summarizeTransaction()` menyimpan penandanya, dan snapshot complaint membawanya:

```
services[].index            int          <- nomor urut baris pada nota, mulai 1
services[].name             string|null  <- services[].service.service_name, BELUM TERVERIFIKASI
services[].code             string|null  <- services[].service_number apa adanya
processes[].service_index   int          <- baris layanan yang dikerjakan proses ini
processes[].service_name    string|null  <- namanya, untuk judul kelompok di halaman complaint
```

**`services[].service.service_name` belum pernah diverifikasi terhadap respons sungguhan.**
Tabel bentuk respons di atas hanya menyebut `service_number`, tanpa menyebut isinya. Karena itu
`name` dan `code` disimpan TERPISAH dan `name` **tidak** jatuh ke `service_number`: cadangan diam-diam
ke sana membuat kode seperti `4471` lewat sebagai nama layanan di layar kasir.

Kalau namanya tidak ada, sebutan barisnya jatuh ke nomor urutnya — `Barang ke-3 dari 10`, di pemilih
intake `Barang ke-3 · 1 pcs` — yang masih bisa dicocokkan orang dengan struk di tangannya. Kodenya
tetap ditampilkan, tapi sebagai keterangan, bukan sebagai identitas barang.

Complaint boleh menunjuk satu baris lewat kolom `nevira_service_index` (null = seluruh nota).
Snapshot yang tersimpan sebelum API-51 tidak punya penanda ini; halaman complaint
membacanya sebagai satu kelompok tanpa judul, persis seperti sebelumnya.

Semua itu bisa diukur sendiri di lingkungan yang punya service account, tanpa menyentuh data:

```
php artisan nevira:hitung-layanan --jumlah=40
```

Perintah itu menjawab empat hal sekaligus, dari respons sungguhan: berapa nota yang berisi lebih dari
satu baris, apakah `service.service_name` ada, apa isi `service_number` kalau tidak, dan berapa nilai
unik yang bisa dipetakan ke enam nilai `config('complaint.layanan')`. Hanya membaca, dan yang dicetak
hanya angka, nama layanan, dan kode layanan — bukan nomor nota, nama pelanggan, atau nama karyawan.

`GET /api/transactions` dan `GET /api/customer` mengembalikan paginasi Laravel: `{ current_page, data: [...] }`.

`GET /api/customer/{id}` mengembalikan objek langsung, **tanpa** pembungkus `data`.

`GET /api/user/by-outlet/{id_outlet}` mengembalikan daftar karyawan outlet itu di dalam kunci `data`
(diuji ke outlet Tebet, id 118: 39 baris):

```
data[].id_user      int
data[].username     string   <- nama yang dipakai NEVIRA
data[].nip          string
data[].id_role      int      <- pemetaan angka ke nama peran BELUM diketahui
data[].status       int      <- 0 = nonaktif, dibuang sebelum ditawarkan
```

Dipakai untuk memilih pelaku complaint dari daftar alih-alih mengetik nama (API-19). Ditarik lewat
`NeviraGate::outletStaff()` — jatah panggilannya sama dengan panggilan NEVIRA lain, dan hasilnya
disimpan sebentar (`NEVIRA_OUTLET_STAFF_TTL`, standar 10 menit) supaya membuka halaman complaint
berkali-kali tidak menghabiskan jatah itu.

## Kredensial

Lewat environment variable saja — `NEVIRA_EMAIL`, `NEVIRA_PASSWORD`. Lihat `.env.example`.

Gunakan **service account** khusus integrasi dengan hak baca secukupnya. Jangan memakai akun pribadi: kalau orangnya ganti password, integrasi mati, dan hak aksesnya jauh lebih luas daripada yang dibutuhkan.


## Yang tidak boleh keluar dari server

`id_transaction` adalah pengenal internal basis data NEVIRA. Nilainya **tidak pernah** dikirim ke browser, tidak ditampilkan di halaman mana pun, dan tidak disimpan di `nevira_snapshot`.

Yang jadi pegangan petugas — dan satu-satunya yang tampil — adalah **nomor nota** (`transaction_number`), karena itu yang tercetak di struk pelanggan.

Di basis data complaint keduanya dipisah:

| Kolom | Isi | Boleh tampil |
|---|---|---|
| `nevira_transaction_number` | Nomor nota dari struk | ya |
| `nevira_transaction_id` | Id internal NEVIRA | **tidak** |

Endpoint `/nevira/lookup` juga hanya menerima nomor nota yang **cocok persis**. Pencarian sebagian ditolak: dulu mengetik `INV` saja mengembalikan order pelanggan mana pun, sehingga endpoint itu praktis jadi alat menyisir basis data NEVIRA.
