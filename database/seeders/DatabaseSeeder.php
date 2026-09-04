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
     * Akun demo dari seeder lama. Tiga di antaranya memakai email yang
     * sekarang dipakai akun sungguhan, dan semuanya memakai password
     * harfiah `password` yang ada di riwayat commit publik.
     *
     * Yang emailnya dipakai ulang diperbaiki oleh daftar di bawah. Yang
     * tidak dipakai lagi harus dinonaktifkan — kalau hanya dilewati, akun
     * itu hidup terus dengan password yang bisa dibaca siapa pun.
     */
    private const DEMO_LAMA = ['cc@lessworry.id', 'kasirbaru@lessworry.id'];

    /**
     * Akun tim.
     *
     * Seeder ini **memperbaiki keadaan**, bukan sekadar membuat yang belum
     * ada. Melewati akun yang sudah ada terasa aman tapi tidak: di mesin
     * yang pernah memakai seeder lama, `satrio@lessworry.id` akan tetap
     * supervisor dengan password `password` — terkunci dari pengelolaan
     * pengguna, sekaligus bisa dimasuki siapa pun yang membaca repositori.
     *
     * Password sementara acak dicetak sekali ke layar orang yang menjalankan
     * perintah. Tidak ditulis ke berkas, tidak masuk log, tidak masuk
     * repositori. Akun yang sudah menunggu penggantian password tidak
     * disetel ulang, supaya seeder aman dijalankan berulang.
     */
    private function pengguna(): void
    {
        $tebet = Outlet::where('nevira_outlet_id', '118')->first();

        $daftar = [
            ['Satrio Wibowo', 'satrio@lessworry.id', 'admin', null, null],
            ['Ainul Ghozi', 'ghozi@lessworry.id', 'admin', null, null],
            ['Eric', 'eric@lessworry.id', 'admin', null, null],

            ['Tsulasa', 'tsulasa@lessworry.id', 'supervisor', null, null],
            ['Samsuri', 'samsuri@lessworry.id', 'supervisor', null, null],

            ['Audry', 'audry@lessworry.id', 'customer_care', null, null],
            ['Adhyasta Dwi Yudistira', 'adhyasta@lessworry.id', 'customer_care', null, null],

            ['Arifin', 'arifin@lessworry.id', 'divisi', 'produksi', null],

            ['Kasir', 'kasir@lessworry.id', 'kasir', null, $tebet?->id],
            ['Produksi', 'produksi@lessworry.id', 'divisi', 'produksi', null],
            ['Kurir', 'kurir@lessworry.id', 'divisi', 'kurir', null],
        ];

        $dicetak = [];

        foreach ($daftar as [$nama, $email, $peran, $divisi, $outletId]) {
            $user = User::where('email', $email)->first();

            $atribut = [
                'name' => $nama,
                'role' => $peran,
                'division' => $divisi,
                'outlet_id' => $outletId,
                'is_active' => true,
            ];

            // Password hanya diterbitkan kalau akunnya baru, atau kalau akun
            // lama belum menunggu penggantian — yang berarti passwordnya masih
            // password lama yang bocor.
            $perluPasswordBaru = $user === null || ! $user->must_change_password;

            if ($perluPasswordBaru) {
                // Tanpa simbol: password ini disampaikan lewat pesan dan
                // diketik ulang orang. Karakter yang mudah salah baca hanya
                // menambah panggilan "tidak bisa masuk".
                $sementara = Str::password(14, symbols: false);
                $atribut['password'] = $sementara;
                $atribut['must_change_password'] = true;
            }

            if ($user === null) {
                $user = User::create($atribut + ['email' => $email]);
            } else {
                $user->forceFill($atribut)->save();
            }

            if ($perluPasswordBaru) {
                $dicetak[] = [$nama, $email, $peran.($divisi ? ' / '.$divisi : ''), $sementara];
            }
        }

        $this->matikanDemoLama();

        if (! $dicetak) {
            $this->command->info('Semua akun sudah menunggu penggantian password. Tidak ada yang disetel ulang.');

            return;
        }

        $this->command->newLine();
        $this->command->table(['Nama', 'Email', 'Peran', 'Password sementara'], $dicetak);
        $this->command->warn('Password di atas hanya ditampilkan sekali. Tidak tersimpan di mana pun.');
        $this->command->line('Sampaikan lewat jalur pribadi, jangan grup. Semuanya wajib diganti saat login pertama.');
    }

    /**
     * Akun demo yang tidak dipakai lagi dinonaktifkan dan passwordnya diganti
     * acak — bukan dihapus, supaya jejak audit complaint yang pernah
     * disentuhnya tetap utuh.
     */
    private function matikanDemoLama(): void
    {
        $dimatikan = [];

        foreach (self::DEMO_LAMA as $email) {
            $user = User::where('email', $email)->first();

            if ($user === null) {
                continue;
            }

            $user->forceFill([
                'is_active' => false,
                'password' => Str::password(24, symbols: false),
                'must_change_password' => true,
            ])->save();

            $dimatikan[] = $email;
        }

        if ($dimatikan) {
            $this->command->warn(
                'Akun demo lama dinonaktifkan dan passwordnya dibuang: '.implode(', ', $dimatikan)
            );
        }
    }
}
