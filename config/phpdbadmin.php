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
    ],

];
