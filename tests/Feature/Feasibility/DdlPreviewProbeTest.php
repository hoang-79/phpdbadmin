<?php

namespace Tests\Feature\Feasibility;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * F-5 DDL mit Vorschau: Zeigt die Vorschau genau das SQL, das danach läuft — auch beim SQLite-Umbau?
 * Und: Wo lässt sich eine Folge von Änderungen zurückrollen?
 */
#[Group('feasibility')]
class DdlPreviewProbeTest extends ProbeTestCase
{
    #[DataProvider('drivers')]
    public function test_blueprint_preview_is_exactly_what_runs_for_create_and_alter(string $driver): void
    {
        $connection = $this->freshConnection($driver);

        $create = new Blueprint($connection, 'probe_items', function (Blueprint $table) {
            $table->create();
            $table->id();
            $table->string('sku', 40)->unique();
            $table->string('title', 120);
            $table->decimal('price', 8, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        $createPreview = $create->toSql();
        $this->assertSame($createPreview, $this->runPreview($connection, $createPreview));

        $connection->table('probe_items')->insert([
            ['sku' => 'A-1', 'title' => 'Äpfel', 'price' => 1.5, 'notes' => 'x'],
            ['sku' => 'B-2', 'title' => 'Birnen', 'price' => null, 'notes' => null],
            ['sku' => 'C-3', 'title' => 'Zitronen', 'price' => 3.25, 'notes' => 'y'],
        ]);

        $alter = new Blueprint($connection, 'probe_items', function (Blueprint $table) {
            $table->renameColumn('title', 'name');
            $table->decimal('price', 10, 2)->default(0)->nullable(false)->change();
            $table->dropColumn('notes');
            $table->integer('stock')->default(0);
            $table->index('name');
        });

        // Vorher muss NULL in price weg, sonst scheitert NOT NULL — das zeigt die Vorschau dem Menschen.
        $connection->table('probe_items')->whereNull('price')->update(['price' => 0]);

        [$alterPreview, $previewMs] = $this->measure(fn () => $alter->toSql());
        $this->assertSame($alterPreview, $this->runPreview($connection, $alterPreview));

        $schema = $connection->getSchemaBuilder();
        $columns = array_column($schema->getColumns('probe_items'), null, 'name');
        $this->assertEqualsCanonicalizing(['id', 'sku', 'name', 'price', 'created_at', 'stock'], array_keys($columns));
        $this->assertFalse($columns['price']['nullable']);
        $indexes = array_column($schema->getIndexes('probe_items'), 'columns');
        $this->assertContains(['name'], $indexes);
        $this->assertContains(['sku'], $indexes, 'Unique-Index auf sku muss den Umbau überleben');
        $this->assertSame(
            ['Äpfel', 'Birnen', 'Zitronen'],
            $connection->table('probe_items')->orderBy('sku')->pluck('name')->all(),
        );

        $this->recordFinding('f5-ddl-vorschau', $driver, [
            'create_statements' => count($createPreview),
            'alter_statements' => count($alterPreview),
            'alter_preview_ms' => $previewMs,
            'alter_preview' => $alterPreview,
        ]);
    }

    #[DataProvider('drivers')]
    public function test_pretend_returns_dml_with_bindings_and_writes_nothing(string $driver): void
    {
        $connection = $this->freshConnection($driver);
        $connection->getSchemaBuilder()->create('probe_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        $connection->table('probe_items')->insert(['name' => 'vorher']);

        $queries = $connection->pretend(function (Connection $pretending) {
            $pretending->table('probe_items')->where('id', 1)->update(['name' => 'nachher']);
            $pretending->table('probe_items')->where('id', 1)->delete();
        });

        $this->assertCount(2, $queries);
        $this->assertStringStartsWith('update', strtolower($queries[0]['query']));
        $this->assertSame(['nachher', 1], $queries[0]['bindings']);
        $this->assertStringStartsWith('delete', strtolower($queries[1]['query']));
        $this->assertSame('vorher', $connection->table('probe_items')->value('name'));

        // Lesbare Vorschau mit eingesetzten Werten (nur zum Zeigen, ausgeführt wird mit Bindungen).
        $raw = $connection->table('probe_items')->where('name', "O'Brien")->toRawSql();

        $this->recordFinding('f5-dml-vorschau', $driver, [
            'update' => $queries[0]['query'],
            'delete' => $queries[1]['query'],
            'raw_select' => $raw,
        ]);
    }

    #[DataProvider('drivers')]
    public function test_pretend_preview_of_column_change_is_compared_with_compiled_preview(string $driver): void
    {
        $connection = $this->freshConnection($driver);
        $connection->getSchemaBuilder()->create('probe_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('notes')->nullable();
        });

        // change() erzwingt auf SQLite den Tabellenumbau, für den die Grammatik den Bestand lesen muss.
        $change = fn (Blueprint $table) => $table->string('title', 200)->nullable()->change();
        $compiled = (new Blueprint($connection, 'probe_items', $change))->toSql();

        $pretended = array_column($connection->pretend(function (Connection $pretending) use ($change) {
            $pretending->getSchemaBuilder()->table('probe_items', $change);
        }), 'query');

        // pretend() liefert für jede SELECT-Abfrage eine leere Antwort. Liest die Grammatik den Bestand
        // (SQLite-Umbau), weicht die Vorschau ab — deshalb kommt die Vorschau aus toSql(), nicht aus pretend().
        $this->recordFinding('f5-pretend-gegen-tosql', $driver, [
            'gleich' => $compiled === array_values(array_filter($pretended, fn (string $query) => ! str_starts_with(strtolower(ltrim($query)), 'select'))),
            'compiled' => $compiled,
            'pretended' => $pretended,
        ]);

        $this->assertNotEmpty($compiled);
        $this->assertFalse(
            array_column($connection->getSchemaBuilder()->getColumns('probe_items'), null, 'name')['title']['nullable'],
            'pretend() darf nichts ändern',
        );
    }

    #[DataProvider('drivers')]
    public function test_naive_apply_in_one_transaction_breaks_on_mysql_mariadb_and_deletes_child_rows_on_sqlite(string $driver): void
    {
        [$connection, $preview] = $this->parentWithChildren($driver);

        $error = null;

        try {
            // Naiv: die ganze Vorschau in einer Transaktion.
            $connection->transaction(function () use ($connection, $preview) {
                foreach ($preview as $statement) {
                    $connection->statement($statement);
                }
            });
        } catch (\PDOException $exception) {
            $error = $exception->getMessage();
        }

        $children = $connection->table('probe_children')->count();

        $this->recordFinding('f5-umbau-naiv', $driver, ['kinder_nach_umbau' => $children, 'fehler' => $error]);

        match ($driver) {
            // DDL schliesst die Transaktion implizit ab; das Commit von Laravel findet keine mehr.
            'mysql', 'mariadb' => $this->assertSame('There is no active transaction', $error),
            // PRAGMA foreign_keys wirkt in einer Transaktion nicht: DROP TABLE löscht per CASCADE die Kinder.
            'sqlite' => $this->assertSame(0, $children),
            'pgsql' => $this->assertSame(2, $children),
        };
    }

    #[DataProvider('drivers')]
    public function test_driver_specific_apply_keeps_child_rows(string $driver): void
    {
        [$connection, $preview] = $this->parentWithChildren($driver);

        match ($driver) {
            // Keine Transaktion möglich: Anweisung für Anweisung, die Vorschau sagt das dem Menschen.
            'mysql', 'mariadb' => array_map(fn (string $statement) => $connection->statement($statement), $preview),
            'pgsql' => $connection->transaction(fn () => array_map(fn (string $statement) => $connection->statement($statement), $preview)),
            // Ablauf nach sqlite.org/lang_altertable.html: Fremdschlüssel ausserhalb der Transaktion aus,
            // Umbau in der Transaktion, foreign_key_check vor dem Commit, danach wieder ein.
            'sqlite' => $this->applySqliteRebuild($connection, $preview),
        };

        $this->assertSame(2, $connection->table('probe_children')->count());
        $this->assertSame([], $driver === 'sqlite' ? $connection->select('PRAGMA foreign_key_check') : []);
    }

    /**
     * @return array{0: Connection, 1: list<string>}
     */
    private function parentWithChildren(string $driver): array
    {
        $connection = $this->freshConnection($driver);
        $schema = $connection->getSchemaBuilder();
        $schema->create('probe_parents', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
        });
        $schema->create('probe_children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->constrained('probe_parents')->cascadeOnDelete();
        });
        $connection->table('probe_parents')->insert(['name' => 'Eltern']);
        $connection->table('probe_children')->insert([['parent_id' => 1], ['parent_id' => 1]]);

        $preview = (new Blueprint($connection, 'probe_parents', fn (Blueprint $table) => $table->string('name', 200)->change()))->toSql();

        return [$connection, $preview];
    }

    /**
     * @param  list<string>  $preview
     */
    private function applySqliteRebuild(Connection $connection, array $preview): void
    {
        $body = array_values(array_filter($preview, fn (string $statement) => ! str_starts_with(strtolower($statement), 'pragma foreign_keys')));

        $connection->statement('PRAGMA foreign_keys = OFF');

        try {
            $connection->transaction(function () use ($connection, $body) {
                foreach ($body as $statement) {
                    $connection->statement($statement);
                }

                if ($connection->select('PRAGMA foreign_key_check') !== []) {
                    throw new \RuntimeException('Fremdschlüssel nach dem Umbau verletzt');
                }
            });
        } finally {
            $connection->statement('PRAGMA foreign_keys = ON');
        }
    }

    #[DataProvider('drivers')]
    public function test_failed_ddl_sequence_rolls_back_only_where_ddl_is_transactional(string $driver): void
    {
        $connection = $this->freshConnection($driver);
        $schema = $connection->getSchemaBuilder();

        $connection->beginTransaction();

        try {
            $connection->statement('CREATE TABLE probe_first (id INTEGER PRIMARY KEY)');
            $connection->statement('CREATE TABLE probe_first (id INTEGER PRIMARY KEY)');
            $this->fail('Die zweite Anweisung hätte scheitern müssen');
        } catch (QueryException) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }

        $survived = $schema->hasTable('probe_first');
        $expected = in_array($driver, ['mysql', 'mariadb'], true);

        $this->assertSame($expected, $survived, $expected
            ? 'MySQL/MariaDB: DDL schliesst die Transaktion implizit ab — die erste Tabelle bleibt'
            : 'PostgreSQL/SQLite: DDL ist transaktional — die erste Tabelle ist zurückgerollt');

        $this->recordFinding('f5-ddl-transaktion', $driver, ['erste_tabelle_bleibt_nach_fehler' => $survived]);
    }

    /**
     * Führt die Vorschau Anweisung für Anweisung aus und gibt zurück, was wirklich lief (ohne Lesezugriffe).
     *
     * @param  list<string>  $statements
     * @return list<string>
     */
    private function runPreview(Connection $connection, array $statements): array
    {
        $executed = [];
        $connection->listen(function (QueryExecuted $query) use (&$executed) {
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $executed[] = $query->sql;
            }
        });

        foreach ($statements as $statement) {
            $connection->statement($statement);
        }

        return $executed;
    }
}
