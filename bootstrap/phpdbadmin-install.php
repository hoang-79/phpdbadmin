<?php

/*
|--------------------------------------------------------------------------
| Brücke für den Web-Installer
|--------------------------------------------------------------------------
|
| Der Installer (`phpdbadmin-installer.php`) lädt diese Datei im selben PHP-Prozess, nachdem er das Release entpackt
| und die `.env` geschrieben hat, und ruft die Funktion auf, die sie zurückgibt. Sie bootet die App, richtet die
| App-Datenbank ein, legt das erste Administratorkonto an und gibt den Einrichtungslink zurück
| (App\Services\Setup\WebInstall).
|
| Eingabe:  name, email, locale ('de'|'en'), publicPath (Ordner, aus dem der Webserver ausliefert, oder null)
| Rückgabe: version, setupUrl (oder null), existing (es gab schon ein Konto), checks (Systemprüfung:
|           title, level 'ok'|'warn'|'fail', hint)
| Fehler:   App\Services\Setup\InstallFailed mit einer Meldung in der gewählten Sprache
|
*/

use App\Services\Setup\WebInstall;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

return static function (array $input): array {
    require_once __DIR__.'/../vendor/autoload.php';

    /** @var Application $app */
    $app = require __DIR__.'/app.php';

    $publicPath = $input['publicPath'] ?? null;

    if (is_string($publicPath) && $publicPath !== '') {
        $app->usePublicPath($publicPath);
    }

    $app->make(Kernel::class)->bootstrap();

    return $app->make(WebInstall::class)->run($input);
};
