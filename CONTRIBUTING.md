# Cara kerja di repositori ini

Sampai commit ke-46, semua kode masuk `main` tanpa satu pun pull request. Dokumen ini menutup pintu itu. Isinya bukan pendapat yang berubah tiap review — ini yang dirujuk saat sebuah PR ditolak.

## Alur

1. Kerjakan di branch, bukan di `main`.
2. Buka pull request.
3. Maldini meninjau. Lolos → di-merge squash. Tidak lolos → dikembalikan dengan berkas, baris, dan bentuk yang benar.
4. Hanya Maldini yang merge ke `main`.

Buffon memvonis benar-tidaknya perilaku. Review di sini menilai cara membangunnya. Keduanya masukan berbeda; satu tidak menggantikan yang lain.

## Sebelum membuka PR

Jalankan sendiri. Ini persis yang dijalankan CI:

```bash
composer install
cp .env.example .env && php artisan key:generate
vendor/bin/pint --test                                    # gaya kode
vendor/bin/phpstan analyse --memory-limit=1G              # Larastan level 5
php artisan test                                          # seluruh suite
```

Butuh PHP **8.4** atau lebih baru. `composer.json` menulis `"php": "^8.3"`, tapi `composer.lock` mengunci symfony 8.1.5 yang menuntut `php >= 8.4.1` — di 8.3 `composer install` gagal sebelum satu test pun jalan.

Pint memperbaiki sendiri: `vendor/bin/pint`.

## Yang membuat PR ditolak — menghalangi

Satu saja cukup untuk menahan merge. Tidak ada pengecualian karena "cuma masalah gaya" atau "sedang buru-buru".

**Pemeriksaan mesin**

- `vendor/bin/pint --test` gagal.
- `vendor/bin/phpstan analyse` gagal.
- `php artisan test` gagal, atau ada test yang di-skip tanpa alasan tertulis.
- Test dihapus atau dilonggarkan tanpa penggantinya yang membuktikan hal yang sama.

**Khusus repositori ini** — semua ini pernah bocor di sini:

- Controller memegang `NeviraClient` langsung. Semua akses NEVIRA lewat satu gerbang; `tests/Feature/NeviraChokePointTest.php` menjaganya dan harus tetap ada serta lulus.
- Token NEVIRA dikirim lewat `withToken()`. Token itu dikirim mentah tanpa `Bearer`; salah kirim membuat NEVIRA membalas 500, bukan 401, dan itu menyesatkan berjam-jam.
- Id internal NEVIRA sampai ke browser — termasuk lewat ekspor CSV dan pesan error.
- Lampiran pindah dari disk privat.
- Pengaman supervisor aktif terakhir bisa dilucuti lewat cara apa pun, termasuk pencabutan privilege.
- Kredensial masuk kode, komentar, atau log.
- Data pribadi pelanggan masuk log aplikasi.

**Batas lapisan**

- Wewenang di Policy, bukan `abort_unless` yang bertebaran di controller.
- Validasi di FormRequest, bukan blok panjang di controller.
- Urusan luar (HTTP, disk, antrian) di Service, bukan dipanggil controller langsung.

**Komentar yang menjelaskan KENAPA tidak boleh ikut terhapus saat merapikan.** Beberapa di antaranya menahan orang berikutnya mengulang kesalahan yang sudah pernah terjadi. Komentar yang hanya mengulang kode boleh hilang; yang menjelaskan alasan sebuah keputusan aneh tidak.

**Nama yang berbohong.** `simpanFoto` yang diam-diam juga mengubah status adalah cacat, bukan gaya.

**Perbaikan bug tanpa test yang gagal sebelum perbaikannya.** Test yang hanya menyentuh jalur sukses tidak membuktikan apa pun tentang bug itu.

**Jalur gagal tidak dijawab.** Apa yang terjadi saat jaringan mati, data tidak ada, dua orang menyimpan bersamaan? Jalur gagal paling jarang diuji dan paling sering dipakai.

## Yang dicatat tapi tidak menahan merge

Ditandai **sebaiknya diperbaiki** — boleh merge, tapi harus ada issue lanjutannya:

- Method di atas ~40 baris atau controller di atas ~250 baris. Panduan, bukan hukum. Kalau memecahnya membuat lebih sulit dibaca, jangan dipecah — tulis alasannya di PR.
- Duplikasi yang menandakan konsep hilang: tiga tempat menulis jejak audit dengan caranya sendiri berarti ada konsep yang belum diberi nama. Dua baris mirip yang kebetulan sama bukan duplikasi; jangan memaksakan abstraksi terlalu dini.
- Kejelasan yang bisa lebih baik tanpa mengubah perilaku.

Ditandai **catatan**: pendapat, silakan diabaikan.

Reviewer yang menjadikan semua hal penghalang akan diabaikan. Yang tidak pernah menghalangi apa pun tidak ada gunanya.

## Bentuk PR

- Satu alasan berubah per PR. PR yang mencampur refactor dan perbaikan bug sulit ditinjau dan sulit di-revert.
- Deskripsi menyebut apa yang berubah dan kenapa, bukan daftar berkas.
- Klaim di deskripsi PR tidak menggantikan pemeriksaan. Reviewer menjalankan sendiri.
- Merge memakai squash: satu perubahan jadi satu commit di `main`.

## Aturan yang tidak tertulis di sini tidak berlaku

Kalau sebuah tuntutan muncul berulang di review, tempatnya di dokumen ini atau di `pint.json`/`phpstan.neon` — bukan jadi pendapat yang muncul lagi di review berikutnya.
