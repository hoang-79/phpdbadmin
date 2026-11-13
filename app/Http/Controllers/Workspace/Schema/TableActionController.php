<?php

namespace App\Http\Controllers\Workspace\Schema;

use App\Http\Requests\Workspace\Schema\TableActionRequest;
use App\Models\DatabaseConnection;
use App\Services\Schema\SchemaPlan;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

    /**
     * Tabellenoptionen (S-10) für den Dialog: was die Tabelle hat und was der Server anbietet — nur MySQL und MariaDB.
     */
    public function options(Request $request, DatabaseConnection $connection, string $schema, string $object): JsonResponse
    {
        $this->authorizeEditing($connection);
        $target = $this->targets->open($connection, $this->database($request, $connection));
        $dialect = $connection->driver->dialect();
        $current = $dialect->tableOptions($target, $schema, $object);
        abort_if($current === null, 404);

        return response()->json(['current' => $current, 'choices' => $dialect->optionChoices($target)]);
    }

    private function optional(TableActionRequest $request, string $key): ?string
    {
        $value = $request->validated($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function plan(Connection $target, TableActionRequest $request, DatabaseConnection $connection, string $schema, string $object): SchemaPlan
    {
        return match ($request->operation()) {
            'rename' => $this->plans->rename($target, $connection, $schema, $object, $request->to(), $request->toSchema($schema)),
            'copy' => $this->plans->copy($target, $connection, $schema, $object, $request->to(), (bool) $request->validated('with_data'), $request->toSchema($schema)),
            'truncate' => $this->plans->truncate($target, $connection, $schema, $object),
            'options' => $this->plans->tableOptions(
                $target, $connection, $schema, $object,
                $this->optional($request, 'engine'),
                $this->optional($request, 'collation'),
                (bool) $request->validated('convert'),
                $request->validated('auto_increment') !== null ? (int) $request->validated('auto_increment') : null,
            ),
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
            'options' => __('Optionen von :table geändert.', ['table' => $table]),
            default => __('Tabelle :table gelöscht.', ['table' => $table]),
        };
    }
}
