<?php

namespace App\Providers;

use App\Connections\HostPolicy;
use App\Models\DatabaseConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Je Auflösung neu aus der Konfiguration, damit Änderungen (und Tests) sofort gelten.
        $this->app->bind(HostPolicy::class, fn (): HostPolicy => HostPolicy::fromConfig());
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->bindConnections();

        // «Verbindung testen» baut echte Verbindungen auf: je Konto höchstens zwanzig je Minute.
        RateLimiter::for('connection-test', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('connection-test:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
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
