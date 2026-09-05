<?php

namespace App\Http\Controllers;

use App\Models\Outlet;
use App\Models\User;
use App\Models\UserAudit;
use App\Services\JejakPengguna;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Pengelolaan pengguna oleh admin. (API-14)
 *
 * Tanpa halaman ini tim tidak bisa dionboarding sama sekali — pengguna
 * hanya bisa lahir dari seeder, yang berarti sistem tidak bisa dipakai
 * di luar mesin pengembang.
 *
 * Akun tidak pernah dihapus, hanya dinonaktifkan: complaint menyimpan
 * siapa yang mencatat dan siapa yang menutupnya, dan jejak itu harus utuh.
 */
class UserController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->canManageUsers(), 403);
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);

        return view('users.index', [
            'users' => User::with('outlet')->orderBy('is_active', 'desc')->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeAdmin($request);

        return view('users.create', ['outlets' => Outlet::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'role' => ['required', Rule::in(['kasir', 'customer_care', 'divisi', 'supervisor', 'admin'])],
            'outlet_id' => ['nullable', 'exists:outlets,id'],
            'division' => ['nullable', Rule::in(array_keys(config('complaint.divisions')))],
        ]);

        // Password sementara dibuat sistem, bukan diketik admin —
        // supaya tidak jatuh ke pola yang mudah ditebak seluruh outlet.
        $temporary = Str::password(12, symbols: false);

        $user = new User($data);
        $user->password = $temporary;
        $user->must_change_password = true;
        $user->is_active = true;
        $user->save();

        // Password sementara hanya ditampilkan sekali, lewat flash session.
        // Tidak disimpan, tidak dicatat di log.
        return redirect()->route('users.index')->with('temporary_password', [
            'name' => $user->name,
            'email' => $user->email,
            'password' => $temporary,
        ]);
    }

    public function edit(Request $request, User $user)
    {
        $this->authorizeAdmin($request);

        return view('users.edit', [
            'user' => $user,
            'outlets' => Outlet::orderBy('name')->get(),
            'jejak' => UserAudit::with('actor')->where('user_id', $user->id)
                ->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function update(Request $request, User $user, JejakPengguna $jejak)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // Alamat email bisa diubah admin. Tanpa ini, satu salah ketik saat
            // membuat akun berarti akun yang tidak bisa dipakai selamanya —
            // verifikasi email menjadikan kotak surat syarat mutlak. (API-35 4b)
            //
            // `sometimes`, mengikuti aturan yang sudah dipakai is_active di
            // bawah: kolom yang tidak dikirim berarti "jangan diubah". Alamat
            // email adalah satu-satunya jalan masuk sebuah akun — permintaan
            // yang kebetulan tidak memuatnya tidak boleh menyentuhnya.
            'email' => ['sometimes', 'required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(['kasir', 'customer_care', 'divisi', 'supervisor', 'admin'])],
            'outlet_id' => ['nullable', 'exists:outlets,id'],
            'division' => ['nullable', Rule::in(array_keys(config('complaint.divisions')))],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $emailLama = $user->email;
        $emailBerubah = isset($data['email'])
            && mb_strtolower(trim($data['email'])) !== mb_strtolower($emailLama);

        // Kolom yang tidak dikirim berarti "jangan diubah", bukan "matikan".
        // $request->boolean() memperlakukan kolom absen sebagai false, jadi
        // setiap PUT tanpa field ini mematikan akun diam-diam. (API-14 #9)
        $isActive = $request->has('is_active')
            ? $request->boolean('is_active')
            : (bool) $user->is_active;

        // Supervisor terakhir yang aktif tidak boleh mematikan dirinya sendiri —
        // itu mengunci semua orang keluar dari pengelolaan pengguna.
        if (! $isActive && $user->id === $request->user()->id) {
            return back()->withErrors(['is_active' => 'Kamu tidak bisa menonaktifkan akunmu sendiri.']);
        }

        DB::transaction(function () use ($user, $data, $isActive, $emailBerubah, $emailLama, $jejak, $request) {
            $this->pastikanMasihAdaAdmin($user, $data['role'], $isActive);

            $user->fill($data);
            $user->is_active = $isActive;

            // Verifikasi menyatakan "kotak surat ini terbukti dipegang
            // pemiliknya". Begitu alamatnya diganti, pernyataan itu tidak
            // berlaku lagi untuk alamat yang baru — jadi statusnya direset,
            // dan tautan lama ikut mati karena terikat ke alamat lama.
            if ($emailBerubah) {
                $user->email_verified_at = null;
            }

            $user->save();

            if ($emailBerubah) {
                $jejak->emailDiubah($user, $request->user(), $emailLama);
            }
        });

        $pesan = 'Data '.$user->name.' diperbarui.';

        if ($emailBerubah) {
            $pesan .= ' Alamat emailnya berubah, jadi verifikasinya direset — '
                .$user->name.' harus memverifikasi alamat barunya saat masuk lagi.';
        }

        return redirect()->route('users.index')->with('status', $pesan);
    }

    /**
     * Jaga JUMLAH ADMIN AKTIF, bukan cuma kolom is_active. (API-14 #1)
     *
     * Pengaman lama hanya menyala saat is_active dimatikan, sehingga
     * admin aktif terakhir bisa melucuti dirinya sendiri lewat dropdown
     * peran di form Ubah Pengguna — form yang dipakai tiap minggu. Setelah
     * itu /users membalas 403 untuk semua orang dan tidak ada jalan kembali.
     *
     * Yang menghitung sekarang adalah akibatnya: apa pun caranya, perubahan
     * yang membuat nol admin aktif ditolak. Dibaca di dalam transaksi
     * dengan penguncian baris supaya dua permintaan bersamaan tidak sama-sama
     * membaca "masih ada 2". (API-14 #8)
     *
     * Barisnya dikunci dalam urutan id yang tetap, dan TANPA mengecualikan
     * baris yang sedang diubah. Mengecualikan diri sendiri membuat dua
     * permintaan mengunci baris lawannya masing-masing lalu saling menunggu;
     * mengunci himpunan yang sama dalam urutan yang sama membuat yang kedua
     * antre, lalu membaca keadaan yang sudah diperbarui.
     *
     * Kalau basis data terlanjur sampai ke keadaan nol admin — lewat
     * seeder atau perbaikan manual — jalan pulihnya:
     *
     *   php artisan lessworry:pulihkan-admin <email>
     */
    private function pastikanMasihAdaAdmin(User $user, string $peranBaru, bool $isActive): void
    {
        $tadinyaAdminAktif = $user->role === 'admin' && $user->is_active;
        $tetapAdminAktif = $peranBaru === 'admin' && $isActive;

        if (! $tadinyaAdminAktif || $tetapAdminAktif) {
            return;
        }

        $adminAktif = User::where('role', 'admin')
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        // $user masih tercatat admin aktif saat ini — itu yang sedang dicabut.
        $tersisa = $adminAktif->reject(fn ($id) => $id === $user->id)->count();

        if ($tersisa > 0) {
            return;
        }

        $kolom = $peranBaru === 'admin' ? 'is_active' : 'role';

        throw ValidationException::withMessages([
            $kolom => 'Ini satu-satunya admin aktif. Angkat admin lain dulu — '
                .'tanpa admin, tidak ada yang bisa membuka pengelolaan pengguna lagi.',
        ]);
    }

    /**
     * Menandai satu akun terverifikasi tanpa lewat email. (API-35 bagian 4a)
     *
     * Dibutuhkan dua keadaan yang keduanya nyata: akun bersama yang tidak
     * punya kotak surat sendiri (Kasir, Produksi, Kurir), dan alamat yang
     * ternyata tidak ada. Tanpa jalan ini, verifikasi email berubah dari
     * pengaman menjadi kunci yang mengurung semua orang di luar.
     *
     * Ini MELEMAHKAN pengaman, jadi alasannya wajib dan jejaknya dicatat:
     * siapa menandai siapa, kapan, dan kenapa.
     */
    public function verifyEmail(Request $request, User $user, JejakPengguna $jejak)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.required' => 'Tulis alasannya. Penandaan tanpa alasan tidak bisa ditelusuri belakangan.',
        ], [
            'reason' => 'alasan',
        ]);

        if ($user->hasVerifiedEmail()) {
            return back()->with('warning', 'Akun '.$user->name.' sudah terverifikasi.');
        }

        $user->markEmailAsVerified();
        $jejak->emailDiverifikasiManual($user, $request->user(), $data['reason']);

        return redirect()->route('users.edit', $user)->with(
            'status',
            $user->name.' ditandai terverifikasi. Alasannya tercatat di jejak audit akun ini.'
        );
    }

    /** Setel ulang password jadi sementara — untuk pegawai yang lupa. */
    public function resetPassword(Request $request, User $user)
    {
        $this->authorizeAdmin($request);

        $temporary = Str::password(12, symbols: false);

        $user->forceFill([
            'password' => $temporary,
            'must_change_password' => true,
        ])->save();

        return redirect()->route('users.index')->with('temporary_password', [
            'name' => $user->name,
            'email' => $user->email,
            'password' => $temporary,
        ]);
    }
}
