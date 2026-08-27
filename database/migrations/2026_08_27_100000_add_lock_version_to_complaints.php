<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanda versi untuk mencegah perubahan bersamaan saling menimpa.
     * (API-8 T6)
     *
     * Halaman complaint membawa versi yang sedang ditampilkan; penyimpanan
     * dari halaman yang sudah basi ditolak, bukan diterima diam-diam.
     * Kolom keputusan (resolusi, penyebab akar, status) hanya boleh berubah
     * oleh orang yang melihat isi terbarunya.
     */
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn('lock_version');
        });
    }
};
