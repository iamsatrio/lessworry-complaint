<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Berlaku untuk semua permintaan web.
        $middleware->web(append: [
            \App\Http\Middleware\NoStoreForAuthenticated::class,
        ]);

        $middleware->alias([
            // Tanpa ini Auth::logoutOtherDevices() tidak melakukan apa-apa:
            // hash password di sesi tidak pernah dibandingkan dengan yang
            // tersimpan, jadi sesi di perangkat lain hidup terus setelah
            // password diganti atau direset. (API-14 #2)
            'auth.session'     => \Illuminate\Session\Middleware\AuthenticateSession::class,
            'active'           => \App\Http\Middleware\EnsureUserIsActive::class,
            'password.changed' => \App\Http\Middleware\EnsurePasswordChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
        | Token CSRF basi tidak boleh berujung halaman "Page Expired" kosong.
        | Yang terjadi di lapangan: petugas menekan Simpan, semua isiannya
        | hilang, dan tidak ada keterangan apa pun tentang apa yang salah.
        |
        | Laravel sudah mengubah TokenMismatchException jadi HTTP 419 sebelum
        | render callback dipanggil, jadi ditangani di lapisan responsnya.
        */
        $exceptions->respond(function (
            \Symfony\Component\HttpFoundation\Response $response,
            \Throwable $e,
            \Illuminate\Http\Request $request
        ) {
            if ($response->getStatusCode() !== 419) {
                return $response;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.',
                ], 419);
            }

            return redirect()->back()
                ->withInput($request->except(['_token', 'password', 'password_confirmation']))
                ->withErrors([
                    'session' => 'Halaman ini sudah kedaluwarsa — biasanya karena dibuka terlalu lama '
                        .'atau kamu masuk ulang di tab lain. Isianmu masih ada di bawah; tekan Simpan sekali lagi.',
                ]);
        });
        //
    })->create();
