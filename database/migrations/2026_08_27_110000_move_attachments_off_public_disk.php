<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Pindahkan lampiran yang telanjur duduk di disk publik. (API-8 T9)
     *
     * Commit 5dbeb11 memindahkan unggahan BARU ke disk privat, tapi berkas
     * yang sudah ada tetap di storage/app/public. Di aplikasi barisnya
     * membalas 404 — Storage::disk('local')->exists() gagal — sementara
     * berkasnya sendiri tetap bisa diambil siapa pun lewat /storage/...
     * selama symlink terpasang. Foto bukti berisi barang dan kadang wajah
     * pelanggan.
     *
     * Isinya tidak dibuang: setiap berkas disalin ke disk privat dulu, baru
     * salinan publiknya dihapus. Berkas yang sudah punya kembaran privat
     * dengan isi sama hanya dihapus dari publik.
     */
    public function up(): void
    {
        $publik = Storage::disk('public');
        $privat = Storage::disk('local');

        if (! $publik->exists('complaints')) {
            return;
        }

        foreach ($publik->allFiles('complaints') as $path) {
            if (! $privat->exists($path)) {
                $privat->put($path, $publik->get($path));
            }

            $publik->delete($path);
        }

        // Direktori kosong yang tertinggal ikut dirapikan supaya tidak
        // terbaca seperti masih ada isinya.
        foreach ($publik->allDirectories('complaints') as $dir) {
            if ($publik->allFiles($dir) === []) {
                $publik->deleteDirectory($dir);
            }
        }
    }

    /**
     * Tidak dibalik. Mengembalikan foto pelanggan ke disk publik adalah
     * membuka lagi celah yang baru ditutup.
     */
    public function down(): void
    {
        //
    }
};
