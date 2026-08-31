<?php

return [

    /*
    | Direktori tujuan dump. HANYA isi direktori ini yang boleh dihapus oleh
    | rotasi — lihat BackupDatabase::rotasi(). Taruh di luar direktori yang
    | disajikan web; storage/ sudah memenuhi syarat itu.
    */
    'path' => env('BACKUP_PATH') ?: storage_path('app/backups'),

    /*
    | Berapa dump terakhir yang disimpan. Yang lebih tua dihapus setelah dump
    | baru berhasil — tidak pernah sebelum, dan tidak pernah kalau dumpnya gagal.
    */
    'keep' => (int) env('BACKUP_KEEP', 7),

    /*
    | Binari MySQL. Diisi kalau tidak ada di PATH milik pengguna cron —
    | PATH cron lebih pendek daripada PATH shell interaktif, dan itu penyebab
    | paling sering "backup jalan di terminal, diam di cron".
    */
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    'mysql' => env('BACKUP_MYSQL', 'mysql'),

    // Detik. Dump besar butuh lebih lama daripada batas bawaan Symfony (60s).
    'timeout' => (int) env('BACKUP_TIMEOUT', 900),

];
