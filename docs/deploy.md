# Menjalankan di produksi

Daftar periksa sebelum sistem ini menyentuh data pelanggan sungguhan.

## Wajib — jangan lewati satu pun

- [ ] `APP_ENV=production` dan `APP_DEBUG=false`.
      Di server percobaan pakai `APP_ENV=staging`: setiap halaman memunculkan
      pita "Lingkungan uji" supaya data uji tidak dikira data pelanggan.
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
- [ ] **`NEVIRA_ENABLED=true`.** Kalau `false`, `/health` membalas
      `nevira: disabled` dan tetap 200 — pemantau tetap hijau walau integrasi
      POS mati total, dan complaint diam-diam kehilangan tautan ordernya.
- [ ] `HEALTH_CACHE_STORE` **bukan** `database`. Store bawaan produksi adalah
      `database`; kalau `/health` ikut memakainya, database yang mati membuat
      `/health` ikut mati — pemadaman yang paling perlu dibedakan justru yang
      membuatnya bisu.
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

Sudah ada di dalam aplikasi (API-27):

```bash
php artisan backup:database    # dump terkompresi + rotasi 7 hari
php artisan backup:verify      # pulihkan dump terakhir, hitung baris complaints
```

`backup:database` sudah terdaftar di penjadwal Laravel, harian pukul 02.00.
Yang perlu ditambahkan di server hanyalah satu baris crontab:

```cron
* * * * * cd /var/www/care && php artisan schedule:run >> /dev/null 2>&1
```

- [ ] Baris `schedule:run` di atas terpasang, dan sudah dibuktikan dengan
      melihat berkas baru muncul keesokan harinya.
- [ ] `BACKUP_PATH` diarahkan ke direktori yang **hanya** dipakai backup —
      rotasi menghapus isi direktori itu.
- [ ] Backup folder `storage/app/private` — foto bukti complaint ada di sana,
      dan **tidak** ikut dalam dump database.
- [ ] Simpan salinan di luar server aplikasi. Backup yang duduk di mesin yang
      sama akan ikut hilang bersama mesinnya.
- [ ] **Jalankan `backup:verify` satu kali setelah pasang**, dan catat
      tanggalnya. Ulangi tiap kuartal.
- [ ] `BACKUP_VERIFY_CONNECTION` diisi, dengan pengguna database tersendiri
      yang **tidak punya hak apa pun di database produksi**. Tanpa itu
      `backup:verify` menolak berjalan di MySQL — disengaja. Perintah itu
      memulihkan berkas yang isinya tidak dipercaya, dan yang menahannya
      menulis ke produksi adalah hak akses, bukan pembacaan isi dumpnya
      (`--one-database` hanya mengikuti `USE`; `INSERT INTO produksi.tabel`
      lewat begitu saja). Caranya di `deploy-care-lessworry.md`.
- [ ] Pengguna yang dipakai proses web **tidak** punya `CREATE`/`DROP DATABASE`.

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

`GET /health` — terbuka tanpa autentikasi, tanpa membocorkan apa pun:

```
200  {"status":"ok","checks":{"database":"ok","nevira":"ok","storage":"ok"}}
503  ada yang tidak "ok"
```

Yang dibaca pemantau adalah **kode statusnya**; isi JSON untuk manusia yang
menyusul. Hasil pemeriksaan NEVIRA disimpan 60 detik, jadi pemantau yang
memanggil tiap menit tidak menambah beban ke NEVIRA — termasuk saat NEVIRA
sedang mati.

- [ ] Pemantau apa pun (UptimeRobot, Healthchecks, curl di cron) menembak
      `https://care.lessworry.id/health` tiap menit dan memberi tahu saat
      jawabannya bukan 200.
- [ ] Pemberitahuan saat `nevira_sync_error` melonjak — pertanda integrasi
      NEVIRA putus, dan complaint mulai kehilangan tautan ordernya.
- [ ] Pemberitahuan saat `nevira_sync_error` melonjak — pertanda integrasi
      NEVIRA putus, dan complaint mulai kehilangan tautan ordernya.
- [ ] Rotasi log supaya `storage/logs` tidak menghabiskan disk.

## Setelah rilis

- [ ] Buat akun sungguhan lewat halaman **Pengguna**. Setiap akun baru dapat
      password sementara dan wajib menggantinya saat pertama masuk.
- [ ] Nonaktifkan akun contoh.
- [ ] Tetapkan siapa pemilik teknis sistem ini — satu orang yang bisa
      memperbaiki saat rusak di luar jam kerja.
