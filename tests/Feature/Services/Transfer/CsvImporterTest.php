<?php

namespace Tests\Feature\Services\Transfer;

use App\Connections\ConnectionFactory;
use App\Enums\ColumnKind;
use App\Enums\DatabaseDriver;
use App\Enums\TransferFormat;
use App\Enums\TransferKind;
use App\Enums\TransferStatus;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\Transfer;
use App\Services\Transfer\ColumnInfo;
use App\Services\Transfer\CsvImporter;
use App\Support\TransferSummary;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\Concerns\TransferSamples;
use Tests\TestCase;

/**
 * CSV-Import (E-02): Probelauf schreibt nichts, Fehlerzeilen kommen mit Zeilennummer und Grund, Fehlerzeilen
 * überspringen oder nichts importieren, Werte, die wie SQL aussehen, landen wörtlich, ein Eintrag im Protokoll.
 */
#[Group('target-databases')]
class CsvImporterTest extends TestCase
{
    use LazilyRefreshDatabase;
    use TargetDatabases;
    use TransferSamples;

    /** Zeile 1 Kopf; Zeile 3 hat ein Feld mit Zeilenumbruch, deshalb beginnt der nächste Satz in Zeile 5. */
    private const string FILE = "email;name;aktiv;foto;bemerkung\r\n"
        ."anna@example.ch;Anna Muster;ja;;\r\n"
        ."beat@example.ch;\"Beat \"\"B.\"\" Keller\";nein;AAH//mFiYw==;\"zwei\r\nZeilen\"\r\n"
        ."leer@example.ch;;ja;;fehlt\r\n"
        ."anna@example.ch;Anna doppelt;ja;;\r\n"
        ."kaputt@example.ch;Kein Base64;nein;%%%;\r\n"
        ."zu@wenig.ch;Felder\r\n"
        ."sql@example.ch;\"x'); DROP TABLE kontakte; --\";WAHR;;\"\"\r\n";

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/import-'.getmypid());
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
    public function test_dry_run_reports_error_rows_with_line_numbers_and_writes_nothing(DatabaseDriver $driver): void
    {
        Storage::fake(Transfer::DISK);
        [$writer, $connection, $schema] = $this->contacts($driver);

        $transfer = $this->runTransfer($connection, TransferKind::Import, TransferFormat::Csv, $this->importOptions($schema), $this->file(), dryRun: true);

        $this->assertSame(TransferStatus::Done, $transfer->status, json_encode($transfer->error) ?: '');
        $this->assertSame(0, (int) $writer->table('kontakte')->count());
        $this->assertSame(7, $transfer->result['records'] ?? null);
        $this->assertSame(3, $transfer->result['ready'] ?? null);
        $this->assertSame(4, $transfer->result['error_count'] ?? null);

        $errors = TransferSummary::result($transfer)['errors'] ?? [];
        $this->assertSame([5, 6, 7, 8], array_column($errors, 'line'));
        $this->assertSame('«name» ist leer und darf nicht NULL sein.', $errors[0]['text']);
        $this->assertSame('Die Datenbank lehnt die Zeile ab.', $errors[1]['text']);
        $this->assertNotNull($errors[1]['detail']);
        $this->assertSame('«foto» ist kein gültiges Base64 (Binärspalte).', $errors[2]['text']);
        $this->assertSame('Die Zeile hat 2 Felder, erwartet sind 5.', $errors[3]['text']);
        $this->assertSame(0, AuditEntry::query()->count());
    }

    #[DataProvider('drivers')]
    public function test_import_skips_error_rows_stores_values_verbatim_and_logs_once(DatabaseDriver $driver): void
    {
        Storage::fake(Transfer::DISK);
        [$writer, $connection, $schema] = $this->contacts($driver);

        $transfer = $this->runTransfer($connection, TransferKind::Import, TransferFormat::Csv, $this->importOptions($schema), $this->file());

        $this->assertSame(TransferStatus::Done, $transfer->status, json_encode($transfer->error) ?: '');
        $this->assertSame(3, $transfer->rows);
        $rows = $writer->table('kontakte')->orderBy('email')->get()->map(fn (object $row): array => (array) $row)->all();
        $this->assertSame(['anna@example.ch', 'beat@example.ch', 'sql@example.ch'], array_column($rows, 'email'));
        $this->assertSame('Beat "B." Keller', $rows[1]['name']);
        $this->assertSame("zwei\r\nZeilen", $rows[1]['bemerkung']);
        $this->assertSame("\x00\x01\xFF\xFEabc", is_resource($rows[1]['foto']) ? stream_get_contents($rows[1]['foto']) : $rows[1]['foto']);
        $this->assertSame("x'); DROP TABLE kontakte; --", $rows[2]['name']);
        $this->assertSame('', $rows[2]['bemerkung']);
        $this->assertNull($rows[0]['bemerkung']);
        $this->assertEquals([1, 0, 1], array_map(fn (array $row): int => (int) $row['aktiv'], $rows));

        $entry = AuditEntry::query()->sole();
        $this->assertSame('data.import', $entry->action);
        $this->assertSame($schema.'.kontakte', $entry->target);
        $this->assertSame(3, $entry->context['rows'] ?? null);
        $this->assertSame(4, $entry->context['skipped'] ?? null);
    }

    #[DataProvider('drivers')]
    public function test_import_with_abort_on_error_imports_nothing_and_fails(DatabaseDriver $driver): void
    {
        Storage::fake(Transfer::DISK);
        [$writer, $connection, $schema] = $this->contacts($driver);

        $transfer = $this->runTransfer($connection, TransferKind::Import, TransferFormat::Csv, [...$this->importOptions($schema), 'on_error' => 'abort'], $this->file());

        $this->assertSame(TransferStatus::Failed, $transfer->status);
        $this->assertSame('Nichts importiert: 4 Zeilen mit Fehlern.', TransferSummary::message($transfer->error)['text'] ?? null);
        $this->assertSame(0, (int) $writer->table('kontakte')->count());
        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_analysis_detects_bom_delimiter_header_and_suggests_columns_by_name(): void
    {
        $file = $this->folder.'/kunden.csv';
        file_put_contents($file, "\xEF\xBB\xBFE-Mail;Name;Notiz\r\na@example.ch;Anna;\r\nb@example.ch;Beat;\"x\"\r\n");
        $columns = [
            new ColumnInfo('id', 'bigint', ColumnKind::Number, false, false, false, true, false),
            new ColumnInfo('email', 'varchar(255)', ColumnKind::Text, false, false, false, false, false),
            new ColumnInfo('name', 'varchar(255)', ColumnKind::Text, false, true, false, false, false),
        ];

        $analysis = app(CsvImporter::class)->analyse($file, $columns);

        $this->assertSame(';', $analysis['delimiter']);
        $this->assertSame('utf-8', $analysis['encoding']);
        $this->assertTrue($analysis['bom']);
        $this->assertTrue($analysis['header']);
        $this->assertSame(['email', 'name', null], $analysis['mapping']);
        $this->assertSame(2, $analysis['records']);
        $this->assertSame([null, 'x'], $analysis['columns'][2]['samples']);
    }

    public function test_analysis_reads_windows_1252_files(): void
    {
        $file = $this->folder.'/alt.csv';
        file_put_contents($file, "name,ort\r\n".mb_convert_encoding('Jürg,Zürich', 'Windows-1252', 'UTF-8')."\r\n");
        $columns = [new ColumnInfo('name', 'text', ColumnKind::Text, false, true, false, false, false)];

        $analysis = app(CsvImporter::class)->analyse($file, $columns);

        $this->assertSame('windows-1252', $analysis['encoding']);
        $this->assertSame(',', $analysis['delimiter']);
        $this->assertSame(['Jürg'], $analysis['columns'][0]['samples']);
    }

    /**
     * @return array{0: Connection, 1: DatabaseConnection, 2: string}
     */
    private function contacts(DatabaseDriver $driver): array
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $writer = app(ConnectionFactory::class)->make($settings);
        $writer->getSchemaBuilder()->create('kontakte', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name');
            $table->boolean('aktiv')->default(false);
            $table->binary('foto')->nullable();
            $table->text('bemerkung')->nullable();
        });

        return [$writer, $this->savedConnection($settings), $this->sampleSchemaName($driver)];
    }

    /**
     * @return array<string, mixed>
     */
    private function importOptions(string $schema): array
    {
        $names = ['email', 'name', 'aktiv', 'foto', 'bemerkung'];

        return [
            'schema' => $schema, 'table' => 'kontakte', 'delimiter' => ';', 'encoding' => 'utf-8', 'header' => true,
            'columns' => array_map(fn (string $name, int $index): array => ['index' => $index, 'name' => $name, 'samples' => []], $names, array_keys($names)),
            'mapping' => $names, 'on_error' => 'skip', 'analyze' => false,
        ];
    }

    private function file(): string
    {
        $file = $this->folder.'/kontakte.csv';
        file_put_contents($file, self::FILE);

        return $file;
    }
}
