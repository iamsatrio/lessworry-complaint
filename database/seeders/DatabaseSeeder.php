<?php

namespace Database\Seeders;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Outlet nyata dan akun tim. Tidak ada complaint contoh.
     *
     * Complaint karangan pernah ada di sini supaya papan kerja tidak kosong.
     * Dibuang atas permintaan satrio, dan itu keputusan yang benar: data
     * karangan membuat laporan terlihat masuk akal padahal isinya tidak
     * pernah terjadi, dan tidak ada yang tahu mana yang nyata saat data
     * sungguhan mulai masuk. Papan yang kosong justru jujur.
     *
     * Complaint nyata masuk lewat backfill spreadsheet (API-28).
     */
    public function run(): void
    {
        $this->outlet();
        $this->pengguna();
    }

    private function outlet(): void
    {
        $daftar = [
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

        foreach ($daftar as $idNevira => $nama) {
            Outlet::firstOrCreate(
                ['nevira_outlet_id' => $idNevira],
                ['name' => $nama],
            );
        }
    }

    /**
     * Akun tim. Password dibuat acak per akun dan hanya ditampilkan sekali,
     * ke layar orang yang menjalankan perintah — tidak ditulis ke berkas,
     * tidak masuk log, tidak tersimpan di repositori.
     *
     * Semuanya wajib mengganti password saat pertama masuk.
     */
    private function pengguna(): void
    {
        $tebet = Outlet::where('nevira_outlet_id', '118')->first();

        $daftar = [
            ['Satrio Wibowo',           'satrio@lessworry.id',   'admin',         null, null],
            ['Ainul Ghozi',             'ghozi@lessworry.id',    'admin',         null, null],
            ['Eric',                    'eric@lessworry.id',     'admin',         null, null],

            ['Tsulasa',                 'tsulasa@lessworry.id',  'supervisor',    null, null],
            ['Samsuri',                 'samsuri@lessworry.id',  'supervisor',    null, null],

            ['Audry',                   'audry@lessworry.id',    'customer_care', null, null],
            ['Adhyasta Dwi Yudistira',  'adhyasta@lessworry.id', 'customer_care', null, null],

            ['Arifin',                  'arifin@lessworry.id',   'divisi',        'produksi', null],

            ['Kasir',                   'kasir@lessworry.id',    'kasir',         null, $tebet?->id],
            ['Produksi',                'produksi@lessworry.id', 'divisi',        'produksi', null],
            ['Kurir',                   'kurir@lessworry.id',    'divisi',        'kurir', null],
        ];

        $baru = [];
        $lama = [];

        foreach ($daftar as [$nama, $email, $peran, $divisi, $outletId]) {
            if (User::where('email', $email)->exists()) {
                $lama[] = $email;

                continue;
            }

            // Tanpa simbol: password sementara ini disampaikan lewat pesan
            // dan diketik ulang orang, jadi karakter yang mudah salah baca
            // hanya menambah panggilan "tidak bisa masuk".
            $sementara = Str::password(14, symbols: false);

            User::create([
                'name'                 => $nama,
                'email'                => $email,
                'password'             => $sementara,
                'role'                 => $peran,
                'division'             => $divisi,
                'outlet_id'            => $outletId,
                'is_active'            => true,
                'must_change_password' => true,
            ]);

            $baru[] = [$nama, $email, $peran.($divisi ? ' / '.$divisi : ''), $sementara];
        }

        if ($lama) {
            $this->command?->warn('Sudah ada, dilewati (password tidak diubah): '.implode(', ', $lama));
        }

        if (! $baru) {
            return;
        }

        $this->command?->newLine();
        $this->command?->table(['Nama', 'Email', 'Peran', 'Password sementara'], $baru);
        $this->command?->warn('Password di atas hanya ditampilkan sekali. Tidak tersimpan di mana pun.');
        $this->command?->line('Sampaikan lewat jalur pribadi, jangan grup. Semuanya wajib diganti saat login pertama.');
    }
}
