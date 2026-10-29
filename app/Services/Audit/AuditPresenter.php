<?php

namespace App\Services\Audit;

use App\Enums\ConnectionPermission;
use App\Models\AuditEntry;

/**
 * Einträge des Protokolls für Menschen (Wireframe 14, Reiter «Protokoll»): Art in Klartext, Konto, Ergebnis und
 * eine kurze Beschreibung, wo keine Anweisung steht. Kategorien für den Filter «Art» fassen Aktionen über ihren
 * Präfix zusammen — auch künftige wie `data.update` oder `schema.alter`.
 */
final class AuditPresenter
{
    /**
     * Filter «Art»: Schlüssel → Präfixe der Aktionen.
     *
     * @var array<string, list<string>>
     */
    public const array CATEGORIES = [
        'auth' => ['auth.', 'setup.'],
        'account' => ['account.', 'passkey.'],
        'connection' => ['connection.'],
        'data' => ['data.'],
        'schema' => ['schema.', 'object.'],
        'sql' => ['sql.'],
        'transfer' => ['export.', 'import.', 'transfer.'],
        'audit' => ['audit.'],
    ];

    /**
     * @return list<array{value: string, label: string}>
     */
    public function categories(): array
    {
        return [
            ['value' => 'auth', 'label' => __('Anmeldung')],
            ['value' => 'account', 'label' => __('Konten')],
            ['value' => 'connection', 'label' => __('Verbindungen')],
            ['value' => 'data', 'label' => __('Daten geändert')],
            ['value' => 'schema', 'label' => __('Struktur geändert')],
            ['value' => 'sql', 'label' => __('SQL ausgeführt')],
            ['value' => 'transfer', 'label' => __('Export und Import')],
            ['value' => 'audit', 'label' => __('Protokoll')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function row(AuditEntry $entry): array
    {
        $connection = $entry->connection;

        return [
            'id' => $entry->id,
            'createdAt' => $entry->created_at->toIso8601String(),
            'account' => $this->account($entry),
            'connection' => $entry->connection_id === null ? null : [
                'name' => $connection->name ?? $entry->connection_name ?? $this->removedConnection(),
                'environment' => $connection?->environment->value,
                'readOnly' => $connection->read_only ?? false,
                'removed' => $connection === null,
            ],
            'action' => $entry->action,
            'label' => $this->label($entry->action),
            'sql' => $entry->sql,
            'detail' => $this->detail($entry),
            'affectedRows' => $entry->affected_rows,
            'result' => $this->result($entry),
        ];
    }

    /** Konto: aktueller Name, sonst der Name im Eintrag, bei Fehlversuchen die gekürzte Prüfsumme der Adresse. */
    public function account(AuditEntry $entry): string
    {
        if ($entry->user !== null) {
            return $entry->user->name;
        }

        if ($entry->user_name !== null) {
            return $entry->user_name;
        }

        $hash = $entry->context['email_hash'] ?? null;

        return is_string($hash) && strlen($hash) > 6
            ? __('unbekannt · :hash', ['hash' => substr($hash, 0, 4).'…'.substr($hash, -2)])
            : '—';
    }

    /** ok · rejected (Anmeldung abgelehnt) · error (Aktion gescheitert) · read_only (Schreibschutz) */
    public function result(AuditEntry $entry): string
    {
        $result = $entry->context['result'] ?? null;

        return match (true) {
            is_string($result) && in_array($result, ['ok', 'rejected', 'error', 'read_only'], true) => $result,
            str_ends_with($entry->action, '.failed') => 'rejected',
            isset($entry->context['error']) => 'error',
            default => 'ok',
        };
    }

    public function label(string $action): string
    {
        return match ($action) {
            'auth.login' => __('Anmeldung'),
            'auth.failed' => __('Anmeldung abgelehnt'),
            'auth.logout' => __('Abmeldung'),
            'setup.failed' => __('Einrichtung abgelehnt'),
            'account.installed' => __('Erstes Konto angelegt'),
            'account.invited' => __('Eingeladen'),
            'account.invitation_resent' => __('Einladung erneut gesendet'),
            'account.invitation_withdrawn' => __('Einladung zurückgezogen'),
            'account.updated' => __('Name oder Adresse geändert'),
            'account.role' => __('Rolle geändert'),
            'account.disabled' => __('Konto gesperrt'),
            'account.enabled' => __('Konto entsperrt'),
            'account.deleted' => __('Konto entfernt'),
            'account.ai_key_set' => __('Schlüssel für die KI-Hilfe gespeichert'),
            'account.ai_key_removed' => __('Schlüssel für die KI-Hilfe entfernt'),
            'sql.assist' => __('KI-Hilfe gefragt'),
            'account.session_ended' => __('Sitzung beendet'),
            'account.sessions_ended' => __('Andere Sitzungen beendet'),
            'passkey.registered' => __('Passkey hinzugefügt'),
            'passkey.deleted' => __('Passkey entfernt'),
            'connection.created' => __('Verbindung angelegt'),
            'connection.updated' => __('Verbindung geändert'),
            'connection.deleted' => __('Verbindung entfernt'),
            'connection.shared' => __('Verbindung geteilt'),
            'connection.unshared' => __('Teilen beendet'),
            'connection.permission' => __('Recht geändert'),
            'connection.ssh_host_key_forgotten' => __('SSH-Host-Schlüssel vergessen'),
            'audit.exported' => __('Protokoll exportiert'),
            default => $this->categoryLabel($action) ?? $action,
        };
    }

    /**
     * Kurz, was geschah, wo keine Anweisung steht — nie ein Wert aus einer Zieldatenbank.
     */
    public function detail(AuditEntry $entry): ?string
    {
        $context = $entry->context ?? [];
        $text = fn (string $key): string => is_scalar($context[$key] ?? null) ? (string) $context[$key] : '';

        $detail = match ($entry->action) {
            'auth.login', 'auth.failed' => $this->method($text('method')),
            'connection.permission' => $text('account').': '.$this->permission($text('from')).' → '.$this->permission($text('to')),
            'account.role' => $entry->target.': '.$text('from').' → '.$text('to'),
            'account.deleted' => $text('name').' · '.$entry->target,
            default => $entry->target,
        };

        $error = $text('error');

        return trim(implode(' · ', array_filter([$detail, $error], fn (?string $part): bool => $part !== null && $part !== ''))) ?: null;
    }

    private function categoryLabel(string $action): ?string
    {
        foreach ($this->categories() as $category) {
            foreach (self::CATEGORIES[$category['value']] as $prefix) {
                if (str_starts_with($action, $prefix)) {
                    return $category['label'];
                }
            }
        }

        return null;
    }

    private function method(string $method): string
    {
        return match ($method) {
            'code' => __('per Code'),
            'passkey' => __('per Passkey'),
            'invitation' => __('per Einladung'),
            'setup' => __('per Einrichtung'),
            'confirm-code' => __('Bestätigung per Code'),
            'browser' => __('im Browser'),
            default => $method,
        };
    }

    private function permission(string $permission): string
    {
        return ConnectionPermission::tryFrom($permission)?->label() ?? $this->noAccess();
    }

    private function noAccess(): string
    {
        return __('kein Zugriff');
    }

    private function removedConnection(): string
    {
        return __('entfernte Verbindung');
    }
}
