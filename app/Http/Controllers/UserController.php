<?php

namespace App\Http\Controllers;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Pengelolaan pengguna oleh supervisor. (API-14)
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
    private function authorizeSupervisor(Request $request): void
    {
        abort_unless($request->user()->canManageUsers(), 403);
    }

    public function index(Request $request)
    {
        $this->authorizeSupervisor($request);

        return view('users.index', [
            'users' => User::with('outlet')->orderBy('is_active', 'desc')->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeSupervisor($request);

        return view('users.create', ['outlets' => Outlet::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $this->authorizeSupervisor($request);

        $data = $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'email'     => ['required', 'email', 'max:190', 'unique:users,email'],
            'role'      => ['required', Rule::in(['kasir', 'customer_care', 'divisi', 'supervisor'])],
            'outlet_id' => ['nullable', 'exists:outlets,id'],
            'division'  => ['nullable', Rule::in(array_keys(config('complaint.divisions')))],
        ]);

        // Password sementara dibuat sistem, bukan diketik supervisor —
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
            'name'     => $user->name,
            'email'    => $user->email,
            'password' => $temporary,
        ]);
    }

    public function edit(Request $request, User $user)
    {
        $this->authorizeSupervisor($request);

        return view('users.edit', [
            'user'    => $user,
            'outlets' => Outlet::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeSupervisor($request);

        $data = $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'role'      => ['required', Rule::in(['kasir', 'customer_care', 'divisi', 'supervisor'])],
            'outlet_id' => ['nullable', 'exists:outlets,id'],
            'division'  => ['nullable', Rule::in(array_keys(config('complaint.divisions')))],
            'is_active' => ['nullable', 'boolean'],
        ]);

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

        DB::transaction(function () use ($user, $data, $isActive) {
            $this->pastikanMasihAdaSupervisor($user, $data['role'], $isActive);

            $user->fill($data);
            $user->is_active = $isActive;
            $user->save();
        });

        return redirect()->route('users.index')->with('status', 'Data '.$user->name.' diperbarui.');
    }

    /**
     * Jaga JUMLAH SUPERVISOR AKTIF, bukan cuma kolom is_active. (API-14 #1)
     *
     * Pengaman lama hanya menyala saat is_active dimatikan, sehingga
     * supervisor aktif terakhir bisa melucuti dirinya sendiri lewat dropdown
     * peran di form Ubah Pengguna — form yang dipakai tiap minggu. Setelah
     * itu /users membalas 403 untuk semua orang dan tidak ada jalan kembali.
     *
     * Yang menghitung sekarang adalah akibatnya: apa pun caranya, perubahan
     * yang membuat nol supervisor aktif ditolak. Dibaca di dalam transaksi
     * dengan penguncian baris supaya dua permintaan bersamaan tidak sama-sama
     * membaca "masih ada 2". (API-14 #8)
     *
     * Kalau basis data terlanjur sampai ke keadaan nol supervisor — lewat
     * seeder atau perbaikan manual — jalan pulihnya:
     *
     *   php artisan lessworry:pulihkan-supervisor <email>
     */
    private function pastikanMasihAdaSupervisor(User $user, string $peranBaru, bool $isActive): void
    {
        $tadinyaSupervisorAktif = $user->role === 'supervisor' && $user->is_active;
        $tetapSupervisorAktif   = $peranBaru === 'supervisor' && $isActive;

        if (! $tadinyaSupervisorAktif || $tetapSupervisorAktif) {
            return;
        }

        $tersisa = User::where('role', 'supervisor')
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->lockForUpdate()
            ->count();

        if ($tersisa > 0) {
            return;
        }

        $kolom = $peranBaru === 'supervisor' ? 'is_active' : 'role';

        throw ValidationException::withMessages([
            $kolom => 'Ini satu-satunya supervisor aktif. Angkat supervisor lain dulu — '
                .'tanpa supervisor, tidak ada yang bisa membuka pengelolaan pengguna lagi.',
        ]);
    }

    /** Setel ulang password jadi sementara — untuk pegawai yang lupa. */
    public function resetPassword(Request $request, User $user)
    {
        $this->authorizeSupervisor($request);

        $temporary = Str::password(12, symbols: false);

        $user->forceFill([
            'password'             => $temporary,
            'must_change_password' => true,
        ])->save();

        return redirect()->route('users.index')->with('temporary_password', [
            'name'     => $user->name,
            'email'    => $user->email,
            'password' => $temporary,
        ]);
    }
}
