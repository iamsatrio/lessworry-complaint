<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Backup database harian.
|
| Dini hari, saat outlet tutup: dump paling ringan dampaknya ketika tidak ada
| yang menulis. withoutOverlapping() adalah lapis kedua di atas kunci berkas
| di dalam perintahnya sendiri — dump yang belum selesai tidak boleh disusul
| jadwal berikutnya.
|
| Perlu satu jadwal per menit di hosting, kalau belum ada. Di cPanel dipasang
| lewat menu Cron Jobs, dengan path PHP dan path aplikasi ditulis LENGKAP —
| cron tidak membaca ~/.bashrc:
|   * * * * * cd /home/<user>/care && /path/ke/php84 artisan schedule:run >/dev/null 2>&1
| Selengkapnya: docs/deploy-care-lessworry.md bagian 12.
*/
Schedule::command('backup:database')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onFailure(fn () => Log::error('Jadwal backup:database gagal dijalankan.'));
