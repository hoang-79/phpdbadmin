<?php

namespace Tests\Unit\Connections;

use App\Connections\SshCommand;
use PHPUnit\Framework\TestCase;

/**
 * Argumente für `ssh -N -L` (V-08): als Liste, ohne Benutzerkonfiguration und Agent, nie eine Rückfrage, eigene
 * known_hosts, lokales Ende nur auf 127.0.0.1.
 */
class SshCommandTest extends TestCase
{
    public function test_builds_a_local_forward_without_shell_or_user_configuration(): void
    {
        $arguments = $this->command()->arguments();

        $this->assertSame('/usr/bin/ssh', $arguments[0]);
        $this->assertSame(['-N', '-F', '/dev/null'], array_slice($arguments, 1, 3));
        $this->assertSame('127.0.0.1:41000:127.0.0.1:3306', $this->after($arguments, '-L'));
        $this->assertSame('2222', $this->after($arguments, '-p'));
        $this->assertSame('/tmp/key-abc', $this->after($arguments, '-i'));

        $options = $this->options($arguments);
        $this->assertSame('yes', $options['BatchMode']);
        $this->assertSame('yes', $options['ExitOnForwardFailure']);
        $this->assertSame('accept-new', $options['StrictHostKeyChecking']);
        $this->assertSame('"/srv/phpdbadmin mit Leerzeichen/known_hosts"', $options['UserKnownHostsFile']);
        $this->assertSame('[db.example.ch]:2222', $options['HostKeyAlias']);
        $this->assertSame('/dev/null', $options['GlobalKnownHostsFile']);
        $this->assertSame('none', $options['IdentityAgent']);
        $this->assertSame('yes', $options['IdentitiesOnly']);
        $this->assertSame('no', $options['PasswordAuthentication']);
        $this->assertSame('none', $options['ControlPath']);
        $this->assertSame('7', $options['ConnectTimeout']);
    }

    public function test_user_and_host_can_never_be_read_as_options(): void
    {
        $arguments = (new SshCommand('/usr/bin/ssh', '/tmp/key', '/tmp/known_hosts', '-oProxyCommand=boom', 'x', 22, '-oProxyCommand=boom', 41000, '127.0.0.1', 5432, 5))->arguments();

        $this->assertSame('-oProxyCommand=boom', $this->after($arguments, '-l'), 'der Benutzer ist der Wert von -l');
        $this->assertSame(['--', '-oProxyCommand=boom'], array_slice($arguments, -2), 'nach «--» ist nur noch das Ziel');
    }

    public function test_ipv6_target_is_bracketed(): void
    {
        $command = new SshCommand('/usr/bin/ssh', '/tmp/key', '/tmp/known_hosts', '10.0.0.5', 'bastion', 22, 'app', 41000, '::1', 5432, 5);

        $this->assertSame('127.0.0.1:41000:[::1]:5432', $this->after($command->arguments(), '-L'));
    }

    private function command(): SshCommand
    {
        return new SshCommand(
            binary: '/usr/bin/ssh',
            keyFile: '/tmp/key-abc',
            knownHosts: '/srv/phpdbadmin mit Leerzeichen/known_hosts',
            sshAddress: '192.0.2.10',
            hostKeyAlias: '[db.example.ch]:2222',
            sshPort: 2222,
            username: 'deploy',
            localPort: 41000,
            target: '127.0.0.1',
            targetPort: 3306,
            connectTimeout: 7,
        );
    }

    /**
     * @param  list<string>  $arguments
     */
    private function after(array $arguments, string $flag): ?string
    {
        $position = array_search($flag, $arguments, true);

        return $position === false ? null : $arguments[$position + 1];
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, string>
     */
    private function options(array $arguments): array
    {
        $options = [];

        foreach ($arguments as $position => $argument) {
            if ($argument === '-o') {
                [$name, $value] = explode('=', $arguments[$position + 1], 2);
                $options[$name] = $value;
            }
        }

        return $options;
    }
}
