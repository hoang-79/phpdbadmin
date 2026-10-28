<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\FirstSetupRequest;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Auth\SetupKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ersteinrichtung im Browser (A-06, Wunsch Hoang): das erste Konto ohne Terminal anlegen. Abgesichert über den
 * Einrichtungsschlüssel, den phpdbadmin als Datei auf dem Server ablegt — wer die Installation hochgeladen hat, kommt
 * an die Datei, ein zufälliger Besucher nicht. Nur solange es kein Konto gibt; danach geht es ins Onboarding mit
 * Passkey. Der Weg über `php artisan phpdbadmin:install` bleibt daneben bestehen.
 */
class FirstSetupController extends Controller
{
    public function __construct(private readonly SetupKey $setupKey, private readonly AuditLog $audit) {}

    public function show(): Response|RedirectResponse
    {
        if (User::query()->exists()) {
            return to_route('login');
        }

        $this->setupKey->ensure();

        return Inertia::render('auth/FirstSetup', [
            'keyPath' => $this->setupKey->displayPath(),
        ]);
    }

    public function store(FirstSetupRequest $request): RedirectResponse
    {
        if (! $this->setupKey->matches($request->string('key')->toString())) {
            $this->audit->record('setup.failed', context: ['method' => 'browser']);

            throw ValidationException::withMessages([
                'key' => 'Der Einrichtungsschlüssel stimmt nicht. Er steht in der Datei '.$this->setupKey->displayPath().'.',
            ]);
        }

        // Zwei gleichzeitige Einrichtungen: nur die erste legt ein Konto an.
        $user = Cache::lock('phpdbadmin-ersteinrichtung', 10)->block(5, function () use ($request): ?User {
            if (User::query()->exists()) {
                return null;
            }

            return User::query()->create([
                'name' => trim($request->string('name')->toString()),
                'email' => mb_strtolower(trim($request->string('email')->toString())),
                'role' => UserRole::Admin,
            ]);
        });

        if ($user === null) {
            return to_route('login');
        }

        $this->setupKey->forget();
        $this->audit->record('account.installed', $user, target: $user->email, context: ['method' => 'browser']);

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('onboarding');
    }
}
