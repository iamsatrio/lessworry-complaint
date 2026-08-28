<?php

return [
    /*
    | Integrasi NEVIRA POS — HANYA BACA.
    | Sistem ini tidak pernah menulis, mengubah, atau menghapus data di NEVIRA.
    */
    'base_url' => env('NEVIRA_API_BASE', 'https://api.nevira.id/api'),
    'email'    => env('NEVIRA_EMAIL'),
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
        1  => 'Siap Diantar',
        2  => 'Diantar',
        3  => 'Siap Dijemput',
        4  => 'Dijemput',
        5  => 'Tiba di Outlet',
        6  => 'Batal',
        7  => 'Selesai',
        71 => 'Selesai Diantar',
        73 => 'Selesai Dijemput',
    ],

    /*
    | Alasan pembatalan, dipakai saat status = 6.
    */
    'delivery_cancel_type' => [
        'SYSTEM'              => 'Dibatalkan sistem',
        'COURIER'             => 'Dibatalkan kurir',
        'COURIER_RESCHEDULE'  => 'Dijadwalkan ulang kurir',
    ],

    // Matikan untuk bekerja tanpa koneksi NEVIRA (mode pengembangan).
    'enabled' => filter_var(env('NEVIRA_ENABLED', true), FILTER_VALIDATE_BOOL),
];
