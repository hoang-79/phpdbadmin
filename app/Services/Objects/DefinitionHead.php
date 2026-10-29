<?php

namespace App\Services\Objects;

use App\Enums\DatabaseDriver;
use App\Enums\ObjectKind;

/**
 * Der Kopf einer Definition, die eine Person selbst schreibt (S-06): `CREATE [OR REPLACE] … VIEW name` bzw.
 * `ALTER … name` — Verb, Art, Schema, Name und bei Triggern die Tabelle. Mehr liest phpdbadmin aus dem Text nicht;
 * den Rest prüft der Server. So lässt sich vor dem Ausführen sagen, ob die Anweisung das Objekt anlegt oder ändert,
 * das auf der Seite steht — und nicht ein anderes in einem anderen Schema.
 *
 * Bezeichner ohne Anführungszeichen faltet PostgreSQL in Kleinbuchstaben; MySQL, MariaDB und SQLite vergleichen
 * Namen von Routinen, Triggern und Ereignissen ohne Rücksicht auf Gross- und Kleinschreibung.
 */
final readonly class DefinitionHead
{
    /** Wörter zwischen CREATE und der Art, die phpdbadmin kennt und durchlässt. */
    private const array MODIFIERS = ['ALGORITHM', 'DEFINER', 'SQL', 'TEMP', 'TEMPORARY', 'RECURSIVE', 'CONSTRAINT', 'UNLOGGED'];

    /**
     * @param  'create'|'alter'  $verb
     * @param  array{0: int, 1: int}|null  $definer  Anfang und Ende der Klausel DEFINER = … im Text (MySQL, MariaDB)
     */
    public function __construct(
        public string $verb,
        public bool $orReplace,
        public bool $temporary,
        public string $keyword,
        public ?string $schema,
        public string $name,
        public ?string $table,
        public ?string $tableSchema,
        public ?array $definer,
        private DatabaseDriver $driver,
    ) {}

    /**
     * Liest den Kopf; null, wenn der Text nicht mit CREATE bzw. ALTER einer bekannten Art beginnt.
     */
    public static function parse(string $sql, DatabaseDriver $driver): ?self
    {
        $tokens = self::tokens($sql, $driver, 400);
        $position = 0;
        $word = fn (int $index): string => ($tokens[$index]['type'] ?? null) === 'word' ? strtoupper($tokens[$index]['value']) : '';

        $verb = $word($position);
        if (! in_array($verb, ['CREATE', 'ALTER'], true)) {
            return null;
        }
        $position++;

        $orReplace = false;
        if ($verb === 'CREATE' && $word($position) === 'OR' && $word($position + 1) === 'REPLACE') {
            $orReplace = true;
            $position += 2;
        }

        $temporary = false;
        $definer = null;

        while (in_array($word($position), self::MODIFIERS, true)) {
            $modifier = $word($position);
            $start = $tokens[$position]['start'];
            $position++;

            if ($modifier === 'ALGORITHM') {
                $position = self::skipAssignment($tokens, $position);
            } elseif ($modifier === 'DEFINER') {
                $position = self::skipDefiner($tokens, $position);
                $definer = [$start, $tokens[$position - 1]['end'] ?? $start];
            } elseif ($modifier === 'SQL') {
                $position += $word($position) === 'SECURITY' ? 2 : 0;
            } elseif ($modifier === 'TEMP' || $modifier === 'TEMPORARY') {
                $temporary = true;
            }
        }

        $keyword = $word($position);
        $known = array_merge(...array_map(fn (ObjectKind $kind): array => $kind->keywords(), ObjectKind::cases()));

        if (! in_array($keyword, $known, true)) {
            return null;
        }
        $position++;

        if ($word($position) === 'IF') {
            $position += $word($position + 1) === 'NOT' ? 3 : 2;
        }

        [$schema, $name, $position] = self::qualifiedName($tokens, $position, $driver);

        if ($name === null) {
            return null;
        }

        $table = null;
        $tableSchema = null;

        if ($keyword === 'TRIGGER') {
            for ($index = $position; $index < count($tokens); $index++) {
                if ($word($index) === 'ON') {
                    [$tableSchema, $table] = self::qualifiedName($tokens, $index + 1, $driver);

                    break;
                }
            }
        }

        return new self(strtolower($verb) === 'create' ? 'create' : 'alter', $orReplace, $temporary, $keyword, $schema, $name, $table, $tableSchema, $definer, $driver);
    }

    /** Gehört der Kopf zu dieser Art (Domänen sind bei PostgreSQL Typen)? */
    public function isKind(ObjectKind $kind): bool
    {
        return in_array($this->keyword, $kind->keywords(), true);
    }

    /** Ist das der Name des Objekts — nach den Regeln des Treibers? */
    public function names(string $name): bool
    {
        return self::sameName($this->name, $name, $this->driver);
    }

    /** Liegt das Objekt im Schema, oder steht keines im Text (dann gilt das Schema der Sitzung)? */
    public function isIn(string $schema): bool
    {
        return $this->schema === null || self::sameName($this->schema, $schema, $this->driver);
    }

    public static function sameName(string $a, string $b, DatabaseDriver $driver): bool
    {
        return $driver === DatabaseDriver::Postgres ? $a === $b : mb_strtolower($a) === mb_strtolower($b);
    }

    /** Der Text ohne die Klausel DEFINER = … — für Vorlagen und das Wiederherstellen unter dem eigenen Konto. */
    public static function withoutDefiner(string $sql, DatabaseDriver $driver): string
    {
        $head = self::parse($sql, $driver);

        if ($head?->definer === null) {
            return $sql;
        }

        [$start, $end] = $head->definer;

        return substr($sql, 0, $start).ltrim(substr($sql, $end));
    }

    /** `CREATE …` → `CREATE OR REPLACE …` (Vorlage zum Ändern, wo der Treiber es kann). */
    public static function withOrReplace(string $sql, DatabaseDriver $driver): string
    {
        $head = self::parse($sql, $driver);

        if ($head === null || $head->verb !== 'create' || $head->orReplace) {
            return $sql;
        }

        return (string) preg_replace('/^(\s*(?:(?:--[^\n]*|#[^\n]*)\n\s*|\/\*.*?\*\/\s*)*CREATE)\b/is', '$1 OR REPLACE', $sql, 1);
    }

    /**
     * Schema und Name ab einer Stelle: `name`, `schema.name`, jeweils mit oder ohne Anführungszeichen.
     *
     * @param  list<array{type: string, value: string, start: int, end: int}>  $tokens
     * @return array{0: string|null, 1: string|null, 2: int}
     */
    private static function qualifiedName(array $tokens, int $position, DatabaseDriver $driver): array
    {
        $first = self::identifier($tokens[$position] ?? null, $driver);

        if ($first === null) {
            return [null, null, $position];
        }

        if (self::isPunct($tokens, $position + 1, '.')) {
            $second = self::identifier($tokens[$position + 2] ?? null, $driver);

            if ($second === null || self::isPunct($tokens, $position + 3, '.')) {
                return [null, null, $position];
            }

            return [$first, $second, $position + 3];
        }

        return [null, $first, $position + 1];
    }

    /**
     * @param  array{type: string, value: string, start: int, end: int}|null  $token
     */
    private static function identifier(?array $token, DatabaseDriver $driver): ?string
    {
        return match ($token['type'] ?? null) {
            'ident' => $token['value'] !== '' ? $token['value'] : null,
            'word' => $driver === DatabaseDriver::Postgres ? strtolower($token['value']) : $token['value'],
            default => null,
        };
    }

    /**
     * `= wert` überspringen.
     *
     * @param  list<array{type: string, value: string, start: int, end: int}>  $tokens
     */
    private static function skipAssignment(array $tokens, int $position): int
    {
        return ($tokens[$position]['value'] ?? null) === '=' ? $position + 2 : $position;
    }

    /**
     * `= konto[@host]` bzw. `= CURRENT_USER[()]` überspringen.
     *
     * @param  list<array{type: string, value: string, start: int, end: int}>  $tokens
     */
    private static function skipDefiner(array $tokens, int $position): int
    {
        if (($tokens[$position]['value'] ?? null) !== '=') {
            return $position;
        }
        $position += 2;

        if (strtoupper($tokens[$position - 1]['value'] ?? '') === 'CURRENT_USER' && ($tokens[$position]['value'] ?? null) === '(') {
            return $position + 2;
        }

        // Konto und Host stehen als drei Teile da: 'app' @ '%' (auch ohne Anführungszeichen).
        if (self::isPunct($tokens, $position, '@')) {
            return $position + 2;
        }

        return $position;
    }

    /**
     * @param  list<array{type: string, value: string, start: int, end: int}>  $tokens
     */
    private static function isPunct(array $tokens, int $index, string $char): bool
    {
        $token = $tokens[$index] ?? null;

        return $token !== null && $token['type'] === 'punct' && $token['value'] === $char;
    }

    /**
     * Wörter, Bezeichner in Anführungszeichen, Zeichenketten und Satzzeichen — ohne Kommentare und Leerraum.
     *
     * @return list<array{type: string, value: string, start: int, end: int}>
     */
    public static function tokens(string $sql, DatabaseDriver $driver, int $limit = PHP_INT_MAX): array
    {
        $isMySql = in_array($driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true);
        $length = strlen($sql);
        $tokens = [];
        $index = 0;

        while ($index < $length && count($tokens) < $limit) {
            $char = $sql[$index];
            $next = $sql[$index + 1] ?? '';

            if (ctype_space($char)) {
                $index++;

                continue;
            }

            if (($char === '-' && $next === '-') || ($char === '#' && $isMySql)) {
                $end = strpos($sql, "\n", $index);
                $index = $end === false ? $length : $end + 1;

                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $index + 2);
                $index = $end === false ? $length : $end + 2;

                continue;
            }

            $close = match (true) {
                $char === '"' => '"',
                $char === '`' && $driver !== DatabaseDriver::Postgres => '`',
                $char === '[' && $driver === DatabaseDriver::Sqlite => ']',
                $char === "'" => "'",
                default => null,
            };

            if ($close !== null) {
                [$value, $end] = self::quoted($sql, $index, $close, $isMySql && $char === "'");
                $isString = $char === "'" || ($char === '"' && $isMySql);
                $tokens[] = ['type' => $isString ? 'string' : 'ident', 'value' => $value, 'start' => $index, 'end' => $end];
                $index = $end;

                continue;
            }

            if (preg_match('/\G[\w$\x80-\xFF]+/', $sql, $match, 0, $index) === 1) {
                $tokens[] = ['type' => 'word', 'value' => $match[0], 'start' => $index, 'end' => $index + strlen($match[0])];
                $index += strlen($match[0]);

                continue;
            }

            $tokens[] = ['type' => 'punct', 'value' => $char, 'start' => $index, 'end' => $index + 1];
            $index++;
        }

        return $tokens;
    }

    /**
     * Inhalt in Anführungszeichen bis zum schliessenden; verdoppelt heisst «eines» (bei `[…]` gibt es das nicht).
     *
     * @return array{0: string, 1: int} Inhalt und Position hinter dem schliessenden Zeichen
     */
    private static function quoted(string $sql, int $start, string $close, bool $backslash): array
    {
        $length = strlen($sql);
        $value = '';

        for ($index = $start + 1; $index < $length; $index++) {
            $char = $sql[$index];

            if ($backslash && $char === '\\' && $index + 1 < $length) {
                $value .= $sql[++$index];

                continue;
            }

            if ($char === $close) {
                if ($close !== ']' && ($sql[$index + 1] ?? '') === $close) {
                    $value .= $close;
                    $index++;

                    continue;
                }

                return [$value, $index + 1];
            }

            $value .= $char;
        }

        return [$value, $length];
    }
}
