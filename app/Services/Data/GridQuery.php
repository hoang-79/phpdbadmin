<?php

namespace App\Services\Data;

use App\Enums\FilterOperator;
use Illuminate\Http\Request;

/**
 * Was das Datenraster zeigen soll (D-01, D-02): Sortierung, geführte Filter, freies WHERE, Suche über die
 * Textspalten, Blättern über den Schlüssel (`after`) oder den Versatz (`offset`). Steht in der Adresse, damit
 * Neuladen und Teilen denselben Ausschnitt zeigen. Spaltennamen prüft erst TableData gegen die echte Tabelle.
 *
 * @phpstan-type Sort array{column: string, direction: 'asc'|'desc'}
 * @phpstan-type Filter array{column: string, operator: FilterOperator, value: string|null, boolean: 'and'|'or'}
 */
final readonly class GridQuery
{
    public const int PAGE_SIZE = 200;

    public const int MAX_FILTERS = 20;

    /**
     * @param  list<Sort>  $sort
     * @param  list<Filter>  $filters
     * @param  list<string|int|float|null>|null  $after  Werte des Primärschlüssels der letzten geladenen Zeile
     */
    public function __construct(
        public array $sort = [],
        public array $filters = [],
        public ?string $where = null,
        public ?string $search = null,
        public ?array $after = null,
        public int $offset = 0,
        public int $limit = self::PAGE_SIZE,
    ) {}

    /**
     * Aus der Adresse: `sort=-created_at,id`, `f[0][c]=status&f[0][o]=eq&f[0][v]=offen&f[0][b]=and`, `where=…`,
     * `q=…`, `after[]=48236`, `offset=400`. Unbrauchbares fällt still weg — die Adresse kann von Hand stammen.
     */
    public static function fromRequest(Request $request): self
    {
        $sort = [];
        foreach (array_filter(explode(',', (string) $request->query('sort', ''))) as $part) {
            $sort[] = str_starts_with($part, '-')
                ? ['column' => substr($part, 1), 'direction' => 'desc']
                : ['column' => $part, 'direction' => 'asc'];
        }

        $filters = [];
        $raw = $request->query('f');
        foreach (is_array($raw) ? array_slice($raw, 0, self::MAX_FILTERS) : [] as $filter) {
            $operator = is_array($filter) ? FilterOperator::tryFrom((string) ($filter['o'] ?? '')) : null;

            if ($operator === null || ! is_string($filter['c'] ?? null) || $filter['c'] === '') {
                continue;
            }

            $filters[] = [
                'column' => $filter['c'],
                'operator' => $operator,
                'value' => $operator->needsValue() ? (string) ($filter['v'] ?? '') : null,
                'boolean' => ($filter['b'] ?? 'and') === 'or' ? 'or' : 'and',
            ];
        }

        $after = $request->query('after');

        return new self(
            sort: array_values(array_filter($sort, fn (array $item): bool => $item['column'] !== '')),
            filters: $filters,
            where: self::text($request->query('where')),
            search: self::text($request->query('q')),
            after: is_array($after) ? array_values(array_map(fn (mixed $value): ?string => is_scalar($value) ? (string) $value : null, $after)) : null,
            offset: max(0, (int) $request->query('offset', '0')),
            limit: min(500, max(1, (int) $request->query('limit', (string) self::PAGE_SIZE))),
        );
    }

    public function isFiltered(): bool
    {
        return $this->filters !== [] || $this->where !== null || $this->search !== null;
    }

    /**
     * Die Adresse dieses Ausschnitts ohne Blättern — für Links, Zählen und die nächste Seite.
     *
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        return array_filter([
            'sort' => implode(',', array_map(fn (array $item): string => ($item['direction'] === 'desc' ? '-' : '').$item['column'], $this->sort)) ?: null,
            'f' => array_map(fn (array $filter): array => array_filter([
                'c' => $filter['column'],
                'o' => $filter['operator']->value,
                'v' => $filter['value'],
                'b' => $filter['boolean'] === 'or' ? 'or' : null,
            ], fn (mixed $value): bool => $value !== null), $this->filters) ?: null,
            'where' => $this->where,
            'q' => $this->search,
        ], fn (mixed $value): bool => $value !== null);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
