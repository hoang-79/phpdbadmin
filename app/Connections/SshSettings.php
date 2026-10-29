<?php

namespace App\Connections;

/**
 * Angaben für einen SSH-Tunnel (V-08): SSH-Server, Benutzer und der private Schlüssel — eingefügt (verschlüsselt
 * gespeichert) oder als Datei in einem erlaubten Ordner —, dazu optional die Passphrase. Host und Port der Datenbank
 * gelten aus Sicht des SSH-Servers.
 */
final readonly class SshSettings
{
    public const int DEFAULT_PORT = 22;

    public function __construct(
        public string $host,
        public ?int $port,
        public string $username,
        public ?string $privateKey = null,
        public ?string $keyPath = null,
        public ?string $passphrase = null,
    ) {}

    public function port(): int
    {
        return $this->port ?? self::DEFAULT_PORT;
    }

    /** Mit anderem Schlüssel (beim Bearbeiten: das gespeicherte, wenn das Feld leer bleibt). */
    public function withKey(?string $privateKey, ?string $passphrase): self
    {
        return new self($this->host, $this->port, $this->username, $privateKey, $this->keyPath, $passphrase);
    }

    /** Schlüssel für das Gleichsein zweier Tunnel in einer Anfrage — ohne Geheimnisse im Klartext. */
    public function fingerprint(): string
    {
        return hash('xxh128', serialize([$this->host, $this->port(), $this->username, $this->privateKey, $this->keyPath, $this->passphrase]));
    }
}
