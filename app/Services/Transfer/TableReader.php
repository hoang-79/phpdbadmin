<?php

namespace App\Services\Transfer;

use App\Enums\DatabaseDriver;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use PDO;
use Pdo\Mysql;

/**
 * Liest eine Tabelle in Paketen mit wenig Speicher (F-8, ADR-007): über den Primärschlüssel nach Schlüssel
 * (WHERE schlüssel > letzter ORDER BY schlüssel LIMIT 5000 — gemessen 3 MB je Million Zeilen auf allen vier).
 * Ohne Schlüssel (und für Sichten) über einen Cursor, der nicht das ganze Ergebnis hält: PostgreSQL mit einem
 * Server-Cursor (DECLARE/FETCH), MySQL und MariaDB ungepuffert, SQLite liest ohnehin Zeile für Zeile. Versatz
 * (OFFSET) ohne eindeutige Sortierung könnte Zeilen doppelt liefern oder auslassen und kommt deshalb nicht vor.
 *
 * Läuft im Schreibschutz-Wächter des Aufrufers (Dialect::runReadOnly) — der Server-Cursor braucht dessen Transaktion.
 */
final class TableReader
{
    public const int CHUNK = 5000;

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $primaryKey
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(Connection $target, DatabaseDriver $driver, string $schema, string $table, array $columns, array $primaryKey, int $chunk = self::CHUNK): Generator
    {
        $columns = array_values(array_unique([...$columns, ...$primaryKey]));

        if ($primaryKey !== []) {
            yield from $this->byKey($target, $schema, $table, $columns, $primaryKey, $chunk);

            return;
        }

        yield from match ($driver) {
            DatabaseDriver::Postgres => $this->withServerCursor($target, $schema, $table, $columns, $chunk),
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => $this->unbuffered($target, $schema, $table, $columns),
            DatabaseDriver::Sqlite => $this->streamed($target, $target->table($schema.'.'.$table)->select($columns)),
        };
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $primaryKey
     * @return Generator<int, array<string, mixed>>
     */
    private function byKey(Connection $target, string $schema, string $table, array $columns, array $primaryKey, int $chunk): Generator
    {
        $after = null;

        do {
            $query = $target->table($schema.'.'.$table)->select($columns);

            foreach ($primaryKey as $column) {
                $query->orderBy($column);
            }

            if ($after !== null) {
                $this->after($query, $primaryKey, $after);
            }

            $count = 0;
            $last = null;

            foreach ($query->limit($chunk)->get() as $row) {
                $last = (array) $row;
                $count++;

                yield $last;
            }

            $after = $last === null ? null : array_map(fn (string $column): mixed => Values::read($last[$column] ?? null), $primaryKey);
        } while ($count === $chunk && $after !== null);
    }

    /**
     * (a, b) > (x, y) ausgeschrieben, weil nicht jede Version Zeilenwerte vergleicht.
     *
     * @param  list<string>  $primaryKey
     * @param  list<mixed>  $after
     */
    private function after(Builder $query, array $primaryKey, array $after): void
    {
        $query->where(function (Builder $outer) use ($primaryKey, $after): void {
            foreach (array_keys($primaryKey) as $depth) {
                $outer->orWhere(function (Builder $inner) use ($primaryKey, $after, $depth): void {
                    for ($position = 0; $position < $depth; $position++) {
                        $inner->where($primaryKey[$position], '=', $after[$position]);
                    }
                    $inner->where($primaryKey[$depth], '>', $after[$depth]);
                });
            }
        });
    }

    /**
     * @param  list<string>  $columns
     * @return Generator<int, array<string, mixed>>
     */
    private function withServerCursor(Connection $target, string $schema, string $table, array $columns, int $chunk): Generator
    {
        $select = $target->table($schema.'.'.$table)->select($columns)->toSql();
        // Fester Name des Cursors und gequotete Namen aus dem Katalog — keine Werte der Person.
        $target->statement('declare phpdbadmin_export no scroll cursor for '.$select);

        try {
            do {
                $rows = $target->select('fetch forward '.$chunk.' from phpdbadmin_export');

                foreach ($rows as $row) {
                    yield (array) $row;
                }
            } while (count($rows) === $chunk);
        } finally {
            $target->statement('close phpdbadmin_export');
        }
    }

    /**
     * @param  list<string>  $columns
     * @return Generator<int, array<string, mixed>>
     */
    private function unbuffered(Connection $target, string $schema, string $table, array $columns): Generator
    {
        $pdo = $target->getPdo();
        $pdo->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, false);

        try {
            yield from $this->streamed($target, $target->table($schema.'.'.$table)->select($columns));
        } finally {
            $pdo->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, true);
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function streamed(Connection $target, Builder $query): Generator
    {
        // Ohne Bindungen (nur Spalten und Tabelle) — deshalb direkt vorbereitet, mit eigenem closeCursor().
        $statement = $target->getPdo()->prepare($query->toSql());
        $statement->setFetchMode(PDO::FETCH_ASSOC);
        $statement->execute();

        try {
            while (($row = $statement->fetch()) !== false) {
                /** @var array<string, mixed> $row */
                yield $row;
            }
        } finally {
            $statement->closeCursor();
        }
    }
}
