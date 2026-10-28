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
    ],

];
