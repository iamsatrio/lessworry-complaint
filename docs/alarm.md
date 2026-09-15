# Kerangka alarm — Dashboard Operations

Rujukan untuk menambah jenis alarm baru. Keputusan rancangannya ada di **API-72**;
alur hidup alarmnya digambar di **API-71 alur 2**.

## Apa yang dijawab halamannya

Satu pertanyaan: **ada yang perlu ditangani sekarang?**

Angka pencapaian, grafik, dan tren tidak masuk ke sini — mereka dibaca sebulan
sekali, bukan tiap pagi. Ukuran yang menentukan rancangannya: **hari normal
selesai dalam 30 detik.** Halaman yang menuntut lima menit setiap pagi akan
berhenti dibuka dalam dua minggu.

## Tiga keadaan

```
PADAM ──keadaan terpenuhi──► MENYALA ──ditandai──► DITANGANI
  ▲                             ▲                     │
  └────keadaan hilang───────────┴──keadaan masih ada───┘
```

- Yang **memadamkan** alarm hanya keadaannya berubah. Bukan tombol.
- Menandai *"sudah saya tangani"* menyatakan **aku memegangnya**, bukan
  *masalahnya selesai*. Terlihat semua orang, lengkap dengan nama dan jam.
- Penandaan berlaku **satu hari operasional** (`Asia/Jakarta`, lihat
  `PapanAlarm::tanggalOperasional()`). Kalau keadaannya masih ada besok,
  alarmnya menyala lagi — tanpa satu pun pekerjaan terjadwal.

Ini **bukan sistem penugasan**: tidak ada penetapan pemilik, tenggat per alarm,
atau notifikasi ke orang tertentu. Itu sistem tiket, dan Less Worry sudah punya
satu.

## Menambah satu alarm

1. Buat kelas di `app/Alarms/` yang mengimplementasikan `App\Alarms\Alarm`:

```php
final class SaldoKoinMenipis implements Alarm
{
    public function kunci(): string { return 'nevira.saldo_koin'; }

    public function judul(): string { return 'Saldo koin NEVIRA menipis'; }

    public function tindakan(): string { return 'Isi ulang sebelum kasir tidak bisa menerbitkan nota.'; }

    public function periksa(Lingkup $lingkup): ?Nyala
    {
        // null = PADAM. Jangan mengembalikan Nyala dengan jumlah 0.
    }
}
```

`App\Alarms\TagihanJatuhTempo` adalah contoh yang sudah jalan untuk alarm yang
barisnya BUKAN complaint — lihat di sana kalau alarmmu berdiri di atas tabel
lain. (API-73)

2. Daftarkan di `config/complaint.php` → `alarms.terdaftar`. Urutannya urutan
   tampil. Kelas yang tidak mengimplementasikan `Alarm` membuat papan melempar
   saat dibuka — papan yang diam-diam kehilangan satu alarm terlihat sama
   dengan papan yang alarmnya padam.

3. Tulis testnya. Yang wajib ada: keadaan yang menyalakan, dan keadaan yang
   **tidak** menyalakan.

## Bentuk isi kartu

Kartu alarm merender tiga kolom, dan ketiganya datang dari `Nyala` — bukan
ditebak kartunya:

| Bagian `Nyala` | Isi |
|---|---|
| `daftar[]` | satu baris: `id`, `tiket` (label), `outlet`, `umur` (kolom waktu), `tautan` |
| `kolom` | judul ketiga kolom. Bawaannya `Nyala::KOLOM_COMPLAINT` = Tiket · Outlet · Lama |
| `tautanSemua` | tujuan "dan N lagi". Null berarti jumlahnya disebut tanpa tautan |

Nama kunci `tiket` dan `umur` datang dari dua alarm complaint yang pertama,
tapi artinya umum. Alarm yang barisnya bukan complaint mengganti **judul**
kolomnya lewat `kolom`, bukan bentuk barisnya — satu bentuk baris berarti satu
kartu yang merender semuanya. `TagihanJatuhTempo` memakainya untuk
Tagihan · Outlet · Jatuh tempo.

`tautan` wajib diisi tiap baris. Kartu tidak boleh menebak "ini pasti
complaint": tebakan itu menerbitkan tautan ke complaint untuk baris tagihan,
dan yang keluar 404 yang terlihat seperti data hilang.

## Aturan yang mengikat

- **Ambil data lewat `Lingkup::complaints()`**, jangan menulis kueri sendiri.
  Ia memanggil `Complaint::visibleTo($user)`. Alarm yang mengambil jalur sendiri
  membocorkan outlet orang lain tanpa satu pun halaman lain terlihat salah —
  celah yang paling lama tidak ketahuan.
- **Kunci alarm tidak boleh diubah setelah dipakai.** Penandaan disimpan atas
  nama kunci itu (`penanda_alarm.alarm`); menggantinya membuat alarm menyala
  kembali seolah tidak pernah dipegang siapa pun.
- **Saring di SQL, bukan di memori.** Halaman ini dibuka tiap pagi dan tabel
  complaint sudah berisi ratusan baris; `->get()->filter(...)` akan menariknya
  seluruhnya.
- **Kalau alarmmu punya pasangan method di model** (seperti
  `ComplaintLewatSla` dengan `Complaint::isOverdue()`), tulis test yang menjaga
  keduanya sepakat baris demi baris. Dua tempat yang menjawab pertanyaan yang
  sama dengan caranya sendiri akan berpisah diam-diam.
- **Kalau alarmmu menghitung tanggal, hitung di kalender operasional.**
  Aplikasi berjalan di UTC dan hari UTC berganti pukul 07.00 WIB — di tengah
  jam kerja. Jatuh tempo dan "hari ini" yang dibandingkan dengan zona berbeda
  selisihnya bergeser tujuh jam, dan pembulatan ke bawah memakan satu hari:
  "telat 6 hari" tertulis "telat 5 hari", tiap hari, tanpa satu pun tanda.
  `PeriodeTagihan::hariIni()` mengembalikan tanggal polos untuk alasan itu.
  (API-73)

## Wewenang

`dashboard.view`, daftar perannya di `config/complaint.php` →
`dashboard_roles`. Kasir dan Customer Care tidak memilikinya, dan **menu
Dashboard tidak dirender** untuk mereka — bukan menu yang membalas 403.

Cakupan outlet tetap berlaku di dalam halaman kalau kelak peran ber-outlet
masuk daftar itu; `AlarmFilterRequest` menolak saringan `?outlet=` di luar
cakupan pemintanya, dan `tests/Feature/AlarmWewenangTest.php` sudah mengujinya
untuk keadaan yang belum ada itu.

## Setelan

| env | bawaan | arti |
|---|---|---|
| `ALARM_BELUM_DIPEGANG_JAM` | `4` | umur minimum complaint tanpa pemilik sebelum alarmnya menyala. **Tebakan** — uji coba lapangan (API-10) yang memperbaikinya |
| `ALARM_ZONA_WAKTU` | `Asia/Jakarta` | zona yang menentukan kapan "besok" mulai |

## Tagihan bulanan — pengecualian yang disebut, bukan dilanggar diam-diam

`TagihanJatuhTempo` menyaring di PHP, bukan di SQL. Aturan "saring di SQL" di
atas berdiri karena tabel complaint berisi ratusan baris dan tumbuh terus;
tabel `tagihan` adalah daftar tulisan tangan berisi belasan baris, dan aturan
"hari terakhir bulan" ditulis berbeda di sqlite dan MySQL. Jumlah kuerinya
tetap dua berapa pun banyak tagihannya. Alasan lengkapnya ada di
`App\Services\PeriodeTagihan`.

Ia juga satu-satunya alarm yang benar-benar PADAM saat ditandai. Itu bukan
ketidakkonsistenan: menandai complaint "sudah saya tangani" berarti *aku
memegangnya* dan keadaannya belum berubah, sementara menandai tagihan "sudah
dibayar" memang mengubah keadaannya. Yang memadamkan alarm tetap keadaannya
berubah. Penandaannya PER PERIODE (`pembayaran_tagihan.periode`, 'YYYY-MM'),
bukan per hari operasional seperti `penanda_alarm`.

Wewenangnya juga dua, bukan satu: `dashboard.view` membaca, dan
`dashboard.manage_tagihan` — daftar perannya di `config/complaint.php` →
`tagihan_roles` — mengubah dan menandai dibayar.
