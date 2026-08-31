<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // /health dipasang di sini, bukan di routes/web.php: rutenya tidak
        // boleh memakai middleware web. Alasannya ditulis di routes/health.php.
        then: function () {
            \Illuminate\Support\Facades\Route::group([], base_path('routes/health.php'));
        },
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
        | Token CSRF basi tidak boleh berujung halaman "Page Expired" kosong,
        | dan tidak boleh berujung halaman yang terlihat seperti simpan yang
        | berhasil. Yang terjadi di lapangan: petugas menekan Simpan, kembali
        | ke form dengan isian utuh, tanpa keterangan apa pun — dan pergi
        | mengira complaintnya sudah masuk.
        |
        | Jawabannya dirender langsung, bukan dialihkan: pesan yang dititipkan
        | ke flash session ikut mati bersama sesinya. Lihat SesiKedaluwarsa.
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

            return app(\App\Http\Responses\SesiKedaluwarsa::class)->render($request);
        });
        //
    })->create();
