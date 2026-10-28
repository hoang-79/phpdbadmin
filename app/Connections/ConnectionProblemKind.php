<?php

namespace App\Connections;

/**
 * Fehlerarten beim Verbinden, für Menschen unterscheidbar (V-02, F-2).
 */
enum ConnectionProblemKind: string
{
    case HostNotAllowed = 'host_not_allowed';
    case HostUnknown = 'host_unknown';
    case HostUnreachable = 'host_unreachable';
    case Timeout = 'timeout';
    case AccessDenied = 'access_denied';
    case DatabaseMissing = 'database_missing';
    case Ssl = 'ssl';
    case FileNotAllowed = 'file_not_allowed';
    case FileMissing = 'file_missing';
    case FileNotDatabase = 'file_not_database';
    case Unknown = 'unknown';

    public function title(): string
    {
        return match ($this) {
            self::HostNotAllowed => 'Host nicht erlaubt',
            self::HostUnknown => 'Host unbekannt',
            self::HostUnreachable => 'Server nicht erreichbar',
            self::Timeout => 'Zeitüberschreitung',
            self::AccessDenied => 'Zugang verweigert',
            self::DatabaseMissing => 'Datenbank fehlt',
            self::Ssl => 'Verschlüsselung (SSL) gescheitert',
            self::FileNotAllowed => 'Datei nicht erlaubt',
            self::FileMissing => 'Datei nicht gefunden',
            self::FileNotDatabase => 'Keine SQLite-Datenbank',
            self::Unknown => 'Verbindung gescheitert',
        };
    }

    public function explanation(): string
    {
        return match ($this) {
            self::HostNotAllowed => 'Diese Installation verbindet nur zu erlaubten Hosts. Die Liste legt der Betrieb fest (PHPDBADMIN_ALLOWED_HOSTS).',
            self::HostUnknown => 'Der Name lässt sich nicht in eine Adresse auflösen. Prüfen Sie die Schreibweise.',
            self::HostUnreachable => 'Unter dieser Adresse und diesem Port antwortet kein Datenbank-Server. Prüfen Sie Host und Port und ob der Dienst läuft.',
            self::Timeout => 'Der Server hat nicht rechtzeitig geantwortet. Vielleicht blockiert eine Firewall den Port.',
            self::AccessDenied => 'Der Server kennt diesen Benutzer nicht oder das Passwort stimmt nicht. Das Konto muss Zugriff von der Adresse dieses Servers haben.',
            self::DatabaseMissing => 'Die Anmeldung hat geklappt, aber diese Datenbank gibt es auf dem Server nicht — oder das Konto darf sie nicht sehen.',
            self::Ssl => 'Die verschlüsselte Verbindung kam nicht zustande. Unterstützt der Server SSL? Sonst SSL auf «Aus» stellen — nur in vertrauenswürdigen Netzen.',
            self::FileNotAllowed => 'SQLite-Dateien müssen in einem erlaubten Ordner liegen (PHPDBADMIN_SQLITE_PATHS). Pfade mit «..» oder Verknüpfungen nach draussen gelten nicht.',
            self::FileMissing => 'Unter diesem Pfad liegt keine lesbare Datei.',
            self::FileNotDatabase => 'Die Datei lässt sich öffnen, ist aber keine SQLite-Datenbank.',
            self::Unknown => 'Der Server hat die Verbindung abgelehnt. Die Meldung des Servers hilft weiter.',
        };
    }
}
