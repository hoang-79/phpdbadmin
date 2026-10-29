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
    case DatabaseNotAllowed = 'database_not_allowed';
    case Ssl = 'ssl';
    case FileNotAllowed = 'file_not_allowed';
    case FileMissing = 'file_missing';
    case FileNotDatabase = 'file_not_database';
    case SshUnreachable = 'ssh_unreachable';
    case SshKeyRejected = 'ssh_key_rejected';
    case SshKeyInvalid = 'ssh_key_invalid';
    case SshHostKeyChanged = 'ssh_host_key_changed';
    case SshForwardFailed = 'ssh_forward_failed';
    case SshUnavailable = 'ssh_unavailable';
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
            self::DatabaseNotAllowed => __('Datenbank nicht erlaubt'),
            self::Ssl => __('Verschlüsselung (SSL) gescheitert'),
            self::FileNotAllowed => __('Datei nicht erlaubt'),
            self::FileMissing => __('Datei nicht gefunden'),
            self::FileNotDatabase => __('Keine SQLite-Datenbank'),
            self::SshUnreachable => __('SSH-Server nicht erreichbar'),
            self::SshKeyRejected => __('SSH: Schlüssel abgelehnt'),
            self::SshKeyInvalid => __('SSH: Schlüssel unlesbar'),
            self::SshHostKeyChanged => __('SSH: Host-Schlüssel geändert'),
            self::SshForwardFailed => __('SSH: Datenbank hinter dem Tunnel nicht erreichbar'),
            self::SshUnavailable => __('SSH nicht verfügbar'),
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
            self::DatabaseNotAllowed => __('Datenbanknamen mit ; \' " = \\ oder Steuerzeichen nimmt phpdbadmin nicht an — und die eigene Datenbank von phpdbadmin ist nie erreichbar.'),
            self::Ssl => __('Die verschlüsselte Verbindung kam nicht zustande. Unterstützt der Server SSL? Sonst SSL auf «Aus» stellen — nur in vertrauenswürdigen Netzen.'),
            self::FileNotAllowed => __('SQLite-Dateien müssen in einem erlaubten Ordner liegen (PHPDBADMIN_SQLITE_PATHS). Pfade mit «..» oder Verknüpfungen nach draussen gelten nicht.'),
            self::FileMissing => __('Unter diesem Pfad liegt keine lesbare Datei.'),
            self::FileNotDatabase => __('Die Datei lässt sich öffnen, ist aber keine SQLite-Datenbank.'),
            self::SshUnreachable => __('Unter dieser Adresse und diesem Port antwortet kein SSH-Dienst. Prüfen Sie Host und Port des SSH-Servers.'),
            self::SshKeyRejected => __('Der SSH-Server nimmt den Schlüssel für diesen Benutzer nicht an. Steht der öffentliche Schlüssel in ~/.ssh/authorized_keys des Benutzers?'),
            self::SshKeyInvalid => __('Der private Schlüssel lässt sich nicht lesen — falsches Format oder falsche Passphrase.'),
            self::SshHostKeyChanged => __('Der SSH-Server zeigt einen anderen Host-Schlüssel als beim ersten Mal. Das kann ein Angriff sein oder ein neu aufgesetzter Server — vergessen Sie den alten Schlüssel nur, wenn Sie den neuen Fingerabdruck geprüft haben.'),
            self::SshForwardFailed => __('Der Tunnel steht, aber der SSH-Server erreicht die Datenbank nicht. Host und Port der Datenbank gelten aus Sicht des SSH-Servers — oft 127.0.0.1.'),
            self::SshUnavailable => __('Auf diesem Server fehlt das Programm ssh, oder PHP darf keine Prozesse starten (proc_open).'),
            self::Unknown => __('Der Server hat die Verbindung abgelehnt. Die Meldung des Servers hilft weiter.'),
        };
    }
}
