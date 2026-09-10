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
            // Pesan galat SMTP bisa memuat nama host dan kredensial. Ia boleh
            // masuk log server; ia tidak pernah boleh sampai ke layar orang.
            Log::error('Gagal mengirim email verifikasi.', [
                'user_id' => $user->id,
                'sumber' => $sumber,
                'error' => $e->getMessage(),
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
