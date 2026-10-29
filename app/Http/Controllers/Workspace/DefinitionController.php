<?php

namespace App\Http\Controllers\Workspace;

use App\Connections\ConnectionNotAllowed;
use App\Connections\PasswordRequired;
use App\Connections\ReadOnlyConnection;
use App\Connections\TargetConnections;
use App\Connections\WriteUnlock;
use App\Dialects\Objects\ObjectDefinition;
use App\Dialects\Objects\ObjectDialects;
use App\Enums\ConnectionEnvironment;
use App\Enums\ObjectKind;
use App\Http\Requests\Workspace\ObjectChangeRequest;
use App\Models\DatabaseConnection;
use App\Services\Catalog\Catalog;
use App\Services\Catalog\Structure;
use App\Services\Objects\DefinitionRejected;
use App\Services\Objects\ObjectChangeFailed;
use App\Services\Objects\ObjectChanges;
use App\Services\Objects\ObjectPlan;
use App\Services\Objects\ObjectPlans;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PDOException;

/**
 * Objekte eines Schemas neben den Tabellen (S-06): Sichten, Routinen, Trigger, Ereignisse, Sequenzen und Typen —
 * Detailseite mit Definition (nur lesen, kopierbar, «Im Editor öffnen»), Anlegen, Ändern und Löschen mit Vorschau
 * (JSON), Bestätigung (Produktion: Name der Verbindung) und genau einem Eintrag im Protokoll. Ansehen darf, wer die
 * Verbindung öffnen kann; ändern nur mit «schreiben» und ohne Schreibschutz.
 */
class DefinitionController extends WorkspaceController
{
    public function __construct(
        Catalog $catalog,
        private readonly TargetConnections $targets,
        private readonly Structure $structure,
        private readonly ObjectPlans $plans,
        private readonly ObjectChanges $changes,
        private readonly WriteUnlock $unlock,
    ) {
        parent::__construct($catalog);
    }

    public function detail(Request $request, DatabaseConnection $connection, string $schema, string $kind, string $name): Response|RedirectResponse
    {
        $objectKind = $this->kind($connection, $kind);

        return $this->guarded($request, $connection, function () use ($request, $connection, $schema, $objectKind, $name): Response {
            [$database, $schemas] = $this->locate($request, $connection, $schema);
            $qualifier = $request->query('qualifier');
            $object = ObjectDialects::for($connection->driver)->describe(
                $this->targets->open($connection, $database),
                $schema,
                $objectKind,
                $name,
                is_string($qualifier) && $qualifier !== '' ? $qualifier : null,
            );
            abort_if($object === null, 404);

            $isView = $objectKind === ObjectKind::View;

            return $this->page($request, $connection, $schemas, $schema, $database, $objectKind, [
                // Sichten sind auch ein Reiter wie Tabellen (Daten · Struktur · Definition); die übrigen Arten nicht.
                'selected' => $isView ? $object->name : null,
                'current' => ['kind' => $objectKind->value, 'name' => $object->name],
                'definition' => $object->toArray(),
                'template' => null,
                'object' => $isView ? $this->structure->summary($connection, $schema, $object->name, $database) : null,
            ]);
        });
    }

    public function create(Request $request, DatabaseConnection $connection, string $schema, string $kind): Response|RedirectResponse
    {
        $objectKind = $this->kind($connection, $kind);

        return $this->guarded($request, $connection, function () use ($request, $connection, $schema, $objectKind): Response {
            [$database, $schemas] = $this->locate($request, $connection, $schema);

            return $this->page($request, $connection, $schemas, $schema, $database, $objectKind, [
                'selected' => null,
                'current' => null,
                'definition' => null,
                'template' => ObjectDialects::for($connection->driver)->template($objectKind, $schema),
                'object' => null,
            ]);
        });
    }

    /**
     * Vorschau: genau die Anweisungen, die laufen werden — geprüft wie beim Anwenden, ausgeführt wird nichts.
     *
     * @throws ValidationException
     */
    public function preview(ObjectChangeRequest $request, DatabaseConnection $connection, string $schema, string $kind): JsonResponse
    {
        $objectKind = $this->kind($connection, $kind);

        try {
            [$database] = $this->locate($request, $connection, $schema);
            $plan = $this->changes->preview($connection, fn (Connection $target): ObjectPlan => $this->plan($target, $request, $connection, $objectKind, $schema), $database);
        } catch (DefinitionRejected $rejected) {
            throw ValidationException::withMessages([$rejected->field => $rejected->getMessage()]);
        } catch (PasswordRequired|ConnectionNotAllowed|QueryException|PDOException $exception) {
            throw ValidationException::withMessages(['change' => $this->problemText($connection, $exception)]);
        }

        return response()->json($plan->preview());
    }

    public function apply(ObjectChangeRequest $request, DatabaseConnection $connection, string $schema, string $kind): RedirectResponse
    {
        $objectKind = $this->kind($connection, $kind);
        $database = null;

        try {
            [$database] = $this->locate($request, $connection, $schema);
            $plan = $this->changes->apply($connection, $this->user($request), fn (Connection $target): ObjectPlan => $this->plan($target, $request, $connection, $objectKind, $schema), $database);
        } catch (DefinitionRejected $rejected) {
            return back()->withErrors([$rejected->field => $rejected->getMessage()]);
        } catch (ObjectChangeFailed $failure) {
            if ($failure->restored === false) {
                Inertia::flash('toast', ['type' => 'error', 'message' => $this->lostText($failure->getMessage())]);

                return to_route('workspace.schema', array_filter([$connection, $schema, 'db' => $database], fn ($value) => $value !== null));
            }

            return back()->withErrors(['change' => $failure->restored === true ? $this->restoredText($failure->getMessage()) : $failure->getMessage()]);
        } catch (ReadOnlyConnection) {
            abort(403, $this->readOnlyText());
        } catch (PasswordRequired|ConnectionNotAllowed|QueryException|PDOException $exception) {
            return back()->withErrors(['change' => $this->problemText($connection, $exception)]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->doneText($plan)]);

        if ($plan->action === 'object.drop') {
            return to_route('workspace.schema', array_filter([$connection, $schema, 'db' => $database], fn ($value) => $value !== null));
        }

        return to_route('workspace.definition', array_filter([
            $connection, $schema, $plan->kind->value, $plan->name,
            'db' => $database,
            'qualifier' => $plan->kind === ObjectKind::Trigger ? $plan->qualifier : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * @param  list<string>  $schemas
     * @param  array<string, mixed>  $props
     */
    private function page(Request $request, DatabaseConnection $connection, array $schemas, string $schema, ?string $database, ObjectKind $kind, array $props): Response
    {
        $dialect = ObjectDialects::for($connection->driver);
        $mayWrite = Gate::forUser($this->user($request))->allows('write', $connection);
        $writable = ! $connection->read_only || $this->unlock->isActive($connection);

        return $this->render($request, $connection, 'workspace/Definition', $schemas, $schema, $this->catalog->groups($connection, $schema, $database), $database, [
            ...$props,
            'kind' => $kind->value,
            'kinds' => array_map(fn (ObjectKind $known): string => $known->value, $dialect->kinds()),
            'changes' => [
                'canChange' => $mayWrite && $writable,
                'blocked' => match (true) {
                    ! $mayWrite => 'right',
                    ! $writable => 'read_only',
                    default => null,
                },
                'confirmName' => $connection->environment === ConnectionEnvironment::Production ? $connection->name : null,
                'replacesInPlace' => $dialect->replacesInPlace($kind),
                'transactional' => $dialect->transactionalDdl(),
            ],
        ]);
    }

    private function plan(Connection $target, ObjectChangeRequest $request, DatabaseConnection $connection, ObjectKind $kind, string $schema): ObjectPlan
    {
        $driver = $connection->driver;

        return match ($request->action()) {
            'create' => $this->plans->create($target, $driver, $kind, $schema, (string) $request->validated('definition')),
            'alter' => $this->plans->alter($driver, $this->existing($target, $request, $connection, $kind, $schema), (string) $request->validated('definition')),
            'drop' => $this->plans->drop($driver, $this->existing($target, $request, $connection, $kind, $schema)),
        };
    }

    private function existing(Connection $target, ObjectChangeRequest $request, DatabaseConnection $connection, ObjectKind $kind, string $schema): ObjectDefinition
    {
        $object = ObjectDialects::for($connection->driver)->describe($target, $schema, $kind, (string) $request->validated('name'), $request->qualifier());

        if ($object === null) {
            throw new DefinitionRejected($this->missingText(), 'name');
        }

        return $object;
    }

    /** Art aus der Adresse, nur wenn der Treiber sie kennt — sonst 404. */
    private function kind(DatabaseConnection $connection, string $kind): ObjectKind
    {
        $objectKind = ObjectKind::tryFrom($kind);
        abort_unless($objectKind !== null && in_array($objectKind, ObjectDialects::for($connection->driver)->kinds(), true), 404);

        return $objectKind;
    }

    /**
     * Datenbank aus der Adresse und die Schemas der Verbindung; ein unbekanntes Schema ist 404.
     *
     * @return array{0: string|null, 1: list<string>}
     */
    private function locate(Request $request, DatabaseConnection $connection, string $schema): array
    {
        $database = $this->database($request, $connection);
        $schemas = $this->catalog->schemas($connection, $database);
        abort_unless(in_array($schema, $schemas, true), 404);

        return [$database, $schemas];
    }

    private function problemText(DatabaseConnection $connection, PasswordRequired|ConnectionNotAllowed|QueryException|PDOException $exception): string
    {
        return match (true) {
            $exception instanceof PasswordRequired => __('Für diese Verbindung fehlt in dieser Sitzung das Passwort.'),
            $exception instanceof ConnectionNotAllowed => $exception->problem->title(),
            default => $connection->driver->dialect()->describeConnectionError($exception)->title(),
        };
    }

    private function doneText(ObjectPlan $plan): string
    {
        return match ($plan->action) {
            'object.create' => __('«:name» angelegt.', ['name' => $plan->name]),
            'object.alter' => __('«:name» geändert.', ['name' => $plan->name]),
            'object.drop' => __('«:name» gelöscht.', ['name' => $plan->name]),
        };
    }

    private function restoredText(string $message): string
    {
        return __(':message — die bisherige Definition ist wiederhergestellt.', ['message' => $message]);
    }

    private function lostText(string $message): string
    {
        return __(':message — die bisherige Definition liess sich nicht wiederherstellen.', ['message' => $message]);
    }

    private function missingText(): string
    {
        return __('Dieses Objekt gibt es nicht mehr — laden Sie die Seite neu.');
    }

    private function readOnlyText(): string
    {
        return __('Diese Verbindung ist schreibgeschützt. Heben Sie den Schutz auf Zeit auf, um zu ändern.');
    }
}
