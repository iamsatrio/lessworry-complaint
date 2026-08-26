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

Akun contoh (hanya untuk pengembangan, password `password`):

| Peran | Email |
|---|---|
| Supervisor | `satrio@lessworry.id` |
| Customer Care | `cc@lessworry.id` |
| Kasir | `kasir@lessworry.id` |
| Divisi Produksi | `produksi@lessworry.id` |

`kasirbaru@lessworry.id` sengaja masih memegang password sementara, untuk melihat alur wajib-ganti-password.

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
