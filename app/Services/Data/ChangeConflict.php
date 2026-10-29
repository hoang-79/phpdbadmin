<?php

namespace App\Services\Data;

use RuntimeException;

/**
 * Beim Anwenden traf eine Änderung nicht genau eine Zeile, oder eine Zeile hat sich seither geändert — die
 * Transaktion wurde zurückgerollt, nichts ist geschrieben (N-03).
 */
final class ChangeConflict extends RuntimeException {}
