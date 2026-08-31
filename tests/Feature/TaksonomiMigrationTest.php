<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * API-25 nomor 7 — migrasi harus jalan di atas data lama tanpa satu pun
 * complaint kehilangan kategori, bobot, atau status.
 *
 * Caranya sama seperti test migrasi sebelumnya: mundur ke skema lama, tulis
 * baris seperti yang ditulis versi sebelumnya, lalu maju lagi. Yang tersimpan
 * harus pindah tempat, bukan terbuang.
 */
class TaksonomiMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRASI = 'database/migrations/2026_08_31_100000_align_taxonomy_with_team_data.php';

    private function mundur(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::MIGRASI]);
    }

    private function maju(): void
    {
        Artisan::call('migrate', ['--path' => self::MIGRASI]);
    }

    /**
     * Satu baris complaint seperti yang ditulis skema lama: kategori lama,
     * priority empat tingkat, status lima nilai.
     */
    private function complaintLama(string $nomor, string $kategori, ?string $sub, string $priority, string $status): int
    {
        return DB::table('complaints')->insertGetId([
            'ticket_number' => $nomor,
            'channel'       => 'wa_cc',
            'reporter_name' => 'Pelapor',
            'category'      => $kategori,
            'sub_category'  => $sub,
            'priority'      => $priority,
            'status'        => $status,
            'description'   => 'Keluhan lama',
            'created_at'    => now()->subDays(3),
            'updated_at'    => now()->subDay(),
        ]);
    }

    public function test_data_lama_pindah_ke_taksonomi_tim_tanpa_ada_yang_hilang(): void
    {
        $this->mundur();

        $this->assertTrue(Schema::hasColumn('complaints', 'priority'),
            'rollback tidak mengembalikan skema lama, test ini tidak menguji apa pun');
        $this->assertFalse(Schema::hasColumn('complaints', 'bobot'));

        $baris = [
            'kotor'     => $this->complaintLama('LW-20260101-001', 'hasil_cuci', 'Masih kotor', 'urgent', 'baru'),
            'bau'       => $this->complaintLama('LW-20260101-002', 'hasil_cuci', 'Bau', 'high', 'ditangani'),
            'sobek'     => $this->complaintLama('LW-20260101-003', 'hasil_cuci', 'Rusak/sobek', 'medium', 'menunggu_pelanggan'),
            'telat'     => $this->complaintLama('LW-20260101-004', 'keterlambatan', 'Telat antar', 'low', 'selesai'),
            'tagih'     => $this->complaintLama('LW-20260101-005', 'salah_tagih', 'Berat tidak sesuai', 'medium', 'ditolak'),
            'sikap'     => $this->complaintLama('LW-20260101-006', 'sikap_petugas', 'Pelayanan kasir', 'low', 'baru'),
            'hilang'    => $this->complaintLama('LW-20260101-007', 'barang_hilang', 'Item kurang', 'high', 'ditangani'),
            'tertukar'  => $this->complaintLama('LW-20260101-008', 'barang_hilang', 'Tertukar pelanggan lain', 'urgent', 'baru'),
        ];

        $sebelum = DB::table('complaints')->count();

        $this->maju();

        $this->assertSame($sebelum, DB::table('complaints')->count(),
            'jumlah complaint berubah — ada baris yang hilang saat migrasi');
        $this->assertFalse(Schema::hasColumn('complaints', 'priority'));

        $ambil = fn (string $kunci) => DB::table('complaints')->find($baris[$kunci]);

        // Sub-kategori yang naik pangkat jadi kategori, dan tidak tersisa ganda.
        $this->assertSame('kurang_bersih', $ambil('kotor')->category);
        $this->assertNull($ambil('kotor')->sub_category);
        $this->assertSame('berbau', $ambil('bau')->category);
        $this->assertNull($ambil('bau')->sub_category);
        $this->assertSame('barang_tertukar', $ambil('tertukar')->category);

        // Kategori yang dipetakan langsung.
        $this->assertSame('barang_rusak', $ambil('sobek')->category);
        $this->assertSame('Rusak/sobek', $ambil('sobek')->sub_category);
        $this->assertSame('terlambat', $ambil('telat')->category);
        $this->assertSame('Telat antar', $ambil('telat')->sub_category);
        $this->assertSame('barang_hilang', $ambil('hilang')->category);

        // Kategori karangan jatuh ke Lainnya, bukan hilang.
        $this->assertSame('lainnya', $ambil('tagih')->category);
        $this->assertSame('lainnya', $ambil('sikap')->category);

        // Tidak ada complaint yang berakhir di luar taksonomi baru.
        $this->assertSame(0, DB::table('complaints')
            ->whereNotIn('category', array_keys(config('complaint.categories')))->count());

        // Priority → bobot.
        $this->assertSame('berat', $ambil('kotor')->bobot);
        $this->assertSame('berat', $ambil('bau')->bobot);
        $this->assertSame('sedang', $ambil('sobek')->bobot);
        $this->assertSame('ringan', $ambil('telat')->bobot);

        $this->assertSame(0, DB::table('complaints')
            ->whereNotIn('bobot', ['ringan', 'sedang', 'berat'])->count());

        // Status lima nilai → tiga status plus dua penanda.
        $this->assertSame('open', $ambil('kotor')->status);
        $this->assertSame('handling', $ambil('bau')->status);

        $this->assertSame('handling', $ambil('sobek')->status);
        $this->assertSame('menunggu_pelanggan', $ambil('sobek')->pause_reason);
        $this->assertNotNull($ambil('sobek')->paused_at);

        $this->assertSame('close', $ambil('telat')->status);
        $this->assertSame('selesai', $ambil('telat')->close_reason);

        $this->assertSame('close', $ambil('tagih')->status);
        $this->assertSame('ditolak', $ambil('tagih')->close_reason);

        $this->assertSame(0, DB::table('complaints')
            ->whereNotIn('status', ['open', 'handling', 'close'])->count());
    }

    public function test_migrasi_aman_dijalankan_ulang(): void
    {
        $this->mundur();

        $id = $this->complaintLama('LW-20260101-101', 'hasil_cuci', 'Bau', 'high', 'menunggu_pelanggan');

        $this->maju();

        $sesudahSekali = (array) DB::table('complaints')->find($id);

        // Dijalankan lagi tanpa rollback: setiap langkahnya memeriksa dulu
        // apakah pekerjaannya sudah dilakukan, jadi tidak ada yang berubah.
        $this->maju();

        $sesudahDuaKali = (array) DB::table('complaints')->find($id);

        $this->assertSame($sesudahSekali, $sesudahDuaKali,
            'migrasi yang dijalankan ulang mengubah data yang sudah dipindahkan');
        $this->assertSame('berbau', $sesudahDuaKali['category']);
        $this->assertSame('handling', $sesudahDuaKali['status']);
    }

    public function test_rollback_mengembalikan_kolom_dan_nilai_lama(): void
    {
        $this->mundur();

        $baru = $this->complaintLama('LW-20260101-201', 'keterlambatan', 'Telat antar', 'urgent', 'ditolak');

        $this->maju();
        $this->mundur();

        $this->assertTrue(Schema::hasColumn('complaints', 'priority'));
        $this->assertFalse(Schema::hasColumn('complaints', 'bobot'));

        $baris = DB::table('complaints')->find($baru);

        $this->assertSame('keterlambatan', $baris->category);
        $this->assertSame('ditolak', $baris->status);
        // urgent dan high sama-sama jadi 'berat', jadi jalan pulangnya
        // memilih satu nilai yang sah — bukan menebak yang mana asalnya.
        $this->assertSame('high', $baris->priority);

        $this->maju();
    }

    public function test_riwayat_status_ikut_dipetakan(): void
    {
        $this->mundur();

        $complaintId = $this->complaintLama('LW-20260101-301', 'hasil_cuci', null, 'medium', 'selesai');

        DB::table('complaint_activities')->insert([
            'complaint_id' => $complaintId,
            'type'         => 'status_change',
            'from_status'  => 'ditangani',
            'to_status'    => 'selesai',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $this->maju();

        $riwayat = DB::table('complaint_activities')->where('complaint_id', $complaintId)->first();

        $this->assertSame('handling', $riwayat->from_status);
        $this->assertSame('close', $riwayat->to_status);
    }
}
