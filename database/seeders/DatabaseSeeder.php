<?php

namespace Database\Seeders;

use App\Models\Complaint;
use App\Models\ComplaintActivity;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Outlet nyata beserta id NEVIRA-nya, supaya penentuan outlet dari
        // nota berfungsi tanpa menunggu `php artisan nevira:sync-outlets`.
        $daftarOutlet = [
            '115' => 'Kemang',
            '116' => 'Cipete',
            '117' => 'Hampton Gading Serpong',
            '118' => 'Tebet',
            '119' => 'Lebak Bulus',
            '120' => 'Fatmawati',
            '121' => 'Pondok Indah',
            '122' => 'Jati Padang',
            '123' => 'Park Serpong',
            '124' => 'Jagakarsa',
            '179' => 'Citra Garden Serpong',
        ];

        foreach ($daftarOutlet as $idNevira => $nama) {
            Outlet::create(['name' => $nama, 'nevira_outlet_id' => $idNevira]);
        }

        $pusat = Outlet::where('nevira_outlet_id', '118')->first();   // Tebet
        $cabang = Outlet::where('nevira_outlet_id', '123')->first();  // Park Serpong

        // Password contoh untuk lingkungan pengembangan. WAJIB diganti sebelum produksi.
        //
        // Akun demo di bawah sengaja TIDAK ditandai must_change_password supaya
        // sistem bisa langsung ditelusuri. Akun sungguhan yang dibuat lewat
        // halaman Pengguna selalu memakai password sementara dan wajib diganti
        // saat pertama masuk.
        $pw = 'password';

        $supervisor = User::create([
            'name' => 'Satrio Wibowo', 'email' => 'satrio@lessworry.id',
            'password' => $pw, 'role' => 'supervisor',
        ]);

        $cc = User::create([
            'name' => 'Customer Care', 'email' => 'cc@lessworry.id',
            'password' => $pw, 'role' => 'customer_care',
        ]);

        $kasir = User::create([
            'name' => 'Kasir Pusat', 'email' => 'kasir@lessworry.id',
            'password' => $pw, 'role' => 'kasir', 'outlet_id' => $pusat->id,
        ]);

        User::create([
            'name' => 'Divisi Produksi', 'email' => 'produksi@lessworry.id',
            'password' => $pw, 'role' => 'divisi', 'division' => 'produksi',
        ]);

        // Satu akun contoh yang masih memegang password sementara, supaya alur
        // "wajib ganti password saat pertama masuk" bisa dilihat langsung.
        User::create([
            'name' => 'Kasir Cabang (baru)', 'email' => 'kasirbaru@lessworry.id',
            'password' => $pw, 'role' => 'kasir', 'outlet_id' => $cabang->id,
            'must_change_password' => true,
        ]);

        // Contoh complaint supaya papan kerja dan laporan tidak kosong saat pertama dibuka.
        // Kategori, bobot, layanan, dan status memakai taksonomi tim. (API-25)
        $samples = [
            ['wa_cc', 'Ibu Rina', '081234567801', 'kurang_bersih', null, 'sedang', 'kiloan_cuset', 'open', null, null,
             'Kemeja putih masih ada noda di bagian kerah setelah dicuci.', $pusat->id, '-3 hours', null],
            ['kasir', 'Pak Budi', '081234567802', 'barang_hilang', 'Item kurang', 'berat', 'kiloan', 'handling', null, null,
             'Pelanggan menghitung 12 potong saat menyerahkan, yang kembali 11 potong.', $pusat->id, '-26 hours', null],
            ['wa_outlet', 'Mbak Sinta', '081234567803', 'terlambat', 'Telat selesai', 'ringan', 'satuan_cloth', 'close', 'selesai', 'proses_ulang',
             'Dijanjikan selesai Selasa, baru bisa diambil Kamis.', $cabang->id, '-5 days', '-4 days'],
            ['wa_cc', 'Ibu Rina', '081234567801', 'kurang_rapih', null, 'ringan', 'satuan_bedding', 'close', 'ditolak', 'terkonfirmasi',
             'Sprei terlihat kusut saat diterima.', $pusat->id, '-8 days', '-8 days'],
            ['kasir', 'Pak Deni', '081234567804', 'berbau', null, 'sedang', 'kiloan_culip', 'handling', null, null,
             'Cucian bau apek. Diminta membawa kembali untuk dicuci ulang.', $cabang->id, '-2 days', null],
        ];

        foreach ($samples as [$channel, $name, $phone, $cat, $sub, $bobot, $layanan, $status, $closeReason, $tindakLanjut, $desc, $outletId, $created, $resolved]) {
            $complaint = new Complaint([
                'channel' => $channel, 'reporter_name' => $name, 'reporter_phone' => $phone,
                'category' => $cat, 'sub_category' => $sub, 'bobot' => $bobot, 'layanan' => $layanan,
                'description' => $desc, 'outlet_id' => $outletId,
            ]);

            $complaint->status = $status;
            $complaint->close_reason = $closeReason;
            $complaint->tindak_lanjut = $tindakLanjut;
            $complaint->created_at = now()->parse($created);
            $complaint->updated_at = $complaint->created_at;
            $complaint->ticket_number = Complaint::nextTicketNumber();
            $complaint->created_by = $channel === 'kasir' ? $kasir->id : $cc->id;
            $complaint->assigned_to = $status === 'open' ? null : $cc->id;
            $complaint->applySla();

            if ($status !== 'open') {
                $complaint->first_response_at = $complaint->created_at->copy()->addMinutes(35);
            }

            if ($resolved) {
                $complaint->resolved_at = now()->parse($resolved);
                $complaint->resolution = 'Dicuci ulang tanpa biaya dan diantar ke pelanggan.';
                $complaint->root_cause = 'Proses pemeriksaan akhir terlewat saat jam sibuk.';
                $complaint->compensation_amount = 25000;
            }

            $complaint->save();

            ComplaintActivity::create([
                'complaint_id' => $complaint->id, 'user_id' => $complaint->created_by,
                'type' => 'created', 'to_status' => 'open',
                'note' => 'Complaint dibuat lewat '.$complaint->channelLabel(),
            ]);
        }

        // Satu contoh tiket yang sedang dijeda: Handling di papan, tapi jam
        // SLA-nya berhenti sampai pelanggan membalas.
        $dijeda = Complaint::where('category', 'berbau')->first();
        $dijeda->forceFill([
            'paused_at'    => now()->subDay(),
            'pause_reason' => 'menunggu_pelanggan',
        ])->save();
    }
}
