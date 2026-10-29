<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\SchemaOverview;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Einstellungen der Schema-Übersicht speichern — die Oberfläche schickt sie bei jeder Änderung von selbst. Der
 * Filter gilt nur für das genannte Schema; Verbindung, Datenbank und Schema dienen dabei nur als Schlüssel im
 * eigenen Konto, gelesen wird dort nichts.
 */
class SchemaOverviewPreferenceController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'view' => ['required', Rule::in(SchemaOverview::VIEWS)],
            'sort' => ['required', Rule::in(SchemaOverview::SORTS)],
            'direction' => ['required', Rule::in(SchemaOverview::DIRECTIONS)],
            'perPage' => ['required', 'integer', Rule::in(SchemaOverview::PER_PAGE)],
            'kind' => ['required', Rule::in(SchemaOverview::KINDS)],
            'connection' => ['required', 'string', 'ulid'],
            'database' => ['nullable', 'string', 'max:128'],
            'schema' => ['required', 'string', 'max:128'],
            'filter' => ['nullable', 'string', 'max:'.SchemaOverview::FILTER_LENGTH],
        ]);

        SchemaOverview::save(
            $user,
            [
                'view' => (string) $validated['view'],
                'sort' => (string) $validated['sort'],
                'direction' => (string) $validated['direction'],
                'perPage' => (int) $validated['perPage'],
                'kind' => (string) $validated['kind'],
            ],
            (string) $validated['connection'],
            isset($validated['database']) ? (string) $validated['database'] : null,
            (string) $validated['schema'],
            trim((string) ($validated['filter'] ?? '')),
        );

        return response()->noContent();
    }
}
