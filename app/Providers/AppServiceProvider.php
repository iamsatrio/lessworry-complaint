<?php

namespace App\Providers;

use Carbon\Carbon;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Antarmuka berbahasa Indonesia: tanggal dan waktu relatif ikut diterjemahkan.
        Carbon::setLocale('id');

        // Navigasi halaman memakai kelas CSS aplikasi ini, bukan kelas Tailwind
        // yang tidak pernah dimuat. Lihat view-nya untuk apa yang terjadi
        // sebelum ini di layar 390px. (API-38 #2)
        Paginator::defaultView('vendor.pagination.lessworry');
        Paginator::defaultSimpleView('vendor.pagination.lessworry');
    }
}
