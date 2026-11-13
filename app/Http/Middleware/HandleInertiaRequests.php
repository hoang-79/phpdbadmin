<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Ai\SqlAssistant;
use App\Services\Update\UpdateChannel;
use App\Support\Translations;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => $locale,
            // Einmal je Sprache: der Browser behält die Übersetzungen, bis die Sprache wechselt (N-06).
            'translations' => Inertia::once(fn (): array => Translations::forFrontend($locale))->as('translations.'.$locale),
            // Nur, was die Oberfläche braucht — nie das ganze Modell (T-03).
            'auth' => [
                'user' => $user instanceof User ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'isAdmin' => $user->isAdmin(),
                    // KI-Hilfe (Q-08): eingeschaltet und Schlüssel hinterlegt — nie der Schlüssel selbst.
                    'ai' => SqlAssistant::enabled() ? ['hasKey' => filled($user->ai_api_key)] : null,
                ] : null,
            ],
            // Neue Version (B-09): nur für Administratoren und nur aus dem Zwischenspeicher — fehlt er, fragt phpdbadmin
            // nach der Antwort nach.
            'update' => fn (): ?array => $user instanceof User && $user->isAdmin() ? $this->updateHint() : null,
        ];
    }

    /**
     * @return array{version: string}|null
     */
    private function updateHint(): ?array
    {
        $channel = app(UpdateChannel::class);
        $channel->refreshAfterResponse();
        $status = $channel->cached();

        return $status !== null && $status['available'] && $status['latest'] !== null ? ['version' => $status['latest']] : null;
    }
}
