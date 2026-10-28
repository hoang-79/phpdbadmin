<?php

namespace Tests\Feature\Services\Auth;

use App\Services\Auth\SetupKey;
use Tests\TestCase;

class SetupKeyTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/einrichtung-'.getmypid());
        config(['phpdbadmin.setup.key_path' => $this->folder.'/private/einrichtungsschluessel.txt']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    public function test_creates_readable_key_file_only_for_the_server_account(): void
    {
        app(SetupKey::class)->ensure();

        $path = $this->folder.'/private/einrichtungsschluessel.txt';
        $this->assertFileExists($path);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/m', (string) file_get_contents($path));
    }

    public function test_never_replaces_a_key_that_may_already_have_been_read(): void
    {
        $key = app(SetupKey::class);
        $key->ensure();
        $first = file_get_contents($key->path());

        $key->ensure();

        $this->assertSame($first, file_get_contents($key->path()));
    }

    public function test_accepts_key_regardless_of_case_spaces_and_dashes(): void
    {
        $key = app(SetupKey::class);
        $key->ensure();
        preg_match('/^([A-Z2-9]{4})-([A-Z2-9]{4})-([A-Z2-9]{4})$/m', (string) file_get_contents($key->path()), $match);

        $this->assertTrue($key->matches(strtolower($match[1].' '.$match[2].$match[3])));
        $this->assertFalse($key->matches('AAAA-AAAA-AAAA'));
        $this->assertFalse($key->matches(''));
    }

    public function test_nothing_matches_without_key_file(): void
    {
        $key = app(SetupKey::class);
        $key->ensure();
        $key->forget();

        $this->assertFileDoesNotExist($key->path());
        $this->assertFalse($key->matches('AAAA-AAAA-AAAA'));
    }
}
