<?php

namespace Tests\Concerns;

/**
 * Ein nachgebauter Herd-Ordner für die Erkennung (V-07) — so, wie Herd ihn auf macOS anlegt: Dienste in
 * `config/services.plist`, Ordner der Sites in `config/valet/config.json`, verknüpfte Sites als Verknüpfungen in
 * `config/valet/Sites`, je Site eine `.env`.
 */
trait FakeHerd
{
    /** Passwort der Site «shop» — darf nie im Browser landen. */
    protected const string HERD_SHOP_PASSWORD = 'Geheim-Shop-Passwort-1';

    /**
     * @param  int  $runningPort  Port eines «laufenden» Dienstes (der Test öffnet ihn)
     * @param  int  $stoppedPort  Port eines Dienstes, der nicht läuft
     * @return string Pfad des Herd-Ordners
     */
    protected function fakeHerd(string $root, int $runningPort, int $stoppedPort): string
    {
        $herd = $root.'/Herd';
        @mkdir($herd.'/config/valet/Sites', 0777, true);
        @mkdir($root.'/Projekte/shop', 0777, true);
        @mkdir($root.'/Projekte/blog/database', 0777, true);
        @mkdir($root.'/Projekte/ohne-db', 0777, true);
        @mkdir($root.'/Projekte/mit-url', 0777, true);
        @mkdir($root.'/Extern/verknuepft', 0777, true);

        file_put_contents($herd.'/config/services.plist', $this->plist([
            ['id' => 'AAAA-1', 'name' => 'MariaDB', 'type' => 'mariadb', 'port' => (string) $runningPort, 'version' => '10.11.6'],
            ['id' => 'BBBB-2', 'name' => 'Redis', 'type' => 'redis', 'port' => '6379', 'version' => '7.4.7'],
            ['id' => 'CCCC-3', 'name' => 'PostgreSQL', 'type' => 'postgresql', 'port' => (string) $stoppedPort, 'version' => '18'],
        ]));
        file_put_contents($herd.'/config/valet/config.json', json_encode([
            'tld' => 'test',
            'paths' => [$herd.'/config/valet/Sites', $root.'/Projekte', $root.'/gibt-es-nicht'],
        ]));
        // PHP merkt sich aufgelöste Pfade (realpath-Cache); ohne Leeren meldet symlink() nach dem Aufräumen des
        // vorigen Tests «File exists».
        clearstatcache(true);
        symlink($root.'/Extern/verknuepft', $herd.'/config/valet/Sites/verknuepft');

        file_put_contents($root.'/Projekte/shop/.env', implode("\n", [
            'APP_NAME=Shop',
            'DB_CONNECTION=mysql',
            'DB_HOST=127.0.0.1',
            'DB_PORT=3307',
            'DB_DATABASE=shop',
            'DB_USERNAME=shop',
            'DB_PASSWORD="'.self::HERD_SHOP_PASSWORD.'"',
        ]));
        file_put_contents($root.'/Projekte/blog/.env', "DB_CONNECTION=sqlite\n");
        touch($root.'/Projekte/blog/database/database.sqlite');
        file_put_contents($root.'/Projekte/ohne-db/.env', "APP_NAME=OhneDatenbank\n");
        file_put_contents($root.'/Projekte/mit-url/.env', "DB_URL=pgsql://app:Geheim%2FUrl@127.0.0.1:5432/app\n");
        file_put_contents($root.'/Extern/verknuepft/.env', "DB_CONNECTION=mariadb\nDB_HOST=db.extern.example\nDB_USERNAME=app\n");

        return $herd;
    }

    /**
     * @param  list<array<string, string>>  $services
     */
    private function plist(array $services): string
    {
        $dicts = array_map(fn (array $service): string => "\t<dict>\n".implode('', array_map(
            fn (string $key, string $value): string => "\t\t<key>{$key}</key>\n\t\t<string>".htmlspecialchars($value)."</string>\n",
            array_keys($service),
            $service,
        ))."\t\t<key>autoStart</key>\n\t\t<true/>\n\t</dict>\n", $services);

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">'."\n"
            ."<plist version=\"1.0\">\n<array>\n".implode('', $dicts)."</array>\n</plist>\n";
    }
}
