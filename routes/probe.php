<?php

/*
 * Feasibility F-4 (dokumentationen/02-feasibility): Probeseite für den SQL-Editor, bis TB 03 ihn ersetzt.
 * Nur in der lokalen Umgebung geladen (routes/web.php). Die Raster-Probe (F-3) ersetzt seit TB 02 das echte Raster.
 */

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

$probeConnection = function (): Connection {
    $file = storage_path('framework/testing/probe-ui.sqlite');
    if (! is_file($file)) {
        @mkdir(dirname($file), 0755, true);
        touch($file);
    }

    /** @var Connection $connection */
    $connection = DB::build(['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true]);

    if (! $connection->getSchemaBuilder()->hasTable('orders')) {
        $connection->unprepared(<<<'SQL'
            CREATE TABLE customers (id INTEGER PRIMARY KEY, name VARCHAR(80) NOT NULL, email VARCHAR(120) NOT NULL UNIQUE, city VARCHAR(60));
            CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL REFERENCES customers (id), status VARCHAR(20) NOT NULL,
                total NUMERIC NOT NULL, note VARCHAR(120), created_at DATETIME NOT NULL);
            CREATE INDEX orders_created_at_index ON orders (created_at);
            WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < 1000)
                INSERT INTO customers (id, name, email, city) SELECT x, 'Kundin ' || x, 'kundin' || x || '@example.ch', 'Ort ' || (x % 50) FROM n;
            WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < 1000000)
                INSERT INTO orders (id, customer_id, status, total, note, created_at)
                SELECT x, 1 + x % 1000, CASE x % 3 WHEN 0 THEN 'offen' WHEN 1 THEN 'bezahlt' ELSE 'storniert' END,
                       (x % 100000) / 100.0, CASE WHEN x % 7 = 0 THEN 'Bitte Rückruf' END,
                       datetime('2026-01-01 00:00:00', '+' || x || ' minutes') FROM n;
            ANALYZE;
            SQL);
    }

    return $connection;
};

Route::get('probe/editor', function () use ($probeConnection): Response {
    $schema = $probeConnection()->getSchemaBuilder();
    $tables = [];
    foreach (['customers', 'orders'] as $table) {
        $tables[$table] = array_column($schema->getColumns($table), 'name');
    }
    // Zusätzlich 1'000 erfundene Tabellen: Vervollständigung mit einem grossen Schema prüfen.
    for ($i = 1; $i <= 1000; $i++) {
        $tables[sprintf('probe_t%04d', $i)] = ['id', 'name', 'created_at'];
    }

    return Inertia::render('probe/Editor', ['schema' => $tables]);
})->name('probe.editor');
