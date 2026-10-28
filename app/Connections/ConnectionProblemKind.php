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
            self::HostNotAllowed => __('Host nicht erlaubt'),
            self::HostUnknown => __('Host unbekannt'),
            self::HostUnreachable => __('Server nicht erreichbar'),
            self::Timeout => __('Zeitüberschreitung'),
            self::AccessDenied => __('Zugang verweigert'),
            self::DatabaseMissing => __('Datenbank fehlt'),
            self::Ssl => __('Verschlüsselung (SSL) gescheitert'),
            self::FileNotAllowed => __('Datei nicht erlaubt'),
            self::FileMissing => __('Datei nicht gefunden'),
            self::FileNotDatabase => __('Keine SQLite-Datenbank'),
            self::Unknown => __('Verbindung gescheitert'),
        };
    }

    public function explanation(): string
    {
        return match ($this) {
            self::HostNotAllowed => __('Diese Installation verbindet nur zu erlaubten Hosts. Die Liste legt der Betrieb fest (PHPDBADMIN_ALLOWED_HOSTS).'),
            self::HostUnknown => __('Der Name lässt sich nicht in eine Adresse auflösen. Prüfen Sie die Schreibweise.'),
            self::HostUnreachable => __('Unter dieser Adresse und diesem Port antwortet kein Datenbank-Server. Prüfen Sie Host und Port und ob der Dienst läuft.'),
            self::Timeout => __('Der Server hat nicht rechtzeitig geantwortet. Vielleicht blockiert eine Firewall den Port.'),
            self::AccessDenied => __('Der Server kennt diesen Benutzer nicht oder das Passwort stimmt nicht. Das Konto muss Zugriff von der Adresse dieses Servers haben.'),
            self::DatabaseMissing => __('Die Anmeldung hat geklappt, aber diese Datenbank gibt es auf dem Server nicht — oder das Konto darf sie nicht sehen.'),
            self::Ssl => __('Die verschlüsselte Verbindung kam nicht zustande. Unterstützt der Server SSL? Sonst SSL auf «Aus» stellen — nur in vertrauenswürdigen Netzen.'),
            self::FileNotAllowed => __('SQLite-Dateien müssen in einem erlaubten Ordner liegen (PHPDBADMIN_SQLITE_PATHS). Pfade mit «..» oder Verknüpfungen nach draussen gelten nicht.'),
            self::FileMissing => __('Unter diesem Pfad liegt keine lesbare Datei.'),
            self::FileNotDatabase => __('Die Datei lässt sich öffnen, ist aber keine SQLite-Datenbank.'),
            self::Unknown => __('Der Server hat die Verbindung abgelehnt. Die Meldung des Servers hilft weiter.'),
        };
    }
}
