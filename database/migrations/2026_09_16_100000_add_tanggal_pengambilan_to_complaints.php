<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tanggal barang diterima pelanggan, dan dari mana tanggal itu diketahui.
 * (API-48)
 *
 * Kolomnya `date`, bukan `datetime`, dan itu keputusan — bukan penyederhanaan.
 * Dua sumbernya punya ketelitian yang berbeda: jejak `diambil_customer` punya
 * detiknya, sedangkan baris pengantaran NEVIRA hanya punya tanggalnya.
 * Menyimpan keduanya sebagai datetime membuat separuh baris mengaku tahu jam
 * yang tidak pernah ada. Yang dipakai hanya selisih HARI, jadi tanggal sudah
 * cukup untuk seluruh keperluannya.
 *
 * `sumber_tanggal_pengambilan` tidak pernah null. Complaint yang tanggalnya
 * tidak diketahui tetap menyebutkan bahwa tidak diketahui — itu jawaban,
 * bukan kekosongan yang harus ditebak pembacanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->date('tanggal_pengambilan')->nullable()->after('nevira_sync_error');
            $table->string('sumber_tanggal_pengambilan', 20)
                ->default('tidak_diketahui')
                ->after('tanggal_pengambilan');
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn(['tanggal_pengambilan', 'sumber_tanggal_pengambilan']);
        });
    }
};
