<?php

namespace App\Http\Controllers\Workspace\Schema;

use App\Http\Requests\Workspace\Schema\TableDraftRequest;
use App\Models\DatabaseConnection;
use App\Services\Schema\SchemaPlan;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tabelle anlegen (S-02): geführtes Formular mit Spalten, Primärschlüssel, Indizes und Fremdschlüsseln; die Vorschau
 * kommt aus Blueprint::toSql() und ist genau das, was danach läuft.
 */
class TableCreateController extends SchemaEditorController
{
    public function create(Request $request, DatabaseConnection $connection, string $schema): Response|RedirectResponse
    {
        $this->authorizeEditing($connection);

        return $this->guarded($request, $connection, function () use ($request, $connection, $schema): Response {
            $database = $this->database($request, $connection);
            $schemas = $this->catalog->schemas($connection, $database);
            abort_unless(in_array($schema, $schemas, true), 404);

            return $this->render($request, $connection, 'workspace/TableCreate', $schemas, $schema, $this->catalog->groups($connection, $schema, $database), $database, [
                'editor' => $this->editor($connection, $schema, $database),
                'targets' => Inertia::defer(fn (): array => $this->targetColumns($connection, $schema, $database)),
            ]);
        });
    }

    public function preview(TableDraftRequest $request, DatabaseConnection $connection, string $schema): JsonResponse
    {
        $draft = $request->draft();

        return $this->previewResponse($request, fn (Connection $target): SchemaPlan => $this->plans->create($target, $connection, $schema, $draft));
    }

    public function store(TableDraftRequest $request, DatabaseConnection $connection, string $schema): RedirectResponse
    {
        $draft = $request->draft();
        $database = $this->requestedDatabase($request);

        return $this->applyChange(
            $request,
            fn (Connection $target): SchemaPlan => $this->plans->create($target, $connection, $schema, $draft),
            function () use ($connection, $schema, $draft, $database): RedirectResponse {
                $this->toast($this->createdMessage($draft->name));

                return to_route('workspace.object.structure', $this->withDatabase([$connection, $schema, $draft->name], $database));
            },
        );
    }

    private function createdMessage(string $table): string
    {
        return __('Tabelle :table angelegt.', ['table' => $table]);
    }
}
