<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
| Sengaja di luar grup "web".
|
| Middleware web memulai sesi setiap permintaan, dan SESSION_DRIVER=database:
| pemantau yang memanggil tiap menit akan meninggalkan satu baris sesi baru
| setiap kali — 1.440 baris sampah per hari, dari endpoint yang justru dibuat
| untuk memantau kesehatan sistem.
|
| Lajunya tetap dibatasi. Endpoint ini terbuka tanpa autentikasi, dan tidak
| ada alasan sah memanggilnya lebih dari sekali per detik.
*/
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/health', HealthController::class)->name('health');
});
