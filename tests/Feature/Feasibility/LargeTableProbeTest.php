<?php

namespace Tests\Feature\Feasibility;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Pdo\Mysql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * F-3 Raster und F-8 Export: eine Million Zeilen je Treiber, 1'000 Tabellen im Katalog.
 *
 * Schwer — läuft nur mit PROBE_GROSS=1 (sonst ausdrücklich übersprungen):
 * PROBE_GROSS=1 php vendor/bin/phpunit tests/Feature/Feasibility/LargeTableProbeTest.php
 */
#[Group('feasibility')]
#[Group('feasibility-large')]
class LargeTableProbeTest extends ProbeTestCase
{
    private const ROWS = 1_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('PROBE_GROSS') !== '1') {
            $this->markTestSkipped('Schwere Probe: nur mit PROBE_GROSS=1');
        }
    }

    #[DataProvider('drivers')]
    public function test_million_row_table_shows_first_and_deep_keyset_page_under_one_second(string $driver): void
    {
        $connection = $this->freshConnection($driver);
        [, $fillMs] = $this->measure(fn () => $this->fillMillionRows($connection, $driver));
        $this->assertSame(self::ROWS, (int) $connection->table('probe_big')->count());

        $queries = [
            'erste_seite' => 'SELECT * FROM probe_big ORDER BY id LIMIT 100',
            'schluessel_tief' => 'SELECT * FROM probe_big WHERE id > 900000 ORDER BY id LIMIT 100',
            'versatz_tief' => 'SELECT * FROM probe_big ORDER BY id LIMIT 100 OFFSET 900000',
            'sortiert_index_absteigend' => 'SELECT * FROM probe_big ORDER BY created_at DESC LIMIT 100',
            'sortiert_ohne_index' => 'SELECT * FROM probe_big ORDER BY label LIMIT 100',
            'suche_like' => "SELECT * FROM probe_big WHERE label LIKE '%99999%' LIMIT 100",
            'zaehlen_genau' => 'SELECT COUNT(*) AS c FROM probe_big',
        ];

        $timings = [];
        foreach ($queries as $name => $sql) {
            $timings[$name] = $this->median(fn () => $connection->select($sql));
        }

        [$estimate, $estimateMs] = $this->measure(fn () => $this->estimateRows($connection, $driver));

        $this->recordFinding('f3-raster', $driver, [
            'fuellen_ms' => $fillMs,
            'abfragen_median_ms' => $timings,
            'schaetzung' => $estimate,
            'schaetzung_ms' => $estimateMs,
        ]);

        $this->assertLessThan(1000, $timings['erste_seite']);
        $this->assertLessThan(1000, $timings['schluessel_tief']);
        $this->assertLessThan(1000, $timings['sortiert_index_absteigend']);
    }

    #[DataProvider('drivers')]
    public function test_million_row_export_streams_with_bounded_memory(string $driver): void
    {
        $connection = $this->freshConnection($driver);
        $this->fillMillionRows($connection, $driver);

        $results = [
            'lazy_by_id_5000' => $this->exportCsv(fn () => $connection->table('probe_big')->lazyById(5000)),
            'cursor' => $this->exportCsv(fn () => $connection->table('probe_big')->orderBy('id')->cursor()),
        ];

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $unbuffered = $this->connection($driver, ['options' => [Mysql::ATTR_USE_BUFFERED_QUERY => false]]);
            $results['cursor_ungepuffert'] = $this->exportCsv(fn () => $unbuffered->table('probe_big')->orderBy('id')->cursor());
        }

        [, $importMs] = $this->measure(fn () => $this->importHundredThousandRows($connection));

        $this->recordFinding('f8-export', $driver, $results + ['import_100k_in_1000er_paketen_ms' => $importMs]);

        $this->assertSame(self::ROWS, $results['lazy_by_id_5000']['zeilen']);
        $this->assertLessThan(64, $results['lazy_by_id_5000']['speicher_mb'], 'Export in Paketen braucht zu viel Speicher');
    }

    #[DataProvider('drivers')]
    public function test_catalog_with_thousand_tables_lists_under_one_second(string $driver): void
    {
        $connection = $this->freshConnection($driver);

        [, $createMs] = $this->measure(function () use ($connection) {
            for ($i = 1; $i <= 1000; $i++) {
                $connection->statement(sprintf('CREATE TABLE probe_t%04d (id INTEGER PRIMARY KEY, name VARCHAR(20))', $i));
            }
        });

        $schema = $connection->getSchemaBuilder();
        $own = match ($driver) {
            'mysql', 'mariadb' => self::PROBE_DATABASE,
            'pgsql' => 'public',
            'sqlite' => 'main',
        };
        $tablesMs = $this->median(fn () => $schema->getTables($own));
        $listingMs = $this->median(fn () => $schema->getTableListing($own));
        $columnsMs = $this->median(fn () => $schema->getColumns('probe_t0500'));

        $this->recordFinding('f3-katalog-1000', $driver, [
            'anlegen_ms' => $createMs,
            'getTables_ms' => $tablesMs,
            'getTableListing_ms' => $listingMs,
            'getColumns_einzeln_ms' => $columnsMs,
            // Ohne Schemaangabe liefert MariaDB die Tabellen aller Datenbanken des Servers.
            'ohne_schema_anzahl' => count($schema->getTableListing()),
        ]);

        $this->assertCount(1000, $schema->getTableListing($own));
        $this->assertLessThan(1000, $tablesMs);
    }

    private function fillMillionRows(Connection $connection, string $driver): void
    {
        $schema = $connection->getSchemaBuilder();
        $schema->create('probe_digits', fn (Blueprint $table) => $table->integer('d'));
        $connection->table('probe_digits')->insert(array_map(fn (int $d) => ['d' => $d], range(0, 9)));

        $schema->create('probe_big', function (Blueprint $table) {
            $table->id();
            $table->integer('category');
            $table->string('label', 60);
            $table->decimal('amount', 10, 2);
            $table->dateTime('created_at');
            $table->index('created_at');
        });

        [$label, $timestamp] = match ($driver) {
            'mysql', 'mariadb' => ["CONCAT('Eintrag ', n)", "DATE_ADD('2026-01-01 00:00:00', INTERVAL n SECOND)"],
            'pgsql' => ["'Eintrag ' || n", "TIMESTAMP '2026-01-01 00:00:00' + n * INTERVAL '1 second'"],
            'sqlite' => ["'Eintrag ' || n", "datetime('2026-01-01 00:00:00', '+' || n || ' seconds')"],
        };

        $connection->statement(
            "INSERT INTO probe_big (category, label, amount, created_at)
             SELECT n % 100, {$label}, (n % 10000) / 100.0, {$timestamp}
             FROM (SELECT a.d + 10 * b.d + 100 * c.d + 1000 * e.d + 10000 * f.d + 100000 * g.d AS n
                   FROM probe_digits a CROSS JOIN probe_digits b CROSS JOIN probe_digits c
                   CROSS JOIN probe_digits e CROSS JOIN probe_digits f CROSS JOIN probe_digits g) numbers"
        );
    }

    /**
     * @return array{zeilen: int, dauer_ms: float, speicher_mb: float}
     */
    private function exportCsv(callable $rows): array
    {
        $file = storage_path('framework/testing/probe-export-'.getmypid().'.csv');
        $handle = fopen($file, 'w');
        $baseline = memory_get_usage();
        memory_reset_peak_usage();
        $count = 0;

        $start = hrtime(true);
        foreach ($rows() as $row) {
            fputcsv($handle, (array) $row, escape: '');
            $count++;
        }
        $elapsedMs = round((hrtime(true) - $start) / 1e6, 1);

        $peakMb = round((memory_get_peak_usage() - $baseline) / 1048576, 1);
        fclose($handle);
        @unlink($file);

        return ['zeilen' => $count, 'dauer_ms' => $elapsedMs, 'speicher_mb' => $peakMb];
    }

    private function importHundredThousandRows(Connection $connection): void
    {
        $connection->getSchemaBuilder()->create('probe_import', function (Blueprint $table) {
            $table->id();
            $table->string('label', 60);
            $table->integer('amount');
        });

        $connection->transaction(function () use ($connection) {
            for ($batch = 0; $batch < 100; $batch++) {
                $rows = [];
                for ($i = 0; $i < 1000; $i++) {
                    $rows[] = ['label' => 'Import '.($batch * 1000 + $i), 'amount' => $i];
                }
                $connection->table('probe_import')->insert($rows);
            }
        });
    }

    private function estimateRows(Connection $connection, string $driver): ?int
    {
        return match ($driver) {
            'mysql', 'mariadb' => (function () use ($connection) {
                $connection->statement('ANALYZE TABLE probe_big');

                return (int) $connection->selectOne(
                    'SELECT TABLE_ROWS AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                    ['probe_big'],
                )->n;
            })(),
            'pgsql' => (function () use ($connection) {
                $connection->statement('ANALYZE probe_big');

                return (int) $connection->selectOne("SELECT reltuples::bigint AS n FROM pg_class WHERE oid = 'probe_big'::regclass")->n;
            })(),
            'sqlite' => (function () use ($connection) {
                $connection->statement('ANALYZE probe_big');
                $stat = $connection->selectOne("SELECT stat FROM sqlite_stat1 WHERE tbl = 'probe_big' LIMIT 1");

                return $stat === null ? null : (int) strtok($stat->stat, ' ');
            })(),
        };
    }

    private function median(callable $callback): float
    {
        $times = [];
        for ($i = 0; $i < 3; $i++) {
            [, $times[]] = $this->measure($callback);
        }
        sort($times);

        return $times[1];
    }
}
