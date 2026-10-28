<?php

namespace App\Services\Data;

use App\Connections\TargetConnections;
use App\Enums\ColumnKind;
use App\Models\DatabaseConnection;
use Illuminate\Database\QueryException;

/**
 * Eine Zeile im Detail (Wireframe 07): alle Werte ungekürzt bis zu einer Grenze, dazu die Verweise in beide
 * Richtungen (D-03) — worauf die Zeile zeigt und wie viele Zeilen anderer Tabellen auf sie zeigen.
 *
 * @phpstan-import-type Cell from Cells
 */
final class RowDetail
{
    public function __construct(private readonly TargetConnections $targets, private readonly TableData $data) {}

    /**
     * @param  list<string|null>  $key  Werte des Primärschlüssels in seiner Reihenfolge
     * @return array{values: list<Cell>, outgoing: list<array{column: string, table: string, foreignColumn: string, value: string, label: string|null}>, incoming: list<array{name: string, table: string, columns: list<string>, values: list<string|null>, count: int|null}>}|null
     */
    public function describe(DatabaseConnection $connection, string $schema, string $object, array $key, ?string $database = null): ?array
    {
        $columns = $this->data->columns($connection, $schema, $object, $database);
        $primaryKey = array_values(array_map(fn (array $column): string => $column['name'], array_filter($columns ?? [], fn (array $column): bool => $column['primary'])));

        if ($columns === null || $primaryKey === [] || count($primaryKey) !== count($key)) {
            return null;
        }

        $target = $this->targets->open($connection, $database);
        $dialect = $connection->driver->dialect();

        $record = $dialect->runReadOnly($target, function () use ($target, $schema, $object, $primaryKey, $key): ?array {
            $query = $target->table($schema.'.'.$object);
            foreach ($primaryKey as $position => $column) {
                $query->where($column, '=', $key[$position]);
            }
            $row = $query->first();

            return $row === null ? null : (array) $row;
        });

        if ($record === null) {
            return null;
        }

        $values = array_map(
            fn (array $column): mixed => Cells::encode($record[$column['name']] ?? null, ColumnKind::from($column['kind']), Cells::DETAIL_TEXT),
            $columns,
        );

        $outgoing = [];
        foreach ($columns as $column) {
            $value = Cells::key($record[$column['name']] ?? null);

            if ($column['references'] === null || $value === null) {
                continue;
            }

            $outgoing[] = [
                'column' => $column['name'],
                'table' => $column['references']['table'],
                'foreignColumn' => $column['references']['column'],
                'value' => $value,
                'label' => $this->label($connection, $schema, $column['references']['table'], $column['references']['column'], $value, $database),
            ];
        }

        $incoming = [];
        foreach ($dialect->referencingForeignKeys($target, $schema, $object) as $reference) {
            $referencedValues = array_map(fn (string $column): ?string => Cells::key($record[$column] ?? null), $reference['foreignColumns']);

            try {
                $count = in_array(null, $referencedValues, true) ? 0 : $dialect->runReadOnly($target, function () use ($target, $schema, $reference, $referencedValues): int {
                    $query = $target->table($schema.'.'.$reference['table']);
                    foreach ($reference['columns'] as $position => $column) {
                        $query->where($column, '=', $referencedValues[$position]);
                    }

                    return $query->count();
                });
            } catch (QueryException) {
                // Zeitlimit auf einer grossen Tabelle ohne Index: Verweis zeigen, Anzahl offen.
                $count = null;
            }

            $incoming[] = [
                'name' => $reference['name'],
                'table' => $reference['table'],
                'columns' => $reference['columns'],
                'values' => $referencedValues,
                'count' => $count,
            ];
        }

        return ['values' => $values, 'outgoing' => $outgoing, 'incoming' => $incoming];
    }

    private function label(DatabaseConnection $connection, string $schema, string $table, string $column, string $value, ?string $database): ?string
    {
        $target = $this->targets->open($connection, $database);
        $labelColumn = $this->data->labelColumn($target, $schema, $table, $column);

        if ($labelColumn === null) {
            return null;
        }

        try {
            $label = $connection->driver->dialect()->runReadOnly($target, fn (): mixed => $target->table($schema.'.'.$table)->where($column, '=', $value)->value($labelColumn));
        } catch (QueryException) {
            return null;
        }

        return is_scalar($label) && $label !== '' && mb_check_encoding((string) $label, 'UTF-8') ? mb_strimwidth((string) $label, 0, 80, '…') : null;
    }
}
