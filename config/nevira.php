<?php

return [
    /*
    | Integrasi NEVIRA POS — HANYA BACA.
    | Sistem ini tidak pernah menulis, mengubah, atau menghapus data di NEVIRA.
    */
    'base_url' => env('NEVIRA_API_BASE', 'https://api.nevira.id/api'),
    'email'    => env('NEVIRA_EMAIL'),
    'password' => env('NEVIRA_PASSWORD'),

    'login_endpoint' => env('NEVIRA_LOGIN_ENDPOINT', '/login'),

    // Bearer token di-cache supaya tidak login berulang tiap request.
    'token_ttl_minutes' => (int) env('NEVIRA_TOKEN_TTL', 55),

    'timeout' => (int) env('NEVIRA_TIMEOUT', 15),

    // Matikan untuk bekerja tanpa koneksi NEVIRA (mode pengembangan).
    'enabled' => filter_var(env('NEVIRA_ENABLED', true), FILTER_VALIDATE_BOOL),
];
