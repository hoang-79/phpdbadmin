<?php

namespace App\Http\Controllers\Workspace;

use App\Connections\TargetConnections;
use App\Models\DatabaseConnection;
use App\Services\Catalog\Catalog;
use App\Services\Catalog\Structure;
use App\Services\Shape\MigrationWriter;
use App\Services\Shape\ShapeReader;
use App\Services\Shape\TableShape;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Laravel-Migration aus einer bestehenden Tabelle (S-09): erzeugt die Datei zum Kopieren oder Herunterladen — aus
 * der Struktur der Tabelle, mit den Zielen ihrer Fremdschlüssel, damit die Ganzzahltypen auf jedem Treiber passen.
 * Nur lesen; Sichten haben keine Migration (404).
 */
class MigrationController extends WorkspaceController
{
    public function __construct(
        Catalog $catalog,
        private readonly Structure $structure,
        private readonly TargetConnections $targets,
        private readonly ShapeReader $reader,
        private readonly MigrationWriter $writer,
    ) {
        parent::__construct($catalog);
    }

    public function __invoke(Request $request, DatabaseConnection $connection, string $schema, string $object): Response|RedirectResponse
    {
        return $this->guarded($request, $connection, function () use ($request, $connection, $schema, $object): Response {
            $database = $this->database($request, $connection);
            $schemas = $this->catalog->schemas($connection, $database);
            abort_unless(in_array($schema, $schemas, true), 404);

            $target = $this->targets->open($connection, $database);
            $table = $this->reader->table($target, $connection->driver, $schema, $object);
            abort_if($table === null, 404);

            $referenced = [];
            foreach (array_unique(array_map(fn ($foreignKey): string => $foreignKey->foreignTable, $table->foreignKeys)) as $name) {
                if ($name !== $table->name && ($shape = $this->reader->table($target, $connection->driver, $schema, $name)) instanceof TableShape) {
                    $referenced[] = $shape;
                }
            }

            $origin = trim($connection->driver->label().' '.$connection->server_version).', '.($connection->driver->isServer() ? $schema : basename((string) $connection->sqlite_path));

            return $this->render($request, $connection, 'workspace/Migration', $schemas, $schema, $this->catalog->groups($connection, $schema, $database), $database, [
                'selected' => $object,
                'object' => $this->structure->summary($connection, $schema, $object, $database),
                'migration' => $this->writer->write($table, $referenced, $origin),
            ]);
        });
    }
}
