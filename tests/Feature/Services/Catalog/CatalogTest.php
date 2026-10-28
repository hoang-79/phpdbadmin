<?php

namespace Tests\Feature\Services\Catalog;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Services\Catalog\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Navigator (O-01, O-04): dieselben Objektgruppen für alle vier Treiber; was ein Treiber nicht kennt, steht mit
 * Erklärung da.
 */
#[Group('target-databases')]
class CatalogTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/katalog-'.getmypid());
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
     * @return array<string, array{0: DatabaseDriver}>
     */
    public static function drivers(): array
    {
        return [...self::serverDrivers(), 'SQLite' => [DatabaseDriver::Sqlite]];
    }

    #[DataProvider('drivers')]
    public function test_groups_are_the_same_for_every_driver(DatabaseDriver $driver): void
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $this->createSampleSchema(app(ConnectionFactory::class)->make($settings), $driver);
        $connection = $this->savedConnection($settings);

        $groups = array_column(app(Catalog::class)->groups($connection, $this->sampleSchemaName($driver)), null, 'key');

        $this->assertSame(['customers', 'orders'], array_column($groups['tables']['items'], 'name'));
        $this->assertSame(['open_orders'], array_column($groups['views']['items'], 'name'));
        $this->assertSame(['orders_note_default'], array_column($groups['triggers']['items'], 'name'));
        $this->assertSame('orders', $groups['triggers']['items'][0]['detail']);

        match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => [
                $this->assertSame(['vertrag_netto'], array_column($groups['routines']['items'], 'name')),
                $this->assertSame(['vertrag_aufraeumen'], array_column($groups['events']['items'], 'name')),
                $this->assertArrayNotHasKey('sequences', $groups),
            ],
            DatabaseDriver::Postgres => [
                $this->assertContains('vertrag_netto', array_column($groups['routines']['items'], 'name')),
                $this->assertContains('vertrag_nummern', array_column($groups['sequences']['items'], 'name')),
                $this->assertArrayNotHasKey('events', $groups),
            ],
            DatabaseDriver::Sqlite => [
                $this->assertSame([], $groups['routines']['items']),
                $this->assertNotNull($groups['routines']['note']),
            ],
        };
    }

    #[DataProvider('drivers')]
    public function test_default_schema_is_the_configured_database(DatabaseDriver $driver): void
    {
        $connection = $this->savedConnection($this->scratchSettings($driver, $this->folder));

        $this->assertSame($this->sampleSchemaName($driver), app(Catalog::class)->defaultSchema($connection));
    }
}
