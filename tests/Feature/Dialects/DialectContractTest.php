<?php

namespace Tests\Feature\Dialects;

use App\Connections\ConnectionFactory;
use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;
use Throwable;

/**
 * Vertragstest der Dialekt-Schicht (B-02): derselbe Test gegen alle vier Umsetzungen — umgebaut aus den Proben der
 * Feasibility (F-1 Katalog, F-2 Verbindungen, F-7 Sicherheit).
 */
#[Group('target-databases')]
class DialectContractTest extends TestCase
{
    use TargetDatabases;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/vertrag-'.getmypid());
        @mkdir($this->folder, 0777, true);

        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'phpdbadmin.connections.sqlite_paths' => [$this->folder],
            'phpdbadmin.connections.connect_timeout' => 3,
        ]);
    }

    protected function tearDown(): void
    {
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

    #[DataProvider('drivers')]
    public function test_reports_version_and_default_schema(DatabaseDriver $driver): void
    {
        $connection = $this->writable($driver);
        $dialect = $driver->dialect();

        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $dialect->serverVersion($connection));
        $this->assertSame($this->schema($driver), $dialect->defaultSchema($connection));
        $this->assertContains($this->schema($driver), $dialect->schemas($connection));
        $this->assertNotContains('information_schema', $dialect->schemas($connection));
    }

    #[DataProvider('drivers')]
    public function test_lists_tables_and_views_with_row_estimates(DatabaseDriver $driver): void
    {
        $connection = $this->writable($driver);
        $this->createSampleSchema($connection, $driver);
        $connection->table('customers')->insert([['email' => 'a@example.com'], ['email' => 'b@example.com']]);
        match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => $connection->statement('analyze table customers'),
            DatabaseDriver::Postgres => $connection->statement('analyze customers'),
            DatabaseDriver::Sqlite => null,
        };

        $tables = array_column($driver->dialect()->tables($connection, $this->schema($driver)), null, 'name');

        $this->assertSame(['customers', 'open_orders', 'orders'], array_keys($tables));
        $this->assertSame('table', $tables['customers']['type']);
        $this->assertSame('view', $tables['open_orders']['type']);
        $this->assertSame($driver === DatabaseDriver::Sqlite ? null : 2, $tables['customers']['rows']);
    }

    #[DataProvider('drivers')]
    public function test_reads_check_constraints_and_triggers_laravel_does_not_know(DatabaseDriver $driver): void
    {
        $connection = $this->writable($driver);
        $this->createSampleSchema($connection, $driver);
        $dialect = $driver->dialect();

        $checks = $dialect->checkConstraints($connection, $this->schema($driver), 'orders');

        $this->assertSame(['orders_total_check'], array_column($checks, 'name'));
        $this->assertStringContainsString('total', $checks[0]['definition']);
        $this->assertSame(['orders_note_default'], $dialect->triggers($connection, $this->schema($driver), 'orders'));
    }

    #[DataProvider('serverDrivers')]
    public function test_statement_timeout_stops_long_query_within_two_seconds(DatabaseDriver $driver): void
    {
        $connection = $this->writable($driver);
        $dialect = $driver->dialect();
        $dialect->setStatementTimeout($connection, 500);

        $sleep = $driver === DatabaseDriver::Postgres ? 'select pg_sleep(5) as result' : 'select sleep(5) as result';
        $start = hrtime(true);
        $error = null;

        try {
            $connection->selectOne($sleep);
        } catch (Throwable $exception) {
            $error = $exception;
        }

        $this->assertLessThan(2000, (hrtime(true) - $start) / 1e6);
        // MySQL bricht SELECT still ab (F-2): kein Fehler — dort entscheidet der Merker der Anwendung.
        $this->assertSame($driver !== DatabaseDriver::MySql, $error !== null && $dialect->isTimeout($error));
    }

    public function test_sqlite_says_it_has_no_statement_timeout(): void
    {
        $this->assertFalse(DatabaseDriver::Sqlite->dialect()->supportsStatementTimeout());
        $this->assertTrue(DatabaseDriver::Postgres->dialect()->supportsStatementTimeout());
    }

    #[DataProvider('serverDrivers')]
    public function test_session_runs_in_utc(DatabaseDriver $driver): void
    {
        $connection = $this->writable($driver);

        $zone = $driver === DatabaseDriver::Postgres
            ? $connection->selectOne('show timezone')?->TimeZone
            : $connection->selectOne('select @@session.time_zone as zone')?->zone;

        $this->assertContains($zone, ['+00:00', 'UTC']);
    }

    /**
     * @return array<string, array{0: DatabaseDriver, 1: list<string>}>
     */
    public static function escapeAttempts(): array
    {
        $mysql = [
            "insert into guard (name) values ('ausgebrochen')",
            'set session transaction read write',
            "insert into guard (name) values ('nach set read write')",
            'create table escape_attempt (id int)',
            'drop table guard',
            'truncate table guard',
            "set session transaction read write; insert into guard (name) values ('mehrfach')",
            'commit',
            "insert into guard (name) values ('nach commit')",
        ];

        return [
            'MySQL' => [DatabaseDriver::MySql, $mysql],
            'MariaDB' => [DatabaseDriver::MariaDb, $mysql],
            'PostgreSQL' => [DatabaseDriver::Postgres, [
                "insert into guard (name) values ('ausgebrochen')",
                'set transaction read write',
                'set session characteristics as transaction read write',
                "insert into guard (name) values ('nach set read write')",
                'set default_transaction_read_only = off',
                'create table escape_attempt (id int)',
                'drop table guard',
                "do \$\$ begin insert into guard (name) values ('do-block'); end \$\$",
                "select nextval('guard_id_seq')",
                "commit; insert into guard (name) values ('mehrfach')",
                'commit',
                "insert into guard (name) values ('nach commit')",
            ]],
            'SQLite' => [DatabaseDriver::Sqlite, [
                "insert into guard (name) values ('ausgebrochen')",
                'pragma query_only = off',
                "insert into guard (name) values ('nach pragma')",
                'create table escape_attempt (id int)',
                'drop table guard',
            ]],
        ];
    }

    /**
     * @param  list<string>  $attempts
     */
    #[DataProvider('escapeAttempts')]
    public function test_read_only_guard_withstands_escape_attempts(DatabaseDriver $driver, array $attempts): void
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $writable = app(ConnectionFactory::class)->make($settings);
        $writable->getSchemaBuilder()->create('guard', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        $writable->table('guard')->insert(['name' => 'Bestand']);

        $guarded = app(ConnectionFactory::class)->make($settings, readOnly: true);
        $dialect = $driver->dialect();
        $rejected = 0;

        foreach ($attempts as $sql) {
            try {
                $dialect->runReadOnly($guarded, fn () => $guarded->statement($sql));
            } catch (Throwable) {
                $rejected++;
            }
        }

        $schema = $writable->getSchemaBuilder();
        $this->assertTrue($schema->hasTable('guard'), 'Tabelle wurde gelöscht');
        $this->assertSame(['Bestand'], $writable->table('guard')->pluck('name')->all(), 'Daten wurden verändert');
        $this->assertFalse($schema->hasTable('escape_attempt'), 'Tabelle wurde angelegt');
        $this->assertGreaterThan(0, $rejected);

        try {
            $dialect->runReadOnly($guarded, fn () => $guarded->statement("insert into guard (name) values ('x')"));
            $this->fail('Schreibende Anweisung wurde ausgeführt');
        } catch (Throwable $exception) {
            $this->assertTrue($dialect->isReadOnlyViolation($exception), $exception->getMessage());
        }
    }

    #[DataProvider('serverDrivers')]
    public function test_warns_about_root_account_but_not_about_read_account(DatabaseDriver $driver): void
    {
        $root = $this->writable($driver);
        $dialect = $driver->dialect();

        $this->assertContains('superuser', array_column($dialect->accountWarnings($root), 'key'));

        $reader = app(ConnectionFactory::class)->make($this->readAccount($driver, $root));
        $this->assertSame([], $dialect->accountWarnings($reader));
    }

    public function test_sqlite_has_no_account_and_no_warnings(): void
    {
        $this->assertSame([], DatabaseDriver::Sqlite->dialect()->accountWarnings($this->writable(DatabaseDriver::Sqlite)));
    }

    public function test_sqlite_attach_only_inside_allowed_folder(): void
    {
        $connection = $this->writable(DatabaseDriver::Sqlite);
        $inside = $this->folder.'/innen.sqlite';
        touch($inside);
        $outside = sys_get_temp_dir().'/phpdbadmin-aussen-'.getmypid().'.sqlite';
        touch($outside);

        try {
            $connection->statement("attach database '".$outside."' as aussen");
            $this->fail('ATTACH ausserhalb wurde erlaubt');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('not authorized', $exception->getMessage());
        } finally {
            @unlink($outside);
        }

        $connection->statement("attach database '".$inside."' as innen");
        $this->assertContains('innen', DatabaseDriver::Sqlite->dialect()->schemas($connection));
    }

    #[DataProvider('serverDrivers')]
    public function test_multiple_statements_in_one_call_are_refused(DatabaseDriver $driver): void
    {
        $connection = $this->writable($driver);

        $this->expectException(Throwable::class);

        $connection->statement('select 1; select 2');
    }

    public function test_mysql_never_uploads_files_of_the_app_server(): void
    {
        $connection = $this->writable(DatabaseDriver::MySql);
        $connection->statement('create table zeilen (zeile text)');

        try {
            $connection->statement("load data local infile '/etc/hosts' into table zeilen");
        } catch (Throwable) {
            // Erwartet: der Client verweigert LOCAL INFILE.
        }

        $this->assertSame(0, $connection->table('zeilen')->count());
    }

    private function writable(DatabaseDriver $driver): Connection
    {
        return app(ConnectionFactory::class)->make($this->scratchSettings($driver, $this->folder));
    }

    private function schema(DatabaseDriver $driver): string
    {
        return match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => 'phpdbadmin_test',
            DatabaseDriver::Postgres => 'public',
            DatabaseDriver::Sqlite => 'main',
        };
    }

    /**
     * Lesekonto «phpdbadmin_lesen» mit Leserecht auf die Test-Datenbank — legt es bei Bedarf an (V-10).
     */
    private function readAccount(DatabaseDriver $driver, Connection $root): ConnectionSettings
    {
        $password = 'Lesen-Test-4711';

        if ($driver === DatabaseDriver::Postgres) {
            if ($root->selectOne("select 1 as found from pg_roles where rolname = 'phpdbadmin_lesen'") === null) {
                $root->statement("create role phpdbadmin_lesen login password '".$password."'");
            }
            $root->statement('grant connect on database phpdbadmin_test to phpdbadmin_lesen');
            $root->statement('grant usage on schema public to phpdbadmin_lesen');
        } else {
            foreach (['localhost', '127.0.0.1', '%'] as $host) {
                $root->statement("create user if not exists 'phpdbadmin_lesen'@'".$host."' identified by '".$password."'");
                $root->statement("grant select on phpdbadmin_test.* to 'phpdbadmin_lesen'@'".$host."'");
            }
        }

        $server = $this->serverSettings($driver);

        return new ConnectionSettings($driver, $server->host, $server->port, 'phpdbadmin_test', 'phpdbadmin_lesen', $password);
    }

    /**
     * Beispielschema mit allem, was der Katalog liefern muss (aus der Probe F-1).
     */
    private function createSampleSchema(Connection $connection, DatabaseDriver $driver): void
    {
        $connection->getSchemaBuilder()->create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
        });

        $connection->statement(match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => 'create table orders (
                id bigint unsigned auto_increment primary key,
                customer_id bigint unsigned not null,
                total decimal(10,2) not null,
                note varchar(255) null,
                constraint orders_total_check check (total >= 0),
                constraint orders_customer_fk foreign key (customer_id) references customers (id) on delete cascade
            )',
            DatabaseDriver::Postgres => 'create table orders (
                id bigserial primary key,
                customer_id bigint not null references customers (id) on delete cascade,
                total numeric(10,2) not null constraint orders_total_check check (total >= 0),
                note varchar(255) null
            )',
            DatabaseDriver::Sqlite => 'create table orders (
                id integer primary key autoincrement,
                customer_id integer not null references customers (id) on delete cascade,
                total numeric not null constraint orders_total_check check (total >= 0),
                note varchar(255) null
            )',
        });

        $connection->statement("create view open_orders as select id, customer_id, total from orders where note is null or note <> 'bezahlt'");

        foreach (match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => ["create trigger orders_note_default before insert on orders for each row set new.note = coalesce(new.note, 'offen')"],
            DatabaseDriver::Postgres => [
                "create function vertrag_orders_note() returns trigger language plpgsql as \$\$ begin new.note := coalesce(new.note, 'offen'); return new; end \$\$",
                'create trigger orders_note_default before insert on orders for each row execute function vertrag_orders_note()',
            ],
            DatabaseDriver::Sqlite => ["create trigger orders_note_default after insert on orders when new.note is null begin update orders set note = 'offen' where id = new.id; end"],
        } as $statement) {
            $connection->unprepared($statement);
        }
    }
}
