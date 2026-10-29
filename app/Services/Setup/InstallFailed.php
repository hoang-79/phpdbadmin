<?php

namespace App\Services\Setup;

use RuntimeException;

/**
 * Die Einrichtung über den Web-Installer ist gescheitert — die Meldung zeigt der Installer so, wie sie ist.
 */
final class InstallFailed extends RuntimeException {}
