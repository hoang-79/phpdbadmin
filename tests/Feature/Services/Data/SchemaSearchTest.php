<?php

namespace Tests\Feature\Services\Data;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Services\Data\SchemaSearch;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Suche über ein ganzes Schema (D-08) je Treiber: Treffer je Tabelle in den Textspalten, ohne Rücksicht auf Gross-
 * und Kleinschreibung, Platzhalter wörtlich; Treffer- und Zeitgrenze sagen, was ungesucht blieb.
 */
#[Group('target-databases')]
class SchemaSearchTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    private Connection $writer;

    private DatabaseConnection $connection;

    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/suche-'.getmypid());
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
    public function test_finds_hits_per_table_in_text_columns(DatabaseDriver $driver): void
    {
        $this->samples($driver);

        $outcome = app(SchemaSearch::class)->search($this->connection, null, $this->schema, 'zürich');

        // Dieselbe Bedingung wie die Suche im Raster: MySQL und MariaDB vergleichen nach der Kollation, die auch Akzente
        // übergeht («Zurich»); PostgreSQL und SQLite nur ohne Gross- und Kleinschreibung.
        $inCustomers = $this->ignoresAccents($driver) ? 3 : 2;
        $this->assertSame([['table' => 'kunden', 'hits' => $inCustomers, 'more' => false, 'columns' => ['name', 'ort']], ['table' => 'notizen', 'hits' => 1, 'more' => false, 'columns' => ['text']]], $outcome['tables']);
        $this->assertSame([2, 3, null, $inCustomers + 1], [$outcome['searched'], $outcome['total'], $outcome['stoppedBy'], $outcome['hits']], 'zahlen hat keine Textspalte, die Sicht zählt nicht');

        $literal = app(SchemaSearch::class)->search($this->connection, null, $this->schema, '50%');
        $this->assertSame([['table' => 'notizen', 'hits' => 1, 'more' => false, 'columns' => ['text']]], $literal['tables'], '«500 Stück» ist kein Treffer');
    }

    #[DataProvider('drivers')]
    public function test_hit_and_time_limits_say_what_stayed_unsearched(DatabaseDriver $driver): void
    {
        $this->samples($driver);

        config(['phpdbadmin.data.search_hits' => 2]);
        $hits = app(SchemaSearch::class)->search($this->connection, null, $this->schema, 'zürich');
        $this->assertSame('hits', $hits['stoppedBy']);
        $this->assertSame(2, $hits['hits']);
        $this->assertSame([['table' => 'kunden', 'hits' => 2, 'more' => $this->ignoresAccents($driver), 'columns' => ['name', 'ort']]], $hits['tables']);
        $this->assertContains('notizen', $hits['unsearched']);

        config(['phpdbadmin.data.search_hits' => 1000, 'phpdbadmin.data.search_seconds' => 0]);
        $time = app(SchemaSearch::class)->search($this->connection, null, $this->schema, 'zürich');
        $this->assertSame(['time', 0, []], [$time['stoppedBy'], $time['searched'], $time['tables']]);
        $this->assertEqualsCanonicalizing(['kunden', 'notizen', 'zahlen'], $time['unsearched']);
    }

    private function ignoresAccents(DatabaseDriver $driver): bool
    {
        return $driver === DatabaseDriver::MySql || $driver === DatabaseDriver::MariaDb;
    }

    private function samples(DatabaseDriver $driver): void
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $this->writer = app(ConnectionFactory::class)->make($settings);
        $this->connection = $this->savedConnection($settings);
        $this->schema = $this->sampleSchemaName($driver);

        $schema = $this->writer->getSchemaBuilder();
        $schema->create('kunden', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ort')->nullable();
        });
        $schema->create('notizen', function (Blueprint $table) {
            $table->id();
            $table->text('text');
        });
        $schema->create('zahlen', function (Blueprint $table) {
            $table->id();
            $table->integer('n');
        });
        $this->writer->statement('create view kunden_ansicht as select id, name, ort from kunden');

        $this->writer->table('kunden')->insert([
            ['name' => 'Anna', 'ort' => 'Zürich'],
            ['name' => 'Zürich Versicherung', 'ort' => 'Bern'],
            ['name' => 'Beat', 'ort' => 'Zurich'],
        ]);
        $this->writer->table('notizen')->insert([['text' => 'Treffen in ZÜRICH'], ['text' => 'Rabatt 50% heute'], ['text' => '500 Stück']]);
        $this->writer->table('zahlen')->insert([['n' => 1]]);
    }
}
