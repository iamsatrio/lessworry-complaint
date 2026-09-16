<?php

return [
    /*
    | Integrasi NEVIRA POS — HANYA BACA.
    | Sistem ini tidak pernah menulis, mengubah, atau menghapus data di NEVIRA.
    */
    'base_url' => env('NEVIRA_API_BASE', 'https://api.nevira.id/api'),
    'email' => env('NEVIRA_EMAIL'),
    'password' => env('NEVIRA_PASSWORD'),

    /*
    | NEVIRA punya dua pintu login, dan akun hanya bisa lewat pintu yang
    | sesuai platform miliknya:
    |
    |   /login        -> platform POS (kasir, produksi)
    |   /admin/login  -> platform Back Office
    |
    | Akun yang mencoba pintu yang salah ditolak dengan HTTP 400
    | "Anda tidak memiliki akses untuk platform ini!".
    | Service account integrasi memakai Back Office.
    */
    'login_endpoint' => env('NEVIRA_LOGIN_ENDPOINT', '/admin/login'),

    // Bearer token di-cache supaya tidak login berulang tiap request.
    'token_ttl_minutes' => (int) env('NEVIRA_TOKEN_TTL', 55),

    'timeout' => (int) env('NEVIRA_TIMEOUT', 15),

    /*
    | Daftar karyawan per outlet hampir tidak berubah dalam sehari, sementara
    | halaman complaint dibuka berkali-kali. Disimpan sebentar supaya tidak
    | menghabiskan jatah panggilan NEVIRA.
    */
    'outlet_staff_ttl_minutes' => (int) env('NEVIRA_OUTLET_STAFF_TTL', 10),

    /*
    | Kode status pengantaran NEVIRA. Diambil dari peta di back office
    | NEVIRA sendiri, bukan tebakan.
    */
    'delivery_status' => [
        1 => 'Siap Diantar',
        2 => 'Diantar',
        3 => 'Siap Dijemput',
        4 => 'Dijemput',
        5 => 'Tiba di Outlet',
        6 => 'Batal',
        7 => 'Selesai',
        71 => 'Selesai Diantar',
        73 => 'Selesai Dijemput',
    ],

    /*
    | Kode status yang berarti perjalanan kurir SELESAI.
    |
    | Peta di atas menyebut 71 "Selesai Diantar", dan itulah kode yang dulu
    | diasumsikan menandai order antar yang sudah sampai. Data hidup tidak
    | memakainya: 800 baris /deliveries-transactions (4 halaman terpisah,
    | diperiksa 16 September 2026) berisi status 7, 1, 5, 6, dan 3 — tidak
    | satu pun 71 atau 73. Menyaring dengan 71 saja mengembalikan nol baris,
    | dan SELURUH order antar akan tercatat "tanggal pengambilan tidak
    | diketahui" tanpa ada yang tahu kenapa.
    |
    | 71 tetap diterima kalau-kalau ada baris lama yang memakainya. Yang
    | menentukan sekarang adalah 7. (API-48)
    */
    'delivery_done_status' => [7, 71],

    /*
    | Satu nota bisa punya dua perjalanan kurir: MENJEMPUT cucian kotor dari
    | pelanggan, dan MENGANTAR cucian bersih kembali. Keduanya berakhir
    | dengan status 7, jadi statusnya saja tidak cukup untuk membedakan.
    | Yang membedakan `initial_status`:
    |
    |   '1' (Siap Diantar)  -> perjalanan ANTAR; selesainya berarti barang
    |                          sampai ke pelanggan
    |   '3' (Siap Dijemput) -> perjalanan JEMPUT; selesainya berarti barang
    |                          tiba di outlet
    |
    | Dari 800 baris yang diperiksa: 509 berawal '1', 291 berawal '3'. Salah
    | membaca ini mencatat tanggal barang MASUK sebagai tanggal barang
    | DITERIMA pelanggan — dan jarak harinya jadi negatif atau nol untuk
    | complaint yang sebenarnya datang berminggu-minggu kemudian. (API-48)
    */
    'delivery_initial_antar' => '1',

    /*
    | Jejak serah terima barang, di `services[].service_process_log`.
    |
    | Inilah satu-satunya tempat NEVIRA mencatat kapan barang berpindah dari
    | outlet ke pelanggan:
    |
    |   diambil_customer -> "Diambil oleh Customer", dengan foto bukti
    |   diantar_kurir    -> "Diantar kurir oleh <nama>", barang keluar outlet
    |
    | `data.completion_date` pada transaksi TIDAK dipakai dan tidak boleh
    | dipakai: kosong di 953 dari 953 transaksi yang diperiksa (April–September
    | 2026), termasuk 702 yang berstatus COMPLETED. Kolomnya ada di skema dan
    | tidak pernah diisi. (Gerbang bukti API-48)
    */
    'handover_activities' => ['diambil_customer', 'diantar_kurir'],

    /*
    | Alasan pembatalan, dipakai saat status = 6.
    */
    'delivery_cancel_type' => [
        'SYSTEM' => 'Dibatalkan sistem',
        'COURIER' => 'Dibatalkan kurir',
        'COURIER_RESCHEDULE' => 'Dijadwalkan ulang kurir',
    ],

    // Matikan untuk bekerja tanpa koneksi NEVIRA (mode pengembangan).
    'enabled' => filter_var(env('NEVIRA_ENABLED', true), FILTER_VALIDATE_BOOL),
];
