<?php

namespace App\Services\Sql\Explain;

use App\Connections\TargetConnections;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Services\Sql\StatementClassifier;
use App\Services\Sql\StatementKind;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use PDO;
use Throwable;

/**
 * EXPLAIN als Baum mit Hinweisen (Q-06): die Anweisung unter der Schreibmarke, je Treiber im eigenen Format —
 * MySQL `EXPLAIN FORMAT=JSON` (ab 8.3 als Baum aus Operationen), MariaDB ebenso mit eigenem JSON, PostgreSQL
 * `EXPLAIN (FORMAT JSON, VERBOSE)`, SQLite `EXPLAIN QUERY PLAN` — und als einheitlicher Baum zurück.
 *
 * Ohne ANALYZE als Vorgabe: dann führt der Server nichts aus. ANALYZE führt die Anweisung wirklich aus und gibt es nur
 * für lesende Anweisungen, im Schreibschutz-Wächter und mit der Zeitgrenze des Editors. Erklärt werden SELECT, WITH,
 * TABLE und VALUES sowie INSERT, UPDATE und DELETE; gefährliche Anweisungen (DELETE und UPDATE ohne WHERE) nicht.
 *
 * MySQL und MariaDB lehnen EXPLAIN einer Änderung in einer Nur-lesen-Transaktion ab — dort braucht es dafür das Recht
 * «schreiben» und eine beschreibbare Verbindung, wie beim Ausführen.
 *
 * @phpstan-import-type PlanNode from PlanReader
 * @phpstan-import-type PlanHint from PlanReader
 *
 * @phpstan-type Explanation array{sql: string, analyzed: bool, analyzable: bool, plan: PlanNode, hints: list<PlanHint>, raw: string, durationMs: float}
 */
final class Explainer
{
    private const array READS = ['SELECT', 'WITH', 'TABLE', 'VALUES'];

    private const array CHANGES = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'MERGE'];

    public function __construct(private readonly TargetConnections $targets, private readonly StatementClassifier $classifier) {}

    /**
     * @param  bool  $mayWrite  Recht «schreiben» und eine beschreibbare Verbindung (für EXPLAIN von Änderungen bei MySQL/MariaDB)
     * @param  string|null  $searchPath  Schema für unqualifizierte Namen (PostgreSQL)
     * @return Explanation
     *
     * @throws ExplainRejected
     */
    public function explain(DatabaseConnection $connection, ?string $database, string $sql, bool $analyze, bool $mayWrite, ?string $searchPath = null): array
    {
        $driver = $connection->driver;
        $first = $this->firstWord($sql);
        $kind = $this->classifier->kind($sql);
        $isRead = $kind === StatementKind::Read && in_array($first, self::READS, true);
        $isChange = in_array($first, self::CHANGES, true) || ($first === 'WITH' && $kind === StatementKind::Write);

        if (in_array($first, ['EXPLAIN', 'ANALYZE', 'DESCRIBE', 'DESC'], true)) {
            throw new ExplainRejected($this->alreadyExplainText());
        }

        if (! $isRead && ! $isChange) {
            throw new ExplainRejected($this->onlyText());
        }

        $danger = $this->classifier->danger($sql);

        if ($danger !== null) {
            throw new ExplainRejected($this->dangerText($danger));
        }

        if ($analyze && ! $isRead) {
            throw new ExplainRejected($this->analyzeReadText());
        }

        if ($analyze && $driver === DatabaseDriver::Sqlite) {
            throw new ExplainRejected($this->sqliteAnalyzeText());
        }

        $isMySql = in_array($driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true);

        if ($isMySql && $isChange && ! $mayWrite) {
            throw new ExplainRejected($this->changeNeedsWriteText());
        }

        $target = $this->targets->open($connection, $database);
        $dialect = $driver->dialect();
        $dialect->setStatementTimeout($target, (int) config('phpdbadmin.editor.timeout_seconds', 30) * 1000);
        $treeFormat = false;

        if ($driver === DatabaseDriver::MySql) {
            $version = $dialect->serverVersion($target);
            $treeFormat = version_compare($version, '8.3', '>=');

            if ($analyze && ! $treeFormat) {
                throw new ExplainRejected($this->mysqlAnalyzeText($version));
            }
        }

        if ($searchPath !== null && $driver === DatabaseDriver::Postgres) {
            // Unqualifizierte Namen im gewählten Schema suchen; gequotet, weil der Name von aussen kommt.
            $target->statement('set search_path to "'.str_replace('"', '""', $searchPath).'", public');
        }

        $explain = match ($driver) {
            DatabaseDriver::MySql => ($analyze ? 'EXPLAIN ANALYZE FORMAT=JSON ' : 'EXPLAIN FORMAT=JSON ').$sql,
            DatabaseDriver::MariaDb => ($analyze ? 'ANALYZE FORMAT=JSON ' : 'EXPLAIN FORMAT=JSON ').$sql,
            DatabaseDriver::Postgres => ($analyze ? 'EXPLAIN (ANALYZE, FORMAT JSON, VERBOSE) ' : 'EXPLAIN (FORMAT JSON, VERBOSE) ').$sql,
            DatabaseDriver::Sqlite => 'EXPLAIN QUERY PLAN '.$sql,
        };
        $start = hrtime(true);

        try {
            if ($treeFormat) {
                $target->statement('set session explain_json_format_version = 2');
            }

            $rows = $isMySql && $isChange
                ? $this->asChange($target, $explain)
                : $dialect->runReadOnly($target, fn (): array => $this->fetch($target, $explain));
        } catch (Throwable $error) {
            throw new ExplainRejected($this->failedText($dialect->isTimeout($error)
                ? __('Abgebrochen nach :seconds s (Zeitgrenze).', ['seconds' => (int) config('phpdbadmin.editor.timeout_seconds', 30)])
                : ($error instanceof QueryException ? ($error->getPrevious() ?? $error) : $error)->getMessage()), fromServer: true);
        }

        $durationMs = round((hrtime(true) - $start) / 1e6, 1);

        if ($driver === DatabaseDriver::Sqlite) {
            $plan = array_map(fn (array $row): array => ['id' => (int) ($row['id'] ?? 0), 'parent' => (int) ($row['parent'] ?? 0), 'detail' => (string) ($row['detail'] ?? '')], $rows);
            $tree = (new SqlitePlanReader)->read($plan);
            $raw = $this->sqliteText($plan);
        } else {
            $json = json_decode((string) (array_values($rows[0] ?? [])[0] ?? ''), true);
            $json = is_array($json) ? $json : [];
            $tree = $driver === DatabaseDriver::Postgres
                ? (new PostgresPlanReader(fn (?string $schema, string $table): ?float => $this->tableRows($target, $schema, $table)))->read($json, $analyze)
                : (new MySqlPlanReader)->read($json, $analyze);
            $raw = (string) json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return [
            'sql' => $sql,
            'analyzed' => $analyze,
            'analyzable' => $isRead && $driver !== DatabaseDriver::Sqlite && ($driver !== DatabaseDriver::MySql || $treeFormat),
            'plan' => $tree['plan'],
            'hints' => $tree['hints'],
            'raw' => $raw,
            'durationMs' => $durationMs,
        ];
    }

    /**
     * MySQL/MariaDB: EXPLAIN einer Änderung ausserhalb der Nur-lesen-Transaktion — ohne ANALYZE führt der Server sie
     * nicht aus. Der Wächter setzt die Sitzung bei jeder lesenden Anweisung auf «read only» — für diese zurück.
     *
     * @return list<array<string, mixed>>
     */
    private function asChange(Connection $target, string $explain): array
    {
        $target->statement('set session transaction read write');

        return $this->fetch($target, $explain);
    }

    /**
     * Die Anweisung ist das EXPLAIN-Präfix aus diesem Dienst plus die eine Anweisung, die die Person erklären lässt —
     * wie im SQL-Editor über PDO, ohne Platzhalter.
     *
     * @return list<array<string, mixed>>
     */
    private function fetch(Connection $target, string $explain): array
    {
        $statement = $target->getPdo()->query($explain);

        if ($statement === false) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /** Geschätzte Zeilen einer Tabelle aus der Statistik (PostgreSQL); null, wenn noch nie gezählt. */
    private function tableRows(Connection $target, ?string $schema, string $table): ?float
    {
        try {
            $rows = $target->selectOne(
                'select c.reltuples as estimate from pg_class c join pg_namespace n on n.oid = c.relnamespace
                 where c.relname = ? and n.nspname = coalesce(?, current_schema())',
                [$table, $schema],
            )?->estimate;
        } catch (Throwable) {
            return null;
        }

        return is_numeric($rows) && (float) $rows >= 0 ? (float) $rows : null;
    }

    /**
     * Wie die Kommandozeile von SQLite: eingerückt nach Ebene.
     *
     * @param  list<array{id: int, parent: int, detail: string}>  $rows
     */
    private function sqliteText(array $rows, int $parent = 0, int $depth = 0): string
    {
        $lines = $depth === 0 ? ['QUERY PLAN'] : [];

        foreach ($rows as $row) {
            if ($row['parent'] === $parent && $row['id'] !== $parent) {
                $lines[] = str_repeat('   ', $depth).'`--'.$row['detail'];
                $nested = $this->sqliteText($rows, $row['id'], $depth + 1);

                if ($nested !== '') {
                    $lines[] = $nested;
                }
            }
        }

        return implode("\n", $lines);
    }

    private function firstWord(string $sql): string
    {
        $plain = (string) preg_replace(['~/\*.*?\*/~s', '~--[^\n]*~', '~#[^\n]*~'], ' ', $sql);

        return preg_match('/^\s*\(*\s*([A-Za-z]+)/', $plain, $match) === 1 ? strtoupper($match[1]) : '';
    }

    private function alreadyExplainText(): string
    {
        return __('Bitte ohne EXPLAIN davor — phpdbadmin setzt es selbst.');
    }

    private function onlyText(): string
    {
        return __('Erklären lassen sich SELECT, WITH, TABLE und VALUES sowie INSERT, UPDATE und DELETE.');
    }

    private function dangerText(string $reason): string
    {
        return __('Gefährliche Anweisung — phpdbadmin erklärt sie nicht: :reason', ['reason' => $reason]);
    }

    private function analyzeReadText(): string
    {
        return __('ANALYZE führt die Anweisung wirklich aus — das gibt es nur für lesende Anweisungen.');
    }

    private function sqliteAnalyzeText(): string
    {
        return __('SQLite misst keine Laufzeit je Schritt — es gibt nur den Plan.');
    }

    private function mysqlAnalyzeText(string $version): string
    {
        return __('ANALYZE als JSON gibt es bei MySQL erst ab 8.3 (hier :version).', ['version' => $version]);
    }

    private function changeNeedsWriteText(): string
    {
        return __('EXPLAIN von INSERT, UPDATE und DELETE prüfen MySQL und MariaDB wie eine Änderung — das braucht das Recht «schreiben» und eine beschreibbare Verbindung.');
    }

    private function failedText(string $message): string
    {
        return __('Der Server konnte die Anweisung nicht erklären: :message', ['message' => $message]);
    }
}
