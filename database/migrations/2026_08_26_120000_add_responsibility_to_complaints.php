<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            // Penanggung jawab akar masalah — DITETAPKAN MANUSIA setelah
            // ditelusuri, bukan disimpulkan sistem. Data NEVIRA hanya
            // menunjukkan siapa mengerjakan tahap apa; itu fakta, bukan vonis.
            $table->unsignedBigInteger('responsible_staff_id')->nullable()->after('root_cause');
            $table->string('responsible_staff_name')->nullable()->after('responsible_staff_id');
            $table->string('responsible_staff_nip')->nullable()->after('responsible_staff_name');
            $table->string('responsible_stage')->nullable()->after('responsible_staff_nip');
            $table->text('responsibility_note')->nullable()->after('responsible_stage');
            $table->foreignId('responsibility_set_by')->nullable()->after('responsibility_note')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('responsibility_set_at')->nullable()->after('responsibility_set_by');

            $table->index('responsible_staff_id');
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsibility_set_by');
            $table->dropColumn([
                'responsible_staff_id', 'responsible_staff_name', 'responsible_staff_nip',
                'responsible_stage', 'responsibility_note', 'responsibility_set_at',
            ]);
        });
    }
};
