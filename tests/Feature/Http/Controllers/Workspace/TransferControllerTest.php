<?php

namespace Tests\Feature\Http\Controllers\Workspace;

use App\Connections\ConnectionFactory;
use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;
use App\Enums\TransferStatus;
use App\Jobs\RunExport;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\TargetDatabases;
use Tests\Concerns\TransferSamples;
use Tests\TestCase;

/**
 * Seite «Export und Import» (Wireframe 11, E-01, E-06, B-07): Aufträge nur des eigenen Kontos, Export ohne Worker
 * mit Grenze, mit Worker in der Warteschlange, Download bis zur Frist, Abbrechen, Statistik nur mit Schreibrecht.
 */
class TransferControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use TargetDatabases;
    use TransferSamples;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/seite-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config(['phpdbadmin.connections.sqlite_paths' => [$this->folder]]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    public function test_page_lists_only_own_transfers_and_presets_the_export_from_the_address(): void
    {
        $connection = $this->sqliteSamples();
        $own = Transfer::factory()->for($this->user)->for($connection, 'databaseConnection')->create();
        Transfer::factory()->for(User::factory()->create())->for($connection, 'databaseConnection')->create();
        Transfer::factory()->expired()->for($this->user)->for($connection, 'databaseConnection')->create();

        $this->actingAs($this->user)->get(route('workspace.transfers.index', [$connection, 'schema' => 'main', 'table' => 'kunden']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/transfers/Index')
                ->where('workspace.schema', 'main')
                ->where('preset.table', 'kunden')
                ->where('selected', 'kunden')
                ->has('transfers', 1)
                ->where('transfers.0.id', $own->id)
                ->where('mode.background', false)
                ->where('canWrite', true));
    }

    public function test_guest_is_sent_to_login_and_foreign_connection_is_404(): void
    {
        $foreign = DatabaseConnection::factory()->sqlite($this->folder.'/x.sqlite')->create();

        $this->get(route('workspace.transfers.index', $foreign))->assertRedirect(route('login'));
        $this->actingAs($this->user)->get(route('workspace.transfers.index', $foreign))->assertNotFound();
    }

    public function test_export_without_worker_runs_in_the_request_and_offers_the_file(): void
    {
        Storage::fake(Transfer::DISK);
        $connection = $this->sqliteSamples();

        $this->actingAs($this->user)->post(route('workspace.transfers.export', $connection), [
            'format' => 'csv', 'scope' => 'table', 'schema' => 'main', 'table' => 'kunden',
            'delimiter' => 'comma', 'bom' => false, 'header' => true, 'binary' => 'skip',
        ])->assertRedirect(route('workspace.transfers.index', [$connection, 'schema' => 'main', 'table' => 'kunden']));

        $transfer = Transfer::query()->sole();
        $this->assertSame(TransferStatus::Done, $transfer->status);
        $this->assertFalse($transfer->in_background);
        $this->assertSame('kunden-'.now()->format('Y-m-d').'.csv', $transfer->file_name);

        $download = $this->get(route('workspace.transfers.download', [$connection, $transfer->id]))->assertOk();
        $this->assertStringContainsString('kunden-'.now()->format('Y-m-d').'.csv', (string) $download->headers->get('content-disposition'));
        $this->assertStringStartsWith("id,name,notiz,betrag,wert,aktiv,erstellt,meta\r\n1,Jürg Müller,,", $download->streamedContent());
    }

    public function test_export_over_the_limit_without_worker_is_rejected_with_explanation(): void
    {
        config(['phpdbadmin.transfers.sync_max_rows' => 5]);
        $connection = $this->sqliteSamples();

        $this->actingAs($this->user)->post(route('workspace.transfers.export', $connection), [
            'format' => 'json', 'scope' => 'schema', 'schema' => 'main',
        ])->assertSessionHasErrors(['scope' => 'Ohne Hintergrund-Worker exportiert phpdbadmin höchstens 5 Zeilen in einer Anfrage; die Auswahl hat etwa 27. Richten Sie einen Worker ein (php artisan queue:work) oder wählen Sie weniger.']);

        $this->assertSame(0, Transfer::query()->count());
    }

    public function test_export_with_worker_is_queued(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake([RunExport::class]);
        $connection = $this->sqliteSamples();

        $this->actingAs($this->user)->post(route('workspace.transfers.export', $connection), [
            'format' => 'sql', 'scope' => 'schema', 'schema' => 'main', 'content' => 'both',
        ])->assertRedirect();

        $transfer = Transfer::query()->sole();
        $this->assertSame(TransferStatus::Queued, $transfer->status);
        $this->assertTrue($transfer->in_background);
        Queue::assertPushed(RunExport::class, fn (RunExport $job): bool => $job->transferId === $transfer->id);
    }

    public function test_csv_of_a_whole_database_is_refused(): void
    {
        $connection = $this->sqliteSamples();

        $this->actingAs($this->user)->post(route('workspace.transfers.export', $connection), [
            'format' => 'csv', 'scope' => 'schema', 'schema' => 'main',
        ])->assertSessionHasErrors(['scope' => 'CSV gibt es nur für eine Tabelle — für eine ganze Datenbank wählen Sie JSON oder SQL.']);
    }

    public function test_download_is_404_for_another_account_and_after_the_deadline(): void
    {
        Storage::fake(Transfer::DISK);
        $connection = $this->sqliteSamples();
        $other = Transfer::factory()->for(User::factory()->create())->for($connection, 'databaseConnection')->finished('transfers/a/orders.csv')->create();
        $expired = Transfer::factory()->for($this->user)->for($connection, 'databaseConnection')->finished('transfers/b/orders.csv')->expired()->create();
        Storage::disk(Transfer::DISK)->put('transfers/a/orders.csv', 'x');
        Storage::disk(Transfer::DISK)->put('transfers/b/orders.csv', 'x');

        $this->actingAs($this->user)->get(route('workspace.transfers.download', [$connection, $other->id]))->assertNotFound();
        $this->actingAs($this->user)->get(route('workspace.transfers.download', [$connection, $expired->id]))->assertNotFound();
    }

    public function test_status_returns_own_transfers_for_polling(): void
    {
        $connection = $this->sqliteSamples();
        $running = Transfer::factory()->for($this->user)->for($connection, 'databaseConnection')->create(['status' => TransferStatus::Running, 'progress' => 40, 'rows' => 4000, 'total_rows' => 10000]);

        $this->actingAs($this->user)->getJson(route('workspace.transfers.status', $connection))
            ->assertOk()
            ->assertJsonCount(1, 'transfers')
            ->assertJsonPath('transfers.0.id', $running->id)
            ->assertJsonPath('transfers.0.active', true)
            ->assertJsonPath('transfers.0.progress', 40)
            ->assertJsonPath('transfers.0.cancelUrl', route('workspace.transfers.cancel', [$connection, $running->id]));
    }

    public function test_cancel_stops_own_running_transfer_and_not_foreign_ones(): void
    {
        $connection = $this->sqliteSamples();
        $own = Transfer::factory()->for($this->user)->for($connection, 'databaseConnection')->create(['status' => TransferStatus::Running]);
        $foreign = Transfer::factory()->for(User::factory()->create())->for($connection, 'databaseConnection')->create(['status' => TransferStatus::Running]);

        $this->actingAs($this->user)->post(route('workspace.transfers.cancel', [$connection, $own->id]))->assertRedirect();
        $this->actingAs($this->user)->post(route('workspace.transfers.cancel', [$connection, $foreign->id]))->assertNotFound();

        $this->assertSame(TransferStatus::Cancelled, $own->refresh()->status);
        $this->assertSame(TransferStatus::Running, $foreign->refresh()->status);
    }

    public function test_statistics_on_button_press_runs_analyze_and_is_logged(): void
    {
        $connection = $this->sqliteSamples();

        $this->actingAs($this->user)->post(route('workspace.transfers.analyze', $connection), ['schema' => 'main', 'table' => 'kunden'])
            ->assertRedirect()
            ->assertInertiaFlash('toast.message', 'Statistik von «kunden» aktualisiert.');

        $stat = app(ConnectionFactory::class)->make($this->sqliteSettings())->selectOne("select stat from sqlite_stat1 where tbl = 'kunden'");
        $this->assertNotNull($stat);
        $entry = AuditEntry::query()->sole();
        $this->assertSame('data.analyze', $entry->action);
        $this->assertSame('main.kunden', $entry->target);
    }

    public function test_statistics_on_a_read_only_connection_is_forbidden(): void
    {
        $connection = $this->sqliteSamples(['read_only' => true]);

        $this->actingAs($this->user)->post(route('workspace.transfers.analyze', $connection), ['schema' => 'main', 'table' => 'kunden'])->assertForbidden();
        $this->assertSame(0, AuditEntry::query()->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function sqliteSamples(array $attributes = []): DatabaseConnection
    {
        $settings = $this->sqliteSettings();
        $this->createTransferSamples(app(ConnectionFactory::class)->make($settings), DatabaseDriver::Sqlite);

        return $this->savedConnection($settings, $this->user, $attributes);
    }

    private function sqliteSettings(): ConnectionSettings
    {
        $file = $this->folder.'/seite.sqlite';

        if (! is_file($file)) {
            touch($file);
        }

        return new ConnectionSettings(DatabaseDriver::Sqlite, sqlitePath: $file);
    }
}
