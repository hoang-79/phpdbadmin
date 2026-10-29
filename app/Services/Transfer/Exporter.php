<?php

namespace App\Services\Transfer;

use App\Connections\TargetConnections;
use App\Dialects\Dialect;
use App\Enums\DatabaseDriver;
use App\Enums\TransferFormat;
use App\Models\Transfer;
use App\Services\Transfer\Writers\CsvWriter;
use App\Services\Transfer\Writers\ExportWriter;
use App\Services\Transfer\Writers\JsonWriter;
use App\Services\Transfer\Writers\SqlWriter;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Export einer Tabelle (Sicht) oder eines ganzen Schemas als CSV, JSON oder SQL (E-01, ADR-007): gelesen in Paketen
 * im Schreibschutz-Wächter, geschrieben als Strom in `storage/app/private/transfers/<Auftrag>/`. Der Wächter hält
 * alles in einer Nur-lesen-Transaktion — auf MySQL, MariaDB und SQLite sieht der Export einen Stand.
 *
 * @phpstan-import-type TableSummary from Dialect
 */
final class Exporter
{
    public function __construct(private readonly TargetConnections $targets, private readonly TableReader $reader) {}

    /**
     * Tabellen und Sichten, die der Auftrag umfasst; leer, wenn die gewählte Tabelle fehlt.
     *
     * @return list<TableSummary>
     */
    public function objects(Connection $target, Dialect $dialect, string $schema, ?string $table): array
    {
        $all = $dialect->tables($target, $schema);

        return array_values(array_filter($all, fn (array $object): bool => $table === null || $object['name'] === $table));
    }

    /**
     * Geschätzte Zeilen der Auswahl — Statistik des Servers, sonst gezählt (SQLite kennt keine Schätzung, Sichten
     * auch nicht; gezählt wird unter dem Zeitlimit der Verbindung).
     *
     * @param  list<TableSummary>  $objects
     */
    public function estimateRows(Connection $target, Dialect $dialect, string $schema, array $objects): int
    {
        return $dialect->runReadOnly($target, function () use ($target, $schema, $objects): int {
            $total = 0;

            foreach ($objects as $object) {
                $total += $object['rows'] ?? $target->table($schema.'.'.$object['name'])->count();
            }

            return $total;
        });
    }

    /**
     * @return array{tables: int, views: int}
     *
     * @throws TransferCancelled
     * @throws TransferFailed
     */
    public function run(Transfer $transfer, TransferProgress $progress): array
    {
        $connection = $transfer->databaseConnection;
        $target = $this->targets->open($connection, $transfer->openDatabase());
        $dialect = $connection->driver->dialect();
        $schema = (string) $transfer->option('schema');
        $table = $transfer->option('scope') === 'table' ? $transfer->option('table') : null;
        $objects = $this->objects($target, $dialect, $schema, $table);

        if ($objects === []) {
            throw new TransferFailed(__('Die Tabelle «:table» gibt es nicht mehr.', locale: 'de'), ['table' => (string) $table]);
        }

        $total = $this->estimateRows($target, $dialect, $schema, $this->withRows($transfer, $objects));
        $transfer->forceFill(['total_rows' => $total])->save();

        $disk = Storage::disk(Transfer::DISK);
        $disk->makeDirectory($transfer->directory());
        $path = $transfer->directory().'/'.$transfer->file_name;
        $stream = fopen($disk->path($path), 'wb');

        if ($stream === false) {
            throw new RuntimeException('Export file not writable');
        }

        $rows = 0;
        $tables = 0;
        $views = 0;

        try {
            $dialect->runReadOnly($target, function () use ($transfer, $target, $connection, $schema, $objects, $stream, $progress, $total, &$rows, &$tables, &$views): void {
                $writer = $this->writer($transfer, $stream, $target, $connection->driver, count($objects) > 1);
                $writer->begin();

                foreach ($this->ordered($target, $schema, $objects) as $object) {
                    $table = ExportTable::describe($target, $connection->driver, $schema, $object['name'], $object['type'] === 'view');
                    if ($table->isView) {
                        $views++;
                    } else {
                        $tables++;
                    }
                    $writer->beginTable($table);

                    if ($writer->wantsRows($table)) {
                        $columns = array_map(fn (ColumnInfo $column): string => $column->name, $table->dataColumns());

                        foreach ($this->reader->rows($target, $connection->driver, $schema, $table->name, $columns, $table->primaryKey) as $row) {
                            $writer->row($row);
                            $rows++;
                            $progress->report($rows, $total > 0 ? $rows / $total : null);
                        }
                    }

                    $writer->endTable();
                    $progress->report($rows, $total > 0 ? $rows / $total : null);
                }

                $writer->finish();
            });
        } finally {
            fclose($stream);
        }

        $transfer->forceFill(['file_path' => $path, 'file_size' => $disk->size($path), 'rows' => $rows])->save();

        return ['tables' => $tables, 'views' => $views];
    }

    /**
     * Objekte, deren Zeilen gelesen werden (für die Schätzung): beim SQL-Export ohne Daten keine, Sichten nur bei
     * CSV und JSON.
     *
     * @param  list<TableSummary>  $objects
     * @return list<TableSummary>
     */
    public function withRows(Transfer $transfer, array $objects): array
    {
        if ($transfer->format !== TransferFormat::Sql) {
            return $objects;
        }

        if ($transfer->option('content', 'both') === 'structure') {
            return [];
        }

        return array_values(array_filter($objects, fn (array $object): bool => $object['type'] === 'table'));
    }

    /**
     * @param  resource  $stream
     */
    private function writer(Transfer $transfer, $stream, Connection $target, DatabaseDriver $driver, bool $multiple): ExportWriter
    {
        return match ($transfer->format) {
            TransferFormat::Csv => new CsvWriter(
                $stream,
                delimiter: $transfer->option('delimiter', ',') ?? ',',
                bom: $transfer->flag('bom'),
                header: $transfer->flag('header', true),
                skipBinary: $transfer->option('binary') === 'skip',
            ),
            TransferFormat::Json => new JsonWriter($stream, keyedByTable: $multiple),
            TransferFormat::Sql => new SqlWriter(
                $stream,
                $target,
                $driver,
                structure: $transfer->option('content', 'both') !== 'data',
                data: $transfer->option('content', 'both') !== 'structure',
                drop: $transfer->flag('drop'),
                header: implode(' · ', array_filter([
                    'phpdbadmin',
                    trim($driver->label().' '.$transfer->databaseConnection->server_version),
                    $transfer->option('schema'),
                    now()->utc()->format('Y-m-d H:i').' UTC',
                ])),
            ),
        };
    }

    /**
     * Tabellen so, dass jede nach den Tabellen kommt, auf die ihre Fremdschlüssel zeigen (Daten ohne verletzte
     * Verweise); bei Kreisen der Rest nach Namen. Sichten zuletzt.
     *
     * @param  list<TableSummary>  $objects
     * @return list<TableSummary>
     */
    private function ordered(Connection $target, string $schema, array $objects): array
    {
        if (count($objects) < 2) {
            return $objects;
        }

        $tables = array_values(array_filter($objects, fn (array $object): bool => $object['type'] === 'table'));
        $views = array_values(array_filter($objects, fn (array $object): bool => $object['type'] === 'view'));
        $names = array_column($tables, 'name');
        $needs = [];

        foreach ($names as $name) {
            $needs[$name] = [];

            foreach ($target->getSchemaBuilder()->getForeignKeys($schema.'.'.$name) as $foreignKey) {
                $foreign = (string) $foreignKey['foreign_table'];
                $foreignSchema = $foreignKey['foreign_schema'] ?? $schema;

                if ($foreign !== $name && $foreignSchema === $schema && in_array($foreign, $names, true)) {
                    $needs[$name][$foreign] = true;
                }
            }
        }

        $ordered = [];
        $placed = [];

        while (count($ordered) < count($tables)) {
            $progress = false;

            foreach ($tables as $table) {
                if (! isset($placed[$table['name']]) && array_diff_key($needs[$table['name']], $placed) === []) {
                    $ordered[] = $table;
                    $placed[$table['name']] = true;
                    $progress = true;
                }
            }

            if (! $progress) {
                foreach ($tables as $table) {
                    if (! isset($placed[$table['name']])) {
                        $ordered[] = $table;
                        $placed[$table['name']] = true;
                    }
                }
            }
        }

        return [...$ordered, ...$views];
    }
}
