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

    /*
    | Koneksi database yang dipakai `backup:verify` MEMULIHKAN dump — wajib
    | memakai pengguna database yang berbeda dari aplikasi, dan pengguna itu
    | tidak boleh punya hak tulis di database produksi.
    |
    | Itu pengaman utamanya, bukan pembacaan isi dump: `--one-database` hanya
    | mengikuti pernyataan `USE`, sementara dump yang menulis dengan nama
    | database lengkap (`INSERT INTO lessworry_care.complaints ...`) lewat
    | begitu saja. Yang menahannya cuma hak akses.
    |
    | Kosong = `backup:verify` menolak berjalan di MySQL. Itu disengaja.
    | Jalur SQLite tidak memakainya: di sana restore dikurung open_basedir.
    */
    'verify_connection' => env('BACKUP_VERIFY_CONNECTION'),

    // Detik. Dump besar butuh lebih lama daripada batas bawaan Symfony (60s).
    'timeout' => (int) env('BACKUP_TIMEOUT', 900),

];
