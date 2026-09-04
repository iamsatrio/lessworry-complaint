<?php

namespace App\Http\Responses;

use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ViewErrorBag;

/**
 * Simpan yang gagal harus terlihat gagal.
 *
 * Penanganan sebelumnya menitipkan pesannya ke flash session lalu mengalihkan
 * halaman. Yang rusak justru sesinya: pengalihan itu melewati middleware auth
 * yang mengalihkan sekali lagi ke /login, dan flash — yang hanya bertahan satu
 * permintaan — habis di jalan. Petugas melihat form dengan isian utuh (dipulihkan
 * draft perangkat) tanpa keterangan apa pun, lalu pergi melayani pelanggan
 * berikutnya sambil yakin complaint sudah masuk. Bukan complaint yang gagal
 * dicatat, tapi complaint yang DIKIRA sudah dicatat — dan selama itu terjadi,
 * tidak ada angka di sistem ini yang bisa dipercaya.
 *
 * Jawabannya sekarang dirender langsung dengan status 419: tidak ada pengalihan,
 * jadi tidak ada yang bisa menghabiskan pesannya di jalan.
 */
class SesiKedaluwarsa
{
    /** Tidak pernah dititipkan ke sesi — selalu dicetak di halaman yang dibalas. */
    public const PESAN = 'Halaman ini sudah kedaluwarsa — biasanya karena dibuka terlalu lama '
        .'atau kamu masuk ulang di tab lain.';

    public function render(Request $request)
    {
        $isian = $request->except(['_token', 'password', 'password_confirmation']);

        // Sesinya masih hidup (mis. token basi karena masuk ulang di tab lain):
        // formnya bisa dirender ulang lengkap dengan isiannya.
        if ($this->simpanComplaint($request) && Auth::user()?->canCreateComplaint()) {
            return response()->view('complaints.create', [
                'outlets' => Outlet::where('is_active', true)->orderBy('name')->get(),
                'kembali' => $isian,
                'gagal' => self::PESAN.' Isianmu masih ada di bawah — periksa sekali lagi, lalu tekan Coba Simpan Lagi.',
                // Kotak galat umum layout ("Periksa lagi sebelum lanjut") keliru
                // di sini: yang gagal bukan isiannya.
                'errors' => new ViewErrorBag,
            ], 419)->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        }

        // Sesinya benar-benar mati: petugasnya tidak dikenali lagi, jadi form
        // yang butuh identitasnya tidak bisa dirender. Yang tidak boleh hilang
        // adalah kepastian bahwa complaint itu TIDAK masuk.
        if ($this->simpanComplaint($request)) {
            // Setelah masuk lagi, mendarat langsung di form — bukan di dashboard.
            $request->session()->put('url.intended', route('complaints.create'));
        }

        // Isian pelanggan sengaja tidak dicetak di sini: halamannya dibalas ke
        // pengunjung yang tidak dikenali, di perangkat outlet yang dipakai
        // bergantian. Isian itu tetap aman di draft perangkat yang terkunci
        // per pengguna, dan ditawarkan kembali setelah petugasnya masuk lagi.
        return response()->view('errors.sesi-kedaluwarsa', [
            'pesan' => self::PESAN,
            'kembali' => $this->simpanComplaint($request),
            'errors' => new ViewErrorBag,
        ], 419)->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }

    private function simpanComplaint(Request $request): bool
    {
        return $request->route() !== null && $request->routeIs('complaints.store');
    }
}
