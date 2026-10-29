<?php

namespace Tests\Feature\Services\Shape;

use App\Enums\DatabaseDriver;
use App\Services\Shape\ChangeScriptBuilder;
use App\Services\Shape\Comparison;
use App\Services\Shape\ShapeComparer;
use App\Services\Shape\ShapeReader;
use App\Services\Shape\TableDifference;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ShapeSamples;
use Tests\TestCase;

/**
 * Schemavergleich (S-08) mit zwei Ständen: je Treiber zwei Schemas auf demselben Server, dazu gegenseitig zwischen
 * zwei Treibern. Abnahme: Das Änderungsskript, auf das Ziel angewendet, lässt beim erneuten Vergleich keine
 * Unterschiede in Tabellen, Spalten (Art), Indizes und Fremdschlüsseln.
 *
 * @phpstan-import-type ColumnDifference from TableDifference
 */
#[Group('target-databases')]
class ShapeComparerTest extends TestCase
{
    use ShapeSamples;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/vergleich-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'phpdbadmin.connections.sqlite_paths' => [$this->folder],
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanUpShapes();
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: DatabaseDriver}>
     */
    public static function drivers(): array
    {
        return [...self::serverDrivers(), 'SQLite' => [DatabaseDriver::Sqlite]];
    }

    /**
     * @return array<string, array{0: DatabaseDriver, 1: DatabaseDriver}>
     */
    public static function driverPairs(): array
    {
        return [
            'MySQL → PostgreSQL' => [DatabaseDriver::MySql, DatabaseDriver::Postgres],
            'PostgreSQL → MySQL' => [DatabaseDriver::Postgres, DatabaseDriver::MySql],
            'SQLite → MariaDB' => [DatabaseDriver::Sqlite, DatabaseDriver::MariaDb],
            'MariaDB → SQLite' => [DatabaseDriver::MariaDb, DatabaseDriver::Sqlite],
            'PostgreSQL → SQLite' => [DatabaseDriver::Postgres, DatabaseDriver::Sqlite],
            'MySQL → MariaDB' => [DatabaseDriver::MySql, DatabaseDriver::MariaDb],
        ];
    }

    #[DataProvider('drivers')]
    public function test_finds_every_difference_between_two_states_on_the_same_driver(DatabaseDriver $driver): void
    {
        [$source, $sourceSchema] = $this->firstSide($driver, $this->folder);
        [$target, $targetSchema] = $this->secondSide($driver, $this->folder);
        $this->createNewState($source, $sourceSchema);
        $this->createOldState($target, $targetSchema);

        $comparison = $this->compare($driver, $source, $sourceSchema, $driver, $target, $targetSchema);

        $tables = array_column(array_map(fn (TableDifference $table): array => ['name' => $table->name, 'status' => $table->status], $comparison->tables), 'status', 'name');
        $this->assertSame(['artikel' => 'added', 'bestellungen' => 'changed', 'kunden' => 'changed', 'archiv' => 'removed'], $tables);

        $customers = $this->difference($comparison, 'kunden');
        $columns = $this->columnChanges($customers);
        $this->assertSame([
            // SQLite speichert keine Länge bei varchar — dort sind 50 und 100 Zeichen dasselbe.
            ...($driver === DatabaseDriver::Sqlite ? [] : ['email' => 'changed:type']),
            'name' => 'changed:nullable',
            'status' => 'changed:default',
            'aktiv' => 'added',
            'meta' => 'added',
            'alt_feld' => 'removed',
        ], $columns);
        $this->assertSame(['added:UNIQUE (email)', 'removed:INDEX (email)'], $this->indexChanges($customers));

        $orders = $this->difference($comparison, 'bestellungen');
        $this->assertSame([], $this->columnChanges($orders));
        $this->assertSame(['added:INDEX (total)'], $this->indexChanges($orders));
        $this->assertCount(1, $orders->foreignKeys);
        $this->assertSame('changed', $orders->foreignKeys[0]['status']);
        $this->assertSame('cascade', $orders->foreignKeys[0]['source']?->onDelete);
        $this->assertSame('no action', $orders->foreignKeys[0]['target']?->onDelete, 'RESTRICT und NO ACTION gelten als gleich');
    }

    #[DataProvider('drivers')]
    public function test_script_applied_to_the_target_leaves_no_difference_on_the_same_driver(DatabaseDriver $driver): void
    {
        [$source, $sourceSchema] = $this->firstSide($driver, $this->folder);
        [$target, $targetSchema] = $this->secondSide($driver, $this->folder);
        $this->createNewState($source, $sourceSchema);
        $this->createOldState($target, $targetSchema);

        $script = app(ChangeScriptBuilder::class)->build($this->compare($driver, $source, $sourceSchema, $driver, $target, $targetSchema), $target, withDrops: true);
        $this->applyStatements($target, $script->statements());

        $again = $this->compare($driver, $source, $sourceSchema, $driver, $target, $targetSchema);
        $this->assertSame([], $this->describe($again), implode("\n", $script->statements()));
    }

    #[DataProvider('driverPairs')]
    public function test_script_applied_to_the_target_leaves_no_difference_between_two_drivers(DatabaseDriver $sourceDriver, DatabaseDriver $targetDriver): void
    {
        [$source, $sourceSchema] = $this->firstSide($sourceDriver, $this->folder);
        // Verschiedene Treiber liegen auf verschiedenen Servern; nur SQLite braucht eine zweite Datei.
        [$target, $targetSchema] = $targetDriver === DatabaseDriver::Sqlite
            ? $this->secondSide($targetDriver, $this->folder)
            : $this->firstSide($targetDriver, $this->folder);
        $this->createNewState($source, $sourceSchema);
        $this->createOldState($target, $targetSchema);

        $before = $this->compare($sourceDriver, $source, $sourceSchema, $targetDriver, $target, $targetSchema);
        $this->assertNotSame([], $before->tables);

        $script = app(ChangeScriptBuilder::class)->build($before, $target, withDrops: true);
        $this->applyStatements($target, $script->statements());

        $again = $this->compare($sourceDriver, $source, $sourceSchema, $targetDriver, $target, $targetSchema);
        $this->assertSame([], $this->describe($again), implode("\n", $script->statements()));
    }

    #[DataProvider('drivers')]
    public function test_script_without_drops_keeps_what_only_the_target_has(DatabaseDriver $driver): void
    {
        [$source, $sourceSchema] = $this->firstSide($driver, $this->folder);
        [$target, $targetSchema] = $this->secondSide($driver, $this->folder);
        $this->createNewState($source, $sourceSchema);
        $this->createOldState($target, $targetSchema);

        $script = app(ChangeScriptBuilder::class)->build($this->compare($driver, $source, $sourceSchema, $driver, $target, $targetSchema), $target, withDrops: false);
        $this->applyStatements($target, $script->statements());

        $again = $this->compare($driver, $source, $sourceSchema, $driver, $target, $targetSchema);
        $this->assertSame([
            'kunden' => ['column alt_feld removed', 'index INDEX (email) removed'],
            'archiv' => ['table removed'],
        ], $this->describe($again));
        $this->assertContains('Tabelle «kunden»: Spalte «alt_feld» steht nur im Ziel und bleibt.', $script->notes());
    }

    private function compare(DatabaseDriver $sourceDriver, Connection $source, string $sourceSchema, DatabaseDriver $targetDriver, Connection $target, string $targetSchema): Comparison
    {
        $reader = app(ShapeReader::class);

        return app(ShapeComparer::class)->compare(
            $reader->schema($source, $sourceDriver, $sourceSchema),
            $reader->schema($target, $targetDriver, $targetSchema),
        );
    }

    private function difference(Comparison $comparison, string $table): TableDifference
    {
        $difference = array_find($comparison->tables, fn (TableDifference $difference): bool => $difference->name === $table);
        $this->assertNotNull($difference, 'Kein Unterschied für '.$table);

        return $difference;
    }

    /**
     * @return array<string, string>
     */
    private function columnChanges(TableDifference $table): array
    {
        $changes = [];
        foreach ($table->columns as $column) {
            $changes[$column['name']] = $column['status'].($column['changes'] !== [] ? ':'.implode(',', $column['changes']) : '');
        }

        return $changes;
    }

    /**
     * @return list<string>
     */
    private function indexChanges(TableDifference $table): array
    {
        return array_map(fn (array $index): string => $index['status'].':'.($index['source'] ?? $index['target'])?->summary(), $table->indexes);
    }

    /**
     * Was übrig bleibt, lesbar für die Fehlermeldung.
     *
     * @return array<string, list<string>>
     */
    private function describe(Comparison $comparison): array
    {
        $described = [];

        foreach ($comparison->tables as $table) {
            if ($table->status !== Comparison::CHANGED) {
                $described[$table->name][] = 'table '.$table->status;

                continue;
            }

            foreach ($table->columns as $column) {
                $described[$table->name][] = 'column '.$column['name'].' '.$column['status'].($column['changes'] !== [] ? ' ('.implode(',', $column['changes']).': '.$column['source']?->summary().' ≠ '.$column['target']?->summary().')' : '');
            }

            foreach ($table->indexes as $index) {
                $described[$table->name][] = 'index '.($index['source'] ?? $index['target'])?->summary().' '.$index['status'];
            }

            foreach ($table->foreignKeys as $foreignKey) {
                $described[$table->name][] = 'foreign key '.($foreignKey['source'] ?? $foreignKey['target'])?->summary().' '.$foreignKey['status'];
            }
        }

        return $described;
    }
}
