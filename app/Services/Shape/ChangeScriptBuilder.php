<?php

namespace App\Services\Shape;

use App\Enums\DatabaseDriver;
use App\Support\TrustedSql;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Throwable;

/**
 * Baut aus einem Vergleich das Änderungsskript im Dialekt des Ziels (S-08) — über Laravels Schema-Grammatik der
 * Zielverbindung (`Blueprint::toSql()`), nie über `pretend()` (ADR-003). Nichts wird ausgeführt.
 *
 * Reihenfolge: Fremdschlüssel entfernen, die wegfallen oder sich ändern; Tabellen entfernen (nur auf Wunsch); neue
 * Tabellen anlegen, abhängige nach ihren Zielen; bestehende Tabellen ändern; neue Fremdschlüssel. SQLite baut eine
 * Tabelle für Fremdschlüssel und geänderte Spalten neu — dort steht alles einer Tabelle in einem Blueprint, sonst
 * läse der zweite Umbau den alten Stand.
 *
 * Was «entfernen» heisst (Tabellen, Spalten, Indizes und Fremdschlüssel, die nur im Ziel stehen), kommt nur mit
 * `withDrops` ins Skript.
 */
final class ChangeScriptBuilder
{
    private Connection $connection;

    private DatabaseDriver $driver;

    private string $schema;

    private bool $sameDriver;

    private ChangeScript $script;

    /** @var array<string, array<string, LaravelColumn>> Spalten von Fremdschlüsseln, angeglichen an ihr Ziel */
    private array $references = [];

    /**
     * @param  list<string>  $header  Kommentarzeilen für den Kopf des Skripts
     */
    public function build(Comparison $comparison, Connection $target, bool $withDrops, array $header = []): ChangeScript
    {
        $this->connection = $target;
        $this->driver = $comparison->target->driver;
        $this->schema = $comparison->target->schema;
        $this->sameDriver = $comparison->sameDriver();
        $this->script = new ChangeScript($header);
        $this->references = $this->sameDriver ? [] : PortableColumns::referenceTypes($comparison->source);

        $added = array_values(array_filter($comparison->tables, fn (TableDifference $table): bool => $table->status === Comparison::ADDED));
        $removed = array_values(array_filter($comparison->tables, fn (TableDifference $table): bool => $table->status === Comparison::REMOVED));
        $changed = array_values(array_filter($comparison->tables, fn (TableDifference $table): bool => $table->status === Comparison::CHANGED));
        $existing = array_map('strtolower', array_keys($comparison->target->tables));
        $isSqlite = $this->driver === DatabaseDriver::Sqlite;

        if (! $isSqlite) {
            foreach ($changed as $table) {
                $this->step($table->name, __('Tabelle «:table»: Fremdschlüssel entfernen', ['table' => $table->name]), fn (Blueprint $blueprint) => $this->dropForeignKeys($blueprint, $table, $withDrops));
            }
        }

        if ($withDrops) {
            foreach (PortableColumns::dependentsFirst(array_values(array_filter(array_map(fn (TableDifference $table): ?TableShape => $table->target, $removed)))) as $table) {
                $this->step($table->name, __('Tabelle «:table» entfernen (nur im Ziel)', ['table' => $table->name]), fn (Blueprint $blueprint) => $blueprint->drop());
            }
        } elseif ($removed !== []) {
            $this->script->note(trans_choice(':count Tabelle steht nur im Ziel und bleibt.|:count Tabellen stehen nur im Ziel und bleiben.', count($removed)));
        }

        $deferred = [];
        $created = [];
        foreach (PortableColumns::dependenciesFirst(array_values(array_filter(array_map(fn (TableDifference $table): ?TableShape => $table->source, $added)))) as $table) {
            $available = [...$existing, ...$created, strtolower($table->name)];
            $this->step($table->name, __('Tabelle «:table» anlegen', ['table' => $table->name]), function (Blueprint $blueprint) use ($table, $available, $isSqlite, &$deferred): void {
                $blueprint->create();
                $this->createTable($blueprint, $table);

                foreach ($table->foreignKeys as $foreignKey) {
                    // SQLite prüft das Ziel eines Fremdschlüssels erst beim Schreiben — dort geht alles gleich mit.
                    if ($isSqlite || in_array(strtolower($foreignKey->foreignTable), $available, true)) {
                        $this->addForeignKey($blueprint, $table->name, $foreignKey);
                    } else {
                        $deferred[] = [$table->name, $foreignKey];
                    }
                }
            });
            $created[] = strtolower($table->name);
        }

        foreach ($changed as $table) {
            $this->step($table->name, __('Tabelle «:table» ändern', ['table' => $table->name]), fn (Blueprint $blueprint) => $this->alterTable($blueprint, $table, $withDrops, $isSqlite));
        }

        if (! $isSqlite) {
            foreach ($changed as $table) {
                $this->step($table->name, __('Tabelle «:table»: Fremdschlüssel anlegen', ['table' => $table->name]), fn (Blueprint $blueprint) => $this->addForeignKeys($blueprint, $table));
            }

            foreach ($deferred as [$tableName, $foreignKey]) {
                $this->step($tableName, __('Tabelle «:table»: Fremdschlüssel anlegen', ['table' => $tableName]), fn (Blueprint $blueprint) => $this->addForeignKey($blueprint, $tableName, $foreignKey));
            }
        }

        return $this->script;
    }

    /**
     * Ein Schritt: ein Blueprint für eine Tabelle, als SQL. Scheitert die Grammatik (etwas, das der Treiber nicht
     * kann), steht der Grund als Hinweis im Skript statt eines halben Skripts.
     *
     * @param  callable(Blueprint): mixed  $define
     */
    private function step(string $table, string $heading, callable $define): void
    {
        $blueprint = new Blueprint($this->connection, $this->qualified($table));

        try {
            $define($blueprint);
            $this->script->add($heading, array_values(array_map('strval', $blueprint->toSql())));
        } catch (Throwable $exception) {
            $this->script->note(__('Tabelle «:table»: :reason', ['table' => $table, 'reason' => $exception->getMessage()]));
        }
    }

    private function createTable(Blueprint $blueprint, TableShape $table): void
    {
        foreach ($table->columns as $column) {
            $this->defineColumn($blueprint, $table->name, $column);
        }

        $primary = $table->primary();

        if ($primary !== null && ! $table->primaryIsImplicit()) {
            $blueprint->primary($primary->columns);
        }

        foreach ($table->indexes as $index) {
            if ($index->kind !== IndexShape::PRIMARY && ! $table->isImplicitForeignKeyIndex($index)) {
                $this->addIndex($blueprint, $table->name, $index);
            }
        }

        foreach ($table->checks as $check) {
            $this->script->note(__('Tabelle «:table»: Check «:check» — Laravel kennt keine Checks, bitte von Hand übertragen.', ['table' => $table->name, 'check' => $check['definition']]));
        }
    }

    /**
     * Eine bestehende Tabelle ändern. Erst entfernen und hinzufügen, was SQLite ohne Umbau kann (Indizes, Spalten),
     * dann alles, was einen Umbau braucht, in einem Zug — sonst baute SQLite die Tabelle mehrfach um —, zuletzt neue
     * Indizes. Fremdschlüssel ändert dieser Schritt nur in SQLite; bei den Servern stehen sie in eigenen Schritten.
     */
    private function alterTable(Blueprint $blueprint, TableDifference $table, bool $withDrops, bool $isSqlite): void
    {
        foreach ($table->indexes as $index) {
            if ($index['status'] === Comparison::REMOVED && $index['target'] !== null && $index['target']->kind !== IndexShape::PRIMARY) {
                $this->dropOrKeep($withDrops, $table->name, $index['target']->summary(), fn () => $this->dropIndex($blueprint, $index['target']));
            }
        }

        foreach ($table->columns as $column) {
            if ($column['status'] === Comparison::REMOVED) {
                $this->dropOrKeep($withDrops, $table->name, __('Spalte «:column»', ['column' => $column['name']]), fn () => $blueprint->dropColumn($column['name']));
            }
        }

        foreach ($table->columns as $column) {
            if ($column['status'] === Comparison::ADDED && $column['source'] !== null) {
                $this->defineColumn($blueprint, $table->name, $column['source']);

                if (! $column['source']->nullable && $column['source']->default === null) {
                    $this->script->note(__('Tabelle «:table»: neue Spalte «:column» ist NOT NULL ohne Vorgabe — auf einer Tabelle mit Zeilen scheitert das je nach Datenbank.', ['table' => $table->name, 'column' => $column['name']]));
                }
            }
        }

        if ($isSqlite) {
            $this->dropForeignKeys($blueprint, $table, $withDrops);
        }

        foreach ($table->columns as $column) {
            if ($column['status'] === Comparison::CHANGED && $column['source'] !== null) {
                $this->defineColumn($blueprint, $table->name, $column['source'], $column['target']?->name, changing: true)->change();
            }
        }

        foreach ($table->indexes as $index) {
            if ($index['status'] === Comparison::REMOVED && $index['target']?->kind === IndexShape::PRIMARY) {
                $this->dropOrKeep($withDrops, $table->name, $index['target']->summary(), fn () => $blueprint->dropPrimary($index['target']->name));
            }
        }

        foreach ($table->indexes as $index) {
            if ($index['status'] === Comparison::ADDED && $index['source']?->kind === IndexShape::PRIMARY) {
                $blueprint->primary($index['source']->columns);
            }
        }

        if ($isSqlite) {
            $this->addForeignKeys($blueprint, $table);
        }

        foreach ($table->indexes as $index) {
            if ($index['status'] === Comparison::ADDED && $index['source'] !== null && $index['source']->kind !== IndexShape::PRIMARY) {
                $this->addIndex($blueprint, $table->name, $index['source']);
            }
        }
    }

    /**
     * Was nur im Ziel steht: entfernen, wenn gewünscht — sonst ein Hinweis, dass es bleibt.
     *
     * @param  callable(): mixed  $drop
     */
    private function dropOrKeep(bool $withDrops, string $table, string $what, callable $drop): void
    {
        if ($withDrops) {
            $drop();
        } else {
            $this->script->note(__('Tabelle «:table»: :what steht nur im Ziel und bleibt.', ['table' => $table, 'what' => $what]));
        }
    }

    private function dropForeignKeys(Blueprint $blueprint, TableDifference $table, bool $withDrops): void
    {
        foreach ($table->foreignKeys as $foreignKey) {
            $existing = $foreignKey['target'];

            if ($existing === null) {
                continue;
            }

            if ($foreignKey['status'] === Comparison::REMOVED && ! $withDrops) {
                $this->script->note(__('Tabelle «:table»: :what steht nur im Ziel und bleibt.', ['table' => $table->name, 'what' => __('Fremdschlüssel :key', ['key' => $existing->summary()])]));

                continue;
            }

            if ($this->driver === DatabaseDriver::Sqlite) {
                $blueprint->dropForeign($existing->columns);
            } elseif ($existing->name !== null) {
                $blueprint->dropForeign($existing->name);
            }
        }
    }

    private function addForeignKeys(Blueprint $blueprint, TableDifference $table): void
    {
        foreach ($table->foreignKeys as $foreignKey) {
            if ($foreignKey['status'] !== Comparison::REMOVED && $foreignKey['source'] !== null) {
                $this->addForeignKey($blueprint, $table->name, $foreignKey['source']);
            }
        }
    }

    private function addForeignKey(Blueprint $blueprint, string $table, ForeignKeyShape $foreignKey): void
    {
        $definition = $blueprint->foreign($foreignKey->columns, $this->sameDriver && $foreignKey->name !== null ? $foreignKey->name : PortableColumns::name($table, $foreignKey->columns, 'foreign'))
            ->references($foreignKey->foreignColumns)
            ->on($this->reference($foreignKey->foreignTable));

        if ($foreignKey->onDelete !== 'no action') {
            $definition->onDelete($foreignKey->onDelete);
        }

        if ($foreignKey->onUpdate !== 'no action') {
            $definition->onUpdate($foreignKey->onUpdate);
        }
    }

    private function addIndex(Blueprint $blueprint, string $table, IndexShape $index): void
    {
        $mysqlFamily = ShapeComparer::family($this->driver) === 'mysql';
        $kind = $index->kind;

        if (in_array($kind, [IndexShape::FULLTEXT, IndexShape::SPATIAL], true) && ! $mysqlFamily) {
            $this->script->note(__('Tabelle «:table»: :index wird als gewöhnlicher Index angelegt.', ['table' => $table, 'index' => $index->summary()]));
            $kind = IndexShape::INDEX;
        }

        $name = $this->sameDriver ? $index->name : PortableColumns::name($table, $index->columns, match ($kind) {
            IndexShape::UNIQUE => 'unique',
            IndexShape::FULLTEXT => 'fulltext',
            IndexShape::SPATIAL => 'spatialindex',
            default => 'index',
        });

        match ($kind) {
            IndexShape::UNIQUE => $blueprint->unique($index->columns, $name),
            IndexShape::FULLTEXT => $blueprint->fullText($index->columns, $name),
            IndexShape::SPATIAL => $blueprint->spatialIndex($index->columns, $name),
            default => $blueprint->index($index->columns, $name),
        };
    }

    private function dropIndex(Blueprint $blueprint, IndexShape $index): void
    {
        // PostgreSQL sucht einen Index ohne Schema nur im Suchpfad — «drop index» braucht ihn deshalb mit Schema.
        $name = $this->driver === DatabaseDriver::Postgres ? $this->schema.'.'.$index->name : $index->name;

        match ($index->kind) {
            IndexShape::PRIMARY => $blueprint->dropPrimary($index->name),
            IndexShape::UNIQUE => $blueprint->dropUnique($index->name),
            IndexShape::FULLTEXT => $blueprint->dropFullText($name),
            IndexShape::SPATIAL => $blueprint->dropSpatialIndex($name),
            default => $blueprint->dropIndex($name),
        };
    }

    /**
     * Eine Spalte so, wie sie in der Quelle steht — als Laravel-Spalte für den Treiber des Ziels.
     *
     * @param  string|null  $name  Name im Ziel, wenn er sich nur in Gross- und Kleinschreibung unterscheidet
     */
    private function defineColumn(Blueprint $blueprint, string $table, ColumnShape $column, ?string $name = null, bool $changing = false): ColumnDefinition
    {
        $laravel = PortableColumns::forDriver($this->references[strtolower($table)][strtolower($column->name)] ?? $column->laravel, $this->driver);

        // PostgreSQL legt eine Aufzählung als Text mit Check an; ALTER COLUMN … TYPE kann den Check nicht mitnehmen.
        if ($changing && $this->driver === DatabaseDriver::Postgres && $laravel->type === 'enum') {
            $laravel = new LaravelColumn('string', ['length' => LaravelTypes::DEFAULT_STRING_LENGTH]);
            $this->script->note(__('Tabelle «:table», Spalte «:column»: Aufzählung in PostgreSQL — der Check bleibt, wie er ist; geänderte Werte bitte von Hand nachführen.', ['table' => $table, 'column' => $column->name]));
        }

        if ($laravel->note !== null) {
            $this->script->note(__('Tabelle «:table», Spalte «:column»: :note', ['table' => $table, 'column' => $column->name, 'note' => $laravel->note]));
        }

        $definition = $blueprint->addColumn($laravel->type, $name ?? $column->name, $laravel->parameters);
        $definition->nullable($column->nullable);

        if ($column->default !== null) {
            if ($this->sameDriver || $column->default->isPortable()) {
                // Ausdruck aus dem Katalog der Quelle (z. B. CURRENT_TIMESTAMP); zwischen Treibern nur die, die alle verstehen.
                $definition->default($column->default->isExpression
                    ? new TrustedSql($column->default->value)
                    : ($laravel->type === 'boolean' ? $column->default->asBoolean() : $column->default->value));
            } else {
                $this->script->note(__('Tabelle «:table», Spalte «:column»: Vorgabe :default lässt sich nicht übertragen.', ['table' => $table, 'column' => $column->name, 'default' => $column->default->display()]));
            }
        }

        if ($column->comment !== null) {
            $definition->comment($column->comment);
        }

        if ($column->generated !== null) {
            if ($this->sameDriver) {
                // Ausdruck aus dem Katalog der Quelle, gleicher Treiber.
                $expression = new TrustedSql($column->generated['expression']);
                $column->generated['type'] === 'virtual' ? $definition->virtualAs($expression) : $definition->storedAs($expression);
            } else {
                $this->script->note(__('Tabelle «:table», Spalte «:column»: berechnete Spalte — der Ausdruck lässt sich nicht übertragen, die Spalte wird gewöhnlich angelegt.', ['table' => $table, 'column' => $column->name]));
            }
        }

        return $definition;
    }

    /** Name im Ziel: in SQLite ohne «main.», sonst mit Schema bzw. Datenbank. */
    private function qualified(string $table): string
    {
        return $this->driver === DatabaseDriver::Sqlite && $this->schema === 'main' ? $table : $this->schema.'.'.$table;
    }

    /** Ziel eines Fremdschlüssels: SQLite kennt dort keinen Schemanamen. */
    private function reference(string $table): string
    {
        return $this->driver === DatabaseDriver::Sqlite ? $table : $this->schema.'.'.$table;
    }
}
