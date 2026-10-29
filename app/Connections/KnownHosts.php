<?php

namespace App\Connections;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Die eigene known_hosts von phpdbadmin für SSH-Tunnel (V-08, `storage/app/ssh/known_hosts`): beim ersten Verbinden
 * angenommen (`accept-new`), danach geprüft. Gespeichert unter dem Namen des SSH-Servers (`HostKeyAlias`), nicht
 * unter der Adresse — so fällt ein anderer Server unter demselben Namen auf.
 */
final class KnownHosts
{
    public function path(): string
    {
        $path = (string) config('phpdbadmin.ssh.known_hosts', storage_path('app/ssh/known_hosts'));
        $folder = dirname($path);

        if (! is_dir($folder)) {
            @mkdir($folder, 0700, true);
        }

        if (! is_file($path)) {
            @touch($path);
            @chmod($path, 0600);
        }

        return $path;
    }

    /** Name in known_hosts: «host» beim Standardport, sonst «[host]:port». */
    public static function name(string $host, int $port): string
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B[]"));

        return $port === SshSettings::DEFAULT_PORT ? $host : '['.$host.']:'.$port;
    }

    /**
     * Fingerabdruck des gespeicherten Host-Schlüssels («ED25519 SHA256:…»); null, solange noch keiner angenommen ist.
     */
    public function fingerprint(string $host, int $port): ?string
    {
        $keygen = self::executable('ssh-keygen');

        if ($keygen === null) {
            return null;
        }

        $process = new Process([$keygen, '-l', '-F', self::name($host, $port), '-f', $this->path()]);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        foreach (explode("\n", $process->getOutput()) as $line) {
            if (preg_match('/\s(\S+)\s+(SHA256:[A-Za-z0-9+\/=]+)/', $line, $match) === 1) {
                return strtoupper($match[1]).' '.$match[2];
            }
        }

        return null;
    }

    /** Gespeicherten Host-Schlüssel vergessen — beim nächsten Verbinden wird der neue angenommen. */
    public function forget(string $host, int $port): void
    {
        $keygen = self::executable('ssh-keygen');

        if ($keygen === null) {
            return;
        }

        (new Process([$keygen, '-R', self::name($host, $port), '-f', $this->path()]))->run();
        @unlink($this->path().'.old');
    }

    /** Ein Programm von OpenSSH: aus der Konfiguration oder dem Suchpfad (PHP-FPM hat oft einen kurzen). */
    public static function executable(string $name): ?string
    {
        $configured = config('phpdbadmin.ssh.'.($name === 'ssh' ? 'binary' : 'keygen'));

        if (is_string($configured) && $configured !== '' && str_contains($configured, '/')) {
            return is_executable($configured) ? $configured : null;
        }

        return (new ExecutableFinder)->find(is_string($configured) && $configured !== '' ? $configured : $name, null, ['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin']);
    }
}
