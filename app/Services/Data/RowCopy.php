<?php

namespace App\Services\Data;

use App\Enums\DatabaseDriver;

/**
 * Zeilen kopieren als TSV, Markdown oder INSERT (E-05): für Tabellenkalkulation, Notizen oder eine andere Datenbank.
 * Werte wie im Raster (Cells); NULL bleibt NULL, Binärdaten gehen nicht in die Zwischenablage.
 *
 * @phpstan-import-type Cell from Cells
 */
final class RowCopy
{
    /**
     * @param  list<string>  $columns
     * @param  list<list<Cell>>  $rows
     * @param  'tsv'|'markdown'|'insert'  $format
     */
    public function format(array $columns, array $rows, string $format, DatabaseDriver $driver, string $table): string
    {
        return match ($format) {
            'tsv' => $this->tsv($columns, $rows),
            'markdown' => $this->markdown($columns, $rows),
            'insert' => $this->insert($columns, $rows, $driver, $table),
        };
    }

    /**
     * @param  list<string>  $columns
     * @param  list<list<Cell>>  $rows
     */
    private function tsv(array $columns, array $rows): string
    {
        $line = fn (array $values): string => implode("\t", array_map(fn (mixed $value): string => str_replace(["\t", "\r\n", "\n", "\r"], [' ', ' ', ' ', ' '], $this->text($value, '')), $values));

        return implode("\n", [$line($columns), ...array_map($line, $rows)])."\n";
    }

    /**
     * @param  list<string>  $columns
     * @param  list<list<Cell>>  $rows
     */
    private function markdown(array $columns, array $rows): string
    {
        $cell = fn (mixed $value): string => str_replace(['|', "\r\n", "\n"], ['\\|', '<br>', '<br>'], $this->text($value, 'NULL'));
        $line = fn (array $values): string => '| '.implode(' | ', array_map($cell, $values)).' |';

        return implode("\n", [$line($columns), '| '.implode(' | ', array_fill(0, count($columns), '---')).' |', ...array_map($line, $rows)])."\n";
    }

    /**
     * @param  list<string>  $columns
     * @param  list<list<Cell>>  $rows
     */
    private function insert(array $columns, array $rows, DatabaseDriver $driver, string $table): string
    {
        $quote = fn (string $name): string => in_array($driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true)
            ? '`'.str_replace('`', '``', $name).'`'
            : '"'.str_replace('"', '""', $name).'"';
        $head = 'INSERT INTO '.$quote($table).' ('.implode(', ', array_map($quote, $columns)).') VALUES';

        $literal = function (mixed $value) use ($driver): string {
            return match (true) {
                $value === null, is_array($value) && isset($value['binary']) => 'NULL',
                is_bool($value) => $value ? 'TRUE' : 'FALSE',
                is_int($value), is_float($value) => (string) $value,
                default => "'".str_replace(
                    in_array($driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true) ? ['\\', "'"] : ["'"],
                    in_array($driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true) ? ['\\\\', "''"] : ["''"],
                    $this->text($value, ''),
                )."'",
            };
        };

        return implode("\n", array_map(fn (array $row): string => $head.' ('.implode(', ', array_map($literal, $row)).');', $rows))."\n";
    }

    /**
     * @param  Cell  $value
     */
    private function text(mixed $value, string $null): string
    {
        return match (true) {
            $value === null => $null,
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => isset($value['binary']) ? '' : $value['text'],
            default => (string) $value,
        };
    }
}
