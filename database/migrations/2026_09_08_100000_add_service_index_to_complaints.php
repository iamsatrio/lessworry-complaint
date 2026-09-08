<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Complaint boleh menunjuk SATU baris layanan pada nota. (API-51)
     *
     * Satu nota bisa berisi sepuluh sprei, masing-masing dikerjakan orang
     * yang berbeda. Keluhan pelanggan hampir selalu tentang satu barang —
     * "topi kelunturan", "kekurangan 2 sarung bantal" — bukan seluruh
     * pesanan. Tanpa kolom ini penetapan pelaku menuduh sepuluh rantai
     * pengerjaan sekaligus.
     *
     * Null berarti keluhannya menyangkut seluruh nota. Itu pula nilai
     * seluruh complaint yang sudah ada: tidak ada yang perlu ditebak
     * belakangan.
     */
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->unsignedSmallInteger('nevira_service_index')
                ->nullable()
                ->after('nevira_transaction_number');
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn('nevira_service_index');
        });
    }
};
