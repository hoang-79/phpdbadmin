<?php

namespace App\Services\Transfer;

use App\Enums\DatabaseDriver;
use Generator;
use RuntimeException;

/**
 * Zerlegt eine SQL-Datei in einzelne Anweisungen (E-02), gelesen in Stücken — auch Dateien von mehreren hundert MB.
 * Kennt je Treiber: Zeichenketten mit '' (MySQL/MariaDB auch \', PostgreSQL bei E'…'), Namen in "…", `…` und [`…`]
 * (SQLite), Kommentare (`--`, `#` bei MySQL/MariaDB, verschachtelte /* *\/ bei PostgreSQL), ausführbare Kommentare
 * /*! … *\/ (MySQL/MariaDB, gehören zur Anweisung), $$-Blöcke (PostgreSQL), den Befehl DELIMITER des
 * MySQL-Clients und BEGIN … END in CREATE TRIGGER/PROCEDURE/FUNCTION/EVENT (CASE … END zählt mit, END IF/LOOP/WHILE/
 * REPEAT nicht).
 *
 * Grenzen (bewusst): BEGIN … END ausserhalb solcher CREATE-Anweisungen (anonyme Blöcke von MariaDB) braucht DELIMITER;
 * sql_mode ANSI_QUOTES bzw. NO_BACKSLASH_ESCAPES ändern die Regeln für Zeichenketten und werden nicht erkannt.
 */
final class SqlScript
{
    private const int CHUNK = 1_048_576;

    private const string WORD_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';

    /** So weit muss der Puffer über die aktuelle Stelle hinaus reichen, um Schlüsselwörter und Marken zu erkennen. */
    private const int LOOKAHEAD = 512;

    /** @var resource|null */
    private $handle = null;

    private string $buffer = '';

    private int $position = 0;

    /** Beginn der laufenden Anweisung im Puffer (davor darf der Puffer verworfen werden). */
    private int $start = 0;

    private int $line = 1;

    private int $dropped = 0;

    private bool $eof = false;

    private string $delimiter = ';';

    private bool $isMySql;

    private bool $isPostgres;

    private bool $isSqlite;

    public function __construct(private readonly string $path, DatabaseDriver $driver)
    {
        $this->isMySql = $driver === DatabaseDriver::MySql || $driver === DatabaseDriver::MariaDb;
        $this->isPostgres = $driver === DatabaseDriver::Postgres;
        $this->isSqlite = $driver === DatabaseDriver::Sqlite;
    }

    /**
     * Anweisungen ohne Trennzeichen, mit der Zeile, in der sie beginnen. Reine Kommentare ergeben keine Anweisung.
     *
     * @return Generator<int, array{sql: string, line: int}>
     */
    public function statements(): Generator
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('SQL file not readable');
        }

        $this->handle = $handle;
        $this->buffer = '';
        $this->position = $this->start = $this->dropped = 0;
        $this->line = 1;
        $this->eof = false;
        $this->delimiter = ';';

        try {
            $this->ensure(3);

            if (str_starts_with($this->buffer, Csv::BOM)) {
                $this->position = 3;
            }

            while (true) {
                $this->start = $this->position;
                $this->skipSpaceAndComments();
                $this->start = $this->position;

                if ($this->atEnd()) {
                    return;
                }

                if ($this->atDelimiter()) {
                    $this->move($this->position + strlen($this->delimiter));

                    continue;
                }

                if ($this->isMySql && $this->readDelimiterCommand()) {
                    continue;
                }

                $line = $this->line;
                $this->scanStatement();
                $sql = trim(substr($this->buffer, $this->start, $this->position - $this->start));

                if (! $this->atEnd()) {
                    $this->move($this->position + strlen($this->delimiter));
                }

                if ($sql !== '') {
                    yield ['sql' => $sql, 'line' => $line];
                }
            }
        } finally {
            fclose($handle);
            $this->handle = null;
        }
    }

    /** Gelesene Bytes bis zur aktuellen Stelle (für den Fortschritt). */
    public function offset(): int
    {
        return $this->dropped + $this->position;
    }

    /**
     * Erstes Schlüsselwort einer Anweisung in Grossbuchstaben (INSERT, CREATE, …), ohne führende Kommentare.
     */
    public static function keyword(string $sql): string
    {
        $text = (string) preg_replace('~^(?:\s+|--[^\n]*\n?|#[^\n]*\n?|/\*(?!!).*?\*/)+~s', '', $sql);
        $text = (string) preg_replace('~^/\*!\d*\s*~', '', $text);

        return preg_match('/^[A-Za-z]+/', $text, $match) === 1 ? strtoupper($match[0]) : '';
    }

    private function scanStatement(): void
    {
        $tracksBlocks = $this->delimiter === ';' && $this->startsRoutine();
        $depth = 0;
        $specials = "'\"`-#/\$;\n[".$this->delimiter[0];

        while (true) {
            $this->ensure(self::LOOKAHEAD);

            if ($this->atEnd()) {
                return;
            }

            $char = $this->buffer[$this->position];

            if ($this->delimiter !== ';') {
                if ($this->atDelimiter()) {
                    return;
                }
            } elseif ($char === ';' && $depth === 0) {
                return;
            }

            if ($tracksBlocks && (ctype_alnum($char) || $char === '_')) {
                $word = strtoupper($this->readWord());
                $depth += match ($word) {
                    'BEGIN', 'CASE' => 1,
                    'END' => in_array(strtoupper($this->peekWord()), ['IF', 'LOOP', 'WHILE', 'REPEAT'], true) ? 0 : -1,
                    default => 0,
                };
                $depth = max(0, $depth);

                continue;
            }

            if ($char === "'") {
                $this->skipString("'", $this->isMySql || ($this->isPostgres && $this->precededByEscapePrefix()));
            } elseif ($char === '"') {
                $this->skipString('"', $this->isMySql);
            } elseif ($char === '`' && ! $this->isPostgres) {
                $this->skipString('`', false);
            } elseif ($char === '[' && $this->isSqlite) {
                $this->skipUntil(']');
            } elseif ($this->atLineComment()) {
                $this->skipUntil("\n", inclusive: false);
            } elseif ($this->atBlockComment()) {
                $this->skipBlockComment();
            } elseif (! ($char === '$' && $this->isPostgres && $this->skipDollarQuote())) {
                $this->skipPlain($tracksBlocks ? $specials.self::WORD_CHARS : $specials);
            }
        }
    }

    /** Beginnt die Anweisung mit CREATE … TRIGGER/PROCEDURE/FUNCTION/EVENT? Nur dann zählen BEGIN und END. */
    private function startsRoutine(): bool
    {
        $this->ensure(self::LOOKAHEAD);
        $head = substr($this->buffer, $this->position, self::LOOKAHEAD);

        return preg_match(
            '/^create\s+(?:or\s+replace\s+)?(?:definer\s*=\s*(?:`[^`]*`|\'[^\']*\'|"[^"]*"|[^\s@]+)(?:\s*@\s*(?:`[^`]*`|\'[^\']*\'|"[^"]*"|\S+))?\s+)?(?:temp(?:orary)?\s+)?(?:if\s+not\s+exists\s+)?(?:trigger|procedure|function|event)\b/i',
            $head,
        ) === 1;
    }

    private function skipSpaceAndComments(): void
    {
        while (true) {
            $this->ensure(self::LOOKAHEAD);

            if ($this->atEnd()) {
                return;
            }

            $spaces = strspn($this->buffer, " \t\r\n\f\v", $this->position);

            if ($spaces > 0) {
                $this->move($this->position + $spaces);

                continue;
            }

            if ($this->atLineComment()) {
                $this->skipUntil("\n", inclusive: false);
            } elseif ($this->atBlockComment() && ! $this->atExecutableComment()) {
                $this->skipBlockComment();
            } else {
                return;
            }
        }
    }

    /** MySQL-Client: «DELIMITER ;;» am Anfang einer Anweisung setzt ein neues Trennzeichen bis zum Zeilenende. */
    private function readDelimiterCommand(): bool
    {
        $head = substr($this->buffer, $this->position, self::LOOKAHEAD);

        if (preg_match('/^delimiter[ \t]+(\S+)[^\n]*/i', $head, $match) !== 1) {
            return false;
        }

        $this->delimiter = $match[1];
        $this->move($this->position + strlen($match[0]));

        return true;
    }

    private function atLineComment(): bool
    {
        $char = $this->buffer[$this->position];

        if ($char === '#') {
            return $this->isMySql;
        }

        if ($char !== '-' || ($this->buffer[$this->position + 1] ?? '') !== '-') {
            return false;
        }

        // MySQL und MariaDB: «--» nur mit Leerraum danach ein Kommentar (sonst «5--3»).
        $after = $this->buffer[$this->position + 2] ?? '';

        return ! $this->isMySql || $after === '' || ctype_space($after) || ctype_cntrl($after);
    }

    private function atBlockComment(): bool
    {
        return $this->buffer[$this->position] === '/' && ($this->buffer[$this->position + 1] ?? '') === '*';
    }

    /** /*! … *\/ und /*M! … *\/: MySQL und MariaDB führen den Inhalt aus — er gehört zur Anweisung. */
    private function atExecutableComment(): bool
    {
        if (! $this->isMySql) {
            return false;
        }

        $third = $this->buffer[$this->position + 2] ?? '';

        return $third === '!' || ($third === 'M' && ($this->buffer[$this->position + 3] ?? '') === '!');
    }

    private function skipBlockComment(): void
    {
        $depth = 0;

        while (true) {
            $this->ensure(2);

            if ($this->atEnd()) {
                return;
            }

            if ($this->atBlockComment()) {
                $depth = $this->isPostgres || $depth === 0 ? $depth + 1 : $depth;
                $this->move($this->position + 2);

                continue;
            }

            if ($this->buffer[$this->position] === '*' && ($this->buffer[$this->position + 1] ?? '') === '/') {
                $this->move($this->position + 2);

                if (--$depth <= 0) {
                    return;
                }

                continue;
            }

            $next = strcspn($this->buffer, '/*', $this->position + 1) + $this->position + 1;
            $this->move(min($next, strlen($this->buffer)));
        }
    }

    /**
     * Zeichenkette oder Name bis zum schliessenden Zeichen; verdoppeltes Zeichen und (wo erlaubt) Backslash maskieren.
     */
    private function skipString(string $quote, bool $backslashEscapes): void
    {
        $this->move($this->position + 1);
        $stops = $backslashEscapes ? $quote.'\\' : $quote;

        while (true) {
            $offset = strcspn($this->buffer, $stops, $this->position);

            if ($this->position + $offset >= strlen($this->buffer)) {
                $this->move(strlen($this->buffer));

                if ($this->eof) {
                    return;
                }

                $this->fill();

                continue;
            }

            $this->move($this->position + $offset);
            $this->ensure(2);

            if ($this->buffer[$this->position] === '\\') {
                $this->move(min($this->position + 2, strlen($this->buffer)));

                continue;
            }

            if (($this->buffer[$this->position + 1] ?? '') === $quote) {
                $this->move($this->position + 2);

                continue;
            }

            $this->move($this->position + 1);

            return;
        }
    }

    private function skipUntil(string $end, bool $inclusive = true): void
    {
        while (true) {
            $found = strpos($this->buffer, $end, $this->position + 1);

            if ($found !== false) {
                $this->move($inclusive ? $found + strlen($end) : $found);

                return;
            }

            $this->move(strlen($this->buffer));

            if ($this->eof) {
                return;
            }

            $this->fill();
        }
    }

    /** PostgreSQL: $$ … $$ oder $marke$ … $marke$ — nicht, wenn das $ zu einem Namen gehört (a$b) oder $1 ist. */
    private function skipDollarQuote(): bool
    {
        $previous = $this->position > 0 ? $this->buffer[$this->position - 1] : ' ';

        if (ctype_alnum($previous) || $previous === '_' || $previous === '$') {
            return false;
        }

        if (preg_match('/\G\$([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)?\$/', $this->buffer, $match, 0, $this->position) !== 1) {
            return false;
        }

        $this->move($this->position + strlen($match[0]) - 1);
        $this->skipUntil($match[0]);

        return true;
    }

    private function skipPlain(string $stops): void
    {
        $offset = strcspn($this->buffer, $stops, $this->position + 1);
        $this->move(min($this->position + 1 + $offset, strlen($this->buffer)));
    }

    private function readWord(): string
    {
        $length = strspn($this->buffer, self::WORD_CHARS.'$', $this->position);
        $word = substr($this->buffer, $this->position, $length);
        $this->move($this->position + $length);

        return $word;
    }

    private function peekWord(): string
    {
        return preg_match('/\G\s*([A-Za-z]+)/', $this->buffer, $match, 0, $this->position) === 1 ? $match[1] : '';
    }

    private function precededByEscapePrefix(): bool
    {
        $previous = $this->position > 0 ? $this->buffer[$this->position - 1] : '';
        $before = $this->position > 1 ? $this->buffer[$this->position - 2] : ' ';

        return ($previous === 'E' || $previous === 'e') && ! ctype_alnum($before) && $before !== '_';
    }

    private function atDelimiter(): bool
    {
        return substr_compare($this->buffer, $this->delimiter, $this->position, strlen($this->delimiter)) === 0;
    }

    private function atEnd(): bool
    {
        return $this->eof && $this->position >= strlen($this->buffer);
    }

    /** Zur Stelle gehen und die überquerten Zeilen zählen. */
    private function move(int $to): void
    {
        if ($to > $this->position) {
            $this->line += substr_count($this->buffer, "\n", $this->position, $to - $this->position);
        }

        $this->position = $to;
    }

    private function ensure(int $bytes): void
    {
        while (! $this->eof && strlen($this->buffer) - $this->position < $bytes) {
            $this->fill();
        }
    }

    /** Nächstes Stück lesen; was vor der laufenden Anweisung liegt, wird verworfen. */
    private function fill(): void
    {
        if ($this->start > 0) {
            $this->buffer = substr($this->buffer, $this->start);
            $this->position -= $this->start;
            $this->dropped += $this->start;
            $this->start = 0;
        }

        $chunk = $this->handle === null ? false : fread($this->handle, self::CHUNK);

        if ($chunk === false || $chunk === '') {
            $this->eof = true;

            return;
        }

        $this->buffer .= $chunk;
    }
}
