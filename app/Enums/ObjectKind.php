<?php

namespace App\Enums;

/**
 * Objekte eines Schemas neben den Tabellen (S-06): Sichten, Routinen, Trigger, Ereignisse (MySQL, MariaDB),
 * Sequenzen und eigene Typen (PostgreSQL). Welche ein Treiber kennt, sagt sein Objekt-Dialekt
 * (ObjectDialect::kinds). Der Wert steht in der Adresse (`…/x/{kind}/{name}`).
 */
enum ObjectKind: string
{
    case View = 'view';
    case Function = 'function';
    case Procedure = 'procedure';
    case Trigger = 'trigger';
    case Event = 'event';
    case Sequence = 'sequence';
    case Type = 'type';

    /**
     * Schlüsselwörter nach CREATE bzw. ALTER, die diese Art anlegen oder ändern. Bei PostgreSQL ist eine Domäne ein
     * eigener Typ mit eigenem Schlüsselwort.
     *
     * @return list<string>
     */
    public function keywords(): array
    {
        return match ($this) {
            self::View => ['VIEW'],
            self::Function => ['FUNCTION'],
            self::Procedure => ['PROCEDURE'],
            self::Trigger => ['TRIGGER'],
            self::Event => ['EVENT'],
            self::Sequence => ['SEQUENCE'],
            self::Type => ['TYPE', 'DOMAIN'],
        };
    }

    /** Gruppe im Navigator (Catalog::groups). */
    public function group(): string
    {
        return match ($this) {
            self::View => 'views',
            self::Function, self::Procedure => 'routines',
            self::Trigger => 'triggers',
            self::Event => 'events',
            self::Sequence => 'sequences',
            self::Type => 'types',
        };
    }

    /** Muster für die Adresse — nur diese Werte erreichen den Controller. */
    public static function pattern(): string
    {
        return implode('|', array_column(self::cases(), 'value'));
    }
}
