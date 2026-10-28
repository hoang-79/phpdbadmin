<?php

namespace Tests\Feature\Http\Controllers\Workspace\Server;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;
use Throwable;

/**
 * Prozesse und Sitzungen (W-02): Liste mit eigener Sitzung, beenden nach Bestätigung — die beendete Sitzung ist
 * danach tot, und genau ein Eintrag steht im Protokoll.
 */
#[Group('target-databases')]
class ProcessControllerTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/prozesse-'.getmypid());
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

    public function test_lists_processes_with_own_session_and_exact_end_statements(): void
    {
        $connection = $this->savedConnection($this->scratchSettings(DatabaseDriver::MariaDb, $this->folder), $this->user);

        $this->actingAs($this->user)->get(route('workspace.server.processes', $connection))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/server/Processes')
                ->where('processes', function ($processes): bool {
                    $own = array_values(array_filter($processes->all(), fn (array $process): bool => $process['own']));

                    return count($own) === 1
                        && $own[0]['endStatements'] === ['query' => 'KILL QUERY '.$own[0]['id'], 'session' => 'KILL '.$own[0]['id']];
                }));
    }

    public function test_sqlite_says_there_are_no_server_processes(): void
    {
        $file = $this->folder.'/prozesse.sqlite';
        (new PDO('sqlite:'.$file))->exec('create table notes (id integer primary key)');
        $connection = DatabaseConnection::factory()->for($this->user, 'owner')->sqlite($file)->create();

        $this->actingAs($this->user)->get(route('workspace.server.processes', $connection))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('processes', null));
    }

    #[DataProvider('serverDrivers')]
    public function test_ending_a_session_kills_it_and_records_exactly_one_entry(DatabaseDriver $driver): void
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $connection = $this->savedConnection($settings, $this->user);
        $second = app(ConnectionFactory::class)->make($settings);
        $id = (int) $driver->dialect()->sessionId($second);
        $secondPdo = $second->getPdo();

        $this->actingAs($this->user)->from(route('workspace.server.processes', $connection))
            ->post(route('workspace.server.processes.end', [$connection, $id]), ['mode' => 'session'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workspace.server.processes', $connection))
            ->assertInertiaFlash('toast.message', 'Sitzung '.$id.' beendet.');

        $entry = AuditEntry::query()->sole();
        $this->assertSame('server.kill', $entry->action);
        $this->assertSame('#'.$id, $entry->target);
        $this->assertSame($driver === DatabaseDriver::Postgres ? 'SELECT pg_terminate_backend('.$id.')' : 'KILL '.$id, $entry->sql);
        $this->assertSame(['ok', 'session', $connection->id], [$entry->context['outcome'] ?? null, $entry->context['mode'] ?? null, $entry->context['connection'] ?? null]);
        $this->assertTrue($this->sessionDiesSoon($secondPdo), 'Die beendete Sitzung antwortet noch');
    }

    public function test_unknown_process_is_reported_and_the_attempt_recorded(): void
    {
        $connection = $this->savedConnection($this->scratchSettings(DatabaseDriver::MariaDb, $this->folder), $this->user);

        $this->actingAs($this->user)->from(route('workspace.server.processes', $connection))
            ->post(route('workspace.server.processes.end', [$connection, 999999999]), ['mode' => 'query'])
            ->assertSessionHasErrors(['server' => 'Den Prozess 999999999 gibt es nicht mehr.']);

        $entry = AuditEntry::query()->sole();
        $this->assertSame('failed', $entry->context['outcome'] ?? null);
        $this->assertSame('KILL QUERY 999999999', $entry->sql);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidProcessIds(): array
    {
        return [
            'Text' => ['abc'],
            'sieht aus wie SQL' => ['1;DROP TABLE x'],
            'null' => ['0'],
            'negativ' => ['-1'],
            'zu gross' => ['1234567890123456789012'],
        ];
    }

    #[DataProvider('invalidProcessIds')]
    public function test_process_id_must_be_a_positive_whole_number(string $process): void
    {
        $connection = $this->savedConnection($this->scratchSettings(DatabaseDriver::MariaDb, $this->folder), $this->user);

        $this->actingAs($this->user)->post('/c/'.$connection->id.'/server/processes/'.rawurlencode($process).'/end', ['mode' => 'session'])
            ->assertNotFound();

        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_mode_must_be_query_or_session(): void
    {
        $connection = $this->savedConnection($this->scratchSettings(DatabaseDriver::MariaDb, $this->folder), $this->user);

        $this->actingAs($this->user)->post(route('workspace.server.processes.end', [$connection, 42]), ['mode' => 'kill all'])
            ->assertSessionHasErrors('mode');
    }

    private function sessionDiesSoon(PDO $pdo): bool
    {
        $until = hrtime(true) + 3e9;

        do {
            try {
                $pdo->query('select 1');
            } catch (Throwable) {
                return true;
            }

            usleep(50_000);
        } while (hrtime(true) < $until);

        return false;
    }
}
