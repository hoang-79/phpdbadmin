<?php

namespace App\Dialects;

use App\Enums\DatabaseDriver;
use Illuminate\Database\Connection;
use Throwable;

/**
 * MariaDB 10.6 und neuer: dieselben Fehlercodes wie MySQL, aber ein eigenes Zeitlimit (max_statement_time in
 * Sekunden, gilt für jede Anweisung) mit Fehler 1969 statt stillem Abbruch (F-2).
 */
class MariaDbDialect extends MySqlDialect
{
    public function driver(): DatabaseDriver
    {
        return DatabaseDriver::MariaDb;
    }

    public function setStatementTimeout(Connection $connection, int $milliseconds): void
    {
        $connection->statement('set session max_statement_time = '.number_format(max(0, $milliseconds) / 1000, 3, '.', ''));
    }

    public function isTimeout(Throwable $error): bool
    {
        [$state, $code] = $this->errorCodes($error);

        return $code === 1969 || $state === '70100';
    }

    /** MariaDB führt Rollen in mysql.user (is_role) und kennt dort kein account_locked. */
    protected function accountQuery(): string
    {
        return 'select user, host, is_role, password_expired from mysql.user order by user, host';
    }

    protected function accountAttributes(array $values): array
    {
        return array_values(array_filter([
            ($values['is_role'] ?? 'N') === 'Y' ? 'role' : null,
            ($values['password_expired'] ?? 'N') === 'Y' ? 'expired' : null,
        ]));
    }

    /** Rollen haben keinen Host: SHOW GRANTS FOR 'rolle'. */
    protected function grantsOf(Connection $connection, AccountName $account, array $values): array
    {
        if (($values['is_role'] ?? 'N') !== 'Y') {
            return parent::grantsOf($connection, $account, $values);
        }

        return array_values(array_map(
            fn (object $row): string => (string) (array_values((array) $row)[0] ?? ''),
            $connection->select('show grants for '.$this->quoteString($connection, $account->name)),
        ));
    }
}
