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
        $samples = [
            ['wa_cc', 'Ibu Rina', '081234567801', 'hasil_cuci', 'Masih kotor', 'urgent', 'baru',
             'Kemeja putih masih ada noda di bagian kerah setelah dicuci.', $pusat->id, '-3 hours', null],
            ['kasir', 'Pak Budi', '081234567802', 'barang_hilang', 'Item kurang', 'high', 'ditangani',
             'Pelanggan menghitung 12 potong saat menyerahkan, yang kembali 11 potong.', $pusat->id, '-26 hours', null],
            ['wa_outlet', 'Mbak Sinta', '081234567803', 'keterlambatan', 'Telat selesai', 'medium', 'selesai',
             'Dijanjikan selesai Selasa, baru bisa diambil Kamis.', $cabang->id, '-5 days', '-4 days'],
            ['wa_cc', 'Ibu Rina', '081234567801', 'salah_tagih', 'Berat tidak sesuai', 'medium', 'selesai',
             'Ditagih 5kg padahal timbangan menunjukkan 4,2kg.', $pusat->id, '-8 days', '-8 days'],
            ['kasir', 'Pak Deni', '081234567804', 'hasil_cuci', 'Bau', 'high', 'menunggu_pelanggan',
             'Cucian bau apek. Diminta membawa kembali untuk dicuci ulang.', $cabang->id, '-2 days', null],
        ];

        foreach ($samples as [$channel, $name, $phone, $cat, $sub, $priority, $status, $desc, $outletId, $created, $resolved]) {
            $complaint = new Complaint([
                'channel' => $channel, 'reporter_name' => $name, 'reporter_phone' => $phone,
                'category' => $cat, 'sub_category' => $sub, 'priority' => $priority,
                'status' => $status, 'description' => $desc, 'outlet_id' => $outletId,
            ]);

            $complaint->created_at = now()->parse($created);
            $complaint->updated_at = $complaint->created_at;
            $complaint->ticket_number = Complaint::nextTicketNumber();
            $complaint->created_by = $channel === 'kasir' ? $kasir->id : $cc->id;
            $complaint->assigned_to = $status === 'baru' ? null : $cc->id;
            $complaint->applySla();

            if ($status !== 'baru') {
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
                'type' => 'created', 'to_status' => 'baru',
                'note' => 'Complaint dibuat lewat '.$complaint->channelLabel(),
            ]);
        }
    }
}
