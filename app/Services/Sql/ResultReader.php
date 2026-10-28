<?php

namespace App\Services\Sql;

use App\Enums\ColumnKind;
use App\Services\Data\Cells;
use Closure;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Liest ein Ergebnis bis zur Zeilengrenze (Q-03) — im Dienst und im SQLite-Arbeitsprozess gleich. Werte wie im Raster
 * (Cells), Spalten mit dem Typ, den der Treiber nennt.
 *
 * @phpstan-import-type Cell from Cells
 */
final class ResultReader
{
    /**
     * @return array{0: list<array{name: string, type: string|null}>, 1: list<list<Cell>>, 2: bool, 3: int|null}
     */
    public static function read(PDOStatement|false $statement, int $limit, ?Closure $stopRest): array
    {
        if ($statement === false || $statement->columnCount() === 0) {
            $affected = $statement === false ? null : $statement->rowCount();

            if ($statement !== false) {
                $statement->closeCursor();
            }

            return [[], [], false, $affected];
        }

        $columns = [];
        for ($index = 0; $index < $statement->columnCount(); $index++) {
            $meta = $statement->getColumnMeta($index) ?: [];
            $columns[] = ['name' => (string) ($meta['name'] ?? $index), 'type' => isset($meta['native_type']) ? strtolower((string) $meta['native_type']) : null];
        }

        $rows = [];
        $truncated = false;

        while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
            if (count($rows) === $limit) {
                $truncated = true;

                break;
            }

            $rows[] = array_map(fn (mixed $value): mixed => Cells::encode($value, ColumnKind::Other), $row);
        }

        if ($truncated && $stopRest !== null) {
            // Ungepuffert müsste MySQL den Rest noch übertragen; die Anweisung wird stattdessen beendet.
            $stopRest();
        }

        try {
            $statement->closeCursor();
        } catch (PDOException) {
            // Nach KILL QUERY meldet MySQL beim Schliessen «Query execution was interrupted».
        }

        return [$columns, $rows, $truncated, null];
    }
}
