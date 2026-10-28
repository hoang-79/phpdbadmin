<?php

namespace Tests\Feature\Http\Controllers\Workspace\Server;

use App\Connections\ConnectionFactory;
use App\Connections\ConnectionSettings;
use App\Dialects\PlannedStatement;
use App\Enums\DatabaseDriver;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Datenbank-Benutzer, Rollen und Rechte (W-04) über die Oberfläche: immer zuerst die Vorschau (Passwort maskiert),
 * ausgeführt erst danach; jede Änderung genau ein Eintrag im Protokoll — nie mit Passwort. Konten heissen
 * «phpdbadmin_w04_…» und werden im Test wieder entfernt.
 */
#[Group('target-databases')]
class AccountControllerTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private const string PASSWORD = "W04-Geheim'; DROP USER root; --";

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/konten-'.getmypid());
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

    public function test_lists_accounts_with_privileges_and_marks_the_own_one(): void
    {
        $connection = $this->savedConnection($this->scratchSettings(DatabaseDriver::MariaDb, $this->folder), $this->user);

        $this->actingAs($this->user)->get(route('workspace.server.accounts', $connection))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/server/Accounts')
                ->where('hasHosts', true)
                ->where('levels.table', fn ($privileges): bool => in_array('SELECT', $privileges->all(), true))
                ->where('databases', [$this->targetTestDatabase()])
                ->where('accounts', function ($accounts): bool {
                    $own = array_values(array_filter($accounts->all(), fn (array $account): bool => $account['own']));

                    return count($own) === 1 && $own[0]['name'] === 'root' && $own[0]['grants'] !== []
                        && ! str_contains(implode("\n", array_merge(...array_column($accounts->all(), 'grants'))), 'IDENTIFIED');
                })
                ->missing('tables')
                ->reloadOnly('tables', fn (Assert $reload) => $reload->where('tables', [])));
    }

    public function test_tables_of_the_chosen_database_are_loaded_on_request(): void
    {
        $settings = $this->scratchSettings(DatabaseDriver::MariaDb, $this->folder);
        app(ConnectionFactory::class)->make($settings)->statement('create table w04_orders (id int primary key)');
        $connection = $this->savedConnection($settings, $this->user);

        $this->actingAs($this->user)->get(route('workspace.server.accounts', [$connection, 'tables_in' => $this->targetTestDatabase()]))
            ->assertInertia(fn (Assert $page) => $page
                ->reloadOnly('tables', fn (Assert $reload) => $reload->where('tables', ['w04_orders'])));
    }

    public function test_sqlite_has_no_accounts(): void
    {
        $file = $this->folder.'/konten.sqlite';
        (new PDO('sqlite:'.$file))->exec('create table notes (id integer primary key)');
        $connection = DatabaseConnection::factory()->for($this->user, 'owner')->sqlite($file)->create();

        $this->actingAs($this->user)->get(route('workspace.server.accounts', $connection))
            ->assertInertia(fn (Assert $page) => $page->where('accounts', null)->where('levels', []));
        $this->postJson(route('workspace.server.accounts.preview', $connection), ['action' => 'create', 'name' => 'x', 'password' => 'Geheim-1234'])
            ->assertJsonValidationErrors(['action' => 'SQLite kennt keine Konten und Rechte.']);
    }

    #[DataProvider('serverDrivers')]
    public function test_preview_shows_the_statement_with_masked_password_and_changes_nothing(DatabaseDriver $driver): void
    {
        $connection = $this->savedConnection($this->scratchSettings($driver, $this->folder), $this->user);

        $response = $this->actingAs($this->user)->postJson(route('workspace.server.accounts.preview', $connection), $this->createPayload($driver));

        $response->assertOk()
            ->assertJsonPath('statements.0', $driver === DatabaseDriver::Postgres
                ? 'CREATE ROLE "'.$this->accountName().'" LOGIN PASSWORD '.PlannedStatement::MASK
                : "CREATE USER '".$this->accountName()."'@'localhost' IDENTIFIED BY ".PlannedStatement::MASK);
        $this->assertStringNotContainsString('W04-Geheim', (string) $response->getContent());
        $this->assertFalse($this->accountExists($this->root($driver), $driver));
        $this->assertSame(0, AuditEntry::query()->count());
    }

    /**
     * Anlegen, erteilen, entziehen, löschen — je ein Eintrag im Protokoll, nirgends das Passwort.
     */
    #[DataProvider('serverDrivers')]
    public function test_account_lifecycle_records_one_entry_per_change_without_the_password(DatabaseDriver $driver): void
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $root = $this->root($driver);
        $root->statement('create table w04_orders (id int primary key)');
        $connection = $this->savedConnection($settings, $this->user);
        $account = ['name' => $this->accountName(), ...($driver === DatabaseDriver::Postgres ? [] : ['host' => 'localhost'])];
        $grant = [
            ...$account,
            'level' => 'table',
            'database' => $this->targetTestDatabase(),
            'schema' => $driver === DatabaseDriver::Postgres ? 'public' : null,
            'table' => 'w04_orders',
            'privileges' => ['SELECT', 'INSERT'],
        ];
        $this->actingAs($this->user)->from(route('workspace.server.accounts', $connection));

        try {
            $this->post(route('workspace.server.accounts.apply', $connection), $this->createPayload($driver))
                ->assertSessionHasNoErrors()
                ->assertInertiaFlash('toast.type', 'success');
            $this->assertTrue($this->accountExists($root, $driver));

            $this->post(route('workspace.server.accounts.apply', $connection), ['action' => 'grant', ...$grant])->assertSessionHasNoErrors();
            $this->assertTrue($this->canInsert($driver, $root));

            $this->post(route('workspace.server.accounts.apply', $connection), ['action' => 'revoke', ...$grant])->assertSessionHasNoErrors();
            $this->assertFalse($this->canInsert($driver, $root));

            $this->post(route('workspace.server.accounts.apply', $connection), ['action' => 'drop', ...$account])->assertSessionHasNoErrors();
            $this->assertFalse($this->accountExists($root, $driver));
        } finally {
            $this->dropAccount($root, $driver);
        }

        $this->assertSame(
            ['server.user.create', 'server.grant', 'server.revoke', 'server.user.drop'],
            AuditEntry::query()->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame(['ok'], AuditEntry::query()->get()->pluck('context.outcome')->unique()->values()->all());
        $this->assertStringContainsString(PlannedStatement::MASK, (string) AuditEntry::query()->where('action', 'server.user.create')->value('sql'));
        $this->assertStringNotContainsString('W04-Geheim', AuditEntry::query()->get()->toJson());
        $this->assertStringNotContainsString('W04-Geheim', (string) json_encode(session()->all()));
    }

    public function test_server_error_is_shown_without_the_password_and_recorded_as_failed(): void
    {
        $connection = $this->savedConnection($this->scratchSettings(DatabaseDriver::MariaDb, $this->folder), $this->user);
        $root = $this->root(DatabaseDriver::MariaDb);
        $root->statement("create user '".$this->accountName()."'@'localhost' identified by 'Vorher-1234'");

        try {
            $this->actingAs($this->user)->from(route('workspace.server.accounts', $connection))
                ->post(route('workspace.server.accounts.apply', $connection), $this->createPayload(DatabaseDriver::MariaDb))
                ->assertSessionHasErrors('server');
        } finally {
            $this->dropAccount($root, DatabaseDriver::MariaDb);
        }

        $this->assertStringContainsString('1396', (string) session('errors')?->first('server'));
        $this->assertStringNotContainsString('W04-Geheim', (string) json_encode(session()->all()));
        $entry = AuditEntry::query()->sole();
        $this->assertSame(['server.user.create', 'failed'], [$entry->action, $entry->context['outcome'] ?? null]);
        $this->assertStringNotContainsString('W04-Geheim', $entry->toJson());
    }

    public function test_dropping_the_own_account_is_refused(): void
    {
        $connection = $this->savedConnection($this->scratchSettings(DatabaseDriver::MariaDb, $this->folder), $this->user);

        $this->actingAs($this->user)->postJson(route('workspace.server.accounts.preview', $connection), ['action' => 'drop', 'name' => 'root', 'host' => 'localhost'])
            ->assertJsonValidationErrors(['name' => 'Mit diesem Konto ist phpdbadmin verbunden — es lässt sich hier nicht löschen.']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function sqlLookingInput(): array
    {
        $grant = ['action' => 'grant', 'name' => 'phpdbadmin_w04_x', 'host' => '%', 'level' => 'table', 'privileges' => ['SELECT']];

        return [
            'Kontoname' => [['action' => 'create', 'name' => "x'; DROP USER root; --", 'host' => '%', 'password' => 'Geheim-1234'], 'name'],
            'Host' => [['action' => 'create', 'name' => 'phpdbadmin_w04_x', 'host' => "%' IDENTIFIED BY 'x", 'password' => 'Geheim-1234'], 'host'],
            'Recht' => [[...$grant, 'database' => 'phpdbadmin_test_server', 'table' => 'x', 'privileges' => ['SELECT ON *.* TO root; --']], 'privileges.0'],
            'Recht ausserhalb der Liste' => [[...$grant, 'level' => 'server', 'privileges' => ['FILE']], 'privileges.0'],
            'Datenbank' => [[...$grant, 'level' => 'database', 'database' => 'x`.* TO root; --'], 'database'],
            'Tabelle' => [[...$grant, 'database' => 'phpdbadmin_test_server', 'table' => '*'], 'table'],
            'Ebene' => [[...$grant, 'level' => 'schema'], 'level'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('sqlLookingInput')]
    public function test_input_that_looks_like_sql_is_rejected_before_any_statement(array $payload, string $field): void
    {
        $connection = $this->savedConnection($this->scratchSettings(DatabaseDriver::MariaDb, $this->folder), $this->user);

        $this->actingAs($this->user)->postJson(route('workspace.server.accounts.preview', $connection), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
        $this->actingAs($this->user)->from(route('workspace.server.accounts', $connection))
            ->post(route('workspace.server.accounts.apply', $connection), $payload)
            ->assertSessionHasErrors($field);

        $this->assertSame(0, AuditEntry::query()->count());
    }

    private function accountName(): string
    {
        return 'phpdbadmin_w04_'.getmypid();
    }

    /**
     * @return array<string, string>
     */
    private function createPayload(DatabaseDriver $driver): array
    {
        return [
            'action' => 'create',
            'name' => $this->accountName(),
            ...($driver === DatabaseDriver::Postgres ? [] : ['host' => 'localhost']),
            'password' => self::PASSWORD,
        ];
    }

    private function root(DatabaseDriver $driver): Connection
    {
        $server = $this->serverSettings($driver);

        return app(ConnectionFactory::class)->make(new ConnectionSettings($driver, $server->host, $server->port, $this->targetTestDatabase(), $server->username, $server->password));
    }

    private function accountExists(Connection $root, DatabaseDriver $driver): bool
    {
        return $driver === DatabaseDriver::Postgres
            ? $root->selectOne('select 1 as found from pg_roles where rolname = ?', [$this->accountName()]) !== null
            : $root->selectOne('select 1 as found from mysql.user where user = ?', [$this->accountName()]) !== null;
    }

    /** Darf das Konto einfügen? Geprüft mit seiner eigenen Anmeldung — das Passwort sah aus wie SQL. */
    private function canInsert(DatabaseDriver $driver, Connection $root): bool
    {
        if ($driver === DatabaseDriver::Postgres) {
            return (bool) $root->selectOne("select has_table_privilege(?, 'public.w04_orders', 'INSERT') as allowed", [$this->accountName()])?->allowed;
        }

        $server = $this->serverSettings($driver);
        $own = app(ConnectionFactory::class)->make(new ConnectionSettings($driver, $server->host, $server->port, null, $this->accountName(), self::PASSWORD));

        try {
            $own->table($this->targetTestDatabase().'.w04_orders')->insert(['id' => random_int(1, 1_000_000)]);

            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            app(ConnectionFactory::class)->release($own);
        }
    }

    private function dropAccount(Connection $root, DatabaseDriver $driver): void
    {
        if ($driver !== DatabaseDriver::Postgres) {
            $root->statement("drop user if exists '".$this->accountName()."'@'localhost'");

            return;
        }

        if ($this->accountExists($root, $driver)) {
            $root->statement('drop owned by "'.$this->accountName().'"');
            $root->statement('drop role "'.$this->accountName().'"');
        }
    }
}
