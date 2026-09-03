<?php

return [

    /*
    | Cache store khusus /health — SENGAJA BUKAN store bawaan.
    |
    | Store bawaan produksi adalah `database` (config/cache.php). Kalau /health
    | ikut memakainya, pemadaman database membuat pemeriksaannya sendiri ikut
    | melempar galat: pemeriksaan `database` mengembalikan "error" dengan benar,
    | lalu pemeriksaan berikutnya menyentuh cache dan meledak jadi HTTP 500 —
    | tepat pada pemadaman yang paling perlu dibedakan.
    |
    | Dipakai untuk dua hal: hasil pemeriksaan NEVIRA, dan penghitung laju
    | rute /health. Keduanya harus hidup saat database mati.
    */
    'cache_store' => env('HEALTH_CACHE_STORE', 'file'),

    // Panggilan per menit per alamat IP. Pemantau memanggil sekali per menit.
    'rate_limit' => (int) env('HEALTH_RATE_LIMIT', 60),

];
