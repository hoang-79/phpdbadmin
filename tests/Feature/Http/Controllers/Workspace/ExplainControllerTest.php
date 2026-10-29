<?php

namespace Tests\Feature\Http\Controllers\Workspace;

use App\Connections\ConnectionFactory;
use App\Connections\ConnectionSettings;
use App\Enums\ConnectionPermission;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * EXPLAIN als Baum mit Hinweisen (Q-06), Durchlauf je Treiber: der Baum hat eine Wurzel, eine Tabelle ohne passenden
 * Index bringt den Hinweis «vollständiger Durchlauf», eine mit Index nicht; EXPLAIN einer Änderung führt nichts aus,
 * ANALYZE nur für lesende Anweisungen; gefährliche Anweisungen werden abgewiesen.
 */
#[Group('target-databases')]
class ExplainControllerTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/erklaeren-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'phpdbadmin.connections.sqlite_paths' => [$this->folder],
        ]);
        $this->user = User::factory()->create();
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
    public function test_plan_has_a_root_and_flags_a_full_scan_without_a_matching_index(DatabaseDriver $driver): void
    {
        [$connection, $schema] = $this->prepared($driver);
        $sql = "-- Bericht\nselect 1;\nselect * from e_ohne where wert = 5;\nselect * from e_mit where wert = 5";

        $plain = $this->explain($connection, $schema, $sql, strpos($sql, 'e_ohne') + 2)->assertOk()->json();

        $this->assertSame('select * from e_ohne where wert = 5', $plain['sql']);
        $this->assertFalse($plain['analyzed']);
        $this->assertSame($driver !== DatabaseDriver::Sqlite, $plain['analyzable']);
        $this->assertNotSame('', $plain['plan']['operation']);
        $this->assertIsInt($plain['plan']['id']);
        $this->assertNotSame('', $plain['raw']);
        $scans = array_values(array_filter($plain['hints'], fn (array $hint): bool => $hint['kind'] === 'full_scan'));
        $this->assertCount(1, $scans, 'Hinweis «vollständiger Durchlauf» für e_ohne');
        $this->assertStringContainsString('«e_ohne»', $scans[0]['message']);
        $this->assertContains($scans[0]['node'], $this->ids($plain['plan']), 'Der Hinweis zeigt auf einen Knoten im Baum.');

        $indexed = $this->explain($connection, $schema, $sql, strlen($sql))->assertOk()->json();
        $this->assertSame([], array_values(array_filter($indexed['hints'], fn (array $hint): bool => $hint['kind'] === 'full_scan')));
    }

    #[DataProvider('drivers')]
    public function test_explaining_a_change_runs_nothing(DatabaseDriver $driver): void
    {
        [$connection, $schema, $target] = $this->prepared($driver);

        $this->explain($connection, $schema, 'delete from e_ohne where wert < 1000')->assertOk()->assertJsonPath('analyzed', false)->assertJsonPath('analyzable', false);
        $this->explain($connection, $schema, 'update e_ohne set notiz = 1 where wert = 5')->assertOk();

        $this->assertSame(2000, (int) $target->table('e_ohne')->count());
        $this->assertSame(0, (int) $target->table('e_ohne')->whereNotNull('notiz')->count());
    }

    /**
     * @return array<string, array{0: DatabaseDriver}>
     */
    public static function analyzingDrivers(): array
    {
        return self::serverDrivers();
    }

    #[DataProvider('analyzingDrivers')]
    public function test_analyze_measures_reading_statements(DatabaseDriver $driver): void
    {
        [$connection, $schema] = $this->prepared($driver);

        $analyzed = $this->explain($connection, $schema, 'select count(*) from e_ohne where wert > 10', analyze: true)->assertOk()->json();

        $this->assertTrue($analyzed['analyzed']);
        $this->assertNotSame([], array_filter($this->nodes($analyzed['plan']), fn (array $node): bool => $node['actualRows'] !== null));
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: string}>
     */
    public static function rejected(): array
    {
        return [
            'ANALYZE einer Änderung' => ['delete from e_ohne where wert = 1', true, 'ANALYZE führt die Anweisung wirklich aus — das gibt es nur für lesende Anweisungen.'],
            'DROP' => ['drop table e_ohne', false, 'Erklären lassen sich SELECT, WITH, TABLE und VALUES sowie INSERT, UPDATE und DELETE.'],
            'DELETE ohne WHERE' => ['delete from e_ohne', false, 'Gefährliche Anweisung — phpdbadmin erklärt sie nicht: DELETE ohne WHERE löscht alle Zeilen.'],
            'schon mit EXPLAIN' => ['explain select 1', false, 'Bitte ohne EXPLAIN davor — phpdbadmin setzt es selbst.'],
            'SELECT INTO' => ['select * into neu from e_ohne', false, 'Erklären lassen sich SELECT, WITH, TABLE und VALUES sowie INSERT, UPDATE und DELETE.'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_statements_that_must_not_be_explained_are_rejected(string $sql, bool $analyze, string $message): void
    {
        [$connection, $schema, $target] = $this->prepared(DatabaseDriver::Sqlite);

        $this->explain($connection, $schema, $sql, analyze: $analyze)
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'explain')
            ->assertJsonPath('message', $message);

        $this->assertSame(2000, (int) $target->table('e_ohne')->count());
    }

    public function test_sqlite_knows_no_analyze_and_server_errors_come_back_as_message(): void
    {
        [$connection, $schema] = $this->prepared(DatabaseDriver::Sqlite);

        $this->explain($connection, $schema, 'select * from e_ohne', analyze: true)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'SQLite misst keine Laufzeit je Schritt — es gibt nur den Plan.');
        $this->explain($connection, $schema, 'select * from gibt_es_nicht')
            ->assertUnprocessable()
            ->assertJsonPath('fromServer', true)
            ->assertJsonPath('message', fn (string $message): bool => str_starts_with($message, 'Der Server konnte die Anweisung nicht erklären: ') && str_contains($message, 'gibt_es_nicht'));
    }

    /**
     * @return array<string, array{0: DatabaseDriver}>
     */
    public static function mysqlDrivers(): array
    {
        return ['MySQL' => [DatabaseDriver::MySql], 'MariaDB' => [DatabaseDriver::MariaDb]];
    }

    #[DataProvider('mysqlDrivers')]
    public function test_mysql_explains_changes_only_with_write_permission_on_a_writable_connection(DatabaseDriver $driver): void
    {
        [, $schema, , $settings] = $this->prepared($driver);
        $readOnly = $this->savedConnection($settings, $this->user, ['read_only' => true]);
        $shared = $this->savedConnection($settings, User::factory()->create(), ['shared' => true]);
        $shared->members()->attach($this->user, ['permission' => ConnectionPermission::Read->value]);
        $message = 'EXPLAIN von INSERT, UPDATE und DELETE prüfen MySQL und MariaDB wie eine Änderung — das braucht das Recht «schreiben» und eine beschreibbare Verbindung.';

        foreach ([$readOnly, $shared] as $connection) {
            $this->explain($connection, $schema, 'delete from e_ohne where wert = 1')->assertUnprocessable()->assertJsonPath('message', $message);
            $this->explain($connection, $schema, 'select * from e_ohne where wert = 1')->assertOk();
        }
    }

    #[DataProvider('mysqlDrivers')]
    public function test_mysql_explain_of_a_change_rolls_back_what_functions_write_while_planning(DatabaseDriver $driver): void
    {
        [$connection, $schema, $target] = $this->prepared($driver);
        $target->statement('create table e_spur (n integer)');
        // MariaDB wertet die Funktion beim Planen aus — und schreibt dabei.
        $target->unprepared('create function e_merk() returns int deterministic modifies sql data begin insert into e_spur values (1); return 5; end');

        $this->explain($connection, $schema, 'delete from e_ohne where id = e_merk()')->assertOk();

        $this->assertSame(0, (int) $target->table('e_spur')->count());
        $this->assertSame(2000, (int) $target->table('e_ohne')->count());
    }

    public function test_foreign_connection_is_404(): void
    {
        [$connection] = $this->prepared(DatabaseDriver::Sqlite, User::factory()->create());

        $this->explain($connection, 'main', 'select 1')->assertNotFound();
    }

    private function explain(DatabaseConnection $connection, ?string $schema, string $sql, ?int $cursor = null, bool $analyze = false): TestResponse
    {
        return $this->actingAs($this->user)->postJson(route('workspace.sql.explain', $connection), [
            'sql' => $sql,
            'cursor' => $cursor ?? strlen($sql),
            'schema' => $schema,
            'analyze' => $analyze,
        ]);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private function nodes(array $node): array
    {
        return [$node, ...array_merge([], ...array_map(fn (array $child): array => $this->nodes($child), $node['children']))];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<int>
     */
    private function ids(array $plan): array
    {
        return array_column($this->nodes($plan), 'id');
    }

    /**
     * Zwei Tabellen mit je 2'000 Zeilen, eine ohne und eine mit Index auf «wert», Statistik aktuell.
     *
     * @return array{0: DatabaseConnection, 1: string, 2: Connection, 3: ConnectionSettings}
     */
    private function prepared(DatabaseDriver $driver, ?User $owner = null): array
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $target = app(ConnectionFactory::class)->make($settings);

        foreach (['e_ohne', 'e_mit'] as $table) {
            $target->statement('create table '.$table.' (id integer primary key, wert integer not null, notiz varchar(20) null)');

            foreach (array_chunk(range(1, 2000), 500) as $chunk) {
                $target->table($table)->insert(array_map(fn (int $value): array => ['id' => $value, 'wert' => $value], $chunk));
            }
        }

        $target->statement('create index e_mit_wert on e_mit (wert)');
        $target->statement(match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => 'analyze table e_ohne, e_mit',
            default => 'analyze',
        });

        return [$this->savedConnection($settings, $owner ?? $this->user), $this->sampleSchemaName($driver), $target, $settings];
    }
}
