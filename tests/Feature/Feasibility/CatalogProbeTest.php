<?php

namespace Tests\Feature\Feasibility;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * F-1 Katalog: Reicht die Introspektion von Laravel 13 für den Navigator und die Strukturansicht?
 * Was fehlt, muss die Dialekt-Schicht liefern (Check-Constraints, Trigger).
 */
#[Group('feasibility')]
class CatalogProbeTest extends ProbeTestCase
{
    #[DataProvider('drivers')]
    public function test_laravel_introspection_returns_tables_columns_indexes_foreign_keys_and_views(string $driver): void
    {
        $connection = $this->freshConnection($driver);
        $this->createSampleSchema($connection, $driver);
        $schema = $connection->getSchemaBuilder();

        [$tables, $tablesMs] = $this->measure(fn () => $schema->getTables(self::schemaName($driver)));
        $tableNames = array_column($tables, 'name');
        sort($tableNames);
        $this->assertSame(['customers', 'orders'], $tableNames);

        $columns = $schema->getColumns('customers');
        $this->assertSame(['id', 'email', 'name', 'status', 'balance', 'meta', 'created_at'], array_column($columns, 'name'));
        $byName = array_column($columns, null, 'name');
        $this->assertTrue($byName['id']['auto_increment']);
        $this->assertFalse($byName['email']['nullable']);
        $this->assertTrue($byName['name']['nullable']);
        $this->assertNotNull($byName['balance']['default']);

        $indexes = $schema->getIndexes('orders');
        $this->assertContains(['customer_id', 'ordered_at'], array_column($indexes, 'columns'));
        $this->assertNotEmpty(array_filter($indexes, fn (array $index) => $index['primary'] && $index['columns'] === ['id']));

        $foreignKeys = $schema->getForeignKeys('orders');
        $this->assertCount(1, $foreignKeys);
        $this->assertSame(['customer_id'], $foreignKeys[0]['columns']);
        $this->assertSame('customers', $foreignKeys[0]['foreign_table']);
        $this->assertSame(['id'], $foreignKeys[0]['foreign_columns']);
        $this->assertSame('cascade', strtolower($foreignKeys[0]['on_delete']));

        $views = $schema->getViews(self::schemaName($driver));
        $this->assertSame(['open_orders'], array_column($views, 'name'));
        $this->assertNotSame('', trim((string) $views[0]['definition']));

        // getTypes() gibt es nur für PostgreSQL; die übrigen Grammatiken werfen eine RuntimeException.
        $types = $driver === 'pgsql' ? $schema->getTypes(self::schemaName($driver)) : [];

        $this->recordFinding('f1-katalog', $driver, [
            'server_version' => $connection->getServerVersion(),
            'getTables_ms' => $tablesMs,
            'table_keys' => array_keys($tables[0]),
            'column_types' => array_map(fn (array $column) => $column['type'], $byName),
            'column_type_names' => array_map(fn (array $column) => $column['type_name'], $byName),
            'name_comment' => $byName['name']['comment'],
            'email_collation' => $byName['email']['collation'],
            'balance_default' => $byName['balance']['default'],
            'status_type' => $byName['status']['type'],
            'index_names' => array_column($indexes, 'name'),
            'view_definition_start' => mb_substr(trim((string) $views[0]['definition']), 0, 60),
            'types' => array_column($types, 'name'),
            'schemas' => array_column($schema->getSchemas(), 'name'),
        ]);
    }

    #[DataProvider('drivers')]
    public function test_check_constraints_and_triggers_are_readable_through_dialect_queries(string $driver): void
    {
        $connection = $this->freshConnection($driver);
        $this->createSampleSchema($connection, $driver);

        $checks = $this->checkConstraints($connection, $driver);
        $triggers = $this->triggers($connection, $driver);

        $this->assertNotEmpty(array_filter($checks, fn (string $definition) => str_contains($definition, 'total')), 'Check-Constraint auf total fehlt');
        $this->assertContains('orders_note_default', $triggers);

        $this->recordFinding('f1-dialekt', $driver, [
            'check_constraints' => $checks,
            'triggers' => $triggers,
            'laravel_liefert' => 'weder Check-Constraints noch Trigger — eigene Abfrage je Treiber nötig',
        ]);
    }

    /**
     * Beispielschema mit allem, was die Strukturansicht zeigen muss.
     */
    private function createSampleSchema(Connection $connection, string $driver): void
    {
        $schema = $connection->getSchemaBuilder();

        $schema->create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable()->comment('Anzeigename');
            $table->enum('status', ['aktiv', 'gesperrt'])->default('aktiv');
            $table->decimal('balance', 10, 2)->default(0);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('name');
        });

        $orders = match ($driver) {
            'mysql', 'mariadb' => 'CREATE TABLE orders (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id BIGINT UNSIGNED NOT NULL,
                total DECIMAL(10,2) NOT NULL,
                note VARCHAR(255) NULL,
                ordered_at DATETIME NULL,
                CONSTRAINT orders_total_check CHECK (total >= 0),
                CONSTRAINT orders_customer_fk FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
                INDEX orders_customer_ordered_index (customer_id, ordered_at)
            )',
            'pgsql' => 'CREATE TABLE orders (
                id BIGSERIAL PRIMARY KEY,
                customer_id BIGINT NOT NULL REFERENCES customers (id) ON DELETE CASCADE,
                total NUMERIC(10,2) NOT NULL CONSTRAINT orders_total_check CHECK (total >= 0),
                note VARCHAR(255) NULL,
                ordered_at TIMESTAMP NULL
            )',
            'sqlite' => 'CREATE TABLE orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id INTEGER NOT NULL REFERENCES customers (id) ON DELETE CASCADE,
                total NUMERIC NOT NULL CONSTRAINT orders_total_check CHECK (total >= 0),
                note VARCHAR(255) NULL,
                ordered_at DATETIME NULL
            )',
        };
        $connection->statement($orders);

        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            $connection->statement('CREATE INDEX orders_customer_ordered_index ON orders (customer_id, ordered_at)');
        }

        $connection->statement("CREATE VIEW open_orders AS SELECT id, customer_id, total FROM orders WHERE note IS NULL OR note <> 'bezahlt'");

        foreach ($this->triggerStatements($driver) as $statement) {
            $connection->unprepared($statement);
        }

        if ($driver === 'pgsql') {
            $connection->statement("CREATE TYPE order_state AS ENUM ('offen', 'bezahlt')");
        }
    }

    /**
     * @return list<string>
     */
    private function triggerStatements(string $driver): array
    {
        return match ($driver) {
            'mysql', 'mariadb' => ["CREATE TRIGGER orders_note_default BEFORE INSERT ON orders FOR EACH ROW SET NEW.note = COALESCE(NEW.note, 'offen')"],
            'pgsql' => [
                "CREATE FUNCTION probe_orders_note() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN NEW.note := COALESCE(NEW.note, 'offen'); RETURN NEW; END \$\$",
                'CREATE TRIGGER orders_note_default BEFORE INSERT ON orders FOR EACH ROW EXECUTE FUNCTION probe_orders_note()',
            ],
            'sqlite' => ["CREATE TRIGGER orders_note_default AFTER INSERT ON orders WHEN NEW.note IS NULL BEGIN UPDATE orders SET note = 'offen' WHERE id = NEW.id; END"],
        };
    }

    /**
     * Dialekt-Abfrage: Check-Constraints der Tabelle orders.
     *
     * @return list<string>
     */
    private function checkConstraints(Connection $connection, string $driver): array
    {
        $rows = match ($driver) {
            'mysql', 'mariadb' => $connection->select(
                'SELECT cc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS definition
                 FROM information_schema.CHECK_CONSTRAINTS cc
                 JOIN information_schema.TABLE_CONSTRAINTS tc
                   ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
                 WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = ?',
                ['orders', 'CHECK'],
            ),
            'pgsql' => $connection->select(
                "SELECT conname AS name, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE contype = 'c' AND conrelid = 'orders'::regclass",
            ),
            // SQLite führt keinen Katalog der Constraints: nur der CREATE-Text in sqlite_schema verrät sie.
            'sqlite' => array_map(
                fn (object $row) => (object) ['name' => 'aus CREATE-Text', 'definition' => preg_match('/CHECK\s*\((.+?)\)\s*,?\s*\n/s', $row->sql, $match) ? $match[1] : ''],
                $connection->select("SELECT sql FROM sqlite_schema WHERE type = 'table' AND name = ?", ['orders']),
            ),
        };

        return array_map(fn (object $row) => $row->name.': '.$row->definition, $rows);
    }

    /**
     * Dialekt-Abfrage: Trigger der Tabelle orders.
     *
     * @return list<string>
     */
    private function triggers(Connection $connection, string $driver): array
    {
        $rows = match ($driver) {
            'mysql', 'mariadb' => $connection->select('SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?', ['orders']),
            'pgsql' => $connection->select("SELECT tgname AS name FROM pg_trigger WHERE tgrelid = 'orders'::regclass AND NOT tgisinternal"),
            'sqlite' => $connection->select("SELECT name FROM sqlite_schema WHERE type = 'trigger' AND tbl_name = ?", ['orders']),
        };

        return array_map(fn (object $row) => $row->name, $rows);
    }

    private static function schemaName(string $driver): ?string
    {
        return match ($driver) {
            'mysql', 'mariadb' => self::PROBE_DATABASE,
            'pgsql' => 'public',
            'sqlite' => 'main',
        };
    }
}
