<?php

namespace App\Services\Data;

use App\Connections\TargetConnections;
use App\Models\DatabaseConnection;
use Throwable;

/**
 * Suche über alle Tabellen eines Schemas (D-08): nur lesen, in den Textspalten, mit derselben Bedingung wie die Suche
 * im Raster — der Link «im Raster zeigen» trifft genau diese Zeilen. Begrenzt durch eine Zeit für die ganze Suche
 * (jede Abfrage bekommt nur die verbleibende Zeit als Zeitlimit) und durch eine Zahl von Treffern: Ist sie erreicht,
 * bleiben die übrigen Tabellen ungesucht und die Seite sagt es.
 *
 * @phpstan-type TableHits array{table: string, hits: int, more: bool, columns: list<string>}
 * @phpstan-type SearchOutcome array{tables: list<TableHits>, searched: int, total: int, unsearched: list<string>, failed: list<array{table: string, message: string}>, stoppedBy: 'time'|'hits'|null, hits: int, durationMs: int}
 */
final class SchemaSearch
{
    public function __construct(private readonly TargetConnections $targets, private readonly TableData $data) {}

    /**
     * @return SearchOutcome
     */
    public function search(DatabaseConnection $connection, ?string $database, string $schema, string $term): array
    {
        $seconds = max(0, (int) config('phpdbadmin.data.search_seconds', 10));
        $maxHits = max(1, (int) config('phpdbadmin.data.search_hits', 1000));
        $target = $this->targets->open($connection, $database);
        $dialect = $connection->driver->dialect();
        $tables = array_values(array_filter($dialect->tables($target, $schema), fn (array $object): bool => $object['type'] === 'table'));
        $started = hrtime(true);
        $deadline = $started + $seconds * 1_000_000_000;

        $found = [];
        $failed = [];
        $unsearched = [];
        $searched = 0;
        $hits = 0;
        $outOfTime = false;
        $enoughHits = false;

        try {
            foreach ($tables as $object) {
                $name = $object['name'];
                $remainingMs = intdiv($deadline - hrtime(true), 1_000_000);

                $outOfTime = $outOfTime || (! $enoughHits && $remainingMs <= 0);
                $enoughHits = $enoughHits || (! $outOfTime && $hits >= $maxHits);

                if ($outOfTime || $enoughHits) {
                    $unsearched[] = $name;

                    continue;
                }

                $columns = array_values(array_map(
                    fn (array $column): string => $column['name'],
                    array_filter($this->data->columns($connection, $schema, $name, $database) ?? [], fn (array $column): bool => in_array($column['kind'], SearchReplace::KINDS, true)),
                ));

                if ($columns === []) {
                    continue;
                }

                if ($dialect->supportsStatementTimeout()) {
                    $dialect->setStatementTimeout($target, max(100, $remainingMs));
                }

                $searched++;
                $limit = $maxHits - $hits;

                try {
                    $count = (int) $this->data->searchHits($connection, $schema, $name, $term, $limit, $database);
                } catch (GridQueryFailed $failure) {
                    $failed[] = ['table' => $name, 'message' => $failure->reason === 'timeout' ? $this->timeoutText() : $failure->getMessage()];

                    continue;
                }

                if ($count > 0) {
                    $found[] = ['table' => $name, 'hits' => min($count, $limit), 'more' => $count > $limit, 'columns' => $columns];
                    $hits += min($count, $limit);
                }
            }
        } finally {
            if ($dialect->supportsStatementTimeout()) {
                try {
                    $dialect->setStatementTimeout($target, (int) config('phpdbadmin.connections.statement_timeout_ms', 15000));
                } catch (Throwable) {
                    // Verbindung weg — die nächste Anfrage setzt das Zeitlimit ohnehin neu.
                }
            }
        }

        return [
            'tables' => $found,
            'searched' => $searched,
            'total' => count($tables),
            'unsearched' => $unsearched,
            'failed' => $failed,
            'stoppedBy' => $outOfTime ? 'time' : ($enoughHits ? 'hits' : null),
            'hits' => $hits,
            'durationMs' => intdiv(hrtime(true) - $started, 1_000_000),
        ];
    }

    private function timeoutText(): string
    {
        return __('Zeitgrenze erreicht — diese Tabelle ist zu gross für die Suche über alles. Suchen Sie im Raster der Tabelle.');
    }
}
