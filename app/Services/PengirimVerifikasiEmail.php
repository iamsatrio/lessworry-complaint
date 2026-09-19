<?php

namespace App\Services;

use App\Mail\VerifikasiEmail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Menerbitkan dan mengirim tautan verifikasi. (API-35 bagian 2 dan 5)
 *
 * Dipisah dari controller karena dua pemanggil yang berbeda memakainya:
 * login pertama yang belum terverifikasi, dan tombol kirim ulang.
 */
class PengirimVerifikasiEmail
{
    public const TERKIRIM = 'terkirim';

    public const DIBATASI = 'dibatasi';

    public const GAGAL = 'gagal';

    /** Tautan berlaku 60 menit, lalu mati sendiri. */
    public const UMUR_MENIT = 60;

    public const BATAS = 3;

    public const JENDELA_DETIK = 600;

    /**
     * Penanda "pengiriman terakhir gagal", dibaca /health.
     *
     * Tanpa ini /health menyimpulkan keberhasilan dari konfigurasi: ia membalas
     * `mail: ok` begitu mailernya bukan log/array, tanpa pernah tahu apakah
     * mailer itu bisa dihubungi. Produksi dengan SMTP terpasang tapi mati —
     * kredensial kedaluwarsa, host pindah, port ditutup firewall — mengunci
     * SETIAP akun di login pertama sementara pemantauannya tetap hijau. Itu
     * keadaan yang paling mungkin terjadi setelah deploy pertama berhasil.
     * (Tinjauan PR #17 nomor 2)
     *
     * Yang dicatat hasilnya, bukan SMTP-nya yang ditanya: memanggil SMTP dari
     * /health membuat setiap ketukan pemantau jadi satu koneksi keluar —
     * kesalahan yang sudah dihindari pemeriksaan NEVIRA.
     *
     * Disimpan di store khusus /health (config/health.php), bukan store
     * bawaan, dengan alasan yang sama seperti hasil pemeriksaan NEVIRA: store
     * bawaan produksi adalah `database`, dan penandanya harus tetap terbaca
     * justru saat database yang mati.
     */
    public const CACHE_GAGAL = 'health:mail:gagal';

    /**
     * Penandanya kedaluwarsa sendiri setelah 24 jam.
     *
     * Bukan supaya papannya cepat hijau lagi: selama SMTP-nya benar-benar
     * mati, setiap percobaan login memperbarui penanda ini, jadi /health tetap
     * merah. Yang dihindari adalah satu kegagalan sesaat berbulan-bulan lalu
     * mengunci papan pada sistem yang sejak itu tidak pernah mengirim apa pun.
     */
    public const CACHE_GAGAL_DETIK = 86400;

    /**
     * Mailer yang menerima surat lalu tidak mengantarkannya ke mana pun.
     *
     * `log` menulisnya ke storage/logs/laravel.log, `array` menyimpannya di
     * memori proses. Keduanya "berhasil" dari sudut pandang Mail::send —
     * tidak ada galat yang bisa ditangkap — jadi satu-satunya cara membedakan
     * surat yang terkirim dari surat yang tidak pernah berangkat adalah
     * membaca konfigurasinya, bukan menunggu kegagalan.
     */
    public const MAILER_TANPA_PENGIRIMAN = ['log', 'array'];

    /**
     * Benar kalau surat hanya dicatat, tidak diantar.
     *
     * Dipakai halaman verifikasi supaya tidak mengaku mengirim sesuatu yang
     * tidak dikirim, dan /health supaya keadaan ini terlihat dari luar sebelum
     * ada yang terkunci karenanya. (API-47)
     */
    public static function hanyaMencatat(): bool
    {
        return in_array((string) config('mail.default'), self::MAILER_TANPA_PENGIRIMAN, true);
    }

    /**
     * Satu-satunya bentuk pesan galat yang boleh masuk log. (API-121)
     *
     * Dua hal berbeda harus hilang dari kalimat galat SMTP, dan alasannya
     * berbeda: kredensial karena ia rahasia, alamat email karena ia data
     * pribadi. Digabung di sini supaya tidak ada pemanggil yang mendapat
     * separuhnya — sebelum ini `kirim()` memanggil `tanpaKredensial()`
     * langsung, dan alamat email penerima lolos ke `storage/logs`.
     *
     * URUTANNYA MENENTUKAN. Kredensial dulu, alamat email sesudahnya:
     * `smtp://user:pa ss@smtp.lessworry.id` memuat potongan yang berbentuk
     * alamat email (`ss@smtp.lessworry.id`). Kalau penyensoran alamat
     * berjalan lebih dulu, potongan itu diganti penanda, DSN-nya tidak lagi
     * cocok dengan pola kredensial, dan `user:pa ` tertinggal di log.
     *
     * Penanda yang dipakai keduanya BERBEDA — `[kredensial-disensor]` dan
     * `[email-disensor]` — dan itu bukan hiasan. Kalau satu lintasan meluber
     * ke wilayah lintasan lain, yang muncul di log satu penanda dua kali,
     * bukan satu dari masing-masing. Penanda yang salah pada baris yang
     * bocor lebih buruk daripada tidak ada penanda sama sekali: baris yang
     * tampak sudah ditangani tidak akan diperiksa siapa pun lagi. Itu juga
     * alasan lintasan kredensial dibiarkan MENYENSOR BERLEBIH pada bentuk
     * ambigu, bukan melewatkannya — lihat pagar 1 di `tanpaKredensial()`.
     */
    public static function amanUntukLog(string $pesan): string
    {
        return self::tanpaAlamatEmail(self::tanpaKredensial($pesan));
    }

    /**
     * Buang setiap alamat email dari pesan galat. (API-121)
     *
     * `Mail::to($user->email)` membuat alamat penerima muncul di kalimat
     * galat pada jalur "address rejected" — jalur yang paling sering dipakai
     * — dan dari sana ia masuk `storage/logs`. Alamat itu milik anggota tim
     * (pelanggan tidak punya akun dan tidak pernah dikirimi surat dari jalur
     * ini), tapi ia tetap data pribadi dan tetap tidak boleh masuk log.
     *
     * Dua keputusan di API-121, keduanya sengaja, keduanya bukan selera:
     *
     *   1. DISENSOR PENUH, bukan disamarkan sebagian (`b***@lessworry.id`).
     *      Entri lognya sudah memuat `user_id` di baris yang sama, jadi
     *      "siapa yang gagal dikirimi" sudah terjawab dan domainnya bisa
     *      ditarik dari catatan pengguna itu. Penyamaran sebagian menyisakan
     *      data pribadi sambil menambah nol informasi yang belum ada.
     *   2. SEMUA alamat, tanpa daftar kecualian — termasuk alamat sistem
     *      seperti `noreply@lessworry.id`. Membedakan alamat sistem dari
     *      alamat orang menuntut aturan yang bisa diperiksa mesin; tanpa itu
     *      yang berlaku pendapat per-tinjauan. Alamat sistem yang ikut
     *      tersensor tidak menghilangkan apa pun — ia konstanta dari
     *      konfigurasi, bukan temuan.
     *
     * Nama host tetap DIBIARKAN, alasan yang sama dengan `tanpaKredensial()`:
     * `mail.example:587` bukan alamat email dan tidak punya `@`, jadi ia di
     * luar jangkauan pola ini.
     *
     * Batas yang disengaja: alamat tanpa titik di domain (`mailer@localhost`)
     * tidak disensor. Syarat titik itu yang menjaga lintasan ini tidak
     * menelan `user@host` di tengah kalimat galat. Jalur yang jadi alasan
     * issue ini tertutup penuh — kolom `email` divalidasi, jadi alamat
     * anggota tim selalu berdomain bertitik.
     */
    public static function tanpaAlamatEmail(string $pesan): string
    {
        // Bagian lokal TIDAK memuat `[` dan `]`, dan itu yang menjaga penanda
        // kredensial tetap utuh: sesudah `tanpaKredensial()`, pesannya memuat
        // `smtp://[kredensial-disensor]@smtp.lessworry.id`. Karakter tepat
        // sebelum `@` di situ adalah `]`, yang tidak bisa jadi bagian lokal,
        // jadi polanya tidak cocok dan NAMA HOST-nya tidak ikut terbuang.
        // Hilangnya nama host adalah regresi, bukan penyensoran yang lebih
        // aman — itu satu-satunya petunjuk yang tersisa untuk menelusuri.
        return (string) preg_replace(
            '#[\w.%+\-]+@[\w\-]+(?:\.[\w\-]+)*\.[A-Za-z]{2,}#',
            '[email-disensor]',
            $pesan
        );
    }

    /**
     * Buang kredensial dari pesan galat sebelum ia masuk log. (API-37 nomor 1)
     *
     * Kegagalan koneksi biasa hanya memuat nama host. Yang membawa DSN utuh
     * adalah galat dari DSN yang salah bentuk dan beberapa jalur
     * TransportException lain — jarang, tapi persis jalur yang muncul saat
     * seseorang baru salah menulis `.env`, yaitu saat orang paling mungkin
     * menempelkan isi log ke tempat lain untuk minta tolong.
     *
     * Nama host sengaja DIBIARKAN: itu yang berguna saat menelusuri, dan ia
     * bukan rahasia. Yang dibuang bagian `user:pass` saja, diganti penanda
     * yang terlihat supaya pembacanya tahu ada yang disensor dan tidak
     * mengira DSN-nya memang tidak berkredensial.
     *
     * Fungsi ini menangani KREDENSIAL saja, dan itu sengaja: alamat email
     * dibuang terpisah oleh `tanpaAlamatEmail()`. Yang dipanggil sebelum
     * menulis log adalah `amanUntukLog()`, yang menjalankan keduanya dengan
     * urutan yang benar. Jangan memanggil yang ini langsung untuk sesuatu
     * yang akan masuk log — separuh penyensoran bukan penyensoran. (API-121)
     *
     * BENTUK YANG MASIH LOLOS UTUH — tidak tersensor sama sekali, bukan
     * tersensor sebagian. Ditulis apa adanya supaya pembaca berikutnya tidak
     * mengira fungsi ini menjamin lebih dari yang ia lakukan:
     *
     *   - Password yang memuat LEBIH DARI TIGA spasi. Lintasan kedua dibatasi
     *     tiga spasi; di atas itu ia menolak, dan seluruh DSN masuk log.
     *   - `smtp://user:587 @host` — password yang seluruhnya angka lalu spasi.
     *     Pagar nomor port membacanya sebagai port. Sudah begitu sejak sebelum
     *     API-122 dan tidak diubah di sini.
     *
     * TERSENSOR SEBAGIAN — potongan password tertinggal di log:
     *
     *   - Password yang memuat tanda at, lalu spasi, lalu tanda at lagi.
     *     Masukan `smtp://user:pa@ss w0rd@host` menyisakan `ss w0rd` di log.
     *     Milik LINTASAN PERTAMA, ada sejak API-120, dan TIDAK tertutup oleh
     *     perbaikan lintasan kedua di API-126 — alasannya ditulis di komentar
     *     lintasan pertama, di bawah. Diukur, bukan diperkirakan; belum ada
     *     issue-nya.
     *
     * Arah sebaliknya — menelan teks yang BUKAN kredensial — masih terbuka,
     * dan sengaja dibiarkan terbuka. `scheme://host:teks` yang diikuti alamat
     * surel dalam tiga spasi tetap tertelan: `smtp://mail.example:abc gagal
     * budi@lessworry.id` keluar sebagai `smtp://[kredensial-disensor]@lessworry.id`,
     * jadi nama host hilang dari log padahal tidak ada kredensial di situ.
     *
     * Itu HARGA, bukan kelalaian. Bentuk itu tidak bisa dibedakan dari
     * `pengguna:password` yang nama penggunanya bertitik, dan dari dua
     * kegagalan yang mungkin — kehilangan nama host, atau membocorkan
     * kredensial — hanya satu yang boleh dipilih. Percobaan pertama API-124
     * memilih yang lain (pagar titik di lintasan kedua) dan hasilnya
     * `smtp://budi.santoso:pa ss@host` lolos utuh; itu dibatalkan di sini.
     * Menutup arah ini butuh pembeda yang tidak ada di teksnya — misalnya
     * daftar host yang sah dari konfigurasi. (API-124)
     *
     * Kelas itu TIDAK sama dengan yang ditutup API-126. Yang ditutup API-126
     * terjadi pada DSN yang MEMANG berkredensial, dan akar sebabnya urutan
     * backtracking, bukan pembeda yang tidak ada: `smtp://user:pa
     * ss@smtp.lessworry.id hubungi budi@lessworry.id` dulu kehilangan nama
     * host padahal tidak ada yang ambigu di situ. Keputusan "kalau ambigu,
     * SENSOR" di atas tidak berubah karenanya. Alasannya ditulis di sini dan
     * bukan cuma dirujuk ke nomor issue: API-124 sudah `cancelled`, dan
     * rujukan ke issue yang tertutup tidak bisa dibaca sebagai penjelasan.
     */
    public static function tanpaKredensial(string $pesan): string
    {
        // Dua lintasan, dan urutannya menentukan. Lintasan pertama menangani
        // kredensial yang tidak memuat spasi — bentuk yang lazim — sehingga
        // DSN normal tidak pernah sampai ke lintasan kedua yang lebih longgar.
        //
        // Batasnya SPASI saja. Sebelum API-122 `/` ikut membatasi, dan itu
        // membuat password ber-`/` lolos separuh: `smtp://user:p@ss/w0rd@host`
        // hanya tersensor sampai `p`. Percobaan pertama API-122 menukarnya
        // dengan `,`, `)`, `"` sebagai pembatas — itu regresi yang lebih buruk:
        // ketiga karakter itu lazim di password buatan generator, dan hasilnya
        // nol penyensoran, termasuk nama penggunanya. Yang menjaga alamat
        // surel di kalimat yang sama tetap terbaca adalah batas spasi plus
        // syarat host di bawah, bukan ketiga karakter itu.
        //
        // `(?=[\w.\-]|$)` mensyaratkan ada host sesudah `@` yang dipilih.
        // Tanpa itu, password seperti `p@ s` membuat pola berhenti di `@`
        // pertama — karena `@` berikutnya ada di seberang spasi, di luar
        // jangkauannya — dan menyisakan ` s` di log. Dengan syarat itu,
        // lintasan pertama menolak dan lintasan kedua yang menanganinya.
        //
        // BATAS YANG MASIH TERBUKA di lintasan ini, disebut juga di docblock:
        // `(?!\S*@)` hanya melihat `@` di dalam deretan tanpa spasi yang
        // sedang dibaca. Pada `smtp://user:pa@ss w0rd@host`, `@` sesudah `pa`
        // lolos kedua syarat — `@` berikutnya ada di seberang spasi, dan
        // sesudahnya ada `ss` yang terbaca sebagai host — jadi lintasan ini
        // menyensor sampai situ dan `ss w0rd` tertinggal. Sesudah itu barisnya
        // sudah berubah, jadi lintasan kedua tidak punya `://` berkredensial
        // lagi untuk dipegang; melazy-kan lintasan kedua (API-126) tidak
        // menolong untuk bentuk ini. Menutupnya butuh keputusan yang belum
        // ada: di `A@B C@D@host` tidak ada tanda yang membedakan `B` (masih
        // password) dari host sebenarnya, dan aturan yang sama juga memutuskan
        // nasib `smtp://host:teks hubungi budi@lessworry.id`.
        $pesan = (string) preg_replace(
            '#(?<=://)\S*:\S*@(?!\S*@)(?=[\w.\-]|$)#',
            '[kredensial-disensor]@',
            $pesan
        );

        // Lintasan kedua: password yang MEMUAT SPASI. Lintasan pertama tidak
        // menyeberangi spasi sama sekali, jadi tanpa lintasan ini seluruh DSN
        // lolos utuh ke `storage/logs` tanpa satu pun tanda bahwa penyensoran
        // gagal — kegagalan yang tak bersuara.
        //
        // Menyeberangi spasi berarti risikonya bergeser: yang tadinya kurang
        // menyensor jadi berpotensi menelan nama host dan alamat surel
        // penerima yang kebetulan ada di kalimat yang sama. Keduanya bukan
        // kredensial dan harus tetap terbaca. Empat pagar menahannya:
        //
        //   1. `[^\s/\r\n]+?` sebelum `:` — nama pengguna DSN tidak pernah
        //      memuat `/`; kalau ada `/`, yang dibaca itu JALUR, bukan
        //      kredensial. Ini yang menjaga
        //      `https://api.nevira.id/v1:abc gagal budi@lessworry.id` utuh.
        //
        //      TITIK TIDAK ikut dilarang di sini, dan itu keputusan yang
        //      dibalik dari percobaan pertama API-124. Melarang titik memang
        //      menyelamatkan `smtp://mail.example:abc gagal budi@lessworry.id`
        //      dari tertelan — tapi `host:teks` dan `pengguna:password` TIDAK
        //      BISA dibedakan dari teksnya, dan titik bukan pembeda: nama
        //      pengguna SMTP justru lazim bertitik karena ia biasanya alamat
        //      surel. Harga pagar titik adalah `smtp://budi.santoso:pa ss@host`
        //      lolos utuh — nama pengguna dan password sekaligus. Menukar
        //      kebocoran kredensial yang lazim dengan keterbacaan host pada
        //      bentuk yang jarang adalah pertukaran ke arah yang salah.
        //      Yang dipilih di sini: kalau ambigu, SENSOR. (API-124)
        //   2. `(?!\d{1,5}(?:[^\w.\-]|$))` — angka lalu apa pun yang BUKAN
        //      karakter host adalah NOMOR PORT, bukan password. Kelas ini
        //      menggantikan daftar `[\s/,)"]`; daftar itu diam-diam
        //      mensyaratkan tanda baca tertentu, sehingga `mail.example:587!`
        //      tidak dikenali sebagai port dan seluruh kalimat tertelan.
        //   3. paling tiga spasi. Ini batas JANGKAUAN, bukan pagar yang
        //      menjaga nama host dan alamat penerima — di dalam tiga spasi
        //      keduanya tetap tertelan, dan sampai API-126 memang tertelan.
        //      Yang menjaganya pagar 5. Password lebih dari tiga spasi jadi
        //      tidak tersensor — disebut di docblock.
        //   4. `\r\n` di luar kelas — penyensoran tidak melompati baris.
        //   5. semua bintang LAZY, dan `@` ikut jadi pembatas sesudah host.
        //      Dua perubahan, satu pasang: melepas salah satunya mengembalikan
        //      salah satu dari dua kesalahan. (API-126)
        //
        //      Sebelum API-126 bintang di dalam `(?:[ ]...)` greedy. `{0,3}?`
        //      lazy pada JUMLAH pengulangan, tapi PCRE menambah pengulangan
        //      dulu sebelum memundurkan bintang di pengulangan sebelumnya —
        //      jadi yang terpilih `@` TERJAUH yang terjangkau, bukan yang
        //      pertama, dan `smtp://user:pa ss@smtp.lessworry.id hubungi
        //      budi@lessworry.id` kehilangan nama host DAN alamat penerima
        //      sekaligus. Pagar 3 tidak menahannya: ia hanya membatasi
        //      jangkauan, dan satu kata masih di dalam jangkauan.
        //
        //      Lazy sendiri tidak cukup. Ia memilih `@` PERTAMA yang lolos
        //      lookahead, dan pada `pa ss@w0rd@host` `@` pertama itu ada di
        //      TENGAH password: `w0rd` tertinggal di log. Diukur di korpus
        //      15.552 bentuk, lazy tanpa `@` di kelas pembatas membocorkan
        //      1.728 bentuk yang pola greedy sensor — regresi keamanan, bukan
        //      perbaikan. Di jalur log sungguhan `amanUntukLog()` kebocoran itu
        //      tertutup kebetulan, karena `tanpaAlamatEmail()` ikut membuang
        //      `potongan@host` — dan NAMA HOST-nya ikut terbuang bersamanya,
        //      jadi bentuk itu tetap lebih buruk di kedua arah. Fungsi ini juga
        //      publik dan bisa dipanggil tanpa lintasan alamat. `@` di
        //      `[^\w.\-@]` menolak kandidat yang "host"-nya
        //      langsung diikuti `@` — host yang diikuti `@` bukan host — jadi
        //      pola memundur ke `@` berikutnya. Dengan keduanya: penelanan
        //      nol, kebocoran sama dengan greedy.
        $pesan = (string) preg_replace(
            '#(?<=://)[^\s/\r\n]+?:(?!\d{1,5}(?:[^\w.\-]|$))[^\s\r\n]*?(?:[ ][^\s\r\n]*?){0,3}?@(?=[\w.\-]+(?::\d+)?(?:[^\w.\-@]|$))#',
            '[kredensial-disensor]@',
            $pesan
        );

        return $pesan;
    }

    /**
     * Dua sumber, dua penghitung — sengaja.
     *
     * Batas 3 per 10 menit di issue adalah batas untuk PERMINTAAN KIRIM ULANG.
     * Kalau surat otomatis saat login ikut memakan jatah yang sama, orang yang
     * baru login hanya kebagian dua kali tekan, dan tombol di halaman verifikasi
     * berhenti bekerja tanpa alasan yang terlihat. Sebaliknya, membiarkan jalur
     * login tanpa batas berarti login berulang jadi alat mengirimi orang surat
     * bertubi-tubi. Jadi keduanya dibatasi, masing-masing dengan penghitungnya.
     *
     * @param  string  $sumber  'login' atau 'permintaan'
     */
    public function kirim(User $user, string $sumber = 'permintaan'): string
    {
        $key = 'verifikasi-email:'.$sumber.':'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::BATAS)) {
            return self::DIBATASI;
        }

        // Dihitung SEBELUM dikirim: kalau SMTP mati, percobaannya tetap
        // dihitung. Tanpa itu, SMTP yang mati jadi celah kirim tanpa batas.
        RateLimiter::hit($key, self::JENDELA_DETIK);

        try {
            Mail::to($user->email)->send(
                new VerifikasiEmail($user, $this->tautan($user), self::UMUR_MENIT)
            );
        } catch (Throwable $e) {
            // Pesan galat SMTP bisa memuat nama host, kredensial, DAN alamat
            // email penerima. Ia tidak pernah boleh sampai ke layar orang —
            // dan, sejak API-37 nomor 1, juga tidak boleh masuk log apa
            // adanya. Aturan repositori ini tidak membuat pengecualian untuk
            // log server, dan data pribadi anggota tim tidak lebih boleh
            // masuk log daripada kredensial. (API-121)
            Log::error('Gagal mengirim email verifikasi.', [
                'user_id' => $user->id,
                'sumber' => $sumber,
                // Jenis dan kode galat yang menelusuri, bukan kalimatnya:
                // keduanya cukup membedakan "SMTP tidak bisa dihubungi" dari
                // "alamat ditolak", dan tidak satu pun bisa memuat rahasia.
                'jenis' => get_class($e),
                'kode' => $e->getCode(),
                'error' => self::amanUntukLog($e->getMessage()),
            ]);

            $this->catatHasil(gagal: true);

            return self::GAGAL;
        }

        // Satu pengiriman yang berhasil membuktikan mailernya hidup — itu yang
        // membersihkan penandanya, bukan lewatnya waktu.
        $this->catatHasil(gagal: false);

        return self::TERKIRIM;
    }

    /**
     * Catat hasil pengiriman terakhir untuk dibaca /health.
     *
     * Kegagalan menulis penanda tidak boleh menggagalkan pengiriman: cache
     * yang tidak bisa ditulis adalah kerusakan tersendiri, dan /health sudah
     * punya barisnya sendiri untuk itu. Yang tidak boleh terjadi adalah surat
     * yang sudah terkirim dilaporkan gagal hanya karena catatannya tidak bisa
     * ditulis.
     */
    private function catatHasil(bool $gagal): void
    {
        try {
            $cache = Cache::store(config('health.cache_store'));

            $gagal
                ? $cache->put(self::CACHE_GAGAL, true, self::CACHE_GAGAL_DETIK)
                : $cache->forget(self::CACHE_GAGAL);
        } catch (Throwable) {
            // Sengaja diam.
        }
    }

    /**
     * Tautan bertanda tangan, terikat pada id pengguna DAN alamat emailnya.
     *
     * Ikatan ke alamat itulah yang membuat tautan lama mati begitu admin
     * mengganti email seseorang — kalau tidak, tautan yang sudah terlanjur
     * beredar tetap bisa memverifikasi alamat yang sudah bukan miliknya.
     */
    public function tautan(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(self::UMUR_MENIT), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);
    }
}
