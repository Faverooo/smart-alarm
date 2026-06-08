<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST |
            Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
        // Web (panel + gated photo stream): Filament's login is named
        // `filament.admin.auth.login` (not `login`), so point the `auth`
        // middleware's guest redirect at it.
        $middleware->redirectGuestsTo(fn() => route('filament.admin.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // API routes (incl. the binary photo render) must always answer with a
        // clean 401 JSON when the device token is missing/expired — never a 302
        // redirect to the HTML login — so the ESP knows to re-pair.
        $exceptions->shouldRenderJsonWhen(
            fn($request, $e) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
