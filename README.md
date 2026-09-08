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

Seeder membuat lima akun tim — akun sungguhan, bukan akun contoh (API-36,
API-45, API-50):

| Peran | Email |
|---|---|
| Admin | `satrio@lessworry.id` |
| Admin | `ghozi@lessworry.id` |
| Admin | `eric@lessworry.id` |
| Admin | `tsulasa@lessworry.id` |
| Customer Care (seluruh outlet) | `care@lessworry.id` |

Tidak ada password di berkas ini dan tidak ada password bawaan. Seeder
mencetak password sementara acak ke layar orang yang menjalankannya, sekali,
lalu tidak menyimpannya di mana pun; semuanya wajib diganti saat login
pertama. Menjalankan seeder ulang tidak menyetel ulang password yang sudah
diganti sendiri.

Semua akun awal beralamat `@lessworry.id`, dan itu syarat: kotak suratnya ada
di Google Workspace Less Worry, jadi tautan verifikasi email (API-35) yang
sampai ke sana benar-benar membuktikan kepemilikan akun. Verifikasi berlaku
penuh untuk kelimanya, tanpa pengecualian.

Akun bersama Kasir, Produksi, dan Kurir sempat diseed dengan alamat
`getnada.com` — kotak surat publik yang bisa dibaca siapa saja yang tahu
alamatnya. Ketiganya dibuang di API-50. Yang dibuang **akunnya, bukan
perannya**: `kasir`, `divisi`, dan `supervisor` tetap bisa dipilih di halaman
Pengguna, dan akun sungguhannya dibuat Admin dari sana dengan alamat kerja
masing-masing.

`care@lessworry.id` adalah akun peran, bukan akun perorangan: riwayat complaint
mencatat "Customer Care" yang menutup tiket, bukan siapa orangnya. Begitu peran
ini dipegang dua orang atau lebih, akun perorangan jadi perlu (dicatat di
API-45). Alamat lama `cc@lessworry.id` tidak dipakai ulang dan tetap
dinonaktifkan.

Akun seeder versi lama (`cc@`, `kasirbaru@`, `samsuri@`, `arifin@`,
`adhyasta@`, `audry@`, alamat `kasir@`/`produksi@`/`kurir@` di `lessworry.id`,
dan ketiga alamat `getnada.com`) dinonaktifkan dan passwordnya dibuang saat
seeder dijalankan — tidak dihapus, supaya jejak audit complaint yang pernah
disentuhnya utuh.

## Memperbarui salinan yang sudah ada

`git pull` saja **tidak cukup**. Yang berubah bukan cuma berkas kode: ada
migrasi baru, kunci `.env` baru, dan versi paket baru yang semuanya perlu
langkah sendiri. Urutan lengkapnya:

```bash
git pull                    # ambil kode terbaru
composer install            # samakan paket dengan composer.lock
php artisan migrate         # jalankan migrasi baru
php artisan config:clear    # buang cache konfigurasi lama
```

Kenapa tiga perintah setelah `git pull` perlu:

| Perintah | Kalau dilewat |
|---|---|
| `composer install` | `composer.lock` ikut berubah saat paket ditambah (Larastan, misalnya). Tanpa ini: "class not found" atau versi paket tidak cocok. |
| `php artisan migrate` | Tabel dan kolom baru belum ada. Halaman yang memakainya balas **500**, dan pesannya tidak menyebut migrasi. |
| `php artisan config:clear` | Kalau konfigurasi pernah di-cache, perubahan di `config/` tidak berlaku sampai cache dibuang. Aman dijalankan walau belum pernah di-cache. |

Tidak perlu `npm install`. Seluruh CSS ada di dalam blade; `@vite` cuma dipakai
`resources/views/welcome.blade.php`, dan halaman itu tidak punya route — `/`
langsung diarahkan ke `/dashboard`.

### Kunci `.env` yang tertinggal

Ini yang paling sering luput. `.env` diabaikan git, jadi kunci baru yang
ditambahkan ke `.env.example` **tidak pernah sampai** ke `.env` siapa pun.
Tidak ada yang memberi tahu — yang muncul cuma galat di tempat yang kelihatan
tidak berhubungan (`/health` gagal, perintah backup gagal, email tidak
terkirim).

Cari selisihnya:

```bash
comm -23 <(grep -E '^[A-Z_]+=' .env.example | cut -d= -f1 | sort) \
         <(grep -E '^[A-Z_]+=' .env | cut -d= -f1 | sort)
```

Yang keluar adalah kunci yang ada di `.env.example` tapi belum ada di `.env`.
Salin nilainya dari `.env.example`, lalu isi yang perlu diisi. Kalau tidak ada
keluaran sama sekali, `.env` sudah lengkap.

Butuh bash atau zsh (`<(...)` tidak jalan di `sh`). Jalankan dari akar
repositori.

### Kapan `migrate:fresh --seed` dipakai

```bash
php artisan migrate:fresh --seed
```

Perintah ini **menghapus seluruh isi database** lalu membangunnya dari nol.
Pakai hanya kalau datanya memang boleh hilang — mesin pengembangan sendiri,
atau saat migrasi lokal sudah kadung berantakan dan lebih cepat mulai ulang.

> **Jangan pernah di produksi.** Data complaint sungguhan tidak bisa
> dikembalikan dengan `git`. Urutan pembaruan untuk server produksi ada di
> **API-34**, dan langkah deploy lengkapnya di
> `docs/deploy-care-lessworry.md`.

## Dokumentasi

- `docs/nevira-api.md` — kontrak integrasi NEVIRA, termasuk jebakan header `Bearer`
- `docs/deploy.md` — daftar periksa sebelum produksi
- `docs/deploy-care-lessworry.md` — rujukan produksi: langkah deploy dan pembaruan di `care.lessworry.id`

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
