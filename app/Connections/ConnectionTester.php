<?php

namespace App\Connections;

use App\Support\Translations;
use Throwable;

/**
 * «Verbindung testen» (V-02, Wireframe 04): Prüfschritte in der Reihenfolge, in der sie scheitern können — Host
 * bzw. Datei erlaubt, Server erreichbar, Anmeldung bzw. Datenbank geöffnet, Rechte des Kontos (V-10). Nach dem
 * ersten Fehler bleiben die übrigen Schritte ungeprüft.
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
            ? ['allowed' => __('Host erlaubt'), 'reachable' => __('Server erreichbar'), 'login' => __('Anmeldung'), 'account' => __('Rechte des Kontos')]
            : ['allowed' => __('Datei erlaubt'), 'login' => __('Datenbank geöffnet')];

        $reached = false;
        foreach ($labels as $key => $label) {
            if ($key === $at) {
                $steps[] = $this->step($key, $label, 'failed', $problem->title());
                $reached = true;
            } elseif ($reached) {
                $steps[] = $this->step($key, $label, 'skipped', __('nicht geprüft'));
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
