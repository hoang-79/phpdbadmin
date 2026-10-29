<?php

namespace App\Services\Data;

use RuntimeException;

/**
 * Die gesammelten Änderungen passen nicht zur Tabelle (unbekannte Spalte, ungültiges JSON, kein Primärschlüssel …) —
 * nichts wurde versucht.
 */
final class ChangeRejected extends RuntimeException {}
