<?php

/*
 * Feasibility F-3 und F-4 (dokumentationen/02-feasibility): Probeseiten für Raster und SQL-Editor.
 * Nur in der lokalen Umgebung geladen (routes/web.php); werden in TB 00 übernommen oder entfernt.
 */

use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

Route::get('probe/raster', function () use ($probeConnection): Response {
    $connection = $probeConnection();
    $estimate = (int) strtok((string) $connection->selectOne("SELECT stat FROM sqlite_stat1 WHERE tbl = 'orders' LIMIT 1")?->stat, ' ');

    return Inertia::render('probe/Raster', [
        'columns' => array_column($connection->getSchemaBuilder()->getColumns('orders'), 'name'),
        'estimate' => $estimate,
    ]);
})->name('probe.raster');

Route::get('probe/raster/rows', function (Request $request) use ($probeConnection): JsonResponse {
    $after = max(0, (int) $request->query('after', '0'));
    $start = hrtime(true);
    $rows = $probeConnection()->table('orders')->where('id', '>', $after)->orderBy('id')->limit(200)->get();

    return response()->json([
        'rows' => $rows,
        'server_ms' => round((hrtime(true) - $start) / 1e6, 1),
    ]);
})->name('probe.raster.rows');
