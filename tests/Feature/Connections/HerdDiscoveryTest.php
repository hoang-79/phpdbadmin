<?php

namespace Tests\Feature\Connections;

use App\Connections\HerdDiscovery;
use App\Connections\HerdSuggestion;
use Tests\Concerns\FakeHerd;
use Tests\TestCase;

/**
 * Herd-Erkennung (V-07) gegen einen nachgebauten Herd-Ordner: Dienste mit Port und Zustand, Sites aus den Ordnern
 * der Herd-Konfiguration mit den Angaben ihrer .env — nie mit Passwort im Vorschlag.
 */
class HerdDiscoveryTest extends TestCase
{
    use FakeHerd;

    private string $root;

    /** @var resource */
    private $listener;

    private int $runningPort;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/herd-'.getmypid());
        @mkdir($this->root, 0777, true);

        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($listener);
        $this->listener = $listener;
        $name = (string) stream_socket_get_name($listener, false);
        $this->runningPort = (int) substr($name, (int) strrpos($name, ':') + 1);

        config([
            'phpdbadmin.herd.enabled' => true,
            'phpdbadmin.herd.path' => $this->fakeHerd($this->root, $this->runningPort, $this->closedPort()),
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'phpdbadmin.connections.sqlite_paths' => [$this->root.'/Projekte'],
        ]);
    }

    protected function tearDown(): void
    {
        fclose($this->listener);
        exec('rm -rf '.escapeshellarg($this->root));

        parent::tearDown();
    }

    public function test_suggests_database_services_with_port_and_state(): void
    {
        $services = array_values(array_filter(app(HerdDiscovery::class)->suggestions(), fn (HerdSuggestion $suggestion): bool => $suggestion->origin === HerdSuggestion::SERVICE));

        $this->assertSame(['MariaDB', 'PostgreSQL'], array_map(fn (HerdSuggestion $service): string => $service->name, $services), 'Redis ist keine Datenbank');
        $this->assertSame(['mariadb', '127.0.0.1', $this->runningPort, 'root', true, '10.11.6'], [$services[0]->driver->value, $services[0]->host, $services[0]->port, $services[0]->username, $services[0]->running, $services[0]->version]);
        $this->assertFalse($services[1]->running);
    }

    public function test_suggests_sites_from_their_env_without_password(): void
    {
        $sites = [];
        foreach (app(HerdDiscovery::class)->suggestions() as $suggestion) {
            if ($suggestion->origin === HerdSuggestion::SITE) {
                $sites[$suggestion->name] = $suggestion->toArray();
            }
        }

        $this->assertSame(['blog', 'mit-url', 'shop', 'verknuepft'], array_keys($sites), 'ohne-db hat keine Datenbank');
        $this->assertSame(['mysql', '127.0.0.1', 3307, 'shop', 'shop', true, true], [$sites['shop']['driver'], $sites['shop']['host'], $sites['shop']['port'], $sites['shop']['username'], $sites['shop']['database'], $sites['shop']['hasPassword'], $sites['shop']['allowed']]);
        $this->assertSame(['sqlite', realpath($this->root.'/Projekte/blog/database/database.sqlite'), true], [$sites['blog']['driver'], $sites['blog']['sqlitePath'], $sites['blog']['allowed']]);
        $this->assertSame(['pgsql', 'app', 'app', 5432, true], [$sites['mit-url']['driver'], $sites['mit-url']['username'], $sites['mit-url']['database'], $sites['mit-url']['port'], $sites['mit-url']['hasPassword']]);
        $this->assertFalse($sites['verknuepft']['allowed'], 'Host ausserhalb der erlaubten Liste');

        $json = (string) json_encode($sites);
        $this->assertStringNotContainsString(self::HERD_SHOP_PASSWORD, $json);
        $this->assertStringNotContainsString('Geheim', $json);
    }

    public function test_password_comes_only_for_the_same_host_port_and_user(): void
    {
        $discovery = app(HerdDiscovery::class);
        $shop = array_find($discovery->suggestions(), fn (HerdSuggestion $suggestion): bool => $suggestion->name === 'shop');
        $this->assertNotNull($shop);

        $this->assertSame(self::HERD_SHOP_PASSWORD, $discovery->passwordFor($shop->id, '127.0.0.1', 3307, 'shop'));
        $this->assertNull($discovery->passwordFor($shop->id, '127.0.0.1', 3306, 'shop'), 'anderer Port');
        $this->assertNull($discovery->passwordFor($shop->id, '127.0.0.1', 3307, 'root'), 'anderer Benutzer');
        $this->assertNull($discovery->passwordFor('site:gibt-es-nicht', '127.0.0.1', 3307, 'shop'));
        $this->assertNull($discovery->passwordFor('service:AAAA-1', '127.0.0.1', $this->runningPort, 'root'), 'Dienste haben kein Passwort');
    }

    public function test_is_off_outside_local_by_default_and_without_herd_folder(): void
    {
        config(['phpdbadmin.herd.enabled' => null]);
        $this->assertFalse(app()->isLocal());
        $this->assertFalse(app(HerdDiscovery::class)->enabled(), 'Vorgabe: nur bei APP_ENV=local');
        $this->assertSame([], app(HerdDiscovery::class)->suggestions());

        config(['phpdbadmin.herd.enabled' => true, 'phpdbadmin.herd.path' => $this->root.'/kein-herd']);
        $this->assertFalse(app(HerdDiscovery::class)->enabled(), 'ohne Herd-Ordner aus');
    }

    private function closedPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
