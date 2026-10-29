<?php

namespace App\Services\Sql;

use App\Connections\TargetConnections;
use App\Connections\WriteUnlock;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Catalog\Catalog;
use App\Services\Data\TableData;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Ist ein Ergebnis des SQL-Editors bearbeitbar (Q-05)? Ja, wenn die Abfrage genau eine Tabelle liest
 * (SingleTableSelect), die Tabelle einen Primärschlüssel hat, das Ergebnis ihn vollständig als Spalten der Tabelle
 * enthält und die Angaben des Treibers (Tabelle je Spalte aus `getColumnMeta`) dazu passen. Im Zweifel nur lesen —
 * mit dem Grund. Bearbeitet wird danach wie im Raster über den RowEditor (Vorschau, eine Transaktion, Rückgängig).
 *
 * @phpstan-import-type GridColumn from TableData
 * @phpstan-import-type Result from QueryRunner
 *
 * @phpstan-type ResultSource array{editable: bool, reason: string|null, schema: string|null, table: string|null, keyColumns: list<int>, columns: list<GridColumn|null>}
 */
final class EditableResult
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly TableData $data,
        private readonly TargetConnections $targets,
        private readonly WriteUnlock $unlock,
    ) {}

    /**
     * Herkunft eines Ergebnisses — null für Anweisungen ohne Zeilen und für alles, was kein SELECT ist.
     *
     * @param  Result  $result
     * @param  string|null  $schema  im Editor gewähltes Schema (MySQL/MariaDB: Datenbank)
     * @return ResultSource|null
     */
    public function describe(DatabaseConnection $connection, User $user, ?string $database, ?string $schema, array $result): ?array
    {
        if ($result['status'] !== 'ok' || $result['columns'] === []) {
            return null;
        }

        $parsed = SingleTableSelect::parse($result['sql'], $connection->driver);

        if ($parsed === SingleTableSelect::NOT_SELECT) {
            return null;
        }

        if (is_string($parsed)) {
            return $this->readOnly($this->explain($parsed));
        }

        try {
            return $this->match($connection, $user, $database, $schema, $parsed, $result);
        } catch (Throwable $exception) {
            report($exception);

            return $this->readOnly(__('Die Herkunft des Ergebnisses liess sich nicht prüfen — es bleibt schreibgeschützt.'));
        }
    }

    /**
     * @param  Result  $result
     * @return ResultSource
     */
    private function match(DatabaseConnection $connection, User $user, ?string $database, ?string $editorSchema, SingleTableSelect $parsed, array $result): array
    {
        $resolved = $this->resolve($connection, $database, $editorSchema, $parsed);

        if ($resolved === null) {
            return $this->readOnly(__('Die Tabelle «:table» liess sich im Katalog nicht finden.', ['table' => $parsed->table]));
        }

        [$schema, $table, $type] = $resolved;

        if ($type !== 'table') {
            return $this->readOnly($this->withoutKeyText(), $schema, $table);
        }

        $columns = $this->data->columns($connection, $schema, $table, $database) ?? [];
        $primaryKey = array_values(array_map(fn (array $column): string => $column['name'], array_filter($columns, fn (array $column): bool => $column['primary'])));

        if ($primaryKey === []) {
            return $this->readOnly($this->withoutKeyText(), $schema, $table);
        }

        // Spalten des Ergebnisses der Reihe nach: Stern = alle Spalten der Tabelle, sonst eine je Eintrag.
        $expected = [];
        foreach ($parsed->items as $item) {
            if ($item['kind'] === 'star') {
                foreach ($columns as $column) {
                    $expected[] = ['column' => $column, 'name' => $column['name']];
                }

                continue;
            }

            if ($item['kind'] === 'expression') {
                $expected[] = ['column' => null, 'name' => null];

                continue;
            }

            $qualifierFits = $item['qualifier'] === null || $this->sameName($connection->driver, $item['qualifier'], $item['qualifierQuoted'], $parsed->alias ?? $parsed->table);
            $column = $qualifierFits ? $this->findColumn($connection->driver, $columns, $item['name'], $item['quoted']) : null;
            $expected[] = ['column' => $column, 'name' => $item['alias'] ?? $item['name']];
        }

        if (count($expected) !== count($result['columns'])) {
            return $this->mismatch($schema, $table);
        }

        // Treiber fragen: Von welcher Tabelle stammt jede Spalte? (MySQL/MariaDB nennen den Alias.)
        $metaTable = in_array($connection->driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true) ? ($parsed->alias ?? $parsed->table) : $table;

        foreach ($result['columns'] as $index => $column) {
            if ($expected[$index]['column'] === null) {
                continue;
            }

            if (strcasecmp($column['name'], (string) $expected[$index]['name']) !== 0) {
                return $this->mismatch($schema, $table);
            }

            $source = $column['table'] ?? null;

            if ($source === '') {
                // Der Treiber sagt «berechnet» (etwa current_user ohne Klammern) — diese Spalte nicht bearbeiten.
                $expected[$index]['column'] = null;
            } elseif ($source !== null && strcasecmp($source, $metaTable) !== 0) {
                return $this->mismatch($schema, $table);
            }
        }

        $keyColumns = [];
        $missing = [];
        foreach ($primaryKey as $name) {
            $position = array_find_key($expected, fn (array $entry): bool => ($entry['column']['name'] ?? null) === $name);

            if ($position === null) {
                $missing[] = $name;
            } else {
                $keyColumns[] = (int) $position;
            }
        }

        if ($missing !== []) {
            return $this->readOnly(__('Das Ergebnis enthält den Primärschlüssel nicht vollständig (es fehlt: :columns). Nehmen Sie ihn in die Abfrage auf, um Zellen zu bearbeiten.', ['columns' => implode(', ', $missing)]), $schema, $table);
        }

        foreach ($result['rows'] as $row) {
            foreach ($keyColumns as $position) {
                if (! is_scalar($row[$position] ?? null)) {
                    return $this->readOnly(__('Der Primärschlüssel einer Zeile ist leer, binär oder zu lang für eine sichere Zuordnung.'), $schema, $table);
                }
            }
        }

        // Bearbeitbar: jede Spalte der Tabelle beim ersten Vorkommen, nicht der Schlüssel, keine Binärdaten.
        $seen = [];
        $editable = [];
        foreach ($expected as $entry) {
            $column = $entry['column'];
            $usable = $column !== null && ! $column['primary'] && $column['kind'] !== 'binary' && ! isset($seen[$column['name']]);
            $editable[] = $usable ? $column : null;

            if ($column !== null) {
                $seen[$column['name']] = true;
            }
        }

        $reason = match (true) {
            ! Gate::forUser($user)->allows('write', $connection) => $this->readerText(),
            $connection->read_only && ! $this->unlock->isActive($connection) => $this->readOnlyText(),
            array_filter($editable) === [] => $this->nothingEditableText(),
            default => null,
        };

        return [
            'editable' => $reason === null,
            'reason' => $reason,
            'schema' => $schema,
            'table' => $table,
            'keyColumns' => $keyColumns,
            'columns' => $editable,
        ];
    }

    /**
     * Schema und Tabelle so, wie der Katalog sie schreibt. Ohne Schema: MySQL/MariaDB die Datenbank der Abfrage,
     * PostgreSQL der Suchpfad des Editors (gewähltes Schema, dann public), SQLite «main».
     *
     * @return array{0: string, 1: string, 2: 'table'|'view'}|null
     */
    private function resolve(DatabaseConnection $connection, ?string $database, ?string $editorSchema, SingleTableSelect $parsed): ?array
    {
        $driver = $connection->driver;
        $schemas = $this->catalog->schemas($connection, $database);

        if ($parsed->schema !== null) {
            $found = $this->findName($driver, $schemas, $parsed->schema, $parsed->schemaQuoted);
            $candidates = $found === null ? [] : [$found];
        } else {
            $candidates = match ($driver) {
                DatabaseDriver::MySql, DatabaseDriver::MariaDb => [$connection->database ?? $editorSchema],
                DatabaseDriver::Postgres => [$editorSchema, 'public'],
                DatabaseDriver::Sqlite => ['main'],
            };
        }

        $target = $this->targets->open($connection, $database);

        foreach (array_unique(array_filter($candidates, fn (?string $schema): bool => $schema !== null && in_array($schema, $schemas, true))) as $schema) {
            $objects = array_column($driver->dialect()->tables($target, $schema), 'type', 'name');
            $name = $this->findName($driver, array_map(strval(...), array_keys($objects)), $parsed->table, $parsed->tableQuoted);

            if ($name !== null) {
                return [$schema, $name, $objects[$name]];
            }
        }

        return null;
    }

    /**
     * Name aus einer Liste: genau, sonst ohne Rücksicht auf Gross- und Kleinschreibung, wenn ungequotet und
     * eindeutig. PostgreSQL faltet ungequotete Namen klein.
     *
     * @param  list<string>  $names
     */
    private function findName(DatabaseDriver $driver, array $names, string $name, bool $quoted): ?string
    {
        if ($driver === DatabaseDriver::Postgres && ! $quoted) {
            $name = mb_strtolower($name);
        }

        if (in_array($name, $names, true)) {
            return $name;
        }

        if ($quoted || $driver === DatabaseDriver::Postgres) {
            return null;
        }

        $matches = array_values(array_filter($names, fn (string $candidate): bool => mb_strtolower($candidate) === mb_strtolower($name)));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @param  list<GridColumn>  $columns
     * @return GridColumn|null
     */
    private function findColumn(DatabaseDriver $driver, array $columns, string $name, bool $quoted): ?array
    {
        $found = $this->findName($driver, array_column($columns, 'name'), $name, $quoted);

        return $found === null ? null : (array_find($columns, fn (array $column): bool => $column['name'] === $found));
    }

    private function sameName(DatabaseDriver $driver, string $written, bool $quoted, string $actual): bool
    {
        return $this->findName($driver, [$actual], $written, $quoted) !== null;
    }

    private function withoutKeyText(): string
    {
        return __('Sichten und Tabellen ohne Primärschlüssel lassen sich nur lesen.');
    }

    private function readerText(): string
    {
        return __('Ihr Konto darf über diese Verbindung nur lesen.');
    }

    private function readOnlyText(): string
    {
        return __('Diese Verbindung ist schreibgeschützt — heben Sie den Schutz auf Zeit auf, um zu ändern.');
    }

    private function nothingEditableText(): string
    {
        return __('Keine Spalte des Ergebnisses lässt sich bearbeiten — nur Schlüssel, Ausdrücke oder Binärdaten.');
    }

    private function explain(string $reason): string
    {
        return match ($reason) {
            SingleTableSelect::NO_TABLE => __('Die Abfrage liest keine Tabelle.'),
            SingleTableSelect::SEVERAL_TABLES => __('Die Abfrage liest mehrere Tabellen (Join) — bearbeiten lässt sich nur das Ergebnis einer Tabelle.'),
            SingleTableSelect::GROUPED => __('Gruppierte Zeilen (GROUP BY, HAVING) gehören zu keiner einzelnen Zeile der Tabelle.'),
            SingleTableSelect::DISTINCT => __('DISTINCT fasst Zeilen zusammen — einzelne Zeilen lassen sich nicht zuordnen.'),
            SingleTableSelect::SET_OPERATION => __('UNION, INTERSECT und EXCEPT mischen Ergebnisse — einzelne Zeilen lassen sich nicht zuordnen.'),
            default => __('Diese Form der Abfrage erkennt phpdbadmin nicht sicher als Abfrage einer Tabelle — das Ergebnis bleibt schreibgeschützt.'),
        };
    }

    /**
     * @return ResultSource
     */
    private function mismatch(string $schema, string $table): array
    {
        return $this->readOnly(__('Die Spalten des Ergebnisses passen nicht eindeutig zur Tabelle «:table» — das Ergebnis bleibt schreibgeschützt.', ['table' => $table]), $schema, $table);
    }

    /**
     * @return ResultSource
     */
    private function readOnly(string $reason, ?string $schema = null, ?string $table = null): array
    {
        return ['editable' => false, 'reason' => $reason, 'schema' => $schema, 'table' => $table, 'keyColumns' => [], 'columns' => []];
    }
}
