<?php

namespace Tests\Concerns;

/**
 * SQLite-Abfragen laufen in einem eigenen Prozess (`phpdbadmin:sqlite-worker`). Der liest die erlaubten Ordner aus der
 * Umgebung bzw. der `.env`, nicht aus `config()` des Tests — ohne diese Hilfe bestanden die Tests nur dort, wo die
 * eigene `.env` den Testordner zufällig erlaubt (lokal ja, in der CI nein).
 *
 * Symfony Process gibt dem Kindprozess nur Variablen weiter, die auch in `$_SERVER` stehen, und `$_ENV` geht vor —
 * `putenv()` allein reicht deshalb nicht.
 */
trait SqliteWorkerFolders
{
    /**
     * Ordner für SQLite-Dateien erlauben — hier und im Arbeitsprozess; nach dem Test wieder wie vorher.
     *
     * @param  list<string>  $folders
     */
    protected function allowSqliteFolders(array $folders): void
    {
        config(['phpdbadmin.connections.sqlite_paths' => $folders]);

        $name = 'PHPDBADMIN_SQLITE_PATHS';
        $value = implode(',', $folders);
        $before = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];

        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;

        $this->beforeApplicationDestroyed(function () use ($name, $before): void {
            putenv($before[0] === false ? $name : $name.'='.$before[0]);

            if ($before[1] === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $before[1];
            }

            if ($before[2] === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $before[2];
            }
        });
    }
}
