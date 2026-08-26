<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('nevira_outlet_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('kasir')->after('password');
            $table->foreignId('outlet_id')->nullable()->after('role')->constrained()->nullOnDelete();
            $table->string('division')->nullable()->after('outlet_id');
            $table->boolean('is_active')->default(true)->after('division');
            $table->boolean('must_change_password')->default(false)->after('is_active');
        });

        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number')->unique();

            // Kanal masuk: kasir | wa_outlet | wa_cc
            $table->string('channel');

            // Pelapor
            $table->string('reporter_name');
            $table->string('reporter_phone')->nullable();

            // Tautan NEVIRA (read-only). Boleh kosong: complaint tanpa order tetap dicatat.
            $table->string('nevira_transaction_id')->nullable()->index();
            $table->string('nevira_customer_id')->nullable()->index();
            $table->json('nevira_snapshot')->nullable();
            $table->timestamp('nevira_synced_at')->nullable();
            $table->string('nevira_sync_error')->nullable();

            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();

            $table->string('category');
            $table->string('sub_category')->nullable();
            $table->string('priority')->default('medium');
            $table->string('status')->default('baru');

            $table->text('description');

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('forwarded_division')->nullable();

            $table->text('resolution')->nullable();
            $table->text('root_cause')->nullable();
            $table->unsignedBigInteger('compensation_amount')->default(0);

            // SLA
            $table->timestamp('due_response_at')->nullable();
            $table->timestamp('due_resolution_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'priority']);
        });

        Schema::create('complaint_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // created | status_change | note | forward | assign
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('complaint_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_attachments');
        Schema::dropIfExists('complaint_activities');
        Schema::dropIfExists('complaints');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('outlet_id');
            $table->dropColumn(['role', 'division', 'is_active', 'must_change_password']);
        });
        Schema::dropIfExists('outlets');
    }
};
