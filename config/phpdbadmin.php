<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Anmeldung (ADR-008)
    |--------------------------------------------------------------------------
    |
    | Code per Mail: Gültigkeit in Minuten, Fehlversuche bis zur Sperre, Wartezeit bis «Code erneut senden».
    | Einladungen: Gültigkeit in Tagen.
    |
    */

    'login' => [
        'code_minutes' => (int) env('PHPDBADMIN_CODE_MINUTES', 10),
        'code_attempts' => (int) env('PHPDBADMIN_CODE_ATTEMPTS', 5),
        'resend_seconds' => (int) env('PHPDBADMIN_RESEND_SECONDS', 60),
        'invitation_days' => (int) env('PHPDBADMIN_INVITATION_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ersteinrichtung im Browser (A-06)
    |--------------------------------------------------------------------------
    |
    | Datei mit dem Einrichtungsschlüssel. Sie entsteht beim ersten Aufruf der Einrichtung und wird danach gelöscht;
    | nur wer Zugriff auf den Server hat, kann sie lesen.
    |
    */

    'setup' => [
        'key_path' => storage_path('app/private/einrichtungsschluessel.txt'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Protokoll (B-04)
    |--------------------------------------------------------------------------
    |
    | Aufbewahrung in Tagen; ältere Einträge löscht der tägliche Aufräumlauf (`model:prune`). 0 behält alles.
    |
    */

    'audit' => [
        'retention_days' => (int) env('PHPDBADMIN_AUDIT_RETENTION_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | Zielverbindungen (V-05, T-05, T-08)
    |--------------------------------------------------------------------------
    |
    | Erlaubte Hosts: Namen (auch «*.example.ch»), Adressen und Netze (CIDR), durch Kommas getrennt. Ein Name gilt,
    | wenn er selbst erlaubt ist oder jede seiner Adressen in einem erlaubten Netz liegt; verbunden wird zur einmal
    | geprüften Adresse. SQLite: Ordner, in denen Dateien liegen dürfen (auch «~/…»); die App-Datenbank selbst ist
    | nie erreichbar.
    |
    */

    'connections' => [
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) (env('PHPDBADMIN_ALLOWED_HOSTS') ?: '127.0.0.1,::1,localhost'))))),
        'sqlite_paths' => array_values(array_filter(array_map('trim', explode(',', (string) (env('PHPDBADMIN_SQLITE_PATHS') ?: storage_path('app/sqlite')))))),
        'connect_timeout' => (int) env('PHPDBADMIN_CONNECT_TIMEOUT', 5),
        // Zeitlimit je Anweisung im Arbeitsbildschirm (Katalog, Struktur); SQLite kennt keines (F-2).
        'statement_timeout_ms' => (int) env('PHPDBADMIN_STATEMENT_TIMEOUT_MS', 15000),
        // Wartung (W-05): OPTIMIZE und VACUUM grosser Tabellen brauchen länger als eine Abfrage.
        'maintenance_timeout_ms' => (int) env('PHPDBADMIN_MAINTENANCE_TIMEOUT_MS', 600000),
        // Strukturänderungen (S-02 bis S-05): ALTER TABLE grosser Tabellen und der Umbau bei SQLite brauchen länger.
        'schema_timeout_ms' => (int) env('PHPDBADMIN_SCHEMA_TIMEOUT_MS', 600000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Datenraster (D-01, D-06)
    |--------------------------------------------------------------------------
    |
    | Bis zu dieser geschätzten Zeilenzahl zählt das Raster genau; darüber zeigt es die Schätzung des Servers und
    | zählt genau nur auf Wunsch.
    |
    */

    'data' => [
        'exact_count_limit' => (int) env('PHPDBADMIN_EXACT_COUNT_LIMIT', 50000),
        // Rückgängig (D-09): bis zu so vielen Zeilen je Änderung werden Vorher-Werte gesichert, so lange.
        'undo_rows' => (int) env('PHPDBADMIN_UNDO_ROWS', 1000),
        'undo_days' => (int) env('PHPDBADMIN_UNDO_DAYS', 7),
        // Schreibschutz auf Zeit aufheben (V-11).
        'unlock_minutes' => (int) env('PHPDBADMIN_UNLOCK_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | SQL-Editor (Q-02, Q-03, Q-04, Q-09)
    |--------------------------------------------------------------------------
    |
    | Zeitgrenze je Anweisung, Zeilen je Ergebnis («mehr laden» bis zur Obergrenze), Frist des Verlaufs. SQLite-
    | Abfragen laufen in einem Arbeitsprozess; unter PHP-FPM braucht er den Pfad zur PHP-Kommandozeile, falls sie nicht
    | neben FPM liegt.
    |
    */

    'editor' => [
        'timeout_seconds' => (int) env('PHPDBADMIN_EDITOR_TIMEOUT', 30),
        'row_limit' => (int) env('PHPDBADMIN_EDITOR_ROWS', 1000),
        'max_row_limit' => (int) env('PHPDBADMIN_EDITOR_MAX_ROWS', 10000),
        'history_days' => (int) env('PHPDBADMIN_HISTORY_DAYS', 90),
        'php_binary' => env('PHPDBADMIN_PHP_BINARY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Export und Import (E-01, E-02, B-07, ADR-007)
    |--------------------------------------------------------------------------
    |
    | Aufträge laufen im Hintergrund über die Warteschlange, wenn sich ein Worker in den letzten 90 Sekunden gemeldet
    | hat. Ohne Worker (keiner gemeldet, QUEUE_CONNECTION=sync oder
    | PHPDBADMIN_TRANSFERS_BACKGROUND=false) — und für Verbindungen, deren Passwort nur in der Sitzung liegt — laufen
    | sie gleich in der Anfrage, aber nur bis zu den Grenzen: Zeilen beim Export, Dateigrösse beim Import. Dateien
    | liegen unter storage/app/private/transfers und werden nach der Frist gelöscht (model:prune, täglich).
    |
    */

    'transfers' => [
        'background' => (bool) env('PHPDBADMIN_TRANSFERS_BACKGROUND', true),
        // Ein Worker gilt als da, wenn er sich in dieser Zeit gemeldet hat (QueueHeartbeat).
        'worker_seconds' => (int) env('PHPDBADMIN_TRANSFERS_WORKER_SECONDS', 90),
        'sync_max_rows' => (int) env('PHPDBADMIN_TRANSFERS_SYNC_MAX_ROWS', 100000),
        'sync_max_bytes' => (int) env('PHPDBADMIN_TRANSFERS_SYNC_MAX_MB', 20) * 1024 * 1024,
        'max_upload_kb' => (int) env('PHPDBADMIN_TRANSFERS_MAX_UPLOAD_MB', 512) * 1024,
        'retention_hours' => (int) env('PHPDBADMIN_TRANSFERS_RETENTION_HOURS', 24),
        // Zeitlimit je Anweisung während eines Auftrags: länger als im Arbeitsbildschirm (Pakete, Indizes beim Import).
        'statement_timeout_ms' => (int) env('PHPDBADMIN_TRANSFERS_STATEMENT_TIMEOUT_MS', 600000),
    ],

    /*
    |--------------------------------------------------------------------------
    | SQL-Hilfe mit KI (Q-08)
    |--------------------------------------------------------------------------
    |
    | Aus, bis die Installation sie einschaltet. Dann braucht jedes Konto seinen eigenen Schlüssel (Konto → KI-Hilfe);
    | gesendet werden nur die Frage und das Schema (Tabellen, Spalten, Typen, Schlüssel) — nie Daten, nie der Text
    | im Editor. Claude über die Messages API von Anthropic.
    |
    */

    'ai' => [
        'enabled' => (bool) env('PHPDBADMIN_AI', false),
        'model' => (string) env('PHPDBADMIN_AI_MODEL', 'claude-opus-5-5'),
        'effort' => (string) env('PHPDBADMIN_AI_EFFORT', 'medium'),
        'endpoint' => (string) env('PHPDBADMIN_AI_ENDPOINT', 'https://api.anthropic.com/v1/messages'),
        'timeout_seconds' => (int) env('PHPDBADMIN_AI_TIMEOUT', 120),
        // So viel Schema geht höchstens mit; darüber werden Tabellen weggelassen und die Antwort sagt es.
        'max_schema_chars' => (int) env('PHPDBADMIN_AI_MAX_SCHEMA_CHARS', 60000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schemavergleich (S-08)
    |--------------------------------------------------------------------------
    |
    | Höchstzahl der Tabellen je Seite: der Vergleich liest je Tabelle Spalten, Indizes, Fremdschlüssel und Checks in
    | der Anfrage. Darüber meldet die Seite «zu viele Tabellen».
    |
    */

    'compare' => [
        'max_tables' => (int) env('PHPDBADMIN_COMPARE_MAX_TABLES', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | SSH-Tunnel (V-08)
    |--------------------------------------------------------------------------
    |
    | Verbindungen über SSH laufen durch das System-ssh (`ssh -N -L`). Der SSH-Server muss wie jeder Host erlaubt sein
    | (PHPDBADMIN_ALLOWED_HOSTS). Private Schlüssel werden eingefügt (verschlüsselt gespeichert) oder liegen als Datei
    | in einem erlaubten Ordner. Host-Schlüssel nimmt phpdbadmin beim ersten Verbinden an und prüft sie danach gegen
    | die eigene known_hosts.
    |
    */

    'ssh' => [
        'binary' => env('PHPDBADMIN_SSH_BINARY', 'ssh'),
        'keygen' => env('PHPDBADMIN_SSH_KEYGEN', 'ssh-keygen'),
        'key_paths' => array_values(array_filter(array_map('trim', explode(',', (string) (env('PHPDBADMIN_SSH_KEY_PATHS') ?: storage_path('app/ssh/keys')))))),
        'known_hosts' => storage_path('app/ssh/known_hosts'),
        'temporary' => storage_path('app/ssh/tmp'),
        // Sekunden bis der Tunnel steht (Anmeldung beim SSH-Server eingeschlossen).
        'timeout' => (int) env('PHPDBADMIN_SSH_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel Herd erkennen (V-07)
    |--------------------------------------------------------------------------
    |
    | Vorschläge im Formular «Verbindung anlegen»: laufende Dienste und die Datenbanken der Herd-Sites aus ihrer .env.
    | Ohne Angabe nur bei APP_ENV=local und vorhandenem Herd-Ordner; nur für Administratoren. Passwörter aus fremden
    | .env gehen nie an den Browser.
    |
    */

    'herd' => [
        'enabled' => env('PHPDBADMIN_HERD'),
        'path' => env('PHPDBADMIN_HERD_PATH'),
    ],

];
