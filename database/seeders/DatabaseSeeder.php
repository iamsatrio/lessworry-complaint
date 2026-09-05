<?php

namespace Database\Seeders;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
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
     * Alamat yang TIDAK BOLEH lagi bisa dimasuki.
     *
     * Tiga sumbernya, satu perlakuannya:
     *
     * 1. Akun demo seeder lama (`cc@`, `kasirbaru@`) — password harfiah
     *    `password` yang ada di riwayat commit publik.
     * 2. Empat orang yang dibuang dari daftar akun (API-36): `samsuri@`,
     *    `arifin@`, `adhyasta@`, `audry@`. Cukup dihapus dari daftar hanya
     *    kalau basis datanya baru; di mesin yang sudah memuat 11 akun versi
     *    lama, menghapus barisnya dari `$daftar` justru MENINGGALKANNYA
     *    HIDUP — seeder tidak menyentuh apa yang tidak disebutnya.
     * 3. Tiga alamat yang berganti domain: `kasir@`, `produksi@`, `kurir@`
     *    di `lessworry.id`. Orangnya tetap, alamatnya pindah ke
     *    `getnada.com`, jadi baris lamanya adalah akun kedua yang tidak
     *    dipakai siapa-siapa — dan masih menerima password lamanya.
     *
     * Dinonaktifkan dan passwordnya dibuang, bukan dihapus: complaint
     * menyimpan siapa yang mencatat dan menutupnya, dan jejak itu harus utuh.
     */
    private const DEMO_LAMA = [
        'cc@lessworry.id',
        'kasirbaru@lessworry.id',
        'samsuri@lessworry.id',
        'arifin@lessworry.id',
        'adhyasta@lessworry.id',
        'audry@lessworry.id',
        'kasir@lessworry.id',
        'produksi@lessworry.id',
        'kurir@lessworry.id',
    ];

    /**
     * Password harfiah yang dibagikan seeder-seeder lama, dan yang karena itu
     * ada di riwayat commit. Dipakai untuk mengenali akun yang masih bisa
     * dimasuki siapa pun yang membaca repositori.
     */
    private const PASSWORD_BOCOR = 'password';

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
     * repositori. Password yang sudah dipilih sendiri oleh orangnya TIDAK
     * pernah disetel ulang — yang diterbitkan ulang hanya akun yang masih
     * menerima password bocor, jadi seeder aman dijalankan tiap deploy.
     */
    private function pengguna(): void
    {
        $tebet = Outlet::where('nevira_outlet_id', '118')->first();

        // Daftar yang ditetapkan satrio (API-36): tujuh akun, bukan sebelas.
        //
        // Tiga akun bersama — Kasir, Produksi, Kurir — dipakai bergantian oleh
        // beberapa orang, jadi alamatnya bukan alamat pribadi siapa pun.
        // Alamatnya `getnada.com` supaya password sementara bisa diterima
        // saat uji coba. Kotak surat itu bisa dibaca siapa saja yang tahu
        // alamatnya, jadi ia CUKUP untuk mengantar password sekali pakai dan
        // TIDAK CUKUP sebagai bukti kepemilikan akun — verifikasi email
        // (API-35) tidak boleh bersandar padanya.
        //
        // Tidak ada akun Customer Care di sini, dan itu memang isi daftarnya.
        // Akibatnya complaint Sedang dan Berat tidak punya penutup selain
        // supervisor dan admin; sudah diangkat ke satrio di API-36.
        $daftar = [
            ['Satrio Wibowo', 'satrio@lessworry.id', 'admin', null, null],
            ['Ainul Ghozi', 'ghozi@lessworry.id', 'admin', null, null],
            ['Eric', 'eric@lessworry.id', 'admin', null, null],

            ['Tsulasa', 'tsulasa@lessworry.id', 'supervisor', null, null],

            ['Kasir', 'kasir@getnada.com', 'kasir', null, $tebet?->id],
            ['Produksi', 'produksi@getnada.com', 'divisi', 'produksi', null],
            ['Kurir', 'kurir@getnada.com', 'divisi', 'kurir', null],
        ];

        $dicetak = [];

        foreach ($daftar as [$nama, $email, $peran, $divisi, $outletId]) {
            $user = User::where('email', $email)->first();

            $atribut = [
                'name' => $nama,
                'role' => $peran,
                'division' => $divisi,
                'outlet_id' => $outletId,
            ];

            // `is_active` hanya disetel saat akun DIBUAT. Menonaktifkan orang
            // adalah keputusan manusia yang berumur — itu satu-satunya cara
            // mencabut akses, karena akun tidak pernah dihapus. Deploy
            // berikutnya tidak boleh menghidupkannya kembali tanpa ada yang
            // memutuskan begitu.
            if ($user === null) {
                $atribut['is_active'] = true;
            }

            // Password diterbitkan kalau akunnya baru, atau kalau password
            // yang bocor MASIH BERLAKU pada akun itu.
            //
            // Sebelumnya baris ini memakai `! $user->must_change_password`
            // sebagai perantara. Perantara itu salah dua arah sekaligus:
            // seeder paling awal memberi password bocor SEKALIGUS menandai
            // wajib-ganti, jadi akun yang bisa diambil alih justru dilewati;
            // dan setelah orang benar-benar mengganti passwordnya, tandanya
            // kembali false sehingga seeder berikutnya menghapus password
            // pilihannya sendiri.
            //
            // Passwordnya adalah nilai harfiah yang diketahui, jadi tanyakan
            // faktanya, bukan gejalanya. Tidak ada positif palsu: aturan
            // password di PasswordController membuat `password` tidak bisa
            // dipasang siapa pun lewat antarmuka.
            $perluPasswordBaru = $user === null || Hash::check(self::PASSWORD_BOCOR, $user->password);

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
        $this->command->newLine();
        // Seeder TIDAK menandai satu pun akun terverifikasi. Verifikasi harus
        // dibuktikan, bukan diberikan — kalau seeder memberikannya, gerbang
        // yang baru dipasang tidak menahan apa pun. Yang perlu diketahui orang
        // yang menjalankan seeder adalah akibatnya, dan jalan keluarnya.
        $this->command->warn('Belum ada akun yang terverifikasi emailnya.');
        $this->command->line(
            'Login pertama akan mengirim tautan verifikasi ke alamat di atas. Alamat yang '
            .'tidak ada berarti akun yang tidak bisa dipakai — periksa dulu sebelum dibagikan.'
        );
        $this->command->line(
            'Kalau SMTP belum siap atau semua admin terkurung: php artisan lessworry:pulihkan-admin <email>'
        );
    }

    /**
     * Alamat yang tidak dipakai lagi dinonaktifkan dan passwordnya diganti
     * acak — bukan dihapus, supaya jejak audit complaint yang pernah
     * disentuhnya tetap utuh.
     *
     * Passwordnya diganti tanpa syarat, bukan hanya kalau masih bocor:
     * akun ini tidak boleh bisa dimasuki lagi apa pun password terakhirnya,
     * dan `is_active = false` saja bisa terbalik oleh satu perbaikan manual.
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
                'Akun lama dinonaktifkan dan passwordnya dibuang: '.implode(', ', $dimatikan)
            );
        }
    }
}
