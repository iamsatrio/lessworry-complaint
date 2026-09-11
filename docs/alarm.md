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
final class TagihanJatuhTempo implements Alarm
{
    public function kunci(): string { return 'tagihan.jatuh_tempo'; }

    public function judul(): string { return 'Tagihan jatuh tempo'; }

    public function tindakan(): string { return 'Bayar sebelum layanan diputus.'; }

    public function periksa(Lingkup $lingkup): ?Nyala
    {
        // null = PADAM. Jangan mengembalikan Nyala dengan jumlah 0.
    }
}
```

2. Daftarkan di `config/complaint.php` → `alarms.terdaftar`. Urutannya urutan
   tampil. Kelas yang tidak mengimplementasikan `Alarm` membuat papan melempar
   saat dibuka — papan yang diam-diam kehilangan satu alarm terlihat sama
   dengan papan yang alarmnya padam.

3. Tulis testnya. Yang wajib ada: keadaan yang menyalakan, dan keadaan yang
   **tidak** menyalakan.

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
