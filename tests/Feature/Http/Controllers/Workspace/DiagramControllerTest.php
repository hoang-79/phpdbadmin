<?php

namespace Tests\Feature\Http\Controllers\Workspace;

use App\Connections\ConnectionFactory;
use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Beziehungsdiagramm (S-07): je Treiber Tabellen mit Schlüsseln und die Fremdschlüssel aus dem Katalog, Sichten nicht;
 * grosse Schemas zeigen eine Auswahl; der SVG-Export liest Namen neu aus dem Katalog und übernimmt die Positionen.
 */
#[Group('target-databases')]
class DiagramControllerTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/diagramm-'.getmypid());
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
    public function test_tables_keys_and_relations_come_from_the_catalog(DatabaseDriver $driver): void
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $this->createSampleSchema(app(ConnectionFactory::class)->make($settings), $driver);
        $connection = $this->savedConnection($settings, $this->user);
        $schema = $this->sampleSchemaName($driver);

        $diagram = $this->actingAs($this->user)->get(route('workspace.diagram', [$connection, $schema]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/Diagram')
                ->where('selection', ['customers', 'orders'])
                ->where('catalog.0.name', 'customers')
                ->where('catalog.0.relations', 1)
                ->where('catalog.0.neighbours', ['orders']))
            ->inertiaProps('diagram');

        $tables = array_column($diagram['tables'], null, 'name');
        $this->assertSame(['customers', 'orders'], array_keys($tables), 'Sichten gehören nicht ins Diagramm.');
        $this->assertSame([['id', true, false]], array_values(array_map(
            fn (array $column): array => [$column['name'], $column['primary'], $column['foreign']],
            array_filter($tables['customers']['columns'], fn (array $column): bool => $column['primary'] || $column['foreign']),
        )));
        $this->assertSame(['customer_id'], array_column(array_filter($tables['orders']['columns'], fn (array $column): bool => $column['foreign']), 'name'));
        $this->assertCount(1, $diagram['relations']);
        $this->assertSame(['orders', ['customer_id'], 'customers', ['id']], [
            $diagram['relations'][0]['table'], $diagram['relations'][0]['columns'], $diagram['relations'][0]['foreignTable'], $diagram['relations'][0]['foreignColumns'],
        ]);
        $this->assertLessThan($tables['orders']['x'], $tables['customers']['x'], 'Die verwiesene Tabelle steht links.');
    }

    public function test_large_schemas_show_the_most_connected_tables_first_and_follow_the_choice(): void
    {
        $connection = $this->sqliteWith(function ($target): void {
            $target->statement('create table zentrale (id integer primary key)');

            foreach (range(1, 64) as $index) {
                $target->statement(sprintf('create table t%02d (id integer primary key, zentrale_id integer references zentrale (id))', $index));
            }
        });

        $this->actingAs($this->user)->get(route('workspace.diagram', [$connection, 'main']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('catalog', 65)
                ->has('selection', 40)
                ->where('selection', fn ($selection): bool => collect($selection)->contains('zentrale'))
                ->has('diagram.tables', 40));

        $this->get(route('workspace.diagram', [$connection, 'main', 't' => ['t07', 'zentrale', 'gibt_es_nicht']]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selection', ['t07', 'zentrale'])
                ->has('diagram.relations', 1));
    }

    public function test_svg_download_reads_names_from_the_catalog_and_uses_the_positions(): void
    {
        $connection = $this->sqliteWith(function ($target): void {
            $target->statement('create table "<b>kunden</b>" (id integer primary key)');
            $target->statement('create table bestellungen (id integer primary key, kunde_id integer references "<b>kunden</b>" (id))');
        });

        $response = $this->actingAs($this->user)->postJson(route('workspace.diagram.svg', [$connection, 'main']), [
            'tables' => ['<b>kunden</b>', 'bestellungen', 'erfunden'],
            'positions' => [['name' => 'bestellungen', 'x' => 0, 'y' => 0], ['name' => '<b>kunden</b>', 'x' => 300, 'y' => 40]],
        ])->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringStartsWith('attachment; filename=main-beziehungen.svg', (string) $response->headers->get('Content-Disposition'));
        $svg = (string) $response->getContent();
        $this->assertNotFalse(simplexml_load_string($svg));
        $this->assertStringContainsString('&lt;b&gt;kunden&lt;/b&gt;', $svg);
        $this->assertStringNotContainsString('<b>', $svg);
        $this->assertStringNotContainsString('erfunden', $svg);
        $this->assertStringContainsString('<rect class="box" x="324" y="64"', $svg);
        $this->assertStringContainsString('<title>main — Beziehungsdiagramm</title>', $svg);
        $this->assertSame(1, substr_count($svg, 'class="link"'));
    }

    public function test_svg_rejects_positions_out_of_range(): void
    {
        $connection = $this->sqliteWith(fn ($target) => $target->statement('create table a (id integer primary key)'));

        $this->actingAs($this->user)->postJson(route('workspace.diagram.svg', [$connection, 'main']), [
            'tables' => ['a'],
            'positions' => [['name' => 'a', 'x' => 1e9, 'y' => 0]],
        ])->assertJsonValidationErrors('positions.0.x');
    }

    public function test_unknown_schema_and_foreign_connection_are_404(): void
    {
        $connection = $this->sqliteWith(fn ($target) => null);
        $foreign = $this->sqliteWith(fn ($target) => null, User::factory()->create());

        $this->actingAs($this->user)->get(route('workspace.diagram', [$connection, 'aussen']))->assertNotFound();
        $this->get(route('workspace.diagram', [$foreign, 'main']))->assertNotFound();
        $this->postJson(route('workspace.diagram.svg', [$foreign, 'main']), ['tables' => ['a']])->assertNotFound();
    }

    /**
     * @param  callable(Connection): mixed  $prepare
     */
    private function sqliteWith(callable $prepare, ?User $owner = null): DatabaseConnection
    {
        $file = $this->folder.'/diagramm-'.uniqid().'.sqlite';
        touch($file);
        $connection = DatabaseConnection::factory()->sqlite($file)->for($owner ?? $this->user, 'owner')->create(['read_only' => false]);
        $prepare(app(ConnectionFactory::class)->make(ConnectionSettings::fromConnection($connection, null)));

        return $connection;
    }
}
