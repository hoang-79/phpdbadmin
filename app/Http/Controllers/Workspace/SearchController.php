<?php

namespace App\Http\Controllers\Workspace;

use App\Models\DatabaseConnection;
use App\Services\Catalog\Catalog;
use App\Services\Data\SchemaSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Suche über ein ganzes Schema (D-08): nur lesen, Treffer je Tabelle mit Link ins gefilterte Raster. Der Suchtext
 * steht in der Adresse (`?q=…`), damit Neuladen und Teilen dieselbe Suche zeigen.
 */
class SearchController extends WorkspaceController
{
    public function __construct(Catalog $catalog, private readonly SchemaSearch $search)
    {
        parent::__construct($catalog);
    }

    public function index(Request $request, DatabaseConnection $connection): Response|RedirectResponse
    {
        return $this->guarded($request, $connection, function () use ($request, $connection): Response {
            $database = $this->database($request, $connection);
            $schemas = $this->catalog->schemas($connection, $database);
            $requested = $request->query('schema');
            $schema = is_string($requested) && in_array($requested, $schemas, true) ? $requested : $this->catalog->defaultSchema($connection, $database);
            $term = is_string($request->query('q')) ? mb_substr((string) $request->query('q'), 0, 1000) : '';

            return $this->render($request, $connection, 'workspace/Search', $schemas, $schema, $schema === null ? [] : $this->catalog->groups($connection, $schema, $database), $database, [
                'term' => $term,
                'outcome' => $schema !== null && trim($term) !== '' ? $this->search->search($connection, $database, $schema, $term) : null,
                'limits' => [
                    'seconds' => (int) config('phpdbadmin.data.search_seconds', 10),
                    'hits' => (int) config('phpdbadmin.data.search_hits', 1000),
                ],
            ]);
        });
    }
}
