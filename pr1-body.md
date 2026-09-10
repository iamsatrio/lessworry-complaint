Masukan nomor 1 dari API-62 — cacat, dan sengaja dipisah dari empat masukan lainnya karena kecil dan tidak bergantung pada apa pun di antaranya.

## Yang rusak

`resources/views/components/grafik/garis.blade.php` memberi keterangan tiap titik lewat `<title>` SVG. Secara teknis itu keterangan saat ditunjuk. Praktiknya tidak terpakai:

- peramban menundanya sekitar satu detik,
- yang muncul tooltip sistem operasi — kecil, di luar gaya halaman,
- sasarannya bulatan 4,5 satuan. Di layar sentuh praktis mustahil, di tetikus pun meleset.

## Yang berubah

**Tooltip sungguhan.** Kotak kecil bergaya halaman, digambar di server bersama grafiknya, dimunculkan CSS `:hover`. Muncul seketika, memuat label periode di baris pertama dan nilainya di baris kedua. Tetap tanpa satu baris JavaScript — grafik ini masih SVG yang sudah jadi saat halaman dikirim.

**Sasaran tunjuk tak terlihat ±28px.** Jari-jarinya dihitung, bukan ditebak. Satuan viewBox bukan piksel: SVG diregangkan ke lebar kartu, jadi satu satuan bernilai `lebarLayar / 880` piksel. Jari-jarinya diturunkan dari skala TERKECIL yang mungkin, sehingga garis tengahnya ≥28px di 1440px maupun di 390px.

**Lebar minimum kanvas ikut jumlah titik.** Ini konsekuensi yang tidak bisa dihindari: kanvas 560px dengan 31 titik hanya punya 18px per titik — sasaran 28px di situ mustahil secara aritmetika, berapa pun jari-jari yang ditulis. Jadi yang mengalah lebar kanvasnya (digeser mendatar, seperti sekarang), bukan sasarannya yang dipampatkan. **Untuk grafik bulanan yang dipakai hari ini lebarnya tidak berubah** — ambang itu baru menggigit di atas ~19 titik, dan itu baru terjadi kalau masukan nomor 2 (rentang waktu harian/mingguan) masuk.

**`<title>` dipertahankan** sebagai cadangan untuk pembaca layar dan untuk saat CSS gagal dimuat.

## Yang tidak berubah

Grafik batang tidak disentuh. Batangnya setinggi 19 satuan dan nilainya sudah tertulis sebagai label yang selalu terlihat di sebelahnya — ia tidak punya cacat yang sama.

## Test

`tests/Feature/TooltipGrafikTest.php`, 8 test. Gagal sebelum perubahan ini.

Yang dijaga di sana bukan "tooltipnya muncul" — itu tidak bisa dibuktikan test PHP — melainkan hal-hal yang bisa: bahwa keterangannya ada di dalam HTML yang dikirim server, bahwa aritmetika sasarannya benar-benar menghasilkan ≥28px di kedua lebar layar, bahwa sasarannya tidak saling menimpa (kalau menimpa, menunjuk Agustus terbaca September), dan bahwa kotaknya tidak terpotong tepi gambar.

## Diperiksa sendiri

```
vendor/bin/pint --test                        lolos
vendor/bin/phpstan analyse --memory-limit=1G  0 error
php artisan test                              571 lolos
```

Diperiksa juga di peramban sungguhan pada 1440px dan 390px dengan data contoh 18 bulan.
