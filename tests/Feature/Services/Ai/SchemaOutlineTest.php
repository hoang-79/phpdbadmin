<?php

namespace Tests\Feature\Services\Ai;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Services\Ai\SchemaOutline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Umriss des Schemas für die KI-Hilfe (Q-08) je Treiber: Spalten mit Typ, Primär- und Fremdschlüssel aus dem Katalog,
 * Kürzung ganzer Tabellen bei zu langem Text.
 */
#[Group('target-databases')]
class SchemaOutlineTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/umriss-'.getmypid());
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
    public function test_outlines_columns_and_keys_from_the_catalog(DatabaseDriver $driver): void
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $this->createSampleSchema(app(ConnectionFactory::class)->make($settings), $driver);

        $outline = app(SchemaOutline::class)->describe($this->savedConnection($settings), $this->sampleSchemaName($driver), null, 60000);

        $lines = collect(explode("\n", $outline['text']))->keyBy(fn (string $line): string => strstr($line, ' (', true) ?: $line);
        $this->assertMatchesRegularExpression('/^customers \(id [^,]+ PK, email [^,]+ NOT NULL, name [^,]+, /', (string) $lines['customers']);
        $this->assertMatchesRegularExpression('/customer_id [^,]+ NOT NULL → customers\.id/', (string) $lines['orders']);
        $this->assertSame(0, $outline['omitted']);

        $short = app(SchemaOutline::class)->describe($this->savedConnection($settings), $this->sampleSchemaName($driver), null, strlen((string) $lines['customers']) + 1);
        $this->assertSame(1, $short['tables']);
        $this->assertSame($outline['tables'] - 1, $short['omitted']);
    }
}
