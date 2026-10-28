<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * Ein Stück SQL, das bewusst nicht aus Literalen besteht: gequotete Spaltennamen der Tabelle selbst oder SQL, das
 * die Person ausdrücklich selbst schreibt (freies WHERE, SQL-Editor). Werte gehören nie hierher — die werden
 * gebunden. Jede Verwendung begründet im Aufruf, warum sie sicher ist; die Suche nach «TrustedSql» zeigt alle Stellen
 * für die Sicherheitsprüfung (N-02).
 */
final readonly class TrustedSql implements Expression
{
    public function __construct(private string $sql) {}

    public function getValue(Grammar $grammar): string
    {
        return $this->sql;
    }
}
