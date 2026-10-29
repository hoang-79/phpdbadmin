<?php

namespace App\Providers;

use App\Connections\HostPolicy;
use App\Connections\TargetConnections;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Support\QueueHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Je Auflösung neu aus der Konfiguration, damit Änderungen (und Tests) sofort gelten.
        $this->app->bind(HostPolicy::class, fn (): HostPolicy => HostPolicy::fromConfig());

        // Eine Sammlung offener Zielverbindungen je Anfrage; am Ende der Anfrage freigegeben.
        $this->app->scoped(TargetConnections::class);

        // Ein Worker-Prozess hält sein Lebenszeichen über alle Aufträge hinweg (Drosselung im Objekt).
        $this->app->singleton(QueueHeartbeat::class);
        $this->app->terminating(fn () => $this->app->resolved(TargetConnections::class)
            ? $this->app->make(TargetConnections::class)->releaseAll()
            : null);
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->bindConnections();

        // Lebenszeichen des Workers (B-07): ohne Worker laufen Aufträge in der Anfrage statt ewig zu warten.
        Event::listen([JobPopping::class, JobProcessing::class], fn () => $this->app->make(QueueHeartbeat::class)->beat());

        // Verwaltung (Wireframe 14): Konten, Einladungen, Rechte und Protokoll — nur für Administratoren (A-07).
        Gate::define('administrate', fn (User $user): bool => $user->isAdmin());

        // «Verbindung testen» baut echte Verbindungen auf: je Konto höchstens zwanzig je Minute.
        RateLimiter::for('connection-test', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('connection-test:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Einladungen verschicken Mails: je Konto höchstens zwanzig je Stunde.
        RateLimiter::for('invitations', fn (Request $request): Limit => Limit::perHour(20)
            ->by('invitations:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );
    }

    /**
     * {connection} in Adressen: nur, was das angemeldete Konto sehen darf. Eine fremde Verbindung gibt es für dieses
     * Konto nicht — 404 statt 403, damit niemand fremde Kennungen erraten kann (T-16).
     */
    protected function bindConnections(): void
    {
        Route::bind('connection', function (string $value): DatabaseConnection {
            $user = auth()->user();

            if (! $user instanceof User) {
                throw (new ModelNotFoundException)->setModel(DatabaseConnection::class, [$value]);
            }

            return DatabaseConnection::query()->visibleTo($user)->findOrFail($value);
        });
    }
}
