<?php

return [

    /*
    | Kanal masuk complaint. Sesuai API-4: direct kasir, WA outlet, WA customer care.
    */
    'channels' => [
        'kasir' => 'Direct Kasir',
        'wa_outlet' => 'WA Outlet',
        'wa_cc' => 'WA Customer Care',
    ],

    /*
    | Kanal untuk data yang kanalnya TIDAK PERNAH DICATAT — spreadsheet lama
    | tidak punya kolomnya. (API-28)
    |
    | Terpisah dari `channels` supaya tidak muncul di dropdown intake:
    | kasir tidak boleh bisa memilih "impor" untuk keluhan yang baru saja
    | diceritakan pelanggan di depannya. Labelnya tetap ada supaya laporan
    | per kanal tidak menampilkan kunci mentah.
    */
    'channels_legacy' => [
        'impor' => 'Tidak tercatat (impor data lama)',
    ],

    /*
    | Kategori — taksonomi yang benar-benar dipakai tim. (API-25, keputusan
    | API-18 nomor 5; angkanya dikoreksi dari CSV asli, 545 baris)
    |
    | URUTANNYA BAGIAN DARI DATANYA, bukan selera. Menurun menurut porsi 2026
    | (237 baris) supaya pilihan yang paling sering dipakai berada paling dekat
    | dengan jempol kasir. Jangan diurutkan ulang menurut abjad.
    |
    |   Kurang Bersih 27,8% · Barang Rusak 20,7% · Barang Hilang 13,9%
    |   Terlambat 12,2% · Berbau 8,4% · Lainnya 7,6% · Kurang Rapih 5,9%
    |   Barang Tertukar 3,4%
    |
    | `Salah Tagih` dan `Sikap Petugas` dibuang: nol kemunculan.
    |
    | Sub-kategori yang dulu menumpang di bawah `hasil_cuci` sudah naik jadi
    | kategori sendiri (Kurang Bersih, Berbau) — tidak boleh muncul dua kali.
    |
    | Enum, bukan teks bebas: di file aslinya `Kurang rapih` dan `Kurang Rapih`
    | tercatat sebagai dua nilai berbeda, dan satu hal yang sama jadi dua baris
    | di laporan.
    */
    'categories' => [
        'kurang_bersih' => ['label' => 'Kurang Bersih',   'sub' => []],
        'barang_rusak' => ['label' => 'Barang Rusak',    'sub' => ['Luntur', 'Rusak/sobek', 'Menyusut']],
        'barang_hilang' => ['label' => 'Barang Hilang',   'sub' => ['Item kurang']],
        'terlambat' => ['label' => 'Terlambat',       'sub' => ['Telat selesai', 'Telat antar', 'Telat jemput']],
        'berbau' => ['label' => 'Berbau',          'sub' => []],
        'lainnya' => ['label' => 'Lainnya',         'sub' => []],
        'kurang_rapih' => ['label' => 'Kurang Rapih',    'sub' => []],
        'barang_tertukar' => ['label' => 'Barang Tertukar', 'sub' => []],
    ],

    /*
    | Bobot keluhan — tiga tingkat, sama dengan dropdown yang sudah dipakai
    | tim. Porsi 2026: Ringan 52,3% · Berat 37,6% · Sedang 10,1%.
    |
    | Ini MENGGANTIKAN `priority` empat tingkat, bukan menambahinya. Dua sumbu
    | penilaian berarti dua kasir menilai keluhan yang sama secara berbeda.
    */
    'bobot' => [
        'ringan' => 'Ringan',
        'sedang' => 'Sedang',
        'berat' => 'Berat',
    ],

    /*
    | SLA. Respon pertama satu angka untuk semua bobot — janji 1x24 jam itu
    | sudah beredar ke pelanggan. Penyelesaian dihitung dalam HARI, bukan jam:
    | penyelesaian nyata tim diukur dalam hari, dan target berjam-jam membuat
    | seluruh papan merah di hari pertama lalu berhenti dibaca. (API-18 #3)
    */
    'sla' => [
        'response_hours' => 24,
        'resolution_days' => [
            'ringan' => 2,
            'sedang' => 3,
            'berat' => 5,
        ],
    ],

    /*
    | Status yang dilihat pengguna tinggal tiga — kosakata yang sudah dipakai
    | tim. "Menunggu Pelanggan" dan "Ditolak" turun jadi penanda di bawah ini,
    | bukan status tersendiri. (API-18 #6)
    */
    'statuses' => [
        'open' => 'Open',
        'handling' => 'Handling',
        'close' => 'Close',
    ],

    'open_statuses' => ['open', 'handling'],

    /*
    | Alasan penutupan. Tiketnya tetap Close; laporan tetap bisa memisahkan
    | yang selesai dari yang tidak berdasar.
    */
    'close_reasons' => [
        'selesai' => 'Selesai',
        'ditolak' => 'Ditolak',
    ],

    /*
    | Alasan jeda. Tiket tetap berstatus Handling, tapi hitungan SLA berhenti
    | selama jeda dan tenggatnya mundur sebanyak lama jeda saat dilanjutkan.
    */
    'pause_reasons' => [
        'menunggu_pelanggan' => 'Menunggu Pelanggan',
    ],

    /*
    | Layanan yang dikeluhkan. ENAM nilai, bukan empat — Kiloan punya tiga
    | varian yang tim catat terpisah, dan menggabungkannya membuang justru
    | perbedaan yang mau dilihat.
    |
    | Porsi 2026: Kiloan–Cuci Setrika 29,5% · Kiloan 17,7% · Kiloan–Cuci Lipat
    | 14,8% · Satuan Cloth 17,3% · Satuan Non Cloth 16,4% · Satuan Bedding 3,0%.
    | Keluarga Kiloan totalnya 62% — itu jumlah tiga layanan, bukan satu.
    |
    | Diurutkan per keluarga, bukan menurun murni: varian Kiloan berdampingan
    | supaya kasir tidak perlu menyisir daftar untuk membedakan Cuci Setrika
    | dari Cuci Lipat.
    |
    | Wajib diisi saat intake supaya laporan bisa menunjukkan layanan mana yang
    | paling sering bermasalah.
    */
    'layanan' => [
        'kiloan_cuset' => 'Kiloan – Cuci Setrika',
        'kiloan' => 'Kiloan',
        'kiloan_culip' => 'Kiloan – Cuci Lipat',
        'satuan_non_cloth' => 'Satuan Non Cloth',
        'satuan_cloth' => 'Satuan Cloth',
        'satuan_bedding' => 'Satuan Bedding',
    ],

    /*
    | Tindak lanjut penyelesaian — dropdown, bukan teks bebas. Teks bebas
    | membuat "mana yang paling lama" harus dihitung tangan. Diisi saat
    | penyelesaian, bukan saat intake.
    |
    | Menurun menurut porsi 2026: Proses ulang 38,0% · Tracking 19,8% ·
    | Terkonfirmasi 18,6% · Compensate 12,7% · Voucher 6,3% · Repair 2,1% ·
    | Delivery ulang 1,7% · Pickup ulang 0,4% · Repaint 0%.
    |
    | `repaint` tetap ada meski 0% pada 2026 — dua kejadian sepanjang 2025,
    | dan dua kejadian bukan nol. Membuangnya memaksa kasus berikutnya dicatat
    | sebagai sesuatu yang bukan dirinya.
    */
    'tindak_lanjut' => [
        'proses_ulang' => 'Proses ulang',
        'tracking' => 'Tracking',
        'terkonfirmasi' => 'Terkonfirmasi',
        'compensate' => 'Compensate',
        'voucher' => 'Voucher',
        'repair' => 'Repair',
        'delivery_ulang' => 'Delivery ulang',
        'pickup_ulang' => 'Pickup ulang',
        'repaint' => 'Repaint',
    ],

    /*
    | Nomor nota NEVIRA wajib diisi, dengan dua pengecualian yang sah.
    | Petugas harus memilih salah satunya secara sadar — tidak ada jalan
    | menyimpan complaint tanpa nota dan tanpa alasan.
    */
    'nota_exemptions' => [
        'belum_terbit' => 'Complaint keterlambatan penjemputan — nota belum terbit',
        'lebih_sebulan' => 'Transaksi lebih dari 1 bulan',
    ],

    /*
    | Kategori/sub-kategori yang secara wajar belum punya nota.
    | Dipakai untuk menyarankan pengecualian, bukan memberlakukannya diam-diam.
    */
    'no_nota_yet' => [
        'terlambat' => ['Telat jemput'],
    ],

    // Transaksi lebih tua dari ini dianggap boleh tanpa nota.
    'nota_max_age_days' => 30,

    /*
    | Peran seorang pelaku DALAM SATU KEJADIAN — bukan jabatannya
    | sehari-hari. Kasir yang kebetulan ikut mengantar tercatat sebagai
    | kurir untuk complaint itu. (API-19)
    */
    'responsible_roles' => [
        'kasir' => 'Kasir',
        'produksi' => 'Produksi / Cuci',
        'kurir' => 'Kurir',
        'customer_care' => 'Customer Care',
        'lainnya' => 'Lainnya',
    ],

    'divisions' => [
        'produksi' => 'Produksi',
        'kurir' => 'Kurir',
        'keuangan' => 'Keuangan',
    ],

    /*
    | Batas wewenang kompensasi per peran, dalam rupiah. Berlaku saat mengubah
    | angkanya, DAN saat menutup complaint yang memegang angka itu. (API-25)
    */
    'compensation_limit' => [
        'admin' => PHP_INT_MAX,
        'kasir' => 50000,
        'customer_care' => 200000,
        'divisi' => 0,
        'supervisor' => PHP_INT_MAX,
    ],
];
