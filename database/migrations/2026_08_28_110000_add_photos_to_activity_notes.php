<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-20 — foto bukti menempel pada catatan penanganan, bukan hanya pada
 * complaint saat dibuat.
 *
 * Tabelnya sengaja tidak digandakan: lampiran catatan dan lampiran keluhan
 * disimpan, disajikan, dan diperiksa wewenangnya dengan cara yang persis
 * sama. Yang membedakan hanya ke mana ia menempel — kolom
 * complaint_activity_id kosong berarti lampiran keluhan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaint_attachments', function (Blueprint $table) {
            $table->foreignId('complaint_activity_id')->nullable()->after('complaint_id')
                ->constrained('complaint_activities')->cascadeOnDelete();

            // Versi kecil untuk lini masa. Kosong berarti kompresinya gagal
            // dan yang tersimpan adalah berkas aslinya.
            $table->string('thumb_path')->nullable()->after('path');
            $table->string('mime')->nullable()->after('original_name');

            // Ukuran sebelum dan sesudah, supaya penghematannya terukur dan
            // bukan sekadar diyakini.
            $table->unsignedBigInteger('original_bytes')->nullable()->after('mime');
            $table->unsignedBigInteger('stored_bytes')->nullable()->after('original_bytes');

            // Kompresi yang gagal tidak boleh membatalkan catatannya —
            // berkas aslinya tetap disimpan, kegagalannya dicatat di sini.
            $table->string('compression_error')->nullable()->after('stored_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('complaint_attachments', function (Blueprint $table) {
            $table->dropForeign(['complaint_activity_id']);
            $table->dropColumn([
                'complaint_activity_id', 'thumb_path', 'mime',
                'original_bytes', 'stored_bytes', 'compression_error',
            ]);
        });
    }
};
