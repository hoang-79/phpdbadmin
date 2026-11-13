<?php

namespace App\Http\Controllers\Workspace\Schema;

use App\Http\Requests\Workspace\Schema\TableActionRequest;
use App\Models\DatabaseConnection;
use App\Services\Schema\SchemaPlan;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Tabelle umbenennen, kopieren (Struktur oder mit Daten), leeren und löschen (S-04) — immer mit Vorschau; Leeren
 * und Löschen nur mit dem eingetippten Namen der Tabelle. Umbenennen und Kopieren auch in eine andere Datenbank bzw.
 * ein anderes Schema desselben Servers (S-11).
 */
class TableActionController extends SchemaEditorController
{
    public function preview(TableActionRequest $request, DatabaseConnection $connection, string $schema, string $object): JsonResponse
    {
        return $this->previewResponse($request, fn (Connection $target): SchemaPlan => $this->plan($target, $request, $connection, $schema, $object));
    }

    public function apply(TableActionRequest $request, DatabaseConnection $connection, string $schema, string $object): RedirectResponse
    {
        $database = $this->requestedDatabase($request);

        return $this->applyChange(
            $request,
            fn (Connection $target): SchemaPlan => $this->plan($target, $request, $connection, $schema, $object),
            function () use ($request, $connection, $schema, $object, $database): RedirectResponse {
                $this->toast($this->doneMessage($request->operation(), $object, $request->operation() === 'rename' || $request->operation() === 'copy' ? $request->to() : null, $request->operation() === 'rename' || $request->operation() === 'copy' ? $request->toSchema($schema) : $schema, $schema));

                return match ($request->operation()) {
                    'rename', 'copy' => to_route('workspace.object.structure', $this->withDatabase([$connection, $request->toSchema($schema), $request->to()], $database)),
                    'drop' => to_route('workspace.schema', $this->withDatabase([$connection, $schema], $database)),
                    default => to_route('workspace.object.structure', $this->withDatabase([$connection, $schema, $object], $database)),
                };
            },
        );
    }

    private function plan(Connection $target, TableActionRequest $request, DatabaseConnection $connection, string $schema, string $object): SchemaPlan
    {
        return match ($request->operation()) {
            'rename' => $this->plans->rename($target, $connection, $schema, $object, $request->to(), $request->toSchema($schema)),
            'copy' => $this->plans->copy($target, $connection, $schema, $object, $request->to(), (bool) $request->validated('with_data'), $request->toSchema($schema)),
            'truncate' => $this->plans->truncate($target, $connection, $schema, $object),
            default => $this->plans->drop($target, $connection, $schema, $object),
        };
    }

    private function doneMessage(string $operation, string $table, ?string $to, string $toSchema, string $schema): string
    {
        // Über Datenbanken hinweg (S-11) steht das Ziel mit Datenbank bzw. Schema in der Meldung
        $target = $toSchema !== $schema ? $toSchema.'.'.$to : (string) $to;

        return match ($operation) {
            'rename' => $toSchema !== $schema
                ? __('Tabelle :table nach :to verschoben.', ['table' => $table, 'to' => $target])
                : __('Tabelle :table heisst jetzt :to.', ['table' => $table, 'to' => $target]),
            'copy' => __('Tabelle :table nach :to kopiert.', ['table' => $table, 'to' => $target]),
            'truncate' => __('Tabelle :table geleert.', ['table' => $table]),
            default => __('Tabelle :table gelöscht.', ['table' => $table]),
        };
    }
}
