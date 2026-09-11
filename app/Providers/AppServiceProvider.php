<?php

namespace App\Providers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
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
        /*
         * Wewenang membuka Dashboard Operations. (API-72)
         *
         * Ability, bukan Policy: yang dijaga bukan sebuah model — tidak ada
         * objek "dashboard" untuk ditanyakan. Rutenya memakai
         * `can:dashboard.view` dan navigasinya memanggil method yang sama,
         * jadi tidak ada dua tempat yang bisa berbeda pendapat.
         */
        Gate::define('dashboard.view', fn (User $user): bool => $user->canViewDashboard());

        // Antarmuka berbahasa Indonesia: tanggal dan waktu relatif ikut diterjemahkan.
        Carbon::setLocale('id');

        // Navigasi halaman memakai kelas CSS aplikasi ini, bukan kelas Tailwind
        // yang tidak pernah dimuat. Lihat view-nya untuk apa yang terjadi
        // sebelum ini di layar 390px. (API-38 #2)
        Paginator::defaultView('vendor.pagination.lessworry');

        // defaultSimpleView SENGAJA tidak disetel ke view yang sama. View itu
        // memakai $elements dan $paginator->total()/firstItem()/lastItem();
        // paginator sederhana tidak mengirim $elements dan tidak punya
        // total(), jadi simplePaginate() pertama yang dipakai orang berikutnya
        // akan membalas "Undefined variable $elements" tanpa petunjuk apa pun.
        // Belum ada yang memakainya hari ini — itu justru alasan membuangnya
        // sekarang, selagi tidak ada yang bergantung padanya.
        // (Tinjauan PR #14 nomor 3)
    }
}
