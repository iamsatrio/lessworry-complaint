<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak tindakan admin atas sebuah akun. (API-35 bagian 4a)
 *
 * Complaint sudah punya riwayatnya sendiri di complaint_activities; akun
 * tidak punya apa-apa. Yang memaksa tabel ini lahir: admin boleh menandai
 * satu akun terverifikasi secara manual — itu MELEMAHKAN pengaman, jadi
 * harus ada barisnya: siapa menandai siapa, kapan, dan alasannya.
 *
 * Baris audit tidak pernah diubah, jadi tidak ada updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_audits', function (Blueprint $table) {
            $table->id();
            // Akun yang dikenai tindakan.
            $table->foreignId('user_id')->constrained('users');
            // Pelakunya. NULL berarti perintah shell — yang memegang shell
            // tidak punya akun, dan memaksa satu di situ berarti mengarang.
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('action', 60);
            // Alasan yang diketik admin. Wajib untuk penandaan manual.
            $table->text('reason')->nullable();
            // Kalimat yang ditulis sistem: apa persisnya yang berubah.
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_audits');
    }
};
