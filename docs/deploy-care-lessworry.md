# Deploy ke care.lessworry.id

`care.lessworry.id` berjalan di **cPanel DomaiNesia** — hosting bersama, bukan
VPS. Header balasannya `server: DomaiNesia`, dan berkasnya dikelola lewat File
Manager cPanel.

Konsekuensinya, dan ini yang membedakan dokumen ini dari panduan Laravel mana
pun di internet:

- **Tidak ada `sudo`.** Tidak ada satu pun langkah di sini yang memintanya.
- **Tidak ada berkas konfigurasi nginx yang disunting tangan.** Domain,
  document root, dan sertifikat diatur lewat menu panel.
- **Kepemilikan berkas sudah benar bawaannya.** Tidak ada `chown www-data`.
- **`php` bawaan shell belum tentu versi yang dipakai aplikasi.** Ini penyebab
  kegagalan paling sering; lihat bagian 0.2.
- **Ada `public_html`, dan isinya bisa diunduh siapa saja lewat browser.**
  Aturan menaruh berkas berisi data pelanggan ada di bagian 9 — baca sebelum
  memindahkan CSV apa pun.

> **Jalankan di akun cPanel milikmu.** Panduan ini tidak menjalankan apa pun
> dari sisi Multica — agent tidak punya, dan tidak boleh punya, akses ke
> hosting produksimu.

Jalur VPS yang lama tidak dibuang, hanya dipindahkan ke **Lampiran A**. Ia
berlaku kalau suatu hari hosting dipindahkan; hari ini bukan jalur yang dipakai.

---

## 0. Sebelum malam deploy — tiga hal yang harus dipastikan

Ketiganya diperiksa **jauh sebelum** malam deploy. Kalau salah satunya gagal,
yang dibutuhkan adalah tiket ke DomaiNesia, dan tiket butuh waktu.

### 0.1 Apakah Terminal aktif?

Masuk cPanel → cari **Terminal** di kotak pencarian menu. Kalau tidak ada,
cari **SSH Access**.

```bash
# Di dalam Terminal cPanel — buktikan ia benar-benar shell
whoami
echo "$HOME"
```

**Kalau Terminal dan SSH dua-duanya tidak ada, deploy tertahan di sini.**
Bukan pilihan gaya: `migrate`, `migrate:fresh --seed`, `nevira:sync-outlets`,
`complaint:import`, `backup:database` — semuanya perintah baris perintah, dan
tidak ada satu pun yang punya padanan di menu cPanel.

Yang harus dilakukan: minta DomaiNesia mengaktifkan Terminal atau SSH untuk
akun ini (di sebagian paket bersama fiturnya dimatikan bawaannya, dan bisa
diaktifkan atas permintaan).

Yang **tidak boleh** dilakukan: menaruh berkas PHP di `public_html` yang
menjalankan perintah artisan saat dibuka dari browser. Berkas seperti itu
adalah eksekusi perintah tanpa autentikasi di internet terbuka — pintu yang
jauh lebih lebar daripada masalah yang dipecahkannya. Kalau Terminal tidak ada,
laporkan sebagai penghalang, jangan diakali.

### 0.2 `php` yang mana, dan apakah 8.4?

Aplikasi ini menuntut **PHP 8.4**. Bukan preferensi: `composer.lock` mengunci
symfony 8.1.5 yang menuntut `php >= 8.4.1`, jadi `composer install` gagal di
8.3 sebelum satu baris kode pun jalan. `composer.json` menulis `"php": "^8.3"`,
dan itu menyesatkan — yang mengikat adalah lock-nya. CI dipatok ke 8.4 karena
alasan yang sama.

```bash
php -v                       # ini yang dipanggil kalau kamu mengetik "php"
```

Kalau bukan 8.4, **jangan pakai `php` polos**. Cari binari yang benar:

```bash
ls -d /opt/cpanel/ea-php* /opt/alt/php* 2>/dev/null
```

Salah satu bentuk di bawah ini biasanya ada di cPanel — cari yang membalas 8.4:

```bash
/opt/cpanel/ea-php84/root/usr/bin/php -v
/usr/local/bin/ea-php84 -v
/opt/alt/php84/usr/bin/php -v
```

Simpan yang benar sebagai variabel, dan **pakai itu di setiap perintah artisan
di dokumen ini**:

```bash
export PHP=/opt/cpanel/ea-php84/root/usr/bin/php   # ganti dengan yang tadi terbukti 8.4
$PHP -v
```

Terminal cPanel tidak selalu mengingat `export` antar sesi. Supaya tidak
terlupa saat malam deploy:

```bash
echo 'export PHP=/opt/cpanel/ea-php84/root/usr/bin/php' >> ~/.bashrc
```

Ekstensi yang dibutuhkan — periksa dengan binari yang sama, bukan `php` polos:

```bash
$PHP -m | tr 'A-Z' 'a-z' | sort > ~/ada.txt
for e in bcmath curl dom fileinfo gd mbstring openssl pdo_mysql tokenizer xml zip; do
  grep -qx "$e" ~/ada.txt || echo "KURANG: $e"
done
rm ~/ada.txt
```

`gd` ada di daftar itu dengan alasan: kompresi foto bukti memakainya. Tanpa gd
foto tetap tersimpan, tapi apa adanya — ukuran penuh berikut EXIF-nya, dan EXIF
ponsel memuat koordinat GPS.

**Versi PHP untuk web diatur terpisah dari versi PHP untuk CLI.** cPanel →
**MultiPHP Manager** (atau **Select PHP Version**) → pastikan domain
`care.lessworry.id` memakai 8.4 juga. Kalau CLI 8.4 tapi web 8.3, aplikasi
gagal hanya di browser, dan pesannya tidak akan menyebut versi PHP.

**Kalau 8.4 sama sekali tidak tersedia di akun ini**, itu penghalang yang sama
kerasnya dengan Terminal yang mati: mintakan ke DomaiNesia, jangan menurunkan
kunci di `composer.lock` supaya "muat" — itu memasang versi library yang tidak
pernah diuji CI.

### 0.3 Nama pengguna dan letak home

```bash
whoami          # ini <user> di seluruh dokumen ini
echo "$HOME"    # biasanya /home/<user>
```

Sepanjang dokumen ini `<user>` = nama pengguna cPanel, dan folder aplikasi
bernama `care`. Sesuaikan kalau di akunmu berbeda.

Kalau aplikasinya **sudah terpasang** (situsnya sudah menjawab), pastikan dulu
letak yang sebenarnya sebelum mengikuti langkah 1–7 — jangan memasang yang
kedua di sebelahnya:

```bash
ls -la ~ | head -30
readlink -f ~/public_html
```

---

## 1. Tata letak berkas

```
/home/<user>/
├── care/                 ← seluruh kode aplikasi (Laravel root)
│   ├── public/           ← HANYA folder ini yang boleh dijangkau web
│   ├── .env              ← kredensial. chmod 600. TIDAK di bawah public_html
│   └── storage/
├── public_html/          ← document root bawaan cPanel
├── impor/                ← CSV berisi data pelanggan (lihat bagian 9)
└── backup-care/          ← dump database (lihat bagian 12)
```

Yang penting satu kalimat: **apa pun di dalam `public_html/` bisa diunduh
siapa saja yang menebak namanya.** Yang di luarnya tidak. Karena itu akar
aplikasi ada di `~/care`, bukan di `~/public_html/care`.

Ada dua cara menyambungkan `care/public` ke domain. Pilih yang pertama kalau
panel mengizinkan.

**Cara A — document root diarahkan lewat panel (dianjurkan).**
cPanel → **Domains** (atau **Subdomains**) → pada `care.lessworry.id` →
**Document Root** diisi `/home/<user>/care/public`.

**Cara B — symlink, kalau panel mengunci document root ke `public_html`.**

```bash
cd ~
[ -d public_html ] && mv public_html public_html.lama-$(date +%F)
ln -s /home/<user>/care/public public_html
ls -la ~/public_html
```

Cara B menyimpan `public_html.lama-*` alih-alih menghapusnya. Isinya diperiksa
dulu, dibuang belakangan — bukan sebaliknya.

Yang **tidak** boleh: menyalin seluruh isi `care/` ke dalam `public_html/`.
Itu membuat `.env`, `storage/`, dan `vendor/` bisa diminta lewat URL.

---

## 2. Ambil kode

Repositori privat, jadi hosting perlu kunci sendiri. **Pakai deploy key, jangan
menyalin kunci SSH pribadimu** — deploy key hanya berlaku untuk satu repo, dan
bisa dicabut tanpa mengganggu yang lain.

cPanel punya menu **Git™ Version Control** yang bisa melakukan clone; kalau
dipakai, kuncinya dibuat di menu **SSH Access → Manage SSH Keys**. Lewat
Terminal, langkahnya:

```bash
ssh-keygen -t ed25519 -C "care.lessworry.id deploy" -f ~/.ssh/lessworry_deploy -N ""
cat ~/.ssh/lessworry_deploy.pub
```

Salin isinya ke GitHub: repo `iamsatrio/lessworry-complaint` → **Settings →
Deploy keys → Add deploy key**. **Jangan** centang *Allow write access* —
hosting tidak perlu push.

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

```bash
cd ~
git clone github-lessworry:iamsatrio/lessworry-complaint.git care
cd ~/care
git log --oneline -1        # pastikan commit-nya sesuai
```

Kalau `git` tidak ada di akun ini (`git --version` gagal) dan menu Git™ Version
Control juga tidak ada: unduh arsip repo di mesinmu sendiri, jalankan
`composer install --no-dev` di sana, lalu unggah hasilnya lewat File Manager
sebagai satu berkas `.zip` dan ekstrak di `~/care`. Lebih repot saat
memperbarui, tapi tidak menuntut apa pun yang tidak ada.

---

## 3. Database — lewat menu, bukan `mysql -e`

Di hosting bersama tidak ada akses root MySQL. Semua lewat cPanel →
**MySQL® Databases**:

1. **Create New Database** → `care` → cPanel menyimpannya sebagai
   `<user>_care`.
2. **Add New User** → `care` → jadi `<user>_care`. Passwordnya **dibuat oleh
   Password Generator cPanel**, jangan dikarang sendiri.
3. **Add User To Database** → pilih keduanya → **ALL PRIVILEGES**.

Catat nama lengkapnya berikut awalan `<user>_`. Nama tanpa awalan tidak akan
pernah bisa dihubungi.

Yang tidak diberikan cPanel kepada pengguna itu — dan tidak bisa diminta —
adalah `CREATE DATABASE`. Itu punya akibat pada `backup:verify`; lihat
bagian 12.

---

## 4. Dependensi dan `.env`

```bash
cd ~/care
composer -V           # ada?
```

Kalau tidak ada, pasang satu salinan di home (bukan sistem):

```bash
mkdir -p ~/bin
curl -sS https://getcomposer.org/installer | $PHP -- --install-dir="$HOME/bin" --filename=composer
$PHP ~/bin/composer -V
```

Pasang dependensi dengan binari PHP yang benar — kalau `composer` dipanggil
polos, ia memakai `php` bawaan, dan di 8.3 ia gagal:

```bash
cd ~/care
$PHP ~/bin/composer install --no-dev --optimize-autoloader
```

```bash
cp .env.example .env
$PHP artisan key:generate     # WAJIB di hosting ini, jangan menyalin APP_KEY dari mesin lain
```

Sunting `.env` — lewat `nano .env` di Terminal, atau File Manager → klik kanan
berkasnya → **Edit**:

```ini
APP_NAME="Less Worry Complaint"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://care.lessworry.id
APP_LOCALE=id

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<user>_care
DB_USERNAME=<user>_care
DB_PASSWORD=password_dari_generator_cpanel

SESSION_DRIVER=database
SESSION_LIFETIME=30          # menit; naikkan kalau tim sering kehabisan sesi
SESSION_EXPIRE_ON_CLOSE=true
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true

# Surat — WAJIB terisi sebelum akun dibagikan ke tim.
# MAIL_MAILER=log menulis surat ke storage/logs/laravel.log dan tidak
# mengirimkannya ke mana pun. Di produksi itu berarti SETIAP akun terkunci di
# login pertama, karena verifikasi email berdiri sebelum ganti password.
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS=care@lessworry.id
MAIL_FROM_NAME="Less Worry Complaint"

HEALTH_CACHE_STORE=file      # jangan database: kalau database mati, /health ikut bisu

BACKUP_PATH=/home/<user>/backup-care
BACKUP_KEEP=7

# NEVIRA — pakai service account, bukan akun pribadi
NEVIRA_API_BASE=https://api.nevira.id/api
NEVIRA_LOGIN_ENDPOINT=/admin/login
NEVIRA_EMAIL=
NEVIRA_PASSWORD=
NEVIRA_ENABLED=true
```

```bash
chmod 600 ~/care/.env
ls -l ~/care/.env      # harus -rw-------
```

Lewat File Manager: pilih `.env` → **Permissions** → centang hanya *User Read*
dan *User Write* (0600).

**`APP_DEBUG=false` tidak bisa ditawar.** Kalau `true`, setiap error
menampilkan isi variabel dan potongan kode ke siapa pun yang memicunya —
termasuk kredensial NEVIRA.

---

## 5. Domain, HTTPS, dan batas unggahan — semuanya lewat panel

- **Document root**: cPanel → **Domains** → `care.lessworry.id` →
  `/home/<user>/care/public`. (Atau cara B di bagian 1.)
- **HTTPS**: cPanel → **SSL/TLS Status** → pastikan **AutoSSL** aktif untuk
  `care.lessworry.id`. Tidak ada certbot, tidak ada `systemctl`.
  `SESSION_SECURE_COOKIE=true` baru berfungsi setelah sertifikatnya terbit;
  kalau dinyalakan lebih dulu, kamu tidak akan bisa login.
- **Paksa HTTPS**: cPanel → **Domains** → tombol **Force HTTPS Redirect**.
- **Batas unggahan**: cPanel → **MultiPHP INI Editor** → domain
  `care.lessworry.id` → `upload_max_filesize` dan `post_max_size` minimal
  `10M`. Batas aplikasi 8 MB per foto; kalau server menolak lebih dulu,
  petugas menerima 413 tanpa penjelasan alih-alih pesan dari aplikasi.

Berkas `public/.htaccess` yang ikut di repo sudah mengurus rewrite Laravel.
Tidak ada blok server yang perlu ditulis.

---

## 6. Izin berkas

Di cPanel seluruh berkas sudah dimiliki oleh pengguna akunmu, dan proses web
berjalan sebagai pengguna yang sama. **Tidak ada `chown`, tidak ada `sudo`.**
Yang perlu diperiksa hanya dua:

```bash
chmod 600 ~/care/.env                                  # satu-satunya yang wajib diubah
ls -ld ~/care/storage ~/care/bootstrap/cache           # harus bisa ditulis (7xx)
```

Kalau salah satunya tidak bisa ditulis:

```bash
chmod -R 755 ~/care/storage ~/care/bootstrap/cache
```

`777` tidak pernah dibutuhkan di sini, dan di hosting bersama ia justru
membuka berkas ke proses lain di mesin yang sama.

---

## 7. Migrasi dan akun pertama

```bash
cd ~/care
$PHP artisan migrate --force
```

**`storage:link` tidak diperlukan** dan sebaiknya tidak dibuat: foto bukti
disimpan di disk privat (`storage/app/private`) dan disajikan lewat rute yang
memeriksa wewenang. Symlink publik ke `storage` pernah jadi celahnya.

**Jangan menjalankan `--seed` di produksi** kecuali pada urutan reset di
bagian 10, yang memang disengaja.

Buat admin pertama:

```bash
$PHP artisan tinker
```

```php
$u = new App\Models\User();
$u->name = 'Satrio Wibowo';
$u->email = 'satrio@lessworry.id';
$u->password = 'password_sementara_yang_kuat';
$u->role = 'admin';
$u->is_active = true;
$u->must_change_password = true;
$u->save();
exit
```

**`admin`, bukan `supervisor`.** Pengelolaan pengguna dijaga
`User::canManageUsers()`, yang hanya mengizinkan `admin`, dan setiap rute
`/users` memanggilnya. Akun pertama yang dibuat sebagai `supervisor` bisa
masuk tapi **tidak bisa membuat akun siapa pun** — seluruh tim tertahan di
satu akun, dan satu-satunya jalan keluarnya kembali ke shell ini.

Kalau terlanjur, tidak perlu mengulang dari awal:

```bash
$PHP artisan lessworry:pulihkan-admin satrio@lessworry.id
```

### Buktikan surat benar-benar sampai — sebelum akun dibagikan

**Jangan lewati langkah ini, dan jangan menukar urutannya.** Verifikasi email
berdiri di depan gerbang ganti password: kalau surat tidak benar-benar
terkirim, setiap akun yang kamu buat terkunci di login pertama — semuanya
sekaligus. Membagikan password sementara lebih dulu berarti seluruh tim
memegang password untuk akun yang tidak bisa mereka buka, dan satu-satunya
jalan keluarnya menuntut akses shell ke server ini.

Pertama, pastikan mailernya memang mengirim. Domain dan Document Root belum
tentu sudah diarahkan di titik ini, jadi dibaca langsung dari aplikasinya lewat
CLI, bukan lewat web:

```bash
cd ~/care
$PHP artisan tinker --execute="echo config('mail.default');"    # harus 'smtp'
```

Kalau yang keluar `log` atau `array`, surat tidak dikirim ke mana pun. Perbaiki
`.env`, jalankan `$PHP artisan config:cache`, lalu ulangi.

`smtp` yang tertulis benar **belum** berarti SMTP-nya bisa dihubungi — itu yang
dibuktikan langkah berikutnya, dan itu sebabnya langkah ini tidak cukup sendiri.

Lalu kirim satu verifikasi sungguhan ke satu alamat dan tunggu suratnya sampai:

```bash
$PHP artisan tinker
```

```php
$u = App\Models\User::where('email', 'satrio@lessworry.id')->firstOrFail();
app(App\Services\PengirimVerifikasiEmail::class)->kirim($u, 'permintaan');
exit
```

Balasannya harus `"terkirim"`. Hasilnya juga tercatat untuk `/health`: setiap
pengiriman yang gagal membuat `"mail"` jadi `"error"`, dan pengiriman berikutnya
yang berhasil menghapus penandanya kembali.

Lalu **buka kotak surat alamat itu dan pastikan
suratnya benar-benar ada** — `"terkirim"` hanya berarti server SMTP menerimanya,
belum berarti surat itu lolos dari filter spam. Kalau tidak sampai dalam lima
menit, cek folder spam, lalu:

```bash
tail -n 50 storage/logs/laravel.log | grep -i 'Gagal mengirim email verifikasi'
```

Baru setelah surat itu terbukti sampai, lanjutkan membuat akun tim.

### Buat akun tim dan bagikan password sementaranya

Sisanya dibuat lewat halaman **Pengguna** setelah kamu masuk. Setiap akun baru
dapat password sementara dan wajib menggantinya saat pertama masuk.

### Petakan outlet ke NEVIRA

```bash
$PHP artisan nevira:sync-outlets --dry-run    # lihat rencananya
$PHP artisan nevira:sync-outlets              # jalankan
```

Wajib dijalankan setelah kredensial NEVIRA terisi. Tanpa pemetaan ini:

- complaint tidak bisa menentukan outletnya sendiri dari nota,
- pembatasan kasir per outlet tidak punya dasar pembanding, sehingga kasir
  **ditolak** saat memeriksa nota.

Jalankan ulang setiap kali ada outlet baru dibuka. Perintah ini hanya membaca
dari NEVIRA dan tidak pernah menghapus outlet yang sudah ada.

---

## 8. Optimasi dan uji sebelum diumumkan

```bash
cd ~/care
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
```

Ulangi tiga perintah ini **setiap kali `.env` atau config berubah** — kalau
tidak, perubahannya tidak terbaca.

```bash
curl -I https://care.lessworry.id/login          # 200, dan lewat HTTPS
curl -s https://care.lessworry.id/health         # ketiga pemeriksaan "ok"

# Yang tidak boleh terjangkau dari web — ketiganya harus 403 atau 404
curl -s -o /dev/null -w '.env        %{http_code}\n' https://care.lessworry.id/.env
curl -s -o /dev/null -w 'storage/    %{http_code}\n' https://care.lessworry.id/storage/
curl -s -o /dev/null -w 'vendor/     %{http_code}\n' https://care.lessworry.id/vendor/autoload.php
```

Kalau `.env` membalas 200, berhenti: document root salah — ia menunjuk ke
`~/care`, bukan ke `~/care/public`. Ganti kredensial NEVIRA dan password
database setelah memperbaikinya, karena keduanya sudah pernah terbuka.

Lalu lewat browser: masuk sebagai admin → sistem memaksa ganti password →
buat satu akun kasir → catat satu complaint uji → cek nomor nota NEVIRA
tertarik.

Langkah "buat satu akun kasir" itu sekalian membuktikan akun pertamanya memang
`admin`: kalau ia `supervisor`, halaman Pengguna membalas 403 di sini.

---

## 9. Menaruh berkas yang berisi data pelanggan

Berlaku untuk `DATA COMPLAINT*.csv`, ekspor spreadsheet, dan berkas apa pun
yang memuat nama, nomor telepon, atau keluhan pelanggan.

- **Jangan pernah menaruhnya di dalam `public_html/`, atau di anak folder mana
  pun di bawahnya — termasuk `care/public/`.** Berkas di sana bisa diunduh
  siapa saja yang menebak namanya; tidak perlu login, tidak ada jejak siapa
  yang mengunduh. Nama seperti `data.csv` atau `complaint.csv` ditebak dalam
  percobaan pertama.
- **Tempatnya di home, sejajar dengan `public_html`, bukan di dalamnya.**
  Gunakan `/home/<user>/impor/`:

  ```bash
  mkdir -p ~/impor
  chmod 700 ~/impor
  ```

  Unggahnya lewat File Manager: masuk ke `/home/<user>/impor` **sebelum**
  menekan Upload. File Manager membuka `public_html` bawaannya — itu justru
  tempat yang salah.

- **Hapus berkasnya setelah impor selesai.**

  ```bash
  shred -u ~/impor/"DATA COMPLAINT.csv" 2>/dev/null || rm -f ~/impor/"DATA COMPLAINT.csv"
  ls -la ~/impor
  ```

  Isinya sudah masuk basis data; salinan yang tertinggal hanya menambah tempat
  data pelanggan bisa bocor. Berkas yang dihapus lewat File Manager mampir ke
  **Trash** cPanel — kosongkan juga.

- **Buktikan ia memang tidak bisa dijangkau dari web** — sebelum mengunggah dan
  sesudahnya:

  ```bash
  curl -s -o /dev/null -w '%{http_code}\n' \
    "https://care.lessworry.id/impor/DATA%20COMPLAINT.csv"
  ```

  Hasilnya **harus `404`**. Kalau `200`, berkasnya berada di bawah document
  root: pindahkan sekarang juga, lalu anggap isinya sudah tersebar.

Laporan hasil impor (`storage/app/impor/`) juga bukan berkas publik. Ia berisi
angka, bukan baris pelanggan — tapi `storage/` tetap di luar document root, dan
biarkan begitu.

---

## 10. Urutan reset dan impor di cPanel (versi jalan dari API-34)

API-34 menuliskan urutan ini dengan perintah VPS. Yang di bawah ini adalah
urutan yang sama, dengan perintah yang benar-benar bisa dijalankan di Terminal
cPanel. **Yang berlaku adalah yang di bawah ini.**

Seluruhnya dijalankan dari `~/care`, dengan `$PHP` dari bagian 0.2.

### 0. Satu pertanyaan yang harus dijawab dulu

**Apakah sudah ada complaint sungguhan yang diketik orang ke sistem?**

```bash
cd ~/care
$PHP artisan tinker --execute="echo App\Models\Complaint::count().' complaint, '.App\Models\User::count().' pengguna'.PHP_EOL;"
```

Kalau angkanya bukan nol, **berhenti**. `migrate:fresh` menghapus semuanya dan
tidak bisa dibatalkan.

### 1. Deploy kodenya dulu

```bash
cd ~/care
git pull origin main
$PHP ~/bin/composer install --no-dev --optimize-autoloader
$PHP artisan config:cache && $PHP artisan route:cache && $PHP artisan view:cache
```

Belum menyentuh database.

### 2. Backup dulu, walau akan direset

```bash
$PHP artisan backup:database
ls -lh ~/backup-care
```

Terasa mubazir. Tidak: kalau ternyata ada sesuatu di database yang tidak
diduga, itu ketahuan **setelah** reset, bukan sebelum.

`backup:verify` tidak bisa dijalankan di sini — alasannya di bagian 12. Yang
menggantikannya: **unduh dump yang baru terbit dan pulihkan di mesin lain**
sebelum lanjut. Kalau dump itu tidak bisa dipulihkan, jangan reset; setelah
reset, spreadsheet berhenti jadi jaring pengaman.

### 3. Reset

```bash
$PHP artisan migrate:fresh --seed
```

Keluarannya memuat **tabel akun beserta password sementaranya**. Ditampilkan
sekali, tidak tersimpan di mana pun. Salin sebelum menutup Terminal — Terminal
cPanel di dalam browser: jangan tutup tabnya. Sampaikan lewat jalur pribadi,
jangan grup; semuanya wajib diganti saat login pertama.

### 4. Tarik outlet dari NEVIRA

```bash
$PHP artisan nevira:sync-outlets
```

**Jangan dilewati.** `migrate:fresh` menghapus tabel outlet, dan tanpa outlet
yang benar kasir ditolak saat memeriksa nota.

### 5. Backfill 545 complaint

CSV-nya ada di `~/impor/`, **bukan** di `public_html` dan bukan di dalam
`~/care` (bagian 9):

```bash
cd ~/care
$PHP artisan complaint:import ~/impor/"DATA COMPLAINT - 3. Data Input New.csv" --sumber=spreadsheet-2026-08
```

Perintah itu **tidak menulis apa pun**. Baca laporannya lebih dulu. Angka
pembanding:

| | |
|---|---|
| Baris masuk | **545** |
| Kategori / layanan / tindak lanjut kosong | 5 |
| Status Close tanpa tanggal tutup | **61** — waktu penyelesaian tidak diketahui, bukan nol |
| Nilai kompensasi berawalan `Rp` | 93 |
| `Duren Tiga` tanpa outlet | **29** |

Kalau cocok, baru tulis:

```bash
$PHP artisan complaint:import ~/impor/"DATA COMPLAINT - 3. Data Input New.csv" --sumber=spreadsheet-2026-08 --tulis
```

Kalau menyimpang dari angka di atas, berhenti dan tanyakan. Jalan mundurnya
ada: `$PHP artisan complaint:import-hapus spreadsheet-2026-08`.

### 6. Hapus CSV-nya

```bash
shred -u ~/impor/"DATA COMPLAINT - 3. Data Input New.csv" 2>/dev/null \
  || rm -f ~/impor/"DATA COMPLAINT - 3. Data Input New.csv"
ls -la ~/impor
```

Sudah masuk basis data. Salinan yang tertinggal hanya menambah tempat bocor.

### 7. Periksa

```bash
curl -s https://care.lessworry.id/health
```

Ketiganya harus `ok`. Lalu buka papan kerja: 545 complaint historis, kategori
teratas **Kurang Bersih** lalu **Barang Rusak**, dan tidak ada yang merah
karena semuanya sudah lewat. Terakhir, login dengan salah satu akun dari
langkah 3 dan pastikan sistem **memaksa ganti password sebelum apa pun bisa
dibuka**.

### Yang berubah maknanya setelah reset

Sebelum reset, spreadsheet adalah sumber kebenaran dan sistem adalah
salinannya. **Sesudah reset, sistem adalah satu-satunya tempat riwayat
complaint hidup.** Akibatnya: backup harian harus benar-benar terbit (periksa
di hari kedua), complaint tetap dicatat di dua tempat selama dua minggu
pertama, dan pemilik teknis manusia jadi mendesak.

---

## 11. Memperbarui versi

```bash
cd ~/care
$PHP artisan down
git pull origin main
$PHP ~/bin/composer install --no-dev --optimize-autoloader
$PHP artisan migrate --force
$PHP artisan config:cache && $PHP artisan route:cache && $PHP artisan view:cache
$PHP artisan up
```

Tidak ada baris `chown` di sini — kepemilikannya memang sudah benar.

Simpan sebagai `~/care/deploy.sh` (`chmod +x`) supaya tidak ada langkah
terlewat. Baris pertamanya harus menyetel `PHP` sendiri; cron dan skrip tidak
membaca `~/.bashrc`.

---

## 12. Backup

Perintahnya sudah ada di aplikasi; tidak perlu menulis cron mysqldump sendiri.

```bash
mkdir -p ~/backup-care
chmod 700 ~/backup-care
```

**Di luar `public_html`, dan tidak di dalam `~/care`.** Dump berisi seluruh
complaint pelanggan; satu berkas yang bisa diunduh dari browser membocorkan
semuanya sekaligus.

Di `.env` (sudah ada di bagian 4):

```ini
BACKUP_PATH=/home/<user>/backup-care
BACKUP_KEEP=7
```

Direktori itu harus **hanya** dipakai backup: rotasi menghapus dump lama di
dalamnya. Berkas lain memang dilewati, tapi jangan mengandalkan itu.

Pastikan `mysqldump` terjangkau — di hosting bersama ia ada, tapi belum tentu
di `PATH` milik cron:

```bash
which mysqldump mysql
```

Kalau jawabannya kosong atau bukan `/usr/bin/...`, isi path lengkapnya di
`.env`:

```ini
BACKUP_MYSQLDUMP=/usr/bin/mysqldump
BACKUP_MYSQL=/usr/bin/mysql
```

Buktikan sekali:

```bash
cd ~/care
$PHP artisan config:cache        # .env baru berubah
$PHP artisan backup:database
ls -lh ~/backup-care
```

### Menjadwalkan — menu Cron Jobs, bukan `crontab -e`

cPanel → **Cron Jobs**. Tambahkan satu entri, **Common Settings: Once Per
Minute (`* * * * *`)**, dengan perintah:

```
cd /home/<user>/care && /opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:run >/dev/null 2>&1
```

Tiga hal yang membuat baris ini gagal diam-diam kalau salah:

1. **Path PHP ditulis lengkap.** Cron tidak membaca `~/.bashrc`, jadi `$PHP`
   tidak ada di sana, dan `php` polos bisa saja 8.3. Pakai path yang terbukti
   di bagian 0.2.
2. **Path aplikasi ditulis lengkap.** Cron mulai dari home, bukan dari
   `~/care`.
3. **`>/dev/null 2>&1` di akhir.** Tanpa itu cPanel mengirim email setiap
   menit. Isi juga kolom **Email** di halaman Cron Jobs dengan alamat yang
   dibaca orang — atau kosongkan sekalian.

Satu baris ini menjalankan seluruh jadwal aplikasi, sekarang dan nanti.
`backup:database` sudah terdaftar di penjadwal Laravel (harian, 02.00).

Buktikan sehari sesudahnya bahwa berkasnya memang terbit:

```bash
ls -lh ~/backup-care
```

Cron yang terpasang tapi tidak menghasilkan berkas adalah kegagalan paling
sunyi di seluruh dokumen ini.

### Foto bukti belum ikut

Dump hanya berisi database. Foto complaint ada di `storage/app/private` dan
butuh entri Cron Jobs sendiri:

```
30 2 * * *  tar czf /home/<user>/backup-care/files-$(date +\%F).tar.gz -C /home/<user>/care/storage/app private
0  3 * * *  find /home/<user>/backup-care -name 'files-*.tar.gz' -mtime +30 -delete
```

Tanda `%` di cron **wajib** ditulis `\%`, termasuk di kolom perintah cPanel.
Tanpa backslash, cron memotong perintahnya di situ dan `tar` tidak pernah
jalan.

### `backup:verify` tidak bisa jalan di hosting ini

`backup:verify` memulihkan dump ke database sementara bernama
`<db>_verify_<acak>`, menghitung baris `complaints`, lalu membuangnya. Ia
menuntut dua hal yang tidak ada di cPanel:

1. **`CREATE DATABASE` saat perintah berjalan.** Nama database sementaranya
   acak setiap kali, jadi tidak bisa dibuatkan lebih dulu lewat menu MySQL®
   Databases. Pengguna database cPanel tidak punya hak itu, dan tidak bisa
   diberi.
2. **Pengguna database kedua tanpa hak apa pun di database produksi.**
   Perintah ini menolak berjalan kalau `BACKUP_VERIFY_CONNECTION` kosong atau
   memakai pengguna yang sama — dan itu disengaja: yang menahan dump jahat
   menulis ke produksi adalah hak akses, bukan pembacaan isi dumpnya.

Jadi di `care.lessworry.id`, `BACKUP_VERIFY_CONNECTION` dibiarkan **kosong**
dan `backup:verify` **tidak dijalankan di server**. Itu bukan berarti backup
tidak diuji — pengujiannya pindah tempat:

```bash
# 1. Di mesinmu sendiri, unduh dump terakhir
scp <user>@care.lessworry.id:~/backup-care/<berkas-terbaru>.sql.gz ./
# (atau File Manager → backup-care → Download)

# 2. Pulihkan ke database kosong di MySQL lokal
gunzip -c <berkas-terbaru>.sql.gz | mysql -u root uji_pulih_care

# 3. Hitung barisnya — harus mendekati jumlah complaint di produksi
mysql -u root -e "SELECT COUNT(*) FROM uji_pulih_care.complaints;"

# 4. Buang
mysql -u root -e "DROP DATABASE uji_pulih_care;"
```

Lakukan **satu kali setelah pasang, lalu tiap kuartal**, dan catat tanggalnya.
Sekaligus menguji salinan luarnya — bukan hanya berkas yang duduk di mesin yang
sama dengan aplikasinya. **Backup yang belum pernah diuji pulih bukan backup.**

### Salin ke luar hosting

Backup yang duduk di akun yang sama akan ikut hilang bersama akunnya. Kalau SSH
aktif, jadwalkan di mesin lain (bukan di cPanel):

```bash
rsync -avz --remove-source-files=no \
  <user>@care.lessworry.id:~/backup-care/ /path/backup-lessworry/
```

Kalau SSH tidak aktif, unduh manual lewat File Manager **mingguan**, dan catat
di kalender siapa yang melakukannya. Jadwal manual yang tidak punya nama
pemilik tidak akan berjalan.

Menu **Backup** cPanel juga membuat salinan penuh akun, tapi jangan
mengandalkannya sendirian: isinya di mesin yang sama, dan retensinya diatur
DomaiNesia, bukan kamu.

---

## 13. Pemantauan

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://care.lessworry.id/health
```

- `200` — database, NEVIRA, penyimpanan lampiran, dan pengiriman surat
  keempatnya hidup.
- `503` — ada yang tidak. Isi jawabannya menyebut yang mana:
  `{"status":"error","checks":{"database":"ok","nevira":"error","storage":"ok","mail":"ok"}}`

Pemeriksaan `mail` membaca **hasil pengiriman terakhir**, bukan isi `.env`:

| nilai | artinya | HTTP |
|---|---|---|
| `ok` | tidak ada kegagalan kirim yang tercatat | 200 |
| `error` | pengiriman terakhir gagal, **atau** `MAIL_MAILER` masih `log`/`array` di `APP_ENV=production` | 503 |
| `unknown` | penandanya sendiri tidak terbaca — keadaan surat tidak diketahui | 200 |
| `disabled` | mailer hanya mencatat, dan ini bukan produksi | 200 |

`error` di produksi berarti tidak ada surat verifikasi yang benar-benar
terkirim, dan itu mengunci **seluruh** tim di login pertama tanpa satu pun
pesan galat yang terlihat dari layar — karena itu ia dilaporkan di sini,
sebelum ada yang mencoba masuk.

Dua hal yang perlu diketahui tentang cara kerjanya:

- **`/health` tidak menghubungi SMTP sendiri.** Kalau ia melakukannya, tiap
  ketukan pemantau jadi satu koneksi keluar. Yang dibacanya penanda hasil kirim
  yang sebenarnya.
- **Penandanya kedaluwarsa 24 jam.** Bukan supaya papannya cepat hijau lagi:
  selama SMTP benar-benar mati, tiap percobaan login memperbarui penandanya,
  jadi papannya tetap merah. Yang dihindari adalah satu kegagalan sesaat
  berbulan-bulan lalu mengunci papan pada sistem yang sejak itu tidak pernah
  mengirim apa pun.

Endpoint ini sengaja tidak menyebut versi, nama host, maupun pesan galat —
terbuka tanpa autentikasi, jadi tidak boleh berguna bagi penyerang. Hasil
pemeriksaan NEVIRA disimpan 60 detik, aman ditembak tiap menit.

Pasang di pemantau **di luar hosting ini** (UptimeRobot, Healthchecks.io, atau
curl di cron mesin lain) dengan aturan: **beri tahu kalau bukan 200**. Pemantau
yang berjalan di mesin yang dipantaunya ikut mati bersamanya.

Yang belum ada: pemberitahuan otomatis ke WhatsApp/Telegram saat mati. Perlu
keputusan siapa penerimanya dan lewat kanal apa — lihat API-33 dan API-15.

---

## 14. Lingkungan uji (staging) di cPanel

Buat **subdomain kedua** (mis. `uji.lessworry.id`) dengan document root
`/home/<user>/care-uji/public`, dan **database kedua** lewat menu MySQL®
Databases. Lalu di `.env`-nya:

```ini
APP_ENV=staging
APP_DEBUG=false
```

Setiap halaman lalu memunculkan pita "Lingkungan uji" di paling atas. Tanpa
penanda itu, dua tab browser yang terbuka bersamaan terlihat persis sama — dan
complaint pelanggan sungguhan bisa ditutup di server yang salah.

Staging wajib memakai database dan `APP_KEY` sendiri. Jangan menyalin dump
produksi ke sana tanpa menyamarkan nomor telepon dan nama pelanggan.

Cron `schedule:run` untuk staging: **jangan dipasang**, atau backup staging
akan bercampur di direktori yang sama.

---

## 15. Setelah hidup

- Nonaktifkan akun contoh kalau ada yang terlanjur dibuat.
- Tetapkan siapa pemilik teknis sistem ini — satu orang yang bisa memperbaiki
  di luar jam kerja.
- Pantau lonjakan `nevira_sync_error`; itu tanda integrasi NEVIRA putus.
- Periksa sekali lagi bahwa tidak ada berkas CSV yang tertinggal:

  ```bash
  find ~ -name '*.csv' -not -path '*/vendor/*' -not -path '*/node_modules/*' 2>/dev/null
  ```

---

# Lampiran A — jalur VPS (arsip, tidak dipakai hari ini)

Ditulis saat rencananya VPS Ubuntu/Debian + Nginx. **Tidak berlaku di cPanel
DomaiNesia** — tidak ada `sudo`, tidak ada `/etc/nginx`, tidak ada
`www-data`. Disimpan kalau kelak hosting dipindahkan.

<details>
<summary>Buka langkah-langkah VPS</summary>

Asumsi: Ubuntu/Debian, Nginx, PHP 8.4, MySQL.

### Ambil kode

```bash
sudo mkdir -p /var/www
sudo chown $USER:$USER /var/www
cd /var/www
git clone github-lessworry:iamsatrio/lessworry-complaint.git care
cd care
```

### Database

```bash
sudo mysql -e "CREATE DATABASE lessworry_care CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'care'@'localhost' IDENTIFIED BY 'GANTI_DENGAN_PASSWORD_KUAT';"
sudo mysql -e "GRANT ALL PRIVILEGES ON lessworry_care.* TO 'care'@'localhost'; FLUSH PRIVILEGES;"
```

Passwordnya dibuat dengan `openssl rand -base64 24`, jangan dikarang sendiri.

### Izin berkas

```bash
sudo chown -R www-data:www-data /var/www/care
sudo find /var/www/care -type f -exec chmod 644 {} \;
sudo find /var/www/care -type d -exec chmod 755 {} \;
sudo chmod -R 775 /var/www/care/storage /var/www/care/bootstrap/cache
sudo chmod 600 /var/www/care/.env
```

### Nginx

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

### HTTPS

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d care.lessworry.id
sudo systemctl status certbot.timer
```

### Backup dan cron

```bash
sudo mkdir -p /var/backups/care
sudo chown www-data:www-data /var/backups/care
sudo chmod 750 /var/backups/care
sudo crontab -u www-data -e
```

```cron
* * * * * cd /var/www/care && php artisan schedule:run >> /dev/null 2>&1
30 2 * * * tar czf /var/backups/care/files-$(date +\%F).tar.gz -C /var/www/care/storage/app private
0  3 * * * find /var/backups/care -name 'files-*.tar.gz' -mtime +30 -delete
```

### `backup:verify` dengan pengguna database tersendiri

Di VPS inilah `backup:verify` bisa dipakai apa adanya — ia menuntut
`CREATE DATABASE`, yang di hosting bersama tidak ada.

```bash
sudo mysql -e "CREATE USER 'care_verify'@'localhost' IDENTIFIED BY 'PASSWORD_LAIN';"
sudo mysql -e "GRANT ALL PRIVILEGES ON \`lessworry\_care\_verify\_%\`.* TO 'care_verify'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"
```

```ini
BACKUP_VERIFY_CONNECTION=mysql_verify
DB_VERIFY_USERNAME=care_verify
DB_VERIFY_PASSWORD=PASSWORD_LAIN
```

```bash
php artisan config:cache
sudo -u www-data php artisan backup:verify
```

Perhatikan apa yang TIDAK diberikan: tidak ada hak apa pun di `lessworry_care`.
Pengguna ini bahkan tidak bisa membacanya. Kalau dump jahat mencoba menulis ke
sana, MySQL sendiri yang menolak.

Yang menahan, berlapis:

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

</details>
