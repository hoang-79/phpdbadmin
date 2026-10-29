<?php

namespace App\Enums;

/**
 * Recht eines Kontos an einer geteilten Verbindung (A-07). Die Rechte bauen aufeinander auf: lesen ⊂ schreiben ⊂
 * verwalten. Besitzer und Administratoren haben an jeder Verbindung, die sie sehen, das Recht «verwalten».
 */
enum ConnectionPermission: string
{
    case Read = 'read';
    case Write = 'write';
    case Manage = 'manage';

    /**
     * Schliesst dieses Recht das verlangte ein? «verwalten» erlaubt auch Schreiben und Lesen.
     */
    public function allows(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    public function label(): string
    {
        return match ($this) {
            self::Read => __('lesen'),
            self::Write => __('schreiben'),
            self::Manage => __('verwalten'),
        };
    }

    /**
     * Was das Recht zulässt — für die Legende in der Verwaltung (Wireframe 14).
     */
    public function description(): string
    {
        return match ($this) {
            self::Read => __('Daten und Struktur ansehen, SQL nur lesend, exportieren'),
            self::Write => __('zusätzlich Daten und Struktur ändern, importieren'),
            self::Manage => __('zusätzlich Verbindung bearbeiten und entfernen'),
        };
    }

    private function rank(): int
    {
        return match ($this) {
            self::Read => 1,
            self::Write => 2,
            self::Manage => 3,
        };
    }
}
