<?php

namespace App\Services\Release;

use RuntimeException;

/**
 * Das Release liess sich nicht bauen — mit einer Meldung, die sagt, was zu tun ist.
 */
final class ReleaseFailed extends RuntimeException {}
