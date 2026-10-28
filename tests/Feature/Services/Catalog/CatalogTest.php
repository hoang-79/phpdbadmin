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

    /**
     * @return array<string, array{0: DatabaseDriver}>
     */
    public static function mysqlDrivers(): array
    {
        return ['MySQL' => [DatabaseDriver::MySql], 'MariaDB' => [DatabaseDriver::MariaDb]];
    }

    #[DataProvider('mysqlDrivers')]
    public function test_with_database_name_only_that_database_is_shown(DatabaseDriver $driver): void
    {
        $settings = $this->scratchSettings($driver, $this->folder);

        $only = $this->savedConnection($settings);
        $all = $this->savedConnection($settings->withDatabase(null));

        $this->assertSame(['phpdbadmin_test'], app(Catalog::class)->schemas($only));
        $this->assertContains('phpdbadmin_test', app(Catalog::class)->schemas($all));
        $this->assertGreaterThan(1, count(app(Catalog::class)->schemas($all)));
    }

    public function test_postgres_with_all_databases_lists_and_opens_other_databases(): void
    {
        $settings = $this->scratchSettings(DatabaseDriver::Postgres, $this->folder);
        $this->createSampleSchema(app(ConnectionFactory::class)->make($settings), DatabaseDriver::Postgres);
        $all = $this->savedConnection($settings->withDatabase(null));
        $catalog = app(Catalog::class);

        $this->assertContains('phpdbadmin_test', $catalog->databases($all) ?? []);
        $this->assertNull($catalog->databases($this->savedConnection($settings)));

        $tables = array_column($catalog->groups($all, 'public', 'phpdbadmin_test'), null, 'key')['tables']['items'];
        $this->assertSame(['customers', 'orders'], array_column($tables, 'name'));
    }
}
