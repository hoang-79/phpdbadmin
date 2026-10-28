<?php

namespace App\Http\Requests\Connections;

use App\Connections\ConnectionNotAllowed;
use App\Connections\ConnectionProblemKind;
use App\Connections\HostPolicy;
use App\Enums\DatabaseDriver;
use App\Enums\SslMode;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Prüfregeln für die Angaben einer Zielverbindung — gemeinsam für Speichern und «Verbindung testen». Host und Datei
 * werden schon hier gegen die HostPolicy geprüft (V-05), damit der Fehler am Feld steht.
 */
trait ValidatesConnectionSettings
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function settingsRules(): array
    {
        $driver = DatabaseDriver::tryFrom((string) $this->input('driver'));
        $sslModes = $driver !== null ? array_map(fn (SslMode $mode): string => $mode->value, SslMode::for($driver)) : [];

        return [
            'driver' => ['required', Rule::enum(DatabaseDriver::class)],
            'host' => ['exclude_if:driver,sqlite', 'required', 'string', 'max:255', $this->allowedHost(...)],
            'port' => ['exclude_if:driver,sqlite', 'nullable', 'integer', 'between:1,65535'],
            'database' => ['exclude_if:driver,sqlite', 'required_if:driver,pgsql', 'nullable', 'string', 'max:255'],
            'username' => ['exclude_if:driver,sqlite', 'required', 'string', 'max:255'],
            'password' => ['exclude_if:driver,sqlite', 'nullable', 'string', 'max:1024'],
            'sslmode' => ['exclude_if:driver,sqlite', 'nullable', Rule::in($sslModes)],
            'sqlite_path' => ['exclude_unless:driver,sqlite', 'required', 'string', 'max:1024', $this->allowedSqlitePath(...)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function settingsAttributes(): array
    {
        return [
            'host' => 'Host',
            'port' => 'Port',
            'database' => 'Datenbank',
            'username' => 'Benutzer',
            'password' => 'Passwort',
            'sslmode' => 'SSL',
            'sqlite_path' => 'Datei',
            'driver' => 'Treiber',
        ];
    }

    private function allowedHost(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            app(HostPolicy::class)->resolveHost((string) $value);
        } catch (ConnectionNotAllowed $exception) {
            $fail($exception->problem->kind === ConnectionProblemKind::HostUnknown
                ? 'Dieser Name lässt sich nicht auflösen.'
                : 'Dieser Host ist in dieser Installation nicht erlaubt.');
        }
    }

    private function allowedSqlitePath(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            app(HostPolicy::class)->resolveSqlitePath((string) $value);
        } catch (ConnectionNotAllowed $exception) {
            $fail(match ($exception->problem->kind) {
                ConnectionProblemKind::FileMissing => 'Unter diesem Pfad liegt keine Datei.',
                default => $exception->problem->detail === 'Das ist die eigene Datenbank von phpdbadmin.'
                    ? 'Die eigene Datenbank von phpdbadmin ist hier nicht erreichbar.'
                    : 'Diese Datei liegt nicht in einem erlaubten Ordner.',
            });
        }
    }
}
