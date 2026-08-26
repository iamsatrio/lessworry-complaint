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

    // Matikan untuk bekerja tanpa koneksi NEVIRA (mode pengembangan).
    'enabled' => filter_var(env('NEVIRA_ENABLED', true), FILTER_VALIDATE_BOOL),
];
