<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PengirimVerifikasiEmail;
use Illuminate\Http\Request;

/**
 * Halaman verifikasi dan pembukaan tautannya. (API-35 bagian 2 dan 3)
 */
class EmailVerificationController extends Controller
{
    public function __construct(private PengirimVerifikasiEmail $pengirim) {}

    public function notice(Request $request)
    {
        $user = $request->user();

        // Yang sudah terverifikasi tidak pernah melihat halaman ini lagi.
        if ($user->hasVerifiedEmail()) {
            return redirect()->to($this->tujuan($user));
        }

        return view('auth.verifikasi', [
            'user' => $user,
            // Yang menentukan kalimat di kartu adalah HASIL pengiriman, bukan
            // nilai mail.default. Sebelumnya viewnya bercabang pada bentuk
            // mailernya saja, jadi SMTP yang terpasang tapi gagal terkirim
            // tetap mencetak "Tautan verifikasi dikirim ke …" — di layar yang
            // sama dengan spanduk "Surat gagal dikirim". Orang membaca kalimat
            // yang menyebut alamatnya sendiri, lalu menunggu surat yang tidak
            // akan datang. Itu jalur yang terjadi di produksi; log/array hanya
            // ada di mesin pengembang. (Tinjauan PR #17)
            //
            // Datanya sudah ada di tangan: AuthController menitipkannya saat
            // login, resend() saat kirim ulang, dan EnsureEmailVerified
            // me-reflash supaya selamat melewati pantulan.
            'gagalKirim' => (bool) session('kirim_gagal'),
        ]);
    }

    public function resend(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->to($this->tujuan($user));
        }

        return match ($this->pengirim->kirim($user, 'permintaan')) {
            // Mail::send tidak melempar apa pun saat mailernya `log`/`array` —
            // suratnya diterima lalu berhenti di situ. Mengatakan "dikirim
            // ulang" di keadaan itu mengulang kebohongan yang sama seperti
            // halamannya. (API-47)
            PengirimVerifikasiEmail::TERKIRIM => PengirimVerifikasiEmail::hanyaMencatat()
                ? back()->with(
                    'status',
                    'Tautan baru dicatat ke storage/logs/laravel.log — tidak ada surat '
                        .'yang dikirim ke mana pun. Berlaku '
                        .PengirimVerifikasiEmail::UMUR_MENIT.' menit.'
                )
                : back()->with(
                    'status',
                    'Tautan verifikasi dikirim ulang ke '.$user->emailTersamar().'. Berlaku '
                        .PengirimVerifikasiEmail::UMUR_MENIT.' menit.'
                ),
            PengirimVerifikasiEmail::DIBATASI => back()->withErrors([
                'kirim' => 'Tautan sudah dikirim '.PengirimVerifikasiEmail::BATAS
                    .' kali dalam 10 menit terakhir. Tunggu sebentar lalu coba lagi, '
                    .'atau hubungi Admin kalau suratnya tidak pernah sampai.',
            ]),
            // Spanduk galat saja tidak cukup: tanpa penanda ini badan kartunya
            // tetap bilang tautannya sudah dikirim, dan halaman yang sama
            // membantah dirinya sendiri.
            default => back()->with('kirim_gagal', true)->withErrors([
                'kirim' => 'Surat gagal dikirim. Ini masalah di sisi sistem, bukan di akunmu — hubungi Admin.',
            ]),
        };
    }

    /**
     * Tautan bertanda tangan sudah diperiksa middleware `signed`: yang
     * kedaluwarsa atau diubah isinya tidak sampai ke sini (lihat penanganan
     * InvalidSignatureException di bootstrap/app.php).
     *
     * Yang diperiksa di sini adalah ikatannya ke akun yang sedang masuk.
     */
    public function verify(Request $request, string $id, string $hash)
    {
        $user = $request->user();

        if (! hash_equals((string) $user->id, $id) || ! hash_equals(sha1($user->email), $hash)) {
            return redirect()->route('verification.notice')->withErrors([
                'kirim' => 'Tautan verifikasi itu tidak berlaku untuk akun ini — '
                    .'mungkin alamat emailmu sudah diganti admin. Minta tautan baru di bawah.',
            ]);
        }

        // Sekali pakai: tautan yang sama dibuka kedua kalinya tidak
        // memverifikasi apa pun lagi, dan bilang begitu apa adanya.
        if ($user->hasVerifiedEmail()) {
            return redirect()->to($this->tujuan($user))->with(
                'warning',
                'Tautan verifikasi itu sudah dipakai. Akunmu sudah terverifikasi — tidak ada yang perlu diulang.'
            );
        }

        $user->markEmailAsVerified();

        return redirect()->to($this->tujuan($user))->with(
            'status',
            'Email terverifikasi. Sekarang pasang password barumu.'
        );
    }

    /**
     * Setelah verifikasi, gerbang ganti password mengambil alih — kecuali
     * passwordnya memang sudah pernah diganti.
     */
    private function tujuan(User $user): string
    {
        return $user->must_change_password ? route('password.edit') : route('dashboard');
    }
}
