<?php

namespace App\Services\Sql;

use App\Enums\DatabaseDriver;

/**
 * Erkennt eine Abfrage, deren Ergebnis zu genau einer Tabelle gehört (Q-05): `SELECT liste FROM [schema.]tabelle
 * [[AS] alias] [WHERE …] [ORDER BY …] [LIMIT …] [OFFSET …] [FETCH …]`. Bewusst eng — alles andere (Join, mehrere
 * Tabellen, GROUP BY, UNION, DISTINCT, WITH, Unterabfrage als Quelle, Hinweise an den Server, ausführbare Kommentare
 * von MySQL) gilt als «nur lesen». Unterabfragen in WHERE stören nicht: Die Zeilen kommen trotzdem aus der einen
 * Tabelle.
 *
 * Jede Spalte der Liste ist ein Stern, eine Spalte der Tabelle (auch mit Tabelle oder Alias davor und mit Alias
 * dahinter) oder ein Ausdruck — Ausdrücke lassen sich nicht bearbeiten. Ob die Tabelle und die Spalten wirklich
 * existieren und ob das Ergebnis dazu passt, prüft EditableResult gegen den Katalog und die Angaben des Treibers.
 *
 * @phpstan-type Token array{type: 'word'|'quoted'|'string'|'number'|'punct'|'other', text: string}
 * @phpstan-type Item array{kind: 'star'}|array{kind: 'column', name: string, quoted: bool, qualifier: string|null, qualifierQuoted: bool, alias: string|null}|array{kind: 'expression'}
 */
final readonly class SingleTableSelect
{
    public const string NOT_SELECT = 'not-select';

    public const string NO_TABLE = 'no-table';

    public const string SEVERAL_TABLES = 'several-tables';

    public const string GROUPED = 'grouped';

    public const string DISTINCT = 'distinct';

    public const string SET_OPERATION = 'set-operation';

    public const string UNSUPPORTED = 'unsupported';

    /** Wörter, die nach der Tabelle eine Klausel beginnen, die das Ergebnis nicht von der Tabelle löst. */
    private const array CLAUSES = ['WHERE', 'ORDER', 'LIMIT', 'OFFSET', 'FETCH'];

    /** Wörter, die überall auf oberster Ebene das Bearbeiten ausschliessen. */
    private const array JOINS = ['JOIN', 'INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS', 'NATURAL', 'STRAIGHT_JOIN', 'LATERAL', 'USING', 'ON', 'APPLY'];

    private const array GROUPING = ['GROUP', 'HAVING', 'WINDOW', 'QUALIFY'];

    private const array SET_OPERATIONS = ['UNION', 'INTERSECT', 'EXCEPT', 'MINUS'];

    /** Einzelne Wörter, die ein Wert sind und keine Spalte. */
    private const array VALUE_WORDS = ['NULL', 'TRUE', 'FALSE', 'DEFAULT', 'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'CURRENT_USER', 'CURRENT_ROLE', 'CURRENT_SCHEMA', 'CURRENT_CATALOG', 'SESSION_USER', 'SYSTEM_USER', 'USER', 'LOCALTIME', 'LOCALTIMESTAMP'];

    /**
     * @param  list<Item>  $items
     */
    public function __construct(
        public ?string $schema,
        public bool $schemaQuoted,
        public string $table,
        public bool $tableQuoted,
        public ?string $alias,
        public bool $aliasQuoted,
        public array $items,
    ) {}

    /**
     * Die Abfrage, wenn sie genau eine Tabelle liest — sonst der Grund (eine der Konstanten).
     */
    public static function parse(string $sql, DatabaseDriver $driver): self|string
    {
        $tokens = self::tokens($sql, $driver);

        if ($tokens === null) {
            return self::UNSUPPORTED;
        }

        while ($tokens !== [] && $tokens[array_key_last($tokens)]['text'] === ';') {
            array_pop($tokens);
        }

        if ($tokens === [] || ! self::isWord($tokens[0], 'SELECT')) {
            return self::NOT_SELECT;
        }

        // Ebene 0 durchgehen: Klammern zählen, die Stelle von FROM merken, verbotene Wörter erkennen.
        $depth = 0;
        $from = null;

        foreach ($tokens as $position => $token) {
            if ($token['text'] === '(') {
                $depth++;
            } elseif ($token['text'] === ')') {
                $depth--;
            }

            if ($depth < 0) {
                return self::UNSUPPORTED;
            }

            if ($depth !== 0 || $token['type'] !== 'word') {
                continue;
            }

            $word = strtoupper($token['text']);

            if (in_array($word, self::SET_OPERATIONS, true)) {
                return self::SET_OPERATION;
            }

            if (in_array($word, self::GROUPING, true)) {
                return self::GROUPED;
            }

            if (in_array($word, self::JOINS, true)) {
                return self::SEVERAL_TABLES;
            }

            if (in_array($word, ['INTO', 'FOR', 'LOCK', 'PROCEDURE'], true)) {
                return self::UNSUPPORTED;
            }

            if ($word === 'FROM' && $from === null) {
                $from = $position;
            }
        }

        if ($depth !== 0) {
            return self::UNSUPPORTED;
        }

        if ($from === null) {
            return self::NO_TABLE;
        }

        $list = array_slice($tokens, 1, $from - 1);

        if ($list !== [] && self::isWord($list[0], 'ALL')) {
            array_shift($list);
        }

        if ($list !== [] && self::isWord($list[0], 'DISTINCT', 'DISTINCTROW')) {
            return self::DISTINCT;
        }

        $items = [];
        foreach (self::splitByComma($list) as $item) {
            $parsed = self::item($item);

            if ($parsed === null) {
                return self::UNSUPPORTED;
            }

            $items[] = $parsed;
        }

        return self::source(array_slice($tokens, $from + 1), $items);
    }

    /**
     * Quelle nach FROM: Tabelle, Alias, danach nur noch WHERE, ORDER, LIMIT, OFFSET, FETCH.
     *
     * @param  list<Token>  $rest
     * @param  list<Item>  $items
     */
    private static function source(array $rest, array $items): self|string
    {
        $names = [];
        $position = 0;

        while (true) {
            $token = $rest[$position] ?? null;

            if ($token === null) {
                return self::NO_TABLE;
            }

            if (! self::isName($token) || ($token['type'] === 'word' && self::isKeyword($token))) {
                return self::UNSUPPORTED;
            }

            $names[] = $token;
            $position++;

            if (($rest[$position]['text'] ?? null) !== '.') {
                break;
            }

            $position++;
        }

        if (count($names) > 2) {
            return self::UNSUPPORTED;
        }

        $alias = null;
        $next = $rest[$position] ?? null;

        if ($next !== null && self::isWord($next, 'AS')) {
            $position++;
            $next = $rest[$position] ?? null;

            if ($next === null || ! self::isName($next)) {
                return self::UNSUPPORTED;
            }
        }

        if ($next !== null && self::isName($next) && ! ($next['type'] === 'word' && self::isKeyword($next))) {
            $alias = $next;
            $position++;
            $next = $rest[$position] ?? null;
        }

        if ($next !== null && $next['text'] === ',') {
            return self::SEVERAL_TABLES;
        }

        if ($next !== null && ! ($next['type'] === 'word' && in_array(strtoupper($next['text']), self::CLAUSES, true))) {
            return self::UNSUPPORTED;
        }

        $table = $names[count($names) - 1];
        $schema = count($names) === 2 ? $names[0] : null;

        return new self(
            schema: $schema['text'] ?? null,
            schemaQuoted: ($schema['type'] ?? null) === 'quoted',
            table: $table['text'],
            tableQuoted: $table['type'] === 'quoted',
            alias: $alias['text'] ?? null,
            aliasQuoted: ($alias['type'] ?? null) === 'quoted',
            items: $items,
        );
    }

    /**
     * Eine Spalte der Liste: Stern, Spalte (mit Tabelle davor, mit Alias) oder Ausdruck; null für Unbrauchbares.
     *
     * @param  list<Token>  $tokens
     * @return Item|null
     */
    private static function item(array $tokens): ?array
    {
        if ($tokens === []) {
            return null;
        }

        // «*», «t.*», «s.t.*»: Namen und Punkte im Wechsel, am Ende der Stern.
        $last = count($tokens) - 1;

        if ($tokens[$last]['text'] === '*' && ($last === 0 || $tokens[$last - 1]['text'] === '.')) {
            foreach (array_slice($tokens, 0, $last) as $position => $token) {
                if ($position % 2 === 0 ? ! self::isName($token) : $token['text'] !== '.') {
                    return null;
                }
            }

            return $last <= 4 ? ['kind' => 'star'] : null;
        }

        // Name ( . Name ){0,2} — dann wahlweise [AS] Alias.
        $parts = [];
        $position = 0;

        while (isset($tokens[$position]) && self::isName($tokens[$position])) {
            $parts[] = $tokens[$position];
            $position++;

            if (($tokens[$position]['text'] ?? null) !== '.') {
                break;
            }

            $position++;
        }

        $rest = array_slice($tokens, $position);
        $alias = null;

        if ($rest !== [] && self::isWord($rest[0], 'AS')) {
            array_shift($rest);
        }

        if (count($rest) === 1 && (self::isName($rest[0]) || $rest[0]['type'] === 'string')) {
            $alias = $rest[0]['text'];
            $rest = [];
        }

        $isColumn = $parts !== [] && count($parts) <= 3 && $rest === [] && ($tokens[$position - 1]['text'] ?? '') !== '.'
            && ! ($parts[count($parts) - 1]['type'] === 'word' && count($parts) === 1 && in_array(strtoupper($parts[0]['text']), self::VALUE_WORDS, true));

        if (! $isColumn) {
            return ['kind' => 'expression'];
        }

        $column = $parts[count($parts) - 1];
        $qualifier = count($parts) >= 2 ? $parts[count($parts) - 2] : null;

        return [
            'kind' => 'column',
            'name' => $column['text'],
            'quoted' => $column['type'] === 'quoted',
            'qualifier' => $qualifier['text'] ?? null,
            'qualifierQuoted' => ($qualifier['type'] ?? null) === 'quoted',
            'alias' => $alias,
        ];
    }

    /**
     * @param  list<Token>  $tokens
     * @return list<list<Token>>
     */
    private static function splitByComma(array $tokens): array
    {
        $items = [[]];
        $depth = 0;

        foreach ($tokens as $token) {
            if ($token['text'] === '(') {
                $depth++;
            } elseif ($token['text'] === ')') {
                $depth--;
            }

            if ($depth === 0 && $token['text'] === ',') {
                $items[] = [];

                continue;
            }

            $items[count($items) - 1][] = $token;
        }

        return $items;
    }

    /**
     * Zerlegt in Wörter, gequotete Namen, Zeichenketten, Zahlen und Zeichen. Kommentare fallen weg; ausführbare
     * Kommentare von MySQL (`/*! … *\/`) ergeben null — ihr Inhalt wäre SQL.
     *
     * @return list<Token>|null
     */
    private static function tokens(string $sql, DatabaseDriver $driver): ?array
    {
        $isMySql = $driver === DatabaseDriver::MySql || $driver === DatabaseDriver::MariaDb;
        $isPostgres = $driver === DatabaseDriver::Postgres;
        $tokens = [];
        $length = strlen($sql);
        $index = 0;

        while ($index < $length) {
            $char = $sql[$index];
            $next = $sql[$index + 1] ?? '';

            if (ctype_space($char)) {
                $index++;

                continue;
            }

            if ($char === '-' && $next === '-' && (! $isMySql || in_array($sql[$index + 2] ?? "\n", [' ', "\t", "\n", "\r"], true))) {
                $end = strpos($sql, "\n", $index);
                $index = $end === false ? $length : $end + 1;

                continue;
            }

            if ($char === '#' && $isMySql) {
                $end = strpos($sql, "\n", $index);
                $index = $end === false ? $length : $end + 1;

                continue;
            }

            if ($char === '/' && $next === '*') {
                if ($isMySql && in_array($sql[$index + 2] ?? '', ['!', 'M'], true)) {
                    return null;
                }

                $index = self::commentEnd($sql, $index, $isPostgres);

                if ($index < 0) {
                    return null;
                }

                continue;
            }

            if ($char === "'" || ($isMySql && $char === '"') || ($isPostgres && ($char === 'E' || $char === 'e') && $next === "'")) {
                $start = $char === "'" || $char === '"' ? $index : $index + 1;
                $quote = $sql[$start];
                $backslash = $isMySql || $start !== $index;
                $end = self::quotedEnd($sql, $start, $quote, $backslash);

                if ($end < 0) {
                    return null;
                }

                $tokens[] = ['type' => 'string', 'text' => substr($sql, $start + 1, $end - $start - 1)];
                $index = $end + 1;

                continue;
            }

            if ($isPostgres && $char === '$' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $match, 0, $index) === 1) {
                $end = strpos($sql, $match[0], $index + strlen($match[0]));

                if ($end === false) {
                    return null;
                }

                $tokens[] = ['type' => 'string', 'text' => substr($sql, $index + strlen($match[0]), $end - $index - strlen($match[0]))];
                $index = $end + strlen($match[0]);

                continue;
            }

            if ($char === '`' || (! $isMySql && $char === '"') || ($driver === DatabaseDriver::Sqlite && $char === '[')) {
                $close = $char === '[' ? ']' : $char;
                $end = $char === '[' ? strpos($sql, ']', $index + 1) : self::quotedEnd($sql, $index, $close, false);

                if ($end === false || $end < 0) {
                    return null;
                }

                $tokens[] = ['type' => 'quoted', 'text' => str_replace($close.$close, $close, substr($sql, $index + 1, $end - $index - 1))];
                $index = $end + 1;

                continue;
            }

            if (preg_match('/\G[\p{L}_][\p{L}\p{N}_$]*/u', $sql, $match, 0, $index) === 1) {
                $tokens[] = ['type' => 'word', 'text' => $match[0]];
                $index += strlen($match[0]);

                continue;
            }

            if (preg_match('/\G(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?/', $sql, $match, 0, $index) === 1) {
                $tokens[] = ['type' => 'number', 'text' => $match[0]];
                $index += strlen($match[0]);

                continue;
            }

            $tokens[] = ['type' => in_array($char, ['(', ')', ',', '.', ';', '*'], true) ? 'punct' : 'other', 'text' => $char];
            $index++;
        }

        return $tokens;
    }

    /** Ende eines Kommentars `/* … *\/` (PostgreSQL verschachtelt) — Position danach, -1 wenn offen. */
    private static function commentEnd(string $sql, int $index, bool $nested): int
    {
        $depth = 0;
        $length = strlen($sql);

        while ($index < $length - 1) {
            $pair = substr($sql, $index, 2);

            if ($pair === '/*' && ($nested || $depth === 0)) {
                $depth++;
                $index += 2;
            } elseif ($pair === '*/') {
                $depth--;
                $index += 2;

                if ($depth === 0) {
                    return $index;
                }
            } else {
                $index++;
            }
        }

        return -1;
    }

    /** Position des schliessenden Zeichens; verdoppelt gilt als Zeichen, mit Backslash davor auch. -1 wenn offen. */
    private static function quotedEnd(string $sql, int $start, string $quote, bool $backslash): int
    {
        $length = strlen($sql);

        for ($index = $start + 1; $index < $length; $index++) {
            if ($backslash && $sql[$index] === '\\') {
                $index++;

                continue;
            }

            if ($sql[$index] === $quote) {
                if (($sql[$index + 1] ?? '') === $quote) {
                    $index++;

                    continue;
                }

                return $index;
            }
        }

        return -1;
    }

    /**
     * @param  Token  $token
     */
    private static function isWord(array $token, string ...$words): bool
    {
        return $token['type'] === 'word' && in_array(strtoupper($token['text']), $words, true);
    }

    /**
     * @param  Token  $token
     */
    private static function isName(array $token): bool
    {
        return $token['type'] === 'word' || $token['type'] === 'quoted';
    }

    /**
     * Wörter, die nach der Tabelle kein Alias sein können.
     *
     * @param  Token  $token
     */
    private static function isKeyword(array $token): bool
    {
        return in_array(strtoupper($token['text']), [...self::CLAUSES, ...self::JOINS, ...self::GROUPING, ...self::SET_OPERATIONS, 'AS', 'SELECT', 'FROM', 'ONLY', 'TABLESAMPLE', 'PARTITION', 'USE', 'FORCE', 'IGNORE', 'INDEXED', 'NOT', 'AND', 'OR', 'WITH'], true);
    }
}
