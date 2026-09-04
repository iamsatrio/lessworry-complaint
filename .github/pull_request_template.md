## Apa yang berubah dan kenapa

<!-- Bukan daftar berkas. Satu alasan berubah per PR. -->

## Pemeriksaan yang sudah dijalankan sendiri

<!-- CI menjalankan ini juga, tapi reviewer tidak meloloskan apa pun berdasarkan klaim. -->

- [ ] `vendor/bin/pint --test`
- [ ] `vendor/bin/phpstan analyse --memory-limit=1G`
- [ ] `php artisan test`

## Kalau ini perbaikan bug

- [ ] Ada test yang **gagal sebelum** perbaikannya dan lulus sesudahnya. Sebutkan nama test-nya:

## Jalur gagal

<!-- Apa yang terjadi saat jaringan mati, data tidak ada, dua orang menyimpan bersamaan? -->

## Yang perlu diperhatikan reviewer

<!-- Bagian yang paling berisiko, keputusan yang butuh alasan, komentar KENAPA yang sengaja dipertahankan. -->

<!-- Aturan lengkap: CONTRIBUTING.md -->
