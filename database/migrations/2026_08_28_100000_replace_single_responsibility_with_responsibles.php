<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * API-19 — satu complaint bisa melibatkan beberapa orang.
 *
 * Kolom tunggal `responsible_*` di tabel complaints memaksa satu keluhan
 * hanya punya satu pelaku, padahal satu kemeja rusak bisa melewati kasir
 * penerima, petugas cuci, dan kurir. Kolomnya diganti tabel penghubung.
 *
 * Penetapan yang sudah tercatat DIPINDAHKAN, bukan dibuang: itu penilaian
 * yang sudah dibuat orang, lengkap dengan alasan dan nama penetapnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaint_responsibles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->cascadeOnDelete();

            // Identitas karyawan menurut NEVIRA. id_user disimpan supaya
            // rekap tetap benar walau namanya berubah ejaan; boleh kosong
            // untuk orang yang tidak ada di daftar (mis. kurir outlet lain).
            $table->string('nevira_user_id')->nullable()->index();
            $table->string('staff_name');
            $table->string('staff_nip')->nullable()->index();

            // Peran DALAM KEJADIAN INI — bukan jabatannya sehari-hari.
            $table->string('role')->default('lainnya');

            // Tahap produksi kalau memang berasal dari jejak NEVIRA.
            $table->string('stage')->nullable();

            // Wajib. Menunjuk orang tanpa alasan tidak bisa ditinjau ulang.
            $table->text('reason');

            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('set_at')->nullable();
            $table->timestamps();
        });

        // Dibaca ke memori DULU, dipindahkan SETELAH kolomnya dibuang.
        // Urutannya bukan selera: di SQLite, membuang kolom berarti tabel
        // complaints dibangun ulang — tabel lama dihapus, dan penghapusan itu
        // meng-cascade ke complaint_responsibles. Baris yang sudah dipindahkan
        // lebih dulu akan ikut lenyap tanpa satu pun galat.
        $lama = $this->bacaPenetapanLama();

        // Indeksnya dilepas lebih dulu: SQLite membangun ulang tabel saat
        // kolom dibuang, dan indeks yang menunjuk kolom yang sudah hilang
        // membuat pembangunan ulang itu gagal.
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropIndex(['responsible_staff_id']);
        });

        Schema::table('complaints', function (Blueprint $table) {
            // Kunci asing dilepas di blueprint yang sama dengan kolomnya:
            // SQLite menolak membuang kolom yang masih disebut definisi
            // foreign key, dan MySQL menolak tanpa constraint-nya dilepas.
            $table->dropForeign(['responsibility_set_by']);

            $table->dropColumn([
                'responsible_staff_id', 'responsible_staff_name', 'responsible_staff_nip',
                'responsible_stage', 'responsibility_note', 'responsibility_set_by',
                'responsibility_set_at',
            ]);
        });

        $this->simpanPenetapanLama($lama);
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
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

        // Kolom tunggal hanya muat satu orang: yang dikembalikan adalah
        // pelaku yang pertama ditetapkan. Sisanya memang tidak punya tempat
        // di skema lama — itu justru alasan skema ini diganti.
        foreach (DB::table('complaint_responsibles')->orderBy('id')->get()->groupBy('complaint_id') as $complaintId => $rows) {
            $first = $rows->first();

            DB::table('complaints')->where('id', $complaintId)->update([
                'responsible_staff_id' => is_numeric($first->nevira_user_id) ? (int) $first->nevira_user_id : null,
                'responsible_staff_name' => $first->staff_name,
                'responsible_staff_nip' => $first->staff_nip,
                'responsible_stage' => $first->stage,
                'responsibility_note' => $first->reason,
                'responsibility_set_by' => $first->set_by,
                'responsibility_set_at' => $first->set_at,
            ]);
        }

        Schema::dropIfExists('complaint_responsibles');
    }

    private function bacaPenetapanLama()
    {
        return DB::table('complaints')
            ->whereNotNull('responsible_staff_name')
            ->where('responsible_staff_name', '<>', '')
            ->get([
                'id', 'responsible_staff_id', 'responsible_staff_name', 'responsible_staff_nip',
                'responsible_stage', 'responsibility_note', 'responsibility_set_by',
                'responsibility_set_at', 'updated_at',
            ]);
    }

    private function simpanPenetapanLama($lama): void
    {
        foreach ($lama as $row) {
            DB::table('complaint_responsibles')->insert([
                'complaint_id' => $row->id,
                'nevira_user_id' => $row->responsible_staff_id ? (string) $row->responsible_staff_id : null,
                'staff_name' => $row->responsible_staff_name,
                'staff_nip' => $row->responsible_staff_nip,
                'role' => $this->peranDariTahap($row->responsible_stage),
                'stage' => $row->responsible_stage,
                // Penetapan lama seharusnya selalu punya alasan, tapi baris
                // yang lolos sebelum aturan itu ada tetap dipindahkan apa
                // adanya — ditandai, bukan dikarang.
                'reason' => $row->responsibility_note ?: 'Alasan tidak tercatat pada penetapan versi lama.',
                'set_by' => $row->responsibility_set_by,
                'set_at' => $row->responsibility_set_at,
                'created_at' => $row->responsibility_set_at ?: $row->updated_at,
                'updated_at' => $row->updated_at,
            ]);
        }
    }

    private function peranDariTahap(?string $stage): string
    {
        $stage = mb_strtolower((string) $stage);

        return match (true) {
            $stage === '' => 'lainnya',
            str_contains($stage, 'kasir') => 'kasir',
            str_contains($stage, 'kurir') || str_contains($stage, 'antar') => 'kurir',
            default => 'produksi',
        };
    }
};
