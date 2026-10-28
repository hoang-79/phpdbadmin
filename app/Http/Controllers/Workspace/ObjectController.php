<?php

namespace App\Http\Controllers\Workspace;

use App\Models\DatabaseConnection;
use App\Services\Catalog\Catalog;
use App\Services\Catalog\Structure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Ein Objekt im Arbeitsbildschirm: Tabelle oder Sicht mit den Reitern Daten, Struktur, Beziehungen, SQL
 * (Wireframes 05, 08). Unbekannte Schemas und Objekte sind 404, Verbindungsfehler die Fehlerkarte.
 */
class ObjectController extends WorkspaceController
{
    public function __construct(Catalog $catalog, private readonly Structure $structure)
    {
        parent::__construct($catalog);
    }

    /**
     * Struktur ansehen (S-01): Spalten mit Typ im Original und Art (O-04), Indizes, Fremdschlüssel in beide
     * Richtungen, Checks, Trigger, DDL.
     */
    public function structure(Request $request, DatabaseConnection $connection, string $schema, string $object): Response|RedirectResponse
    {
        return $this->guarded($request, $connection, function () use ($request, $connection, $schema, $object): Response {
            $database = $this->database($request, $connection);
            $schemas = $this->catalog->schemas($connection, $database);
            abort_unless(in_array($schema, $schemas, true), 404);

            $structure = $this->structure->describe($connection, $schema, $object, $database);
            abort_if($structure === null, 404);

            return $this->render($request, $connection, 'workspace/Structure', $schemas, $schema, $this->catalog->groups($connection, $schema, $database), $database, [
                'selected' => $object,
                'structure' => $structure,
            ]);
        });
    }
}
