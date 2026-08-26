<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            // Alasan complaint ini boleh tanpa nomor nota. Null berarti
            // notanya memang terisi.
            $table->string('nota_exemption')->nullable()->after('nevira_sync_error');
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn('nota_exemption');
        });
    }
};
