<?php

namespace Tests\Concerns;

use App\Connections\ConnectionFactory;
use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * Die lokalen Datenbank-Server als Ziele für Tests (B-03): MySQL 8.4 auf 3307, MariaDB 10.11 auf 3306, PostgreSQL 18
 * auf 5432 (Herd, Konto root ohne Passwort). Ist ein Server nicht erreichbar, wird der Test für diesen Treiber
 * ausdrücklich übersprungen — nie still bestanden. Ports und Konten lassen sich über PHPDBADMIN_TEST_* ändern (CI).
 */
trait TargetDatabases
{
    /**
     * @return array<string, array{0: DatabaseDriver}>
     */
    public static function serverDrivers(): array
    {
        return [
            'MySQL' => [DatabaseDriver::MySql],
            'MariaDB' => [DatabaseDriver::MariaDb],
            'PostgreSQL' => [DatabaseDriver::Postgres],
        ];
    }

    protected function serverSettings(DatabaseDriver $driver, array $overrides = []): ConnectionSettings
    {
        $prefix = 'PHPDBADMIN_TEST_'.strtoupper($driver->value).'_';
        $port = (int) (getenv($prefix.'PORT') ?: match ($driver) {
            DatabaseDriver::MySql => 3307,
            DatabaseDriver::MariaDb => 3306,
            default => 5432,
        });

        $settings = [
            'driver' => $driver,
            'host' => getenv($prefix.'HOST') ?: '127.0.0.1',
            'port' => $port,
            'database' => $driver === DatabaseDriver::Postgres ? 'postgres' : null,
            'username' => getenv($prefix.'USERNAME') ?: 'root',
            'password' => getenv($prefix.'PASSWORD') ?: null,
            ...$overrides,
        ];

        $this->skipUnlessReachable($driver, (string) $settings['host'], (int) $settings['port']);

        return new ConnectionSettings(...$settings);
    }

    /**
     * Leere Test-Datenbank (targetTestDatabase) auf dem Server (bzw. eine leere SQLite-Datei im Ordner $folder) —
     * wird bei Bedarf angelegt und vor jedem Test geleert.
     */
    protected function scratchSettings(DatabaseDriver $driver, string $folder): ConnectionSettings
    {
        if ($driver === DatabaseDriver::Sqlite) {
            $file = $folder.'/vertrag-'.getmypid().'.sqlite';
            @unlink($file);
            touch($file);

            return new ConnectionSettings(DatabaseDriver::Sqlite, sqlitePath: $file);
        }

        $server = $this->serverSettings($driver);
        $root = app(ConnectionFactory::class)->make($server);

        if ($driver === DatabaseDriver::Postgres) {
            if ($root->selectOne('select 1 as found from pg_database where datname = ?', [$this->targetTestDatabase()]) === null) {
                $root->statement('create database '.$this->targetTestDatabase());
            }
        } else {
            $root->statement('create database if not exists '.$this->targetTestDatabase().' character set utf8mb4 collate utf8mb4_unicode_ci');
        }

        app(ConnectionFactory::class)->release($root);

        $settings = new ConnectionSettings($driver, $server->host, $server->port, $this->targetTestDatabase(), $server->username, $server->password);
        $this->emptyDatabase(app(ConnectionFactory::class)->make($settings), $driver);

        return $settings;
    }

    /**
     * Name der Test-Datenbank auf den Servern; parallele Arbeitskopien setzen PHPDBADMIN_TEST_DATABASE, damit sich
     * ihre Läufe nicht gegenseitig die Tabellen löschen.
     */
    protected function targetTestDatabase(): string
    {
        return getenv('PHPDBADMIN_TEST_DATABASE') ?: 'phpdbadmin_test';
    }

    protected function emptyDatabase(Connection $connection, DatabaseDriver $driver): void
    {
        $schema = $connection->getSchemaBuilder();
        $schema->dropAllViews();
        $schema->dropAllTables();

        if ($driver === DatabaseDriver::Postgres) {
            $schema->dropAllTypes();
            $connection->statement('drop function if exists vertrag_orders_note() cascade');
            $connection->statement('drop function if exists vertrag_netto(numeric)');
            $connection->statement('drop sequence if exists vertrag_nummern');
        }

        if ($driver === DatabaseDriver::MySql || $driver === DatabaseDriver::MariaDb) {
            $connection->statement('drop function if exists vertrag_netto');
            $connection->statement('drop event if exists vertrag_aufraeumen');
        }

        $this->dropOtherObjects($connection, $driver);
    }

    /**
     * Routinen, Ereignisse und Sequenzen, die Tests angelegt haben (S-06) — Tabellen und Sichten löscht Laravel.
     */
    protected function dropOtherObjects(Connection $connection, DatabaseDriver $driver): void
    {
        if ($driver === DatabaseDriver::MySql || $driver === DatabaseDriver::MariaDb) {
            foreach ($connection->select('select routine_name as name, routine_type as kind from information_schema.routines where routine_schema = database()') as $routine) {
                $connection->unprepared('drop '.strtolower((string) $routine->kind).' if exists `'.str_replace('`', '``', (string) $routine->name).'`');
            }

            foreach ($connection->select('select event_name as name from information_schema.events where event_schema = database()') as $event) {
                $connection->unprepared('drop event if exists `'.str_replace('`', '``', (string) $event->name).'`');
            }
        }

        if ($driver === DatabaseDriver::Postgres) {
            foreach ($connection->select(
                "select p.oid::regprocedure::text as signature, p.prokind as kind from pg_proc p join pg_namespace n on n.oid = p.pronamespace
                 where n.nspname = 'public' and p.prokind in ('f', 'p') and not exists (select 1 from pg_depend d where d.objid = p.oid and d.deptype = 'e')",
            ) as $routine) {
                $connection->unprepared('drop '.($routine->kind === 'p' ? 'procedure' : 'function').' if exists '.$routine->signature.' cascade');
            }

            foreach ($connection->select("select quote_ident(sequencename) as name from pg_sequences where schemaname = 'public'") as $sequence) {
                $connection->unprepared('drop sequence if exists public.'.$sequence->name.' cascade');
            }

            $connection->getSchemaBuilder()->dropAllTypes();
        }
    }

    /**
     * Eine gespeicherte Verbindung zu diesen Einstellungen — so, wie der Arbeitsbildschirm sie öffnet.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function savedConnection(ConnectionSettings $settings, ?User $owner = null, array $attributes = []): DatabaseConnection
    {
        return DatabaseConnection::factory()->for($owner ?? User::factory()->create(), 'owner')->create([
            'driver' => $settings->driver,
            'host' => $settings->host,
            'port' => $settings->port,
            'database' => $settings->database,
            'username' => $settings->username,
            'password' => $settings->password,
            'sqlite_path' => $settings->sqlitePath,
            'options' => $settings->driver->isServer() ? ['sslmode' => 'disable'] : null,
            ...$attributes,
        ]);
    }

    protected function skipUnlessReachable(DatabaseDriver $driver, string $host, int $port): void
    {
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 0.5);

        if ($socket === false) {
            $this->markTestSkipped($driver->label().' auf '.$host.':'.$port.' nicht erreichbar — übersprungen, nicht bestanden');
        }

        fclose($socket);
    }

    protected function sampleSchemaName(DatabaseDriver $driver): string
    {
        return match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => $this->targetTestDatabase(),
            DatabaseDriver::Postgres => 'public',
            DatabaseDriver::Sqlite => 'main',
        };
    }

    /**
     * Lesekonto «phpdbadmin_lesen» mit Leserecht auf die Test-Datenbank — legt es bei Bedarf an (V-10).
     */
    protected function readAccount(DatabaseDriver $driver, Connection $root): ConnectionSettings
    {
        $password = 'Lesen-Test-4711';

        if ($driver === DatabaseDriver::Postgres) {
            if ($root->selectOne("select 1 as found from pg_roles where rolname = 'phpdbadmin_lesen'") === null) {
                $root->statement("create role phpdbadmin_lesen login password '".$password."'");
            }
            $root->statement('grant connect on database '.$this->targetTestDatabase().' to phpdbadmin_lesen');
            $root->statement('grant usage on schema public to phpdbadmin_lesen');
        } else {
            foreach (['localhost', '127.0.0.1', '%'] as $host) {
                $root->statement("create user if not exists 'phpdbadmin_lesen'@'".$host."' identified by '".$password."'");
                $root->statement('grant select on '.$this->targetTestDatabase().".* to 'phpdbadmin_lesen'@'".$host."'");
            }
        }

        $server = $this->serverSettings($driver);

        return new ConnectionSettings($driver, $server->host, $server->port, $this->targetTestDatabase(), 'phpdbadmin_lesen', $password);
    }

    /**
     * Beispielschema mit allem, was der Katalog liefern muss (aus der Probe F-1).
     */
    protected function createSampleSchema(Connection $connection, DatabaseDriver $driver): void
    {
        // Alle Merkmale, die die Strukturansicht zeigen muss (aus der Probe F-1).
        $connection->getSchemaBuilder()->create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable()->comment('Anzeigename');
            $table->enum('status', ['aktiv', 'gesperrt'])->default('aktiv');
            $table->decimal('balance', 10, 2)->default(0);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('name');
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

        foreach (match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => [
                'create function vertrag_netto(betrag decimal(10,2)) returns decimal(10,2) deterministic no sql return betrag / 1.081',
                'create event vertrag_aufraeumen on schedule every 1 day do delete from orders where 1 = 0',
            ],
            DatabaseDriver::Postgres => [
                'create function vertrag_netto(betrag numeric) returns numeric language sql immutable as $$ select betrag / 1.081 $$',
                'create sequence vertrag_nummern',
            ],
            DatabaseDriver::Sqlite => [],
        } as $statement) {
            $connection->unprepared($statement);
        }
    }
}
