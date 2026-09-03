<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akumulasi lama jeda, supaya waktu penyelesaian bisa dihitung tanpa
 * menghitung waktu menunggu pelanggan sebagai waktu kerja tim. (Review PR #1)
 *
 * `paused_at` saja tidak cukup: ia hanya menyimpan jeda yang SEDANG berjalan
 * dan dibersihkan begitu dilanjutkan. Satu tiket bisa dijeda berkali-kali, dan
 * yang dibutuhkan laporan adalah totalnya.
 *
 * Berkas terpisah dari migrasi taksonomi, bukan ditempelkan ke dalamnya:
 * migrasi itu sudah dijalankan orang lain di mesin masing-masing, dan mengubah
 * berkas yang sudah jalan tidak akan menambahkan kolom ini di sana.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('complaints', 'paused_minutes')) {
            return;
        }

        Schema::table('complaints', function (Blueprint $table) {
            $table->unsignedInteger('paused_minutes')->default(0)->after('pause_reason');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('complaints', 'paused_minutes')) {
            return;
        }

        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn('paused_minutes');
        });
    }
};
