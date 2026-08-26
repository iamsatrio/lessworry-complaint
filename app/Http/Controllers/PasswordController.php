<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    public function edit()
    {
        return view('password.edit');
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [], [
            'current_password' => 'password sekarang',
            'password'         => 'password baru',
        ]);

        if (! Auth::validate(['email' => $user->email, 'password' => $data['current_password']])) {
            throw ValidationException::withMessages([
                'current_password' => 'Password sekarang tidak cocok.',
            ]);
        }

        if (Auth::validate(['email' => $user->email, 'password' => $data['password']])) {
            throw ValidationException::withMessages([
                'password' => 'Password baru harus berbeda dari yang sekarang.',
            ]);
        }

        $user->forceFill([
            'password'             => $data['password'],
            'must_change_password' => false,
        ])->save();

        // Sesi lain milik pengguna ini ikut diputus setelah password berubah.
        Auth::logoutOtherDevices($data['password']);

        return redirect()->route('dashboard')->with('status', 'Password berhasil diganti.');
    }
}
