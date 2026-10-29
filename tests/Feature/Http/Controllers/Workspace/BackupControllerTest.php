<?php

namespace Tests\Feature\Http\Controllers\Workspace;

use App\Enums\ConnectionEnvironment;
use App\Enums\TransferKind;
use App\Enums\TransferStatus;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Backup\Tools;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PDO;
use Tests\TestCase;

/**
 * Seite «Sicherung und Wiederherstellung» (E-03) mit SQLite: Werkzeuge sichtbar, Sicherung mit Download,
 * Wiederherstellen nur mit Recht «schreiben», nie schreibgeschützt, mit Bestätigung und in Produktion mit Namen;
 * eine Datei der falschen Art wird abgewiesen.
 */
class BackupControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/sicherung-seite-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config(['phpdbadmin.connections.sqlite_paths' => [$this->folder]]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    public function test_page_shows_the_tools_and_only_backups_and_restores(): void
    {
        $connection = $this->connection();
        Transfer::factory()->for($this->user)->for($connection, 'databaseConnection')->create(['kind' => TransferKind::Backup]);
        Transfer::factory()->for($this->user)->for($connection, 'databaseConnection')->create(['kind' => TransferKind::Export]);

        $this->actingAs($this->user)->get(route('workspace.transfers.backup', $connection))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/transfers/Backup')
                ->where('status.canBackup', true)
                ->where('status.restore.0.name', 'sqlite3')
                ->where('status.restore.0.environmentKey', 'PHPDBADMIN_TOOL_SQLITE3')
                ->where('databases', [basename((string) $connection->sqlite_path)])
                ->has('transfers', 1)
                ->where('transfers.0.kind', 'backup')
                ->where('canWrite', true));
    }

    public function test_backup_runs_and_offers_the_file_but_not_to_others(): void
    {
        Storage::fake(Transfer::DISK);
        $connection = $this->connection();

        $this->actingAs($this->user)->post(route('workspace.transfers.backup.store', $connection), ['database' => basename((string) $connection->sqlite_path)])
            ->assertRedirect();

        $transfer = Transfer::query()->sole();
        $this->assertSame([TransferKind::Backup, TransferStatus::Done], [$transfer->kind, $transfer->status]);
        $download = $this->get(route('workspace.transfers.download', [$connection, $transfer->id]))->assertOk();
        $this->assertStringStartsWith("SQLite format 3\0", $download->streamedContent());
        $this->assertSame(1, AuditEntry::query()->where('action', 'transfer.backup')->count());

        $this->actingAs(User::factory()->create())->get(route('workspace.transfers.download', [$connection, $transfer->id]))->assertNotFound();
    }

    public function test_restore_replaces_the_content_after_confirmation(): void
    {
        $this->requireSqlite3();
        Storage::fake(Transfer::DISK);
        $connection = $this->connection();
        $backup = $this->sqliteFile('sicherung.sqlite', ['aus der Sicherung']);

        $this->actingAs($this->user)->post(route('workspace.transfers.restore', $connection), [
            'file' => $this->upload($backup), 'database' => basename((string) $connection->sqlite_path),
        ])->assertSessionHasErrors(['confirm' => 'Bestätigen Sie, dass die Wiederherstellung Tabellen gleichen Namens ersetzt.']);
        $this->assertSame(['eins', 'zwei'], $this->bodies($connection));

        $this->post(route('workspace.transfers.restore', $connection), [
            'file' => $this->upload($backup), 'database' => basename((string) $connection->sqlite_path), 'confirm' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(TransferStatus::Done, Transfer::query()->where('kind', TransferKind::Restore)->sole()->status);
        $this->assertSame(['aus der Sicherung'], $this->bodies($connection));
        $this->assertSame(1, AuditEntry::query()->where('action', 'data.restore')->count());
    }

    public function test_restore_needs_the_right_to_write_and_no_write_protection(): void
    {
        $shared = $this->connection(['shared' => true]);
        $reader = User::factory()->create();
        $shared->members()->attach($reader, ['permission' => 'read']);
        $readOnly = $this->connection(['read_only' => true]);
        $payload = fn (DatabaseConnection $connection): array => [
            'file' => $this->upload($this->sqliteFile('x.sqlite', ['x'])), 'database' => basename((string) $connection->sqlite_path), 'confirm' => true,
        ];

        $this->actingAs($reader)->post(route('workspace.transfers.restore', $shared), $payload($shared))->assertForbidden();
        $this->actingAs($this->user)->post(route('workspace.transfers.restore', $readOnly), $payload($readOnly))->assertForbidden();

        $this->assertSame(0, Transfer::query()->count());
        $this->assertSame(['eins', 'zwei'], $this->bodies($shared));
    }

    public function test_production_needs_the_name_and_the_file_must_fit_the_driver(): void
    {
        Storage::fake(Transfer::DISK);
        $production = $this->connection(['environment' => ConnectionEnvironment::Production, 'read_only' => false, 'name' => 'Live']);
        $database = basename((string) $production->sqlite_path);

        $this->actingAs($this->user)->post(route('workspace.transfers.restore', $production), [
            'file' => $this->upload($this->sqliteFile('x.sqlite', ['x'])), 'database' => $database, 'confirm' => true,
        ])->assertSessionHasErrors(['confirmation' => 'Tippen Sie zur Bestätigung den Namen der Verbindung «Live» ein.']);

        $text = $this->folder.'/dump.sql';
        file_put_contents($text, "create table x (id int);\n");
        $this->post(route('workspace.transfers.restore', $production), [
            'file' => new UploadedFile($text, 'dump.sql', null, null, true), 'database' => $database, 'confirm' => true, 'confirmation' => 'Live',
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, Transfer::query()->count());
        $this->assertSame(['eins', 'zwei'], $this->bodies($production));
    }

    public function test_foreign_connection_is_404(): void
    {
        $foreign = DatabaseConnection::factory()->sqlite($this->folder.'/fremd.sqlite')->create();

        $this->actingAs($this->user)->get(route('workspace.transfers.backup', $foreign))->assertNotFound();
        $this->post(route('workspace.transfers.backup.store', $foreign), ['database' => 'fremd.sqlite'])->assertNotFound();
    }

    private function requireSqlite3(): void
    {
        if (app(Tools::class)->info('sqlite3')['path'] === null) {
            $this->markTestSkipped('sqlite3 nicht gefunden (Pfad in PHPDBADMIN_TOOL_SQLITE3 setzen) — übersprungen, nicht bestanden');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function connection(array $attributes = []): DatabaseConnection
    {
        $file = $this->sqliteFile('notizen-'.uniqid().'.sqlite', ['eins', 'zwei']);

        return DatabaseConnection::factory()->for($this->user, 'owner')->sqlite($file)->create($attributes);
    }

    /**
     * @param  list<string>  $bodies
     */
    private function sqliteFile(string $name, array $bodies): string
    {
        $file = $this->folder.'/'.$name;
        @unlink($file);
        $pdo = new PDO('sqlite:'.$file);
        $pdo->exec('create table notes (id integer primary key, body text not null)');
        $insert = $pdo->prepare('insert into notes (body) values (?)');

        foreach ($bodies as $body) {
            $insert->execute([$body]);
        }

        return $file;
    }

    private function upload(string $file): UploadedFile
    {
        $copy = $this->folder.'/upload-'.uniqid().'.sqlite';
        copy($file, $copy);

        return new UploadedFile($copy, basename($file), null, null, true);
    }

    /**
     * @return list<string>
     */
    private function bodies(DatabaseConnection $connection): array
    {
        return (new PDO('sqlite:'.$connection->sqlite_path))->query('select body from notes order by id')->fetchAll(PDO::FETCH_COLUMN);
    }
}
