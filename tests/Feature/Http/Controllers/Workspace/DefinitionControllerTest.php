<?php

namespace Tests\Feature\Http\Controllers\Workspace;

use App\Connections\ConnectionFactory;
use App\Connections\ConnectionSettings;
use App\Enums\ConnectionEnvironment;
use App\Enums\ConnectionPermission;
use App\Enums\DatabaseDriver;
use App\Enums\ObjectKind;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Catalog\Catalog;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Objekte neben den Tabellen (S-06): je Objektart und Treiber anlegen, im Katalog finden, Definition lesen, ändern
 * und löschen — mit Vorschau, die nichts ausführt, genau einem Eintrag im Protokoll je Aktion, 403 ohne «schreiben»
 * und auf schreibgeschützten Verbindungen. Dazu: Fehler des Servers als Meldung, Rückrollen bzw. Wiederherstellen,
 * Definitionen, die nicht zur Seite passen.
 */
#[Group('target-databases')]
class DefinitionControllerTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/objekte-'.getmypid());
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
     * Treiber, Art, Name, Vorbereitung, CREATE, Stück der Definition, Suchen → Ersetzen in der Vorlage zum Ändern,
     * Stück der Definition danach.
     *
     * @return array<string, array{0: DatabaseDriver, 1: string, 2: string, 3: list<string>, 4: string, 5: string, 6: string, 7: string, 8: string}>
     */
    public static function lifecycles(): array
    {
        $mysql = fn (DatabaseDriver $driver, string $label): array => [
            $label.' Sicht' => [$driver, 'view', 'o_sicht', [], 'create view o_sicht as select 4711 as wert', '4711', '4711', '4712', '4712'],
            $label.' Funktion' => [$driver, 'function', 'o_funktion', [], 'create function o_funktion(x int) returns int deterministic no sql return x + 4711', '4711', '4711', '4712', '4712'],
            $label.' Prozedur' => [$driver, 'procedure', 'o_prozedur', [], "create procedure o_prozedur(in x int)\nbegin\n    select x + 4711;\nend", '4711', '4711', '4712', '4712'],
            $label.' Trigger' => [$driver, 'trigger', 'o_trigger', [], "create trigger o_trigger before insert on o_tabelle for each row set new.notiz = 'v4711'", 'v4711', 'v4711', 'v4712', 'v4712'],
            $label.' Ereignis' => [$driver, 'event', 'o_ereignis', [], 'create event o_ereignis on schedule every 1 day do delete from o_tabelle where id = 4711', '4711', '4711', '4712', '4712'],
        ];

        return [
            ...$mysql(DatabaseDriver::MySql, 'MySQL'),
            ...$mysql(DatabaseDriver::MariaDb, 'MariaDB'),
            'PostgreSQL Sicht' => [DatabaseDriver::Postgres, 'view', 'o_sicht', [], 'create view o_sicht as select 4711 as wert', '4711', '4711', '4712', '4712'],
            'PostgreSQL Funktion' => [DatabaseDriver::Postgres, 'function', 'o_funktion', [], 'create function o_funktion(x integer) returns integer language sql immutable as $$ select x + 4711 $$', '4711', '4711', '4712', '4712'],
            'PostgreSQL Prozedur' => [DatabaseDriver::Postgres, 'procedure', 'o_prozedur', [], 'create procedure o_prozedur(x integer) language plpgsql as $$ begin perform x + 4711; end $$', '4711', '4711', '4712', '4712'],
            'PostgreSQL Trigger' => [DatabaseDriver::Postgres, 'trigger', 'o_trigger', ["create function o_trigger_fn() returns trigger language plpgsql as \$\$ begin new.notiz := 'v4711'; return new; end \$\$"], 'create trigger o_trigger before insert on o_tabelle for each row execute function o_trigger_fn()', 'BEFORE INSERT ON', 'BEFORE INSERT', 'BEFORE INSERT OR UPDATE', 'INSERT OR UPDATE'],
            'PostgreSQL Sequenz' => [DatabaseDriver::Postgres, 'sequence', 'o_sequenz', [], 'create sequence o_sequenz increment by 4711', 'INCREMENT BY 4711', 'INCREMENT BY 4711', 'INCREMENT BY 4712', 'INCREMENT BY 4712'],
            'PostgreSQL Aufzählung' => [DatabaseDriver::Postgres, 'type', 'o_status', [], "create type o_status as enum ('v4711')", "'v4711'", 'new_value', 'v4712', "'v4711', 'v4712'"],
            'PostgreSQL Domäne' => [DatabaseDriver::Postgres, 'type', 'o_betrag', [], 'create domain o_betrag as integer check (value > 4711)', '4711', 'VALUE IS NOT NULL', 'VALUE < 4712', '4712'],
            'PostgreSQL zusammengesetzter Typ' => [DatabaseDriver::Postgres, 'type', 'o_paar', [], 'create type o_paar as (links integer, rechts text)', '"rechts" text', 'new_attribute', 'mitte', '"mitte" text'],
            'SQLite Sicht' => [DatabaseDriver::Sqlite, 'view', 'o_sicht', [], 'create view o_sicht as select 4711 as wert', '4711', '4711', '4712', '4712'],
            'SQLite Trigger' => [DatabaseDriver::Sqlite, 'trigger', 'o_trigger', [], "create trigger o_trigger after insert on o_tabelle begin update o_tabelle set notiz = 'v4711' where id = new.id; end", 'v4711', 'v4711', 'v4712', 'v4712'],
        ];
    }

    /**
     * @param  list<string>  $setup
     */
    #[DataProvider('lifecycles')]
    public function test_each_kind_is_listed_shown_changed_and_dropped(DatabaseDriver $driver, string $kind, string $name, array $setup, string $create, string $initial, string $search, string $replace, string $expected): void
    {
        [$connection, $schema, $target, $settings] = $this->prepared($driver);

        foreach ($setup as $statement) {
            $target->unprepared($statement);
        }

        $this->actingAs($this->user)->from(route('workspace.definition.create', [$connection, $schema, $kind]))
            ->post(route('workspace.definition.apply', [$connection, $schema, $kind]), ['action' => 'create', 'definition' => $create])
            ->assertSessionHasNoErrors()
            ->assertRedirectContains('/x/'.$kind.'/'.$name);

        $groups = array_column(app(Catalog::class)->groups($connection, $schema), null, 'key');
        $this->assertContains($name, array_column($groups[ObjectKind::from($kind)->group()]['items'], 'name'));

        $definition = $this->get(route('workspace.definition', [$connection, $schema, $kind, $name]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/Definition')
                ->where('definition.name', $name)
                ->where('definition.kind', $kind)
                ->where('changes.canChange', true))
            ->inertiaProps('definition');
        $this->assertStringContainsString($initial, $definition['ddl']);
        $this->assertIsString($definition['editable']);

        $changed = str_replace($search, $replace, $definition['editable']);
        $this->assertNotSame($definition['editable'], $changed);
        $alter = ['action' => 'alter', 'name' => $name, 'qualifier' => $definition['qualifier'], 'definition' => $changed];

        $this->postJson(route('workspace.definition.preview', [$connection, $schema, $kind]), $alter)
            ->assertOk()
            ->assertJsonPath('target', $schema.'.'.$name);
        $this->assertSame(1, AuditEntry::query()->count(), 'Die Vorschau schreibt nichts ins Protokoll.');

        $this->assertChangesAreBlocked($settings, $schema, $kind, $alter);

        $this->actingAs($this->user)->post(route('workspace.definition.apply', [$connection, $schema, $kind]), $alter)
            ->assertSessionHasNoErrors()
            ->assertRedirectContains('/x/'.$kind.'/'.$name);
        $this->assertStringContainsString($expected, $this->get(route('workspace.definition', [$connection, $schema, $kind, $name]))->inertiaProps('definition.ddl'));

        $this->post(route('workspace.definition.apply', [$connection, $schema, $kind]), ['action' => 'drop', 'name' => $name, 'qualifier' => $definition['qualifier']])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workspace.schema', [$connection, $schema]));
        $this->get(route('workspace.definition', [$connection, $schema, $kind, $name]))->assertNotFound();

        $this->assertSame(['object.create', 'object.alter', 'object.drop'], AuditEntry::query()->orderBy('id')->pluck('action')->all());
        $this->assertSame([$schema.'.'.$name], AuditEntry::query()->distinct()->pluck('target')->all());
        $this->assertSame(['ok'], AuditEntry::query()->get()->map(fn (AuditEntry $entry): mixed => $entry->context['outcome'] ?? null)->unique()->values()->all());
    }

    public function test_postgres_rejected_replacement_rolls_back_and_is_recorded_once(): void
    {
        [$connection] = $this->prepared(DatabaseDriver::Postgres);
        $this->createObject($connection, 'public', 'view', 'create view o_sicht as select 4711 as wert');

        // PostgreSQL ersetzt keine Sicht, deren Spalten wegfallen.
        $this->post(route('workspace.definition.apply', [$connection, 'public', 'view']), [
            'action' => 'alter', 'name' => 'o_sicht', 'definition' => "create or replace view o_sicht as select 'neu' as anders",
        ])->assertSessionHasErrors('change');
        $this->assertStringContainsString('cannot change name of view column', (string) session('errors')?->first('change'));

        $this->assertStringContainsString('4711', $this->get(route('workspace.definition', [$connection, 'public', 'view', 'o_sicht']))->inertiaProps('definition.ddl'));
        $entry = AuditEntry::query()->where('action', 'object.alter')->sole();
        $this->assertSame('failed', $entry->context['outcome'] ?? null);
        $this->assertSame(0, $entry->context['executed'] ?? null);
    }

    public function test_postgres_without_or_replace_drops_and_creates_in_one_transaction(): void
    {
        [$connection] = $this->prepared(DatabaseDriver::Postgres);
        $this->createObject($connection, 'public', 'view', 'create view o_sicht as select 4711 as wert');
        $alter = ['action' => 'alter', 'name' => 'o_sicht', 'definition' => "create view o_sicht as select 'neu' as anders;"];

        $this->postJson(route('workspace.definition.preview', [$connection, 'public', 'view']), $alter)
            ->assertOk()
            ->assertJsonPath('statements', ['SET LOCAL search_path TO "public"', 'DROP VIEW "public"."o_sicht"', "create view o_sicht as select 'neu' as anders"])
            ->assertJsonPath('transactional', true);

        $this->post(route('workspace.definition.apply', [$connection, 'public', 'view']), $alter)->assertSessionHasNoErrors();

        $this->assertStringContainsString('anders', $this->get(route('workspace.definition', [$connection, 'public', 'view', 'o_sicht']))->inertiaProps('definition.ddl'));
    }

    /**
     * @return array<string, array{0: DatabaseDriver}>
     */
    public static function mysqlDrivers(): array
    {
        return ['MySQL' => [DatabaseDriver::MySql], 'MariaDB' => [DatabaseDriver::MariaDb]];
    }

    #[DataProvider('mysqlDrivers')]
    public function test_mysql_restores_the_previous_routine_when_the_new_one_fails(DatabaseDriver $driver): void
    {
        [$connection, $schema] = $this->prepared($driver);
        $this->createObject($connection, $schema, 'function', 'create function o_funktion(x int) returns int deterministic no sql return x + 4711');
        // Ohne OR REPLACE: löschen und neu anlegen, ohne Transaktion — das Neuanlegen scheitert am Syntaxfehler.
        $alter = ['action' => 'alter', 'name' => 'o_funktion', 'definition' => 'create function o_funktion(x int) returns int deterministic no sql return x +'];

        $this->postJson(route('workspace.definition.preview', [$connection, $schema, 'function']), $alter)
            ->assertOk()
            ->assertJsonPath('transactional', false)
            ->assertJsonPath('statements.1', 'DROP FUNCTION `'.$schema.'`.`o_funktion`');

        $this->post(route('workspace.definition.apply', [$connection, $schema, 'function']), $alter)
            ->assertSessionHasErrors('change');
        $this->assertStringEndsWith('— die bisherige Definition ist wiederhergestellt.', (string) session('errors')?->first('change'));

        $this->assertStringContainsString('4711', $this->get(route('workspace.definition', [$connection, $schema, 'function', 'o_funktion']))->inertiaProps('definition.ddl'));
        $entry = AuditEntry::query()->where('action', 'object.alter')->sole();
        $this->assertSame(['failed', true, 2], [$entry->context['outcome'] ?? null, $entry->context['restored'] ?? null, $entry->context['executed'] ?? null]);
    }

    public function test_postgres_tells_overloaded_routines_apart(): void
    {
        [$connection] = $this->prepared(DatabaseDriver::Postgres);
        $this->createObject($connection, 'public', 'function', 'create function o_doppelt(x integer) returns integer language sql as $$ select x * 2 $$');
        $this->createObject($connection, 'public', 'function', 'create function o_doppelt(x numeric) returns numeric language sql as $$ select x * 2 $$');

        $this->get(route('workspace.definition', [$connection, 'public', 'function', 'o_doppelt', 'qualifier' => 'x numeric']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('definition.qualifier', 'x numeric')
                ->where('definition.overloads', ['x integer', 'x numeric']));

        $this->post(route('workspace.definition.apply', [$connection, 'public', 'function']), ['action' => 'drop', 'name' => 'o_doppelt', 'qualifier' => 'x numeric'])
            ->assertSessionHasNoErrors();

        $this->get(route('workspace.definition', [$connection, 'public', 'function', 'o_doppelt']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('definition.overloads', ['x integer']));
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string, 2: string}>
     */
    public static function rejectedDefinitions(): array
    {
        return [
            'zwei Anweisungen' => [['action' => 'create', 'definition' => 'create view o_a as select 1; create view o_b as select 2'], 'definition', 'Bitte genau eine Anweisung — hier stehen 2.'],
            'nur ein Kommentar' => [['action' => 'create', 'definition' => '-- nichts'], 'definition', 'Da steht keine Anweisung — nur Kommentare oder nichts.'],
            'andere Art' => [['action' => 'create', 'definition' => 'create trigger o_t after insert on o_tabelle begin select 1; end'], 'definition', 'Die Anweisung muss mit CREATE oder ALTER beginnen und ein Objekt der Art «Sicht» betreffen.'],
            'Tabelle löschen' => [['action' => 'create', 'definition' => 'drop table o_tabelle'], 'definition', 'Die Anweisung muss mit CREATE oder ALTER beginnen und ein Objekt der Art «Sicht» betreffen.'],
            'anderes Schema' => [['action' => 'create', 'definition' => 'create view aussen.o_neu as select 1'], 'definition', 'Das Objekt muss im Schema «main» liegen.'],
            'temporär' => [['action' => 'create', 'definition' => 'create temp view o_neu as select 1'], 'definition', 'Temporäre Objekte verschwinden mit der Sitzung — legen Sie ein dauerhaftes an.'],
            'umbenannt' => [['action' => 'alter', 'name' => 'o_sicht', 'definition' => 'create view o_anders as select 1'], 'definition', 'Der Name muss «o_sicht» bleiben — für einen anderen Namen legen Sie ein neues Objekt an und löschen das alte.'],
            'gibt es nicht' => [['action' => 'drop', 'name' => 'o_fehlt'], 'name', 'Dieses Objekt gibt es nicht mehr — laden Sie die Seite neu.'],
            'Name sieht aus wie SQL' => [['action' => 'drop', 'name' => 'o_sicht"; drop table o_tabelle; --'], 'name', 'Dieses Objekt gibt es nicht mehr — laden Sie die Seite neu.'],
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('rejectedDefinitions')]
    public function test_definitions_that_do_not_fit_the_page_are_rejected_before_anything_runs(array $payload, string $field, string $message): void
    {
        [$connection, , $target] = $this->prepared(DatabaseDriver::Sqlite);
        $target->statement('create view o_sicht as select 4711 as wert');

        $this->actingAs($this->user)->postJson(route('workspace.definition.preview', [$connection, 'main', 'view']), $payload)
            ->assertJsonValidationErrors([$field => $message]);
        $this->post(route('workspace.definition.apply', [$connection, 'main', 'view']), $payload)
            ->assertSessionHasErrors([$field => $message]);

        $this->assertSame(['o_sicht'], array_column($target->getSchemaBuilder()->getViews(), 'name'));
        $this->assertContains('o_tabelle', array_column($target->getSchemaBuilder()->getTables(), 'name'));
        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_production_asks_for_the_connection_name_before_applying(): void
    {
        [$connection, , $target] = $this->prepared(DatabaseDriver::Sqlite, ['environment' => ConnectionEnvironment::Production, 'read_only' => false]);
        $target->statement('create view o_sicht as select 4711 as wert');
        $drop = ['action' => 'drop', 'name' => 'o_sicht'];

        $this->actingAs($this->user)->get(route('workspace.definition', [$connection, 'main', 'view', 'o_sicht']))
            ->assertInertia(fn (Assert $page) => $page->where('changes.confirmName', $connection->name));
        $this->postJson(route('workspace.definition.preview', [$connection, 'main', 'view']), $drop)
            ->assertOk()
            ->assertJsonPath('statements', ['DROP VIEW "main"."o_sicht"']);

        $this->post(route('workspace.definition.apply', [$connection, 'main', 'view']), $drop)
            ->assertSessionHasErrors(['confirmation' => 'Geben Sie zur Bestätigung den Namen der Verbindung ein.']);
        $this->post(route('workspace.definition.apply', [$connection, 'main', 'view']), [...$drop, 'confirmation' => 'falsch'])
            ->assertSessionHasErrors(['confirmation' => 'Der Name stimmt nicht mit dem Namen der Verbindung überein.']);
        $this->assertSame(0, AuditEntry::query()->count());

        $this->post(route('workspace.definition.apply', [$connection, 'main', 'view']), [...$drop, 'confirmation' => $connection->name])
            ->assertSessionHasNoErrors();
        $this->assertSame([], $target->getSchemaBuilder()->getViews());
    }

    public function test_temporary_write_unlock_allows_changes_on_a_read_only_connection(): void
    {
        [$connection, , $target] = $this->prepared(DatabaseDriver::Sqlite, ['read_only' => true]);
        $target->statement('create view o_sicht as select 4711 as wert');
        $drop = ['action' => 'drop', 'name' => 'o_sicht'];

        $this->actingAs($this->user)->post(route('workspace.definition.apply', [$connection, 'main', 'view']), $drop)->assertForbidden();

        $this->withSession(['schreibfreigabe.'.$connection->id => ['until' => now()->addMinutes(5)->toIso8601String(), 'reason' => 'Sicht aufräumen']])
            ->post(route('workspace.definition.apply', [$connection, 'main', 'view']), $drop)
            ->assertSessionHasNoErrors();

        $this->assertSame('object.drop', AuditEntry::query()->sole()->action);
    }

    public function test_page_says_why_changes_are_blocked(): void
    {
        [$connection, , $target, $settings] = $this->prepared(DatabaseDriver::Sqlite);
        $target->statement('create view o_sicht as select 4711 as wert');
        $readOnly = $this->savedConnection($settings, $this->user, ['read_only' => true]);
        $shared = $this->savedConnection($settings, User::factory()->create(), ['shared' => true]);
        $shared->members()->attach($this->user, ['permission' => ConnectionPermission::Read->value]);

        $this->actingAs($this->user)->get(route('workspace.definition', [$readOnly, 'main', 'view', 'o_sicht']))
            ->assertInertia(fn (Assert $page) => $page->where('changes.canChange', false)->where('changes.blocked', 'read_only'));
        $this->get(route('workspace.definition', [$shared, 'main', 'view', 'o_sicht']))
            ->assertInertia(fn (Assert $page) => $page->where('changes.canChange', false)->where('changes.blocked', 'right'));
        $this->get(route('workspace.definition', [$connection, 'main', 'view', 'o_sicht']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('changes.canChange', true)
                ->where('object.kind', 'view')
                ->where('current', ['kind' => 'view', 'name' => 'o_sicht']));
    }

    public function test_create_page_offers_a_template_per_kind(): void
    {
        [$connection] = $this->prepared(DatabaseDriver::Sqlite);

        $this->actingAs($this->user)->get(route('workspace.definition.create', [$connection, 'main', 'trigger']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/Definition')
                ->where('definition', null)
                ->where('kinds', ['view', 'trigger'])
                ->where('template', fn (string $template): bool => str_starts_with($template, 'CREATE TRIGGER new_trigger')));
    }

    public function test_unknown_kinds_objects_and_foreign_connections_are_404(): void
    {
        [$connection] = $this->prepared(DatabaseDriver::Sqlite);
        [$foreign] = $this->prepared(DatabaseDriver::Sqlite, owner: User::factory()->create());

        $this->actingAs($this->user)->get(route('workspace.definition', [$connection, 'main', 'view', 'o_fehlt']))->assertNotFound();
        $this->get(route('workspace.definition.create', [$connection, 'main', 'function']))->assertNotFound();
        $this->get('/c/'.$connection->id.'/s/main/x/table/o_tabelle')->assertNotFound();
        $this->get(route('workspace.definition.create', [$connection, 'aussen', 'view']))->assertNotFound();
        $this->get(route('workspace.definition.create', [$foreign, 'main', 'view']))->assertNotFound();
        $this->post(route('workspace.definition.apply', [$foreign, 'main', 'view']), ['action' => 'drop', 'name' => 'o_sicht'])->assertNotFound();
    }

    /**
     * Ohne «schreiben» (Mitglied mit Leserecht) und auf einer schreibgeschützten Verbindung: 403 für Vorschau und
     * Anwenden, nichts im Protokoll.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertChangesAreBlocked(ConnectionSettings $settings, string $schema, string $kind, array $payload): void
    {
        $before = AuditEntry::query()->count();
        $readOnly = $this->savedConnection($settings, $this->user, ['read_only' => true]);
        $shared = $this->savedConnection($settings, User::factory()->create(), ['shared' => true]);
        $reader = User::factory()->create();
        $shared->members()->attach($reader, ['permission' => ConnectionPermission::Read->value]);

        $this->actingAs($this->user)->postJson(route('workspace.definition.preview', [$readOnly, $schema, $kind]), $payload)->assertForbidden();
        $this->post(route('workspace.definition.apply', [$readOnly, $schema, $kind]), $payload)->assertForbidden();
        $this->actingAs($reader)->postJson(route('workspace.definition.preview', [$shared, $schema, $kind]), $payload)->assertForbidden();
        $this->post(route('workspace.definition.apply', [$shared, $schema, $kind]), $payload)->assertForbidden();

        $this->assertSame($before, AuditEntry::query()->count());
    }

    private function createObject(DatabaseConnection $connection, string $schema, string $kind, string $definition): void
    {
        $this->actingAs($this->user)->post(route('workspace.definition.apply', [$connection, $schema, $kind]), ['action' => 'create', 'definition' => $definition])
            ->assertSessionHasNoErrors();
    }

    /**
     * Leere Test-Datenbank mit einer Tabelle für Trigger und Ereignisse.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: DatabaseConnection, 1: string, 2: Connection, 3: ConnectionSettings}
     */
    private function prepared(DatabaseDriver $driver, array $attributes = [], ?User $owner = null): array
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $target = app(ConnectionFactory::class)->make($settings);
        $target->statement('create table o_tabelle (id integer primary key, notiz varchar(50))');

        return [$this->savedConnection($settings, $owner ?? $this->user, $attributes), $this->sampleSchemaName($driver), $target, $settings];
    }
}
