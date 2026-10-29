<?php

namespace App\Services\Setup;

use App\Support\Translations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Einrichtung durch den Web-Installer (über `bootstrap/phpdbadmin-install.php`): App-Datenbank anlegen und migrieren,
 * das erste Administratorkonto anlegen, Einrichtungslink und Systemprüfung zurückgeben. Die `.env` hat der Installer
 * schon geschrieben. Ein zweiter Aufruf legt kein weiteres Konto an.
 *
 * @phpstan-type Check array{title: string, level: 'ok'|'warn'|'fail', hint: string|null}
 * @phpstan-type Result array{version: string, setupUrl: string|null, existing: bool, checks: list<Check>}
 */
final class WebInstall
{
    public function __construct(
        private readonly FirstAccount $account,
        private readonly SystemCheck $check,
    ) {}

    /**
     * @param  array<string, mixed>  $input  name, email, locale ('de'|'en'), publicPath
     * @return Result
     *
     * @throws InstallFailed
     */
    public function run(array $input): array
    {
        $locale = is_string($input['locale'] ?? null) && Translations::isSupported($input['locale']) ? $input['locale'] : Translations::LOCALES[0];
        app()->setLocale($locale);

        // Links ohne Anfrage: Wurzel aus APP_URL — auch wenn phpdbadmin in einem Unterordner liegt.
        $root = rtrim((string) config('app.url'), '/');
        URL::forceRootUrl($root);

        if (str_starts_with($root, 'https://')) {
            URL::forceScheme('https');
        }

        // Nur ein fehlender Schlüssel wird erzeugt — ein vorhandener nie ersetzt.
        if (blank(config('app.key'))) {
            Artisan::call('key:generate', ['--force' => true]);
        }

        $this->prepareDatabase();
        $existing = $this->account->exists();

        if ($existing) {
            $user = $this->account->unfinishedAdmin();
        } else {
            try {
                $user = $this->account->create((string) ($input['name'] ?? ''), (string) ($input['email'] ?? ''), $locale);
            } catch (ValidationException $exception) {
                throw new InstallFailed($this->validationMessage($exception, (string) ($input['email'] ?? '')), previous: $exception);
            }
        }

        return [
            'version' => (string) config('phpdbadmin.version'),
            'setupUrl' => $user === null ? null : $this->account->setupUrl($user),
            'existing' => $existing,
            'checks' => array_map(fn (CheckResult $result): array => [
                'title' => $result->title,
                'level' => $result->level->value,
                'hint' => $result->hint,
            ], $this->check->run()),
        ];
    }

    /**
     * @throws InstallFailed
     */
    private function prepareDatabase(): void
    {
        $connection = (string) config('database.default');

        // SQLite: Die Datei legt erst diese Einrichtung an — PDO verlangt sie schon beim Verbinden.
        if (config("database.connections.{$connection}.driver") === 'sqlite') {
            $path = (string) config("database.connections.{$connection}.database");

            if ($path !== '' && $path !== ':memory:' && ! file_exists($path)) {
                if (! is_dir(dirname($path)) || ! is_writable(dirname($path)) || ! touch($path)) {
                    throw new InstallFailed(__('Die Datenbank-Datei :path lässt sich nicht anlegen — der Ordner muss für PHP beschreibbar sein.', ['path' => $path]));
                }
            }
        }

        try {
            DB::connection()->getPdo();
        } catch (Throwable $exception) {
            throw new InstallFailed(__('Die App-Datenbank ist nicht erreichbar: :reason', ['reason' => $this->reason($exception)]), previous: $exception);
        }

        try {
            $code = Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable $exception) {
            throw new InstallFailed(__('Die App-Datenbank liess sich nicht einrichten (Migration gescheitert): :reason', ['reason' => $this->reason($exception)]), previous: $exception);
        }

        if ($code !== 0) {
            throw new InstallFailed(__('Die App-Datenbank liess sich nicht einrichten (Migration gescheitert): :reason', ['reason' => trim(Artisan::output())]));
        }
    }

    private function validationMessage(ValidationException $exception, string $email): string
    {
        return $exception->validator->errors()->has('email')
            ? __('Die E-Mail-Adresse «:email» ist ungültig.', ['email' => $email])
            : __('Bitte einen Namen für das Konto angeben (höchstens 255 Zeichen).');
    }

    /** Erste Zeile der Meldung — genug zum Verstehen, ohne Stapel. */
    private function reason(Throwable $exception): string
    {
        return mb_substr(trim(strtok($exception->getMessage(), "\n") ?: $exception::class), 0, 300);
    }
}
