# Impor complaint historis dari spreadsheet

Memasukkan complaint yang sudah tercatat di spreadsheet ke dalam sistem, tanpa
menyentuh NEVIRA sekali pun. (API-28)

Berkas sumbernya berisi **nama dan keluhan pelanggan nyata**. Berkas itu tidak
masuk repositori, tidak ditempel ke issue, dan tidak dikirim ke layanan luar.
Yang boleh keluar hanya laporan hasil impor — isinya angka, bukan baris.

## Menjalankan

Hitung dulu. Tanpa `--tulis`, perintah tidak menyentuh basis data sama sekali:

```bash
php artisan complaint:import "DATA COMPLAINT.csv" --sumber=spreadsheet-2026-08
```

Bacalah laporannya. Kalau angkanya masuk akal, baru tulis:

```bash
php artisan complaint:import "DATA COMPLAINT.csv" --sumber=spreadsheet-2026-08 --tulis
```

Aman dijalankan dua kali: baris yang sudah masuk dilewati, bukan digandakan.
Yang mengenalinya adalah **sidik jari dari isi barisnya** (`import_fingerprint`,
unik di tingkat basis data) — bukan `--sumber`. Jadi berkas yang sama yang
diimpor dua kali dengan label berbeda tetap dikenali, dan tidak menggandakan
apa pun.

### Path berkasnya dibaca dari akar proyek

Path yang tidak diawali `/` **diukur dari direktori kerja**, dan direktori
kerjanya adalah akar proyek — bukan folder tempat berkasnya diunduh. Berkas
hasil unduhan biasanya ada di `~/Downloads`, jadi menuliskan namanya saja
membuat perintah mencari di tempat yang salah:

```bash
php artisan complaint:import "DATA COMPLAINT.csv"          # dicari di <akar proyek>/DATA COMPLAINT.csv
php artisan complaint:import ~/Downloads/"DATA COMPLAINT.csv"   # yang dimaksud
```

**Tulis path lengkapnya.** Kalau path-nya berada di dalam tanda kutip, tanda
`~` tidak diperluas — shell hanya memperluasnya di luar kutip — jadi tulis
`/Users/nama/Downloads/berkas.csv` utuh.

Kalau berkasnya tidak ketemu, pesannya menyebut **path absolut yang tadi
dicari** berikut hasil resolusi path relatifnya. Perintah ini tidak menyisir
folder mana pun mencari berkas yang namanya mirip; yang dikatakannya hanya di
mana ia mencari.

Tiga keadaan dibedakan, karena tindakannya berbeda:

| Yang dikatakan | Artinya |
|---|---|
| `Berkas tidak ditemukan.` | Path-nya salah. Cocokkan dengan baris `Dicari di:`. |
| `Berkas ada, tapi izinnya tidak mengizinkan…` | Berkasnya benar; izin aksesnya yang menutup. Pesannya menyebut izin, pemilik, dan sebagai siapa perintah dijalankan. |
| `Berkas ini .xlsx, bukan CSV.` | Berkasnya belum diekspor. Buka di aplikasi spreadsheet, ekspor jadi CSV, jalankan lagi dengan berkas `.csv`-nya. |

### Bendera

| Bendera | Arti |
|---|---|
| `--tulis` | Benar-benar menyimpan. Tanpa ini hanya menghitung. |
| `--dry-run` | Paksa hanya menghitung, walau `--tulis` diberikan. |
| `--sejak=` | Impor hanya baris dengan `Date` ≥ tanggal ini (`YYYY-MM-DD`, **inklusif**). Bawaannya `config('complaint.impor_sejak')`, yang kini `null` — tanpa opsi ini seluruh berkas diimpor. |
| `--sumber=` | Penanda asal. Bawaannya nama berkas. Dipakai juga untuk menghapus. |
| `--laporan=` | Tujuan berkas laporan. Bawaannya `storage/app/impor/`. |

## Dua tanggal yang berbeda, jangan tertukar

`config('complaint.nevira_mulai')` = **2026-05-16** — fakta sejarah: kapan
NEVIRA mulai dipakai. Dipakai untuk **menandai**, bukan menyaring, dan tidak
berubah.

`config('complaint.impor_sejak')` = **null** — saringan: baris mana yang mau
diimpor. Bawaannya tidak ada, jadi seluruh berkas masuk.

Menyatukan keduanya berarti mengubah saringan ikut mengubah arti data yang
sudah tersimpan.

## Saringan tanggal (`--sejak`)

Bawaannya **tidak ada** — seluruh 545 baris diimpor. Cutoff 16 Mei sempat
dipasang lalu dicabut: satrio ingin melihat besaran kerugian complaint sejak
awal, dan laporan kerugian **tidak membutuhkan tautan ke order sama sekali** —
angkanya dari kolom biaya pada complaint itu sendiri. Memotong di 16 Mei
membuang 91% biaya yang pernah dicatat tim.

Opsinya tetap ada. Kalau tanggal potong dibutuhkan lagi, itu satu argumen:

```bash
php artisan complaint:import "DATA COMPLAINT.csv" --sejak=2026-05-16 --tulis
```

Batasnya **inklusif** — baris tertanggal 16 Mei 2026 ikut masuk — dan tanggal
dibaca dari kolom `Date`, bukan `Cucian Input Nota`.

Baris yang lebih tua **dilewati, bukan digagalkan**, dan punya angkanya
sendiri di laporan. Baris yang tanggalnya **tidak terbaca** tetap dihitung
gagal: kalau tanggalnya tidak diketahui, tidak ada yang tahu baris itu di sisi
mana dari tanggal potong.

## Penanda pra-NEVIRA

Complaint yang lebih tua dari `nevira_mulai` ditandai pra-NEVIRA. Penandanya
**diturunkan dari `created_at`**, bukan disimpan sebagai kolom boolean:
"sebelum NEVIRA" sepenuhnya ditentukan oleh tanggal complaint dibanding satu
tanggal yang sudah lewat dan tidak akan berubah. Kolom boolean menambah sumber
kebenaran kedua untuk fakta yang sama — dan begitu keduanya bisa berbeda, suatu
hari mereka akan berbeda, tanpa ada yang tahu mana yang benar.

```php
$complaint->isPraNevira();        // satu baris
Complaint::praNevira()->sum(...); // laporan per era
Complaint::sejakNevira()->get();
```

Halaman complaint menampilkan keterangannya di tempat detail order biasanya
muncul, jadi orang tidak mencari tautan yang tidak pernah bisa ada.

## Jalan mundur

Satu perintah membuang seluruh hasil satu impor:

```bash
php artisan complaint:import-hapus spreadsheet-2026-08
```

Ini satu-satunya penghapusan complaint yang diizinkan di sistem ini, dan hanya
mengenai baris yang punya `import_source`. Complaint yang dicatat orang tidak
bisa disentuh dari sini.

Penanda yang salah ketik tidak menghapus apa pun, dan perintahnya menjawab
dengan **daftar penanda yang benar-benar ada** di basis data beserta jumlah
barisnya — bukan dengan mengulang penanda yang baru saja diketik.

## Yang perlu diketahui tentang datanya

**Nomor nota tidak ditautkan ke NEVIRA.** Tidak satu pun nomor nota data lama
berformat `INV/`; bentuknya angka polos 3–5 digit, kadang dibubuhi nama bulan
(`2138 (Juli)`) justru karena angkanya sendiri tidak unik. Semuanya disimpan
apa adanya di `legacy_nota_number`. Menaruhnya di `nevira_transaction_id` akan
menempelkan keluhan ini ke order pelanggan lain.

**Outlet dicocokkan lewat peta padanan yang ditulis eksplisit** di
`PemetaBarisImpor::PADANAN_OUTLET` — `Hampton GS`, `Park Sepong` (salah ketik
di sumbernya), `Jatipadang`. `Duren Tiga` (29 baris) sengaja TIDAK punya
padanan: outlet itu sudah tidak beroperasi, jadi barisnya masuk tanpa outlet
dengan nama aslinya tersimpan. Bukan pencocokan samar: pencocokan samar akan
menempelkan complaint ke outlet yang salah suatu hari nanti, dan tidak ada yang
akan tahu kapan. Nama yang tidak punya padanan **tidak ditebak**: barisnya
tetap masuk dengan `outlet_id` kosong dan nama aslinya di `legacy_outlet_name`.

**Complaint Close tanpa tanggal tutup tidak diisi tanggal masuk.** Waktu
penyelesaiannya tidak diketahui, bukan nol. Mengisinya supaya kolomnya terlihat
rapi membuat laporan mengumumkan penyelesaian instan yang tidak pernah terjadi.

**Kanal masuk tidak ada di spreadsheet.** Baris impor memakai kanal `impor`,
yang sengaja tidak ada di daftar kanal intake — kasir tidak boleh bisa
memilihnya untuk keluhan yang baru saja diceritakan pelanggan di depannya.

**Nilai yang tidak dikenali dicatat, bukan ditebak.** Barisnya tetap masuk,
nilainya jatuh ke tempat yang jujur (`lainnya`, kosong), dan keanehannya muncul
di bagian 2 dan 6 laporan. Setiap satu adalah kandidat nilai yang kurang di
sistem — itulah gunanya impor ini dikerjakan sebelum kasir memakai sistemnya.

## Laporan hasil impor

Keluaran perintah ini bukan kata "berhasil", melainkan berkas laporannya.
Tujuh bagian: jumlah baris (termasuk berapa yang dilewati karena lebih tua dari
tanggal potong) beserta alasan tiap kegagalan, nilai tanpa padanan enum per
kolom, bentuk nomor nota, tingkat pengisian kolom `Pelaku` (untuk ambang
API-24), sebaran per bulan CSV vs basis data, biaya tercatat dipecah menurut
era NEVIRA, dan daftar keanehan.

Baris yang gagal dilaporkan dengan **nomor baris dan jenis galatnya saja** —
mis. `baris 7: gagal disimpan (QueryException 23000)`. Pesan galat aslinya
tidak ikut, dan itu disengaja: `QueryException::getMessage()` menyulih seluruh
nilai baris ke dalam SQL yang ditampilkannya, jadi nama pelapor, uraian
keluhan, dan path basis data akan ikut masuk ke berkas yang boleh ditempel ke
issue. Untuk mengetahui sebabnya, buka baris itu di berkas sumbernya.

## Memperbaiki hasil impor yang sudah masuk

Impor ulang **melewati** baris yang sudah ada — ia tidak memperbarui apa pun.
Jadi memperbaiki peta padanan outlet lalu menjalankan impor lagi tidak akan
mengubah baris yang sudah masuk; laporannya hanya akan bilang semuanya
dilewati. Hal yang sama berlaku untuk `--sejak` yang dimundurkan: baris lama
memang jadi ikut, tapi baris yang sudah ada tidak diperbarui.

Jalan yang benar adalah hapus dulu, baru impor lagi:

```bash
php artisan complaint:import-hapus spreadsheet-2026-08
php artisan complaint:import "DATA COMPLAINT.csv" --sumber=spreadsheet-2026-08 --tulis
```

Ini aman selama impornya belum dipakai: complaint hasil impor belum punya
catatan penanganan dari petugas. Begitu ada orang yang menambah catatan atau
mengubah status pada baris impor, penghapusan itu ikut membuang pekerjaannya —
perbaiki barisnya lewat aplikasi, jangan impor ulang.
