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
        //
    })->create();
