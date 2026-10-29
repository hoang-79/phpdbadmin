<?php

namespace App\Services\Schema;

use App\Connections\HostPolicy;
use App\Enums\ApplyMode;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Services\Server\ServerInspector;
use App\Support\TrustedSql;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

/**
 * Plant Strukturänderungen aus geprüften Eingaben (S-02 bis S-05). Tabelle anlegen und ändern kommen aus Laravels
 * Blueprint::toSql() — nie aus pretend(), das auf SQLite eine kaputte Vorschau liefert (F-5, ADR-003); umbenennen,
 * kopieren, leeren, löschen und Schemas baut der Plan selbst, mit Namen in Anführungszeichen je Segment. Jeder Name
 * eines bestehenden Objekts muss im Katalog stehen; Vorschau und Anwenden planen über dieselbe Methode, damit die
 * Vorschau genau zeigt, was läuft.
 */
final class SchemaPlans
{
    /** Ausdrücke, die als Vorgabe immer gehen; sonst nur die bisherige Vorgabe der Spalte, wörtlich. */
    private const array EXPRESSIONS = ['CURRENT_TIMESTAMP'];

    /** Eigener Typ: Wörter, höchstens eine Klammer mit Zahlen, auf Wunsch «[]» — nie Anführungszeichen oder «;». */
    private const string CUSTOM_TYPE = '/^[A-Za-z][A-Za-z0-9_]*( [A-Za-z][A-Za-z0-9_]*)*( ?\(\s*\d+(\s*,\s*\d+)?\s*\))?( [A-Za-z][A-Za-z0-9_]*)*(\[\])?$/';

    public function __construct(private readonly ServerInspector $inspector, private readonly HostPolicy $hosts) {}

    /**
     * Tabelle anlegen (S-02).
     *
     * @throws ValidationException
     */
    public function create(Connection $target, DatabaseConnection $connection, string $schema, TableDraft $draft): SchemaPlan
    {
        $driver = $connection->driver;
        $this->checkSchema($target, $connection, $schema);
        $this->checkNewName('name', $draft->name);

        if ($this->exists($target, $connection, $schema, $draft->name)) {
            throw ValidationException::withMessages(['name' => __('Eine Tabelle oder Sicht mit diesem Namen gibt es schon.')]);
        }

        if ($draft->columns === []) {
            throw ValidationException::withMessages(['columns' => __('Eine Tabelle braucht mindestens eine Spalte.')]);
        }

        $names = [];
        foreach ($draft->columns as $position => $column) {
            if (! $column->isNew() || $column->drop) {
                throw ValidationException::withMessages(["columns.{$position}.name" => __('Beim Anlegen sind alle Spalten neu.')]);
            }

            $this->checkColumn("columns.{$position}", $column, $driver, null);
            $names = $this->addName($names, "columns.{$position}.name", $column->name);
        }

        $autoIncrement = array_values(array_filter($draft->columns, fn (ColumnSpec $column): bool => $column->autoIncrement));

        if (count($autoIncrement) > 1) {
            throw ValidationException::withMessages(['columns' => __('Nur eine Spalte kann Auto-Increment sein.')]);
        }

        $primary = $this->checkPrimary($draft, $names, $autoIncrement[0] ?? null);
        $this->checkNewIndexes($draft->indexes, $names, $draft->name, []);
        $this->checkNewForeignKeys($target, $connection, $schema, $draft->name, $draft->foreignKeys, $names);

        if ($draft->comment !== null) {
            $this->checkTableComment($driver, $draft->comment);
        }

        $blueprint = new Blueprint($target, SchemaName::forBlueprint($driver, $schema, $draft->name), function (Blueprint $table) use ($target, $driver, $schema, $draft, $primary): void {
            $table->create();

            foreach ($draft->columns as $column) {
                $this->addColumn($table, $target, $driver, $column, $column->name, null);
            }

            if ($primary !== []) {
                $table->primary($primary);
            }

            foreach ($draft->indexes as $index) {
                $index->unique ? $table->unique($index->columns, $index->nameFor($draft->name)) : $table->index($index->columns, $index->nameFor($draft->name));
            }

            foreach ($draft->foreignKeys as $foreignKey) {
                $this->addForeignKey($table, $driver, $schema, $draft->name, $foreignKey, $foreignKey->columns, $foreignKey->references);
            }

            if ($draft->comment !== null && $driver !== DatabaseDriver::Sqlite) {
                $table->comment($draft->comment);
            }
        });

        $notes = [];
        if ($driver === DatabaseDriver::Sqlite && ($draft->comment !== null || array_any($draft->columns, fn (ColumnSpec $column): bool => $column->comment !== null))) {
            $notes[] = __('SQLite kennt keine Kommentare — sie werden nicht gespeichert.');
        }

        return new SchemaPlan(
            'schema.create',
            $schema.'.'.$draft->name,
            $driver,
            ApplyMode::for($driver),
            $this->compile($blueprint),
            $notes,
            context: ['schema' => $schema, 'table' => $draft->name, 'columns' => count($draft->columns)],
        );
    }

    /**
     * Tabelle ändern (S-03): Spalten hinzufügen, ändern, umbenennen, löschen; Indizes und Fremdschlüssel hinzufügen
     * und löschen; Kommentare. Die Reihenfolge ist so gewählt, dass sie auf allen vier Treibern gilt und SQLite die
     * Tabelle höchstens einmal umbaut: neue Spalten, Fremdschlüssel weg, Spalten ändern, neue Fremdschlüssel (der
     * Umbau), umbenennen, Indizes weg, Spalten weg, neue Indizes, Tabellenkommentar.
     *
     * @throws ValidationException
     */
    public function alter(Connection $target, DatabaseConnection $connection, string $schema, string $table, TableDraft $draft): SchemaPlan
    {
        $driver = $connection->driver;
        $this->checkTable($target, $connection, $schema, $table);
        $builder = $target->getSchemaBuilder();
        $qualified = $schema.'.'.$table;

        $current = array_column($builder->getColumns($qualified), null, 'name');
        $currentIndexes = array_column($builder->getIndexes($qualified), null, 'name');
        $currentForeignKeys = $builder->getForeignKeys($qualified);
        $primary = array_find($currentIndexes, fn (array $index): bool => (bool) $index['primary'])['columns'] ?? [];

        foreach (array_keys($current) as $name) {
            if (! SchemaName::isSafe((string) $name)) {
                throw ValidationException::withMessages(['columns' => __('Die Spalte «:column» hat einen Namen, den phpdbadmin hier nicht ändern kann — nutzen Sie den SQL-Editor.', ['column' => $name])]);
            }
        }

        $currentSpecs = $this->currentSpecs($target, $connection, $schema, $table, $current);

        // Spalten: neu, ändern, umbenennen, löschen.
        $added = [];
        $changed = [];
        $renamed = [];
        $dropped = [];
        $finalNames = [];
        $seen = [];
        $after = [];
        $previous = null;

        foreach ($draft->columns as $position => $column) {
            $field = "columns.{$position}";

            if ($column->isNew()) {
                if ($column->drop) {
                    $previous = null;

                    continue;
                }

                $this->checkColumn($field, $column, $driver, null);

                if ($column->autoIncrement) {
                    throw ValidationException::withMessages(["{$field}.autoIncrement" => __('Auto-Increment lässt sich nur beim Anlegen der Tabelle setzen.')]);
                }

                $finalNames = $this->addName($finalNames, "{$field}.name", $column->name);
                $added[] = $column;
                $after[$column->name] = $previous;
                $previous = $column->name;

                continue;
            }

            $original = (string) $column->original;

            if (! isset($current[$original]) || in_array($original, $seen, true)) {
                throw ValidationException::withMessages(["{$field}.original" => __('Diese Spalte gibt es in der Tabelle nicht.')]);
            }

            $seen[] = $original;

            if ($column->drop) {
                if (in_array($original, $primary, true)) {
                    throw ValidationException::withMessages(["{$field}.drop" => __('Die Spalte gehört zum Primärschlüssel und lässt sich hier nicht löschen.')]);
                }

                $dropped[] = $original;
                $previous = $original;

                continue;
            }

            if ($column->isRenamed()) {
                $this->checkNewName("{$field}.name", $column->name);
                $renamed[$original] = $column->name;
            }

            $finalNames = $this->addName($finalNames, "{$field}.name", $column->name);

            if ($column->changed) {
                $this->checkColumn($field, $column, $driver, $currentSpecs[$original]);

                if ($column->autoIncrement !== $currentSpecs[$original]->autoIncrement) {
                    throw ValidationException::withMessages(["{$field}.autoIncrement" => __('Auto-Increment lässt sich nur beim Anlegen der Tabelle setzen.')]);
                }

                $changed[$original] = $column;
            }

            $previous = $original;
        }

        // Spalten, die im Formular fehlen, bleiben, wie sie sind.
        foreach (array_keys($current) as $name) {
            if (! in_array($name, $seen, true)) {
                $finalNames = $this->addName($finalNames, 'columns', (string) $name);
            }
        }

        if ($finalNames === []) {
            throw ValidationException::withMessages(['columns' => __('Eine Tabelle braucht mindestens eine Spalte — löschen Sie sonst die ganze Tabelle.')]);
        }

        // Indizes: bestehende löschen, neue anlegen.
        $droppedIndexes = [];
        foreach ($draft->indexes as $position => $index) {
            if ($index->original === null) {
                continue;
            }

            $existing = $currentIndexes[$index->original] ?? null;

            if ($existing === null || (bool) $existing['primary']) {
                throw ValidationException::withMessages(["indexes.{$position}.original" => __('Diesen Index gibt es nicht (der Primärschlüssel lässt sich hier nicht löschen).')]);
            }

            if ($index->drop) {
                $droppedIndexes[] = $existing;
            }
        }

        $droppedIndexNames = array_column($droppedIndexes, 'name');
        foreach ($currentIndexes as $name => $index) {
            if ((bool) $index['primary'] || in_array($name, $droppedIndexNames, true)) {
                continue;
            }

            foreach ($index['columns'] as $indexed) {
                if (in_array($indexed, $dropped, true)) {
                    throw ValidationException::withMessages(['indexes' => __('Der Index :index nutzt die Spalte :column, die wegfällt — löschen Sie ihn mit.', ['index' => $name, 'column' => $indexed])]);
                }
            }
        }

        // Schlüssel bleiben erhalten: Fehler zeigen auf die Zeile im Formular (indexes.3.columns).
        $newIndexes = array_filter($draft->indexes, fn (IndexSpec $index): bool => $index->original === null && ! $index->drop);
        $keptIndexNames = array_values(array_diff(array_map('strval', array_keys($currentIndexes)), $droppedIndexNames));
        $this->checkNewIndexes($newIndexes, $finalNames, $table, $keptIndexNames);

        // Fremdschlüssel: bestehende (über ihre Spalten erkannt) löschen, neue anlegen.
        $droppedForeignKeys = [];
        foreach ($draft->foreignKeys as $position => $foreignKey) {
            if ($foreignKey->original === null) {
                continue;
            }

            $existing = array_find($currentForeignKeys, fn (array $key): bool => $key['columns'] === $foreignKey->original);

            if ($existing === null) {
                throw ValidationException::withMessages(["foreignKeys.{$position}.original" => __('Diesen Fremdschlüssel gibt es nicht.')]);
            }

            if ($foreignKey->drop) {
                $droppedForeignKeys[] = $existing;
            }
        }

        foreach ($currentForeignKeys as $foreignKey) {
            if (in_array($foreignKey, $droppedForeignKeys, true)) {
                continue;
            }

            foreach ($foreignKey['columns'] as $column) {
                if (in_array($column, $dropped, true)) {
                    throw ValidationException::withMessages(['foreignKeys' => __('Ein Fremdschlüssel nutzt die Spalte :column, die wegfällt — löschen Sie ihn mit.', ['column' => $column])]);
                }
            }
        }

        $newForeignKeys = array_filter($draft->foreignKeys, fn (ForeignKeySpec $key): bool => $key->original === null && ! $key->drop);
        $this->checkNewForeignKeys($target, $connection, $schema, $table, $newForeignKeys, $finalNames);

        $comment = null;
        if ($draft->commentChanged && $driver !== DatabaseDriver::Sqlite) {
            $comment = $draft->comment ?? '';
            $this->checkTableComment($driver, $comment);
        }

        // Neue Namen → Namen zum Zeitpunkt der Fremdschlüssel (vor dem Umbenennen).
        $beforeRename = array_flip($renamed);
        $atForeignKeys = fn (array $columns): array => array_values(array_map(fn (string $column): string => $beforeRename[$column] ?? $column, $columns));

        $blueprint = new Blueprint($target, SchemaName::forBlueprint($driver, $schema, $table), function (Blueprint $blueprint) use (
            $target, $driver, $schema, $table, $current, $added, $after, $changed, $renamed, $dropped, $droppedIndexes,
            $droppedForeignKeys, $newIndexes, $newForeignKeys, $comment, $atForeignKeys
        ): void {
            $isMySql = in_array($driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true);

            foreach ($added as $column) {
                $definition = $this->addColumn($blueprint, $target, $driver, $column, $column->name, null);

                if ($isMySql) {
                    $previous = $after[$column->name] ?? null;
                    $previous === null ? $definition->first() : $definition->after($previous);
                }
            }

            foreach ($droppedForeignKeys as $foreignKey) {
                $driver === DatabaseDriver::Sqlite
                    ? $blueprint->dropForeign($foreignKey['columns'])
                    : $blueprint->dropForeign((string) $foreignKey['name']);
            }

            foreach ($changed as $original => $column) {
                $this->addColumn($blueprint, $target, $driver, $column, $original, $current[$original])->change();
            }

            foreach ($newForeignKeys as $foreignKey) {
                $references = $foreignKey->table === $table ? $atForeignKeys($foreignKey->references) : $foreignKey->references;
                $this->addForeignKey($blueprint, $driver, $schema, $table, $foreignKey, $atForeignKeys($foreignKey->columns), $references);
            }

            foreach ($renamed as $from => $to) {
                $blueprint->renameColumn($from, $to);
            }

            foreach ($droppedIndexes as $index) {
                (bool) $index['unique'] ? $blueprint->dropUnique((string) $index['name']) : $blueprint->dropIndex((string) $index['name']);
            }

            if ($dropped !== []) {
                $blueprint->dropColumn($dropped);
            }

            foreach ($newIndexes as $index) {
                $index->unique ? $blueprint->unique($index->columns, $index->nameFor($table)) : $blueprint->index($index->columns, $index->nameFor($table));
            }

            if ($comment !== null) {
                $blueprint->comment($comment);
            }
        });

        $statements = $this->compile($blueprint);
        $notes = [];

        if ($driver === DatabaseDriver::Sqlite && array_any($statements, fn (string $sql): bool => str_contains($sql, '__temp__'))) {
            $statements = $this->keepSqliteExtras($target, $schema, $table, $statements);
            $notes[] = __('SQLite baut die Tabelle um: neue Tabelle, Daten kopieren, alte löschen, umbenennen; Indizes, Checks und Trigger werden neu angelegt.');
        }

        if ($driver === DatabaseDriver::Sqlite && $draft->commentChanged) {
            $notes[] = __('SQLite kennt keine Kommentare — sie werden nicht gespeichert.');
        }

        $rows = $this->rowEstimate($target, $connection, $schema, $table);
        $losses = array_map(fn (string $column): string => $this->columnLoss($column, $rows), $dropped);

        return new SchemaPlan(
            'schema.alter',
            $schema.'.'.$table,
            $driver,
            ApplyMode::for($driver),
            $statements,
            $notes,
            $losses,
            $losses === [] ? null : $table,
            [
                'schema' => $schema,
                'table' => $table,
                'added' => array_map(fn (ColumnSpec $column): string => $column->name, $added),
                'changed' => array_keys($changed),
                'renamed' => $renamed,
                'dropped' => $dropped,
            ],
        );
    }

    /**
     * Tabelle umbenennen (S-04).
     *
     * @throws ValidationException
     */
    public function rename(Connection $target, DatabaseConnection $connection, string $schema, string $table, string $to): SchemaPlan
    {
        $driver = $connection->driver;
        $this->checkTable($target, $connection, $schema, $table);
        $this->checkFreeName($target, $connection, $schema, $to);

        $sql = match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => 'RENAME TABLE '.SchemaName::qualified($driver, $schema, $table).' TO '.SchemaName::qualified($driver, $schema, $to),
            default => 'ALTER TABLE '.SchemaName::qualified($driver, $schema, $table).' RENAME TO '.SchemaName::quote($driver, $to),
        };

        return new SchemaPlan('schema.rename', $schema.'.'.$table, $driver, ApplyMode::for($driver), [$sql], [
            __('Fremdschlüssel anderer Tabellen zeigen danach auf den neuen Namen; Sichten und Routinen, die den alten Namen nennen, passen Sie selbst an.'),
        ], context: ['schema' => $schema, 'table' => $table, 'to' => $to]);
    }

    /**
     * Tabelle kopieren (S-04), nur die Struktur oder mit Daten.
     *
     * @throws ValidationException
     */
    public function copy(Connection $target, DatabaseConnection $connection, string $schema, string $table, string $to, bool $withData): SchemaPlan
    {
        $driver = $connection->driver;
        $this->checkTable($target, $connection, $schema, $table);
        $this->checkFreeName($target, $connection, $schema, $to);
        $from = SchemaName::qualified($driver, $schema, $table);
        $copy = SchemaName::qualified($driver, $schema, $to);
        $notes = [__('Fremdschlüssel und Trigger werden nicht mitkopiert.')];

        $statements = match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => $withData
                ? ['CREATE TABLE '.$copy.' LIKE '.$from, 'INSERT INTO '.$copy.' SELECT * FROM '.$from]
                : ['CREATE TABLE '.$copy.' LIKE '.$from],
            DatabaseDriver::Postgres => $this->postgresCopy($target, $schema, $table, $to, $withData),
            DatabaseDriver::Sqlite => $this->sqliteCopy($target, $schema, $table, $to, $withData),
        };

        if ($driver === DatabaseDriver::Postgres) {
            $notes[] = __('Zähler (serial, identity) bekommt die Kopie eigene; sie beginnen nach dem grössten kopierten Wert.');
        }

        return new SchemaPlan('schema.copy', $schema.'.'.$table, $driver, ApplyMode::for($driver), $statements, $notes,
            context: ['schema' => $schema, 'table' => $table, 'to' => $to, 'with_data' => $withData]);
    }

    /**
     * Tabelle leeren (S-04) — nur mit dem Namen der Tabelle zur Bestätigung.
     *
     * @throws ValidationException
     */
    public function truncate(Connection $target, DatabaseConnection $connection, string $schema, string $table): SchemaPlan
    {
        $driver = $connection->driver;
        $this->checkTable($target, $connection, $schema, $table);
        $name = SchemaName::qualified($driver, $schema, $table);

        $statements = match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => ['TRUNCATE TABLE '.$name],
            DatabaseDriver::Postgres => ['TRUNCATE TABLE '.$name.' RESTART IDENTITY'],
            // SQLite kennt kein TRUNCATE; der Zähler von AUTOINCREMENT steht in sqlite_sequence.
            DatabaseDriver::Sqlite => $this->hasSqliteSequence($target, $schema)
                ? ['DELETE FROM '.$name, 'DELETE FROM '.SchemaName::qualified($driver, $schema, 'sqlite_sequence').' WHERE name = '.$this->literal($target, $table)]
                : ['DELETE FROM '.$name],
        };

        $notes = [__('Verweisen Zeilen anderer Tabellen hierher, lehnt der Server das Leeren ab.')];

        return new SchemaPlan('schema.truncate', $schema.'.'.$table, $driver, ApplyMode::for($driver), $statements, $notes,
            [$this->rowsLoss($target, $connection, $schema, $table)], $table, ['schema' => $schema, 'table' => $table]);
    }

    /**
     * Tabelle löschen (S-04) — nur mit dem Namen der Tabelle zur Bestätigung. Ohne CASCADE: Verweist eine andere
     * Tabelle hierher, lehnt der Server ab.
     *
     * @throws ValidationException
     */
    public function drop(Connection $target, DatabaseConnection $connection, string $schema, string $table): SchemaPlan
    {
        $driver = $connection->driver;
        $this->checkTable($target, $connection, $schema, $table);

        return new SchemaPlan('schema.drop', $schema.'.'.$table, $driver, ApplyMode::for($driver), [
            'DROP TABLE '.SchemaName::qualified($driver, $schema, $table),
        ], [
            __('Verweisen andere Tabellen hierher, lehnt der Server das Löschen ab.'),
        ], [$this->rowsLoss($target, $connection, $schema, $table)], $table, ['schema' => $schema, 'table' => $table]);
    }

    /**
     * Datenbank (MySQL, MariaDB) bzw. Schema (PostgreSQL) anlegen (S-05).
     *
     * @throws ValidationException
     */
    public function createSchema(Connection $target, DatabaseConnection $connection, string $name): SchemaPlan
    {
        $driver = $connection->driver;
        $this->checkNewName('name', $name);
        $this->checkServerSchemas($connection);

        if ($connection->database !== null && $driver !== DatabaseDriver::Postgres) {
            throw ValidationException::withMessages(['name' => __('Die Verbindung ist auf eine Datenbank festgelegt — neue Datenbanken legen Sie über eine Verbindung ohne festen Datenbanknamen an.')]);
        }

        if (in_array(mb_strtolower($name), array_map(mb_strtolower(...), $driver->dialect()->schemas($target)), true)) {
            throw ValidationException::withMessages(['name' => __('Diesen Namen gibt es auf dem Server schon.')]);
        }

        $sql = $driver === DatabaseDriver::Postgres
            ? 'CREATE SCHEMA '.SchemaName::quote($driver, $name)
            : 'CREATE DATABASE '.SchemaName::quote($driver, $name);

        return new SchemaPlan('database.create', $name, $driver, ApplyMode::for($driver), [$sql], context: ['name' => $name]);
    }

    /**
     * Datenbank bzw. Schema löschen (S-05) — mit allem darin, nur mit dem Namen zur Bestätigung.
     *
     * @throws ValidationException
     */
    public function dropSchema(Connection $target, DatabaseConnection $connection, string $name): SchemaPlan
    {
        $driver = $connection->driver;
        $this->checkServerSchemas($connection);
        $this->checkSchema($target, $connection, $name);

        if (! SchemaName::isSafe($name)) {
            throw ValidationException::withMessages(['name' => __('Dieser Name lässt sich hier nicht verarbeiten — nutzen Sie den SQL-Editor.')]);
        }

        if ($connection->database !== null && $driver !== DatabaseDriver::Postgres) {
            throw ValidationException::withMessages(['name' => __('Die Verbindung ist auf diese Datenbank eingestellt — löschen Sie sie über eine Verbindung ohne festen Datenbanknamen.')]);
        }

        $tables = count($this->inspector->tablesOn($target, $connection, $name, withViews: true));
        $sql = $driver === DatabaseDriver::Postgres
            ? 'DROP SCHEMA '.SchemaName::quote($driver, $name).' CASCADE'
            : 'DROP DATABASE '.SchemaName::quote($driver, $name);

        return new SchemaPlan('database.drop', $name, $driver, ApplyMode::for($driver), [$sql], [], [
            $this->schemaLoss($tables),
        ], $name, ['name' => $name, 'tables' => $tables]);
    }

    /**
     * SQLite: neue, leere Datei in einem erlaubten Ordner (S-05) — es gibt keinen Server, der Datenbanken anlegt.
     *
     * @throws ValidationException
     */
    public function createSqliteFile(DatabaseConnection $connection, string $folder, string $file): SchemaPlan
    {
        $folders = $this->sqliteFolders();

        if (! in_array($folder, $folders, true)) {
            throw ValidationException::withMessages(['folder' => __('In diesem Ordner darf phpdbadmin keine Dateien anlegen.')]);
        }

        if (preg_match('/^[\p{L}\p{N}_][\p{L}\p{N}_\-]*\.(sqlite|sqlite3|db)$/u', $file) !== 1 || strlen($file) > 120) {
            throw ValidationException::withMessages(['file' => __('Dateiname aus Buchstaben, Ziffern, «_» und «-», mit der Endung .sqlite, .sqlite3 oder .db.')]);
        }

        $path = $folder.DIRECTORY_SEPARATOR.$file;

        if (file_exists($path)) {
            throw ValidationException::withMessages(['file' => __('Diese Datei gibt es schon.')]);
        }

        return new SchemaPlan('database.create', $file, $connection->driver, ApplyMode::Statements, [], [
            __('Legt eine leere SQLite-Datei an. Öffnen können Sie sie danach als neue Verbindung.'),
        ], context: ['folder' => $folder, 'file' => $file], file: $path);
    }

    /**
     * Erlaubte Ordner für SQLite-Dateien, aufgelöst; nur die, die es gibt und in die man schreiben darf.
     *
     * @return list<string>
     */
    public function sqliteFolders(): array
    {
        $folders = [];

        foreach ($this->hosts->sqliteFolders() as $folder) {
            $expanded = str_starts_with($folder, '~/') ? rtrim((string) (getenv('HOME') ?: ''), '/').substr($folder, 1) : $folder;
            $real = realpath($expanded);

            if ($real !== false && is_dir($real) && is_writable($real)) {
                $folders[] = $real;
            }
        }

        return array_values(array_unique($folders));
    }

    /**
     * Die bestehenden Spalten als Einträge des Formulars (für die Seite «Tabelle ändern» und als Vergleich).
     *
     * @param  array<string, array<string, mixed>>|null  $columns  Laravels getColumns(), nach Name
     * @return array<string, ColumnSpec>
     */
    public function currentSpecs(Connection $target, DatabaseConnection $connection, string $schema, string $table, ?array $columns = null): array
    {
        $driver = $connection->driver;
        $columns ??= array_column($target->getSchemaBuilder()->getColumns($schema.'.'.$table), null, 'name');
        $json = $driver === DatabaseDriver::MariaDb ? $this->mariaDbJsonColumns($target, $schema, $table) : [];
        $generated = $driver === DatabaseDriver::MySql ? $this->mySqlGeneratedDefaults($target, $schema, $table) : [];

        $specs = [];
        foreach ($columns as $name => $column) {
            $specs[(string) $name] = ColumnTypes::specOf($driver, $column, in_array($name, $json, true), in_array($name, $generated, true));
        }

        return $specs;
    }

    /**
     * @return list<string>
     */
    private function compile(Blueprint $blueprint): array
    {
        try {
            $statements = $blueprint->toSql();
        } catch (RuntimeException|LogicException $exception) {
            throw ValidationException::withMessages(['draft' => __('Laravel kann diese Änderung nicht erzeugen: :message', ['message' => $exception->getMessage()])]);
        }

        // SQLite: Fremdschlüssel schaltet das Verfahren selbst aus und ein — ausserhalb der Transaktion (ADR-003).
        return array_values(array_filter(
            array_map('strval', $statements),
            fn (string $sql): bool => preg_match('/^pragma foreign_keys\s*=/i', trim($sql)) !== 1,
        ));
    }

    /**
     * Eine Spalte in den Blueprint: Typ, NULL, Vorgabe, Kommentar; beim Ändern bleibt die Kollation eines Texttyps.
     *
     * @param  array<string, mixed>|null  $current  bisherige Spalte (getColumns) beim Ändern
     */
    private function addColumn(Blueprint $table, Connection $target, DatabaseDriver $driver, ColumnSpec $spec, string $name, ?array $current): ColumnDefinition
    {
        $column = match ($spec->type) {
            'string' => $table->string($name, $spec->length ?? 255),
            'char' => $table->char($name, $spec->length ?? 1),
            'decimal' => $table->decimal($name, $spec->precision ?? 10, $spec->scale ?? 0),
            // Werte über PDO::quote — Laravels quoteString() verdoppelt keine Anführungszeichen.
            'enum' => $table->rawColumn($name, 'enum('.implode(',', array_map(fn (string $value): string => $this->literal($target, $value), $spec->values)).')'),
            ColumnTypes::CUSTOM => $table->rawColumn($name, (string) $spec->custom),
            'integer', 'bigInteger', 'smallInteger', 'tinyInteger',
            'unsignedInteger', 'unsignedBigInteger', 'unsignedSmallInteger' => $table->{$spec->type}($name, $spec->autoIncrement),
            default => $table->{$spec->type}($name),
        };

        $column->nullable($spec->nullable);

        if ($spec->defaultKind === 'value') {
            // Bewusst roh: ein Literal über PDO::quote der Zielverbindung — Laravel verdoppelt nur «'», MySQL liest
            // aber auch «\» als Escape.
            $column->default(new TrustedSql($this->literal($target, (string) $spec->defaultValue)));
        } elseif ($spec->defaultKind === 'expression') {
            // Bewusst roh: nur CURRENT_TIMESTAMP oder genau die bisherige Vorgabe der Spalte (geprüft in checkColumn).
            $column->default(new TrustedSql($this->expression($driver, (string) $spec->defaultValue)));
        }

        if ($spec->comment !== null) {
            $column->comment($spec->comment);
        }

        $collation = is_string($current['collation'] ?? null) ? $current['collation'] : null;

        if ($collation !== null && $driver !== DatabaseDriver::Postgres && (ColumnTypes::isTextual($spec->type) || ($spec->type === ColumnTypes::CUSTOM && $spec->custom === ($current['type'] ?? null)))) {
            $column->collation($collation);
        }

        return $column;
    }

    /**
     * @param  list<string>  $columns  Spalten dieser Tabelle zum Zeitpunkt der Anweisung
     * @param  list<string>  $references
     */
    private function addForeignKey(Blueprint $table, DatabaseDriver $driver, string $schema, string $tableName, ForeignKeySpec $foreignKey, array $columns, array $references): void
    {
        $table->foreign($columns, $foreignKey->nameFor($tableName))
            ->references($references)
            ->on(SchemaName::forBlueprint($driver, $schema, $foreignKey->table))
            ->onDelete($foreignKey->onDelete)
            ->onUpdate($foreignKey->onUpdate);
    }

    /**
     * Prüft eine Spalte des Formulars. $current: bisherige Spalte beim Ändern — ihr Typ und ihre Vorgabe gelten
     * wörtlich, auch wenn sie die engen Regeln für eigene Typen und Ausdrücke nicht erfüllen.
     *
     * @throws ValidationException
     */
    private function checkColumn(string $field, ColumnSpec $spec, DatabaseDriver $driver, ?ColumnSpec $current): void
    {
        $this->checkNewName("{$field}.name", $spec->name);

        $error = fn (string $key, string $message): ValidationException => ValidationException::withMessages(["{$field}.{$key}" => $message]);

        if (! ColumnTypes::offers($driver, $spec->type)) {
            throw $error('type', __('Diesen Typ gibt es für :driver hier nicht.', ['driver' => $driver->label()]));
        }

        if ($spec->type === ColumnTypes::CUSTOM) {
            $custom = (string) $spec->custom;
            $verbatim = $current !== null && $custom === ($current->custom ?? $current->type);

            if ($custom === '' || (! $verbatim && preg_match(self::CUSTOM_TYPE, $custom) !== 1)) {
                throw $error('custom', __('Der eigene Typ muss wie ein Typ aussehen, etwa «numeric(12,4)» oder «integer[]» — ohne Anführungszeichen.'));
            }
        }

        if (ColumnTypes::params($spec->type) === 'length' && $spec->length !== null && ($spec->length < 1 || $spec->length > 65535)) {
            throw $error('length', __('Die Länge liegt zwischen 1 und 65535.'));
        }

        if ($spec->type === 'char' && $spec->length === null) {
            throw $error('length', __('Für char braucht es eine Länge.'));
        }

        if (ColumnTypes::params($spec->type) === 'precision' && ($spec->precision !== null || $spec->scale !== null)
            && (($spec->precision ?? 10) < 1 || ($spec->precision ?? 10) > 65 || ($spec->scale ?? 0) < 0 || ($spec->scale ?? 0) > ($spec->precision ?? 10))) {
            throw $error('precision', __('Genauigkeit 1 bis 65, Nachkommastellen 0 bis zur Genauigkeit.'));
        }

        if ($spec->type === 'enum') {
            if ($spec->values === [] || count($spec->values) !== count(array_unique($spec->values))) {
                throw $error('values', __('Eine Auswahl braucht mindestens einen Wert, jeden nur einmal.'));
            }

            if (array_any($spec->values, fn (string $value): bool => str_contains($value, '\\') || mb_strlen($value) > 255)) {
                throw $error('values', __('Werte der Auswahl ohne Backslash und höchstens 255 Zeichen.'));
            }
        }

        if ($spec->autoIncrement && ! ColumnTypes::isInteger($spec->type)) {
            throw $error('autoIncrement', __('Auto-Increment geht nur bei Ganzzahlen.'));
        }

        if ($spec->autoIncrement && ($spec->nullable || $spec->defaultKind !== 'none')) {
            throw $error('autoIncrement', __('Eine Auto-Increment-Spalte ist nie NULL und hat keine Vorgabe.'));
        }

        if ($spec->defaultKind === 'null' && ! $spec->nullable) {
            throw $error('default', __('NULL als Vorgabe geht nur, wenn die Spalte NULL erlaubt.'));
        }

        if ($spec->defaultKind === 'value' && mb_strlen((string) $spec->defaultValue) > 1000) {
            throw $error('default', __('Die Vorgabe ist zu lang.'));
        }

        if ($spec->defaultKind === 'expression') {
            $expression = (string) $spec->defaultValue;
            $verbatim = $current !== null && $current->defaultKind === 'expression' && $expression === $current->defaultValue;

            if (! $verbatim && ! in_array(strtoupper($expression), self::EXPRESSIONS, true)) {
                throw $error('default', __('Als Ausdruck geht hier CURRENT_TIMESTAMP — oder die bisherige Vorgabe der Spalte.'));
            }
        }

        if ($spec->comment !== null && mb_strlen($spec->comment) > 1024) {
            throw $error('comment', __('Der Kommentar ist zu lang (höchstens 1024 Zeichen).'));
        }
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function checkPrimary(TableDraft $draft, array $names, ?ColumnSpec $autoIncrement): array
    {
        $primary = array_values(array_unique($draft->primary));

        if ($autoIncrement !== null) {
            if ($primary !== [] && $primary !== [$autoIncrement->name]) {
                throw ValidationException::withMessages(['primary' => __('Mit einer Auto-Increment-Spalte ist sie allein der Primärschlüssel.')]);
            }

            return [];
        }

        foreach ($primary as $column) {
            if (! in_array($column, $names, true)) {
                throw ValidationException::withMessages(['primary' => __('Der Primärschlüssel nennt eine Spalte, die es nicht gibt.')]);
            }
        }

        return $primary;
    }

    /**
     * @param  array<int, IndexSpec>  $indexes  nach ihrer Stelle im Formular
     * @param  list<string>  $columns  Spalten nach der Änderung
     * @param  list<string>  $taken  Namen bestehender Indizes, die bleiben
     */
    private function checkNewIndexes(array $indexes, array $columns, string $table, array $taken): void
    {
        $names = array_map(mb_strtolower(...), $taken);

        foreach ($indexes as $position => $index) {
            $field = "indexes.{$position}";

            if ($index->columns === [] || array_diff($index->columns, $columns) !== [] || count($index->columns) !== count(array_unique($index->columns))) {
                throw ValidationException::withMessages(["{$field}.columns" => __('Ein Index braucht Spalten der Tabelle, jede nur einmal.')]);
            }

            $name = $index->nameFor($table);
            $this->checkNewName("{$field}.name", $name);

            if (in_array(mb_strtolower($name), $names, true)) {
                throw ValidationException::withMessages(["{$field}.name" => __('Einen Index mit diesem Namen gibt es schon.')]);
            }

            $names[] = mb_strtolower($name);
        }
    }

    /**
     * Neue Fremdschlüssel: Spalten dieser Tabelle, Ziel im selben Schema (oder die Tabelle selbst), gleich viele
     * Spalten auf beiden Seiten.
     *
     * @param  array<int, ForeignKeySpec>  $foreignKeys  nach ihrer Stelle im Formular
     * @param  list<string>  $columns  Spalten dieser Tabelle nach der Änderung
     */
    private function checkNewForeignKeys(Connection $target, DatabaseConnection $connection, string $schema, string $table, array $foreignKeys, array $columns): void
    {
        $tables = $this->inspector->tablesOn($target, $connection, $schema, withViews: false);

        foreach ($foreignKeys as $position => $foreignKey) {
            $field = "foreignKeys.{$position}";

            if ($foreignKey->columns === [] || array_diff($foreignKey->columns, $columns) !== []) {
                throw ValidationException::withMessages(["{$field}.columns" => __('Ein Fremdschlüssel braucht Spalten dieser Tabelle.')]);
            }

            $self = $foreignKey->table === $table;

            if (! $self && ! in_array($foreignKey->table, $tables, true)) {
                throw ValidationException::withMessages(["{$field}.table" => __('Diese Tabelle gibt es im Schema nicht.')]);
            }

            if (! SchemaName::isSafe($foreignKey->table)) {
                throw ValidationException::withMessages(["{$field}.table" => __('Dieser Name lässt sich hier nicht verarbeiten — nutzen Sie den SQL-Editor.')]);
            }

            $targetColumns = $self ? $columns : array_column($target->getSchemaBuilder()->getColumns($schema.'.'.$foreignKey->table), 'name');

            if ($foreignKey->references === [] || count($foreignKey->references) !== count($foreignKey->columns) || array_diff($foreignKey->references, $targetColumns) !== []) {
                throw ValidationException::withMessages(["{$field}.references" => __('Die Zielspalten müssen in der Zieltabelle stehen, gleich viele wie die Spalten.')]);
            }

            if (array_any($foreignKey->references, fn (string $column): bool => ! SchemaName::isSafe($column))) {
                throw ValidationException::withMessages(["{$field}.references" => __('Dieser Name lässt sich hier nicht verarbeiten — nutzen Sie den SQL-Editor.')]);
            }

            $this->checkNewName("{$field}.name", $foreignKey->nameFor($table));
        }
    }

    /**
     * MySQL setzt den Tabellenkommentar mit verdoppelten Anführungszeichen ein, liest aber «\» als Escape.
     */
    private function checkTableComment(DatabaseDriver $driver, string $comment): void
    {
        if (mb_strlen($comment) > 2048 || (in_array($driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true) && str_contains($comment, '\\'))) {
            throw ValidationException::withMessages(['comment' => __('Der Tabellenkommentar hat höchstens 2048 Zeichen und bei MySQL und MariaDB keinen Backslash.')]);
        }
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function addName(array $names, string $field, string $name): array
    {
        if (in_array(mb_strtolower($name), array_map(mb_strtolower(...), $names), true)) {
            throw ValidationException::withMessages([$field => __('Diesen Spaltennamen gibt es schon.')]);
        }

        $names[] = $name;

        return $names;
    }

    private function checkNewName(string $field, string $name): void
    {
        if (($problem = SchemaName::problem($name)) !== null) {
            throw ValidationException::withMessages([$field => $problem]);
        }
    }

    private function checkSchema(Connection $target, DatabaseConnection $connection, string $schema): void
    {
        if (! in_array($schema, $this->inspector->schemasOn($target, $connection), true)) {
            throw ValidationException::withMessages(['schema' => __('Dieses Schema gibt es nicht.')]);
        }
    }

    /**
     * Eine Tabelle (keine Sicht) des Schemas, mit einem Namen, den der Plan sicher einsetzen kann.
     */
    private function checkTable(Connection $target, DatabaseConnection $connection, string $schema, string $table): void
    {
        $this->checkSchema($target, $connection, $schema);

        if (! in_array($table, $this->inspector->tablesOn($target, $connection, $schema, withViews: false), true)) {
            throw ValidationException::withMessages(['table' => __('Diese Tabelle gibt es nicht.')]);
        }

        if (! SchemaName::isSafe($table) || ! SchemaName::isSafe($schema)) {
            throw ValidationException::withMessages(['table' => __('Diese Tabelle hat einen Namen, den phpdbadmin hier nicht ändern kann — nutzen Sie den SQL-Editor.')]);
        }
    }

    private function checkFreeName(Connection $target, DatabaseConnection $connection, string $schema, string $name): void
    {
        $this->checkNewName('to', $name);

        if ($this->exists($target, $connection, $schema, $name)) {
            throw ValidationException::withMessages(['to' => __('Eine Tabelle oder Sicht mit diesem Namen gibt es schon.')]);
        }
    }

    /**
     * Schemas anlegen und löschen gibt es nur auf Servern; MySQL und MariaDB mit festem Datenbanknamen zeigen neue
     * Datenbanken gar nicht — dort ist es gesperrt.
     */
    private function checkServerSchemas(DatabaseConnection $connection): void
    {
        if (! $connection->driver->isServer()) {
            throw ValidationException::withMessages(['name' => __('SQLite kennt keine Schemas zum Anlegen — legen Sie eine neue Datei an.')]);
        }
    }

    private function exists(Connection $target, DatabaseConnection $connection, string $schema, string $name): bool
    {
        return in_array(mb_strtolower($name), array_map(mb_strtolower(...), $this->inspector->tablesOn($target, $connection, $schema, withViews: true)), true);
    }

    private function rowEstimate(Connection $target, DatabaseConnection $connection, string $schema, string $table): ?int
    {
        $summary = array_find($connection->driver->dialect()->tables($target, $schema), fn (array $item): bool => $item['name'] === $table);

        if ($summary !== null && $summary['rows'] !== null) {
            return $summary['rows'];
        }

        // SQLite führt keine Schätzung — die Zahl kommt dann aus count(*), begrenzt auf die erste Million.
        if ($connection->driver === DatabaseDriver::Sqlite) {
            return (int) $target->selectOne('select count(*) as total from (select 1 from '.SchemaName::qualified(DatabaseDriver::Sqlite, $schema, $table).' limit 1000000)')?->total;
        }

        return null;
    }

    /**
     * Ein- und Mehrzahl als eigene Texte statt trans_choice(): Die deutschen Texte sind die Schlüssel und stehen in
     * keiner de.json — trans_choice() fiele dann auf die Rückfallsprache Englisch zurück.
     */
    private function rowsLoss(Connection $target, DatabaseConnection $connection, string $schema, string $table): string
    {
        $rows = $this->rowEstimate($target, $connection, $schema, $table);

        return match (true) {
            $rows === null => __('Alle Zeilen der Tabelle gehen verloren.'),
            $rows === 1 => __('≈ eine Zeile geht verloren.'),
            default => __('≈ :count Zeilen gehen verloren.', ['count' => $this->number($rows)]),
        };
    }

    private function columnLoss(string $column, ?int $rows): string
    {
        return match (true) {
            $rows === null => __('Die Spalte :column wird mit allen Werten gelöscht.', ['column' => $column]),
            $rows === 1 => __('Die Spalte :column wird mit allen Werten gelöscht (≈ eine Zeile).', ['column' => $column]),
            default => __('Die Spalte :column wird mit allen Werten gelöscht (≈ :count Zeilen).', ['column' => $column, 'count' => $this->number($rows)]),
        };
    }

    private function schemaLoss(int $tables): string
    {
        return $tables === 1
            ? __('Gelöscht wird alles darin: eine Tabelle oder Sicht mit allen Daten.')
            : __('Gelöscht wird alles darin: :count Tabellen und Sichten mit allen Daten.', ['count' => $tables]);
    }

    /** Zahl im Schweizer Format («12’406»). */
    private function number(int $value): string
    {
        return number_format($value, 0, '.', '’');
    }

    /** Zeichenkette als SQL-Literal über PDO::quote der Zielverbindung. */
    private function literal(Connection $target, string $value): string
    {
        return (string) $target->getPdo()->quote($value);
    }

    /** MySQL verlangt für Ausdrücke ausser CURRENT_TIMESTAMP Klammern (DEFAULT (uuid())). */
    private function expression(DatabaseDriver $driver, string $expression): string
    {
        $isTimestamp = preg_match('/^current_timestamp(\(\d*\))?$/i', $expression) === 1;

        return in_array($driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true) && ! $isTimestamp && ! str_starts_with($expression, '(')
            ? '('.$expression.')'
            : $expression;
    }

    /**
     * MariaDB führt JSON als longtext mit dem Check json_valid(spalte).
     *
     * @return list<string>
     */
    private function mariaDbJsonColumns(Connection $target, string $schema, string $table): array
    {
        $columns = [];

        foreach (DatabaseDriver::MariaDb->dialect()->checkConstraints($target, $schema, $table) as $check) {
            if (preg_match('/^\s*json_valid\(\s*`?([^`)]+)`?\s*\)\s*$/i', $check['definition'], $match) === 1) {
                $columns[] = $match[1];
            }
        }

        return $columns;
    }

    /**
     * MySQL zeigt Vorgaben ohne Anführungszeichen; Ausdrücke tragen in EXTRA den Vermerk DEFAULT_GENERATED.
     *
     * @return list<string>
     */
    private function mySqlGeneratedDefaults(Connection $target, string $schema, string $table): array
    {
        return array_values(array_map(fn (object $row): string => (string) (((array) $row)['name'] ?? ''), $target->select(
            "select column_name as name from information_schema.columns where table_schema = ? and table_name = ? and extra like '%DEFAULT_GENERATED%'",
            [$schema, $table],
        )));
    }

    private function hasSqliteSequence(Connection $target, string $schema): bool
    {
        return $target->selectOne('select 1 as found from '.SchemaName::quote(DatabaseDriver::Sqlite, $schema).".sqlite_schema where type = 'table' and name = 'sqlite_sequence'") !== null;
    }

    /**
     * PostgreSQL: LIKE … INCLUDING ALL übernimmt Vorgaben, Checks, Indizes, Kommentare und identity; serial-Spalten
     * zeigen danach aber auf die Sequenz des Originals. Deshalb bekommt die Kopie eigene Sequenzen, und nach den Daten
     * stehen alle Zähler hinter dem grössten Wert.
     *
     * @return list<string>
     */
    private function postgresCopy(Connection $target, string $schema, string $table, string $to, bool $withData): array
    {
        $driver = DatabaseDriver::Postgres;
        $from = SchemaName::qualified($driver, $schema, $table);
        $copy = SchemaName::qualified($driver, $schema, $to);
        $statements = ['CREATE TABLE '.$copy.' (LIKE '.$from.' INCLUDING ALL)'];
        $counters = [];

        foreach ($target->getSchemaBuilder()->getColumns($schema.'.'.$table) as $column) {
            $name = (string) $column['name'];
            $default = (string) ($column['default'] ?? '');

            if (str_starts_with($default, 'nextval(')) {
                $sequence = SchemaName::conventional($to, [$name], 'seq');
                $qualifiedSequence = SchemaName::qualified($driver, $schema, $sequence);
                $statements[] = 'CREATE SEQUENCE '.$qualifiedSequence.' OWNED BY '.$copy.'.'.SchemaName::quote($driver, $name);
                $statements[] = 'ALTER TABLE '.$copy.' ALTER COLUMN '.SchemaName::quote($driver, $name).' SET DEFAULT nextval('.$this->literal($target, $qualifiedSequence).'::regclass)';
                $counters[] = [$name, $this->literal($target, $qualifiedSequence)];
            } elseif ((bool) $column['auto_increment']) {
                $counters[] = [$name, 'pg_get_serial_sequence('.$this->literal($target, $copy).', '.$this->literal($target, $name).')'];
            }
        }

        if (! $withData) {
            return $statements;
        }

        $statements[] = 'INSERT INTO '.$copy.' OVERRIDING SYSTEM VALUE SELECT * FROM '.$from;

        foreach ($counters as [$column, $sequence]) {
            $quoted = SchemaName::quote($driver, $column);
            $statements[] = 'SELECT setval('.$sequence.', COALESCE(MAX('.$quoted.'), 1), MAX('.$quoted.') IS NOT NULL) FROM '.$copy;
        }

        return $statements;
    }

    /**
     * SQLite: CREATE-Text der Tabelle und ihrer Indizes aus der Datei, mit dem neuen Namen — so bleiben Typen,
     * Vorgaben, Checks und Primärschlüssel genau gleich.
     *
     * @return list<string>
     */
    private function sqliteCopy(Connection $target, string $schema, string $table, string $to, bool $withData): array
    {
        $driver = DatabaseDriver::Sqlite;
        $catalog = SchemaName::quote($driver, $schema).'.sqlite_schema';
        $create = (string) $target->selectOne('select sql from '.$catalog." where type = 'table' and name = ?", [$table])?->sql;
        $name = '(?:"(?:[^"]|"")+"|`(?:[^`]|``)+`|\[[^\]]+\]|[^\s(]+)';

        $statements = [(string) preg_replace('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?'.$name.'/i', 'CREATE TABLE '.SchemaName::qualified($driver, $schema, $to), $create, 1)];

        foreach ($target->select('select name, sql from '.$catalog." where type = 'index' and tbl_name = ? and sql is not null order by name", [$table]) as $index) {
            $indexName = (string) $index->name;
            $newName = str_starts_with($indexName, $table.'_') ? $to.substr($indexName, strlen($table)) : $to.'_'.$indexName;
            $statements[] = (string) preg_replace(
                '/^\s*CREATE\s+(UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?'.$name.'\s+ON\s+'.$name.'/i',
                'CREATE $1INDEX '.SchemaName::qualified($driver, $schema, mb_strcut($newName, 0, SchemaName::MAX_BYTES)).' ON '.SchemaName::quote($driver, $to),
                (string) $index->sql,
                1,
            );
        }

        if ($withData) {
            $statements[] = 'INSERT INTO '.SchemaName::qualified($driver, $schema, $to).' SELECT * FROM '.SchemaName::qualified($driver, $schema, $table);
        }

        return $statements;
    }

    /**
     * SQLite-Umbau nach sqlite.org (lang_altertable, Abschnitt 7): Laravel legt die Indizes neu an, nicht aber
     * Check-Constraints, Trigger und Sichten. Checks kommen in die neue Tabelle (der Umbau läuft vor jedem Umbenennen,
     * ihr Text stimmt also noch). Sichten und Trigger der Datei werden vor dem Umbau gelöscht und danach mit ihrem
     * gespeicherten Text neu angelegt — sonst scheitert das Umbenennen an jeder Sicht, die auf die Tabelle zeigt («error
     * in view …: no such table»), und die Trigger der Tabelle gingen mit DROP TABLE verloren.
     *
     * @param  list<string>  $statements
     * @return list<string>
     */
    private function keepSqliteExtras(Connection $target, string $schema, string $table, array $statements): array
    {
        $driver = DatabaseDriver::Sqlite;
        $checks = $driver->dialect()->checkConstraints($target, $schema, $table);
        $catalog = SchemaName::quote($driver, $schema).'.sqlite_schema';
        $views = $target->select('select name, sql from '.$catalog." where type = 'view' and sql is not null order by rowid");
        $triggers = $target->select('select name, sql from '.$catalog." where type = 'trigger' and sql is not null order by rowid");

        $result = [];
        $rebuilt = false;

        foreach ($statements as $sql) {
            $isTemporaryTable = preg_match('/^create table (?:"[^"]*"\.)?"__temp__/i', $sql) === 1;

            if ($isTemporaryTable && ! $rebuilt) {
                foreach ($triggers as $trigger) {
                    $result[] = 'DROP TRIGGER '.SchemaName::qualified($driver, $schema, (string) $trigger->name);
                }

                foreach (array_reverse($views) as $view) {
                    $result[] = 'DROP VIEW '.SchemaName::qualified($driver, $schema, (string) $view->name);
                }
            }

            if ($isTemporaryTable && $checks !== []) {
                $constraints = array_map(fn (array $check): string => (str_starts_with($check['name'], 'check_') ? '' : 'constraint '.SchemaName::quote($driver, $check['name']).' ').'check ('.$check['definition'].')', $checks);
                $sql = substr($sql, 0, -1).', '.implode(', ', $constraints).')';
            }

            $result[] = $sql;

            if (! $rebuilt && preg_match('/^alter table (?:"[^"]*"\.)?"__temp__.* rename to /i', $sql) === 1) {
                $rebuilt = true;

                foreach ([...$views, ...$triggers] as $object) {
                    $result[] = (string) $object->sql;
                }
            }
        }

        return $result;
    }
}
