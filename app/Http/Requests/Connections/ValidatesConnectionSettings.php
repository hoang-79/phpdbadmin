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
            // «Alle Datenbanken anzeigen»: kein Datenbankname — sonst ist er Pflicht.
            'all_databases' => ['boolean'],
            'database' => ['exclude_if:driver,sqlite', Rule::excludeIf(fn (): bool => $this->boolean('all_databases')), 'required', 'string', 'max:255', $this->safeDatabaseName(...)],
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
            'host' => __('Host'),
            'port' => __('Port'),
            'database' => __('Datenbank'),
            'all_databases' => __('Alle Datenbanken'),
            'username' => __('Benutzer'),
            'password' => __('Passwort'),
            'sslmode' => __('SSL'),
            'sqlite_path' => __('Datei'),
            'driver' => __('Treiber'),
        ];
    }

    private function allowedHost(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            app(HostPolicy::class)->resolveHost((string) $value);
        } catch (ConnectionNotAllowed $exception) {
            $fail($exception->problem->kind === ConnectionProblemKind::HostUnknown
                ? __('Dieser Name lässt sich nicht auflösen.')
                : __('Dieser Host ist in dieser Installation nicht erlaubt.'));
        }
    }

    /** Gegen DSN-Injektion (T-05): kein «;», keine Anführungszeichen, kein «=», kein Backslash, keine Steuerzeichen. */
    private function safeDatabaseName(string $attribute, mixed $value, Closure $fail): void
    {
        if (! HostPolicy::isSafeDatabaseName((string) $value)) {
            $fail(__('Datenbanknamen mit ; \' " = \\ oder Steuerzeichen nimmt phpdbadmin nicht an.'));
        }
    }

    private function allowedSqlitePath(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            app(HostPolicy::class)->resolveSqlitePath((string) $value);
        } catch (ConnectionNotAllowed $exception) {
            $fail(match ($exception->problem->kind) {
                ConnectionProblemKind::FileMissing => __('Unter diesem Pfad liegt keine Datei.'),
                default => $exception->problem->isAppDatabase()
                    ? __('Die eigene Datenbank von phpdbadmin ist hier nicht erreichbar.')
                    : __('Diese Datei liegt nicht in einem erlaubten Ordner.'),
            });
        }
    }
}
