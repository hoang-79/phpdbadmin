<?php

namespace App\Connections;

use Symfony\Component\Process\Process;

/**
 * Ein laufender SSH-Tunnel (V-08): der Prozess `ssh -N -L` und das lokale Ende auf 127.0.0.1. Wird am Ende der
 * Anfrage beendet (SshTunnels), spätestens, wenn das Objekt verschwindet.
 */
final class SshTunnel
{
    public function __construct(
        private readonly Process $process,
        public readonly int $port,
        public readonly string $knownHostsName,
    ) {}

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    /** Was ssh gemeldet hat — ohne Geheimnisse, ssh gibt weder Schlüssel noch Passphrase aus. */
    public function errorOutput(): string
    {
        return trim($this->process->getErrorOutput());
    }

    public function close(): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop(1);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
