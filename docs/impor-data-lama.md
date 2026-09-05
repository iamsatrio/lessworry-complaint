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
Yang mengenalinya adalah pasangan `import_source` + `import_row`, dan pasangan
itu unik di tingkat basis data.

| Bendera | Arti |
|---|---|
| `--tulis` | Benar-benar menyimpan. Tanpa ini hanya menghitung. |
| `--dry-run` | Paksa hanya menghitung, walau `--tulis` diberikan. |
| `--sumber=` | Penanda asal. Bawaannya nama berkas. Dipakai juga untuk menghapus. |
| `--laporan=` | Tujuan berkas laporan. Bawaannya `storage/app/impor/`. |

## Jalan mundur

Satu perintah membuang seluruh hasil satu impor:

```bash
php artisan complaint:import-hapus spreadsheet-2026-08
```

Ini satu-satunya penghapusan complaint yang diizinkan di sistem ini, dan hanya
mengenai baris yang punya `import_source`. Complaint yang dicatat orang tidak
bisa disentuh dari sini.

## Yang perlu diketahui tentang datanya

**Nomor nota tidak ditautkan ke NEVIRA.** Tidak satu pun nomor nota data lama
berformat `INV/`; bentuknya angka polos 3–5 digit, kadang dibubuhi nama bulan
(`2138 (Juli)`) justru karena angkanya sendiri tidak unik. Semuanya disimpan
apa adanya di `legacy_nota_number`. Menaruhnya di `nevira_transaction_id` akan
menempelkan keluhan ini ke order pelanggan lain.

**Outlet dicocokkan lewat peta padanan yang ditulis eksplisit** di
`PemetaBarisImpor::PADANAN_OUTLET` — `Hampton GS`, `Park Sepong` (salah ketik
di sumbernya), `Jatipadang`. Bukan pencocokan samar: pencocokan samar akan
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

Keluaran perintah ini bukan kata "berhasil", melainkan berkas laporannya. Enam
bagian: jumlah baris beserta alasan tiap kegagalan, nilai tanpa padanan enum
per kolom, bentuk nomor nota, tingkat pengisian kolom `Pelaku` (2026 terpisah,
untuk ambang API-24), sebaran per bulan CSV vs basis data, dan daftar keanehan.

Baris yang gagal dilaporkan **dengan nomor barisnya saja**. Isinya tidak
dikutip — di dalamnya ada nama dan keluhan pelanggan.
