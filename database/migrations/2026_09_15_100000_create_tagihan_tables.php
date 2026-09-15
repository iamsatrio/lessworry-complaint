<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tagihan bulanan yang dikelola sendiri, dan penandaan sudah-dibayar per
 * periode. (API-73)
 *
 * Seluruh daftarnya HIDUP DI TABEL, bukan di berkas config. Itu bukan selera
 * penyimpanan: menambah satu baris tagihan tidak boleh berarti mengubah kode
 * lalu deploy. Tagihan yang menuntut deploy akan berhenti ditambahkan, dan
 * daftar yang basi membuat alarmnya menyesatkan — lebih buruk daripada tidak
 * ada alarm sama sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tagihan', function (Blueprint $table) {
            $table->id();
            $table->string('nama');

            // Boleh kosong: tidak semua tagihan tetap nominalnya — listrik dan
            // air berubah tiap bulan. Memaksa angka berarti orang mengarang
            // angka, dan angka karangan lebih buruk daripada kolom kosong.
            // Rupiah bulat: sen tidak pernah dipakai dan float akan
            // membulatkan diam-diam.
            $table->unsignedBigInteger('jumlah')->nullable();

            // Tanggal dalam bulan, 1–31. Bulan yang tidak punya tanggal itu
            // memakai HARI TERAKHIRNYA — lihat Tagihan::amankan(). Disimpan
            // apa adanya, bukan sudah dipotong: kalau 31 disimpan sebagai 28
            // karena kebetulan dibuat bulan Februari, Maret ikut jatuh tanggal
            // 28 selamanya.
            $table->unsignedTinyInteger('jatuh_tempo_hari');

            // Hanya untuk tagihan tahunan. Null berarti bulanan.
            $table->unsignedTinyInteger('jatuh_tempo_bulan')->nullable();

            $table->string('pengulangan')->default('bulanan');

            // Null = tagihan tingkat jaringan, terlihat semua pemegang
            // `dashboard.view`. Yang ber-outlet mengikuti cakupan outlet
            // pembacanya (Tagihan::scopeVisibleTo).
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Papan alarm hanya menarik yang aktif.
            $table->index(['is_active', 'outlet_id']);
        });

        Schema::create('pembayaran_tagihan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tagihan_id')->constrained('tagihan')->cascadeOnDelete();

            // Periode yang dibayar, 'YYYY-MM'. PER PERIODE, bukan sekali
            // selamanya: membayar tagihan Oktober tidak boleh memadamkan
            // tagihan November.
            //
            // Bentuknya sama untuk bulanan maupun tahunan — tagihan tahunan
            // memakai bulan jatuh temponya. Satu bentuk, supaya tagihan yang
            // pengulangannya diubah tidak kehilangan riwayatnya.
            //
            // Bukan tanggal jatuh tempo yang sudah dihitung: kalau tanggalnya
            // kelak diubah dari 5 ke 7, penandaan yang sudah ada akan berhenti
            // cocok dan alarm periode itu menyala lagi seolah belum dibayar.
            $table->string('periode', 7);

            $table->foreignId('user_id')->constrained();
            $table->timestamp('ditandai_pada');

            // Satu penandaan per tagihan per periode. Dua orang yang menekan
            // tombol pada detik yang sama tidak boleh menghasilkan dua baris.
            $table->unique(['tagihan_id', 'periode']);
        });

        /*
         * Satu setelan, bukan halaman setelan.
         *
         * Ambang "ingatkan berapa hari sebelum jatuh tempo" harus bisa diubah
         * satrio sendiri — sama alasannya dengan daftar tagihannya. Kalau ia
         * env, mengubah 3 hari jadi 7 berarti menyentuh server, dan yang
         * terjadi bukan angka yang diubah melainkan angka yang dibiarkan.
         *
         * Tabelnya sengaja kunci-nilai dan sengaja TIDAK diberi halaman
         * sendiri: hari ini isinya satu baris, dan halaman Setelan yang berisi
         * satu kotak isian adalah halaman yang tidak ada yang tahu harus
         * dibuka. Kotaknya duduk di halaman Tagihan, tempat angka itu berarti.
         */
        Schema::create('pengaturan', function (Blueprint $table) {
            $table->string('kunci')->primary();
            $table->string('nilai');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaturan');
        Schema::dropIfExists('pembayaran_tagihan');
        Schema::dropIfExists('tagihan');
    }
};
