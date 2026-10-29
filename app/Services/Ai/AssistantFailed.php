<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Die KI-Hilfe konnte nicht antworten — die Meldung ist für Menschen geschrieben und schon übersetzt.
 */
final class AssistantFailed extends RuntimeException {}
