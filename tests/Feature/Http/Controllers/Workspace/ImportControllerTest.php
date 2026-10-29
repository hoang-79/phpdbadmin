<?php

namespace Tests\Feature\Http\Controllers\Workspace;

use App\Connections\ConnectionFactory;
use App\Connections\ConnectionSettings;
use App\Enums\ConnectionEnvironment;
use App\Enums\ConnectionPermission;
use App\Enums\DatabaseDriver;
use App\Enums\TransferFormat;
use App\Enums\TransferKind;
use App\Enums\TransferStatus;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Import in Schritten über HTTP (E-02, Wireframe 11): Datei hochladen, Zuordnung, Probelauf, Import. Schreiben nur
 * mit Recht «schreiben» und nie über schreibgeschützte Verbindungen (403); auf Produktion mit dem Namen der
 * Verbindung; fremde Aufträge sind 404.
 */
class ImportControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use TargetDatabases;

    private const string CSV = "E-Mail;Name\r\nanna@example.ch;Anna Muster\r\nbeat@example.ch;Beat Keller\r\n";

    private string $folder;

    private User $user;

    private Connection $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/import-http-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config(['phpdbadmin.connections.sqlite_paths' => [$this->folder]]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    public function test_upload_creates_a_draft_with_suggested_mapping_and_shows_the_steps(): void
    {
        Storage::fake(Transfer::DISK);
        $connection = $this->contacts();

        $response = $this->actingAs($this->user)->post(route('workspace.transfers.import', $connection), [
            'format' => 'csv', 'schema' => 'main', 'table' => 'kontakte', 'file' => UploadedFile::fake()->createWithContent('neu.csv', self::CSV),
        ]);

        $transfer = Transfer::query()->sole();
        $response->assertRedirect(route('workspace.transfers.edit', [$connection, $transfer->id]));
        $this->assertSame(TransferStatus::Draft, $transfer->status);
        $this->assertSame(['email', 'name'], $transfer->options['mapping'] ?? null);
        Storage::disk(Transfer::DISK)->assertExists((string) $transfer->file_path);

        $this->actingAs($this->user)->get(route('workspace.transfers.edit', [$connection, $transfer->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/transfers/Import')
                ->where('selected', 'kontakte')
                ->where('transfer.options.delimiter', 'semicolon')
                ->where('transfer.options.records', 2)
                ->where('transfer.options.columns.0.name', 'E-Mail')
                ->where('transfer.options.columns.0.samples', ['anna@example.ch', 'beat@example.ch'])
                ->where('columns.0.name', 'id')
                ->where('columns.1.required', true)
                ->where('requiresConfirmation', false));
    }

    public function test_dry_run_writes_nothing_then_import_writes_and_logs_once(): void
    {
        Storage::fake(Transfer::DISK);
        $connection = $this->contacts();
        $transfer = $this->draft($connection);

        $this->actingAs($this->user)->post(route('workspace.transfers.run', [$connection, $transfer->id]), ['dry_run' => true, 'mapping' => ['email', 'name']])
            ->assertRedirect(route('workspace.transfers.edit', [$connection, $transfer->id]))
            ->assertInertiaFlash('toast.message', 'Probelauf fertig — nichts wurde geschrieben.');
        $this->assertSame(2, $transfer->refresh()->result['ready'] ?? null);
        $this->assertSame(0, (int) $this->writer->table('kontakte')->count());

        $this->actingAs($this->user)->post(route('workspace.transfers.run', [$connection, $transfer->id]), ['dry_run' => false, 'mapping' => ['email', 'name'], 'analyze' => false])
            ->assertRedirect();
        $this->assertSame(TransferStatus::Done, $transfer->refresh()->status);
        $this->assertSame(['anna@example.ch', 'beat@example.ch'], $this->writer->table('kontakte')->orderBy('email')->pluck('email')->all());
        $this->assertSame(1, AuditEntry::query()->where('action', 'data.import')->count());

        // Fertig importiert: kein zweiter Lauf.
        $this->actingAs($this->user)->post(route('workspace.transfers.run', [$connection, $transfer->id]), ['dry_run' => false, 'mapping' => ['email', 'name']])->assertStatus(409);
    }

    public function test_read_only_connection_refuses_upload_and_run(): void
    {
        Storage::fake(Transfer::DISK);
        $connection = $this->contacts(['read_only' => true]);
        $transfer = $this->draft($connection);

        $this->actingAs($this->user)->post(route('workspace.transfers.import', $connection), [
            'format' => 'csv', 'schema' => 'main', 'table' => 'kontakte', 'file' => UploadedFile::fake()->createWithContent('neu.csv', self::CSV),
        ])->assertForbidden();
        $this->actingAs($this->user)->post(route('workspace.transfers.run', [$connection, $transfer->id]), ['dry_run' => false, 'mapping' => ['email', 'name']])->assertForbidden();

        $this->assertSame(0, (int) $this->writer->table('kontakte')->count());
    }

    public function test_member_with_read_permission_may_not_import(): void
    {
        Storage::fake(Transfer::DISK);
        $owner = User::factory()->create();
        $connection = $this->contacts(['shared' => true], $owner);
        $connection->members()->attach($this->user, ['permission' => ConnectionPermission::Read->value]);

        $this->actingAs($this->user)->get(route('workspace.transfers.index', $connection))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canWrite', false));
        $this->actingAs($this->user)->post(route('workspace.transfers.import', $connection), [
            'format' => 'csv', 'schema' => 'main', 'table' => 'kontakte', 'file' => UploadedFile::fake()->createWithContent('neu.csv', self::CSV),
        ])->assertForbidden();
    }

    public function test_production_import_needs_the_connection_name_but_the_dry_run_does_not(): void
    {
        Storage::fake(Transfer::DISK);
        $connection = $this->contacts(['environment' => ConnectionEnvironment::Production, 'read_only' => false, 'name' => 'shop']);
        $transfer = $this->draft($connection);

        $this->actingAs($this->user)->post(route('workspace.transfers.run', [$connection, $transfer->id]), ['dry_run' => true, 'mapping' => ['email', 'name']])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('workspace.transfers.run', [$connection, $transfer->id]), ['dry_run' => false, 'mapping' => ['email', 'name'], 'confirmation' => 'sho'])
            ->assertSessionHasErrors(['confirmation' => 'Tippen Sie zur Bestätigung den Namen der Verbindung «shop» ein.']);
        $this->assertSame(0, (int) $this->writer->table('kontakte')->count());

        $this->actingAs($this->user)->post(route('workspace.transfers.run', [$connection, $transfer->id]), ['dry_run' => false, 'mapping' => ['email', 'name'], 'confirmation' => 'shop'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, (int) $this->writer->table('kontakte')->count());
    }

    public function test_mapping_to_an_unknown_or_doubled_column_is_refused(): void
    {
        Storage::fake(Transfer::DISK);
        $connection = $this->contacts();
        $transfer = $this->draft($connection);

        $this->actingAs($this->user)->post(route('workspace.transfers.run', [$connection, $transfer->id]), ['dry_run' => true, 'mapping' => ['email', 'id; drop table kontakte']])
            ->assertSessionHasErrors(['mapping' => 'Die Spalte «id; drop table kontakte» gibt es in dieser Tabelle nicht.']);
        $this->actingAs($this->user)->post(route('workspace.transfers.run', [$connection, $transfer->id]), ['dry_run' => true, 'mapping' => ['name', 'name']])
            ->assertSessionHasErrors(['mapping' => 'Jede Spalte der Tabelle lässt sich nur einmal zuordnen.']);
        $this->assertSame(TransferStatus::Draft, $transfer->refresh()->status);
    }

    public function test_import_over_the_limit_without_worker_is_refused_with_explanation(): void
    {
        Storage::fake(Transfer::DISK);
        config(['phpdbadmin.transfers.sync_max_bytes' => 10]);
        $connection = $this->contacts();
        $transfer = $this->draft($connection);

        $this->actingAs($this->user)->post(route('workspace.transfers.run', [$connection, $transfer->id]), ['dry_run' => true, 'mapping' => ['email', 'name']])
            ->assertSessionHasErrors(['run' => 'Ohne Hintergrund-Worker importiert phpdbadmin höchstens 0.0 MB in einer Anfrage; die Datei hat 0.0 MB. Richten Sie einen Worker ein (php artisan queue:work) oder teilen Sie die Datei.']);
    }

    public function test_reading_with_another_delimiter_analyses_again(): void
    {
        Storage::fake(Transfer::DISK);
        $connection = $this->contacts();
        $transfer = $this->draft($connection);

        $this->actingAs($this->user)->patch(route('workspace.transfers.update', [$connection, $transfer->id]), ['delimiter' => 'comma', 'encoding' => 'utf-8', 'header' => false])
            ->assertRedirect();

        $transfer->refresh();
        $this->assertSame(',', $transfer->options['delimiter'] ?? null);
        $this->assertFalse($transfer->options['header'] ?? true);
        $this->assertCount(1, $transfer->options['columns'] ?? []);
    }

    public function test_foreign_transfer_is_404(): void
    {
        Storage::fake(Transfer::DISK);
        $connection = $this->contacts();
        $foreign = Transfer::factory()->for(User::factory()->create())->for($connection, 'databaseConnection')->create(['kind' => TransferKind::Import, 'status' => TransferStatus::Draft]);

        $this->actingAs($this->user)->get(route('workspace.transfers.edit', [$connection, $foreign->id]))->assertNotFound();
        $this->actingAs($this->user)->post(route('workspace.transfers.run', [$connection, $foreign->id]), ['dry_run' => true])->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function contacts(array $attributes = [], ?User $owner = null): DatabaseConnection
    {
        $file = $this->folder.'/kontakte.sqlite';
        touch($file);
        $settings = new ConnectionSettings(DatabaseDriver::Sqlite, sqlitePath: $file);
        $this->writer = app(ConnectionFactory::class)->make($settings);
        $this->writer->getSchemaBuilder()->create('kontakte', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name');
        });

        return $this->savedConnection($settings, $owner ?? $this->user, $attributes);
    }

    private function draft(DatabaseConnection $connection): Transfer
    {
        $transfer = Transfer::factory()->for($this->user)->for($connection, 'databaseConnection')->create([
            'kind' => TransferKind::Import,
            'format' => TransferFormat::Csv,
            'status' => TransferStatus::Draft,
            'target' => 'main.kontakte',
            'options' => [
                'schema' => 'main', 'table' => 'kontakte', 'delimiter' => ';', 'encoding' => 'utf-8', 'header' => true,
                'columns' => [['index' => 0, 'name' => 'E-Mail', 'samples' => []], ['index' => 1, 'name' => 'Name', 'samples' => []]],
                'mapping' => ['email', 'name'],
            ],
            'file_name' => 'neu.csv',
        ]);
        Storage::disk(Transfer::DISK)->put($transfer->directory().'/source.csv', self::CSV);
        $transfer->forceFill(['file_path' => $transfer->directory().'/source.csv', 'file_size' => strlen(self::CSV)])->save();

        return $transfer;
    }
}
