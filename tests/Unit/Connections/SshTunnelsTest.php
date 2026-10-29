<?php

namespace Tests\Unit\Connections;

use App\Connections\ConnectionProblemKind;
use App\Connections\SshTunnels;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Meldungen von ssh in verständliche Arten übersetzt (V-08) — die Texte stammen aus OpenSSH 9 und 10.
 */
class SshTunnelsTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: ConnectionProblemKind}>
     */
    public static function messages(): array
    {
        return [
            'Schlüssel abgelehnt' => ['deploy@192.0.2.10: Permission denied (publickey).', ConnectionProblemKind::SshKeyRejected],
            'Host-Schlüssel geändert' => ["@@@@@@@@@@@\n@    WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED!     @\nHost key verification failed.", ConnectionProblemKind::SshHostKeyChanged],
            'Host-Schlüssel abgewiesen' => ['Host key verification failed.', ConnectionProblemKind::SshHostKeyChanged],
            'Port zu' => ['ssh: connect to host 127.0.0.1 port 2222: Connection refused', ConnectionProblemKind::SshUnreachable],
            'kein Weg' => ['ssh: connect to host 10.0.0.9 port 22: No route to host', ConnectionProblemKind::SshUnreachable],
            'Zeitüberschreitung' => ['ssh: connect to host 10.0.0.9 port 22: Operation timed out', ConnectionProblemKind::Timeout],
            'Name unbekannt' => ['ssh: Could not resolve hostname bastion.invalid: nodename nor servname provided', ConnectionProblemKind::HostUnknown],
            'Schlüssel kaputt' => ['Load key "/srv/key": invalid format', ConnectionProblemKind::SshKeyInvalid],
            'Ziel hinter dem Tunnel' => ['channel 2: open failed: connect failed: Connection refused', ConnectionProblemKind::SshForwardFailed],
            'Ziel verweigert' => ['channel 2: open failed: administratively prohibited: open failed', ConnectionProblemKind::SshForwardFailed],
            'unbekannt' => ['kex_exchange_identification: read: Connection reset by peer', ConnectionProblemKind::SshUnreachable],
            'leer' => ['', ConnectionProblemKind::Unknown],
        ];
    }

    #[DataProvider('messages')]
    public function test_names_what_went_wrong(string $message, ConnectionProblemKind $kind): void
    {
        $problem = SshTunnels::problem($message);

        $this->assertSame($kind, $problem->kind);
        $this->assertSame($message === '' ? null : $message, $problem->serverMessage);
    }
}
