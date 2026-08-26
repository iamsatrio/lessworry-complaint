<?php

namespace App\Providers;

use Carbon\Carbon;
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

        //
    }
}
