<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\BatasiLajuHealth;
use Illuminate\Support\Facades\Route;

/*
| Sengaja di luar grup "web".
|
| Middleware web memulai sesi setiap permintaan, dan SESSION_DRIVER=database:
| pemantau yang memanggil tiap menit akan meninggalkan satu baris sesi baru
| setiap kali — 1.440 baris sampah per hari, dari endpoint yang justru dibuat
| untuk memantau kesehatan sistem.
|
| Lajunya tetap dibatasi, tapi bukan dengan middleware `throttle` bawaan:
| throttle memakai cache store bawaan, dan store bawaan produksi adalah
| `database`. Rute yang gunanya melaporkan database yang mati tidak boleh
| menyentuh database sebelum sampai ke controllernya. Lihat BatasiLajuHealth.
*/
Route::middleware(BatasiLajuHealth::class)->group(function () {
    Route::get('/health', HealthController::class)->name('health');
});
