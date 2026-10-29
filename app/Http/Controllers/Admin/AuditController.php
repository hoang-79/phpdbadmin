<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminPage;
use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Audit\AuditPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Protokoll in der Verwaltung (B-04, Wireframe 14): Filter nach Konto, Verbindung, Art und Zeitraum, Suche in
 * Anweisung und Ziel, Seiten zu 50 Einträgen, Export als CSV. Werte stehen nie darin — nur Platzhalter.
 *
 * @phpstan-type AuditFilters array{account: int|null, connection: string|null, category: string|null, period: string, search: string|null}
 */
class AuditController extends Controller
{
    use AdminPage;

    private const array PERIODS = ['1', '7', '30', '365', 'all'];

    public function __construct(private readonly AuditPresenter $presenter) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $entries = $this->query($filters)
            ->with(['user:id,name', 'connection:id,name,environment,read_only'])
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('admin/Audit', [
            'tabs' => $this->tabs(),
            'filters' => $filters,
            'entries' => [
                'data' => collect($entries->items())->map(fn (AuditEntry $entry): array => $this->presenter->row($entry))->values(),
                'total' => $entries->total(),
                'from' => $entries->firstItem(),
                'to' => $entries->lastItem(),
                'currentPage' => $entries->currentPage(),
                'lastPage' => $entries->lastPage(),
                'previousUrl' => $entries->previousPageUrl(),
                'nextUrl' => $entries->nextPageUrl(),
            ],
            'options' => [
                'accounts' => User::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (User $user): array => ['value' => $user->id, 'label' => $user->name])->values(),
                'connections' => $this->connectionOptions(),
                'categories' => $this->presenter->categories(),
            ],
            'retentionDays' => AuditEntry::retentionDays(),
        ]);
    }

    /** CSV mit denselben Filtern; der Export selbst steht auch im Protokoll. */
    public function export(Request $request, AuditLog $audit): StreamedResponse
    {
        $filters = $this->filters($request);
        $audit->record('audit.exported', $this->actor($request), context: array_filter($filters, fn (mixed $value): bool => $value !== null));

        return response()->streamDownload(function () use ($filters): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fputcsv($output, [__('Zeit (UTC)'), __('Konto'), __('Verbindung'), __('Art'), __('Aktion'), __('Beschreibung'), __('Anweisung'), __('Zeilen'), __('Ergebnis')], escape: '');

            $this->query($filters)
                ->with(['user:id,name', 'connection:id,name'])
                ->lazyById(500)
                ->each(function (AuditEntry $entry) use ($output): void {
                    fputcsv($output, array_map($this->cell(...), [
                        $entry->created_at->utc()->format('Y-m-d H:i:s'),
                        $this->presenter->account($entry),
                        $entry->connection->name ?? $entry->connection_name ?? '',
                        $this->presenter->label($entry->action),
                        $entry->action,
                        $this->presenter->detail($entry) ?? '',
                        $entry->sql ?? '',
                        $entry->affected_rows === null ? '' : (string) $entry->affected_rows,
                        $this->presenter->result($entry),
                    ]), escape: '');
                });

            fclose($output);
        }, 'phpdbadmin-protokoll-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return AuditFilters
     */
    private function filters(Request $request): array
    {
        $request->validate([
            'account' => ['nullable', 'integer'],
            'connection' => ['nullable', 'string', 'max:26'],
            'category' => ['nullable', Rule::in(array_keys(AuditPresenter::CATEGORIES))],
            'period' => ['nullable', Rule::in(self::PERIODS)],
            'search' => ['nullable', 'string', 'max:200'],
        ]);

        return [
            'account' => $request->filled('account') ? $request->integer('account') : null,
            'connection' => $request->filled('connection') ? $request->string('connection')->toString() : null,
            'category' => $request->filled('category') ? $request->string('category')->toString() : null,
            'period' => $request->filled('period') ? $request->string('period')->toString() : '7',
            'search' => $request->filled('search') ? $request->string('search')->trim()->toString() : null,
        ];
    }

    /**
     * @param  AuditFilters  $filters
     * @return Builder<AuditEntry>
     */
    private function query(array $filters): Builder
    {
        return AuditEntry::query()
            ->when($filters['account'], fn (Builder $query, int $account) => $query->where('user_id', $account))
            ->when($filters['connection'], fn (Builder $query, string $connection) => $query->where('connection_id', $connection))
            ->when($filters['category'], fn (Builder $query, string $category) => $query->where(function (Builder $actions) use ($category): void {
                foreach (AuditPresenter::CATEGORIES[$category] as $prefix) {
                    $actions->orWhere('action', 'like', $prefix.'%');
                }
            }))
            ->when($filters['period'] !== 'all', fn (Builder $query) => $query->where('created_at', '>=', now()->subDays((int) $filters['period'])))
            ->when($filters['search'], fn (Builder $query, string $term) => $query->where(fn (Builder $text) => $text
                ->whereLike('sql', '%'.$term.'%')
                ->orWhereLike('target', '%'.$term.'%')
                ->orWhereLike('connection_name', '%'.$term.'%')));
    }

    /**
     * Verbindungen, die im Protokoll vorkommen — auch entfernte, mit ihrem letzten Namen.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function connectionOptions(): array
    {
        return AuditEntry::query()
            ->whereNotNull('connection_id')
            ->groupBy('connection_id')
            ->selectRaw('connection_id, max(connection_name) as connection_name')
            ->with('connection:id,name')
            ->get()
            ->map(fn (AuditEntry $entry): array => [
                'value' => (string) $entry->connection_id,
                'label' => (string) ($entry->connection->name ?? $entry->connection_name ?? $entry->connection_id),
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /** Tabellenprogramme führen Zellen mit = + - @ als Formel aus — solche Zellen beginnen mit einem Apostroph. */
    private function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
