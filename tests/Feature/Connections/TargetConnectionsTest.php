<?php

namespace Tests\Feature\Connections;

use App\Connections\ConnectionFactory;
use App\Connections\PasswordRequired;
use App\Connections\TargetConnections;
use App\Enums\DatabaseDriver;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\Feature\Dialects\DialectContractTest;
use Tests\TestCase;
use Throwable;

/**
 * Gespeicherte Verbindungen öffnen (V-04, V-03): schreibgeschützt hält gegen jede schreibende Anweisung, auch die
 * Ausbruchsversuche aus F-7; ohne gespeichertes Passwort erst nach Eingabe in der Sitzung.
 */
#[Group('target-databases')]
class TargetConnectionsTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/ziele-'.getmypid());
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
     * @param  list<string>  $attempts
     */
    #[DataProviderExternal(DialectContractTest::class, 'escapeAttempts')]
    public function test_read_only_connection_rejects_every_write(DatabaseDriver $driver, array $attempts): void
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $writable = app(ConnectionFactory::class)->make($settings);
        $writable->getSchemaBuilder()->create('guard', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        $writable->table('guard')->insert(['name' => 'Bestand']);

        $connection = $this->savedConnection($settings, attributes: ['read_only' => true]);
        $target = app(TargetConnections::class)->open($connection);

        foreach ($attempts as $sql) {
            try {
                $driver->dialect()->runReadOnly($target, fn () => $target->statement($sql));
            } catch (Throwable) {
                // Erwartet: abgewiesen.
            }
        }

        $this->assertSame(['Bestand'], $writable->table('guard')->pluck('name')->all());
        $this->assertFalse($writable->getSchemaBuilder()->hasTable('escape_attempt'));
        $this->assertTrue($writable->getSchemaBuilder()->hasTable('guard'));
    }

    #[DataProvider('serverDrivers')]
    public function test_read_only_session_refuses_plain_writes_too(DatabaseDriver $driver): void
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        app(ConnectionFactory::class)->make($settings)->getSchemaBuilder()->create('guard', function (Blueprint $table) {
            $table->id();
        });

        $target = app(TargetConnections::class)->open($this->savedConnection($settings, attributes: ['read_only' => true]));

        $this->expectException(Throwable::class);

        $target->statement('insert into guard (id) values (1)');
    }

    /**
     * @return array<string, array{0: DatabaseDriver}>
     */
    public static function drivers(): array
    {
        return [...self::serverDrivers(), 'SQLite' => [DatabaseDriver::Sqlite]];
    }

    /**
     * Laravel verbindet bei «SQLSTATE[25006] Read only sql transaction» neu und wiederholt die Anweisung — der
     * Neuaufbau muss Schreibschutz, ATTACH-Wächter und Zeitlimit wieder einrichten.
     */
    #[DataProvider('drivers')]
    public function test_session_protections_survive_a_reconnect(DatabaseDriver $driver): void
    {
        $target = app(TargetConnections::class)->open(
            $this->savedConnection($this->scratchSettings($driver, $this->folder), attributes: ['read_only' => true]),
        );

        $target->reconnect();

        match ($driver) {
            DatabaseDriver::Postgres => [
                $this->assertSame('on', $target->selectOne("select current_setting('default_transaction_read_only') as v")?->v),
                $this->assertSame('15s', $target->selectOne("select current_setting('statement_timeout') as v")?->v),
            ],
            DatabaseDriver::MySql => [
                $this->assertSame(1, (int) $target->selectOne('select @@session.transaction_read_only as v')?->v),
                $this->assertSame(15000, (int) $target->selectOne('select @@session.max_execution_time as v')?->v),
            ],
            DatabaseDriver::MariaDb => [
                $this->assertSame(1, (int) $target->selectOne('select @@session.tx_read_only as v')?->v),
                $this->assertEquals(15.0, (float) $target->selectOne('select @@session.max_statement_time as v')?->v),
            ],
            DatabaseDriver::Sqlite => $this->assertAttachOutsideIsDenied($target),
        };
    }

    #[DataProvider('serverDrivers')]
    public function test_without_stored_password_opens_only_after_it_was_given_for_the_session(DatabaseDriver $driver): void
    {
        $root = app(ConnectionFactory::class)->make($this->scratchSettings($driver, $this->folder));
        $reader = $this->readAccount($driver, $root);
        $connection = $this->savedConnection($reader, attributes: ['store_password' => false, 'password' => null]);
        $targets = app(TargetConnections::class);

        try {
            $targets->open($connection);
            $this->fail('Verbindung ohne Passwort geöffnet');
        } catch (PasswordRequired) {
            // Erwartet.
        }

        $targets->rememberPassword($connection, (string) $reader->password);

        $this->assertNotEmpty($driver->dialect()->serverVersion($targets->open($connection)));
        $this->assertStringNotContainsString((string) $reader->password, (string) json_encode(session()->all()));
    }

    private function assertAttachOutsideIsDenied(Connection $target): void
    {
        $outside = sys_get_temp_dir().'/phpdbadmin-aussen-'.getmypid().'.sqlite';
        touch($outside);

        try {
            $target->statement("attach database '".$outside."' as aussen");
            $this->fail('ATTACH ausserhalb nach dem Neuaufbau erlaubt');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('not authorized', $exception->getMessage());
        } finally {
            @unlink($outside);
        }
    }
}
