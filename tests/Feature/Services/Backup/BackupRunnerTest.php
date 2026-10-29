<?php

namespace Tests\Feature\Services\Backup;

use App\Connections\ConnectionFactory;
use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;
use App\Enums\TransferFormat;
use App\Enums\TransferKind;
use App\Enums\TransferStatus;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\Transfer;
use App\Services\Backup\BackupRunner;
use App\Services\Backup\Tools;
use App\Services\Transfer\TransferFailed;
use App\Services\Transfer\TransferProgress;
use App\Services\Transfer\TransferRunner;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\Concerns\TransferSamples;
use Tests\TestCase;

/**
 * Abnahme E-03 je Treiber: Sicherung mit dem Werkzeug des Servers → Wiederherstellung in eine leere Datenbank →
 * dieselben Daten. Dazu: Versionsprüfung, fehlendes Werkzeug, und eine hochgeladene Datei kann keine Befehle auf dem
 * phpdbadmin-Server ausführen. Fehlt ein Werkzeug, wird der Test sichtbar übersprungen.
 */
#[Group('target-databases')]
class BackupRunnerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use TargetDatabases;
    use TransferSamples;

    private const array TABLES = ['kunden', 'bestellungen', 'positionen', 'protokoll'];

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/sicherung-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'phpdbadmin.connections.sqlite_paths' => [$this->folder],
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: DatabaseDriver, 1: TransferFormat}>
     */
    public static function backups(): array
    {
        return [
            'MySQL (mysqldump)' => [DatabaseDriver::MySql, TransferFormat::Sql],
            'MariaDB (mariadb-dump)' => [DatabaseDriver::MariaDb, TransferFormat::Sql],
            'PostgreSQL (pg_dump, Custom-Format)' => [DatabaseDriver::Postgres, TransferFormat::PgDump],
            'PostgreSQL (pg_dump, Textformat)' => [DatabaseDriver::Postgres, TransferFormat::Sql],
            'SQLite (VACUUM INTO, sqlite3)' => [DatabaseDriver::Sqlite, TransferFormat::SqliteFile],
        ];
    }

    #[DataProvider('backups')]
    public function test_backup_restores_into_an_empty_database_with_the_same_data(DatabaseDriver $driver, TransferFormat $format): void
    {
        Storage::fake(Transfer::DISK);
        $this->requireTools($driver);
        [$source, $connection, $sourceName] = $this->source($driver);
        [$target, $targetConnection, $targetName] = $this->emptyTarget($driver, $connection);
        $before = $this->tables($source);

        $backup = $this->runBackup($connection, $sourceName, $format);
        $this->assertSame(TransferStatus::Done, $backup->status, json_encode($backup->error) ?: '');
        $this->assertTrue($backup->isDownloadable());
        $this->assertGreaterThan(0, $backup->file_size);
        $this->assertSame(1, AuditEntry::query()->where('action', 'transfer.backup')->count());

        $restore = $this->runRestore($targetConnection, $targetName, $format, Storage::disk(Transfer::DISK)->path((string) $backup->file_path));

        $this->assertSame(TransferStatus::Done, $restore->status, json_encode($restore->error) ?: '');
        $this->assertNotNull($restore->result['method'] ?? null);
        $this->assertEquals($before, $this->tables($target));
        $this->assertSame(5, (int) $target->table('aktive_kunden')->count(), 'Sicht mitgesichert');
        $target->table('protokoll')->insert(['eintrag' => 'neu']);
        $this->assertSame(42, (int) $target->table('protokoll')->where('eintrag', 'neu')->value('zahl'), 'Trigger mitgesichert');
        $entry = AuditEntry::query()->where('action', 'data.restore')->sole();
        $this->assertSame($targetName, $entry->target);
        $this->assertArrayNotHasKey('failed', $entry->context ?? []);
    }

    /**
     * @return array<string, array{0: DatabaseDriver}>
     */
    public static function clients(): array
    {
        return [...self::serverDrivers()];
    }

    #[DataProvider('clients')]
    public function test_an_uploaded_file_cannot_run_commands_on_the_phpdbadmin_server(DatabaseDriver $driver): void
    {
        Storage::fake(Transfer::DISK);
        $this->requireTools($driver);
        [, $connection] = $this->source($driver);
        [$target, $targetConnection, $targetName] = $this->emptyTarget($driver, $connection);
        $markers = array_map(fn (int $number): string => $this->folder.'/befehl-'.$number, [1, 2, 3]);
        $file = $this->folder.'/boese.sql';
        file_put_contents($file, $driver === DatabaseDriver::Postgres
            ? "create table sicher (id int primary key);\n\\! touch {$markers[0]}\n\\o {$markers[1]}\nselect 1;\n"
            : "create table sicher (id int primary key);\n\\! touch {$markers[0]}\nsystem touch {$markers[1]}\ntee {$markers[2]}\ninsert into sicher values (1);\n");

        $restore = $this->runRestore($targetConnection, $targetName, TransferFormat::Sql, $file);

        $this->assertSame(TransferStatus::Failed, $restore->status);
        foreach ($markers as $marker) {
            $this->assertFileDoesNotExist($marker);
        }

        if ($driver === DatabaseDriver::Postgres) {
            $this->assertFalse($target->getSchemaBuilder()->hasTable('sicher'), 'eine Transaktion: nichts geblieben');
        }

        $this->assertTrue(AuditEntry::query()->where('action', 'data.restore')->sole()->context['failed'] ?? false);
    }

    public function test_tool_of_the_wrong_server_is_refused_with_versions(): void
    {
        Storage::fake(Transfer::DISK);
        $mariadbDump = app(Tools::class)->info('mariadb-dump');

        if ($mariadbDump['path'] === null || $mariadbDump['flavor'] !== 'mariadb') {
            $this->markTestSkipped('mariadb-dump nicht gefunden (Pfad in PHPDBADMIN_TOOL_MARIADB_DUMP setzen) — übersprungen, nicht bestanden');
        }

        config(['phpdbadmin.backup.tools.mysqldump' => $mariadbDump['path']]);
        [, $connection, $name] = $this->source(DatabaseDriver::MySql);

        $backup = $this->runBackup($connection, $name, TransferFormat::Sql);

        $this->assertSame(TransferStatus::Failed, $backup->status);
        $this->assertSame(':tool gehört zu MariaDB, der Server ist MySQL :server. Nehmen Sie die Werkzeuge von MySQL.', $backup->error['text'] ?? null);
        $this->assertSame('mysqldump', $backup->error['replace']['tool'] ?? null);
        $this->assertFalse($backup->isDownloadable());
    }

    public function test_missing_tool_is_explained(): void
    {
        Storage::fake(Transfer::DISK);
        config(['phpdbadmin.backup.tools.pg_dump' => $this->folder.'/gibt-es-nicht/pg_dump']);
        [, $connection, $name] = $this->source(DatabaseDriver::Postgres);

        $backup = $this->runBackup($connection, $name, TransferFormat::PgDump);

        $this->assertSame(TransferStatus::Failed, $backup->status);
        $this->assertSame(['tool' => 'pg_dump', 'key' => 'PHPDBADMIN_TOOL_PG_DUMP'], $backup->error['replace'] ?? null);
        $this->assertStringStartsWith(':tool fehlt auf dem phpdbadmin-Server', (string) ($backup->error['text'] ?? ''));
    }

    public function test_connections_through_an_ssh_tunnel_are_not_backed_up_directly(): void
    {
        $connection = DatabaseConnection::factory()->create(['driver' => 'mariadb', 'host' => '10.0.0.5', 'database' => 'shop']);
        $connection->forceFill(['ssh_host' => '127.0.0.1', 'ssh_username' => 'deploy', 'ssh_key' => 'kein-echter-schluessel'])->save();
        $transfer = Transfer::factory()->for($connection->owner)->for($connection, 'databaseConnection')->create([
            'kind' => TransferKind::Backup, 'format' => TransferFormat::Sql, 'status' => TransferStatus::Running,
            'target' => 'shop', 'options' => ['database' => 'shop'], 'file_name' => 'sicherung.sql',
        ]);

        $this->assertTrue(app(BackupRunner::class)->status($connection)['viaSsh']);
        $this->assertFalse(app(BackupRunner::class)->status($connection)['canBackup']);

        $this->expectException(TransferFailed::class);
        $this->expectExceptionMessage('Sicherung und Wiederherstellung gehen noch nicht über einen SSH-Tunnel');
        app(BackupRunner::class)->backup($transfer, new TransferProgress($transfer));
    }

    private function requireTools(DatabaseDriver $driver): void
    {
        $tools = app(Tools::class);
        $needed = Tools::needed($driver);
        // MySQL/MariaDB stellen ohne geschützten Client mit dem eigenen Ausführer wieder her; PostgreSQL braucht beide.
        $required = $driver->isServer() && $driver !== DatabaseDriver::Postgres ? $needed['backup'] : [...$needed['backup'], ...$needed['restore']];

        foreach ($required as $name) {
            if ($tools->info($name)['path'] === null) {
                $this->markTestSkipped($name.' nicht gefunden (Pfad in '.Tools::environmentKey($name).' setzen) — übersprungen, nicht bestanden');
            }
        }
    }

    /**
     * Quelle mit den Beispieldaten des Exports; bei den Servern eine Verbindung für «alle Datenbanken».
     *
     * @return array{0: Connection, 1: DatabaseConnection, 2: string}
     */
    private function source(DatabaseDriver $driver): array
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $writer = app(ConnectionFactory::class)->make($settings);
        $this->createTransferSamples($writer, $driver);

        foreach (match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => ['create trigger protokoll_zahl before insert on protokoll for each row set new.zahl = coalesce(new.zahl, 42)'],
            DatabaseDriver::Postgres => [
                'create or replace function protokoll_zahl() returns trigger language plpgsql as $$ begin new.zahl := coalesce(new.zahl, 42); return new; end $$',
                'create trigger protokoll_zahl before insert on protokoll for each row execute function protokoll_zahl()',
            ],
            DatabaseDriver::Sqlite => ['create trigger protokoll_zahl after insert on protokoll when new.zahl is null begin update protokoll set zahl = 42 where rowid = new.rowid; end'],
        } as $statement) {
            $writer->unprepared($statement);
        }

        $saved = $driver === DatabaseDriver::Sqlite ? $settings : $settings->withDatabase(null);

        return [$writer, $this->savedConnection($saved), $driver === DatabaseDriver::Sqlite ? basename((string) $settings->sqlitePath) : $this->targetTestDatabase()];
    }

    /**
     * Leere Ziel-Datenbank auf demselben Server bzw. eine leere SQLite-Datei mit eigener Verbindung.
     *
     * @return array{0: Connection, 1: DatabaseConnection, 2: string}
     */
    private function emptyTarget(DatabaseDriver $driver, DatabaseConnection $connection): array
    {
        if ($driver === DatabaseDriver::Sqlite) {
            $file = $this->folder.'/ziel-'.getmypid().'.sqlite';
            @unlink($file);
            touch($file);
            $settings = new ConnectionSettings(DatabaseDriver::Sqlite, sqlitePath: $file);

            return [app(ConnectionFactory::class)->make($settings), $this->savedConnection($settings), basename($file)];
        }

        $name = $this->targetTestDatabase().'_ziel';
        $server = $this->serverSettings($driver);
        $root = app(ConnectionFactory::class)->make($server);

        if ($driver === DatabaseDriver::Postgres) {
            $root->statement('drop database if exists '.$name.' with (force)');
            $root->statement('create database '.$name);
        } else {
            $root->statement('drop database if exists '.$name);
            $root->statement('create database '.$name.' character set utf8mb4 collate utf8mb4_unicode_ci');
        }

        app(ConnectionFactory::class)->release($root);

        return [app(ConnectionFactory::class)->make($server->withDatabase($name)), $connection, $name];
    }

    private function runBackup(DatabaseConnection $connection, string $database, TransferFormat $format): Transfer
    {
        $transfer = Transfer::factory()->for($connection->owner)->for($connection, 'databaseConnection')->create([
            'kind' => TransferKind::Backup,
            'format' => $format,
            'status' => TransferStatus::Queued,
            'target' => $database,
            'options' => [
                'database' => $connection->driver === DatabaseDriver::Sqlite ? null : $database,
                'open_database' => $connection->driver === DatabaseDriver::Postgres ? $database : null,
                'objects' => true,
            ],
            'file_name' => 'sicherung.'.$format->extension(),
        ]);

        app(TransferRunner::class)->start($transfer);

        return $transfer->refresh();
    }

    private function runRestore(DatabaseConnection $connection, string $database, TransferFormat $format, string $file): Transfer
    {
        $isSqlite = $connection->driver === DatabaseDriver::Sqlite;
        $transfer = Transfer::factory()->for($connection->owner)->for($connection, 'databaseConnection')->create([
            'kind' => TransferKind::Restore,
            'format' => $format,
            'status' => TransferStatus::Queued,
            'target' => $database,
            'options' => [
                'database' => $isSqlite ? null : $database,
                'schema' => $connection->driver === DatabaseDriver::Postgres ? 'public' : $database,
                'open_database' => $isSqlite ? null : $database,
            ],
            'file_name' => basename($file),
        ]);
        $path = $transfer->directory().'/sicherung.'.$format->extension();
        Storage::disk(Transfer::DISK)->makeDirectory($transfer->directory());
        copy($file, Storage::disk(Transfer::DISK)->path($path));
        $transfer->forceFill(['file_path' => $path, 'file_size' => filesize($file)])->save();

        app(TransferRunner::class)->start($transfer);

        return $transfer->refresh();
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function tables(Connection $connection): array
    {
        return array_combine(self::TABLES, array_map(fn (string $table): array => $this->snapshot($connection, $table), self::TABLES));
    }
}
