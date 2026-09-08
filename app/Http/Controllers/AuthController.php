<?php

namespace App\Http\Controllers;

use App\Services\PengirimVerifikasiEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request, PengirimVerifikasiEmail $pengirim)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Email atau password salah.',
            ]);
        }

        // Akun nonaktif langsung kehilangan akses (API-14).
        if (! Auth::user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();

            throw ValidationException::withMessages([
                'email' => 'Akun ini sudah dinonaktifkan. Hubungi admin sistem.',
            ]);
        }

        $request->session()->regenerate();

        // Login pertama yang emailnya belum terverifikasi langsung dikirimi
        // tautannya — tanpa itu orang mendarat di halaman verifikasi tanpa
        // surat apa pun di kotak masuknya. (API-35)
        //
        // Kegagalan kirim TIDAK boleh menggagalkan login: yang gagal adalah
        // suratnya, dan halaman verifikasilah yang mengatakannya apa adanya.
        $user = Auth::user();

        if (! $user->hasVerifiedEmail()) {
            $hasil = $pengirim->kirim($user, 'login');

            $tujuan = redirect()->intended(route('verification.notice'));

            if ($hasil === PengirimVerifikasiEmail::GAGAL) {
                return $tujuan->with('kirim_gagal', true);
            }

            return $tujuan;
        }

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Titipan untuk halaman masuk: buang draft complaint yang tertinggal
        // di perangkat outlet. Petugas berikutnya tidak boleh menemukan
        // keluhan dan identitas pelanggan yang dicatat petugas sebelumnya.
        $request->session()->flash('bersihkan_semua_draft', true);

        return redirect()->route('login');
    }
}
