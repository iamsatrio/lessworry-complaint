<?php

namespace App\Services;

use App\Mail\VerifikasiEmail;
use App\Models\User;
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

            return self::GAGAL;
        }

        return self::TERKIRIM;
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
