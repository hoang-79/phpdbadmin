<?php

namespace Tests\Feature\Services\Shape;

use App\Enums\ColumnKind;
use App\Enums\DatabaseDriver;
use App\Services\Shape\ColumnShape;
use App\Services\Shape\LaravelColumn;
use App\Services\Shape\MigrationWriter;
use App\Services\Shape\ShapeComparer;
use App\Services\Shape\ShapeReader;
use App\Services\Shape\TableShape;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ShapeSamples;
use Tests\TestCase;

/**
 * Laravel-Migration aus einer Tabelle (S-09). Abnahme: Die erzeugte Migration legt dieselbe Tabelle auf einem
 * anderen Treiber an — Datei in einen Ordner schreiben, laden, `up()` gegen die Testdatenbank des anderen Treibers,
 * danach die Struktur mit dem Schemavergleich (S-08) prüfen.
 */
#[Group('target-databases')]
class MigrationWriterTest extends TestCase
{
    use ShapeSamples;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/migration-'.getmypid());
        @mkdir($this->folder.'/migrations', 0777, true);
        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'phpdbadmin.connections.sqlite_paths' => [$this->folder],
        ]);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('db.schema');
        $this->cleanUpShapes();
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: DatabaseDriver, 1: DatabaseDriver}>
     */
    public static function driverPairs(): array
    {
        return [
            'MySQL → PostgreSQL' => [DatabaseDriver::MySql, DatabaseDriver::Postgres],
            'PostgreSQL → MySQL' => [DatabaseDriver::Postgres, DatabaseDriver::MySql],
            'SQLite → MariaDB' => [DatabaseDriver::Sqlite, DatabaseDriver::MariaDb],
            'MariaDB → SQLite' => [DatabaseDriver::MariaDb, DatabaseDriver::Sqlite],
        ];
    }

    #[DataProvider('driverPairs')]
    public function test_generated_migration_creates_the_same_tables_on_another_driver(DatabaseDriver $sourceDriver, DatabaseDriver $targetDriver): void
    {
        [$source, $sourceSchema] = $this->firstSide($sourceDriver, $this->folder);
        [$target, $targetSchema] = $targetDriver === DatabaseDriver::Sqlite ? $this->secondSide($targetDriver, $this->folder) : $this->firstSide($targetDriver, $this->folder);
        $this->createNewState($source, $sourceSchema);

        $reader = app(ShapeReader::class);
        $tables = array_map(fn (string $name): TableShape => $reader->table($source, $sourceDriver, $sourceSchema, $name) ?? $this->fail('Tabelle fehlt: '.$name), ['kunden', 'bestellungen', 'artikel']);

        foreach ($tables as $table) {
            $this->runMigration($target, app(MigrationWriter::class)->write($table, $tables, $sourceDriver->label())['code']);
        }

        foreach ($tables as $table) {
            $migrated = $reader->table($target, $targetDriver, $targetSchema, $table->name);
            $this->assertNotNull($migrated, $table->name.' fehlt im Ziel');

            $difference = app(ShapeComparer::class)->table($table, $migrated);
            $this->assertNull($difference, $table->name.': '.json_encode($difference, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_writes_laravel_column_methods_and_shortcuts(): void
    {
        [$source, $schema] = $this->firstSide(DatabaseDriver::MySql, $this->folder);
        $this->createNewState($source, $schema);
        $source->getSchemaBuilder()->table('kunden', fn ($table) => $table->timestamps());

        $reader = app(ShapeReader::class);
        $customers = $reader->table($source, DatabaseDriver::MySql, $schema, 'kunden');
        $orders = $reader->table($source, DatabaseDriver::MySql, $schema, 'bestellungen');
        $this->assertNotNull($customers);
        $this->assertNotNull($orders);

        $code = app(MigrationWriter::class)->write($customers, [], 'MySQL')['code'];
        $this->assertStringContainsString("Schema::create('kunden', function (Blueprint \$table) {", $code);
        $this->assertStringContainsString('$table->id();', $code);
        $this->assertStringContainsString("\$table->string('email', 100);", $code);
        $this->assertStringContainsString("\$table->enum('status', ['aktiv', 'gesperrt'])->default('aktiv');", $code);
        $this->assertStringContainsString("\$table->decimal('saldo', 10, 2)->default(0);", $code);
        $this->assertStringContainsString("\$table->boolean('aktiv')->default(true);", $code);
        $this->assertStringContainsString("\$table->timestamp('erstellt')->useCurrent();", $code);
        $this->assertStringContainsString('$table->timestamps();', $code);
        $this->assertStringContainsString("\$table->unique('email');", $code);
        $this->assertStringContainsString("Schema::dropIfExists('kunden');", $code);

        $orderCode = app(MigrationWriter::class)->write($orders, [$customers], 'MySQL')['code'];
        $this->assertStringContainsString("\$table->unsignedBigInteger('kunde_id');", $orderCode);
        $this->assertStringContainsString("\$table->foreign('kunde_id')->references('id')->on('kunden')->cascadeOnDelete();", $orderCode);
        $this->assertStringNotContainsString("index('kunde_id')", $orderCode, 'den Index für den Fremdschlüssel legt MySQL selbst an');
    }

    public function test_names_and_checks_from_the_database_cannot_break_out_of_the_file(): void
    {
        $table = new TableShape(
            name: "x'); system('id'); //",
            driver: DatabaseDriver::Postgres,
            columns: [new ColumnShape("a'b", 'text', ColumnKind::Text, true, null, false, new LaravelColumn('text'), comment: "c'\n?><?php system('id');")],
            indexes: [],
            foreignKeys: [],
            checks: [['name' => "chk\n?>", 'definition' => "1 = 1\n?><?php system('id'); ?>"]],
        );

        $result = app(MigrationWriter::class)->write($table, [], "Quelle\n?>");
        $tokens = token_get_all($result['code']);

        $this->assertSame(1, count(array_filter($tokens, fn (mixed $token): bool => is_array($token) && $token[0] === T_OPEN_TAG)), 'genau ein <?php');
        $this->assertSame([], array_filter($tokens, fn (mixed $token): bool => is_array($token) && in_array($token[0], [T_CLOSE_TAG, T_INLINE_HTML], true)));
        $this->assertSame(0, preg_match('/^\s*system\(/m', $result['code']));
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_create_x_system_id_table\.php$/', $result['fileName']);
    }

    /**
     * Migration wie in einem Projekt: Datei schreiben, laden, `up()` — mit dem Schema-Builder der Zielverbindung.
     */
    private function runMigration(Connection $target, string $code): void
    {
        $file = $this->folder.'/migrations/'.uniqid('m', true).'.php';
        file_put_contents($file, $code);

        app()->instance('db.schema', $target->getSchemaBuilder());

        $migration = include $file;
        $this->assertInstanceOf(Migration::class, $migration);
        $migration->up();
    }
}
