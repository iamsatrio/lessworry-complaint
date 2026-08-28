<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * API-19 — penetapan penanggung jawab yang sudah tercatat tidak boleh hilang
 * saat kolom tunggalnya diganti tabel penghubung.
 *
 * Caranya: mundur satu langkah ke skema lama, tulis satu penetapan seperti
 * yang ditulis versi sebelumnya, lalu maju lagi. Yang tersimpan harus pindah,
 * bukan terbuang.
 */
class ResponsibleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_penetapan_lama_dipindahkan_ke_tabel_pelaku(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 1]);

        $this->assertTrue(Schema::hasColumn('complaints', 'responsible_staff_name'),
            'rollback tidak mengembalikan skema lama, test ini tidak menguji apa pun');

        $userId = DB::table('users')->insertGetId([
            'name' => 'CC Lama', 'email' => 'cclama@lessworry.id',
            'password' => bcrypt('secret123'), 'role' => 'customer_care',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $complaintId = DB::table('complaints')->insertGetId([
            'ticket_number' => 'LW-20260101-001',
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor',
            'category' => 'hasil_cuci', 'priority' => 'medium', 'status' => 'baru',
            'description' => 'Keluhan lama',
            'responsible_staff_id'   => 244,
            'responsible_staff_name' => 'Budi Santoso',
            'responsible_staff_nip'  => 'LW/02',
            'responsible_stage'      => 'Cuci',
            'responsibility_note'    => 'Noda kerah masih ada setelah tahap cuci.',
            'responsibility_set_by'  => $userId,
            'responsibility_set_at'  => '2026-08-01 10:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Artisan::call('migrate');

        $this->assertFalse(Schema::hasColumn('complaints', 'responsible_staff_name'),
            'kolom tunggal masih ada setelah diganti tabel penghubung');

        $pelaku = DB::table('complaint_responsibles')->where('complaint_id', $complaintId)->get();

        $this->assertCount(1, $pelaku, 'penetapan lama hilang saat skema diganti');
        $this->assertSame('Budi Santoso', $pelaku[0]->staff_name);
        $this->assertSame('LW/02', $pelaku[0]->staff_nip);
        $this->assertSame('Cuci', $pelaku[0]->stage);
        $this->assertSame('Noda kerah masih ada setelah tahap cuci.', $pelaku[0]->reason);
        $this->assertSame($userId, (int) $pelaku[0]->set_by);
        $this->assertNotNull($pelaku[0]->set_at);
    }

    public function test_complaint_tanpa_penetapan_tidak_menghasilkan_baris_pelaku(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 1]);

        DB::table('complaints')->insert([
            'ticket_number' => 'LW-20260101-002',
            'channel' => 'wa_cc', 'reporter_name' => 'Pelapor',
            'category' => 'hasil_cuci', 'priority' => 'medium', 'status' => 'baru',
            'description' => 'Keluhan tanpa penetapan',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Artisan::call('migrate');

        $this->assertSame(0, DB::table('complaint_responsibles')->count());
    }
}
