<?php

namespace Tests\Unit\Connections;

use App\Connections\ConnectionNotAllowed;
use App\Connections\ConnectionProblemKind;
use App\Connections\HostPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Erlaubte Hosts und SQLite-Ordner (V-05, T-05, T-08).
 */
class HostPolicyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/phpdbadmin-hostpolicy-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/erlaubt/unter', 0777, true);
        mkdir($this->root.'/draussen', 0777, true);
        touch($this->root.'/erlaubt/notizen.sqlite');
        touch($this->root.'/erlaubt/unter/tief.sqlite');
        touch($this->root.'/draussen/geheim.sqlite');
        touch($this->root.'/erlaubt/app.sqlite');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: list<string>, 1: string, 2: string}>
     */
    public static function allowedHosts(): array
    {
        return [
            'Adresse auf der Liste' => [['127.0.0.1'], '127.0.0.1', '127.0.0.1'],
            'Adresse im Netz' => [['10.0.0.0/8'], '10.1.2.3', '10.1.2.3'],
            'Name auf der Liste' => [['localhost'], 'localhost', '127.0.0.1'],
            'Name, dessen Adressen im Netz liegen' => [['127.0.0.0/8'], 'localhost', '127.0.0.1'],
            'Gross- und Kleinschreibung' => [['127.0.0.1', 'localhost'], 'LOCALHOST', '127.0.0.1'],
            'IPv6 in Klammern' => [['::1'], '[::1]', '::1'],
        ];
    }

    /**
     * @param  list<string>  $allowed
     */
    #[DataProvider('allowedHosts')]
    public function test_allowed_host_resolves_to_checked_address(array $allowed, string $host, string $address): void
    {
        $this->assertSame($address, (new HostPolicy($allowed, []))->resolveHost($host));
    }

    /**
     * @return array<string, array{0: list<string>, 1: string, 2: ConnectionProblemKind}>
     */
    public static function refusedHosts(): array
    {
        return [
            'Adresse nicht auf der Liste' => [['127.0.0.1'], '10.0.0.5', ConnectionProblemKind::HostNotAllowed],
            'Adresse ausserhalb des Netzes' => [['10.0.0.0/8'], '192.168.1.1', ConnectionProblemKind::HostNotAllowed],
            'Name nicht auf der Liste, Adresse ausserhalb' => [['10.0.0.0/8'], 'localhost', ConnectionProblemKind::HostNotAllowed],
            'Platzhalter deckt nur Unternamen' => [['*.example.ch'], '127.0.0.1', ConnectionProblemKind::HostNotAllowed],
            'Name lässt sich nicht auflösen' => [['*.invalid'], 'gibt-es-nicht.invalid', ConnectionProblemKind::HostUnknown],
            'leer' => [['127.0.0.1'], ' ', ConnectionProblemKind::HostUnknown],
        ];
    }

    /**
     * @param  list<string>  $allowed
     */
    #[DataProvider('refusedHosts')]
    public function test_refuses_host_outside_the_list(array $allowed, string $host, ConnectionProblemKind $kind): void
    {
        try {
            (new HostPolicy($allowed, []))->resolveHost($host);
            $this->fail('Host wurde erlaubt: '.$host);
        } catch (ConnectionNotAllowed $exception) {
            $this->assertSame($kind, $exception->problem->kind);
        }
    }

    public function test_sqlite_file_in_allowed_folder_resolves_to_real_path(): void
    {
        $policy = new HostPolicy([], [$this->root.'/erlaubt']);

        $this->assertSame(realpath($this->root.'/erlaubt/notizen.sqlite'), $policy->resolveSqlitePath($this->root.'/erlaubt/notizen.sqlite'));
        $this->assertSame(realpath($this->root.'/erlaubt/unter/tief.sqlite'), $policy->resolveSqlitePath($this->root.'/erlaubt/unter/tief.sqlite'));
    }

    public function test_expands_home_folder(): void
    {
        $home = getenv('HOME');
        putenv('HOME='.$this->root);

        try {
            $policy = new HostPolicy([], ['~/erlaubt']);

            $this->assertSame(realpath($this->root.'/erlaubt/notizen.sqlite'), $policy->resolveSqlitePath('~/erlaubt/notizen.sqlite'));
        } finally {
            putenv('HOME='.$home);
        }
    }

    /**
     * @return array<string, array{0: string, 1: ConnectionProblemKind}>
     */
    public static function refusedPaths(): array
    {
        return [
            'Datei ausserhalb' => ['/draussen/geheim.sqlite', ConnectionProblemKind::FileNotAllowed],
            'mit .. nach draussen' => ['/erlaubt/../draussen/geheim.sqlite', ConnectionProblemKind::FileNotAllowed],
            'Verknüpfung nach draussen' => ['/erlaubt/link.sqlite', ConnectionProblemKind::FileNotAllowed],
            'Ordner mit gleichem Anfang' => ['/erlaubt-nicht/x.sqlite', ConnectionProblemKind::FileNotAllowed],
            'Datei fehlt' => ['/erlaubt/fehlt.sqlite', ConnectionProblemKind::FileMissing],
            'Ordner statt Datei' => ['/erlaubt/unter', ConnectionProblemKind::FileMissing],
            'eigene App-Datenbank' => ['/erlaubt/app.sqlite', ConnectionProblemKind::FileNotAllowed],
        ];
    }

    #[DataProvider('refusedPaths')]
    public function test_refuses_sqlite_path_outside_allowed_folders(string $path, ConnectionProblemKind $kind): void
    {
        symlink($this->root.'/draussen/geheim.sqlite', $this->root.'/erlaubt/link.sqlite');
        mkdir($this->root.'/erlaubt-nicht');
        touch($this->root.'/erlaubt-nicht/x.sqlite');

        $policy = new HostPolicy([], [$this->root.'/erlaubt'], $this->root.'/erlaubt/app.sqlite');

        try {
            $policy->resolveSqlitePath($this->root.$path);
            $this->fail('Pfad wurde erlaubt: '.$path);
        } catch (ConnectionNotAllowed $exception) {
            $this->assertSame($kind, $exception->problem->kind);
        }
    }

    /**
     * Ziel hinter einem SSH-Tunnel (V-08): nach denselben Regeln, ein erlaubter Name geht unverändert weiter.
     *
     * @return array<string, array{0: list<string>, 1: string, 2: string}>
     */
    public static function allowedTunnelTargets(): array
    {
        return [
            'erlaubter Name bleibt Name' => [['db.intern'], 'db.intern', 'db.intern'],
            'Name unter erlaubtem Platzhalter' => [['*.intern'], 'Kunden.DB.intern', 'kunden.db.intern'],
            'Adresse im Netz' => [['10.0.0.0/8'], '10.1.2.3', '10.1.2.3'],
            'Name, der hier auf erlaubte Adressen auflöst' => [['127.0.0.0/8'], 'localhost', '127.0.0.1'],
        ];
    }

    /**
     * @param  list<string>  $allowed
     */
    #[DataProvider('allowedTunnelTargets')]
    public function test_tunnel_target_follows_the_same_rules(array $allowed, string $host, string $target): void
    {
        $this->assertSame($target, (new HostPolicy($allowed, []))->resolveTunnelTarget($host));
    }

    /**
     * @return array<string, array{0: list<string>, 1: string, 2: ConnectionProblemKind}>
     */
    public static function refusedTunnelTargets(): array
    {
        return [
            'Adresse ausserhalb des Netzes' => [['10.0.0.0/8'], '192.168.1.1', ConnectionProblemKind::HostNotAllowed],
            'Name, der nicht auf der Liste steht' => [['127.0.0.1'], 'db.example.invalid', ConnectionProblemKind::HostUnknown],
            'Name mit Zeichen für ssh' => [['*.intern'], 'a:b.intern', ConnectionProblemKind::HostNotAllowed],
            'leer' => [['*.intern'], '', ConnectionProblemKind::HostUnknown],
        ];
    }

    /**
     * @param  list<string>  $allowed
     */
    #[DataProvider('refusedTunnelTargets')]
    public function test_refuses_tunnel_target_outside_the_list(array $allowed, string $host, ConnectionProblemKind $kind): void
    {
        try {
            (new HostPolicy($allowed, []))->resolveTunnelTarget($host);
            $this->fail('Ziel wurde erlaubt: '.$host);
        } catch (ConnectionNotAllowed $exception) {
            $this->assertSame($kind, $exception->problem->kind);
        }
    }

    public function test_ssh_key_file_must_lie_in_allowed_folder(): void
    {
        mkdir($this->root.'/schluessel');
        touch($this->root.'/schluessel/id_ed25519');
        symlink($this->root.'/draussen/geheim.sqlite', $this->root.'/schluessel/link');
        $policy = new HostPolicy([], [], null, [$this->root.'/schluessel']);

        $this->assertSame(realpath($this->root.'/schluessel/id_ed25519'), $policy->resolveSshKeyPath($this->root.'/schluessel/id_ed25519'));

        foreach (['/schluessel/link' => ConnectionProblemKind::FileNotAllowed, '/schluessel/../draussen/geheim.sqlite' => ConnectionProblemKind::FileNotAllowed, '/schluessel/fehlt' => ConnectionProblemKind::FileMissing] as $path => $kind) {
            try {
                $policy->resolveSshKeyPath($this->root.$path);
                $this->fail('Schlüsseldatei wurde erlaubt: '.$path);
            } catch (ConnectionNotAllowed $exception) {
                $this->assertSame($kind, $exception->problem->kind, $path);
            }
        }
    }
}
