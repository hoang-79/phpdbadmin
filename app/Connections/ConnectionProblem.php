<?php

namespace App\Connections;

/**
 * Ein Verbindungsfehler in Klartext (V-02): Art, Erklärung, Code und die Meldung des Servers zum Aufklappen. Das
 * Passwort steht nie darin.
 */
final readonly class ConnectionProblem
{
    /**
     * Detail statt eines Pfads, wenn jemand die App-Datenbank von phpdbadmin selbst öffnen will (HostPolicy). Steht
     * hier auf Deutsch und unübersetzt, weil HostPolicy ohne Laravel auskommt; übersetzt wird bei der Ausgabe.
     */
    public const string APP_DATABASE = 'Das ist die eigene Datenbank von phpdbadmin.';

    public function __construct(
        public ConnectionProblemKind $kind,
        public ?string $code = null,
        public ?string $serverMessage = null,
        public ?string $detail = null,
    ) {}

    public static function of(ConnectionProblemKind $kind, ?string $detail = null): self
    {
        return new self($kind, detail: $detail);
    }

    public function isAppDatabase(): bool
    {
        return $this->detail === self::APP_DATABASE;
    }

    public function title(): string
    {
        return $this->kind->title().($this->code !== null ? ' ('.$this->code.')' : '');
    }

    /**
     * @return array{kind: string, title: string, explanation: string, detail: string|null, code: string|null, serverMessage: string|null}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'title' => $this->title(),
            'explanation' => $this->kind->explanation(),
            'detail' => $this->isAppDatabase() ? $this->appDatabaseNote() : $this->detail,
            'code' => $this->code,
            'serverMessage' => $this->serverMessage,
        ];
    }

    private function appDatabaseNote(): string
    {
        return __('Das ist die eigene Datenbank von phpdbadmin.');
    }
}
