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

        return view('auth.verifikasi', ['user' => $user]);
    }

    public function resend(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->to($this->tujuan($user));
        }

        return match ($this->pengirim->kirim($user, 'permintaan')) {
            PengirimVerifikasiEmail::TERKIRIM => back()->with(
                'status',
                'Tautan verifikasi dikirim ulang ke '.$user->emailTersamar().'. Berlaku '
                    .PengirimVerifikasiEmail::UMUR_MENIT.' menit.'
            ),
            PengirimVerifikasiEmail::DIBATASI => back()->withErrors([
                'kirim' => 'Tautan sudah dikirim '.PengirimVerifikasiEmail::BATAS
                    .' kali dalam 10 menit terakhir. Tunggu sebentar lalu coba lagi, '
                    .'atau hubungi Admin kalau suratnya tidak pernah sampai.',
            ]),
            default => back()->withErrors([
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
