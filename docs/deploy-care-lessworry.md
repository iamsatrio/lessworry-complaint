# Deploy ke care.lessworry.id

Panduan langkah demi langkah lewat SSH. Semua perintah dijalankan di server, kecuali disebut lain.

Asumsi: Ubuntu/Debian, Nginx, PHP 8.4, MySQL. Sesuaikan kalau berbeda.

> **Jalankan di server milikmu.** Panduan ini tidak menjalankan apa pun dari sisi Multica — agent tidak punya, dan tidak boleh punya, akses SSH ke server produksimu.

## 0. Sebelum mulai — cek prasyarat

```bash
ssh user@care.lessworry.id

php -v                 # butuh 8.2+
composer -V
mysql --version
nginx -v
git --version
```

PHP butuh ekstensi ini: `bcmath curl dom fileinfo mbstring openssl pcre pdo pdo_mysql tokenizer xml zip`

```bash
php -m | tr 'A-Z' 'a-z' | sort > /tmp/ada.txt
for e in bcmath curl dom fileinfo mbstring openssl pdo_mysql tokenizer xml zip; do
  grep -qx "$e" /tmp/ada.txt || echo "KURANG: $e"
done
```

## 1. Akses repositori dari server

Repositori privat, jadi server perlu kunci sendiri. **Pakai deploy key, jangan menyalin kunci SSH pribadimu** — deploy key hanya berlaku untuk satu repo, dan bisa dicabut tanpa mengganggu yang lain.

```bash
ssh-keygen -t ed25519 -C "care.lessworry.id deploy" -f ~/.ssh/lessworry_deploy -N ""
cat ~/.ssh/lessworry_deploy.pub
```

Salin isinya ke GitHub: repo `iamsatrio/lessworry-complaint` → **Settings → Deploy keys → Add deploy key**. Centang **Allow write access** hanya kalau server perlu push (untuk deploy biasa: **jangan**).

```bash
cat >> ~/.ssh/config <<'CFG'
Host github-lessworry
  HostName github.com
  User git
  IdentityFile ~/.ssh/lessworry_deploy
  IdentitiesOnly yes
CFG
chmod 600 ~/.ssh/config

ssh -T github-lessworry     # harus menyapa namamu, bukan minta password
```

## 2. Ambil kode

```bash
sudo mkdir -p /var/www
sudo chown $USER:$USER /var/www
cd /var/www
git clone github-lessworry:iamsatrio/lessworry-complaint.git care
cd care
git log --oneline -1     # pastikan commit-nya sesuai
```

## 3. Database

```bash
sudo mysql -e "CREATE DATABASE lessworry_care CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'care'@'localhost' IDENTIFIED BY 'GANTI_DENGAN_PASSWORD_KUAT';"
sudo mysql -e "GRANT ALL PRIVILEGES ON lessworry_care.* TO 'care'@'localhost'; FLUSH PRIVILEGES;"
```

Buat passwordnya dengan `openssl rand -base64 24`, jangan mengarang sendiri.

## 4. Dependensi dan konfigurasi

```bash
cd /var/www/care
composer install --no-dev --optimize-autoloader

cp .env.example .env
php artisan key:generate          # WAJIB di server ini, jangan menyalin APP_KEY dari mesin lain
```

Sunting `.env`:

```bash
nano .env
```

Isi seperti ini:

```ini
APP_NAME="Less Worry Complaint"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://care.lessworry.id
APP_LOCALE=id

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=lessworry_care
DB_USERNAME=care
DB_PASSWORD=password_yang_tadi_dibuat

SESSION_DRIVER=database
SESSION_LIFETIME=30          # menit; naikkan kalau tim sering kehabisan sesi
SESSION_EXPIRE_ON_CLOSE=true
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true

# NEVIRA — pakai service account, bukan akun pribadi
NEVIRA_API_BASE=https://api.nevira.id/api
NEVIRA_LOGIN_ENDPOINT=/admin/login
NEVIRA_EMAIL=
NEVIRA_PASSWORD=
NEVIRA_ENABLED=true
```

```bash
chmod 600 .env
```

**`APP_DEBUG=false` tidak bisa ditawar.** Kalau `true`, setiap error menampilkan isi variabel dan potongan kode ke siapa pun yang memicunya — termasuk kredensial NEVIRA.

## 5. Migrasi

```bash
php artisan migrate --force
php artisan storage:link
```

**Jangan menjalankan `--seed` di produksi.** Seeder membuat akun contoh berpassword `password`.

Buat supervisor pertama lewat tinker:

```bash
php artisan tinker
```

```php
$u = new App\Models\User();
$u->name = 'Satrio Wibowo';
$u->email = 'satrio@lessworry.id';
$u->password = 'password_sementara_yang_kuat';
$u->role = 'supervisor';
$u->is_active = true;
$u->must_change_password = true;
$u->save();
exit
```

Sisanya dibuat lewat halaman **Pengguna** setelah kamu masuk.

### Petakan outlet ke NEVIRA

```bash
php artisan nevira:sync-outlets --dry-run    # lihat rencananya
php artisan nevira:sync-outlets              # jalankan
```

Wajib dijalankan setelah kredensial NEVIRA terisi. Tanpa pemetaan ini:

- complaint tidak bisa menentukan outletnya sendiri dari nota,
- pembatasan kasir per outlet tidak punya dasar pembanding, sehingga kasir **ditolak** saat memeriksa nota.

Jalankan ulang setiap kali ada outlet baru dibuka. Perintah ini hanya membaca dari NEVIRA dan tidak pernah menghapus outlet yang sudah ada.

## 6. Izin berkas

```bash
sudo chown -R www-data:www-data /var/www/care
sudo find /var/www/care -type f -exec chmod 644 {} \;
sudo find /var/www/care -type d -exec chmod 755 {} \;
sudo chmod -R 775 /var/www/care/storage /var/www/care/bootstrap/cache
sudo chmod 600 /var/www/care/.env
```

## 7. Nginx

```bash
sudo nano /etc/nginx/sites-available/care.lessworry.id
```

```nginx
server {
    listen 80;
    server_name care.lessworry.id;
    root /var/www/care/public;

    index index.php;
    charset utf-8;

    client_max_body_size 12M;          # foto bukti complaint

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header Referrer-Policy "strict-origin-when-cross-origin";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Jangan pernah menyajikan berkas tersembunyi
    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/care.lessworry.id /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

## 8. HTTPS

Arahkan DNS `care.lessworry.id` ke IP server lebih dulu, tunggu propagasi, baru:

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d care.lessworry.id
sudo systemctl status certbot.timer     # pastikan perpanjangan otomatis aktif
```

`SESSION_SECURE_COOKIE=true` baru berfungsi setelah HTTPS hidup. Kalau dinyalakan sebelum sertifikat ada, kamu tidak akan bisa login.

## 9. Optimasi

```bash
cd /var/www/care
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Ulangi tiga perintah ini **setiap kali `.env` atau config berubah** — kalau tidak, perubahannya tidak terbaca.

## 10. Uji sebelum diumumkan

```bash
curl -I https://care.lessworry.id/login          # harus 200, dan header HTTPS
curl -I https://care.lessworry.id/storage/       # harus 403 atau 404, TIDAK boleh listing
curl -s https://care.lessworry.id/health         # ketiga pemeriksaan harus "ok"
```

Lalu lewat browser: masuk sebagai supervisor → sistem memaksa ganti password → buat satu akun kasir → catat satu complaint uji → cek nomor nota NEVIRA tertarik.

## Memperbarui versi

```bash
cd /var/www/care
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
php artisan up
```

Simpan sebagai `deploy.sh` supaya tidak ada langkah terlewat.

## Backup — jangan ditunda

Perintahnya sudah ada di aplikasi; tidak perlu menulis cron mysqldump sendiri.

```bash
sudo mkdir -p /var/backups/care
sudo chown www-data:www-data /var/backups/care
sudo chmod 750 /var/backups/care
```

Di `.env`:

```ini
BACKUP_PATH=/var/backups/care
BACKUP_KEEP=7
```

Direktori itu harus **hanya** dipakai backup: rotasi menghapus dump lama di
dalamnya. Berkas lain memang dilewati, tapi jangan mengandalkan itu.

Jalankan sekali untuk membuktikannya bekerja:

```bash
php artisan config:cache          # .env baru berubah
sudo -u www-data php artisan backup:database
```

### Menguji pemulihan — dengan pengguna database tersendiri

`backup:verify` mengambil dump terakhir, memulihkannya ke database sementara
`lessworry_care_verify_xxxx`, menghitung baris `complaints`, lalu membuang
database sementaranya. Itu yang membedakan backup dari berkas yang diasumsikan
benar.

**Perintah ini memulihkan berkas yang isinya tidak dipercaya.** Siapa pun yang
bisa menaruh berkas di direktori backup menentukan SQL apa yang dijalankan.
Karena itu yang menahannya bukan pembacaan isi dumpnya, melainkan hak akses:

> `mysql --one-database` hanya mengikuti pernyataan `USE`. Dump yang menulis
> dengan nama database lengkap — `INSERT INTO lessworry_care.complaints ...` —
> lewat begitu saja. Satu-satunya yang benar-benar menahan tulisan seperti itu
> adalah pengguna database yang tidak punya hak tulis di sana.

Maka **`backup:verify` menolak berjalan di MySQL sampai koneksi pemulihan
terpisah disiapkan.** Itu disengaja: verify yang menolak jalan bisa diperbaiki
dalam lima menit; complaint produksi yang tertimpa tidak bisa dikembalikan.

Buat penggunanya — dipakai HANYA oleh cron pemeriksaan, tidak pernah oleh
proses web:

```bash
sudo mysql -e "CREATE USER 'care_verify'@'localhost' IDENTIFIED BY 'PASSWORD_LAIN';"
sudo mysql -e "GRANT ALL PRIVILEGES ON \`lessworry\_care\_verify\_%\`.* TO 'care_verify'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"
```

Perhatikan apa yang TIDAK diberikan: tidak ada hak apa pun di
`lessworry_care`. Pengguna ini bahkan tidak bisa membacanya. Kalau dump jahat
mencoba menulis ke sana, MySQL sendiri yang menolak.

Lalu di `.env`:

```ini
BACKUP_VERIFY_CONNECTION=mysql_verify
DB_VERIFY_USERNAME=care_verify
DB_VERIFY_PASSWORD=PASSWORD_LAIN
```

```bash
php artisan config:cache
sudo -u www-data php artisan backup:verify
```

Pengguna `care` yang dipakai proses web tetap tanpa privilege tambahan apa pun
— tidak `CREATE DATABASE`, tidak `DROP DATABASE`.

**Lebih baik lagi: jalankan di mesin tempat backup disalin.** Salin
`/var/backups/care` ke mesin lain, pasang kode yang sama di sana, dan jalankan
`backup:verify` dari situ. Sekaligus menguji salinan luarnya — bukan hanya
berkas yang duduk di mesin yang sama dengan aplikasinya.

#### Yang menahan, berlapis

| lapis | menahan apa | bergantung pada |
|---|---|---|
| pengguna database terpisah | tulisan ke database produksi, termasuk yang bernama lengkap | hak akses MySQL |
| `--one-database` | `USE` yang memindahkan restore | klien mysql |
| pemindai isi dump | menolak lebih awal dengan pesan yang jelas | pembacaan teks |

Lapis ketiga sengaja ditaruh paling akhir. Ia sudah ditembus dua kali selama
peninjauan — sekali dengan `;ATTACH ...` sebaris, sekali dengan 300 spasi di
depan perintahnya. Selama keamanannya diputuskan oleh seberapa pintar
pembacanya, akan selalu ada bentuk berikutnya. Gunanya memberi pesan, bukan
menentukan aman.

Pemindai itu memakai **daftar izin**: hanya bentuk pernyataan yang memang
dihasilkan `backup:database` dan `mysqldump` yang dilewatkan. Dump buatan orang
lain dengan `mysqldump --databases`, atau yang memuat `DELIMITER`, akan
ditolak. Itu disengaja.

Di pengembangan lokal dengan SQLite tidak ada koneksi terpisah yang perlu
disiapkan: restore-nya dijalankan di proses PHP terpisah dengan `open_basedir`
dikunci ke satu direktori sementara, jadi `ATTACH DATABASE` ke berkas mana pun
di luar direktori itu gagal karena memang tidak bisa dibuka.

### Jadwalkan

`backup:database` sudah terdaftar di penjadwal Laravel (harian, 02.00). Yang
perlu ditambahkan hanya satu baris:

```bash
sudo crontab -u www-data -e
```

```cron
* * * * * cd /var/www/care && php artisan schedule:run >> /dev/null 2>&1
```

Satu baris ini menjalankan seluruh jadwal aplikasi, sekarang dan nanti — tidak
perlu diubah lagi setiap ada jadwal baru.

### Foto bukti belum ikut

Dump hanya berisi database. Foto complaint ada di `storage/app/private` dan
tetap butuh barisnya sendiri:

```cron
30 2 * * * tar czf /var/backups/care/files-$(date +\%F).tar.gz -C /var/www/care/storage/app private
0  3 * * * find /var/backups/care -name 'files-*.tar.gz' -mtime +30 -delete
```

### Salin ke luar server

Backup yang duduk di mesin yang sama akan ikut hilang bersama mesinnya. Salin
`/var/backups/care` ke penyimpanan lain (rclone, rsync ke mesin lain, atau
objek storage), lalu **uji `backup:verify` dari salinan itu** minimal sekali.

## Pemantauan

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://care.lessworry.id/health
```

- `200` — database, NEVIRA, dan penyimpanan lampiran ketiganya hidup.
- `503` — ada yang tidak. Isi jawabannya menyebut yang mana:
  `{"status":"error","checks":{"database":"ok","nevira":"error","storage":"ok"}}`

Endpoint ini sengaja tidak menyebut versi, nama host, maupun pesan galat —
terbuka tanpa autentikasi, jadi tidak boleh berguna bagi penyerang. Hasil
pemeriksaan NEVIRA disimpan 60 detik, aman ditembak tiap menit.

Pasang di pemantau apa pun (UptimeRobot, Healthchecks.io, atau curl di cron
mesin lain) dengan aturan: **beri tahu kalau bukan 200**.

Yang belum ada: pemberitahuan otomatis ke WhatsApp/Telegram saat mati. Perlu
keputusan siapa penerimanya dan lewat kanal apa — lihat API-15.

## Server percobaan (staging)

Kalau ada server kedua untuk uji coba, bedakan dengan `APP_ENV`:

```ini
APP_ENV=staging
APP_DEBUG=false
```

Setiap halaman lalu memunculkan pita "Lingkungan uji" di paling atas. Tanpa
penanda itu, dua tab browser yang terbuka bersamaan terlihat persis sama — dan
complaint pelanggan sungguhan bisa ditutup di server yang salah.

Staging wajib memakai database dan `APP_KEY` sendiri. Jangan menyalin dump
produksi ke sana tanpa menyamarkan nomor telepon dan nama pelanggan.

## Setelah hidup

- Nonaktifkan akun contoh kalau ada yang terlanjur dibuat.
- Tetapkan siapa pemilik teknis sistem ini — satu orang yang bisa memperbaiki di luar jam kerja.
- Pantau lonjakan `nevira_sync_error`; itu tanda integrasi NEVIRA putus.
