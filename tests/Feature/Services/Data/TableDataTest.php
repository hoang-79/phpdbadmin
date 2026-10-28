<?php

namespace Tests\Feature\Services\Data;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Enums\FilterOperator;
use App\Models\DatabaseConnection;
use App\Services\Data\GridQuery;
use App\Services\Data\GridQueryFailed;
use App\Services\Data\RowDetail;
use App\Services\Data\TableData;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Datenraster je Treiber (D-01, D-02, D-03, D-06): Blättern über den Schlüssel und den Versatz, NULL ausdrücklich
 * zuletzt, Filter mit Umlauten und Werten, die wie SQL aussehen, freies WHERE, Suche, Zählen mit Schwelle,
 * Fremdschlüssel in beide Richtungen.
 */
#[Group('target-databases')]
class TableDataTest extends TestCase
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

        $this->folder = storage_path('framework/testing/daten-'.getmypid());
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
    public function test_pages_follow_the_primary_key_without_gaps_or_duplicates(DatabaseDriver $driver): void
    {
        $this->people($driver);

        $first = $this->page(new GridQuery(limit: 4));
        $second = $this->page(new GridQuery(after: $first['next']['after'] ?? null, limit: 4));
        $last = $this->page(new GridQuery(after: $second['next']['after'] ?? null, limit: 4));

        $this->assertSame(['1', '2', '3', '4'], array_column($first['keys'], 0));
        $this->assertSame(['5', '6', '7', '8'], array_column($second['keys'], 0));
        $this->assertSame(['9', '10'], array_column($last['keys'], 0));
        $this->assertTrue($first['hasMore']);
        $this->assertFalse($last['hasMore']);
        $this->assertNull($last['next']);
        $this->assertSame(['count' => 10, 'exact' => true], $first['total']);
    }

    #[DataProvider('drivers')]
    public function test_null_sorts_last_in_both_directions(DatabaseDriver $driver): void
    {
        $this->people($driver);

        $ascending = $this->names(new GridQuery(sort: [['column' => 'city', 'direction' => 'asc']]), 'city');
        $descending = $this->names(new GridQuery(sort: [['column' => 'city', 'direction' => 'desc']]), 'city');

        // NULL ausdrücklich zuletzt; die Reihenfolge von «zug» und «Zürich» bestimmt die Kollation des Servers.
        $this->assertSame([null, null], array_slice($ascending, -2));
        $this->assertSame([null, null], array_slice($descending, -2));
        $this->assertSame('Basel', $ascending[0]);
        $this->assertSame(array_reverse(array_slice($ascending, 0, 8)), array_slice($descending, 0, 8));
    }

    #[DataProvider('drivers')]
    public function test_sorting_by_other_column_pages_by_offset_and_stays_stable(DatabaseDriver $driver): void
    {
        $this->people($driver);
        $sort = [['column' => 'score', 'direction' => 'desc']];

        $first = $this->page(new GridQuery(sort: $sort, limit: 6));
        $second = $this->page(new GridQuery(sort: $sort, offset: $first['next']['offset'] ?? 0, limit: 6));

        $this->assertSame(['offset' => 6], $first['next']);
        $ids = [...array_column($first['keys'], 0), ...array_column($second['keys'], 0)];
        $this->assertCount(10, array_unique($ids));
    }

    #[DataProvider('drivers')]
    public function test_filters_bind_values_that_look_like_sql(DatabaseDriver $driver): void
    {
        $this->people($driver);

        $this->assertSame([], $this->names($this->filter('name', FilterOperator::Equals, "x' or '1'='1")));
        $this->assertSame([], $this->names($this->filter('name', FilterOperator::Contains, "'; drop table people; --")));
        $this->assertSame(['100% Bio AG'], $this->names($this->filter('name', FilterOperator::Contains, '%')));
        $this->assertSame(['Anna_Muster'], $this->names($this->filter('name', FilterOperator::Contains, '_')));
        $this->assertSame(10, $this->writer->table('people')->count());
    }

    #[DataProvider('drivers')]
    public function test_contains_ignores_case_and_finds_umlauts(DatabaseDriver $driver): void
    {
        $this->people($driver);

        $this->assertSame(['Jürg Müller'], $this->names($this->filter('name', FilterOperator::Contains, 'müller')));
        $this->assertSame(['Jürg Müller'], $this->names($this->filter('name', FilterOperator::Contains, 'MÜLLER')));
        $this->assertSame(['Jürg Müller'], $this->names($this->filter('name', FilterOperator::StartsWith, 'jürg')));
        $this->assertSame(['Anna_Muster'], $this->names($this->filter('name', FilterOperator::EndsWith, 'MUSTER')));
    }

    #[DataProvider('drivers')]
    public function test_comparison_null_and_combined_filters(DatabaseDriver $driver): void
    {
        $this->people($driver);

        $this->assertCount(2, $this->names($this->filter('city', FilterOperator::IsNull)));
        $this->assertCount(4, $this->names($this->filter('score', FilterOperator::GreaterOrEqual, '70')));

        $either = new GridQuery(filters: [
            ['column' => 'city', 'operator' => FilterOperator::Equals, 'value' => 'Bern', 'boolean' => 'and'],
            ['column' => 'city', 'operator' => FilterOperator::Equals, 'value' => 'Basel', 'boolean' => 'or'],
        ]);
        $this->assertCount(3, $this->names($either));

        $notContains = $this->names($this->filter('city', FilterOperator::NotContains, 'z'), 'city');
        $this->assertContains(null, $notContains, 'NULL gilt als «enthält nicht»');
    }

    #[DataProvider('drivers')]
    public function test_search_runs_over_all_text_columns(DatabaseDriver $driver): void
    {
        $this->people($driver);

        $this->assertSame(['Léa Dubois'], $this->names(new GridQuery(search: 'genève')));
        $this->assertSame(['Jürg Müller'], $this->names(new GridQuery(search: 'MÜLL')));
    }

    #[DataProvider('drivers')]
    public function test_free_where_filters_and_reports_errors_without_writing(DatabaseDriver $driver): void
    {
        $this->people($driver);

        $this->assertSame(['Jürg Müller'], $this->names(new GridQuery(where: 'score = 91')));

        try {
            $this->page(new GridQuery(where: 'gibt_es_nicht = 1'));
            $this->fail('Ungültiges WHERE wurde angenommen');
        } catch (GridQueryFailed $failed) {
            $this->assertSame('sql', $failed->reason);
            $this->assertNotNull($failed->serverMessage);
        }

        try {
            $this->page(new GridQuery(where: '1 = 1; delete from people'));
        } catch (GridQueryFailed) {
            // Mehrere Anweisungen lehnt jeder Treiber ab oder führt nur die erste aus — gelöscht wird nie.
        }
        $this->assertSame(10, $this->writer->table('people')->count());
    }

    #[DataProvider('drivers')]
    public function test_unknown_column_is_reported(DatabaseDriver $driver): void
    {
        $this->people($driver);

        $this->expectException(GridQueryFailed::class);

        $this->page(new GridQuery(sort: [['column' => 'gibt_es_nicht', 'direction' => 'asc']]));
    }

    #[DataProvider('drivers')]
    public function test_counts_exactly_below_threshold_and_estimates_above(DatabaseDriver $driver): void
    {
        $this->people($driver);
        $this->analyze($driver);

        config(['phpdbadmin.data.exact_count_limit' => 5]);
        $estimated = $this->page(new GridQuery(limit: 3))['total'];
        $filtered = $this->page(new GridQuery(filters: [['column' => 'score', 'operator' => FilterOperator::GreaterThan, 'value' => '0', 'boolean' => 'and']], limit: 3))['total'];

        if ($driver === DatabaseDriver::Sqlite) {
            // SQLite führt keine Schätzung — gezählt wird immer genau.
            $this->assertSame(['count' => 10, 'exact' => true], $estimated);
            $this->assertSame(['count' => 9, 'exact' => true], $filtered);
        } else {
            $this->assertFalse($estimated['exact']);
            $this->assertGreaterThan(0, $estimated['count']);
            $this->assertSame(['count' => null, 'exact' => false], $filtered);
        }
        $this->assertSame(10, app(TableData::class)->count($this->connection, $this->schema, 'people', new GridQuery));

        config(['phpdbadmin.data.exact_count_limit' => 1000]);
        $this->assertSame(['count' => 10, 'exact' => true], $this->page(new GridQuery(limit: 3))['total']);
    }

    #[DataProvider('drivers')]
    public function test_values_look_the_same_in_all_four_databases(DatabaseDriver $driver): void
    {
        $this->people($driver);
        $page = $this->page(new GridQuery(limit: 1));
        $row = array_combine(array_column($page['columns'], 'name'), $page['rows'][0]);

        $this->assertSame(1, $row['id']);
        $this->assertTrue($row['active']);
        $this->assertSame(['binary' => 4, 'hex' => '00ff10ab'], $row['photo']);
        $this->assertSame(1000, mb_strlen($row['notes']['text'] ?? ''));
        $this->assertSame(1500, $row['notes']['length'] ?? null);
    }

    #[DataProvider('drivers')]
    public function test_composite_key_and_table_without_key(DatabaseDriver $driver): void
    {
        $this->people($driver);
        $this->writer->getSchemaBuilder()->create('memberships', function (Blueprint $table) {
            $table->integer('team');
            $table->integer('member');
            $table->primary(['team', 'member']);
        });
        $this->writer->getSchemaBuilder()->create('log_lines', function (Blueprint $table) {
            $table->string('line');
        });
        foreach ([[1, 1], [1, 2], [2, 1], [2, 3], [3, 1]] as [$team, $member]) {
            $this->writer->table('memberships')->insert(['team' => $team, 'member' => $member]);
        }
        $this->writer->table('log_lines')->insert([['line' => 'a'], ['line' => 'b'], ['line' => 'c']]);

        $first = $this->page(new GridQuery(limit: 3), 'memberships');
        $rest = $this->page(new GridQuery(after: $first['next']['after'] ?? null, limit: 3), 'memberships');
        $this->assertSame([['1', '1'], ['1', '2'], ['2', '1']], $first['keys']);
        $this->assertSame([['2', '3'], ['3', '1']], $rest['keys']);

        $plain = $this->page(new GridQuery(limit: 2), 'log_lines');
        $this->assertSame(['offset' => 2], $plain['next']);
        $this->assertSame([], $plain['primaryKey']);
    }

    #[DataProvider('drivers')]
    public function test_foreign_keys_have_labels_and_point_both_ways(DatabaseDriver $driver): void
    {
        $this->people($driver);
        $this->writer->getSchemaBuilder()->create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people');
            $table->decimal('amount', 8, 2);
        });
        $this->writer->table('invoices')->insert([['person_id' => 3, 'amount' => 10], ['person_id' => 3, 'amount' => 20], ['person_id' => 4, 'amount' => 5]]);

        $page = $this->page(new GridQuery, 'invoices');
        $this->assertSame(['table' => 'people', 'column' => 'id'], $page['columns'][1]['references']);
        $this->assertSame('Jürg Müller', $page['labels']['person_id']['3'] ?? null);

        $person = app(RowDetail::class)->describe($this->connection, $this->schema, 'people', ['3']);
        $this->assertNotNull($person);
        $this->assertSame([['name' => $person['incoming'][0]['name'], 'table' => 'invoices', 'columns' => ['person_id'], 'values' => ['3'], 'count' => 2]], $person['incoming']);

        $invoice = app(RowDetail::class)->describe($this->connection, $this->schema, 'invoices', ['1']);
        $this->assertSame('Jürg Müller', $invoice['outgoing'][0]['label'] ?? null);
        $this->assertNull(app(RowDetail::class)->describe($this->connection, $this->schema, 'invoices', ['999']));
    }

    /**
     * Zehn Personen mit Umlauten, NULL, Werten mit % und _ sowie Binärdaten und langem Text.
     */
    private function people(DatabaseDriver $driver): void
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $this->writer = app(ConnectionFactory::class)->make($settings);
        $this->connection = $this->savedConnection($settings);
        $this->schema = $this->sampleSchemaName($driver);

        $this->writer->getSchemaBuilder()->create('people', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('city')->nullable();
            $table->integer('score')->nullable();
            $table->boolean('active')->default(false);
            $table->binary('photo')->nullable();
            $table->text('notes')->nullable();
        });

        $rows = [
            ['Anna_Muster', 'Zürich', 40], ['Beat Keller', 'Bern', 55], ['Jürg Müller', 'Zürich', 91], ['Léa Dubois', 'Genève', 72],
            ['100% Bio AG', 'Basel', 12], ['Carla Rossi', null, 88], ['Daniel Weber', 'Basel', 30], ['Eva Brunner', 'Zürich', null],
            ['Fabian Meier', null, 65], ['Gabi Huber', 'zug', 70],
        ];
        foreach ($rows as $index => [$name, $city, $score]) {
            $this->writer->table('people')->insert([
                'name' => $name, 'city' => $city, 'score' => $score, 'active' => $index === 0,
                'notes' => $index === 0 ? str_repeat('ä', 1500) : null,
            ]);
        }

        // Binärdaten als Literal: als Text gebunden schneidet PostgreSQL am Nullbyte ab.
        $this->writer->statement('update people set photo = '.($driver === DatabaseDriver::Postgres ? "decode('00ff10ab', 'hex')" : "x'00ff10ab'").' where id = 1');
    }

    private function analyze(DatabaseDriver $driver): void
    {
        match ($driver) {
            DatabaseDriver::Postgres, DatabaseDriver::Sqlite => $this->writer->statement('analyze'),
            default => $this->writer->statement('analyze table people'),
        };
    }

    private function filter(string $column, FilterOperator $operator, ?string $value = null): GridQuery
    {
        return new GridQuery(filters: [['column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => 'and']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function page(GridQuery $query, string $table = 'people'): array
    {
        $page = app(TableData::class)->page($this->connection, $this->schema, $table, $query);
        $this->assertNotNull($page);

        return $page;
    }

    /**
     * @return list<mixed>
     */
    private function names(GridQuery $query, string $column = 'name'): array
    {
        $page = $this->page($query);
        $index = array_search($column, array_column($page['columns'], 'name'), true);

        return array_map(fn (array $row): mixed => $row[$index], $page['rows']);
    }
}
