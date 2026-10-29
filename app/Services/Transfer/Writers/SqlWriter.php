<?php

namespace App\Services\Transfer\Writers;

use App\Enums\DatabaseDriver;
use App\Services\Transfer\ColumnInfo;
use App\Services\Transfer\ExportTable;
use App\Services\Transfer\Values;
use Illuminate\Database\Connection;

/**
 * SQL-Export (E-01): Struktur, Daten oder beides — so, dass phpdbadmin (oder der Client des Servers) die Datei in
 * eine leere Datenbank desselben Treibers zurückspielen kann.
 *
 * - Daten als INSERT in Paketen (100 Zeilen oder 1 MB je Anweisung), Werte über Laravels escape je Treiber.
 * - MySQL/MariaDB und SQLite: Namen ohne Schema (Import in eine andere Datenbank), Fremdschlüssel beim Einspielen
 *   abgeschaltet. PostgreSQL: Namen mit Schema wie in der DDL des Dialekts; Sequenzen der Spalten vor der Tabelle,
 *   ihr Stand nach den Daten (setval), Fremdschlüssel erst am Ende (ALTER TABLE) — so stört weder die Reihenfolge der
 *   Tabellen noch ein Verweis auf die eigene Tabelle.
 * - Sichten am Ende. Nicht enthalten: Trigger, Routinen, Ereignisse und eigenständige Sequenzen (dafür E-03 mit den
 *   Werkzeugen des Servers).
 */
final class SqlWriter implements ExportWriter
{
    private const int ROWS_PER_INSERT = 100;

    private const int BYTES_PER_INSERT = 1_048_576;

    private ?ExportTable $table = null;

    /** @var list<ColumnInfo> */
    private array $columns = [];

    /** @var list<string> */
    private array $values = [];

    private int $valueBytes = 0;

    private bool $wroteRows = false;

    /** @var list<string> Anweisungen nach allen Tabellen (PostgreSQL: Fremdschlüssel). */
    private array $after = [];

    /** @var list<ExportTable> */
    private array $views = [];

    /**
     * @param  resource  $stream
     */
    public function __construct(
        private $stream,
        private readonly Connection $target,
        private readonly DatabaseDriver $driver,
        private readonly bool $structure = true,
        private readonly bool $data = true,
        private readonly bool $drop = false,
        private readonly string $header = '',
    ) {}

    public function begin(): void
    {
        $this->write(($this->header !== '' ? '-- '.$this->header."\n" : '')."\n");

        match ($this->driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => $this->write("SET FOREIGN_KEY_CHECKS = 0;\n\n"),
            DatabaseDriver::Sqlite => $this->write("PRAGMA foreign_keys = OFF;\n\n"),
            DatabaseDriver::Postgres => null,
        };
    }

    public function beginTable(ExportTable $table): void
    {
        $this->table = $table;
        $this->columns = $table->dataColumns();

        if ($table->isView) {
            $this->views[] = $table;

            return;
        }

        if ($this->structure) {
            $this->write($this->tableStructure($table)."\n\n");
        }
    }

    public function wantsRows(ExportTable $table): bool
    {
        return $this->data && ! $table->isView;
    }

    public function row(array $row): void
    {
        $values = [];

        foreach ($this->columns as $column) {
            $values[] = Values::sql($this->target, $row[$column->name] ?? null, $column);
        }

        $tuple = '('.implode(', ', $values).')';
        $this->values[] = $tuple;
        $this->valueBytes += strlen($tuple);

        if (count($this->values) >= self::ROWS_PER_INSERT || $this->valueBytes >= self::BYTES_PER_INSERT) {
            $this->flush();
        }
    }

    public function endTable(): void
    {
        $this->flush();
        $table = $this->table;

        if ($this->wroteRows) {
            $this->write("\n");
            $this->wroteRows = false;
        }

        if ($table !== null && ! $table->isView && $this->data && $this->driver === DatabaseDriver::Postgres) {
            foreach ($this->columns as $column) {
                if ($column->autoIncrement) {
                    $name = $this->wrap($column->name);
                    $this->write('SELECT setval(pg_get_serial_sequence('.$this->target->escape($this->wrap($table->schema.'.'.$table->name)).', '
                        .$this->target->escape($column->name).'), COALESCE(MAX('.$name.'), 1), MAX('.$name.') IS NOT NULL) FROM '.$this->reference($table).";\n\n");
                }
            }
        }

        $this->table = null;
    }

    public function finish(): void
    {
        foreach ($this->after as $statement) {
            $this->write($statement."\n\n");
        }

        if ($this->structure) {
            foreach ($this->views as $view) {
                $this->write($this->viewStructure($view)."\n\n");
            }
        }

        if ($this->driver === DatabaseDriver::MySql || $this->driver === DatabaseDriver::MariaDb) {
            $this->write("SET FOREIGN_KEY_CHECKS = 1;\n");
        }
    }

    private function flush(): void
    {
        if ($this->values === [] || $this->table === null) {
            return;
        }

        $columns = implode(', ', array_map(fn (ColumnInfo $column): string => $this->wrap($column->name), $this->columns));
        $this->write('INSERT INTO '.$this->reference($this->table).' ('.$columns.") VALUES\n".implode(",\n", $this->values).";\n");
        $this->wroteRows = true;
        $this->values = [];
        $this->valueBytes = 0;
    }

    private function tableStructure(ExportTable $table): string
    {
        $statements = [];

        if ($this->drop) {
            $statements[] = 'DROP TABLE IF EXISTS '.$this->reference($table).($this->driver === DatabaseDriver::Postgres ? ' CASCADE;' : ';');
        }

        return implode("\n", [...$statements, ...match ($this->driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => [rtrim($this->driver->dialect()->ddl($this->target, $table->schema, $table->name, false), ';').';'],
            DatabaseDriver::Postgres => $this->postgresTable($table),
            DatabaseDriver::Sqlite => $this->sqliteObject($table, ['table', 'index']),
        }]);
    }

    private function viewStructure(ExportTable $view): string
    {
        $drop = $this->drop
            ? ['DROP VIEW IF EXISTS '.$this->reference($view).($this->driver === DatabaseDriver::Postgres ? ' CASCADE;' : ';')]
            : [];

        return implode("\n", [...$drop, ...match ($this->driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => [$this->portableMySqlView($view)],
            DatabaseDriver::Postgres => [rtrim($this->driver->dialect()->ddl($this->target, $view->schema, $view->name, true), ';').';'],
            DatabaseDriver::Sqlite => $this->sqliteObject($view, ['view']),
        }]);
    }

    /**
     * SHOW CREATE VIEW nennt den Ersteller (DEFINER — beim Einspielen unter einem anderen Konto ein Fehler) und das
     * Schema vor jeder Spalte; beides fällt weg, damit die Sicht in eine andere Datenbank passt.
     */
    private function portableMySqlView(ExportTable $view): string
    {
        $ddl = $this->driver->dialect()->ddl($this->target, $view->schema, $view->name, true);
        $ddl = (string) preg_replace('/\s+DEFINER\s*=\s*`(?:[^`]|``)*`@`(?:[^`]|``)*`/i', '', $ddl);

        return str_replace($this->wrap($view->schema).'.', '', rtrim($ddl, ';')).';';
    }

    /**
     * PostgreSQL: Sequenzen der serial-Spalten zuerst (die DDL verweist mit nextval darauf), Fremdschlüssel aus der
     * Tabelle heraus ans Ende, danach die Sequenzen der Tabelle zuordnen.
     *
     * @return list<string>
     */
    private function postgresTable(ExportTable $table): array
    {
        $oid = $this->target->selectOne(
            'select c.oid from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = ? and c.relname = ?',
            [$table->schema, $table->name],
        )?->oid;
        $qualified = $this->reference($table);
        $sequences = $this->target->select(
            "select s.relname as seq, a.attname as col from pg_depend d
             join pg_class s on s.oid = d.objid and s.relkind = 'S'
             join pg_attribute a on a.attrelid = d.refobjid and a.attnum = d.refobjsubid
             where d.refobjid = ?::oid and d.classid = 'pg_class'::regclass and d.refclassid = 'pg_class'::regclass and d.deptype = 'a'
             order by s.relname",
            [$oid],
        );
        $ddl = $this->driver->dialect()->ddl($this->target, $table->schema, $table->name, false);

        foreach ($this->target->select(
            "select conname as name, pg_get_constraintdef(oid, true) as def from pg_constraint where conrelid = ?::oid and contype = 'f' order by conname",
            [$oid],
        ) as $foreignKey) {
            $line = ",\n    CONSTRAINT ".$this->wrap((string) $foreignKey->name).' '.$foreignKey->def;

            if (str_contains($ddl, $line)) {
                $ddl = str_replace($line, '', $ddl);
                $this->after[] = 'ALTER TABLE '.$qualified.' ADD CONSTRAINT '.$this->wrap((string) $foreignKey->name).' '.$foreignKey->def.';';
            }
        }

        $before = [];
        $owned = [];

        foreach ($sequences as $row) {
            $sequence = (array) $row;
            $name = $this->wrap($table->schema.'.'.$sequence['seq']);
            $before[] = 'CREATE SEQUENCE IF NOT EXISTS '.$name.';';
            $owned[] = 'ALTER SEQUENCE '.$name.' OWNED BY '.$qualified.'.'.$this->wrap((string) $sequence['col']).';';
        }

        return [...$before, $ddl, ...$owned];
    }

    /**
     * SQLite: Tabelle bzw. Sicht und ihre Indizes so, wie die Datei sie speichert — ohne Trigger (die würden beim
     * Einspielen der Daten feuern).
     *
     * @param  list<string>  $types
     * @return list<string>
     */
    private function sqliteObject(ExportTable $table, array $types): array
    {
        $placeholders = implode(', ', array_fill(0, count($types), '?'));

        return array_values(array_map(
            fn (object $row): string => rtrim((string) (((array) $row)['sql'] ?? ''), ';').';',
            $this->target->select(
                'select sql from '.$this->wrap($table->schema).".sqlite_schema where tbl_name = ? and type in ($placeholders) and sql is not null order by case type when 'index' then 1 else 0 end, name",
                [$table->name, ...$types],
            ),
        ));
    }

    /** Name der Tabelle in Anweisungen: mit Schema nur bei PostgreSQL. */
    private function reference(ExportTable $table): string
    {
        return $this->driver === DatabaseDriver::Postgres ? $this->wrap($table->schema.'.'.$table->name) : $this->wrap($table->name);
    }

    private function wrap(string $name): string
    {
        return $this->target->getQueryGrammar()->wrap($name);
    }

    private function write(string $text): void
    {
        fwrite($this->stream, $text);
    }
}
