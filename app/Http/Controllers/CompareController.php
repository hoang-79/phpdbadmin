<?php

namespace App\Http\Controllers;

use App\Connections\ConnectionNotAllowed;
use App\Connections\PasswordRequired;
use App\Connections\TargetConnections;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Catalog\Catalog;
use App\Services\Shape\ChangeScriptBuilder;
use App\Services\Shape\ComparisonPresenter;
use App\Services\Shape\ShapeComparer;
use App\Services\Shape\ShapeReader;
use App\Services\Shape\ShapeTooLarge;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use PDOException;

/**
 * Schemavergleich (S-08): Quelle und Ziel aus den Verbindungen, die das Konto sieht — gleiche oder verschiedene
 * Treiber. Ergebnis als Liste und als Änderungsskript im Dialekt des Ziels zum Kopieren, Herunterladen oder Öffnen
 * im SQL-Editor des Ziels. Ausgeführt wird nichts; deshalb genügt auf beiden Seiten das Recht «ansehen».
 *
 * Alles steht in der Adresse (`?source=…&source_schema=…&target=…&run=1`), damit sich ein Vergleich neu laden und
 * teilen lässt. Unbekannte und fremde Verbindungen sind 404 (T-16).
 */
class CompareController extends Controller
{
    /** Länge, bis zu der das Skript als Vorlage in die Adresse des SQL-Editors passt. */
    private const int EDITOR_TEMPLATE_LIMIT = 4000;

    public function __construct(
        private readonly Catalog $catalog,
        private readonly TargetConnections $targets,
    ) {}

    public function __invoke(Request $request, ShapeReader $reader, ShapeComparer $comparer, ChangeScriptBuilder $builder, ComparisonPresenter $presenter): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $input = $request->validate([
            'source' => ['nullable', 'string', 'size:26'],
            'source_db' => ['nullable', 'string', 'max:255'],
            'source_schema' => ['nullable', 'string', 'max:255'],
            'target' => ['nullable', 'string', 'size:26'],
            'target_db' => ['nullable', 'string', 'max:255'],
            'target_schema' => ['nullable', 'string', 'max:255'],
            'drops' => ['nullable', 'boolean'],
            'run' => ['nullable', 'boolean'],
        ]);

        $source = $this->side($request, $user, 'source', $input);
        $target = $this->side($request, $user, 'target', $input);
        $withDrops = $request->boolean('drops');

        return Inertia::render('compare/Index', [
            'connections' => DatabaseConnection::query()
                ->visibleTo($user)
                ->orderBy('name')
                ->get(['id', 'name', 'group', 'driver', 'environment'])
                ->map(fn (DatabaseConnection $connection): array => [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'group' => $connection->group,
                    'driverLabel' => $connection->driver->label(),
                    'environment' => $connection->environment->value,
                ])
                ->values(),
            'source' => $source['props'],
            'target' => $target['props'],
            'withDrops' => $withDrops,
            // Verglichen wird erst auf Knopfdruck — die Wahl von Verbindung und Schema lädt nur die Listen.
            'result' => $request->boolean('run') ? $this->result($source, $target, $withDrops, $reader, $comparer, $builder, $presenter) : null,
        ]);
    }

    /**
     * Eine Seite des Vergleichs: Verbindung, Datenbanken (PostgreSQL mit «alle Datenbanken»), Schemas, das gewählte
     * Schema — oder der Grund, warum sich die Verbindung nicht öffnen lässt.
     *
     * @param  array<string, mixed>  $input
     * @return array{connection: DatabaseConnection|null, database: string|null, schema: string|null, props: array<string, mixed>}
     */
    private function side(Request $request, User $user, string $prefix, array $input): array
    {
        $id = $input[$prefix] ?? null;
        $empty = ['connection' => null, 'database' => null, 'schema' => null, 'props' => ['connection' => null, 'databases' => null, 'database' => null, 'schemas' => [], 'schema' => null, 'problem' => null]];

        if (! is_string($id) || $id === '') {
            return $empty;
        }

        $connection = DatabaseConnection::query()->visibleTo($user)->find($id);
        abort_if($connection === null, 404);
        Gate::forUser($user)->authorize('view', $connection);

        $summary = ['id' => $connection->id, 'name' => $connection->name, 'driver' => $connection->driver->value, 'driverLabel' => $connection->driver->label(), 'environment' => $connection->environment->value];

        try {
            $databases = $this->catalog->databases($connection);
            $requestedDatabase = $input[$prefix.'_db'] ?? null;
            $database = $databases === null ? null : (in_array($requestedDatabase, $databases, true) ? $requestedDatabase : (in_array(Catalog::POSTGRES_MAINTENANCE_DATABASE, $databases, true) ? Catalog::POSTGRES_MAINTENANCE_DATABASE : ($databases[0] ?? null)));
            $schemas = $this->catalog->schemas($connection, $database);
            $requestedSchema = $input[$prefix.'_schema'] ?? null;
            $schema = in_array($requestedSchema, $schemas, true) ? $requestedSchema : $this->catalog->defaultSchema($connection, $database);
        } catch (PasswordRequired) {
            // Nach der Eingabe zurück zum Vergleich.
            redirect()->setIntendedUrl($request->fullUrl());

            return ['connection' => null, 'database' => null, 'schema' => null, 'props' => [...$empty['props'], 'connection' => $summary, 'problem' => [
                'kind' => 'password',
                'title' => __('Passwort nötig'),
                'explanation' => __('Diese Verbindung speichert kein Passwort. Geben Sie es einmal für diese Sitzung ein.'),
                'passwordUrl' => route('workspace.password', $connection),
            ]]];
        } catch (ConnectionNotAllowed $exception) {
            return ['connection' => null, 'database' => null, 'schema' => null, 'props' => [...$empty['props'], 'connection' => $summary, 'problem' => $exception->problem->toArray()]];
        } catch (QueryException|PDOException $exception) {
            return ['connection' => null, 'database' => null, 'schema' => null, 'props' => [...$empty['props'], 'connection' => $summary, 'problem' => $connection->driver->dialect()->describeConnectionError($exception)->toArray()]];
        }

        return [
            'connection' => $connection,
            'database' => $database,
            'schema' => $schema,
            'props' => ['connection' => $summary, 'databases' => $databases, 'database' => $database, 'schemas' => $schemas, 'schema' => $schema, 'problem' => null],
        ];
    }

    /**
     * @param  array{connection: DatabaseConnection|null, database: string|null, schema: string|null}  $source
     * @param  array{connection: DatabaseConnection|null, database: string|null, schema: string|null}  $target
     * @return array<string, mixed>|null
     */
    private function result(array $source, array $target, bool $withDrops, ShapeReader $reader, ShapeComparer $comparer, ChangeScriptBuilder $builder, ComparisonPresenter $presenter): ?array
    {
        if ($source['connection'] === null || $source['schema'] === null || $target['connection'] === null || $target['schema'] === null) {
            return null;
        }

        $sourceConnection = $source['connection'];
        $targetConnection = $target['connection'];

        try {
            $targetDb = $this->targets->open($targetConnection, $target['database']);
            $comparison = $comparer->compare(
                $reader->schema($this->targets->open($sourceConnection, $source['database']), $sourceConnection->driver, $source['schema']),
                $reader->schema($targetDb, $targetConnection->driver, $target['schema']),
            );
        } catch (ShapeTooLarge $exception) {
            return ['error' => __('Zu viele Tabellen für einen Vergleich: :tables, höchstens :limit (PHPDBADMIN_COMPARE_MAX_TABLES).', ['tables' => (string) $exception->tables, 'limit' => (string) $exception->limit])];
        } catch (QueryException|PDOException) {
            return ['error' => __('Die Struktur liess sich nicht lesen. Prüfen Sie beide Verbindungen und laden Sie neu.')];
        }

        $script = $builder->build($comparison, $targetDb, $withDrops, [
            __('phpdbadmin — Änderungsskript, erzeugt am :date', ['date' => now()->toDateTimeString()]),
            __('Quelle: :connection (:driver), :schema', ['connection' => $sourceConnection->name, 'driver' => $sourceConnection->driver->label(), 'schema' => $source['schema']]),
            __('Ziel: :connection (:driver), :schema', ['connection' => $targetConnection->name, 'driver' => $targetConnection->driver->label(), 'schema' => $target['schema']]),
            __('Nichts davon wurde ausgeführt. Prüfen Sie das Skript, bevor Sie es im SQL-Editor ausführen.'),
            $this->applyNote($targetConnection),
        ]);
        $text = $script->text();

        return [
            'error' => null,
            'sameDriver' => $comparison->sameDriver(),
            'identical' => $comparison->identical,
            'counts' => $comparison->counts(),
            'tables' => $presenter->tables($comparison),
            'script' => $script->isEmpty() ? null : $text,
            'statements' => count($script->statements()),
            'notes' => $script->notes(),
            'editorUrl' => ! $script->isEmpty() && mb_strlen($text) <= self::EDITOR_TEMPLATE_LIMIT
                ? route('workspace.sql', [$targetConnection, 'tab' => 99, 'schema' => $target['schema'], ...($target['database'] !== null ? ['db' => $target['database']] : []), 'vorlage' => $text])
                : null,
            'fileName' => 'aenderungen-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $target['schema']).'.sql',
        ];
    }

    /** Wie der Treiber des Ziels Strukturänderungen ausführt (ADR-003) — steht im Kopf des Skripts. */
    private function applyNote(DatabaseConnection $target): string
    {
        return match ($target->driver->value) {
            'pgsql' => __('PostgreSQL: Strukturänderungen lassen sich in einer Transaktion ausführen.'),
            'sqlite' => __('SQLite: Spalten ändern und Fremdschlüssel bauen die Tabelle neu (Kopie).'),
            default => __('MySQL und MariaDB: Strukturänderungen sind nicht rückrollbar — bei einem Fehler bleiben die vorherigen Schritte bestehen.'),
        };
    }
}
