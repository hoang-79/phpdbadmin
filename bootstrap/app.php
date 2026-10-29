<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance']);

        $middleware->web(append: [
            EnsureAccountIsActive::class,
            SetLocale::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Gedrosselt (benannte Limiter der Anmeldung): Meldung am Feld statt Fehlerseite.
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            $seconds = (int) ($exception->getHeaders()['Retry-After'] ?? 60);
            $field = $request->has('email') ? 'email' : ($request->has('name') ? 'name' : 'code');

            return back()->withErrors([$field => __('Zu viele Versuche. Bitte warten Sie :seconds Sekunden.', ['seconds' => $seconds])]);
        });
    })->create();
