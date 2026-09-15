<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penandaan "sudah saya tangani" pada alarm dashboard. (API-72)
 *
 * Satu baris berarti: pada tanggal itu, orang itu menyatakan ia MEMEGANG alarm
 * tersebut. Bukan bahwa masalahnya selesai — yang memadamkan alarm hanya
 * keadaannya berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penanda_alarm', function (Blueprint $table) {
            $table->id();

            // Kunci alarm, bukan foreign key: daftar alarm hidup di kode
            // (config/complaint.php → alarms.terdaftar), tidak di tabel. Alarm
            // yang kelak dicabut meninggalkan baris yang tidak menunjuk apa
            // pun — dan itu tidak berbahaya, karena papan hanya mencari
            // penanda untuk alarm yang sedang terdaftar.
            $table->string('alarm');

            // Tanggal berlakunya penandaan, DALAM ZONA WAKTU OPERASIONAL.
            // Penandaan sengaja tidak permanen: kalau keadaannya masih ada
            // besok, alarmnya menyala lagi. Yang membuat "besok" terjadi
            // adalah tanggal ini tidak lagi cocok — tanpa satu pun pekerjaan
            // terjadwal yang harus membersihkannya.
            $table->date('untuk_tanggal');

            $table->foreignId('user_id')->constrained();
            $table->timestamp('ditandai_pada');

            // Satu pemegang per alarm per hari. Dua orang yang menekan tombol
            // pada detik yang sama tidak boleh menghasilkan dua baris yang
            // salah satunya akan tampil sembarang — yang pertama tercatat
            // memegangnya, yang kedua membaca namanya.
            $table->unique(['alarm', 'untuk_tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penanda_alarm');
    }
};
