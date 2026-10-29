<?php

namespace App\Services\Transfer;

use App\Connections\TargetConnections;
use App\Enums\ColumnKind;
use App\Enums\DatabaseDriver;
use App\Models\Transfer;
use App\Support\TrustedSql;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * CSV in eine Tabelle (E-02, Wireframe 11): Kopfzeile erkennen, Spalten nach Namen zuordnen, Probelauf, der nichts
 * schreibt (alle Zeilen in einer Transaktion einfügen, dann zurückrollen), Fehlerzeilen mit Zeilennummer und Grund.
 * Eingefügt wird in Paketen zu höchstens 1'000 Zeilen; scheitert ein Paket, findet ein Einzellauf mit Sicherungspunkten
 * die schuldigen Zeilen. Werte werden immer gebunden — auch solche, die wie SQL aussehen.
 *
 * @phpstan-import-type TransferMessage from Transfer
 *
 * @phpstan-type FileColumn array{index: int, name: string, samples: list<string|null>}
 * @phpstan-type RowError array{line: int, message: TransferMessage}
 * @phpstan-type CsvResult array{records: int, ready: int, imported: int, error_count: int, errors: list<RowError>, checked_only: bool}
 */
final class CsvImporter
{
    /** So viele Fehlerzeilen stehen mit Grund im Ergebnis; gezählt werden alle. */
    public const int ERROR_DETAILS = 100;

    private const int MAX_ROWS_PER_BATCH = 1000;

    /** Gebundene Werte je Anweisung — unter den Grenzen von MySQL (65'535) und SQLite (32'766). */
    private const int MAX_BINDINGS = 30_000;

    private const array TRUE_WORDS = ['1', 'true', 't', 'yes', 'y', 'ja', 'j', 'wahr', 'on', 'x'];

    private const array FALSE_WORDS = ['0', 'false', 'f', 'no', 'n', 'nein', 'falsch', 'off'];

    /** @var list<RowError> */
    private array $errors = [];

    private int $errorCount = 0;

    public function __construct(private readonly TargetConnections $targets) {}

    /**
     * Was die Datei verrät, und Vorschläge für die Zuordnung (gleicher Name, ohne Gross-/Kleinschreibung und Zeichen
     * wie «_» oder Leerzeichen). Eigene Angaben (Trennzeichen, Kodierung, Kopfzeile) gehen vor.
     *
     * @param  list<ColumnInfo>  $targetColumns
     * @return array{delimiter: string, encoding: string, bom: bool, header: bool, columns: list<FileColumn>, mapping: list<string|null>, records: int|null}
     */
    public function analyse(string $path, array $targetColumns, ?string $delimiter = null, ?string $encoding = null, ?bool $header = null): array
    {
        $sniffed = CsvReader::sniff($path);
        $delimiter ??= $sniffed['delimiter'];
        $encoding ??= $sniffed['encoding'];
        $reader = new CsvReader($path, $delimiter, $encoding);

        $first = [];
        $samples = [];
        $records = 0;
        $countAll = filesize($path) <= 10 * 1024 * 1024;

        foreach ($reader->records() as $fields) {
            $records++;

            if ($records === 1) {
                $first = $fields;
            } elseif ($records <= 4) {
                $samples[] = $fields;
            } elseif (! $countAll) {
                break;
            }
        }

        $names = array_map(fn (ColumnInfo $column): string => $column->name, array_values(array_filter($targetColumns, fn (ColumnInfo $column): bool => ! $column->generated)));
        $header ??= $this->looksLikeHeader($first, $samples[0] ?? [], $names);
        $rows = $header ? $samples : array_slice([$first, ...$samples], 0, 3);
        $width = max(count($first), ...array_map('count', $samples ?: [[]]));

        $columns = [];
        for ($index = 0; $index < $width; $index++) {
            $columns[] = [
                'index' => $index,
                'name' => $header ? trim((string) ($first[$index] ?? '')) : '',
                'samples' => array_map(fn (array $row): ?string => isset($row[$index]) ? mb_strimwidth((string) $row[$index], 0, 60, '…') : null, array_slice($rows, 0, 3)),
            ];
        }

        return [
            'delimiter' => $delimiter,
            'encoding' => $encoding,
            'bom' => $sniffed['bom'],
            'header' => $header,
            'columns' => $columns,
            'mapping' => array_map(fn (array $column): ?string => $this->suggest($column['name'], $names), $columns),
            'records' => $countAll ? max(0, $records - ($header ? 1 : 0)) : null,
        ];
    }

    /**
     * Probelauf oder Import. Probelauf: alles in einer Transaktion einfügen und zurückrollen — auf Tabellen ohne
     * Transaktionen (MyISAM) nur die Werte prüfen. Import: Fehlerzeilen überspringen oder bei einem Fehler nichts.
     *
     * @return CsvResult
     *
     * @throws TransferCancelled
     * @throws TransferFailed
     */
    public function run(Transfer $transfer, TransferProgress $progress): array
    {
        $this->errors = [];
        $this->errorCount = 0;
        $connection = $transfer->databaseConnection;
        $driver = $connection->driver;
        $target = $this->targets->open($connection, $transfer->openDatabase());
        $schema = (string) $transfer->option('schema');
        $table = (string) $transfer->option('table');
        $columns = [];

        foreach (ColumnInfo::of($target, $driver, $schema, $table) as $column) {
            $columns[$column->name] = $column;
        }

        if ($columns === []) {
            throw new TransferFailed(__('Die Tabelle «:table» gibt es nicht mehr.', locale: 'de'), ['table' => $table]);
        }

        /** @var list<string|null> $mapping */
        $mapping = array_values((array) ($transfer->options['mapping'] ?? []));

        foreach ($mapping as $name) {
            if ($name !== null && (! isset($columns[$name]) || $columns[$name]->generated)) {
                throw new TransferFailed(__('Die Spalte «:column» gibt es in «:table» nicht (mehr).', locale: 'de'), ['column' => $name, 'table' => $table]);
            }
        }

        $mapped = array_filter($mapping, fn (?string $name): bool => $name !== null);
        $expected = count($mapping);
        $batchSize = max(1, min(self::MAX_ROWS_PER_BATCH, intdiv(self::MAX_BINDINGS, max(1, count($mapped)))));
        $transactional = $this->isTransactional($target, $driver, $schema, $table);
        $dryRun = $transfer->dry_run;
        $checkOnly = $dryRun && ! $transactional;
        $abortOnError = ! $dryRun && $transfer->option('on_error', 'skip') === 'abort';
        $utf8 = $transfer->option('encoding', CsvReader::UTF8) === CsvReader::UTF8;
        $header = $transfer->flag('header', true);
        $path = Storage::disk(Transfer::DISK)->path((string) $transfer->file_path);
        $size = max(1, (int) filesize($path));
        $reader = new CsvReader($path, $transfer->option('delimiter', ',') ?? ',', $transfer->option('encoding', CsvReader::UTF8) ?? CsvReader::UTF8);

        if ($driver === DatabaseDriver::MySql || $driver === DatabaseDriver::MariaDb) {
            // Ein früherer Lesezugriff im Wächter lässt die Sitzung schreibgeschützt zurück.
            $target->statement('set session transaction read write');
        }

        $records = 0;
        $ready = 0;
        $batch = [];

        if ($transactional) {
            $target->beginTransaction();
        }

        try {
            $first = true;

            foreach ($reader->records() as $line => $fields) {
                if ($first && $header) {
                    $first = false;

                    continue;
                }

                $first = false;
                $records++;
                $row = $this->row($fields, $mapping, $expected, $columns, $driver, $utf8, $line);

                if ($row !== null) {
                    $batch[$line] = $row;
                }

                if (count($batch) >= ($transactional ? $batchSize : 1)) {
                    $ready += $checkOnly ? count($batch) : $this->insert($target, $schema.'.'.$table, $batch, $transactional);
                    $batch = [];
                }

                $progress->report($records, $reader->bytesRead() / $size);
            }

            if ($batch !== []) {
                $ready += $checkOnly ? count($batch) : $this->insert($target, $schema.'.'.$table, $batch, $transactional);
            }

            if ($transactional && ! $dryRun && ! ($abortOnError && $this->errorCount > 0)) {
                $target->commit();
            } elseif ($transactional) {
                $target->rollBack();
            }
        } catch (Throwable $exception) {
            if ($transactional && $target->transactionLevel() > 0) {
                $target->rollBack();
            }

            throw $exception;
        }

        // Ohne Transaktion (MyISAM) bleibt, was eingefügt ist — auch bei «nichts importieren».
        $imported = $dryRun || ($transactional && $abortOnError && $this->errorCount > 0) ? 0 : $ready;

        // Fehler der Datenbank kommen erst mit ihrem Paket — nach Zeilen geordnet liest sich die Liste wie die Datei.
        usort($this->errors, fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return [
            'records' => $records,
            'ready' => $ready,
            'imported' => $imported,
            'error_count' => $this->errorCount,
            'errors' => $this->errors,
            'checked_only' => $checkOnly,
        ];
    }

    /**
     * Werte einer Zeile für die gewählten Spalten — oder null mit einem Fehler zur Zeile (zählt mit, deshalb impure).
     *
     * @phpstan-impure
     *
     * @param  list<string|null>  $fields
     * @param  list<string|null>  $mapping
     * @param  array<string, ColumnInfo>  $columns
     * @return array<string, mixed>|null
     */
    private function row(array $fields, array $mapping, int $expected, array $columns, DatabaseDriver $driver, bool $utf8, int $line): ?array
    {
        if (count($fields) !== $expected) {
            $this->fail($line, __('Die Zeile hat :count Felder, erwartet sind :expected.', locale: 'de'), ['count' => count($fields), 'expected' => $expected]);

            return null;
        }

        $row = [];

        foreach ($mapping as $index => $name) {
            if ($name === null) {
                continue;
            }

            $column = $columns[$name];
            $raw = $fields[$index] ?? null;

            if ($raw === null) {
                if ($column->nullable) {
                    $row[$name] = null;
                } elseif ($column->autoIncrement && $driver !== DatabaseDriver::Postgres) {
                    // MySQL, MariaDB und SQLite vergeben bei NULL den nächsten Wert.
                    $row[$name] = null;
                } elseif (($column->autoIncrement || $column->hasDefault) && $driver !== DatabaseDriver::Sqlite) {
                    // Schlüsselwort, kein Wert: die Datenbank setzt ihre Vorgabe ein.
                    $row[$name] = new TrustedSql('default');
                } else {
                    $this->fail($line, __('«:column» ist leer und darf nicht NULL sein.', locale: 'de'), ['column' => $name]);

                    return null;
                }

                continue;
            }

            if ($utf8 && ! mb_check_encoding($raw, 'UTF-8')) {
                $this->fail($line, __('«:column» enthält Zeichen, die kein UTF-8 sind — wählen Sie die Kodierung Windows-1252.', locale: 'de'), ['column' => $name]);

                return null;
            }

            if ($column->binary) {
                $bytes = base64_decode($raw, true);

                if ($bytes === false) {
                    $this->fail($line, __('«:column» ist kein gültiges Base64 (Binärspalte).', locale: 'de'), ['column' => $name]);

                    return null;
                }

                $row[$name] = new BinaryValue($bytes);

                continue;
            }

            $row[$name] = $column->kind === ColumnKind::Boolean ? $this->boolean($raw) : $raw;
        }

        return $row;
    }

    /**
     * Ein Paket einfügen; scheitert es, Zeile für Zeile, damit nur die schuldigen Zeilen fehlen. Jeder Versuch hinter
     * einem Sicherungspunkt, der danach freigegeben wird — PostgreSQL bricht sonst die ganze Transaktion ab, und
     * Laravels verschachtelte Transaktionen gäben ihre Sicherungspunkte nie frei.
     *
     * @param  array<int, array<string, mixed>>  $batch  Zeilennummer => Werte
     *
     * @phpstan-impure
     */
    private function insert(Connection $target, string $table, array $batch, bool $savepoints): int
    {
        if (count($batch) > 1 && $this->attempt($target, fn () => $target->table($table)->insert(array_values(array_map($this->bindable(...), $batch))), $savepoints) === null) {
            return count($batch);
        }

        $inserted = 0;

        foreach ($batch as $line => $row) {
            $problem = $this->attempt($target, fn () => $target->table($table)->insert($this->bindable($row)), $savepoints);

            if ($problem === null) {
                $inserted++;
            } else {
                $this->fail($line, __('Die Datenbank lehnt die Zeile ab.', locale: 'de'), [], $this->serverMessage($problem));
            }
        }

        return $inserted;
    }

    /**
     * @param  Closure(): mixed  $insert
     */
    private function attempt(Connection $target, Closure $insert, bool $savepoint): ?QueryException
    {
        // Feste Namen ohne Werte — wie Laravel selbst Sicherungspunkte ohne Vorbereitung setzt.
        if ($savepoint) {
            $target->unprepared('savepoint phpdbadmin_import');
        }

        try {
            $insert();

            if ($savepoint) {
                $target->unprepared('release savepoint phpdbadmin_import');
            }

            return null;
        } catch (QueryException $exception) {
            if ($savepoint) {
                $target->unprepared('rollback to savepoint phpdbadmin_import');
                $target->unprepared('release savepoint phpdbadmin_import');
            }

            return $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function bindable(array $row): array
    {
        return array_map(fn (mixed $value): mixed => $value instanceof BinaryValue ? $value->stream() : $value, $row);
    }

    /**
     * Wahrheitswerte aus Tabellenkalkulationen («ja», «WAHR», «x») als 1/0 — das verstehen alle vier Datenbanken.
     */
    private function boolean(string $raw): string
    {
        $word = mb_strtolower(trim($raw));

        return match (true) {
            in_array($word, self::TRUE_WORDS, true) => '1',
            in_array($word, self::FALSE_WORDS, true) => '0',
            default => $raw,
        };
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private function fail(int $line, string $text, array $replace = [], ?string $detail = null): void
    {
        $this->errorCount++;

        if (count($this->errors) < self::ERROR_DETAILS) {
            $this->errors[] = ['line' => $line, 'message' => ['text' => $text, 'replace' => $replace, 'detail' => $detail]];
        }
    }

    /**
     * MySQL und MariaDB: nur InnoDB (und verwandte) können zurückrollen — MyISAM nicht.
     */
    private function isTransactional(Connection $target, DatabaseDriver $driver, string $schema, string $table): bool
    {
        if ($driver !== DatabaseDriver::MySql && $driver !== DatabaseDriver::MariaDb) {
            return true;
        }

        // Mit Alias: MySQL 8 liefert Spalten von information_schema in Grossbuchstaben.
        $engine = $target->selectOne('select engine as storage_engine from information_schema.tables where table_schema = ? and table_name = ?', [$schema, $table])?->storage_engine;

        return in_array(strtolower((string) $engine), ['innodb', 'xtradb', 'rocksdb', 'tokudb', 'ndbcluster'], true);
    }

    /**
     * Kopfzeile, wenn ein Wert wie eine Spalte der Tabelle heisst — oder wenn die erste Zeile nur Text hat, wo die
     * zweite Zahlen hat.
     *
     * @param  list<string|null>  $first
     * @param  list<string|null>  $second
     * @param  list<string>  $columnNames
     */
    private function looksLikeHeader(array $first, array $second, array $columnNames): bool
    {
        foreach ($first as $value) {
            if ($value !== null && $this->suggest($value, $columnNames) !== null) {
                return true;
            }
        }

        foreach ($first as $index => $value) {
            if ($value !== null && ! is_numeric($value) && isset($second[$index]) && is_numeric($second[$index])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $columnNames
     */
    private function suggest(string $name, array $columnNames): ?string
    {
        $wanted = $this->normalize($name);

        if ($wanted === '') {
            return null;
        }

        foreach ($columnNames as $column) {
            if ($this->normalize($column) === $wanted) {
                return $column;
            }
        }

        return null;
    }

    private function normalize(string $name): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim($name)));
    }

    private function serverMessage(QueryException $exception): string
    {
        // Ohne das SQL mit eingesetzten Werten, das Laravel anhängt (T-11).
        return mb_strimwidth($exception->getPrevious()?->getMessage() ?? $exception->getMessage(), 0, 300, '…');
    }
}
