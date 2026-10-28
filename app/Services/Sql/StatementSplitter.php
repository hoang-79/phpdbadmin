<?php

namespace App\Services\Sql;

use App\Enums\DatabaseDriver;

/**
 * Zerlegt SQL in einzelne Anweisungen (Q-02, Solution-Architektur § 5): jede wird für sich ausgeführt, weil die
 * Verbindungen nie mehrere Anweisungen in einem Aufruf annehmen (F-7). Kennt Zeichenketten und Bezeichner je Dialekt,
 * Kommentare, Dollar-Blöcke von PostgreSQL, `BEGIN … END` in Routinen und Triggern sowie `DELIMITER` aus
 * MySQL-Dumps. Positionen sind Byte-Positionen im Text (UTF-8).
 *
 * @phpstan-type Statement array{sql: string, start: int, end: int}
 */
final class StatementSplitter
{
    /** Wörter, nach denen eine Anweisung Routinen mit eigenem `BEGIN … END` definiert. */
    private const array ROUTINES = ['PROCEDURE', 'FUNCTION', 'TRIGGER', 'EVENT'];

    /**
     * @return list<Statement>
     */
    public function split(string $sql, DatabaseDriver $driver): array
    {
        $statements = [];
        $length = strlen($sql);
        $isMySql = in_array($driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true);
        $isPostgres = $driver === DatabaseDriver::Postgres;
        $delimiter = ';';
        $start = 0;
        $depth = 0;
        $words = [];
        $index = 0;

        while ($index < $length) {
            $char = $sql[$index];
            $next = $sql[$index + 1] ?? '';

            // DELIMITER am Zeilenanfang (MySQL-Dumps): ändert das Trennzeichen, ist selbst keine Anweisung.
            if ($isMySql && $words === [] && $this->atLineStart($sql, $index) && preg_match('/\GDELIMITER[ \t]+(\S+)[^\n]*\n?/i', $sql, $match, 0, $index) === 1) {
                $this->push($statements, $sql, $start, $index);
                $delimiter = $match[1];
                $index += strlen($match[0]);
                $start = $index;

                continue;
            }

            if ($char === '-' && $next === '-' && (! $isMySql || in_array($sql[$index + 2] ?? "\n", [' ', "\t", "\n", "\r"], true))) {
                $index = $this->lineEnd($sql, $index);

                continue;
            }

            if ($char === '#' && $isMySql) {
                $index = $this->lineEnd($sql, $index);

                continue;
            }

            if ($char === '/' && $next === '*') {
                $index = $this->blockCommentEnd($sql, $index, $isPostgres);

                continue;
            }

            if ($char === "'" || $char === '"' || ($char === '`' && ! $isPostgres)) {
                $backslash = $isMySql && $char !== '`' || ($isPostgres && $char === "'" && strtoupper($sql[$index - 1] ?? '') === 'E');
                $index = $this->quotedEnd($sql, $index, $char, $backslash);

                continue;
            }

            if ($char === '[' && $driver === DatabaseDriver::Sqlite) {
                $close = strpos($sql, ']', $index + 1);
                $index = $close === false ? $length : $close + 1;

                continue;
            }

            if ($char === '$' && $isPostgres && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $match, 0, $index) === 1) {
                $close = strpos($sql, $match[0], $index + strlen($match[0]));
                $index = $close === false ? $length : $close + strlen($match[0]);

                continue;
            }

            if ((ctype_alpha($char) || $char === '_') && preg_match('/\G[A-Za-z_][A-Za-z0-9_$]*/', $sql, $match, 0, $index) === 1) {
                $word = strtoupper($match[0]);
                $words[] = $word;
                $index += strlen($match[0]);

                if (! $isPostgres && $this->definesRoutine($words)) {
                    if ($word === 'END') {
                        // END IF/LOOP/WHILE/REPEAT schliessen Blöcke, deren Anfang nicht zählt; END CASE und END
                        // schliessen einen. Das Wort danach gehört zum END und wird mitgelesen.
                        $following = preg_match('/\G\s+(IF|LOOP|WHILE|REPEAT|CASE)\b/i', $sql, $after, 0, $index) === 1 ? $after : null;
                        $depth -= $following !== null && strtoupper($following[1]) !== 'CASE' ? 0 : 1;
                        $index += $following !== null ? strlen($following[0]) : 0;
                    } elseif ($word === 'BEGIN' || $word === 'CASE') {
                        $depth++;
                    }

                    $depth = max(0, $depth);
                }

                continue;
            }

            if ($depth === 0 && substr_compare($sql, $delimiter, $index, strlen($delimiter)) === 0) {
                $index += strlen($delimiter);
                $this->push($statements, $sql, $start, $index, $delimiter);
                $start = $index;
                $words = [];

                continue;
            }

            if (! ctype_space($char) && $words === []) {
                $words[] = '';
            }

            $index++;
        }

        $this->push($statements, $sql, $start, $length, $delimiter);

        return $statements;
    }

    /**
     * Die Anweisung an der Schreibmarke (Byte-Position): die, in der sie steht, oder die letzte davor — wer hinter dem
     * Semikolon steht, meint die gerade beendete Anweisung.
     *
     * @return Statement|null
     */
    public function statementAt(string $sql, int $offset, DatabaseDriver $driver): ?array
    {
        $found = null;

        foreach ($this->split($sql, $driver) as $statement) {
            if ($statement['start'] <= $offset) {
                $found = $statement;
            }

            if ($offset <= $statement['end']) {
                return $found ?? $statement;
            }
        }

        return $found;
    }

    /**
     * @param  list<Statement>  $statements
     */
    private function push(array &$statements, string $sql, int $start, int $end, string $delimiter = ';'): void
    {
        $text = substr($sql, $start, $end - $start);

        if (str_ends_with(rtrim($text), $delimiter)) {
            $text = substr(rtrim($text), 0, -strlen($delimiter));
        }

        $leading = strlen($text) - strlen(ltrim($text));
        $trimmed = trim($text);

        if ($trimmed === '' || $this->onlyComments($trimmed)) {
            return;
        }

        $statements[] = ['sql' => $trimmed, 'start' => $start + $leading, 'end' => $end];
    }

    private function onlyComments(string $sql): bool
    {
        return trim((string) preg_replace(['~/\*.*?\*/~s', '~(--|#)[^\n]*~'], '', $sql)) === '';
    }

    /**
     * @param  list<string>  $words
     */
    private function definesRoutine(array $words): bool
    {
        if (($words[0] ?? '') !== 'CREATE') {
            return false;
        }

        foreach (array_slice($words, 1, 10) as $word) {
            if (in_array($word, self::ROUTINES, true)) {
                return true;
            }
        }

        return false;
    }

    private function atLineStart(string $sql, int $index): bool
    {
        $before = substr($sql, 0, $index);
        $line = strrchr($before, "\n");

        return trim($line === false ? $before : $line) === '';
    }

    private function lineEnd(string $sql, int $index): int
    {
        $end = strpos($sql, "\n", $index);

        return $end === false ? strlen($sql) : $end;
    }

    private function blockCommentEnd(string $sql, int $index, bool $nested): int
    {
        $depth = 0;
        $length = strlen($sql);

        while ($index < $length) {
            if (substr_compare($sql, '/*', $index, 2) === 0 && ($nested || $depth === 0)) {
                $depth++;
                $index += 2;
            } elseif (substr_compare($sql, '*/', $index, 2) === 0) {
                $depth--;
                $index += 2;

                if ($depth === 0) {
                    return $index;
                }
            } else {
                $index++;
            }
        }

        return $length;
    }

    private function quotedEnd(string $sql, int $index, string $quote, bool $backslash): int
    {
        $length = strlen($sql);
        $index++;

        while ($index < $length) {
            $char = $sql[$index];

            if ($backslash && $char === '\\') {
                $index += 2;

                continue;
            }

            if ($char === $quote) {
                if (($sql[$index + 1] ?? '') === $quote) {
                    $index += 2;

                    continue;
                }

                return $index + 1;
            }

            $index++;
        }

        return $length;
    }
}
