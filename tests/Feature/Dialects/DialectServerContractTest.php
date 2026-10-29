<?php

namespace Tests\Feature\Dialects;

use App\Connections\ConnectionFactory;
use App\Connections\ConnectionSettings;
use App\Dialects\AccountName;
use App\Dialects\PlannedStatement;
use App\Dialects\PrivilegeTarget;
use App\Enums\DatabaseDriver;
use App\Enums\MaintenanceOperation;
use App\Enums\PrivilegeLevel;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use mysqli;
use mysqli_sql_exception;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;
use Throwable;

/**
 * Vertragstest der Server-Verwaltung (W-01 bis W-05) gegen alle vier Umsetzungen des Dialekts: Übersicht, Prozesse
 * beenden, Variablen, Konten und Rechte, Wartung. Konten heissen «phpdbadmin_w04_…» und werden immer wieder entfernt.
 */
#[Group('target-databases')]
class DialectServerContractTest extends TestCase
{
    use TargetDatabases;

    /** Sieht aus wie SQL, ist aber nur ein Passwort. */
    private const string NASTY_PASSWORD = "Pa'ss\"wort\\; DROP USER root; --";

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/server-'.getmypid());
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
    public function test_overview_reports_version_uptime_charset_and_size_per_database(DatabaseDriver $driver): void
    {
        $connection = $this->writable($driver);
        $this->createSampleSchema($connection, $driver);

        $overview = $driver->dialect()->serverOverview($connection);

        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $overview['version']);
        $this->assertNotNull($overview['charset']);
        $databases = array_column($overview['databases'], null, 'name');
        $name = $driver === DatabaseDriver::Sqlite ? 'main' : $this->targetTestDatabase();
        $this->assertArrayHasKey($name, $databases);
        $this->assertGreaterThan(0, $databases[$name]['size']);

        if ($driver === DatabaseDriver::Sqlite) {
            $this->assertNull($overview['uptime']);
            $facts = array_column($overview['facts'], 'value', 'key');
            $this->assertSame(4096, $facts['page_size']);
            $this->assertSame('DELETE', $facts['journal_mode']);
            $this->assertStringEndsWith('.sqlite', (string) $facts['file']);
        } else {
            $this->assertGreaterThan(0, $overview['uptime']);
            $this->assertNotNull($databases[$name]['collation']);
        }

        $this->assertSame($driver === DatabaseDriver::Postgres ? ['public'] : null, $overview['schemas'] === null ? null : array_column($overview['schemas'], 'name'));
    }

    #[DataProvider('serverDrivers')]
    public function test_lists_processes_and_marks_its_own_session(DatabaseDriver $driver): void
    {
        $connection = $this->writable($driver);
        $dialect = $driver->dialect();

        $processes = array_column((array) $dialect->processes($connection), null, 'id');

        $own = (int) $dialect->sessionId($connection);
        $this->assertArrayHasKey($own, $processes);
        $this->assertTrue($processes[$own]['own']);
        $this->assertSame(1, count(array_filter($processes, fn (array $process): bool => $process['own'])));
        $this->assertSame($this->serverSettings($driver)->username, $processes[$own]['user']);
    }

    /**
     * Pflichttest W-02: eine zweite Sitzung beenden — sie ist danach aus der Liste verschwunden und tot.
     */
    #[DataProvider('serverDrivers')]
    public function test_ending_a_second_session_removes_it_and_kills_it(DatabaseDriver $driver): void
    {
        $connection = $this->writable($driver);
        $second = app(ConnectionFactory::class)->make($this->serverSettings($driver));
        $dialect = $driver->dialect();
        $id = (int) $dialect->sessionId($second);
        $secondPdo = $second->getPdo();
        $this->assertContains($id, array_column((array) $dialect->processes($connection), 'id'));

        $ended = $dialect->endProcess($connection, $id, wholeSession: true);

        $this->assertTrue($ended);
        $this->assertTrue($this->eventually(fn (): bool => ! in_array($id, array_column((array) $dialect->processes($connection), 'id'), true)), 'Sitzung steht noch in der Liste');
        $this->assertSessionIsDead($secondPdo);
        $this->assertFalse($dialect->endProcess($connection, $id, wholeSession: true), 'Ein beendeter Prozess lässt sich nicht noch einmal beenden');
    }

    #[DataProvider('serverDrivers')]
    public function test_cancelling_stops_the_running_statement_but_keeps_the_session(DatabaseDriver $driver): void
    {
        $connection = $this->writable($driver);
        $dialect = $driver->dialect();
        $settings = $this->serverSettings($driver);
        [$id, $wait, $close] = $this->startLongQuery($driver, $settings);
        $this->assertTrue($this->eventually(fn (): bool => in_array($id, array_column(array_filter(
            (array) $dialect->processes($connection),
            fn (array $process): bool => str_contains((string) $process['statement'], 'sleep'),
        ), 'id'), true)), 'Lange Abfrage erscheint nicht in der Liste');
        $start = hrtime(true);

        $this->assertTrue($dialect->endProcess($connection, $id, wholeSession: false));

        $wait();
        $this->assertLessThan(3000, (hrtime(true) - $start) / 1e6, 'Abfrage lief trotz Abbruch weiter');
        $this->assertContains($id, array_column((array) $dialect->processes($connection), 'id'), 'Die Sitzung selbst bleibt bestehen');
        $close();
    }

    #[DataProvider('serverDrivers')]
    public function test_end_statement_shows_exactly_what_runs(DatabaseDriver $driver): void
    {
        $dialect = $driver->dialect();

        $this->assertSame(
            $driver === DatabaseDriver::Postgres ? ['SELECT pg_cancel_backend(42)', 'SELECT pg_terminate_backend(42)'] : ['KILL QUERY 42', 'KILL 42'],
            [$dialect->endProcessStatement(42, false)?->preview(), $dialect->endProcessStatement(42, true)?->preview()],
        );
    }

    public function test_sqlite_has_no_processes_and_no_accounts(): void
    {
        $connection = $this->writable(DatabaseDriver::Sqlite);
        $dialect = DatabaseDriver::Sqlite->dialect();

        $this->assertNull($dialect->processes($connection));
        $this->assertNull($dialect->sessionId($connection));
        $this->assertNull($dialect->endProcessStatement(1, true));
        $this->assertNull($dialect->accounts($connection));
        $this->assertNull($dialect->currentAccount($connection));
        $this->assertSame([], $dialect->privilegeChoices());
    }

    #[DataProvider('drivers')]
    public function test_reads_variables_and_status(DatabaseDriver $driver): void
    {
        $connection = $this->writable($driver);
        $dialect = $driver->dialect();

        $variables = array_column($dialect->variables($connection), 'value', 'name');
        $status = array_column($dialect->statusValues($connection), 'value', 'name');

        [$variable, $value, $statusName] = match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => ['character_set_server', 'utf8mb4', 'Uptime'],
            DatabaseDriver::Postgres => ['TimeZone', 'UTC', 'pg_stat_database.xact_commit'],
            DatabaseDriver::Sqlite => ['page_size', '4096', 'THREADSAFE'],
        };
        $this->assertSame($value, $variables[$variable] ?? null);
        $this->assertArrayHasKey($statusName, $status);
        $this->assertGreaterThan(20, count($variables));
    }

    /**
     * W-04 je Server-Treiber: Konto anlegen (Passwort, das wie SQL aussieht), Recht auf eine Tabelle erteilen, prüfen,
     * entziehen, löschen.
     */
    #[DataProvider('serverDrivers')]
    public function test_creates_account_grants_and_revokes_table_privilege_and_drops_it(DatabaseDriver $driver): void
    {
        $connection = $this->writable($driver);
        $connection->statement('create table w04_orders (id int primary key)');
        $dialect = $driver->dialect();
        $account = $this->w04Account($driver, $connection);
        $target = new PrivilegeTarget(PrivilegeLevel::Table, $this->targetTestDatabase(), $driver === DatabaseDriver::Postgres ? 'public' : null, 'w04_orders');

        try {
            $this->runAll($connection, $dialect->createAccountStatements($connection, $account, self::NASTY_PASSWORD));
            $asAccount = app(ConnectionFactory::class)->make($this->accountSettings($driver, $account));
            $this->assertFalse($this->canSelect($asAccount, $driver), 'Konto darf ohne Recht lesen');

            $this->runAll($connection, $dialect->privilegeStatements($connection, true, $account, $target, ['SELECT']));
            $this->assertTrue($this->hasTablePrivilege($connection, $driver, $account), 'Recht fehlt nach GRANT');
            $this->assertTrue($this->canSelect($asAccount, $driver));

            $this->runAll($connection, $dialect->privilegeStatements($connection, false, $account, $target, ['SELECT']));
            $this->assertFalse($this->hasTablePrivilege($connection, $driver, $account), 'Recht bleibt nach REVOKE');
            app(ConnectionFactory::class)->release($asAccount);

            $this->runAll($connection, $dialect->dropAccountStatements($connection, $account));
            $this->assertNotContains($account->name, array_column((array) $dialect->accounts($connection), 'name'));
            $this->assertTrue($this->rootStillExists($connection, $driver), 'Das Passwort hat SQL ausgeführt');
        } finally {
            $this->dropW04Account($connection, $driver, $account);
        }
    }

    /**
     * Rechte auf Server- und Datenbank-Ebene (PostgreSQL zusätzlich Schema) erscheinen in der Liste der Konten und
     * verschwinden nach REVOKE. MySQL/MariaDB: _ im Datenbanknamen ist kein Platzhalter.
     */
    #[DataProvider('serverDrivers')]
    public function test_grants_on_server_database_and_schema_appear_in_account_list(DatabaseDriver $driver): void
    {
        $connection = $this->writable($driver);
        $dialect = $driver->dialect();
        $account = $this->w04Account($driver, $connection);
        $targets = match ($driver) {
            DatabaseDriver::Postgres => [
                [new PrivilegeTarget(PrivilegeLevel::Server), ['pg_monitor'], 'GRANT "pg_monitor"'],
                [new PrivilegeTarget(PrivilegeLevel::Database, $this->targetTestDatabase()), ['CONNECT', 'TEMPORARY'], 'GRANT CONNECT, TEMPORARY ON DATABASE "'.$this->targetTestDatabase().'"'],
                [new PrivilegeTarget(PrivilegeLevel::Schema, schema: 'public'), ['USAGE'], 'GRANT USAGE ON SCHEMA "public"'],
            ],
            default => [
                [new PrivilegeTarget(PrivilegeLevel::Server), ['PROCESS'], 'GRANT PROCESS ON *.*'],
                [new PrivilegeTarget(PrivilegeLevel::Database, $this->targetTestDatabase()), ['SELECT', 'INSERT'], 'GRANT SELECT, INSERT ON `'.addcslashes($this->targetTestDatabase(), '_').'`.*'],
            ],
        };

        try {
            $this->runAll($connection, $dialect->createAccountStatements($connection, $account, self::NASTY_PASSWORD));

            foreach ($targets as [$target, $privileges, $expected]) {
                $this->runAll($connection, $dialect->privilegeStatements($connection, true, $account, $target, $privileges));
                $this->assertTrue($this->grantListed($connection, $driver, $account, $expected), 'Nicht gelistet: '.$expected);

                $this->runAll($connection, $dialect->privilegeStatements($connection, false, $account, $target, $privileges));
                $this->assertFalse($this->grantListed($connection, $driver, $account, $expected), 'Nach REVOKE noch gelistet: '.$expected);
            }
        } finally {
            $this->dropW04Account($connection, $driver, $account);
        }
    }

    #[DataProvider('serverDrivers')]
    public function test_preview_masks_the_password_and_quotes_names_that_look_like_sql(DatabaseDriver $driver): void
    {
        $connection = $this->writable($driver);
        $dialect = $driver->dialect();
        $account = new AccountName("o'brien\"`; drop", $driver === DatabaseDriver::Postgres ? null : "%'; --");

        [$create] = $dialect->createAccountStatements($connection, $account, self::NASTY_PASSWORD);
        [$grant] = $dialect->privilegeStatements($connection, true, $account, new PrivilegeTarget(PrivilegeLevel::Table, 'shop', 'public', 'we`ird"name'), ['SELECT']);

        $this->assertStringContainsString(PlannedStatement::MASK, $create->preview());
        $this->assertStringNotContainsString('DROP USER root', $create->preview());
        $this->assertStringContainsString('DROP USER root', $create->sql);
        if ($driver === DatabaseDriver::Postgres) {
            $this->assertStringStartsWith('CREATE ROLE "o\'brien""`; drop" LOGIN PASSWORD \'', $create->sql);
            $this->assertSame('GRANT SELECT ON TABLE "public"."we`ird""name" TO "o\'brien""`; drop"', $grant->sql);
        } else {
            $this->assertStringStartsWith("CREATE USER 'o\\'brien\\\"`; drop'@'%\\'; --' IDENTIFIED BY ", $create->sql);
            $this->assertSame("GRANT SELECT ON `shop`.`we``ird\"name` TO 'o\\'brien\\\"`; drop'@'%\\'; --'", $grant->sql);
        }
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function refusedPrivileges(): array
    {
        return [
            'nicht in der Liste' => [['FILE']],
            'sieht aus wie SQL' => [['SELECT ON *.* TO root; --']],
            'leer' => [[]],
        ];
    }

    /**
     * @param  list<string>  $privileges
     */
    #[DataProvider('refusedPrivileges')]
    public function test_refuses_privileges_outside_the_fixed_list(array $privileges): void
    {
        $connection = $this->writable(DatabaseDriver::MariaDb);

        $this->expectException(InvalidArgumentException::class);

        DatabaseDriver::MariaDb->dialect()->privilegeStatements($connection, true, new AccountName('x', '%'), new PrivilegeTarget(PrivilegeLevel::Database, 'shop'), $privileges);
    }

    /**
     * @return array<string, array{0: DatabaseDriver, 1: MaintenanceOperation}>
     */
    public static function maintenance(): array
    {
        $cases = [];

        foreach (self::drivers() as $label => [$driver]) {
            foreach ($driver->dialect()->maintenanceOperations() as $operation) {
                $cases[$label.' '.$operation->value] = [$driver, $operation];
            }
        }

        return $cases;
    }

    #[DataProvider('maintenance')]
    public function test_maintenance_runs_on_one_table_and_on_the_whole_schema(DatabaseDriver $driver, MaintenanceOperation $operation): void
    {
        $connection = $this->writable($driver);
        $this->createSampleSchema($connection, $driver);
        $dialect = $driver->dialect();
        $schema = $this->sampleSchemaName($driver);
        $results = [];

        foreach ($dialect->maintenanceForTables($operation) ? [['orders'], null] : [null] as $tables) {
            $statements = $dialect->maintenanceStatements($connection, $operation, $schema, $tables);
            $this->assertNotSame([], $statements);

            foreach ($statements as $statement) {
                $results = [...$results, ...$dialect->runMaintenance($connection, $statement)];
            }
        }

        $this->assertNotSame([], $results);
        $this->assertSame([], array_values(array_filter($results, fn (array $result): bool => $result['status'] === 'error')), json_encode($results, JSON_THROW_ON_ERROR));
    }

    public function test_maintenance_offers_per_driver_what_the_driver_knows(): void
    {
        $this->assertSame(['analyze', 'optimize', 'check'], array_column(DatabaseDriver::MySql->dialect()->maintenanceOperations(), 'value'));
        $this->assertSame(['analyze', 'vacuum'], array_column(DatabaseDriver::Postgres->dialect()->maintenanceOperations(), 'value'));
        $this->assertSame(['analyze', 'vacuum', 'check'], array_column(DatabaseDriver::Sqlite->dialect()->maintenanceOperations(), 'value'));
        $this->assertFalse(DatabaseDriver::Sqlite->dialect()->maintenanceForTables(MaintenanceOperation::Vacuum));
    }

    public function test_sqlite_integrity_check_reports_a_damaged_index(): void
    {
        $connection = $this->writable(DatabaseDriver::Sqlite);
        $connection->statement('create table notes (id integer primary key, body text)');
        $connection->statement('create index notes_body on notes (body)');
        $connection->table('notes')->insert([['body' => 'a'], ['body' => 'b']]);
        // Index am Katalog vorbei beschädigen: Tabelle ändern, ohne dass SQLite den Index nachführt.
        $connection->statement('pragma writable_schema = on');
        $connection->statement("update sqlite_schema set sql = 'create index notes_body on notes (id)' where name = 'notes_body'");
        $connection->statement('pragma writable_schema = off');
        $reopened = app(ConnectionFactory::class)->make(new ConnectionSettings(DatabaseDriver::Sqlite, sqlitePath: (string) $connection->getConfig('database')));
        $dialect = DatabaseDriver::Sqlite->dialect();

        $results = $dialect->runMaintenance($reopened, $dialect->maintenanceStatements($reopened, MaintenanceOperation::Check, 'main', null)[0]);

        $this->assertContains('error', array_column($results, 'status'));
    }

    /**
     * @param  list<PlannedStatement>  $statements
     */
    private function runAll(Connection $connection, array $statements): void
    {
        $this->assertNotSame([], $statements);

        foreach ($statements as $statement) {
            $connection->statement($statement->sql);
        }
    }

    private function w04Account(DatabaseDriver $driver, Connection $connection): AccountName
    {
        // Genau der Host, unter dem der Server den Test sieht (Herd: localhost, Container: Docker-Netz) — siehe clientHost.
        return new AccountName('phpdbadmin_w04_'.getmypid(), $driver === DatabaseDriver::Postgres ? null : $this->clientHost($connection));
    }

    private function accountSettings(DatabaseDriver $driver, AccountName $account): ConnectionSettings
    {
        $server = $this->serverSettings($driver);

        // MySQL und MariaDB: ohne Recht auf die Datenbank lässt sie sich nicht als Vorgabe wählen.
        $database = $driver === DatabaseDriver::Postgres ? $this->targetTestDatabase() : null;

        return new ConnectionSettings($driver, $server->host, $server->port, $database, $account->name, self::NASTY_PASSWORD);
    }

    private function dropW04Account(Connection $connection, DatabaseDriver $driver, AccountName $account): void
    {
        if ($driver === DatabaseDriver::Postgres) {
            $exists = $connection->selectOne('select 1 as found from pg_roles where rolname = ?', [$account->name]) !== null;

            if ($exists) {
                // Rechte, die ein abgebrochener Test hinterlassen hat, zuerst entfernen.
                $connection->statement('drop owned by "'.$account->name.'"');
                $connection->statement('drop role "'.$account->name.'"');
            }

            return;
        }

        $connection->statement("drop user if exists '".$account->name."'@'".$account->host."'");
    }

    private function canSelect(Connection $connection, DatabaseDriver $driver): bool
    {
        try {
            $connection->table($driver === DatabaseDriver::Postgres ? 'w04_orders' : $this->targetTestDatabase().'.w04_orders')->count();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function hasTablePrivilege(Connection $connection, DatabaseDriver $driver, AccountName $account): bool
    {
        if ($driver === DatabaseDriver::Postgres) {
            return (bool) $connection->selectOne("select has_table_privilege(?, 'public.w04_orders', 'SELECT') as allowed", [$account->name])?->allowed;
        }

        $grants = array_map(fn (object $row): string => (string) array_values((array) $row)[0], $connection->select("show grants for '".$account->name."'@'".$account->host."'"));

        return array_any($grants, fn (string $grant): bool => str_contains($grant, 'GRANT SELECT ON `'.$this->targetTestDatabase().'`.`w04_orders`'));
    }

    private function grantListed(Connection $connection, DatabaseDriver $driver, AccountName $account, string $expected): bool
    {
        $accounts = array_values(array_filter((array) $driver->dialect()->accounts($connection), fn (array $listed): bool => $listed['name'] === $account->name));
        $this->assertCount(1, $accounts);

        return array_any($accounts[0]['grants'], fn (string $grant): bool => str_starts_with($grant, $expected));
    }

    private function rootStillExists(Connection $connection, DatabaseDriver $driver): bool
    {
        return $driver === DatabaseDriver::Postgres
            ? $connection->selectOne("select 1 as found from pg_roles where rolname = 'root'") !== null
            : $connection->selectOne("select 1 as found from mysql.user where user = 'root'") !== null;
    }

    private function eventually(callable $condition, int $milliseconds = 3000): bool
    {
        $until = hrtime(true) + $milliseconds * 1e6;

        do {
            if ($condition()) {
                return true;
            }

            usleep(50_000);
        } while (hrtime(true) < $until);

        return false;
    }

    private function assertSessionIsDead(PDO $pdo): void
    {
        try {
            $pdo->query('select 1');
            $this->fail('Die beendete Sitzung antwortet noch');
        } catch (Throwable $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    /**
     * Startet «sleep» in einer eigenen Sitzung, ohne zu warten (mysqli bzw. pgsql asynchron).
     *
     * @return array{0: int, 1: callable(): void, 2: callable(): void}
     */
    private function startLongQuery(DatabaseDriver $driver, ConnectionSettings $settings): array
    {
        if ($driver === DatabaseDriver::Postgres) {
            if (! function_exists('pg_connect')) {
                $this->markTestSkipped('Erweiterung pgsql fehlt — übersprungen, nicht bestanden');
            }

            $pg = pg_connect(sprintf('host=%s port=%d dbname=postgres user=%s', $settings->host, $settings->port, $settings->username));
            $this->assertNotFalse($pg);
            $id = (int) pg_get_pid($pg);
            pg_send_query($pg, 'select pg_sleep(10)');

            return [$id, function () use ($pg): void {
                $result = pg_get_result($pg);
                $this->assertNotFalse($result);
                $this->assertStringContainsString('canceling statement', (string) pg_result_error($result));
            }, fn () => pg_close($pg)];
        }

        if (! class_exists(mysqli::class)) {
            $this->markTestSkipped('Erweiterung mysqli fehlt — übersprungen, nicht bestanden');
        }

        $mysql = new mysqli((string) $settings->host, (string) $settings->username, (string) $settings->password, '', (int) $settings->port);
        $id = (int) $mysql->thread_id;
        $mysql->query('select sleep(10)', MYSQLI_ASYNC);

        return [$id, function () use ($mysql): void {
            $links = $errors = $rejects = [$mysql];
            mysqli::poll($links, $errors, $rejects, 5);

            try {
                // MySQL liefert still 1 (SLEEP unterbrochen), MariaDB meldet «Query execution was interrupted».
                $mysql->reap_async_query();
            } catch (mysqli_sql_exception $exception) {
                $this->assertStringContainsString('interrupted', $exception->getMessage());
            }
        }, fn () => $mysql->close()];
    }

    private function writable(DatabaseDriver $driver): Connection
    {
        return app(ConnectionFactory::class)->make($this->scratchSettings($driver, $this->folder));
    }
}
