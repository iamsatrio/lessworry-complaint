<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pisahkan nomor nota (milik pelanggan) dari id internal NEVIRA.
     *
     * Sebelumnya kolom nevira_transaction_id menyimpan — dan menampilkan —
     * id basis data NEVIRA. Itu pengenal internal sistem lain: tidak boleh
     * muncul di layar petugas, tidak boleh dikirim ke browser, dan kalau
     * bocor bisa dipakai menebak transaksi lain.
     *
     * Sekarang nomor nota yang jadi pegangan, dan id internal disimpan
     * terpisah untuk keperluan panggilan API saja.
     */
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->string('nevira_transaction_number')->nullable()->after('nevira_transaction_id')->index();
        });

        // Pindahkan nomor nota dari snapshot ke kolomnya sendiri.
        foreach (DB::table('complaints')->whereNotNull('nevira_snapshot')->get() as $row) {
            $snapshot = json_decode($row->nevira_snapshot, true) ?: [];

            if (! empty($snapshot['invoice'])) {
                DB::table('complaints')->where('id', $row->id)
                    ->update(['nevira_transaction_number' => $snapshot['invoice']]);
            }
        }

        // Baris tanpa snapshot: nilai lama mungkin nomor nota yang diketik
        // petugas dan belum sempat tersinkron. Selamatkan yang non-numerik.
        DB::table('complaints')
            ->whereNull('nevira_transaction_number')
            ->whereNotNull('nevira_transaction_id')
            ->get()
            ->each(function ($row) {
                if (! ctype_digit((string) $row->nevira_transaction_id)) {
                    DB::table('complaints')->where('id', $row->id)->update([
                        'nevira_transaction_number' => $row->nevira_transaction_id,
                        'nevira_transaction_id'     => null,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn('nevira_transaction_number');
        });
    }
};
