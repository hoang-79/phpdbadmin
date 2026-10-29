<?php

namespace App\Services\Audit;

use DateTimeInterface;
use Illuminate\Database\QueryException;
use Stringable;
use Throwable;

/**
 * Werte aus Zieldatenbanken verlassen den Server weder über das Protokoll noch über Meldungen (B-04, T-11).
 * Laravel setzt gebundene Werte in die Meldung einer QueryException ein («SQL: … where email = x@y»), und die
 * Server zitieren Werte in ihren eigenen Meldungen («Duplicate entry 'x@y'», «Key (email)=(x@y)»). Diese Klasse
 * ersetzt beides durch Platzhalter (`?`), bevor etwas angezeigt, protokolliert oder gemeldet wird.
 *
 * Lieber zu viel als zu wenig: Auch Schlüsselnamen in Anführungszeichen werden zu `?` — eine Meldung bleibt so
 * verständlich genug, ein Wert erscheint nie.
 */
final class Masking
{
    public const string PLACEHOLDER = '?';

    /**
     * SQL mit Platzhaltern: Zeichenketten- und Zahl-Literale werden zu `?`; Bezeichner in `…` (und "…", wo sie
     * Bezeichner sind), Kommentare und Schlüsselwörter bleiben. Gebundene Werte, die doch im Text stehen, ebenfalls.
     *
     * @param  array<array-key, mixed>  $bindings
     * @param  bool  $doubleQuotedStrings  MySQL und MariaDB ohne ANSI_QUOTES: "…" ist eine Zeichenkette
     */
    public static function sql(string $sql, array $bindings = [], bool $doubleQuotedStrings = false): string
    {
        $masked = (string) preg_replace_callback(
            '/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"|`(?:[^`]|``)*`|--[^\n]*|\/\*.*?\*\/|(?<![\w$.:])-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?(?![\w$])/s',
            function (array $match) use ($doubleQuotedStrings): string {
                $token = $match[0];

                return match (true) {
                    str_starts_with($token, "'") => self::PLACEHOLDER,
                    str_starts_with($token, '"') => $doubleQuotedStrings ? self::PLACEHOLDER : $token,
                    str_starts_with($token, '`'), str_starts_with($token, '--'), str_starts_with($token, '/*') => $token,
                    default => self::PLACEHOLDER,
                };
            },
            $sql,
        );

        return self::bindings($masked, $bindings);
    }

    /**
     * Nur Geheimnisse ersetzen, der Rest bleibt lesbar — für den Verlauf im SQL-Editor (N-02): Passwörter nach
     * `IDENTIFIED BY`, `IDENTIFIED WITH … BY`, `PASSWORD`, `PASSWORD(…)`, `SET PASSWORD FOR … = …` werden zu `?`.
     */
    public static function secrets(string $sql): string
    {
        return (string) preg_replace(
            '/\b((?:IDENTIFIED(?:\s+WITH\s+\w+)?\s+(?:BY|AS)|PASSWORD(?:\s+FOR\s+\S+)?)\s*(?:=\s*|\(\s*)?)(?:\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*")/i',
            '$1'.self::PLACEHOLDER,
            $sql,
        );
    }

    /**
     * Meldung eines Servers oder von Laravel: eingesetzte Bindungen, Zeichenketten in '…' und die Stellen, an denen
     * die Server Werte ohne Anführungszeichen zitieren, werden zu `?`.
     *
     * @param  array<array-key, mixed>  $bindings
     */
    public static function message(string $message, array $bindings = []): string
    {
        $patterns = [
            // MySQL/MariaDB-Syntaxfehler zitieren ein Stück der Anweisung: «near '…' at line 1».
            '/\bnear \'.*\' at line (\d+)/s' => 'near '.self::PLACEHOLDER.' at line $1',
            // PostgreSQL: «Key (email)=(x@y) already exists.», «Failing row contains (1, x@y).»
            '/\b(Key \([^)]*\))=\((?:[^()]|\([^()]*\))*\)/' => '$1=('.self::PLACEHOLDER.')',
            '/\b(Failing row contains) \((?:[^()]|\([^()]*\))*\)/' => '$1 ('.self::PLACEHOLDER.')',
            // PostgreSQL: «invalid input syntax for type integer: "abc"», «invalid input value for enum s: "x"».
            '/\b(invalid input (?:syntax|value) for [^:]*:) "(?:[^"]|"")*"/' => '$1 '.self::PLACEHOLDER,
            // psql beim Einspielen: «CONTEXT:  COPY kunden, line 3: "3	Jürg	…"» bzw. «…, column name: "Jürg"».
            '/\b(COPY [^,\n]+, line \d+(?:, column [^:\n]+)?): .*$/m' => '$1: '.self::PLACEHOLDER,
            // Zeichenketten in einfachen Anführungszeichen (auch mit '' bzw. \' darin).
            '/\'(?:[^\'\\\\]|\\\\.|\'\')*\'/s' => self::PLACEHOLDER,
        ];

        $masked = self::bindings($message, $bindings);

        foreach ($patterns as $pattern => $replacement) {
            $masked = (string) preg_replace($pattern, $replacement, $masked);
        }

        return $masked;
    }

    /**
     * Meldung einer Ausnahme; bei einer QueryException mit ihren Bindungen und ohne die eingesetzte Anweisung —
     * an deren Stelle steht die Anweisung mit Platzhaltern.
     */
    public static function exception(Throwable $exception): string
    {
        if (! $exception instanceof QueryException) {
            return self::message($exception->getMessage());
        }

        $bindings = $exception->getBindings();
        $previous = $exception->getPrevious();
        $driverMessage = $previous instanceof Throwable ? $previous->getMessage() : $exception->getMessage();

        return self::message($driverMessage, $bindings).' (SQL: '.self::sql($exception->getSql(), $bindings).')';
    }

    /**
     * Gebundene Werte ersetzen, an Wortgrenzen — «1» soll nicht die «1» in «1062» treffen. Der längste zuerst, damit
     * «4711» nicht als «47» plus Rest bleibt. Leere Werte, null und Wahrheitswerte verraten nichts.
     *
     * @param  array<array-key, mixed>  $bindings
     */
    private static function bindings(string $text, array $bindings): string
    {
        $values = [];

        foreach ($bindings as $binding) {
            $value = match (true) {
                $binding instanceof DateTimeInterface => $binding->format('Y-m-d H:i:s'),
                is_string($binding), is_int($binding), is_float($binding), $binding instanceof Stringable => (string) $binding,
                default => '',
            };

            if (trim($value) !== '') {
                $values[$value] = mb_strlen($value);
            }
        }

        arsort($values);

        foreach (array_keys($values) as $value) {
            $value = (string) $value;
            $before = preg_match('/^\w/u', $value) === 1 ? '(?<!\w)' : '';
            $after = preg_match('/\w$/u', $value) === 1 ? '(?!\w)' : '';
            $text = (string) preg_replace('/'.$before.preg_quote($value, '/').$after.'/u', self::PLACEHOLDER, $text);
        }

        return $text;
    }
}
