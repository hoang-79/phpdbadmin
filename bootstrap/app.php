<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Services\Audit\Masking;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance']);

        $middleware->web(append: [
            SecurityHeaders::class,
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

        // Datenbankfehler ohne Werte ins Log (B-04, T-11): Laravel setzt gebundene Werte in die Meldung ein, die Server
        // zitieren Werte. Ersetzt den üblichen Eintrag (false), auch für Fehler der App-Datenbank (Datenmodell § 1.9).
        $exceptions->report(function (QueryException $exception): bool {
            Log::error(Masking::exception($exception), [
                'exception' => $exception::class,
                'code' => $exception->getCode(),
                'connection' => $exception->getConnectionName(),
                'trace' => Masking::message($exception->getTraceAsString(), $exception->getBindings()),
            ]);

            return false;
        });

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
