<?php

namespace App\Http\Controllers\Workspace\Schema;

use App\Enums\DatabaseDriver;
use App\Http\Requests\Workspace\Schema\SchemaRequest;
use App\Models\DatabaseConnection;
use App\Services\Schema\SchemaPlan;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Datenbank (MySQL, MariaDB) bzw. Schema (PostgreSQL) anlegen und löschen, bei SQLite eine neue Datei in einem
 * erlaubten Ordner (S-05). Löschen nur mit dem eingetippten Namen.
 */
class SchemaController extends SchemaEditorController
{
    /**
     * SQLite: Ordner, in denen eine neue Datei entstehen darf (erlaubt und beschreibbar).
     */
    public function folders(DatabaseConnection $connection): JsonResponse
    {
        $this->authorizeEditing($connection);
        abort_unless($connection->driver === DatabaseDriver::Sqlite, 404);

        return response()->json(['folders' => $this->plans->sqliteFolders()]);
    }

    public function preview(SchemaRequest $request, DatabaseConnection $connection): JsonResponse
    {
        return $this->previewResponse($request, fn (Connection $target): SchemaPlan => $this->plan($target, $request, $connection));
    }

    public function apply(SchemaRequest $request, DatabaseConnection $connection): RedirectResponse
    {
        $database = $this->requestedDatabase($request);

        return $this->applyChange(
            $request,
            fn (Connection $target): SchemaPlan => $this->plan($target, $request, $connection),
            function (SchemaPlan $plan) use ($request, $connection, $database): RedirectResponse {
                if ($plan->file !== null) {
                    $this->toast(__('Datei :file angelegt.', ['file' => basename($plan->file)]));
                    Inertia::flash('sqliteFile', $plan->file);

                    return back();
                }

                $name = $request->text('name');

                if ($request->operation() === 'create') {
                    $this->toast($this->createdMessage($connection->driver, $name));

                    return to_route('workspace.schema', $this->withDatabase([$connection, $name], $database));
                }

                $this->toast($this->droppedMessage($connection->driver, $name));

                return to_route('workspace', $this->withDatabase([$connection], $database));
            },
        );
    }

    private function plan(Connection $target, SchemaRequest $request, DatabaseConnection $connection): SchemaPlan
    {
        if ($connection->driver === DatabaseDriver::Sqlite) {
            return $this->plans->createSqliteFile($connection, $request->text('folder'), $request->text('file'));
        }

        return $request->operation() === 'create'
            ? $this->plans->createSchema($target, $connection, $request->text('name'))
            : $this->plans->dropSchema($target, $connection, $request->text('name'));
    }

    private function createdMessage(DatabaseDriver $driver, string $name): string
    {
        return $driver === DatabaseDriver::Postgres
            ? __('Schema :name angelegt.', ['name' => $name])
            : __('Datenbank :name angelegt.', ['name' => $name]);
    }

    private function droppedMessage(DatabaseDriver $driver, string $name): string
    {
        return $driver === DatabaseDriver::Postgres
            ? __('Schema :name gelöscht.', ['name' => $name])
            : __('Datenbank :name gelöscht.', ['name' => $name]);
    }
}
