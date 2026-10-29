<?php

namespace App\Http\Controllers\Connections;

use App\Connections\ConnectionSettings;
use App\Connections\ConnectionTester;
use App\Connections\HostPolicy;
use App\Connections\KnownHosts;
use App\Enums\ConnectionEnvironment;
use App\Enums\DatabaseDriver;
use App\Enums\SslMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Connections\ConnectionRequest;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Translations;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Startseite = Verbindungsübersicht (A-04, Wireframe 03); anlegen und bearbeiten (V-01, Wireframe 04). Passwörter
 * gehen nie an den Browser (V-03) — die Seite erfährt nur, ob eines gespeichert ist. Dasselbe gilt für Schlüssel und
 * Passphrase eines SSH-Tunnels (V-08).
 */
class ConnectionController extends Controller
{
    public function __construct(private readonly AuditLog $audit) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);

        // Sortiert nach Gruppe (ohne Gruppe zuletzt), dann Name — in PHP, weil «group» je Treiber anders zu quoten wäre.
        // Die Übersicht sortiert um (Favoriten oben, Name, zuletzt geöffnet); die Zeile des Kontos trägt Recht und Merker.
        $connections = DatabaseConnection::query()
            ->visibleTo($user)
            ->with(['memberships' => fn (HasMany $memberships) => $memberships->where('user_id', $user->id)])
            ->get()
            ->sortBy([
                fn (DatabaseConnection $a, DatabaseConnection $b): int => ($a->group === null) <=> ($b->group === null),
                fn (DatabaseConnection $a, DatabaseConnection $b): int => strnatcasecmp((string) $a->group, (string) $b->group),
                fn (DatabaseConnection $a, DatabaseConnection $b): int => strnatcasecmp($a->name, $b->name),
            ]);

        return Inertia::render('connections/Index', [
            'connections' => $connections->map(fn (DatabaseConnection $connection): array => $this->summary($connection, $user))->values(),
        ]);
    }

    public function create(Request $request, HostPolicy $policy): Response
    {
        return Inertia::render('connections/Form', [
            'connection' => null,
            ...$this->formOptions($this->user($request), $policy),
        ]);
    }

    public function store(ConnectionRequest $request, ConnectionTester $tester): RedirectResponse
    {
        $user = $this->user($request);

        $connection = new DatabaseConnection([...$this->attributes($request), ...$this->sshSecrets($request, null)]);
        $connection->owner_id = $user->id;
        $connection->save();

        $this->recordVersion($connection, $tester);
        $this->audit->record('connection.created', $user, context: ['driver' => $connection->driver->value, 'ssh' => $connection->usesSsh()], connection: $connection);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Verbindung «:name» gespeichert.', ['name' => $connection->name])]);

        return to_route('home');
    }

    public function edit(Request $request, DatabaseConnection $connection, HostPolicy $policy, KnownHosts $knownHosts): Response
    {
        $user = $this->user($request);
        Gate::authorize('manage', $connection);

        return Inertia::render('connections/Form', [
            'connection' => [
                'id' => $connection->id,
                'name' => $connection->name,
                'group' => $connection->group,
                'driver' => $connection->driver->value,
                'host' => $connection->host,
                'port' => $connection->port,
                'database' => $connection->database,
                'allDatabases' => $connection->driver->isServer() && $connection->database === null,
                'username' => $connection->username,
                'hasPassword' => $connection->store_password && $connection->password !== null,
                'storePassword' => $connection->store_password,
                'sqlitePath' => $connection->sqlite_path,
                'sslmode' => $connection->sslMode()->value,
                'environment' => $connection->environment->value,
                'readOnly' => $connection->read_only,
                // SSH-Tunnel (V-08): ob ein Schlüssel und eine Passphrase gespeichert sind — nie sie selbst.
                'ssh' => [
                    'enabled' => $connection->usesSsh(),
                    'host' => $connection->ssh_host,
                    'port' => $connection->ssh_port,
                    'username' => $connection->ssh_username,
                    'keyPath' => $connection->ssh_key_path,
                    'hasKey' => $connection->ssh_key !== null,
                    'hasPassphrase' => $connection->ssh_passphrase !== null,
                    'hostKey' => $connection->usesSsh() ? $knownHosts->fingerprint((string) $connection->ssh_host, $connection->ssh_port ?? 22) : null,
                ],
            ],
            ...$this->formOptions($user, $policy),
        ]);
    }

    public function update(ConnectionRequest $request, DatabaseConnection $connection, ConnectionTester $tester): RedirectResponse
    {
        // Recht «verwalten» prüft schon die ConnectionRequest — vor der Validierung.
        $user = $this->user($request);

        $attributes = $this->attributes($request);

        // Leeres Passwortfeld beim Bearbeiten: das gespeicherte bleibt (V-03).
        if ($attributes['store_password'] && ($request->string('password')->toString() === '')) {
            unset($attributes['password']);
        }

        $connection->update([...$attributes, ...$this->sshSecrets($request, $connection)]);

        $this->recordVersion($connection, $tester);
        $this->audit->record('connection.updated', $user, context: ['ssh' => $connection->usesSsh()], connection: $connection);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Verbindung «:name» gespeichert.', ['name' => $connection->name])]);

        return to_route('home');
    }

    public function destroy(Request $request, DatabaseConnection $connection): RedirectResponse
    {
        $user = $this->user($request);
        Gate::authorize('manage', $connection);

        $connection->delete();
        $this->audit->record('connection.deleted', $user, connection: $connection);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Verbindung «:name» entfernt.', ['name' => $connection->name])]);

        return to_route('home');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ConnectionRequest $request): array
    {
        $driver = DatabaseDriver::from($request->string('driver')->toString());
        $environment = ConnectionEnvironment::from($request->string('environment')->toString());
        $storePassword = $request->boolean('store_password', true);
        $text = fn (string $key): ?string => $request->filled($key) ? trim($request->string($key)->toString()) : null;

        return [
            'name' => trim($request->string('name')->toString()),
            'group' => $text('group'),
            'driver' => $driver,
            'host' => $driver->isServer() ? $text('host') : null,
            'port' => $driver->isServer() && $request->filled('port') ? $request->integer('port') : null,
            'database' => $driver->isServer() && ! $request->boolean('all_databases') ? $text('database') : null,
            'username' => $driver->isServer() ? $text('username') : null,
            // Leeres Feld heisst «kein Passwort» (z. B. root in Herd) — nicht ein gespeichertes leeres.
            'password' => $driver->isServer() && $storePassword && $request->filled('password') ? $request->string('password')->toString() : null,
            'store_password' => $driver->isServer() ? $storePassword : true,
            'sqlite_path' => $driver->isServer() ? null : $text('sqlite_path'),
            'options' => $driver->isServer() ? ['sslmode' => $request->string('sslmode')->toString() ?: SslMode::Disable->value] : null,
            'environment' => $environment,
            'read_only' => $request->has('read_only') ? $request->boolean('read_only') : $environment->readOnlyByDefault(),
            'ssh_host' => $this->usesSsh($request) ? $text('ssh_host') : null,
            'ssh_port' => $this->usesSsh($request) && $request->filled('ssh_port') ? $request->integer('ssh_port') : null,
            'ssh_username' => $this->usesSsh($request) ? $text('ssh_username') : null,
            'ssh_key_path' => $this->usesSsh($request) ? $text('ssh_key_path') : null,
        ];
    }

    private function usesSsh(ConnectionRequest $request): bool
    {
        return DatabaseDriver::from($request->string('driver')->toString())->isServer() && $request->boolean('ssh');
    }

    /**
     * Schlüssel und Passphrase (V-08): eingefügt ersetzt, ein Pfad ersetzt den eingefügten Schlüssel, leer behält
     * beim Bearbeiten den gespeicherten. Ohne Tunnel wird beides gelöscht.
     *
     * @return array{ssh_key?: string|null, ssh_passphrase?: string|null}
     */
    private function sshSecrets(ConnectionRequest $request, ?DatabaseConnection $existing): array
    {
        if (! $this->usesSsh($request)) {
            return ['ssh_key' => null, 'ssh_passphrase' => null];
        }

        $passphrase = $request->filled('ssh_passphrase') ? $request->string('ssh_passphrase')->toString() : null;

        if ($request->filled('ssh_key')) {
            return ['ssh_key' => $request->string('ssh_key')->toString(), 'ssh_passphrase' => $passphrase];
        }

        if ($request->filled('ssh_key_path')) {
            $samePath = $existing !== null && $existing->ssh_key_path === trim($request->string('ssh_key_path')->toString());

            return ['ssh_key' => null, 'ssh_passphrase' => $passphrase ?? ($samePath ? $existing->ssh_passphrase : null)];
        }

        return $passphrase !== null ? ['ssh_passphrase' => $passphrase] : [];
    }

    /**
     * Version gleich beim Speichern ablesen, kurz und still — scheitert es, bleibt die Verbindung trotzdem gespeichert.
     */
    private function recordVersion(DatabaseConnection $connection, ConnectionTester $tester): void
    {
        // Ein Tunnel braucht bis zu PHPDBADMIN_SSH_TIMEOUT Sekunden — dafür gibt es «Verbindung testen».
        if (($connection->driver->isServer() && ! $connection->store_password) || $connection->usesSsh()) {
            return;
        }

        $result = $tester->test(ConnectionSettings::fromConnection($connection), connectTimeout: 2);

        if ($result->ok) {
            $connection->forceFill(['server_version' => $result->version, 'account_warnings' => $result->warnings, 'last_tested_at' => now()])->saveQuietly();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(DatabaseConnection $connection, User $user): array
    {
        return [
            'id' => $connection->id,
            'name' => $connection->name,
            'group' => $connection->group,
            'driver' => $connection->driver->value,
            'driverLabel' => $connection->driver->label(),
            'serverVersion' => $connection->server_version,
            'location' => $connection->driver->isServer()
                ? $connection->host.':'.($connection->port ?? $connection->driver->defaultPort()).($connection->usesSsh() ? ' · SSH '.$connection->ssh_host : '')
                : $connection->sqlite_path,
            'database' => $connection->database,
            'username' => $connection->username,
            'environment' => $connection->environment->value,
            'readOnly' => $connection->read_only,
            'lastTestedAt' => $connection->last_tested_at?->toIso8601String(),
            'accountWarnings' => Translations::accountWarnings($connection->account_warnings ?? []),
            'favorite' => $connection->membershipOf($user)->favorite ?? false,
            'lastOpenedAt' => $connection->membershipOf($user)?->last_opened_at?->toIso8601String(),
            'shared' => $connection->shared,
            'isOwner' => $connection->owner_id === $user->id,
            'permission' => $connection->permissionFor($user)?->value,
            'canManage' => $connection->isManageableBy($user),
            'canShare' => $user->can('share', $connection),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(User $user, HostPolicy $policy): array
    {
        return [
            'drivers' => array_map(fn (DatabaseDriver $driver): array => [
                'value' => $driver->value,
                'label' => $driver->label(),
                'minimumVersion' => $driver->minimumVersion(),
                'defaultPort' => $driver->defaultPort(),
                'sslModes' => array_map(fn (SslMode $mode): array => ['value' => $mode->value, 'label' => $mode->label()], SslMode::for($driver)),
            ], DatabaseDriver::cases()),
            'environments' => array_map(fn (ConnectionEnvironment $environment): array => [
                'value' => $environment->value,
                'label' => $environment->label(),
                'readOnlyByDefault' => $environment->readOnlyByDefault(),
            ], ConnectionEnvironment::cases()),
            'allowedHosts' => $policy->allowedHosts(),
            'sqliteFolders' => $policy->sqliteFolders(),
            'sshKeyFolders' => $policy->sshKeyFolders(),
            'groups' => DatabaseConnection::query()->visibleTo($user)->whereNotNull('group')->distinct()->orderBy('group')->pluck('group')->values(),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
