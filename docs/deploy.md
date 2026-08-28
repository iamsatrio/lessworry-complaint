# Menjalankan di produksi

Daftar periksa sebelum sistem ini menyentuh data pelanggan sungguhan.

## Wajib — jangan lewati satu pun

- [ ] `APP_ENV=production` dan `APP_DEBUG=false`.
      `APP_DEBUG=true` menampilkan isi variabel dan potongan kode ke siapa pun
      yang memicu error, termasuk kredensial NEVIRA.
- [ ] `php artisan key:generate` di server produksi. Jangan menyalin `APP_KEY`
      dari mesin pengembang — kunci itu yang mengenkripsi sesi.
- [ ] `APP_URL` diisi domain sungguhan, HTTPS aktif.
- [ ] Pindah dari SQLite ke MySQL atau PostgreSQL. Tidak ada kode yang terikat
      SQLite; cukup ubah `DB_*` lalu `php artisan migrate`.
- [ ] Ganti password seluruh akun contoh, atau jangan jalankan seeder sama
      sekali di produksi. Akun demo memakai password `password`.
- [ ] `SESSION_SECURE_COOKIE=true` supaya cookie sesi tidak pernah lewat HTTP.
- [ ] Kredensial NEVIRA diisi dari service account, bukan akun pribadi.
- [ ] Izin tulis untuk `storage/` dan `bootstrap/cache/`.
      Foto bukti disimpan di disk privat (`storage/app/private`) dan disajikan
      lewat rute yang memeriksa wewenang — `storage:link` TIDAK diperlukan
      untuknya, dan symlink publik justru pernah jadi celahnya.
- [ ] Ekstensi PHP `gd` aktif. Kompresi foto memakainya: tanpa gd, foto tetap
      tersimpan tapi apa adanya — ukuran penuh berikut EXIF-nya, dan EXIF
      ponsel memuat koordinat GPS.
- [ ] Batas unggahan server disamakan dengan batas aplikasi (8 MB per foto):
      `upload_max_filesize` dan `post_max_size` minimal `10M` di PHP, dan
      `client_max_body_size 10m;` di nginx. Kalau servernya menolak lebih dulu,
      petugas menerima 413 tanpa penjelasan alih-alih pesan dari aplikasi.

## Pengaturan sesi

Nilai bawaan sudah disetel untuk perangkat outlet yang dipakai bergantian:

```
SESSION_LIFETIME=30
SESSION_EXPIRE_ON_CLOSE=true
SESSION_ENCRYPT=true
```

Jangan dipanjangkan tanpa alasan. Perangkat kasir berpindah tangan, dan sesi
yang menganggur adalah akun yang menganggur.

## Backup

**Backup yang belum pernah diuji pulih bukan backup, itu asumsi.**

- [ ] Backup database terjadwal otomatis, harian.
- [ ] Backup folder `storage/app/public` — foto bukti complaint ada di sana,
      dan tidak ikut kalau hanya database yang dicadangkan.
- [ ] Simpan salinan di luar server aplikasi.
- [ ] **Lakukan satu kali uji pemulihan** ke lingkungan terpisah, dan catat
      tanggalnya. Ulangi tiap kuartal.

## Optimasi

```bash
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Jalankan ulang tiap kali `.env` atau config berubah — kalau tidak, perubahan
tidak terbaca.

## Pemantauan

- [ ] Pemberitahuan saat aplikasi mati.
- [ ] Pemberitahuan saat `nevira_sync_error` melonjak — pertanda integrasi
      NEVIRA putus, dan complaint mulai kehilangan tautan ordernya.
- [ ] Rotasi log supaya `storage/logs` tidak menghabiskan disk.

## Setelah rilis

- [ ] Buat akun sungguhan lewat halaman **Pengguna**. Setiap akun baru dapat
      password sementara dan wajib menggantinya saat pertama masuk.
- [ ] Nonaktifkan akun contoh.
- [ ] Tetapkan siapa pemilik teknis sistem ini — satu orang yang bisa
      memperbaiki saat rusak di luar jam kerja.
