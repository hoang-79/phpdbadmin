<?php

namespace Tests\Feature\Services\Data;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Enums\FilterOperator;
use App\Services\Data\GridQuery;
use App\Services\Data\TableData;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Leistung des Rasters (N-01): erste Seite einer Tabelle mit einer Million Zeilen unter einer Sekunde, je Treiber —
 * ohne Sortierung, nach einer indizierten Spalte absteigend, mit Filter und mit Suche. Schwer, deshalb nur mit
 * PHPDBADMIN_LEISTUNG=1; die Zeiten stehen im Protokoll der Timebox.
 */
#[Group('target-databases')]
#[Group('leistung')]
class GridPerformanceTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('PHPDBADMIN_LEISTUNG') !== '1') {
            $this->markTestSkipped('Schwere Messung: nur mit PHPDBADMIN_LEISTUNG=1');
        }

        $this->folder = storage_path('framework/testing/leistung-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'phpdbadmin.connections.sqlite_paths' => [$this->folder],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->folder)) {
            exec('rm -rf '.escapeshellarg($this->folder));
        }

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
    public function test_first_page_of_a_million_rows_under_one_second(DatabaseDriver $driver): void
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $writer = app(ConnectionFactory::class)->make($settings);
        $this->fillMillionRows($writer, $driver);
        $connection = $this->savedConnection($settings);
        $schema = $this->sampleSchemaName($driver);
        $data = app(TableData::class);

        $cases = [
            'ohne Sortierung' => new GridQuery,
            'created_at absteigend' => new GridQuery(sort: [['column' => 'created_at', 'direction' => 'desc']]),
            'Filter category = 42' => new GridQuery(filters: [['column' => 'category', 'operator' => FilterOperator::Equals, 'value' => '42', 'boolean' => 'and']]),
            'Suche «Eintrag 99999»' => new GridQuery(search: 'Eintrag 99999'),
        ];

        $times = [];
        foreach ($cases as $name => $query) {
            $start = hrtime(true);
            $page = $data->page($connection, $schema, 'big', $query);
            $times[$name] = round((hrtime(true) - $start) / 1e6, 1);

            $this->assertNotNull($page);
            $this->assertNotEmpty($page['rows']);
        }

        fwrite(STDERR, PHP_EOL.'N-01 '.$driver->label().': '.json_encode($times, JSON_UNESCAPED_UNICODE).PHP_EOL);
        $this->assertLessThan(1000, $times['ohne Sortierung']);
        $this->assertLessThan(1000, $times['created_at absteigend']);
    }

    private function fillMillionRows(Connection $connection, DatabaseDriver $driver): void
    {
        $schema = $connection->getSchemaBuilder();
        $schema->create('digits', fn (Blueprint $table) => $table->integer('d'));
        $connection->table('digits')->insert(array_map(fn (int $digit) => ['d' => $digit], range(0, 9)));

        $schema->create('big', function (Blueprint $table) {
            $table->id();
            $table->integer('category');
            $table->string('label', 60);
            $table->decimal('amount', 10, 2);
            $table->dateTime('created_at');
            $table->index('created_at');
        });

        [$label, $timestamp] = match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => ["CONCAT('Eintrag ', n)", "DATE_ADD('2026-01-01 00:00:00', INTERVAL n SECOND)"],
            DatabaseDriver::Postgres => ["'Eintrag ' || n", "TIMESTAMP '2026-01-01 00:00:00' + n * INTERVAL '1 second'"],
            DatabaseDriver::Sqlite => ["'Eintrag ' || n", "datetime('2026-01-01 00:00:00', '+' || n || ' seconds')"],
        };

        $connection->statement(
            "insert into big (category, label, amount, created_at)
             select n % 100, {$label}, (n % 10000) / 100.0, {$timestamp}
             from (select a.d + 10 * b.d + 100 * c.d + 1000 * e.d + 10000 * f.d + 100000 * g.d as n
                   from digits a cross join digits b cross join digits c
                   cross join digits e cross join digits f cross join digits g) numbers"
        );

        match ($driver) {
            DatabaseDriver::Postgres, DatabaseDriver::Sqlite => $connection->statement('analyze'),
            default => $connection->statement('analyze table big'),
        };
    }
}
