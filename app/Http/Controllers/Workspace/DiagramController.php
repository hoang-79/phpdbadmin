<?php

namespace App\Http\Controllers\Workspace;

use App\Connections\ConnectionNotAllowed;
use App\Connections\PasswordRequired;
use App\Models\DatabaseConnection;
use App\Services\Catalog\Catalog;
use App\Services\Catalog\DiagramSvg;
use App\Services\Catalog\RelationDiagram;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Response;
use PDOException;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Beziehungsdiagramm eines Schemas (S-07): Tabellen als Kästen, Fremdschlüssel als Linien, angeordnet auf dem Server;
 * verschieben und zoomen im Browser; Export als eigenständige SVG-Datei mit den Positionen, die die Person
 * eingestellt hat. Ansehen darf, wer die Verbindung öffnen kann.
 */
class DiagramController extends WorkspaceController
{
    public function __construct(Catalog $catalog, private readonly RelationDiagram $diagram, private readonly DiagramSvg $svg)
    {
        parent::__construct($catalog);
    }

    public function diagram(Request $request, DatabaseConnection $connection, string $schema): Response|RedirectResponse
    {
        return $this->guarded($request, $connection, function () use ($request, $connection, $schema): Response {
            [$database, $schemas] = $this->locate($request, $connection, $schema);
            $data = $this->diagram->read($connection, $schema, $database);
            $selection = $this->diagram->selection($data, $this->names($request->query('t')));

            return $this->render($request, $connection, 'workspace/Diagram', $schemas, $schema, $this->catalog->groups($connection, $schema, $database), $database, [
                'diagram' => $this->diagram->arrange($data, $selection),
                'catalog' => $this->diagram->catalog($data),
                'selection' => $selection,
                'limits' => ['allUpTo' => RelationDiagram::ALL_UP_TO, 'max' => RelationDiagram::MAX_TABLES],
            ]);
        });
    }

    /**
     * SVG-Datei der gezeigten Tabellen an den Positionen aus dem Browser — Namen und Spalten kommen neu aus dem
     * Katalog, nie aus der Anfrage.
     */
    public function svg(Request $request, DatabaseConnection $connection, string $schema): HttpResponse|JsonResponse
    {
        $validated = $request->validate([
            'tables' => ['required', 'array', 'max:'.RelationDiagram::MAX_TABLES],
            'tables.*' => ['string', 'max:255'],
            'positions' => ['nullable', 'array', 'max:'.RelationDiagram::MAX_TABLES],
            'positions.*.name' => ['required', 'string', 'max:255'],
            'positions.*.x' => ['required', 'numeric', 'between:-100000,100000'],
            'positions.*.y' => ['required', 'numeric', 'between:-100000,100000'],
        ]);

        try {
            [$database] = $this->locate($request, $connection, $schema);
            $data = $this->diagram->read($connection, $schema, $database);
            $arranged = $this->diagram->arrange($data, $this->diagram->selection($data, $this->names($validated['tables'])));
        } catch (PasswordRequired) {
            return response()->json(['reason' => 'password'], 409);
        } catch (ConnectionNotAllowed $exception) {
            return response()->json(['reason' => 'connection', 'problem' => $exception->problem->toArray()], 503);
        } catch (QueryException|PDOException $exception) {
            if (! $this->isTargetError($exception)) {
                throw $exception;
            }

            return response()->json(['reason' => 'connection', 'problem' => $connection->driver->dialect()->describeConnectionError($exception)->toArray()], 503);
        }

        $positions = [];
        foreach ((array) ($validated['positions'] ?? []) as $position) {
            $positions[(string) $position['name']] = [(int) round((float) $position['x']), (int) round((float) $position['y'])];
        }

        $tables = array_map(fn (array $table): array => isset($positions[$table['name']])
            ? [...$table, 'x' => $positions[$table['name']][0], 'y' => $positions[$table['name']][1]]
            : $table, $arranged['tables']);

        $content = $this->svg->render($tables, $arranged['relations'], $this->titleText($schema), $this->moreText(...));
        $name = $schema.'-'.$this->fileText().'.svg';

        return response($content, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $name, (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name)),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return list<string>|null
     */
    private function names(mixed $names): ?array
    {
        return is_array($names) ? array_values(array_filter($names, 'is_string')) : null;
    }

    /**
     * @return array{0: string|null, 1: list<string>}
     */
    private function locate(Request $request, DatabaseConnection $connection, string $schema): array
    {
        $database = $this->database($request, $connection);
        $schemas = $this->catalog->schemas($connection, $database);
        abort_unless(in_array($schema, $schemas, true), 404);

        return [$database, $schemas];
    }

    private function titleText(string $schema): string
    {
        return __(':schema — Beziehungsdiagramm', ['schema' => $schema]);
    }

    private function fileText(): string
    {
        return __('beziehungen');
    }

    private function moreText(int $count): string
    {
        return trans_choice('… :count weitere Spalte|… :count weitere Spalten', $count, ['count' => $count]);
    }
}
