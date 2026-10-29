<?php

namespace App\Http\Controllers\Workspace\Schema;

use App\Connections\TargetConnections;
use App\Http\Requests\Workspace\Schema\TableDraftRequest;
use App\Models\DatabaseConnection;
use App\Services\Catalog\Catalog;
use App\Services\Catalog\Structure;
use App\Services\Schema\ColumnSpec;
use App\Services\Schema\SchemaChanges;
use App\Services\Schema\SchemaPlan;
use App\Services\Schema\SchemaPlans;
use App\Services\Server\ServerInspector;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tabelle ändern (S-03, Wireframe 9): links der Entwurf, rechts das erzeugte SQL mit dem Verfahren je Treiber
 * (ADR-003). Angewendet wird erst nach Bestätigung; löscht die Änderung Spalten, verlangt sie den Namen der Tabelle.
 */
class TableAlterController extends SchemaEditorController
{
    public function __construct(
        Catalog $catalog,
        SchemaPlans $plans,
        SchemaChanges $changes,
        ServerInspector $inspector,
        TargetConnections $targets,
        private readonly Structure $structure,
    ) {
        parent::__construct($catalog, $plans, $changes, $inspector, $targets);
    }

    public function edit(Request $request, DatabaseConnection $connection, string $schema, string $object): Response|RedirectResponse
    {
        $this->authorizeEditing($connection);

        return $this->guarded($request, $connection, function () use ($request, $connection, $schema, $object): Response {
            $database = $this->database($request, $connection);
            $schemas = $this->catalog->schemas($connection, $database);
            abort_unless(in_array($schema, $schemas, true), 404);
            $structure = $this->structure->describe($connection, $schema, $object, $database);
            abort_if($structure === null || $structure['object']['kind'] !== 'table', 404);

            $target = $this->targets->open($connection, $database);
            $specs = $this->plans->currentSpecs($target, $connection, $schema, $object);

            return $this->render($request, $connection, 'workspace/TableAlter', $schemas, $schema, $this->catalog->groups($connection, $schema, $database), $database, [
                'selected' => $object,
                'structure' => $structure,
                'draft' => [
                    'columns' => array_values(array_map(fn (ColumnSpec $spec): array => $spec->toArray(), $specs)),
                    'indexes' => array_map(fn (array $index): array => [
                        'original' => $index['name'],
                        'name' => $index['name'],
                        'columns' => $index['columns'],
                        'unique' => $index['unique'],
                        'primary' => $index['primary'],
                    ], $structure['indexes']),
                    'foreignKeys' => array_map(fn (array $foreignKey): array => [
                        'original' => $foreignKey['columns'],
                        'name' => $foreignKey['name'] !== '' ? $foreignKey['name'] : null,
                        'columns' => $foreignKey['columns'],
                        'table' => $foreignKey['foreignTable'],
                        'references' => $foreignKey['foreignColumns'],
                        'onDelete' => $foreignKey['onDelete'],
                        'onUpdate' => $foreignKey['onUpdate'],
                    ], $structure['foreignKeys']),
                    'comment' => $structure['object']['comment'],
                ],
                'editor' => $this->editor($connection, $schema, $database),
                'targets' => Inertia::defer(fn (): array => $this->targetColumns($connection, $schema, $database)),
            ]);
        });
    }

    public function preview(TableDraftRequest $request, DatabaseConnection $connection, string $schema, string $object): JsonResponse
    {
        $draft = $request->draft($object);

        return $this->previewResponse($request, fn (Connection $target): SchemaPlan => $this->plans->alter($target, $connection, $schema, $object, $draft));
    }

    public function update(TableDraftRequest $request, DatabaseConnection $connection, string $schema, string $object): RedirectResponse
    {
        $draft = $request->draft($object);
        $database = $this->requestedDatabase($request);

        return $this->applyChange(
            $request,
            function (Connection $target) use ($connection, $schema, $object, $draft): SchemaPlan {
                $plan = $this->plans->alter($target, $connection, $schema, $object, $draft);

                if ($plan->statements === []) {
                    throw ValidationException::withMessages(['draft' => __('Es gibt nichts anzuwenden.')]);
                }

                return $plan;
            },
            function (SchemaPlan $plan) use ($connection, $schema, $object, $database): RedirectResponse {
                $this->toast($this->changedMessage($object, count($plan->statements)));

                return to_route('workspace.object.structure', $this->withDatabase([$connection, $schema, $object], $database));
            },
        );
    }

    /** Ein- und Mehrzahl als eigene Texte — trans_choice() fiele bei deutschen Schlüsseln auf Englisch zurück. */
    private function changedMessage(string $table, int $count): string
    {
        return $count === 1
            ? __('Tabelle :table geändert (eine Anweisung).', ['table' => $table])
            : __('Tabelle :table geändert (:count Anweisungen).', ['table' => $table, 'count' => $count]);
    }
}
