<?php

use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\NoStoreForAuthenticated;
use App\Http\Responses\SesiKedaluwarsa;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // /health dipasang di sini, bukan di routes/web.php: rutenya tidak
        // boleh memakai middleware web. Alasannya ditulis di routes/health.php.
        then: function () {
            Route::group([], base_path('routes/health.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Berlaku untuk semua permintaan web.
        $middleware->web(append: [
            NoStoreForAuthenticated::class,
        ]);

        $middleware->alias([
            // Tanpa ini Auth::logoutOtherDevices() tidak melakukan apa-apa:
            // hash password di sesi tidak pernah dibandingkan dengan yang
            // tersimpan, jadi sesi di perangkat lain hidup terus setelah
            // password diganti atau direset. (API-14 #2)
            'auth.session' => AuthenticateSession::class,
            'active' => EnsureUserIsActive::class,
            'email.verified' => EnsureEmailVerified::class,
            'password.changed' => EnsurePasswordChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
        | Tautan verifikasi yang kedaluwarsa atau diubah isinya tidak boleh
        | berujung halaman 403 tanpa keterangan. Yang membukanya adalah orang
        | yang baru menerima akun dan belum bisa masuk ke mana-mana; halaman
        | "Forbidden" tidak memberitahunya apa yang harus dilakukan.
        |
        | Dibatasi pada rute verifikasi saja — rute bertanda tangan lain, kalau
        | nanti ada, tetap ditolak dengan cara bawaan.
        */
        $exceptions->render(function (InvalidSignatureException $e, Request $request) {
            if (! $request->routeIs('verification.verify')) {
                return null;
            }

            return redirect()->route('verification.notice')->withErrors([
                'kirim' => 'Tautan verifikasi itu sudah kedaluwarsa atau tidak berlaku lagi. '
                    .'Minta tautan baru lewat tombol di bawah.',
            ]);
        });

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
            Response $response,
            Throwable $e,
            Request $request
        ) {
            if ($response->getStatusCode() !== 419) {
                return $response;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.',
                ], 419);
            }

            return app(SesiKedaluwarsa::class)->render($request);
        });
        //
    })->create();
