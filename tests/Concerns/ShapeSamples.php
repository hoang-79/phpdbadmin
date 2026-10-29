<?php

namespace Tests\Concerns;

use App\Connections\ConnectionFactory;
use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * Zwei Stände desselben Schemas für Schemavergleich (S-08) und Migration (S-09): «neu» (Quelle) und «alt» (Ziel)
 * mit allem, was ein Vergleich finden muss — fehlende und zusätzliche Tabellen, Spalten mit anderer Länge, anderem
 * NULL und anderer Vorgabe, Indizes und ein Fremdschlüssel mit anderer Regel.
 *
 * Eine Seite ist eine Verbindung mit einem Schema. Die zweite Seite auf demselben Server: eigene Datenbank
 * (MySQL, MariaDB: `<Testdatenbank>_b`), eigenes Schema (PostgreSQL: `vergleich_b`) oder eigene Datei (SQLite).
 */
trait ShapeSamples
{
    use TargetDatabases;

    /** @var list<Connection> */
    private array $shapeConnections = [];

    /** @var list<array{0: DatabaseDriver, 1: string}> */
    private array $shapeCleanup = [];

    /**
     * Erste Seite: die leere Testdatenbank des Treibers.
     *
     * @return array{0: Connection, 1: string}
     */
    protected function firstSide(DatabaseDriver $driver, string $folder): array
    {
        $connection = $this->shapeConnection($this->scratchSettings($driver, $folder));

        return [$connection, $this->sampleSchemaName($driver)];
    }

    /**
     * Zweite Seite auf demselben Server, leer.
     *
     * @return array{0: Connection, 1: string}
     */
    protected function secondSide(DatabaseDriver $driver, string $folder): array
    {
        $name = $this->targetTestDatabase().'_b';

        if ($driver === DatabaseDriver::Sqlite) {
            $file = $folder.'/zweite-'.getmypid().'.sqlite';
            @unlink($file);
            touch($file);

            return [$this->shapeConnection(new ConnectionSettings(DatabaseDriver::Sqlite, sqlitePath: $file)), 'main'];
        }

        $server = $this->serverSettings($driver);

        if ($driver === DatabaseDriver::Postgres) {
            $connection = $this->shapeConnection(new ConnectionSettings($driver, $server->host, $server->port, $this->targetTestDatabase(), $server->username, $server->password));
            $connection->statement('drop schema if exists vergleich_b cascade');
            $connection->statement('create schema vergleich_b');
            $this->shapeCleanup[] = [$driver, 'vergleich_b'];

            return [$connection, 'vergleich_b'];
        }

        $root = $this->shapeConnection($server);
        $root->statement('drop database if exists '.$name);
        $root->statement('create database '.$name.' character set utf8mb4 collate utf8mb4_unicode_ci');
        $this->shapeCleanup[] = [$driver, $name];

        return [$this->shapeConnection(new ConnectionSettings($driver, $server->host, $server->port, $name, $server->username, $server->password)), $name];
    }

    protected function shapeConnection(ConnectionSettings $settings): Connection
    {
        return $this->shapeConnections[] = app(ConnectionFactory::class)->make($settings);
    }

    /** Zweite Datenbanken und Schemas entfernen, Verbindungen trennen — aus tearDown() aufrufen. */
    protected function cleanUpShapes(): void
    {
        foreach ($this->shapeCleanup as [$driver, $name]) {
            $connection = array_find($this->shapeConnections, fn (Connection $connection): bool => $connection->getDriverName() === $driver->value);

            try {
                $connection?->statement($driver === DatabaseDriver::Postgres ? 'drop schema if exists '.$name.' cascade' : 'drop database if exists '.$name);
            } catch (\Throwable) {
                // Aufräumen nach bestem Wissen — ein Fehler hier darf den Test nicht verdecken.
            }
        }

        foreach ($this->shapeConnections as $connection) {
            $connection->disconnect();
        }

        $this->shapeConnections = [];
        $this->shapeCleanup = [];
    }

    /**
     * Stand «neu»: Kunden, Bestellungen (Fremdschlüssel mit CASCADE), Artikel.
     */
    protected function createNewState(Connection $connection, string $schema): void
    {
        $builder = $connection->getSchemaBuilder();
        $prefix = $this->tablePrefix($connection, $schema);

        $builder->create($prefix.'kunden', function (Blueprint $table) {
            $table->id();
            $table->string('email', 100)->unique();
            $table->string('name')->nullable();
            $table->enum('status', ['aktiv', 'gesperrt'])->default('aktiv');
            $table->decimal('saldo', 10, 2)->default(0);
            $table->boolean('aktiv')->default(true);
            $table->json('meta')->nullable();
            $table->date('geburtstag')->nullable();
            $table->timestamp('erstellt')->useCurrent();
            $table->index('name');
        });

        $builder->create($prefix.'bestellungen', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('kunde_id')->constrained($prefix.'kunden')->cascadeOnDelete();
            $table->decimal('total', 10, 2);
            $table->text('notiz')->nullable();
            $table->index('total');
        });

        $builder->create($prefix.'artikel', function (Blueprint $table) {
            $table->id();
            $table->string('nummer', 20)->unique();
            $table->decimal('preis', 8, 2);
            $table->uuid('kennung')->nullable();
        });
    }

    /**
     * Stand «alt»: Kunden mit kürzerer E-Mail ohne Eindeutigkeit, Name Pflicht, andere Vorgabe, einer Spalte, die
     * wegfällt, ohne «aktiv» und «meta»; Bestellungen mit RESTRICT und ohne Index auf «total»; ein Archiv, das es in
     * «neu» nicht gibt.
     */
    protected function createOldState(Connection $connection, string $schema): void
    {
        $builder = $connection->getSchemaBuilder();
        $prefix = $this->tablePrefix($connection, $schema);

        $builder->create($prefix.'kunden', function (Blueprint $table) {
            $table->id();
            $table->string('email', 50)->index();
            $table->string('name');
            $table->enum('status', ['aktiv', 'gesperrt'])->default('gesperrt');
            $table->decimal('saldo', 10, 2)->default(0);
            $table->string('alt_feld')->nullable();
            $table->date('geburtstag')->nullable();
            $table->timestamp('erstellt')->useCurrent();
            $table->index('name');
        });

        $builder->create($prefix.'bestellungen', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('kunde_id')->constrained($prefix.'kunden')->restrictOnDelete();
            $table->decimal('total', 10, 2);
            $table->text('notiz')->nullable();
        });

        $builder->create($prefix.'archiv', function (Blueprint $table) {
            $table->id();
            $table->text('eintrag');
        });
    }

    /**
     * Anweisungen eines Skripts einzeln ausführen — so, wie der SQL-Editor sie ausführen würde.
     *
     * @param  list<string>  $statements
     */
    protected function applyStatements(Connection $connection, array $statements): void
    {
        foreach ($statements as $statement) {
            $connection->unprepared($statement);
        }
    }

    /** Tabellen der zweiten PostgreSQL-Seite liegen im eigenen Schema; sonst reicht der Name. */
    private function tablePrefix(Connection $connection, string $schema): string
    {
        return $connection->getDriverName() === 'pgsql' && $schema !== 'public' ? $schema.'.' : '';
    }
}
