<?php

return [

    /*
    | Kanal masuk complaint. Sesuai API-4: direct kasir, WA outlet, WA customer care.
    */
    'channels' => [
        'kasir'     => 'Direct Kasir',
        'wa_outlet' => 'WA Outlet',
        'wa_cc'     => 'WA Customer Care',
    ],

    /*
    | Kategori — taksonomi yang benar-benar dipakai tim. (API-25, keputusan
    | API-18 nomor 5)
    |
    | Urutannya mengikuti porsi kemunculan pada 1.032 baris data nyata, supaya
    | pilihan yang paling sering dipakai berada paling dekat dengan jempol.
    | `Salah Tagih` dan `Sikap Petugas` dibuang: nol kemunculan.
    |
    | Sub-kategori yang dulu menumpang di bawah `hasil_cuci` sudah naik jadi
    | kategori sendiri (Kurang Bersih, Berbau) — tidak boleh muncul dua kali.
    */
    'categories' => [
        'barang_rusak'    => ['label' => 'Barang Rusak',    'sub' => ['Luntur', 'Rusak/sobek', 'Menyusut']],
        'kurang_bersih'   => ['label' => 'Kurang Bersih',   'sub' => []],
        'barang_hilang'   => ['label' => 'Barang Hilang',   'sub' => ['Item kurang']],
        'berbau'          => ['label' => 'Berbau',          'sub' => []],
        'kurang_rapih'    => ['label' => 'Kurang Rapih',    'sub' => []],
        'barang_tertukar' => ['label' => 'Barang Tertukar', 'sub' => []],
        'terlambat'       => ['label' => 'Terlambat',       'sub' => ['Telat selesai', 'Telat antar', 'Telat jemput']],
        'lainnya'         => ['label' => 'Lainnya',         'sub' => []],
    ],

    /*
    | Bobot keluhan — tiga tingkat, sama dengan dropdown yang sudah dipakai
    | tim (Ringan 58% · Sedang 12% · Berat 30%).
    |
    | Ini MENGGANTIKAN `priority` empat tingkat, bukan menambahinya. Dua sumbu
    | penilaian berarti dua kasir menilai keluhan yang sama secara berbeda.
    */
    'bobot' => [
        'ringan' => 'Ringan',
        'sedang' => 'Sedang',
        'berat'  => 'Berat',
    ],

    /*
    | SLA. Respon pertama satu angka untuk semua bobot — janji 1x24 jam itu
    | sudah beredar ke pelanggan. Penyelesaian dihitung dalam HARI, bukan jam:
    | penyelesaian nyata tim diukur dalam hari, dan target berjam-jam membuat
    | seluruh papan merah di hari pertama lalu berhenti dibaca. (API-18 #3)
    */
    'sla' => [
        'response_hours'  => 24,
        'resolution_days' => [
            'ringan' => 2,
            'sedang' => 3,
            'berat'  => 5,
        ],
    ],

    /*
    | Status yang dilihat pengguna tinggal tiga — kosakata yang sudah dipakai
    | tim. "Menunggu Pelanggan" dan "Ditolak" turun jadi penanda di bawah ini,
    | bukan status tersendiri. (API-18 #6)
    */
    'statuses' => [
        'open'     => 'Open',
        'handling' => 'Handling',
        'close'    => 'Close',
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
    | Layanan yang dikeluhkan. Sudah dipakai tim; Kiloan 61%. Wajib diisi saat
    | intake supaya laporan bisa menunjukkan layanan mana yang paling sering
    | bermasalah.
    */
    'layanan' => [
        'kiloan'           => 'Kiloan',
        'satuan_cloth'     => 'Satuan Cloth',
        'satuan_bedding'   => 'Satuan Bedding',
        'satuan_non_cloth' => 'Satuan Non Cloth',
    ],

    /*
    | Tindak lanjut penyelesaian — dropdown, bukan teks bebas. Teks bebas
    | membuat "mana yang paling lama" harus dihitung tangan. Diisi saat
    | penyelesaian, bukan saat intake.
    */
    'tindak_lanjut' => [
        'proses_ulang'   => 'Proses Ulang',
        'compensate'     => 'Compensate',
        'terkonfirmasi'  => 'Terkonfirmasi',
        'voucher'        => 'Voucher',
        'delivery_ulang' => 'Delivery Ulang',
        'pickup_ulang'   => 'Pickup Ulang',
        'tracking'       => 'Tracking',
        'repaint'        => 'Repaint',
        'repair'         => 'Repair',
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
        'kasir'         => 'Kasir',
        'produksi'      => 'Produksi / Cuci',
        'kurir'         => 'Kurir',
        'customer_care' => 'Customer Care',
        'lainnya'       => 'Lainnya',
    ],

    'divisions' => [
        'produksi' => 'Operasional Cuci / Produksi',
        'kurir'    => 'Kurir / Antar Jemput',
        'keuangan' => 'Keuangan',
    ],

    /*
    | Batas wewenang kompensasi per peran, dalam rupiah. Berlaku saat mengubah
    | angkanya, DAN saat menutup complaint yang memegang angka itu. (API-25)
    */
    'compensation_limit' => [
        'kasir'          => 50000,
        'customer_care'  => 200000,
        'divisi'         => 0,
        'supervisor'     => PHP_INT_MAX,
    ],
];
