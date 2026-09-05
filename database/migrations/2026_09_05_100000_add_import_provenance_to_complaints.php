<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asal-usul baris hasil impor spreadsheet, plus tiga kolom untuk nilai lama
 * yang tidak punya rumah di model data. (API-28)
 *
 * `import_fingerprint` adalah pencegah ganda yang sesungguhnya: sidik jari
 * dari ISI baris, unik di seluruh tabel. `import_source` + `import_row` juga
 * unik, tapi hanya di dalam satu label — dan labelnya dipilih orang.
 * Mengandalkan label berarti dua perintah yang sama-sama wajar
 * (`--sumber` lupa diisi, lalu `--sumber` diisi) memasukkan berkas yang sama
 * dua kali dan menggandakan seluruh 545 baris tanpa satu peringatan pun.
 * (Review PR #7, P1-4)
 *
 * `import_source` tetap ada untuk provenance dan untuk jalan mundur
 * (`complaint:import-hapus <sumber>`), bukan lagi sebagai pencegah ganda.
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
            $table->string('import_fingerprint', 80)->nullable()->unique();
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
            $table->dropUnique(['import_fingerprint']);
            $table->dropIndex(['import_source']);
            $table->dropColumn([
                'import_source', 'import_row', 'import_fingerprint',
                'legacy_nota_number', 'legacy_outlet_name', 'legacy_pelaku',
            ]);
        });
    }
};
