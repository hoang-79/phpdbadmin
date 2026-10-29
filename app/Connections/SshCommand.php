<?php

namespace App\Connections;

/**
 * Die Argumente für `ssh -N -L` (V-08) — als Liste für Symfony Process, nie als Zeichenkette für eine Shell.
 *
 * Ohne Benutzerkonfiguration (`-F /dev/null`: kein ProxyCommand, keine fremden Optionen aus ~/.ssh/config), ohne
 * Agent und nur mit dem einen Schlüssel; nie eine Rückfrage (`BatchMode`); Host-Schlüssel beim ersten Mal annehmen,
 * danach prüfen (`accept-new`) gegen die eigene known_hosts von phpdbadmin, gespeichert unter dem Namen des Servers
 * (`HostKeyAlias`), verbunden wird zur geprüften Adresse; der Tunnel hört nur auf 127.0.0.1 und endet, wenn die
 * Weiterleitung nicht zustande kommt.
 */
final readonly class SshCommand
{
    /**
     * @param  string  $sshAddress  die geprüfte Adresse des SSH-Servers
     * @param  string  $hostKeyAlias  Name in known_hosts (KnownHosts::name)
     * @param  string  $target  Ziel aus Sicht des SSH-Servers (erlaubter Name oder geprüfte Adresse)
     */
    public function __construct(
        public string $binary,
        public string $keyFile,
        public string $knownHosts,
        public string $sshAddress,
        public string $hostKeyAlias,
        public int $sshPort,
        public string $username,
        public int $localPort,
        public string $target,
        public int $targetPort,
        public int $connectTimeout,
    ) {}

    /**
     * @return list<string>
     */
    public function arguments(): array
    {
        $target = str_contains($this->target, ':') ? '['.$this->target.']' : $this->target;

        return [
            $this->binary,
            '-N',
            '-F', '/dev/null',
            '-L', '127.0.0.1:'.$this->localPort.':'.$target.':'.$this->targetPort,
            '-p', (string) $this->sshPort,
            '-l', $this->username,
            '-i', $this->keyFile,
            '-o', 'BatchMode=yes',
            '-o', 'ExitOnForwardFailure=yes',
            '-o', 'StrictHostKeyChecking=accept-new',
            // ssh trennt Dateilisten an Leerzeichen — der Pfad steht deshalb in Anführungszeichen.
            '-o', 'UserKnownHostsFile="'.$this->knownHosts.'"',
            '-o', 'HostKeyAlias='.$this->hostKeyAlias,
            '-o', 'CheckHostIP=no',
            '-o', 'GlobalKnownHostsFile=/dev/null',
            '-o', 'UpdateHostKeys=no',
            '-o', 'IdentitiesOnly=yes',
            '-o', 'IdentityAgent=none',
            '-o', 'PasswordAuthentication=no',
            '-o', 'KbdInteractiveAuthentication=no',
            '-o', 'ControlMaster=no',
            '-o', 'ControlPath=none',
            '-o', 'PermitLocalCommand=no',
            '-o', 'ConnectTimeout='.$this->connectTimeout,
            '-o', 'ServerAliveInterval=15',
            '-o', 'ServerAliveCountMax=2',
            // INFO statt ERROR: Nur so meldet ssh «channel N: open failed», wenn das Ziel hinter dem Tunnel fehlt.
            '-o', 'LogLevel=INFO',
            '--',
            $this->sshAddress,
        ];
    }
}
