<?php

namespace App\Http\Controllers;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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

        $isActive = $request->boolean('is_active');

        // Supervisor terakhir yang aktif tidak boleh mematikan dirinya sendiri —
        // itu mengunci semua orang keluar dari pengelolaan pengguna.
        if (! $isActive && $user->id === $request->user()->id) {
            return back()->withErrors(['is_active' => 'Kamu tidak bisa menonaktifkan akunmu sendiri.']);
        }

        if (! $isActive && $user->isSupervisor()) {
            $activeSupervisors = User::where('role', 'supervisor')->where('is_active', true)->count();

            if ($activeSupervisors <= 1) {
                return back()->withErrors(['is_active' => 'Ini satu-satunya supervisor aktif. Angkat supervisor lain sebelum menonaktifkan yang ini.']);
            }
        }

        $user->fill($data);
        $user->is_active = $isActive;
        $user->save();

        return redirect()->route('users.index')->with('status', 'Data '.$user->name.' diperbarui.');
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
