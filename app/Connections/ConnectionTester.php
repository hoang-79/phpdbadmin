<?php

namespace App\Connections;

use Throwable;

/**
 * «Verbindung testen» (V-02, Wireframe 04): Prüfschritte in der Reihenfolge, in der sie scheitern können — Host
 * bzw. Datei erlaubt, Server erreichbar bzw. Datei lesbar, Anmeldung bzw. Datenbank geöffnet. Nach dem ersten Fehler
 * bleiben die übrigen Schritte ungeprüft.
 */
final class ConnectionTester
{
    public function __construct(private readonly ConnectionFactory $factory) {}

    public function test(ConnectionSettings $settings, ?int $connectTimeout = null): ConnectionTestResult
    {
        $isServer = $settings->driver->isServer();
        $start = hrtime(true);
        $steps = [];

        try {
            $address = $this->factory->address($settings);
            $steps[] = $this->step('allowed', $isServer ? 'Host erlaubt' : 'Datei erlaubt', 'ok', $isServer
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

            $steps[] = $this->step('reachable', 'Server erreichbar', 'ok', 'Port '.$settings->port().' offen');
        }

        try {
            $connection = $this->factory->make($settings, $connectTimeout);
            $version = $settings->driver->dialect()->serverVersion($connection);
            $this->factory->release($connection);
        } catch (Throwable $exception) {
            $problem = $settings->driver->dialect()->describeConnectionError($exception);

            return $this->failed($steps, 'login', $this->withoutPassword($problem, $settings->password), $isServer, $start);
        }

        $steps[] = $this->step('login', $isServer ? 'Anmeldung' : 'Datenbank geöffnet', 'ok', $settings->driver->label().' '.$version);
        $steps[] = $this->step('account', 'Rechte des Kontos', 'skipped', 'Prüfung folgt mit der Dialekt-Schicht');

        return new ConnectionTestResult(true, $steps, $this->milliseconds($start), $version);
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
    private function failed(array $steps, string $at, ConnectionProblem $problem, bool $isServer, int|float $start): ConnectionTestResult
    {
        $labels = $isServer
            ? ['allowed' => 'Host erlaubt', 'reachable' => 'Server erreichbar', 'login' => 'Anmeldung', 'account' => 'Rechte des Kontos']
            : ['allowed' => 'Datei erlaubt', 'login' => 'Datenbank geöffnet', 'account' => 'Rechte des Kontos'];

        $reached = false;
        foreach ($labels as $key => $label) {
            if ($key === $at) {
                $steps[] = $this->step($key, $label, 'failed', $problem->title());
                $reached = true;
            } elseif ($reached) {
                $steps[] = $this->step($key, $label, 'skipped', 'nicht geprüft');
            }
        }

        return new ConnectionTestResult(false, $steps, $this->milliseconds($start), problem: $problem);
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
