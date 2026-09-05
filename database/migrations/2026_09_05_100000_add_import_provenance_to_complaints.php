<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asal-usul baris hasil impor spreadsheet, plus tiga kolom untuk nilai lama
 * yang tidak punya rumah di model data. (API-28)
 *
 * `import_source` + `import_row` bukan sekadar catatan: pasangan itu unik,
 * dan keunikan itulah yang membuat perintah impor bisa dijalankan dua kali
 * tanpa menggandakan satu baris pun. Menyimpannya sebagai keterangan bebas
 * membuat pencegahan ganda bergantung pada ketelitian pemanggilnya.
 *
 * Tiga kolom `legacy_*` sengaja terpisah dari kolom sistem:
 *
 * - `legacy_nota_number` — nomor nota spreadsheet TIDAK berformat `INV/`
 *   dan tidak unik (132 baris membubuhkan nama bulan justru untuk
 *   membedakan angka yang sama). Menaruhnya di `nevira_transaction_number`
 *   akan menempelkan complaint ke order orang lain.
 * - `legacy_outlet_name` — nama outlet yang tidak punya padanan di daftar
 *   outlet. Complaint tanpa outlet lebih baik daripada complaint di outlet
 *   yang salah, tapi nama aslinya tetap harus bisa dilacak.
 * - `legacy_pelaku` — kolom `Pelaku` berisi teks bebas yang kadang memuat
 *   beberapa nama sekaligus. Memecahnya jadi baris `complaint_responsibles`
 *   berarti menebak pemisahnya; disimpan mentah supaya keputusan itu bisa
 *   diambil belakangan oleh orang, bukan oleh parser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->string('import_source')->nullable()->index();
            $table->unsignedInteger('import_row')->nullable();
            $table->string('legacy_nota_number')->nullable();
            $table->string('legacy_outlet_name')->nullable();
            $table->string('legacy_pelaku')->nullable();

            $table->unique(['import_source', 'import_row']);
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropUnique(['import_source', 'import_row']);
            $table->dropIndex(['import_source']);
            $table->dropColumn([
                'import_source', 'import_row',
                'legacy_nota_number', 'legacy_outlet_name', 'legacy_pelaku',
            ]);
        });
    }
};
