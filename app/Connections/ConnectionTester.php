<?php

namespace App\Connections;

use App\Support\Translations;
use Throwable;

/**
 * «Verbindung testen» (V-02, Wireframe 04): Prüfschritte in der Reihenfolge, in der sie scheitern können — Host
 * bzw. Datei erlaubt, Server erreichbar, Anmeldung bzw. Datenbank geöffnet, Rechte des Kontos (V-10). Nach dem
 * ersten Fehler bleiben die übrigen Schritte ungeprüft.
 *
 * Mit SSH-Tunnel (V-08) steht an der Stelle von «Server erreichbar» der Tunnel — mit dem Fingerabdruck des
 * SSH-Servers, damit man ihn beim ersten Mal vergleichen kann.
 */
final class ConnectionTester
{
    public function __construct(
        private readonly ConnectionFactory $factory,
        private readonly KnownHosts $knownHosts,
    ) {}

    public function test(ConnectionSettings $settings, ?int $connectTimeout = null): ConnectionTestResult
    {
        $isServer = $settings->driver->isServer();
        $start = hrtime(true);
        $steps = [];

        if ($isServer && $settings->ssh !== null) {
            return $this->testThroughTunnel($settings, $settings->ssh, $connectTimeout, $start);
        }

        try {
            $address = $this->factory->address($settings);
            $steps[] = $this->step('allowed', $isServer ? __('Host erlaubt') : __('Datei erlaubt'), 'ok', $isServer
                ? ($address === strtolower((string) $settings->host) ? $address : $settings->host.' → '.$address)
                : $address);
        } catch (ConnectionNotAllowed $exception) {
            return $this->failed($steps, 'allowed', $exception->problem, $isServer, $start);
        }

        if ($isServer) {
            $reachable = $this->reach($address, (int) $settings->port(), $connectTimeout);

            if ($reachable !== null) {
                return $this->failed($steps, 'reachable', $reachable, $isServer, $start);
            }

            $steps[] = $this->step('reachable', __('Server erreichbar'), 'ok', __('Port :port offen', ['port' => (string) $settings->port()]));
        }

        $dialect = $settings->driver->dialect();

        try {
            $connection = $this->factory->make($settings, $connectTimeout);
            $version = $dialect->serverVersion($connection);
        } catch (Throwable $exception) {
            $problem = $dialect->describeConnectionError($exception);

            return $this->failed($steps, 'login', $this->withoutPassword($problem, $settings->password), $isServer, $start);
        }

        $steps[] = $this->step('login', $isServer ? __('Anmeldung') : __('Datenbank geöffnet'), 'ok', $settings->driver->label().' '.$version);
        $warnings = [];

        if ($isServer) {
            // Rechte des Kontos (V-10): eine Warnung, kein Fehler — die Verbindung funktioniert.
            try {
                $warnings = $dialect->accountWarnings($connection);
                $steps[] = $warnings === []
                    ? $this->step('account', __('Rechte des Kontos'), 'ok', __('keine Rechte über die Datenbank hinaus'))
                    : $this->step('account', __('Rechte des Kontos'), 'warning', implode(' ', array_column(Translations::accountWarnings($warnings), 'title')));
            } catch (Throwable) {
                $steps[] = $this->step('account', __('Rechte des Kontos'), 'skipped', __('liess sich nicht lesen'));
            }
        }

        $this->factory->release($connection);

        return new ConnectionTestResult(true, $steps, $this->milliseconds($start), $version, warnings: $warnings);
    }

    /**
     * Über SSH: erlaubt (SSH-Server und Ziel), Tunnel mit Fingerabdruck, Anmeldung durch den Tunnel, Rechte.
     */
    private function testThroughTunnel(ConnectionSettings $settings, SshSettings $ssh, ?int $connectTimeout, int|float $start): ConnectionTestResult
    {
        $steps = [];

        try {
            $target = $this->factory->address($settings);
            $steps[] = $this->step('allowed', __('Hosts erlaubt'), 'ok', __('SSH :ssh · Datenbank :target', [
                'ssh' => $ssh->username.'@'.$ssh->host.':'.$ssh->port(),
                'target' => $target.':'.$settings->port(),
            ]));
        } catch (ConnectionNotAllowed $exception) {
            return $this->failed($steps, 'allowed', $exception->problem, true, $start, tunnel: true);
        }

        try {
            $tunnel = $this->factory->tunnel($settings);
        } catch (ConnectionNotAllowed $exception) {
            return $this->failed($steps, 'tunnel', $exception->problem, true, $start, tunnel: true);
        }

        $hostKey = $this->knownHosts->fingerprint($ssh->host, $ssh->port());
        $steps[] = $this->step('tunnel', __('SSH-Tunnel'), 'ok', $hostKey !== null ? $this->hostKeyDetail($hostKey) : null);
        $dialect = $settings->driver->dialect();

        try {
            $connection = $this->factory->make($settings, $connectTimeout);
            $version = $dialect->serverVersion($connection);
        } catch (TunnelFailed $exception) {
            return $this->failed($steps, 'login', $exception->problem, true, $start, tunnel: true, hostKey: $hostKey);
        } catch (Throwable $exception) {
            return $this->failed($steps, 'login', $this->withoutPassword($dialect->describeConnectionError($exception), $settings->password), true, $start, tunnel: true, hostKey: $hostKey);
        } finally {
            if ($tunnel !== null) {
                $this->factory->releaseTunnel($tunnel);
            }
        }

        $steps[] = $this->step('login', __('Anmeldung'), 'ok', $settings->driver->label().' '.$version);
        $warnings = [];

        try {
            $warnings = $dialect->accountWarnings($connection);
            $steps[] = $warnings === []
                ? $this->step('account', __('Rechte des Kontos'), 'ok', __('keine Rechte über die Datenbank hinaus'))
                : $this->step('account', __('Rechte des Kontos'), 'warning', implode(' ', array_column(Translations::accountWarnings($warnings), 'title')));
        } catch (Throwable) {
            $steps[] = $this->step('account', __('Rechte des Kontos'), 'skipped', __('liess sich nicht lesen'));
        }

        $this->factory->release($connection);

        return new ConnectionTestResult(true, $steps, $this->milliseconds($start), $version, warnings: $warnings, hostKey: $hostKey);
    }

    private function hostKeyDetail(string $fingerprint): string
    {
        return __('Host-Schlüssel :fingerprint', ['fingerprint' => $fingerprint]);
    }

    /**
     * Ein offener Port, bevor der Treiber sich anmeldet: so trennt der Test «nicht erreichbar» sauber von
     * «Zugang verweigert».
     */
    private function reach(string $address, int $port, ?int $timeout): ?ConnectionProblem
    {
        $target = str_contains($address, ':') ? '['.$address.']' : $address;
        $socket = @stream_socket_client(
            'tcp://'.$target.':'.$port,
            $errorCode,
            $errorMessage,
            (float) ($timeout ?? (int) config('phpdbadmin.connections.connect_timeout', 5)),
        );

        if ($socket === false) {
            $kind = stripos((string) $errorMessage, 'timed out') !== false
                ? ConnectionProblemKind::Timeout
                : ConnectionProblemKind::HostUnreachable;

            return new ConnectionProblem($kind, null, trim($errorMessage.' ('.$address.':'.$port.')'));
        }

        fclose($socket);

        return null;
    }

    /**
     * @param  list<array{key: string, label: string, state: string, detail: string|null}>  $steps
     */
    private function failed(array $steps, string $at, ConnectionProblem $problem, bool $isServer, int|float $start, bool $tunnel = false, ?string $hostKey = null): ConnectionTestResult
    {
        $labels = match (true) {
            $tunnel => ['allowed' => __('Hosts erlaubt'), 'tunnel' => __('SSH-Tunnel'), 'login' => __('Anmeldung'), 'account' => __('Rechte des Kontos')],
            $isServer => ['allowed' => __('Host erlaubt'), 'reachable' => __('Server erreichbar'), 'login' => __('Anmeldung'), 'account' => __('Rechte des Kontos')],
            default => ['allowed' => __('Datei erlaubt'), 'login' => __('Datenbank geöffnet')],
        };

        $reached = false;
        foreach ($labels as $key => $label) {
            if ($key === $at) {
                $steps[] = $this->step($key, $label, 'failed', $problem->title());
                $reached = true;
            } elseif ($reached) {
                $steps[] = $this->step($key, $label, 'skipped', __('nicht geprüft'));
            }
        }

        return new ConnectionTestResult(false, $steps, $this->milliseconds($start), problem: $problem, hostKey: $hostKey);
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string|null}
     */
    private function step(string $key, string $label, string $state, ?string $detail = null): array
    {
        return ['key' => $key, 'label' => $label, 'state' => $state, 'detail' => $detail];
    }

    private function withoutPassword(ConnectionProblem $problem, ?string $password): ConnectionProblem
    {
        if ($password === null || $password === '' || $problem->serverMessage === null) {
            return $problem;
        }

        return new ConnectionProblem($problem->kind, $problem->code, str_replace($password, '••••', $problem->serverMessage), $problem->detail);
    }

    private function milliseconds(int|float $start): int
    {
        return (int) round((hrtime(true) - $start) / 1e6);
    }
}
