<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token webstruk dari nota WhatsApp. (API-26)
 *
 * Nota elektronik memuat tautan `nevira.id/webstruk/<token>`. Kalau pola
 * `INV/…` tidak ketemu di teks yang ditempel, token ini satu-satunya
 * pengenal yang tersisa — disimpan sebagai RUJUKAN MANUSIA supaya petugas
 * bisa membuka strukya sendiri, BUKAN untuk dipakai memanggil API. Belum
 * diketahui apakah ada endpoint NEVIRA yang menerimanya, dan menebaknya
 * bukan tugas kolom ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->string('nevira_webstruk_token', 64)->nullable()->after('nevira_transaction_number');
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn('nevira_webstruk_token');
        });
    }
};
