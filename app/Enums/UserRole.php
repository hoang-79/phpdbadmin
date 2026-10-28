<?php

namespace App\Enums;

/**
 * Rolle eines Kontos (A-07): Administratorinnen und Administratoren verwalten Konten, Einladungen und alle
 * Verbindungen; Mitglieder arbeiten mit den Verbindungen, für die sie ein Recht haben.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('Administrator'),
            self::Member => __('Mitglied'),
        };
    }
}
