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
     * Alamat yang TIDAK BOLEH lagi bisa dimasuki, apa pun password terakhirnya.
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
     * 3. Ketiga alamat `getnada.com` itu sendiri (API-50): `kasir@`,
     *    `produksi@`, `kurir@`. `getnada.com` adalah kotak surat publik yang
     *    bisa dibaca siapa saja yang tahu alamatnya, jadi akunnya bukan cuma
     *    tidak diseed lagi — ia harus MATI di mesin yang pernah membuatnya.
     *    Menghapus barisnya dari `$daftar` saja meninggalkannya hidup, dan
     *    hidup berarti bisa direbut lewat lupa-password ke kotak surat itu.
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
        'kasir@getnada.com',
        'produksi@getnada.com',
        'kurir@getnada.com',
    ];

    /**
     * Alamat kerja yang pernah dipakai akun demo, dan karena itu hanya
     * dimatikan kalau password bocornya MASIH BERLAKU pada akun di situ.
     *
     * Ketiganya dulu ada di DEMO_LAMA, jadi seeder mematikannya tiap kali
     * jalan. Buffon menjalankan akibatnya: Admin membuat akun Kasir outlet
     * Tebet dengan `kasir@lessworry.id` — alamat yang README-nya sendiri
     * anjurkan — deploy berikutnya mematikannya, kasirnya tidak bisa masuk
     * pagi berikutnya, dan jejaknya satu baris di keluaran deploy. Ia berulang
     * tiap deploy, bukan sekali. Dicabut atas keputusan API-131.
     *
     * Yang dicabut tiga alamatnya, BUKAN aturan password bocornya: akun demo
     * seeder paling awal memakai alamat yang sama dengan password harfiah
     * `password` yang ada di riwayat commit publik, dan ia tetap harus mati —
     * yang berbahaya passwordnya, bukan alamatnya. Syarat itulah bedanya
     * dengan DEMO_LAMA, yang mati tanpa syarat.
     *
     * Akibat yang diterima sadar (API-131): akun bersama dari seeder sebelas
     * akun yang sudah memakai password pilihan sendiri ikut tetap hidup di
     * alamat ini. Kalau sisa akun demo semacam itu ternyata masih ada di
     * produksi, ia dinonaktifkan SEKALI DENGAN TANGAN lewat halaman Pengguna.
     * Jangan tambahkan kode untuk itu: kode yang mematikan alamat ini tiap
     * deploy justru kesalahan yang API-131 perbaiki.
     */
    private const BEKAS_DEMO_DI_ALAMAT_KERJA = [
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
        // Daftar yang ditetapkan satrio (API-36, API-45, API-50): lima akun.
        //
        // SEMUANYA beralamat `@lessworry.id`. Itu syarat, bukan kebetulan:
        // `lessworry.id` memakai Google Workspace, kotak suratnya dikendalikan
        // Less Worry, jadi tautan verifikasi (API-35) yang sampai ke sana
        // benar-benar membuktikan kepemilikan akun. Tidak ada pengecualian
        // untuk siapa pun di daftar ini.
        //
        // Kasir, Produksi, dan Kurir dulu diseed dengan alamat `getnada.com`
        // supaya password sementara bisa diterima saat uji coba. Ketiganya
        // DIBUANG di API-50: kotak surat itu publik — siapa pun yang tahu
        // alamatnya bisa membacanya, jadi ia cukup untuk mengantar password
        // sekali pakai dan tidak pernah cukup sebagai bukti kepemilikan.
        // Verifikasi email tidak bisa berlaku penuh selama akun seperti itu
        // ada, jadi akunnya yang pergi, bukan verifikasinya yang dilonggarkan.
        //
        // Jangan hidupkan lagi barisnya dengan niat baik. Alamat
        // `getnada.com`-nya ada di DEMO_LAMA supaya mesin yang pernah
        // membuatnya ikut mematikannya. Yang dibuang adalah AKUNNYA, bukan
        // perannya: `kasir`, `divisi`, dan `supervisor` tetap bisa dipilih di
        // halaman Pengguna, dan akun sungguhannya dibuat Admin dari sana —
        // beralamat kerja, satu orang satu akun, bukan lewat seeder.
        //
        // Alamat kerja `kasir@`, `produksi@`, dan `kurir@` di `lessworry.id`
        // BOLEH dipakai untuk akun sungguhan itu (API-131). Lihat
        // BEKAS_DEMO_DI_ALAMAT_KERJA untuk satu-satunya sisa syaratnya.
        //
        // Customer Care ditambahkan di API-45; sebelumnya complaint Sedang dan
        // Berat tidak punya penutup selain supervisor dan admin. Alamat lama
        // `cc@lessworry.id` TIDAK dipakai ulang — ia tetap di DEMO_LAMA dan
        // tetap dinonaktifkan.
        //
        // `care@lessworry.id` akun peran, bukan akun perorangan: riwayat
        // complaint akan mencatat "Customer Care" yang menutup tiket, bukan
        // siapa orangnya. Begitu dua orang atau lebih memegang peran ini,
        // jejak audit berhenti bisa menjawab "siapa yang memutuskan" dan akun
        // perorangan jadi perlu. Dicatat di API-45, belum dikerjakan.
        $daftar = [
            ['Satrio Wibowo', 'satrio@lessworry.id', 'admin'],
            ['Ainul Ghozi', 'ghozi@lessworry.id', 'admin'],
            ['Eric', 'eric@lessworry.id', 'admin'],
            ['Tsulasa', 'tsulasa@lessworry.id', 'admin'],
            ['Customer Care', 'care@lessworry.id', 'customer_care'],
        ];

        $dicetak = [];

        foreach ($daftar as [$nama, $email, $peran]) {
            $user = User::where('email', $email)->first();

            // `name` ditulis tanpa syarat: nama bukan cara mencabut akses, dan
            // tidak ada keputusan manusia yang hilang kalau seeder mengoreksi
            // ejaannya.
            $atribut = [
                'name' => $nama,
            ];

            // `is_active`, `role`, `division`, dan `outlet_id` hanya disetel
            // saat akun DIBUAT.
            // Menonaktifkan orang dan menurunkan perannya adalah keputusan
            // manusia yang berumur — keduanya satu-satunya cara mencabut akses,
            // karena akun tidak pernah dihapus. Deploy berikutnya tidak boleh
            // membatalkannya tanpa ada yang memutuskan begitu.
            //
            // `role` dulu ditulis tanpa syarat, dan itu pagar yang ada
            // pintunya: Admin mencabut hak admin seseorang lewat halaman
            // Pengguna, deploy berikutnya mengembalikan peran TERTINGGI di
            // sistem, dan jejaknya satu baris di keluaran deploy. Diperbaiki
            // atas keputusan API-131.
            //
            // Mengubah peran akun yang sudah ada tetap bisa — lewat halaman
            // Pengguna, yang berjejak. Yang dilindungi di sini penimpaannya,
            // bukan pembuatannya: akun yang belum ada tetap dibuat dengan
            // peran dari daftar, tanpa outlet dan tanpa divisi — kelima akun
            // itu melihat seluruh outlet dan tidak terikat divisi mana pun.
            //
            // `division` dan `outlet_id` dulu ditulis tanpa syarat, dan
            // alasannya sah pada masanya: supaya akun yang dulu terikat outlet
            // atau divisi ikut dilepaskan saat perannya naik. Alasan itu batal
            // atas keputusan API-132, karena harganya lebih besar dari yang
            // dibelinya. Setelah `role` dijaga, akun di daftar ini BISA jadi
            // kasir ber-outlet — Admin menurunkannya lewat halaman Pengguna —
            // dan penulisan tanpa syarat melepas outletnya tiap deploy. Kasir
            // tanpa outlet tidak melihat complaint satu pun (`Complaint`
            // menutup cakupan kosong dengan `1 = 0`), jadi bukan kebocoran
            // wewenang, melainkan kasir yang tidak bisa bekerja pagi itu.
            //
            // Yang dibeli penulisan tanpa syarat hanya satu kolom label di
            // daftar Pengguna: nilai sisa pada akun `admin`/`customer_care`
            // tidak berlaku di mana pun selain tampilan — setiap pembaca
            // `outlet_id`/`division` yang lain bergerbang peran
            // (`Complaint::terlihatOleh`, `Outlet`, `Tagihan`,
            // `ComplaintController`).
            //
            // Akibat yang diterima sadar: akun yang dulu terikat outlet atau
            // divisi lalu naik jadi `admin`/`customer_care` MENYIMPAN nilai
            // lamanya, dan daftar Pengguna menampilkan label lama itu.
            // Dibersihkan SEKALI DENGAN TANGAN lewat halaman Pengguna, aturan
            // yang sama dengan BEKAS_DEMO_DI_ALAMAT_KERJA. Jangan tambahkan
            // kode untuk itu: kode yang membersihkannya tiap deploy justru
            // cacat yang API-132 buang.
            //
            // Satu aturan untuk keempat kolom, supaya orang berikutnya bisa
            // mengingatnya: seeder menulis kolom identitas hanya ketika ia
            // MEMBUAT akunnya.
            //
            // Akibat yang perlu diketahui: di mesin yang akunnya dibuat seeder
            // paling lama, `satrio@lessworry.id` bisa tertinggal sebagai
            // `supervisor` dan terkunci dari pengelolaan pengguna. Jalan
            // keluarnya bukan menimpa peran tiap deploy, melainkan
            // `php artisan lessworry:pulihkan-admin <email>` — sekali, dan
            // tercatat di jejak audit akunnya.
            if ($user === null) {
                $atribut['is_active'] = true;
                $atribut['role'] = $peran;
                $atribut['division'] = null;
                $atribut['outlet_id'] = null;
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
                $dicetak[] = [$nama, $email, $user->role, $sementara];
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
     * Untuk DEMO_LAMA passwordnya diganti tanpa syarat, bukan hanya kalau
     * masih bocor: akun itu tidak boleh bisa dimasuki lagi apa pun password
     * terakhirnya, dan `is_active = false` saja bisa terbalik oleh satu
     * perbaikan manual.
     *
     * BEKAS_DEMO_DI_ALAMAT_KERJA dijaga syarat, karena alamat itu sekarang
     * dipakai akun kasir dan divisi yang sungguhan (API-131). Yang dimatikan
     * hanya akun yang masih menerima password bocor — dan `Hash::check`
     * menjawab tepat pertanyaan itu, bukan gejalanya.
     */
    private function matikanDemoLama(): void
    {
        $dimatikan = [];

        foreach (self::DEMO_LAMA as $email) {
            $user = User::where('email', $email)->first();

            if ($user === null) {
                continue;
            }

            $this->matikan($user);

            $dimatikan[] = $email;
        }

        foreach (self::BEKAS_DEMO_DI_ALAMAT_KERJA as $email) {
            $user = User::where('email', $email)->first();

            // Akun sungguhan di alamat ini tidak disentuh sama sekali:
            // passwordnya sendiri, dan ia tetap hidup tiap deploy.
            if ($user === null || ! Hash::check(self::PASSWORD_BOCOR, $user->password)) {
                continue;
            }

            $this->matikan($user);

            $dimatikan[] = $email;
        }

        if ($dimatikan) {
            $this->command->warn(
                'Akun lama dinonaktifkan dan passwordnya dibuang: '.implode(', ', $dimatikan)
            );
        }
    }

    /** Dinonaktifkan dan passwordnya dibuang acak, bukan dihapus. */
    private function matikan(User $user): void
    {
        $user->forceFill([
            'is_active' => false,
            'password' => Str::password(24, symbols: false),
            'must_change_password' => true,
        ])->save();
    }
}
