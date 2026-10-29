<?php

namespace App\Services\Backup;

use App\Connections\ConnectionSettings;
use App\Connections\TargetConnections;
use App\Enums\DatabaseDriver;
use App\Enums\SslMode;
use App\Enums\TransferFormat;
use App\Models\DatabaseConnection;
use App\Models\Transfer;
use App\Services\Audit\AuditLog;
use App\Services\Transfer\SqlImporter;
use App\Services\Transfer\TransferCancelled;
use App\Services\Transfer\TransferFailed;
use App\Services\Transfer\TransferProgress;
use Illuminate\Support\Facades\Storage;
use Pdo\Sqlite;
use PDOException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Sicherung und Wiederherstellung über die Werkzeuge der Server (E-03), als Auftrag wie ein Export (B-07).
 *
 * - **Sichern:** MySQL mit mysqldump, MariaDB mit mariadb-dump (eine Transaktion, Routinen, Trigger und Ereignisse
 *   wahlweise, Binärdaten hexadezimal), PostgreSQL mit pg_dump im Custom- oder Textformat (ohne Eigentümer und
 *   Rechte), SQLite ohne Werkzeug per `VACUUM INTO` aus einer Nur-lesen-Verbindung. Vorher die Versionsprüfung:
 *   pg_dump nicht älter als der Server, mysqldump/mariadb-dump zur Art des Servers passend.
 * - **Wiederherstellen** in eine bestehende Datenbank: pg_restore (eine Transaktion, bei einem Fehler nichts), psql für
 *   das Textformat (eine Transaktion), mysql bzw. mariadb, sqlite3 `.restore` (Backup-API, ganz oder gar nicht).
 *
 * Eine hochgeladene Datei ist fremder Inhalt: Die Clients können Befehle auf dem phpdbadmin-Server ausführen
 * (`system`, `\!`), Dateien schreiben (`tee`, `\o`) oder lesen (`source`, `\i`). Deshalb laufen sie nur mit Schutz:
 * `mysql --skip-commands`, `mariadb --sandbox`, `psql` im Modus `\restrict` mit eigenem, geheimem Schlüssel. Kennt
 * der MySQL/MariaDB-Client den Schutz nicht, spielt phpdbadmin die Datei mit dem eigenen SQL-Ausführer ein (wie der
 * Import einer SQL-Datei); psql ohne `\restrict` lehnt das Textformat ab.
 *
 * Passwörter nie auf der Kommandozeile (sichtbar in `ps`): MySQL/MariaDB über eine eigene Optionsdatei (0600, danach
 * gelöscht), PostgreSQL über die Umgebung des Prozesses. Verbunden wird zur geprüften Adresse (V-05).
 *
 * @phpstan-import-type ToolInfo from Tools
 *
 * @phpstan-type ToolStatus array{name: string, found: bool, version: string|null, environmentKey: string}
 */
final class BackupRunner
{
    public function __construct(
        private readonly TargetConnections $targets,
        private readonly Tools $tools,
        private readonly SqlImporter $sqlImporter,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Was auf diesem Server geht — für die Seite «Sicherung».
     *
     * @return array{backup: list<ToolStatus>, restore: list<ToolStatus>, canBackup: bool, canRestore: bool, safeClient: bool, plainRestore: bool}
     */
    public function status(DatabaseConnection $connection): array
    {
        $needed = Tools::needed($connection->driver);
        $describe = fn (string $name): array => [
            'name' => $name,
            'found' => $this->tools->info($name)['path'] !== null,
            'version' => $this->tools->info($name)['version'],
            'environmentKey' => Tools::environmentKey($name),
        ];
        $backup = array_map($describe, $needed['backup']);
        $restore = array_map($describe, $needed['restore']);
        $safeClient = $this->hasSafeClient($connection->driver);

        return [
            'backup' => $backup,
            'restore' => $restore,
            'canBackup' => array_all($backup, fn (array $tool): bool => $tool['found']),
            'canRestore' => match ($connection->driver) {
                // Ohne Client (oder ohne Schutz) spielt phpdbadmin die Datei selbst ein.
                DatabaseDriver::MySql, DatabaseDriver::MariaDb => true,
                DatabaseDriver::Postgres => $restore[0]['found'] || $restore[1]['found'],
                DatabaseDriver::Sqlite => $restore[0]['found'],
            },
            'safeClient' => $safeClient,
            'plainRestore' => $connection->driver === DatabaseDriver::Postgres && $this->tools->supports('psql', '\\restrict'),
        ];
    }

    /**
     * Art der hochgeladenen Datei am Anfang erkannt: Archiv von pg_dump, SQLite-Datei oder Text (SQL).
     */
    public static function detectFormat(string $path): ?TransferFormat
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $head = (string) fread($handle, 16);
        fclose($handle);

        return match (true) {
            str_starts_with($head, 'PGDMP') => TransferFormat::PgDump,
            str_starts_with($head, "SQLite format 3\0") => TransferFormat::SqliteFile,
            // Gepackt (gzip) oder sonst binär: nicht erkannt.
            str_starts_with($head, "\x1f\x8b") || str_contains($head, "\0") => null,
            default => TransferFormat::Sql,
        };
    }

    /**
     * Sichern in die Datei des Auftrags.
     *
     * @return array<string, string|int|null>
     *
     * @throws TransferCancelled
     * @throws TransferFailed
     */
    public function backup(Transfer $transfer, TransferProgress $progress): array
    {
        $connection = $transfer->databaseConnection;
        $driver = $connection->driver;
        $database = (string) $transfer->option('database');
        $disk = Storage::disk(Transfer::DISK);
        $disk->makeDirectory($transfer->directory());
        $path = $transfer->directory().'/'.$transfer->file_name;
        $file = $disk->path($path);

        if ($driver === DatabaseDriver::Sqlite) {
            $this->vacuumInto($connection, $file);
            $tool = 'SQLite VACUUM INTO';
            $server = $driver->dialect()->serverVersion($this->targets->open($connection));
        } else {
            [$info, $server] = $this->checkedTool($connection, Tools::needed($driver)['backup'][0], $database);
            $tool = $info['name'].' '.$info['version'];
            $this->dump($transfer, $connection, (string) $info['path'], $database, $file, $progress);
        }

        $transfer->forceFill(['file_path' => $path, 'file_size' => (int) filesize($file)])->save();
        $this->audit->record('transfer.backup', $transfer->user, $database === '' ? $transfer->target : $database, [
            'format' => $transfer->format->value,
            'tool' => $tool,
            'size' => $transfer->file_size,
            'transfer' => $transfer->id,
        ], connection: $connection);

        return ['tool' => $tool, 'server' => $server, 'database' => $transfer->target];
    }

    /**
     * Wiederherstellen aus der hochgeladenen Datei in die gewählte Datenbank — ein Eintrag im Protokoll, auch wenn
     * es scheitert (MySQL und MariaDB lassen dann stehen, was davor lief).
     *
     * @return array<string, string|int|null>
     *
     * @throws TransferCancelled
     * @throws TransferFailed
     */
    public function restore(Transfer $transfer, TransferProgress $progress): array
    {
        $connection = $transfer->databaseConnection;
        $database = (string) $transfer->option('database');
        $file = Storage::disk(Transfer::DISK)->path((string) $transfer->file_path);
        $method = null;

        try {
            $method = match ($connection->driver) {
                DatabaseDriver::Sqlite => $this->restoreSqlite($transfer, $connection, $file, $progress),
                DatabaseDriver::Postgres => $this->restorePostgres($transfer, $connection, $database, $file, $progress),
                DatabaseDriver::MySql, DatabaseDriver::MariaDb => $this->restoreMySql($transfer, $connection, $database, $file, $progress),
            };
        } catch (TransferCancelled|TransferFailed $exception) {
            $this->auditRestore($transfer, $method, $exception);

            throw $exception;
        } catch (Throwable $exception) {
            $this->auditRestore($transfer, $method, $exception);

            throw new TransferFailed(__('Die Wiederherstellung ist gescheitert.', locale: 'de'), detail: mb_strimwidth($exception->getMessage(), 0, 1000, '…'));
        }

        $this->auditRestore($transfer, $method, null);

        return ['method' => $method, 'database' => $transfer->target];
    }

    /**
     * Werkzeug finden und gegen den Server prüfen.
     *
     * @return array{0: ToolInfo, 1: string}
     *
     * @throws TransferFailed
     */
    private function checkedTool(DatabaseConnection $connection, string $name, string $database): array
    {
        $info = $this->tools->info($name);

        if ($info['path'] === null) {
            throw new TransferFailed(__(':tool fehlt auf dem phpdbadmin-Server. Installieren Sie es oder tragen Sie den Pfad in :key ein.', locale: 'de'), ['tool' => $name, 'key' => Tools::environmentKey($name)]);
        }

        $driver = $connection->driver;
        $server = $driver->dialect()->serverVersion($this->targets->open($connection, $driver === DatabaseDriver::Postgres ? $database : null));
        $problem = Tools::mismatch($driver, $info['version'], $info['flavor'], $server);

        if ($problem !== null) {
            throw new TransferFailed($problem, ['tool' => $name, 'version' => (string) $info['version'], 'server' => $server]);
        }

        return [$info, $server];
    }

    /**
     * @throws TransferCancelled
     * @throws TransferFailed
     */
    private function dump(Transfer $transfer, DatabaseConnection $connection, string $tool, string $database, string $file, TransferProgress $progress): void
    {
        $driver = $connection->driver;
        $objects = $transfer->flag('objects', true);

        if ($driver === DatabaseDriver::Postgres) {
            ['settings' => $settings, 'address' => $address] = $this->targets->credentials($connection, $database);
            $plain = $transfer->format === TransferFormat::Sql;

            $this->run($transfer, basename($tool), [
                $tool, '--no-password', '--format='.($plain ? 'plain' : 'custom'), '--no-owner', '--no-privileges',
                ...($plain ? ['--clean', '--if-exists'] : []),
                '--file='.$file,
            ], $this->postgresEnvironment($settings, $address, $database), null, $progress);

            return;
        }

        ['settings' => $settings, 'address' => $address] = $this->targets->credentials($connection);
        $options = $this->clientOptions($settings, $address);

        try {
            $this->run($transfer, basename($tool), [
                $tool, '--defaults-file='.$options, '--protocol=TCP', '--single-transaction', '--quick', '--hex-blob',
                '--default-character-set=utf8mb4', '--no-tablespaces',
                ...($objects ? ['--routines', '--triggers', '--events'] : ['--skip-triggers']),
                ...($driver === DatabaseDriver::MySql ? ['--set-gtid-purged=OFF'] : []),
                ...$this->sslArguments($driver, $settings->sslMode),
                '--result-file='.$file, '--', $database,
            ], [], null, $progress);
        } finally {
            @unlink($options);
        }
    }

    /**
     * SQLite ohne Werkzeug: eine Nur-lesen-Verbindung zur geprüften Datei schreibt einen gleichbleibenden Stand in die
     * Datei des Auftrags (VACUUM INTO, ab SQLite 3.27).
     *
     * @throws TransferFailed
     */
    private function vacuumInto(DatabaseConnection $connection, string $file): void
    {
        ['address' => $source] = $this->targets->credentials($connection);

        try {
            $pdo = new Sqlite('sqlite:'.$source, null, null, [Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READONLY]);
            $pdo->prepare('vacuum into ?')->execute([$file]);
        } catch (PDOException $exception) {
            throw new TransferFailed(__('SQLite konnte die Sicherung nicht schreiben.', locale: 'de'), detail: $exception->getMessage());
        }
    }

    /**
     * @throws TransferCancelled
     * @throws TransferFailed
     */
    private function restoreSqlite(Transfer $transfer, DatabaseConnection $connection, string $file, TransferProgress $progress): string
    {
        $tool = $this->tools->info('sqlite3');

        if ($tool['path'] === null) {
            throw new TransferFailed(__(':tool fehlt auf dem phpdbadmin-Server. Installieren Sie es oder tragen Sie den Pfad in :key ein.', locale: 'de'), ['tool' => 'sqlite3', 'key' => Tools::environmentKey('sqlite3')]);
        }

        $this->checkSqliteFile($file);
        ['address' => $target] = $this->targets->credentials($connection);

        if (str_contains($file, "'")) {
            throw new RuntimeException('Unexpected quote in the transfer path');
        }

        $this->run($transfer, 'sqlite3', [$tool['path'], '-bail', '-batch', $target, ".restore '".$file."'"], [], null, $progress);

        return 'sqlite3 '.$tool['version'];
    }

    /**
     * Eine hochgeladene SQLite-Datei muss sich öffnen lassen und die Prüfung bestehen — sonst wird nichts ersetzt.
     *
     * @throws TransferFailed
     */
    private function checkSqliteFile(string $file): void
    {
        try {
            $pdo = new Sqlite('sqlite:'.$file, null, null, [Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READONLY]);
            $check = $pdo->query('pragma quick_check');
            $result = $check === false ? null : $check->fetchColumn();
        } catch (PDOException) {
            $result = null;
        }

        if ($result !== 'ok') {
            throw new TransferFailed(__('Die Datei ist keine intakte SQLite-Datenbank. Nichts wurde ersetzt.', locale: 'de'));
        }
    }

    /**
     * @throws TransferCancelled
     * @throws TransferFailed
     */
    private function restorePostgres(Transfer $transfer, DatabaseConnection $connection, string $database, string $file, TransferProgress $progress): string
    {
        ['settings' => $settings, 'address' => $address] = $this->targets->credentials($connection, $database);
        $environment = $this->postgresEnvironment($settings, $address, $database);

        if ($transfer->format === TransferFormat::PgDump) {
            [$tool] = $this->checkedTool($connection, 'pg_restore', $database);

            $this->run($transfer, 'pg_restore', [
                (string) $tool['path'], '--no-password', '--no-owner', '--no-privileges', '--clean', '--if-exists',
                '--single-transaction', '--exit-on-error', '--dbname='.$this->conninfo($database), $file,
            ], $environment, null, $progress);

            return 'pg_restore '.$tool['version'];
        }

        $tool = $this->tools->info('psql');

        if ($tool['path'] === null || ! $this->tools->supports('psql', '\\restrict')) {
            throw new TransferFailed(__('Eine Sicherung im Textformat spielt phpdbadmin nur mit psql ab Version 18 (bzw. 17.6, 16.10, 15.14, 14.19) ein — erst damit lässt sich psql gegen Befehle aus der Datei sperren. Nehmen Sie das Custom-Format oder ein neueres psql.', locale: 'de'));
        }

        $restricted = $this->restricted($file);

        try {
            $this->run($transfer, 'psql', [
                $tool['path'], '--no-password', '--no-psqlrc', '--quiet', '--set=ON_ERROR_STOP=1', '--single-transaction', '--file='.$restricted,
            ], $environment, null, $progress);
        } finally {
            @unlink($restricted);
        }

        return 'psql '.$tool['version'];
    }

    /**
     * @throws TransferCancelled
     * @throws TransferFailed
     */
    private function restoreMySql(Transfer $transfer, DatabaseConnection $connection, string $database, string $file, TransferProgress $progress): string
    {
        $driver = $connection->driver;
        $name = Tools::needed($driver)['restore'][0];

        if (! $this->hasSafeClient($driver)) {
            // Ohne geschützten Client: Anweisung für Anweisung mit dem eigenen Ausführer (wie der Import, E-02).
            $this->sqlImporter->run($transfer, $progress);

            return 'phpdbadmin';
        }

        [$tool] = $this->checkedTool($connection, $name, $database);
        ['settings' => $settings, 'address' => $address] = $this->targets->credentials($connection);
        $options = $this->clientOptions($settings, $address);
        $input = fopen($file, 'rb');

        if ($input === false) {
            throw new RuntimeException('Restore file not readable');
        }

        try {
            $this->run($transfer, $name, [
                (string) $tool['path'], '--defaults-file='.$options, '--protocol=TCP', '--batch',
                $driver === DatabaseDriver::MySql ? '--skip-commands' : '--sandbox',
                '--default-character-set=utf8mb4',
                ...$this->sslArguments($driver, $settings->sslMode),
                '--', $database,
            ], [], $input, $progress);
        } finally {
            fclose($input);
            @unlink($options);
        }

        return $name.' '.$tool['version'];
    }

    /** Kennt der Client von MySQL bzw. MariaDB einen Schutz gegen Befehle aus der Datei? */
    private function hasSafeClient(DatabaseDriver $driver): bool
    {
        return match ($driver) {
            DatabaseDriver::MySql => $this->tools->supports('mysql', '--commands') && $this->tools->info('mysql')['flavor'] === 'mysql',
            DatabaseDriver::MariaDb => $this->tools->supports('mariadb', '--sandbox') && $this->tools->info('mariadb')['flavor'] === 'mariadb',
            default => false,
        };
    }

    /**
     * Die Datei für psql im Modus `\restrict` mit eigenem, zufälligem Schlüssel: Ausser `\unrestrict` mit diesem
     * Schlüssel lehnt psql jeden Befehl mit Backslash ab. Die `\restrict`- und `\unrestrict`-Zeilen von pg_dump selbst
     * (ausserhalb von COPY-Daten) fallen weg; alles andere geht unverändert durch. Geschrieben neben die hochgeladene
     * Datei; psql liest sie selbst (`--file`).
     */
    private function restricted(string $file): string
    {
        $source = fopen($file, 'rb');
        $path = dirname($file).'/psql-eingabe-'.bin2hex(random_bytes(6)).'.sql';
        $target = fopen($path, 'xb');

        if ($source === false || $target === false) {
            throw new RuntimeException('Restore file not readable or not writable');
        }

        try {
            fwrite($target, '\\restrict '.bin2hex(random_bytes(24))."\n");
            $inCopy = false;

            while (($line = fgets($source)) !== false) {
                $trimmed = rtrim($line, "\r\n");

                if ($inCopy) {
                    $inCopy = $trimmed !== '\\.';
                } elseif (preg_match('/^COPY\s.+\sFROM\s+stdin;$/i', $trimmed) === 1) {
                    $inCopy = true;
                } elseif (preg_match('/^\\\\(un)?restrict\s+[A-Za-z0-9]+\s*$/', $trimmed) === 1) {
                    continue;
                }

                fwrite($target, $line);
            }
        } finally {
            fclose($source);
            fclose($target);
        }

        return $path;
    }

    /**
     * Ein Werkzeug ausführen — mit Zeitgrenze, abbrechbar über den Auftrag, Fehler mit der Meldung des Werkzeugs.
     *
     * @param  list<string>  $command
     * @param  array<string, string|false>  $environment
     * @param  resource|null  $input
     *
     * @throws TransferCancelled
     * @throws TransferFailed
     */
    private function run(Transfer $transfer, string $name, array $command, array $environment, mixed $input, TransferProgress $progress): void
    {
        $limit = (int) config('phpdbadmin.backup.timeout_seconds', 3600);
        $process = new Process($command, null, $environment, $input, $transfer->in_background ? $limit : min($limit, 280));
        $process->start();
        $checked = microtime(true);

        try {
            // Jede Runde reicht Symfony ein Stück der Eingabe weiter — mit Eingabe also oft nachsehen, sonst gemächlich.
            while ($process->isRunning()) {
                $process->checkTimeout();

                if (microtime(true) - $checked >= 0.5) {
                    $progress->checkCancelled();
                    $checked = microtime(true);
                }

                usleep($input === null ? 100_000 : 1_000);
            }
        } catch (TransferCancelled $cancelled) {
            $process->stop(0);

            throw $cancelled;
        } catch (ProcessTimedOutException) {
            throw new TransferFailed(__(':tool hat die Zeitgrenze von :seconds Sekunden überschritten und wurde beendet.', locale: 'de'), ['tool' => $name, 'seconds' => (int) $process->getTimeout()]);
        }

        if (! $process->isSuccessful()) {
            throw new TransferFailed(
                __(':tool ist mit einem Fehler beendet worden (Code :code).', locale: 'de'),
                ['tool' => $name, 'code' => (int) $process->getExitCode()],
                mb_strimwidth(trim($process->getErrorOutput()) ?: trim($process->getOutput()), 0, 1000, '…'),
            );
        }
    }

    /**
     * Optionsdatei für mysqldump, mysql, mariadb-dump und mariadb: nur sie wird gelesen (`--defaults-file`), 0600,
     * danach gelöscht. Werte in Anführungszeichen, Backslash und Anführungszeichen maskiert.
     */
    private function clientOptions(ConnectionSettings $settings, string $address): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phpdbadmin-client-');

        if ($path === false) {
            throw new RuntimeException('Temporary option file not writable');
        }

        chmod($path, 0600);
        $quote = fn (string $value): string => '"'.str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '\\r', '\\t'], $value).'"';

        $lines = ['[client]', 'host='.$quote($address), 'port='.(int) $settings->port()];

        if ($settings->username !== null) {
            $lines[] = 'user='.$quote($settings->username);
        }

        if ($settings->password !== null && $settings->password !== '') {
            $lines[] = 'password='.$quote($settings->password);
        }

        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }

    /**
     * @return list<string>
     */
    private function sslArguments(DatabaseDriver $driver, SslMode $mode): array
    {
        $required = $mode === SslMode::Require;

        return $driver === DatabaseDriver::MySql
            ? ['--ssl-mode='.($required ? 'REQUIRED' : 'DISABLED')]
            : [$required ? '--ssl' : '--skip-ssl'];
    }

    /**
     * Umgebung für pg_dump, pg_restore und psql: Adresse, Konto, Passwort, Datenbank, SSL — geerbte PG-Variablen, die
     * etwas anderes wollten, fallen weg.
     *
     * @return array<string, string|false>
     */
    private function postgresEnvironment(ConnectionSettings $settings, string $address, string $database): array
    {
        return [
            'PGHOST' => $address,
            'PGPORT' => (string) $settings->port(),
            'PGUSER' => (string) $settings->username,
            'PGPASSWORD' => $settings->password !== null && $settings->password !== '' ? $settings->password : false,
            'PGDATABASE' => $database,
            'PGSSLMODE' => $settings->sslMode->value,
            'PGCONNECT_TIMEOUT' => (string) (int) config('phpdbadmin.connections.connect_timeout', 5),
            'PGAPPNAME' => 'phpdbadmin',
            'PGSERVICE' => false,
            'PGSERVICEFILE' => false,
            'PGOPTIONS' => false,
        ];
    }

    /** `dbname='…'` für pg_restore — ein Name mit «=» würde sonst als Verbindungstext gelesen. */
    private function conninfo(string $database): string
    {
        return "dbname='".str_replace(['\\', "'"], ['\\\\', "\\'"], $database)."'";
    }

    private function auditRestore(Transfer $transfer, ?string $method, ?Throwable $error): void
    {
        $this->audit->record('data.restore', $transfer->user, $transfer->target, [
            'format' => $transfer->format->value,
            'method' => $method,
            'size' => $transfer->file_size,
            'transfer' => $transfer->id,
            ...($error === null ? [] : ['failed' => true]),
        ], connection: $transfer->databaseConnection, error: $error instanceof TransferFailed ? $error->detail ?? $error->text : $error);
    }
}
