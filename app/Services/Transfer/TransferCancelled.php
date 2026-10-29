<?php

namespace App\Services\Transfer;

use RuntimeException;

/**
 * Die Person hat den Auftrag abgebrochen; der Lauf hält beim nächsten Paket an (Export: Datei weg, Import: alles
 * zurückgerollt).
 */
final class TransferCancelled extends RuntimeException {}
