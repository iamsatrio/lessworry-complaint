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
    | Kategori baku. Nilai awal — final ditetapkan di issue API-6.
    */
    'categories' => [
        'hasil_cuci' => ['label' => 'Hasil Cuci', 'sub' => ['Masih kotor', 'Bau', 'Luntur', 'Rusak/sobek', 'Menyusut']],
        'barang_hilang' => ['label' => 'Barang Hilang', 'sub' => ['Item kurang', 'Tertukar pelanggan lain']],
        'keterlambatan' => ['label' => 'Keterlambatan', 'sub' => ['Telat selesai', 'Telat antar', 'Telat jemput']],
        'salah_tagih' => ['label' => 'Salah Tagih', 'sub' => ['Harga tidak sesuai', 'Berat tidak sesuai', 'Promo tidak masuk']],
        'sikap_petugas' => ['label' => 'Sikap Petugas', 'sub' => ['Pelayanan kasir', 'Pelayanan kurir']],
        'lainnya' => ['label' => 'Lainnya', 'sub' => []],
    ],

    'priorities' => [
        'urgent' => 'Mendesak',
        'high' => 'Tinggi',
        'medium' => 'Sedang',
        'low' => 'Rendah',
    ],

    /*
    | SLA dalam menit. Nilai awal — final ditetapkan di API-6.
    */
    'sla' => [
        'urgent' => ['response' => 30,   'resolution' => 240],
        'high' => ['response' => 60,   'resolution' => 480],
        'medium' => ['response' => 240,  'resolution' => 1440],
        'low' => ['response' => 480,  'resolution' => 2880],
    ],

    'statuses' => [
        'baru' => 'Baru',
        'ditangani' => 'Sedang Ditangani',
        'menunggu_pelanggan' => 'Menunggu Pelanggan',
        'selesai' => 'Selesai',
        'ditolak' => 'Ditolak',
    ],

    'open_statuses' => ['baru', 'ditangani', 'menunggu_pelanggan'],

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
        'keterlambatan' => ['Telat jemput'],
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
    | Batas wewenang kompensasi per peran, dalam rupiah. Final di API-6.
    */
    'compensation_limit' => [
        'admin' => PHP_INT_MAX,
        'kasir' => 50000,
        'customer_care' => 200000,
        'divisi' => 0,
        'supervisor' => PHP_INT_MAX,
    ],
];
