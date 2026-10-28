<?php

namespace Tests\Feature\Feasibility;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Pdo\Sqlite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

/**
 * F-7 Sicherheit: Hält ein Schreibschutz, wenn der Mensch im SQL-Editor ausbrechen will?
 * Was bringt ein Werkzeug mit freiem SQL sonst an Gefahren mit (Dateien lesen, Daten in Meldungen)?
 */
#[Group('feasibility')]
class SecurityProbeTest extends ProbeTestCase
{
    /**
     * Versuche, aus einer schreibgeschützten Verbindung auszubrechen — jeder Eintrag läuft als eigener Aufruf.
     *
     * @return array<string, list<string>>
     */
    private static function escapeAttempts(): array
    {
        return [
            'mysql' => [
                "INSERT INTO probe_guard (name) VALUES ('ausgebrochen')",
                'SET SESSION TRANSACTION READ WRITE',
                "INSERT INTO probe_guard (name) VALUES ('nach set read write')",
                'CREATE TABLE probe_escape (id INT)',
                'DROP TABLE probe_guard',
                'TRUNCATE TABLE probe_guard',
                "SET SESSION TRANSACTION READ WRITE; INSERT INTO probe_guard (name) VALUES ('mehrfach')",
                'COMMIT',
                "INSERT INTO probe_guard (name) VALUES ('nach commit')",
            ],
            'pgsql' => [
                "INSERT INTO probe_guard (name) VALUES ('ausgebrochen')",
                'SET TRANSACTION READ WRITE',
                'SET SESSION CHARACTERISTICS AS TRANSACTION READ WRITE',
                "INSERT INTO probe_guard (name) VALUES ('nach set read write')",
                'SET default_transaction_read_only = off',
                'CREATE TABLE probe_escape (id INT)',
                'DROP TABLE probe_guard',
                "DO \$\$ BEGIN INSERT INTO probe_guard (name) VALUES ('do-block'); END \$\$",
                "SELECT nextval('probe_guard_id_seq')",
                "COMMIT; INSERT INTO probe_guard (name) VALUES ('mehrfach')",
                'COMMIT',
                "INSERT INTO probe_guard (name) VALUES ('nach commit')",
            ],
            'sqlite' => [
                "INSERT INTO probe_guard (name) VALUES ('ausgebrochen')",
                'PRAGMA query_only = OFF',
                "INSERT INTO probe_guard (name) VALUES ('nach pragma')",
                'CREATE TABLE probe_escape (id INT)',
                'DROP TABLE probe_guard',
            ],
        ];
    }

    #[DataProvider('drivers')]
    public function test_read_only_guard_withstands_escape_attempts_from_the_sql_editor(string $driver): void
    {
        $writable = $this->freshConnection($driver);
        $writable->getSchemaBuilder()->create('probe_guard', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        $writable->table('probe_guard')->insert(['name' => 'Bestand']);

        $guarded = $driver === 'sqlite'
            ? $this->readOnlySqlite((string) $writable->getConfig('database'))
            : $this->connection($driver);

        $attempts = self::escapeAttempts()[$driver === 'mariadb' ? 'mysql' : $driver];
        $outcomes = [];
        foreach ($attempts as $sql) {
            $outcomes[$sql] = $this->runGuarded($guarded, $driver, $sql);
        }

        $schema = $writable->getSchemaBuilder();
        $rows = $writable->table('probe_guard')->pluck('name')->all();

        $this->recordFinding('f7-schreibschutz', $driver, [
            'ergebnisse' => $outcomes,
            'bestand_danach' => $rows,
            'probe_escape_angelegt' => $schema->hasTable('probe_escape'),
        ]);

        $this->assertTrue($schema->hasTable('probe_guard'), 'Tabelle wurde gelöscht');
        $this->assertSame(['Bestand'], $rows, 'Daten wurden verändert');
        $this->assertFalse($schema->hasTable('probe_escape'), 'Tabelle wurde angelegt');
    }

    public function test_sqlite_authorizer_blocks_attach_outside_the_allowed_folder(): void
    {
        $connection = $this->connection('sqlite');
        $outside = sys_get_temp_dir().'/phpdbadmin-probe-aussen-'.getmypid().'.sqlite';
        touch($outside);
        $inside = $this->sqliteFile();
        $allowedFolder = (string) realpath(dirname((string) $connection->getConfig('database')));

        $seen = [];
        /** @var Sqlite $pdo */
        $pdo = $connection->getPdo();
        $pdo->setAuthorizer(function (int $action, ?string $first) use ($allowedFolder, &$seen): int {
            // 24 = SQLITE_ATTACH; der erste Wert ist der Dateiname — bei «ATTACH ?» beim Vorbereiten noch leer.
            if ($action === 24) {
                $seen[] = $first;
                $real = $first === null || $first === '' ? false : realpath($first);

                return $real !== false && str_starts_with($real, $allowedFolder.'/') ? Sqlite::OK : Sqlite::DENY;
            }

            return Sqlite::OK;
        });

        $quote = fn (string $path) => "'".str_replace("'", "''", $path)."'";
        $outsideResult = $this->attempt(fn () => $connection->statement('ATTACH DATABASE '.$quote($outside).' AS aussen'));
        $boundResult = $this->attempt(fn () => $connection->statement('ATTACH DATABASE ? AS gebunden', [$inside]));
        $insideResult = $this->attempt(fn () => $connection->statement('ATTACH DATABASE '.$quote($inside).' AS innen'));
        @unlink($outside);

        $this->recordFinding('f7-sqlite-attach', 'sqlite', [
            'ausserhalb' => $outsideResult,
            'mit_bindung' => $boundResult,
            'innerhalb' => $insideResult,
            'authorizer_sah' => $seen,
        ]);

        $this->assertStringStartsWith('abgewiesen', $outsideResult);
        $this->assertStringStartsWith('abgewiesen', $boundResult, 'Ohne Dateinamen darf der Authorizer nichts erlauben');
        $this->assertStringStartsWith('ausgeführt', $insideResult);
        $this->assertContains('innen', array_column($connection->select('PRAGMA database_list'), 'name'));
    }

    #[DataProvider('serverDrivers')]
    public function test_server_account_rights_reveal_whether_files_on_the_server_are_readable(string $driver): void
    {
        $connection = $this->connection($driver);

        $finding = match ($driver) {
            'mysql', 'mariadb' => [
                'konto' => $connection->selectOne('SELECT CURRENT_USER() AS account')->account,
                'alle_rechte' => collect($connection->select('SHOW GRANTS'))->contains(fn (object $row) => str_contains(implode(' ', (array) $row), 'ALL PRIVILEGES ON *.*')),
                'secure_file_priv' => $connection->selectOne('SELECT @@secure_file_priv AS v')->v,
                'load_file_liest' => $connection->selectOne("SELECT LOAD_FILE('/etc/hosts') IS NOT NULL AS readable")->readable,
            ],
            'pgsql' => [
                'konto' => $connection->selectOne('SELECT current_user AS account')->account,
                'superuser' => $connection->selectOne('SELECT rolsuper FROM pg_roles WHERE rolname = current_user')->rolsuper,
                'pg_read_file_liest' => $this->attempt(fn () => $connection->selectOne("SELECT length(pg_read_file('/etc/hosts')) > 0 AS readable")->readable),
            ],
        };

        $this->recordFinding('f7-kontorechte', $driver, $finding);

        $this->assertNotEmpty($finding['konto']);
    }

    public function test_mysql_client_refuses_load_data_local_infile_by_default(): void
    {
        $connection = $this->freshConnection('mysql');
        $connection->statement('CREATE TABLE probe_lines (line TEXT)');

        $outcome = $this->attempt(fn () => $connection->statement("LOAD DATA LOCAL INFILE '/etc/hosts' INTO TABLE probe_lines"));

        $this->recordFinding('f7-load-data-local', 'mysql', ['ergebnis' => $outcome, 'zeilen' => $connection->table('probe_lines')->count()]);

        $this->assertSame(0, $connection->table('probe_lines')->count(), 'Der Client hat eine Datei des App-Servers hochgeladen');
    }

    #[DataProvider('drivers')]
    public function test_query_exception_message_contains_bound_values(string $driver): void
    {
        $connection = $this->freshConnection($driver);
        $connection->getSchemaBuilder()->create('probe_secret', function (Blueprint $table) {
            $table->id();
            $table->string('token', 10);
        });

        $message = '';
        try {
            $connection->table('probe_secret')->insert(['id' => 1, 'token' => 'abc']);
            $connection->table('probe_secret')->insert(['id' => 1, 'token' => 'Geheimwert']);
        } catch (QueryException $exception) {
            $message = $exception->getMessage();
        }

        // Laravel setzt die Bindungen in die Meldung ein: Fehlermeldungen tragen Daten in Protokolle.
        $this->assertStringContainsString('Geheimwert', $message);
    }

    private function readOnlySqlite(string $file): Connection
    {
        $config = $this->config('sqlite');
        $config['database'] = $file;
        $config['options'] = [Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READONLY];

        /** @var Connection $connection */
        $connection = DB::build($config);

        return $connection;
    }

    /**
     * Führt eine Anweisung so aus, wie der SQL-Editor es auf einer schreibgeschützten Verbindung täte:
     * eine Anweisung je Aufruf, vorbereitet (keine Mehrfachanweisungen), in einer Nur-lesen-Transaktion,
     * die immer zurückgerollt wird.
     */
    private function runGuarded(Connection $connection, string $driver, string $sql): string
    {
        try {
            match ($driver) {
                'mysql', 'mariadb' => $this->guardMysql($connection, $sql),
                'pgsql' => $this->guardPgsql($connection, $sql),
                'sqlite' => $connection->statement($sql),
            };

            return 'ausgeführt';
        } catch (Throwable $exception) {
            return 'abgewiesen: '.mb_substr(preg_replace('/\s+/', ' ', $exception->getMessage()), 0, 120);
        }
    }

    private function guardMysql(Connection $connection, string $sql): void
    {
        $connection->statement('SET SESSION TRANSACTION READ ONLY');
        $connection->beginTransaction();

        try {
            $connection->statement($sql);
        } finally {
            try {
                $connection->rollBack();
            } catch (Throwable) {
                // DDL oder COMMIT haben die Transaktion schon beendet.
            }
        }
    }

    private function guardPgsql(Connection $connection, string $sql): void
    {
        $connection->beginTransaction();

        try {
            $connection->statement('SET TRANSACTION READ ONLY');
            $connection->statement($sql);
        } finally {
            try {
                $connection->rollBack();
            } catch (Throwable) {
                // COMMIT des Menschen hat die Transaktion schon beendet.
            }
        }
    }

    private function attempt(callable $callback): string
    {
        try {
            return 'ausgeführt: '.var_export($callback(), true);
        } catch (Throwable $exception) {
            return 'abgewiesen: '.mb_substr(preg_replace('/\s+/', ' ', $exception->getMessage()), 0, 120);
        }
    }
}
