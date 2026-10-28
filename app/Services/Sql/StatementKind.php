<?php

namespace App\Services\Sql;

/**
 * Art einer Anweisung im SQL-Editor: lesend läuft immer im Schreibschutz-Wächter, schreibend nur mit Recht und auf
 * beschreibbaren Verbindungen, mit Eintrag im Protokoll.
 */
enum StatementKind: string
{
    case Read = 'read';
    case Write = 'write';
}
