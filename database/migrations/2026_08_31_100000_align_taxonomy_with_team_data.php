<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menyelaraskan kategori, bobot, SLA, dan status dengan data nyata tim.
 * (API-25, keputusan API-18)
 *
 * Tidak ada satu baris complaint pun yang dihapus. Setiap nilai lama punya
 * tujuan barunya, dan `down()` mengembalikannya. Dirancang supaya aman
 * dijalankan ulang: setiap langkah memeriksa dulu apakah pekerjaannya sudah
 * dilakukan.
 */
return new class extends Migration
{
    /** Kategori lama → kategori tim. Yang tidak disebut jatuh ke `lainnya`. */
    private const KATEGORI = [
        'hasil_cuci' => 'barang_rusak',
        'keterlambatan' => 'terlambat',
        'salah_tagih' => 'lainnya',
        'sikap_petugas' => 'lainnya',
    ];

    /** Kategori yang sah setelah migrasi. */
    private const KATEGORI_BARU = [
        'barang_rusak', 'kurang_bersih', 'barang_hilang', 'berbau',
        'kurang_rapih', 'barang_tertukar', 'terlambat', 'lainnya',
    ];

    /**
     * Sub-kategori yang naik pangkat jadi kategori sendiri. Dipetakan lebih
     * dulu daripada kategori induknya supaya "Bau" tidak berakhir sebagai
     * Barang Rusak — dan sub-nya dikosongkan supaya tidak tersisa ganda.
     */
    private const SUB_NAIK_PANGKAT = [
        'hasil_cuci' => ['Masih kotor' => 'kurang_bersih', 'Bau' => 'berbau'],
        'barang_hilang' => ['Tertukar pelanggan lain' => 'barang_tertukar'],
    ];

    private const PRIORITY_KE_BOBOT = [
        'urgent' => 'berat',
        'high' => 'berat',
        'medium' => 'sedang',
        'low' => 'ringan',
    ];

    private const BOBOT_KE_PRIORITY = [
        'berat' => 'high',
        'sedang' => 'medium',
        'ringan' => 'low',
    ];

    public function up(): void
    {
        $this->tambahKolom();
        $this->isiBobotDariPriority();
        $this->petakanKategori();
        $this->petakanStatus();
        $this->buangPriority();
    }

    public function down(): void
    {
        $this->kembalikanPriority();
        $this->kembalikanStatus();
        $this->kembalikanKategori();

        Schema::table('complaints', function (Blueprint $table) {
            foreach (['bobot', 'layanan', 'tindak_lanjut', 'paused_at', 'pause_reason', 'close_reason'] as $kolom) {
                if (Schema::hasColumn('complaints', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }

    /* ---------- up ---------- */

    private function tambahKolom(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            if (! Schema::hasColumn('complaints', 'bobot')) {
                // Default sedang, bukan ringan: baris lama tanpa priority tidak
                // boleh diam-diam jadi complaint yang boleh ditutup kasir.
                $table->string('bobot')->default('sedang')->after('sub_category');
            }

            if (! Schema::hasColumn('complaints', 'layanan')) {
                // Nullable meski wajib di form: complaint lama tidak punya
                // nilainya, dan menebaknya lebih buruk daripada mengakui kosong.
                $table->string('layanan')->nullable()->after('bobot');
            }

            if (! Schema::hasColumn('complaints', 'tindak_lanjut')) {
                $table->string('tindak_lanjut')->nullable()->after('resolution');
            }

            if (! Schema::hasColumn('complaints', 'paused_at')) {
                $table->timestamp('paused_at')->nullable()->after('first_response_at');
            }

            if (! Schema::hasColumn('complaints', 'pause_reason')) {
                $table->string('pause_reason')->nullable()->after('paused_at');
            }

            if (! Schema::hasColumn('complaints', 'close_reason')) {
                $table->string('close_reason')->nullable()->after('status');
            }
        });
    }

    private function isiBobotDariPriority(): void
    {
        if (! Schema::hasColumn('complaints', 'priority')) {
            return;
        }

        foreach (self::PRIORITY_KE_BOBOT as $priority => $bobot) {
            DB::table('complaints')->where('priority', $priority)->update(['bobot' => $bobot]);
        }

        // Nilai priority di luar daftar tidak boleh menghilang tanpa jejak:
        // diperlakukan sebagai sedang, sama dengan default kolomnya.
        DB::table('complaints')
            ->whereNotIn('priority', array_keys(self::PRIORITY_KE_BOBOT))
            ->update(['bobot' => 'sedang']);
    }

    private function petakanKategori(): void
    {
        foreach (self::SUB_NAIK_PANGKAT as $kategoriLama => $peta) {
            foreach ($peta as $sub => $kategoriBaru) {
                DB::table('complaints')
                    ->where('category', $kategoriLama)
                    ->where('sub_category', $sub)
                    ->update(['category' => $kategoriBaru, 'sub_category' => null]);
            }
        }

        foreach (self::KATEGORI as $lama => $baru) {
            DB::table('complaints')->where('category', $lama)->update(['category' => $baru]);
        }

        // Apa pun yang tersisa di luar taksonomi baru jatuh ke Lainnya —
        // tidak ada complaint yang boleh berakhir tanpa kategori yang sah.
        DB::table('complaints')
            ->whereNotIn('category', self::KATEGORI_BARU)
            ->update(['category' => 'lainnya', 'sub_category' => null]);

        // Sub-kategori yang sudah tidak ada lagi di bawah kategori barunya
        // dibersihkan, supaya layar tidak menampilkan rincian yang tidak bisa
        // dipilih ulang.
        foreach (config('complaint.categories') as $kunci => $definisi) {
            $query = DB::table('complaints')->where('category', $kunci)->whereNotNull('sub_category');

            if ($definisi['sub'] !== []) {
                $query->whereNotIn('sub_category', $definisi['sub']);
            }

            $query->update(['sub_category' => null]);
        }
    }

    private function petakanStatus(): void
    {
        DB::table('complaints')->where('status', 'baru')->update(['status' => 'open']);
        DB::table('complaints')->where('status', 'ditangani')->update(['status' => 'handling']);

        // Menunggu Pelanggan turun jadi penanda jeda pada tiket Handling.
        // paused_at diambil dari updated_at: itu perkiraan terbaik yang ada
        // untuk kapan jedanya dimulai, dan lebih jujur daripada now().
        DB::table('complaints')->where('status', 'menunggu_pelanggan')->update([
            'status' => 'handling',
            'paused_at' => DB::raw('updated_at'),
            'pause_reason' => 'menunggu_pelanggan',
        ]);

        DB::table('complaints')->where('status', 'selesai')
            ->update(['status' => 'close', 'close_reason' => 'selesai']);

        DB::table('complaints')->where('status', 'ditolak')
            ->update(['status' => 'close', 'close_reason' => 'ditolak']);

        // Status di luar daftar tetap terbuka, bukan hilang.
        DB::table('complaints')
            ->whereNotIn('status', ['open', 'handling', 'close'])
            ->update(['status' => 'open']);

        // Tiket Close tanpa alasan (mis. dari migrasi yang setengah jalan)
        // dianggap selesai — itu arti "Close" sebelum kolom ini ada.
        DB::table('complaints')->where('status', 'close')->whereNull('close_reason')
            ->update(['close_reason' => 'selesai']);

        // Riwayat ikut dipetakan supaya jejak perubahan status tetap terbaca
        // dengan kosakata yang sama seperti di layar.
        foreach ([
            'baru' => 'open', 'ditangani' => 'handling', 'menunggu_pelanggan' => 'handling',
            'selesai' => 'close', 'ditolak' => 'close',
        ] as $lama => $baru) {
            DB::table('complaint_activities')->where('from_status', $lama)->update(['from_status' => $baru]);
            DB::table('complaint_activities')->where('to_status', $lama)->update(['to_status' => $baru]);
        }
    }

    private function buangPriority(): void
    {
        if (! Schema::hasColumn('complaints', 'priority')) {
            return;
        }

        // Indeksnya dilepas lebih dulu: SQLite menolak membuang kolom yang
        // masih dipakai sebuah indeks.
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropIndex(['status', 'priority']);
        });

        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn('priority');
        });

        Schema::table('complaints', function (Blueprint $table) {
            $table->index(['status', 'bobot']);
        });
    }

    /* ---------- down ---------- */

    private function kembalikanPriority(): void
    {
        if (Schema::hasColumn('complaints', 'priority')) {
            return;
        }

        Schema::table('complaints', function (Blueprint $table) {
            $table->dropIndex(['status', 'bobot']);
        });

        Schema::table('complaints', function (Blueprint $table) {
            $table->string('priority')->default('medium')->after('sub_category');
        });

        foreach (self::BOBOT_KE_PRIORITY as $bobot => $priority) {
            DB::table('complaints')->where('bobot', $bobot)->update(['priority' => $priority]);
        }

        Schema::table('complaints', function (Blueprint $table) {
            $table->index(['status', 'priority']);
        });
    }

    private function kembalikanStatus(): void
    {
        DB::table('complaints')->where('status', 'open')->update(['status' => 'baru']);

        DB::table('complaints')->where('status', 'handling')->whereNull('paused_at')
            ->update(['status' => 'ditangani']);

        DB::table('complaints')->where('status', 'handling')->whereNotNull('paused_at')
            ->update(['status' => 'menunggu_pelanggan']);

        DB::table('complaints')->where('status', 'close')->where('close_reason', 'ditolak')
            ->update(['status' => 'ditolak']);

        DB::table('complaints')->where('status', 'close')->update(['status' => 'selesai']);
    }

    private function kembalikanKategori(): void
    {
        DB::table('complaints')->where('category', 'barang_rusak')->update(['category' => 'hasil_cuci']);
        DB::table('complaints')->where('category', 'terlambat')->update(['category' => 'keterlambatan']);

        DB::table('complaints')->where('category', 'kurang_bersih')
            ->update(['category' => 'hasil_cuci', 'sub_category' => 'Masih kotor']);

        DB::table('complaints')->where('category', 'berbau')
            ->update(['category' => 'hasil_cuci', 'sub_category' => 'Bau']);

        DB::table('complaints')->where('category', 'barang_tertukar')
            ->update(['category' => 'barang_hilang', 'sub_category' => 'Tertukar pelanggan lain']);

        // Kurang Rapih tidak punya padanan di taksonomi lama.
        DB::table('complaints')->where('category', 'kurang_rapih')->update(['category' => 'lainnya']);
    }
};
