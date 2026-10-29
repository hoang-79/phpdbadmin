<?php

namespace App\Connections;

use Illuminate\Database\Connection;
use Symfony\Component\Process\Process;
use WeakMap;

/**
 * SSH-Tunnel einer Anfrage (V-08, Entscheid TB 11: das System-`ssh` als Hilfsprozess, keine PHP-Erweiterung). Ein
 * Tunnel je SSH-Server und Ziel; braucht eine Anfrage mehrere Verbindungen über denselben Tunnel (lesen, schreiben,
 * abbrechen), teilen sie ihn. Freigegeben wird mit der Verbindung (ConnectionFactory::release), am Ende der Anfrage
 * alles Übrige — auch nach einem Fehler.
 *
 * Der private Schlüssel liegt nur so lange als Datei (0600) vor, bis ssh angemeldet ist; eine Passphrase entfernt
 * `ssh-keygen -p` aus dieser Kopie, die Passphrase geht über die Standardeingabe, nie über die Befehlszeile.
 */
final class SshTunnels
{
    /** @var array<string, array{tunnel: SshTunnel, users: int}> */
    private array $open = [];

    /** @var WeakMap<Connection, SshTunnel> */
    private WeakMap $attached;

    private bool $shutdownRegistered = false;

    public function __construct(private readonly KnownHosts $knownHosts)
    {
        $this->attached = new WeakMap;
    }

    /**
     * Tunnel zum Ziel öffnen oder einen offenen mitbenutzen.
     *
     * @param  string  $sshAddress  geprüfte Adresse des SSH-Servers (HostPolicy)
     * @param  string  $target  Ziel aus Sicht des SSH-Servers (HostPolicy::resolveTunnelTarget)
     *
     * @throws TunnelFailed
     * @throws ConnectionNotAllowed Datei des Schlüssels nicht erlaubt
     */
    public function open(SshSettings $ssh, string $sshAddress, string $target, int $targetPort, HostPolicy $policy): SshTunnel
    {
        $key = hash('xxh128', $ssh->fingerprint().'|'.$sshAddress.'|'.$target.'|'.$targetPort);

        if (isset($this->open[$key]) && $this->open[$key]['tunnel']->isRunning()) {
            $this->open[$key]['users']++;

            return $this->open[$key]['tunnel'];
        }

        $tunnel = $this->start($ssh, $sshAddress, $target, $targetPort, $policy);
        $this->open[$key] = ['tunnel' => $tunnel, 'users' => 1];
        $this->registerShutdown();

        return $tunnel;
    }

    /** Die Verbindung läuft über diesen Tunnel — freigegeben wird er mit ihr. */
    public function attach(Connection $connection, SshTunnel $tunnel): void
    {
        $this->attached[$connection] = $tunnel;
    }

    public function releaseFor(Connection $connection): void
    {
        if (isset($this->attached[$connection])) {
            $tunnel = $this->attached[$connection];
            unset($this->attached[$connection]);
            $this->release($tunnel);
        }
    }

    public function release(SshTunnel $tunnel): void
    {
        foreach ($this->open as $key => $entry) {
            if ($entry['tunnel'] === $tunnel && --$this->open[$key]['users'] <= 0) {
                $tunnel->close();
                unset($this->open[$key]);
            }
        }
    }

    public function closeAll(): void
    {
        foreach ($this->open as $entry) {
            $entry['tunnel']->close();
        }

        $this->open = [];
    }

    public function openCount(): int
    {
        return count($this->open);
    }

    /**
     * @throws TunnelFailed
     * @throws ConnectionNotAllowed
     */
    private function start(SshSettings $ssh, string $sshAddress, string $target, int $targetPort, HostPolicy $policy): SshTunnel
    {
        $binary = KnownHosts::executable('ssh');

        if ($binary === null || ! function_exists('proc_open')) {
            throw new TunnelFailed(ConnectionProblem::of(ConnectionProblemKind::SshUnavailable));
        }

        $timeout = max(1, (int) config('phpdbadmin.ssh.timeout', 10));
        $keyFile = $this->keyFile($ssh, $policy);

        try {
            if ($ssh->passphrase !== null && $ssh->passphrase !== '') {
                $this->removePassphrase($keyFile, $ssh->passphrase);
            }

            // Ein zweiter Versuch nur, wenn ein anderer Prozess den freien Port gerade selbst genommen hat.
            $attempt = 0;

            while (true) {
                $attempt++;
                $command = new SshCommand(
                    binary: $binary,
                    keyFile: $keyFile,
                    knownHosts: $this->knownHosts->path(),
                    sshAddress: $sshAddress,
                    hostKeyAlias: KnownHosts::name($ssh->host, $ssh->port()),
                    sshPort: $ssh->port(),
                    username: $ssh->username,
                    localPort: $this->freePort(),
                    target: $target,
                    targetPort: $targetPort,
                    connectTimeout: $timeout,
                );

                $process = new Process($command->arguments(), null, self::environment());
                $process->setTimeout(null);
                $process->start();

                $state = $this->waitUntilListening($process, $command->localPort, $timeout);

                if ($state === true) {
                    return new SshTunnel($process, $command->localPort, $command->hostKeyAlias);
                }

                $process->stop(0);
                $error = trim($process->getErrorOutput());

                if ($attempt === 1 && stripos($error, 'address already in use') !== false) {
                    continue;
                }

                throw new TunnelFailed($state === null
                    ? new ConnectionProblem(ConnectionProblemKind::Timeout, null, $error !== '' ? $error : null, $ssh->host.':'.$ssh->port())
                    : self::problem($error));
            }
        } finally {
            // ssh hat den Schlüssel beim Anmelden gelesen; danach braucht ihn niemand mehr auf der Platte.
            @unlink($keyFile);
        }
    }

    /**
     * Meldung von ssh in eine Art übersetzen, die Menschen weiterhilft.
     */
    public static function problem(string $error): ConnectionProblem
    {
        $text = strtolower($error);

        $kind = match (true) {
            str_contains($text, 'host key verification failed'), str_contains($text, 'host identification has changed') => ConnectionProblemKind::SshHostKeyChanged,
            str_contains($text, 'permission denied') => ConnectionProblemKind::SshKeyRejected,
            // «channel 2: open failed: connect failed: Connection refused» — der SSH-Server erreicht das Ziel nicht.
            str_contains($text, 'open failed'), str_contains($text, 'connect failed') => ConnectionProblemKind::SshForwardFailed,
            str_contains($text, 'could not resolve hostname') => ConnectionProblemKind::HostUnknown,
            str_contains($text, 'timed out') => ConnectionProblemKind::Timeout,
            str_contains($text, 'connection refused'), str_contains($text, 'no route to host'), str_contains($text, 'network is unreachable'), str_contains($text, 'connection closed by'), str_contains($text, 'connection reset') => ConnectionProblemKind::SshUnreachable,
            str_contains($text, 'load key'), str_contains($text, 'invalid format'), str_contains($text, 'passphrase'), str_contains($text, 'libcrypto') => ConnectionProblemKind::SshKeyInvalid,
            default => ConnectionProblemKind::Unknown,
        };

        return new ConnectionProblem($kind, null, $error !== '' ? mb_substr($error, -2000) : null);
    }

    /**
     * Schlüssel als Datei nur für ssh: eingefügt oder aus einem erlaubten Ordner, in einer Kopie mit 0600 — ssh lehnt
     * Schlüssel ab, die andere lesen dürfen, und eine Passphrase lässt sich nur aus einer Kopie entfernen.
     *
     * @throws TunnelFailed
     * @throws ConnectionNotAllowed
     */
    private function keyFile(SshSettings $ssh, HostPolicy $policy): string
    {
        $content = $ssh->privateKey;

        if (($content === null || trim($content) === '') && $ssh->keyPath !== null && $ssh->keyPath !== '') {
            $content = (string) @file_get_contents($policy->resolveSshKeyPath($ssh->keyPath));
        }

        if ($content === null || trim($content) === '') {
            throw new TunnelFailed(ConnectionProblem::of(ConnectionProblemKind::SshKeyInvalid));
        }

        $folder = (string) config('phpdbadmin.ssh.temporary', storage_path('app/ssh/tmp'));

        if (! is_dir($folder)) {
            @mkdir($folder, 0700, true);
        }

        $file = $folder.'/key-'.bin2hex(random_bytes(12));
        $previous = umask(0077);

        try {
            // OpenSSH verlangt Zeilenenden ohne \r und einen Zeilenumbruch am Schluss.
            file_put_contents($file, rtrim(str_replace("\r\n", "\n", $content))."\n");
        } finally {
            umask($previous);
        }

        @chmod($file, 0600);

        return $file;
    }

    /**
     * @throws TunnelFailed
     */
    private function removePassphrase(string $keyFile, string $passphrase): void
    {
        $keygen = KnownHosts::executable('ssh-keygen');

        if ($keygen === null) {
            throw new TunnelFailed(ConnectionProblem::of(ConnectionProblemKind::SshUnavailable));
        }

        $process = new Process([$keygen, '-p', '-q', '-f', $keyFile, '-N', ''], null, self::environment(), $passphrase."\n", 10);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new TunnelFailed(new ConnectionProblem(ConnectionProblemKind::SshKeyInvalid, null, trim(str_replace($passphrase, '••••', $process->getErrorOutput())) ?: null));
        }
    }

    /**
     * Warten, bis ssh angemeldet ist und das lokale Ende annimmt.
     *
     * @return bool|null true bereit, false ssh hat aufgegeben, null Zeit abgelaufen
     */
    private function waitUntilListening(Process $process, int $port, int $timeout): ?bool
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            if (! $process->isRunning()) {
                return false;
            }

            $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.2);

            if ($socket !== false) {
                fclose($socket);

                return true;
            }

            usleep(50_000);
        }

        return null;
    }

    private function freePort(): int
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if ($server === false) {
            throw new TunnelFailed(new ConnectionProblem(ConnectionProblemKind::Unknown, null, $errorMessage));
        }

        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * Ohne Agent und ohne grafische Passwortabfrage des Kontos, unter dem PHP läuft.
     *
     * @return array<string, false>
     */
    private static function environment(): array
    {
        return ['SSH_AUTH_SOCK' => false, 'SSH_ASKPASS' => false, 'SSH_ASKPASS_REQUIRE' => false, 'DISPLAY' => false];
    }

    /** Auch nach einem fatalen Fehler: kein ssh-Prozess überlebt die Anfrage. */
    private function registerShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;
        register_shutdown_function(fn () => $this->closeAll());
    }
}
