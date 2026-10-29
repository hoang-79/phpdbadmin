<?php

namespace Tests\Feature\Services\Data;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Models\AuditEntry;
use App\Models\ChangeSnapshot;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Data\ChangeRejected;
use App\Services\Data\RowEditor;
use App\Services\Data\SearchReplace;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Suchen und Ersetzen in einer Tabelle (D-08) je Treiber: Vorschau schreibt nichts, Anwenden in einer Transaktion mit
 * einem Eintrag im Protokoll und Rückgängig; `%`, `_`, `!` und `\` wörtlich (LIKE-Escape), Umlaute und Emoji, Gross-
 * und Kleinschreibung, ganzer Wert; ungültiges JSON und zu viele Zeilen ändern nichts.
 */
#[Group('target-databases')]
class SearchReplaceTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private const array TITLES = [
        1 => 'Rabatt 50% auf alles',
        2 => 'Rabatt 500 Franken',
        3 => 'snake_case_name',
        4 => 'snakeXcaseXname',
        5 => 'C:\\Pfad\\alt',
        6 => 'Jürg MÜLLER',
        7 => 'Jürg Müller',
        8 => 'Jurg Muller',
        9 => 'Gruss 🙂 und 🙂',
        10 => 'Achtung!% wörtlich',
    ];

    private string $folder;

    private Connection $writer;

    private DatabaseConnection $connection;

    private User $user;

    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/ersetzen-'.getmypid());
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
    public function test_preview_counts_rows_and_shows_samples_without_writing(DatabaseDriver $driver): void
    {
        $this->texts($driver);

        $preview = $this->replacer()->preview($this->connection, null, $this->schema, 'texte', $this->replacement('müller', 'Meier', ['title', 'body']));

        $this->assertSame([2, 3], [$preview['rows'], $preview['cells']], 'MÜLLER und Müller, nicht Muller; Zeile 7 in zwei Spalten');
        $this->assertSame(['key' => ['6'], 'column' => 'title', 'before' => 'Jürg MÜLLER', 'after' => 'Jürg Meier'], $preview['samples'][0]);
        $this->assertTrue($preview['undoable']);
        $this->assertFalse($preview['tooMany']);
        $this->assertStringContainsStringIgnoringCase('update', $preview['sql']);
        $this->assertStringNotContainsString('Meier', $preview['sql']);
        $this->assertSame(self::TITLES, $this->titles(), 'Vorschau schreibt nichts');
    }

    #[DataProvider('drivers')]
    public function test_apply_writes_in_one_transaction_logs_once_and_undoes(DatabaseDriver $driver): void
    {
        $this->texts($driver);
        $before = $this->rows();

        $result = $this->replacer()->apply($this->connection, $this->user, null, $this->schema, 'texte', $this->replacement('müller', 'Meier', ['title', 'body']));

        $this->assertSame([2, 3, true], [$result['rows'], $result['cells'], $result['undoable']]);
        $this->assertSame('Jürg Meier', $this->titles()[6]);
        $this->assertSame('Jürg Meier', $this->titles()[7]);
        $this->assertSame('Jurg Muller', $this->titles()[8]);
        $this->assertSame('bei Meier zu Hause', $this->writer->table('texte')->where('id', 7)->value('body'));

        $entries = AuditEntry::query()->where('action', 'data.replace')->get();
        $this->assertCount(1, $entries);
        $this->assertSame(2, $entries[0]->affected_rows);
        $this->assertStringNotContainsString('Meier', (string) $entries[0]->sql.json_encode($entries[0]->context));
        $this->assertStringNotContainsString('müller', (string) $entries[0]->sql.json_encode($entries[0]->context));
        $this->assertSame(2, ChangeSnapshot::query()->where('audit_log_id', $result['auditId'])->count());

        app(RowEditor::class)->undo($this->connection, $this->user, $result['auditId']);
        $this->assertEquals($before, $this->rows());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: bool, 4: array<int, string>}>
     */
    public static function literalCases(): array
    {
        return [
            'Prozent' => ['50%', '60%', false, false, [1 => 'Rabatt 60% auf alles']],
            'Unterstrich' => ['_case_', '-case-', true, false, [3 => 'snake-case-name']],
            'Backslash' => ['\\Pfad\\', '\\Ordner\\', true, false, [5 => 'C:\\Ordner\\alt']],
            'Ausrufezeichen (Escape-Zeichen)' => ['!%', '?', false, false, [10 => 'Achtung? wörtlich']],
            'Emoji, jedes Vorkommen' => ['🙂', '😀', false, false, [9 => 'Gruss 😀 und 😀']],
            'Gross- und Kleinschreibung beachtet' => ['Müller', 'Meier', true, false, [7 => 'Jürg Meier']],
            'ganzer Wert, beachtet' => ['Jürg Müller', 'J. M.', true, true, [7 => 'J. M.']],
            'ganzer Wert, nicht beachtet' => ['jürg müller', 'J. M.', false, true, [6 => 'J. M.', 7 => 'J. M.']],
            'leerer Ersatz' => ['Rabatt ', '', true, false, [1 => '50% auf alles', 2 => '500 Franken']],
        ];
    }

    #[DataProvider('drivers')]
    public function test_special_characters_are_literal_and_matching_follows_the_options(DatabaseDriver $driver): void
    {
        $this->texts($driver);

        foreach (self::literalCases() as $case => [$search, $replace, $matchCase, $wholeValue, $changed]) {
            $this->writer->table('texte')->delete();
            $this->fill();

            $this->replacer()->apply($this->connection, $this->user, null, $this->schema, 'texte', $this->replacement($search, $replace, ['title'], $matchCase, $wholeValue));

            $this->assertSame(array_replace(self::TITLES, $changed), $this->titles(), $case);
        }
    }

    #[DataProvider('drivers')]
    public function test_invalid_json_and_too_many_rows_change_nothing(DatabaseDriver $driver): void
    {
        $this->texts($driver);
        $before = $this->rows();

        // SQLite kennt keinen JSON-Typ — dort ist die Spalte Text und wird nicht geprüft.
        if ($driver !== DatabaseDriver::Sqlite) {
            try {
                $this->replacer()->apply($this->connection, $this->user, null, $this->schema, 'texte', $this->replacement('"tag"', '"tag', ['body', 'meta']));
                $this->fail('ungültiges JSON muss abgewiesen werden');
            } catch (ChangeRejected $rejected) {
                $this->assertStringContainsString('kein gültiges JSON', $rejected->getMessage());
            }
        }

        config(['phpdbadmin.data.undo_rows' => 1]);

        try {
            $this->replacer()->apply($this->connection, $this->user, null, $this->schema, 'texte', $this->replacement('Rabatt', 'Aktion', ['title']));
            $this->fail('über der Grenze für Rückgängig nur mit Bestätigung');
        } catch (ChangeRejected $rejected) {
            $this->assertStringContainsString('nicht rückgängig', $rejected->getMessage());
        }

        $this->assertEquals($before, $this->rows(), 'zurückgerollt');
        $this->assertSame(0, AuditEntry::query()->where('action', 'data.replace')->count());

        $result = $this->replacer()->apply($this->connection, $this->user, null, $this->schema, 'texte', $this->replacement('Rabatt', 'Aktion', ['title']), withoutUndo: true);
        $this->assertSame([2, false], [$result['rows'], $result['undoable']]);
        $this->assertSame(0, ChangeSnapshot::query()->count());

        config(['phpdbadmin.data.replace_rows' => 1]);
        $this->assertTrue($this->replacer()->preview($this->connection, null, $this->schema, 'texte', $this->replacement('Aktion', 'Rabatt', ['title']))['tooMany']);
    }

    #[DataProvider('drivers')]
    public function test_only_text_columns_outside_the_key_of_tables_with_a_key(DatabaseDriver $driver): void
    {
        $this->texts($driver);

        $this->assertSame(['title', 'body', 'meta'], array_column($this->replacer()->columns($this->connection, null, $this->schema, 'texte'), 'name'));

        foreach ([['texte', ['id']], ['texte', ['gibt_es_nicht']], ['ohne_schluessel', ['eintrag']]] as [$table, $columns]) {
            try {
                $this->replacer()->preview($this->connection, null, $this->schema, $table, $this->replacement('1', '2', $columns));
                $this->fail($table.' '.implode(',', $columns));
            } catch (ChangeRejected) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function replacer(): SearchReplace
    {
        return app(SearchReplace::class);
    }

    /**
     * @param  list<string>  $columns
     * @return array{columns: list<string>, search: string, replace: string, matchCase: bool, wholeValue: bool}
     */
    private function replacement(string $search, string $replace, array $columns, bool $matchCase = false, bool $wholeValue = false): array
    {
        return ['columns' => $columns, 'search' => $search, 'replace' => $replace, 'matchCase' => $matchCase, 'wholeValue' => $wholeValue];
    }

    private function texts(DatabaseDriver $driver): void
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $this->writer = app(ConnectionFactory::class)->make($settings);
        $this->connection = $this->savedConnection($settings, $this->user);
        $this->schema = $this->sampleSchemaName($driver);

        $schema = $this->writer->getSchemaBuilder();
        $schema->create('texte', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->integer('zahl')->default(0);
        });
        $schema->create('ohne_schluessel', function (Blueprint $table) {
            $table->string('eintrag');
        });

        $this->fill();
    }

    private function fill(): void
    {
        foreach (self::TITLES as $id => $title) {
            $this->writer->table('texte')->insert([
                'id' => $id,
                'title' => $title,
                'body' => match ($id) {
                    7 => 'bei Müller zu Hause',
                    1 => 'Text mit "tag" drin',
                    default => null,
                },
                'meta' => $id === 1 ? '{"tag": 1}' : null,
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function titles(): array
    {
        return $this->writer->table('texte')->orderBy('id')->pluck('title', 'id')->map(fn (mixed $title): string => (string) $title)->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->writer->table('texte')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }
}
