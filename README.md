# Complaint Management Less Worry

Aplikasi web internal untuk mencatat dan menangani keluhan pelanggan Less Worry, terintegrasi dengan NEVIRA POS.

## Status

Aplikasi Laravel yang berjalan. Terhubung ke NEVIRA POS (hanya baca) dan sudah diverifikasi terhadap data produksi.

## Menjalankan secara lokal

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

Seeder membuat tujuh akun tim — akun sungguhan, bukan akun contoh (API-36):

| Peran | Email |
|---|---|
| Admin | `satrio@lessworry.id` |
| Admin | `ghozi@lessworry.id` |
| Admin | `eric@lessworry.id` |
| Supervisor | `tsulasa@lessworry.id` |
| Kasir (outlet Tebet) | `kasir@getnada.com` |
| Divisi Produksi | `produksi@getnada.com` |
| Divisi Kurir | `kurir@getnada.com` |

Tidak ada password di berkas ini dan tidak ada password bawaan. Seeder
mencetak password sementara acak ke layar orang yang menjalankannya, sekali,
lalu tidak menyimpannya di mana pun; semuanya wajib diganti saat login
pertama. Menjalankan seeder ulang tidak menyetel ulang password yang sudah
diganti sendiri.

Tiga akun terakhir dipakai bergantian beberapa orang, jadi alamatnya bukan
alamat pribadi siapa pun. `getnada.com` adalah kotak surat sekali pakai yang
bisa dibaca siapa saja yang tahu alamatnya — cukup untuk mengantar password
sementara saat uji coba, dan **tidak boleh** dipakai sebagai bukti kepemilikan
akun kalau verifikasi email dibangun nanti (API-35).

Akun seeder versi lama (`cc@`, `kasirbaru@`, `samsuri@`, `arifin@`,
`adhyasta@`, `audry@`, dan alamat `kasir@`/`produksi@`/`kurir@` di
`lessworry.id`) dinonaktifkan dan passwordnya dibuang saat seeder dijalankan —
tidak dihapus, supaya jejak audit complaint yang pernah disentuhnya utuh.

## Dokumentasi

- `docs/nevira-api.md` — kontrak integrasi NEVIRA, termasuk jebakan header `Bearer`
- `docs/deploy.md` — daftar periksa sebelum produksi

## Pengujian

```bash
php artisan test
```

## Pelacakan pekerjaan

Dikelola di Multica, workspace Apique Ops, project Less Worry.

- Issue induk: **API-2** — lingkup, pengguna, dan kriteria selesai
- Stage 1 discovery: API-3 (NEVIRA), API-4 (alur complaint)
- Stage 2 desain: API-5, API-6, API-12 (arsitektur & stack), API-13 (peran & hak akses)
- Stage 3 bangun: API-7, API-8, API-9, API-14
- Stage 4 rollout: API-10, API-11, API-15

Keputusan arsitektur final ada di **API-12**. Jangan mulai membangun sebelum keputusan itu tertulis.

## Integrasi NEVIRA

Base URL: `https://api.nevira.id/api`. Autentikasi Laravel Sanctum — `POST /login` mengembalikan bearer token, dipakai di header `Authorization: Bearer <token>`.

Detail endpoint dan parameter ada di komentar issue API-3.

**Aturan keras: sistem ini hanya membaca dari NEVIRA.** Tidak menulis, tidak mengubah, tidak menghapus data POS.

## Kredensial

Semua kredensial lewat environment variable. Salin `.env.example` menjadi `.env` dan isi nilainya secara lokal.

`.env` tidak pernah di-commit. Jangan menaruh token, password, atau API key di dalam kode, README, atau komentar issue.
