<?php

namespace App\Services\Sql;

/**
 * Ordnet Anweisungen ein (Q-03, Q-07): lesend oder schreibend, und gefährlich — DROP, TRUNCATE sowie DELETE und
 * UPDATE ohne WHERE verlangen eine Bestätigung. Zeichenketten, Bezeichner und Kommentare zählen nicht mit, ein WHERE
 * nur in einer Unterabfrage auch nicht.
 */
final class StatementClassifier
{
    public function kind(string $sql): StatementKind
    {
        $plain = $this->plain($sql);
        $first = $this->firstWord($plain);

        return match (true) {
            in_array($first, ['SHOW', 'DESCRIBE', 'DESC', 'TABLE', 'VALUES', 'USE'], true) => StatementKind::Read,
            $first === 'SELECT' => preg_match('/\bINTO\b/i', $this->topLevel($plain)) === 1 ? StatementKind::Write : StatementKind::Read,
            $first === 'WITH' => preg_match('/\b(INSERT|UPDATE|DELETE|MERGE)\b/i', $plain) === 1 ? StatementKind::Write : StatementKind::Read,
            $first === 'EXPLAIN' => preg_match('/\bANALY[SZ]E\b/i', $plain) === 1 && preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|CREATE|DROP|ALTER)\b/i', $plain) === 1
                ? StatementKind::Write
                : StatementKind::Read,
            $first === 'PRAGMA' => str_contains($plain, '=') ? StatementKind::Write : StatementKind::Read,
            default => StatementKind::Write,
        };
    }

    /**
     * Warum die Anweisung gefährlich ist, oder null.
     */
    public function danger(string $sql): ?string
    {
        $plain = $this->plain($sql);
        $first = $this->firstWord($plain);
        $topLevel = $this->topLevel($plain);
        $hasWhere = preg_match('/\bWHERE\b/i', $topLevel) === 1;

        $kind = match (true) {
            $first === 'DROP' => 'drop',
            $first === 'TRUNCATE' => 'truncate',
            $first === 'DELETE' && ! $hasWhere => 'delete',
            $first === 'UPDATE' && ! $hasWhere => 'update',
            $first === 'ALTER' && preg_match('/\bDROP\b/i', $topLevel) === 1 => 'alter',
            default => null,
        };

        return $kind === null ? null : $this->explain($kind);
    }

    private function explain(string $kind): string
    {
        return match ($kind) {
            'drop' => __('DROP löscht das Objekt mit allen Daten.'),
            'truncate' => __('TRUNCATE leert die Tabelle vollständig.'),
            'delete' => __('DELETE ohne WHERE löscht alle Zeilen.'),
            'update' => __('UPDATE ohne WHERE ändert alle Zeilen.'),
            default => __('ALTER … DROP entfernt Spalten oder Einschränkungen mit ihren Daten.'),
        };
    }

    /**
     * Text ohne Kommentare, Zeichenketten und gequotete Bezeichner — die werden zu Leerzeichen bzw. leeren Platzhaltern.
     */
    private function plain(string $sql): string
    {
        return (string) preg_replace(
            ['~/\*.*?\*/~s', '~--[^\n]*~', '~#[^\n]*~', "~'(?:[^'\\\\]|\\\\.|'')*'~s", '~"(?:[^"]|"")*"~', '~`(?:[^`]|``)*`~', '~\$([A-Za-z_]\w*)?\$.*?\$\1\$~s'],
            [' ', ' ', ' ', "''", 'x', 'x', "''"],
            $sql,
        );
    }

    private function firstWord(string $plain): string
    {
        return preg_match('/^\s*\(*\s*([A-Za-z]+)/', $plain, $match) === 1 ? strtoupper($match[1]) : '';
    }

    /**
     * Nur die oberste Ebene: alles in Klammern entfernt.
     */
    private function topLevel(string $plain): string
    {
        do {
            $plain = (string) preg_replace('/\([^()]*\)/', ' ', $plain, -1, $count);
        } while ($count > 0);

        return $plain;
    }
}
