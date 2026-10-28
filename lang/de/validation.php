<?php

/*
 * Prüfmeldungen auf Deutsch für die Regeln, die phpdbadmin verwendet; alle übrigen fallen auf Englisch zurück.
 * Form «Feld: was fehlt», damit die Sätze ohne Artikel stimmen.
 */

return [
    'array' => ':Attribute: Liste erwartet.',
    'between' => [
        'numeric' => ':Attribute: zwischen :min und :max.',
        'string' => ':Attribute: zwischen :min und :max Zeichen.',
    ],
    'boolean' => ':Attribute: ja oder nein.',
    'digits' => ':Attribute: genau :digits Ziffern eingeben.',
    'email' => 'Das ist keine gültige E-Mail-Adresse.',
    'in' => ':Attribute: dieser Wert ist nicht erlaubt.',
    'integer' => ':Attribute: ganze Zahl erwartet.',
    'max' => [
        'numeric' => ':Attribute: höchstens :max.',
        'string' => ':Attribute: höchstens :max Zeichen.',
    ],
    'min' => [
        'numeric' => ':Attribute: mindestens :min.',
        'string' => ':Attribute: mindestens :min Zeichen.',
    ],
    'numeric' => ':Attribute: Zahl erwartet.',
    'required' => ':Attribute fehlt.',
    'required_if' => ':Attribute fehlt.',
    'string' => ':Attribute: Text erwartet.',
    'unique' => ':Attribute ist schon vergeben.',

    'attributes' => [
        'code' => 'Code',
        'credential' => 'Passkey',
        'email' => 'E-Mail-Adresse',
        'name' => 'Name',
    ],
];
