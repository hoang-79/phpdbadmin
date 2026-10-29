<?php

namespace App\Support;

/**
 * Werte aus SQL entfernen, bevor es ins Protokoll geht (B-04, F-7): Zeichenketten und Zahlen-Literale werden zu «?».
 * Bezeichner und Schlüsselwörter bleiben, damit man sieht, was geändert wurde.
 */
final class SqlMask
{
    public static function mask(string $sql): string
    {
        return (string) preg_replace(
            ["~'(?:[^'\\\\]|\\\\.|'')*'~s", '~\$([A-Za-z_]\w*)?\$.*?\$\1\$~s', '~(?<![\w.$"`])-?\d+(?:\.\d+)?(?:e[+-]?\d+)?\b~i'],
            ['?', '?', '?'],
            $sql,
        );
    }
}
