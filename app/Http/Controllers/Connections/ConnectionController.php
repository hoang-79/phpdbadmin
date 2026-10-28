<?php

namespace App\Http\Controllers\Connections;

use App\Connections\ConnectionSettings;
use App\Connections\ConnectionTester;
use App\Connections\HostPolicy;
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
 * gehen nie an den Browser (V-03) — die Seite erfährt nur, ob eines gespeichert ist.
 */
class ConnectionController extends Controller
{
    public function __construct(private readonly AuditLog $audit) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);

        // Sortiert nach Gruppe (ohne Gruppe zuletzt), dann Name — in PHP, weil «group» je Treiber anders zu quoten wäre.
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

        $connection = new DatabaseConnection($this->attributes($request));
        $connection->owner_id = $user->id;
        $connection->save();

        $this->recordVersion($connection, $tester);
        $this->audit->record('connection.created', $user, $connection->id, ['name' => $connection->name, 'driver' => $connection->driver->value]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Verbindung «:name» gespeichert.', ['name' => $connection->name])]);

        return to_route('home');
    }

    public function edit(Request $request, DatabaseConnection $connection, HostPolicy $policy): Response
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

        $connection->update($attributes);

        $this->recordVersion($connection, $tester);
        $this->audit->record('connection.updated', $user, $connection->id, ['name' => $connection->name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Verbindung «:name» gespeichert.', ['name' => $connection->name])]);

        return to_route('home');
    }

    public function destroy(Request $request, DatabaseConnection $connection): RedirectResponse
    {
        $user = $this->user($request);
        Gate::authorize('manage', $connection);

        $connection->delete();
        $this->audit->record('connection.deleted', $user, $connection->id, ['name' => $connection->name]);

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
        ];
    }

    /**
     * Version gleich beim Speichern ablesen, kurz und still — scheitert es, bleibt die Verbindung trotzdem gespeichert.
     */
    private function recordVersion(DatabaseConnection $connection, ConnectionTester $tester): void
    {
        if ($connection->driver->isServer() && ! $connection->store_password) {
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
                ? $connection->host.':'.($connection->port ?? $connection->driver->defaultPort())
                : $connection->sqlite_path,
            'database' => $connection->database,
            'username' => $connection->username,
            'environment' => $connection->environment->value,
            'readOnly' => $connection->read_only,
            'lastTestedAt' => $connection->last_tested_at?->toIso8601String(),
            'accountWarnings' => Translations::accountWarnings($connection->account_warnings ?? []),
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
