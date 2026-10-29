<?php

namespace App\Http\Requests\Connections;

use App\Connections\ConnectionNotAllowed;
use App\Connections\ConnectionProblemKind;
use App\Connections\HostPolicy;
use App\Enums\DatabaseDriver;
use App\Enums\SslMode;
use App\Models\DatabaseConnection;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Prüfregeln für die Angaben einer Zielverbindung — gemeinsam für Speichern und «Verbindung testen». Host und Datei
 * werden schon hier gegen die HostPolicy geprüft (V-05), damit der Fehler am Feld steht. Mit SSH-Tunnel (V-08) gilt
 * der Host aus Sicht des SSH-Servers, und der SSH-Server selbst muss erlaubt sein.
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
            ...$this->sshRules(),
        ];
    }

    /**
     * SSH-Tunnel (V-08): nur für Server und nur eingeschaltet. Benutzer und Host so, dass sie nie als Option von ssh
     * gelesen werden können; der Schlüssel eingefügt oder als Datei in einem erlaubten Ordner — beim Bearbeiten darf
     * das Feld leer bleiben, dann gilt der gespeicherte.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function sshRules(): array
    {
        $off = Rule::excludeIf(fn (): bool => $this->input('driver') === DatabaseDriver::Sqlite->value || ! $this->boolean('ssh'));

        return [
            'ssh' => ['boolean'],
            'ssh_host' => [$off, 'required', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9.:-]*$/', $this->allowedSshHost(...)],
            'ssh_port' => [$off, 'nullable', 'integer', 'between:1,65535'],
            'ssh_username' => [$off, 'required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_][A-Za-z0-9._-]*$/'],
            'ssh_key' => [$off, Rule::requiredIf(fn (): bool => ! $this->filled('ssh_key_path') && ! $this->hasStoredSshKey()), 'nullable', 'string', 'max:16384', $this->looksLikePrivateKey(...)],
            'ssh_key_path' => [$off, 'nullable', 'string', 'max:1024', $this->allowedSshKeyPath(...)],
            'ssh_passphrase' => [$off, 'nullable', 'string', 'max:1024'],
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
            'ssh_host' => __('SSH-Host'),
            'ssh_port' => __('SSH-Port'),
            'ssh_username' => __('SSH-Benutzer'),
            'ssh_key' => __('Privater Schlüssel'),
            'ssh_key_path' => __('Schlüsseldatei'),
            'ssh_passphrase' => __('Passphrase'),
        ];
    }

    /**
     * Beim Bearbeiten (oder Testen einer gespeicherten Verbindung) darf das Schlüsselfeld leer bleiben.
     */
    private function hasStoredSshKey(): bool
    {
        $connection = $this->route('connection');

        if ($connection instanceof DatabaseConnection) {
            return $connection->ssh_key !== null || $connection->ssh_key_path !== null;
        }

        return $this->filled('connection');
    }

    private function allowedSshHost(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            app(HostPolicy::class)->resolveHost((string) $value);
        } catch (ConnectionNotAllowed $exception) {
            $fail($exception->problem->kind === ConnectionProblemKind::HostUnknown
                ? __('Dieser Name lässt sich nicht auflösen.')
                : __('Dieser Host ist in dieser Installation nicht erlaubt.'));
        }
    }

    private function allowedSshKeyPath(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            app(HostPolicy::class)->resolveSshKeyPath((string) $value);
        } catch (ConnectionNotAllowed $exception) {
            $fail($exception->problem->kind === ConnectionProblemKind::FileMissing
                ? __('Unter diesem Pfad liegt keine Datei.')
                : __('Diese Datei liegt nicht in einem erlaubten Ordner.'));
        }
    }

    private function looksLikePrivateKey(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && trim($value) !== '' && preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $value) !== 1) {
            $fail(__('Das sieht nicht nach einem privaten Schlüssel aus (-----BEGIN … PRIVATE KEY-----).'));
        }
    }

    private function allowedHost(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $viaSsh = $this->boolean('ssh') && $this->filled('ssh_host');
            $viaSsh ? app(HostPolicy::class)->resolveTunnelTarget((string) $value) : app(HostPolicy::class)->resolveHost((string) $value);
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
