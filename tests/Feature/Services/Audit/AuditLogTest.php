<?php

namespace Tests\Feature\Services\Audit;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Protokoll (B-04): schreibende Aktionen mit Verbindung, Ziel, Anweisung und Zeilen; gebundene Werte erscheinen in
 * keiner Meldung — geprüft mit echten Fehlern der vier Treiber; Bereinigung nach Frist.
 */
#[Group('target-databases')]
class AuditLogTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private const string SECRET = 'geheim-4711@kunde-shop.ch';

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/protokoll-'.getmypid());
        @mkdir($this->folder, 0777, true);

        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'phpdbadmin.connections.sqlite_paths' => [$this->folder],
            'phpdbadmin.connections.connect_timeout' => 3,
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

    public function test_records_connection_target_masked_statement_and_rows(): void
    {
        $user = User::factory()->create(['name' => 'Nina Brügger']);
        $connection = DatabaseConnection::factory()->create(['name' => 'shop', 'driver' => DatabaseDriver::MySql]);

        $entry = app(AuditLog::class)->record('data.update', $user,
            target: 'shop.customers',
            connection: $connection,
            sql: 'update `customers` set `email` = ?, `note` = "frei" where `id` = ?',
            affectedRows: 1,
            bindings: [self::SECRET, 17],
        );

        $this->assertSame($connection->id, $entry->connection_id);
        $this->assertSame('shop', $entry->connection_name);
        $this->assertSame('Nina Brügger', $entry->user_name);
        $this->assertSame('shop.customers', $entry->target);
        $this->assertSame('update `customers` set `email` = ?, `note` = ? where `id` = ?', $entry->sql);
        $this->assertSame(1, $entry->affected_rows);
    }

    public function test_entry_keeps_names_after_account_and_connection_are_removed(): void
    {
        $user = User::factory()->create(['name' => 'Reto Imhof']);
        $connection = DatabaseConnection::factory()->for($user, 'owner')->create(['name' => 'notizen']);
        $entry = app(AuditLog::class)->record('connection.updated', $user, connection: $connection);

        $user->delete();

        $entry->refresh();
        $this->assertNull($entry->user_id);
        $this->assertSame('Reto Imhof', $entry->user_name);
        $this->assertNull($entry->connection);
        $this->assertSame('notizen', $entry->connection_name);
    }

    #[DataProvider('drivers')]
    public function test_bound_value_appears_in_no_message_of_a_real_failure(DatabaseDriver $driver): void
    {
        $target = app(ConnectionFactory::class)->make($this->scratchSettings($driver, $this->folder));
        $target->getSchemaBuilder()->create('kunden', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->integer('punkte')->nullable();
        });
        $target->table('kunden')->insert(['email' => self::SECRET]);

        $failures = [$this->failure($target, 'insert into kunden (email) values (?)', [self::SECRET])];

        if ($driver !== DatabaseDriver::Sqlite) {
            // SQLite nimmt jeden Wert in eine Zahlenspalte; MySQL, MariaDB und PostgreSQL zitieren ihn in der Meldung.
            $failures[] = $this->failure($target, 'update kunden set punkte = ? where id = 1', [self::SECRET]);
        }

        if ($driver !== DatabaseDriver::Sqlite) {
            $this->assertStringContainsString(self::SECRET, (string) $failures[1]->getPrevious()?->getMessage(), 'Der Server soll den Wert in seiner Meldung zitieren');
        }

        foreach ($failures as $failure) {
            $this->assertContains(self::SECRET, $failure->getBindings());

            $entry = app(AuditLog::class)->record('data.insert', target: 'kunden', sql: $failure->getSql(), bindings: $failure->getBindings(), error: $failure);
            $stored = json_encode(DB::table('audit_log')->where('id', $entry->id)->first(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString(self::SECRET, $stored);
            $this->assertStringNotContainsString('geheim', $stored);
            $this->assertArrayHasKey('error', $entry->context ?? []);
        }
    }

    public function test_reported_query_exception_reaches_the_log_without_values(): void
    {
        Log::spy();
        DB::table('users')->insert(['name' => 'Erste', 'email' => self::SECRET]);
        $failure = $this->failure(DB::connection(), 'insert into users (name, email) values (?, ?)', ['Zweite', self::SECRET]);

        report($failure);

        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
            $all = $message.json_encode($context, JSON_UNESCAPED_UNICODE);

            return ! str_contains($all, self::SECRET) && str_contains($message, 'insert into users (name, email) values (?, ?)');
        });
    }

    public function test_entries_older_than_the_retention_are_pruned(): void
    {
        config(['phpdbadmin.audit.retention_days' => 30]);
        $old = $this->travel(-31)->days(fn (): AuditEntry => AuditEntry::query()->create(['action' => 'auth.login']));
        $young = $this->travel(-29)->days(fn (): AuditEntry => AuditEntry::query()->create(['action' => 'auth.login']));

        $this->artisan('model:prune', ['--model' => [AuditEntry::class]])->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($young);
    }

    public function test_retention_of_zero_keeps_everything(): void
    {
        config(['phpdbadmin.audit.retention_days' => 0]);
        $old = $this->travel(-5)->years(fn (): AuditEntry => AuditEntry::query()->create(['action' => 'auth.login']));

        $this->artisan('model:prune', ['--model' => [AuditEntry::class]])->assertSuccessful();

        $this->assertModelExists($old);
    }

    public function test_pruning_runs_daily(): void
    {
        $prune = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'model:prune'));

        $this->assertCount(1, $prune);
        $this->assertSame('0 0 * * *', $prune->sole()->expression);
    }

    /**
     * @param  array<int, mixed>  $bindings
     */
    private function failure(Connection $connection, string $sql, array $bindings): QueryException
    {
        try {
            $connection->statement($sql, $bindings);
        } catch (QueryException $exception) {
            return $exception;
        }

        $this->fail('Die Anweisung sollte scheitern: '.$sql);
    }
}
