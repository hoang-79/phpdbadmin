<?php

namespace Tests\Feature\Services\Update;

use App\Services\Release\ReleaseSignature;
use App\Services\Update\UpdateChannel;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Woher neue Versionen kommen (B-09): nur ein signiertes Manifest zählt, der Stand liegt im Zwischenspeicher, und ohne
 * Zwischenspeicher fragt nur nach draussen, wer es darf.
 */
class UpdateChannelTest extends TestCase
{
    private string $secretKey;

    protected function setUp(): void
    {
        parent::setUp();

        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        config([
            'phpdbadmin.version' => '1.4.0',
            'phpdbadmin.release.repository' => 'hoang-79/phpdbadmin',
            'phpdbadmin.release.public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'phpdbadmin.update.manifest_url' => null,
        ]);
        Http::preventStrayRequests();
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function manifest(string $version, ?string $secretKey = null, array $override = []): array
    {
        $file = 'phpdbadmin-'.$version.'.zip';
        $sha256 = str_repeat('a', 64);

        return [
            'format' => ReleaseSignature::FORMAT, 'version' => $version, 'tag' => 'v'.$version, 'file' => $file,
            'size' => 1234, 'sha256' => $sha256, 'php' => '8.5.0', 'extensions' => ['json'], 'published' => '2026-11-20T08:00:00Z',
            'signature' => ReleaseSignature::sign(ReleaseSignature::message($version, $file, 1234, $sha256), $secretKey ?? $this->secretKey),
            ...$override,
        ];
    }

    public function test_offers_a_newer_signed_release_from_github_with_its_notes(): void
    {
        Http::fake(['github.com/hoang-79/phpdbadmin/releases/latest/download/latest.json' => Http::response($this->manifest('1.5.0'))]);

        $status = app(UpdateChannel::class)->check();

        $this->assertTrue($status['available']);
        $this->assertSame('1.5.0', $status['latest']);
        $this->assertSame('https://github.com/hoang-79/phpdbadmin/releases/tag/v1.5.0', $status['notesUrl']);
        $this->assertNull($status['error']);
    }

    public function test_the_same_version_is_not_offered(): void
    {
        Http::fake(['github.com/*' => Http::response($this->manifest('1.4.0'))]);

        $status = app(UpdateChannel::class)->check();

        $this->assertFalse($status['available']);
        $this->assertSame('1.4.0', $status['latest']);
    }

    public function test_a_release_signed_with_another_key_is_not_offered(): void
    {
        $foreign = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        Http::fake(['github.com/*' => Http::response($this->manifest('9.9.9', $foreign))]);

        $status = app(UpdateChannel::class)->check();

        $this->assertFalse($status['available']);
        $this->assertNull($status['latest']);
        $this->assertStringContainsString('Signatur', (string) $status['error']);
    }

    public function test_a_changed_version_breaks_the_signature(): void
    {
        Http::fake(['github.com/*' => Http::response($this->manifest('1.5.0', override: ['version' => '9.9.9', 'file' => 'phpdbadmin-9.9.9.zip']))]);

        $status = app(UpdateChannel::class)->check();

        $this->assertFalse($status['available']);
        $this->assertStringContainsString('Signatur', (string) $status['error']);
    }

    public function test_an_unreachable_source_is_reported_and_remembered_for_an_hour(): void
    {
        Http::fake(['github.com/*' => Http::failedConnection()]);
        $channel = app(UpdateChannel::class);

        $status = $channel->check();

        $this->assertStringContainsString('nicht erreichbar', (string) $status['error']);
        $this->assertSame($status, $channel->status());
        $this->travel(61)->minutes();
        $this->assertNull($channel->status(network: false));
    }

    public function test_status_comes_from_the_cache_for_twelve_hours_and_never_asks_without_permission(): void
    {
        Http::fake(['github.com/*' => Http::response($this->manifest('1.5.0'))]);
        $channel = app(UpdateChannel::class);

        $this->assertNull($channel->status(network: false));
        Http::assertNothingSent();

        $channel->status();
        $channel->status();
        Http::assertSentCount(1);

        $this->travel(13)->hours();
        $channel->status();
        Http::assertSentCount(2);
    }

    public function test_after_an_update_the_status_of_the_previous_version_no_longer_counts(): void
    {
        Http::fake(['github.com/*' => Http::response($this->manifest('1.5.0'))]);
        $channel = app(UpdateChannel::class);
        $channel->check();

        config(['phpdbadmin.version' => '1.5.0']);

        $this->assertNull($channel->cached());
    }

    public function test_an_own_source_serves_the_archive_beside_its_manifest(): void
    {
        config(['phpdbadmin.update.manifest_url' => 'https://spiegel.example.ch/phpdbadmin/latest.json']);
        Http::fake(['spiegel.example.ch/*' => Http::response($this->manifest('1.5.0'))]);
        $channel = app(UpdateChannel::class);

        $manifest = $channel->latest();

        $this->assertSame('https://spiegel.example.ch/phpdbadmin/phpdbadmin-1.5.0.zip', $channel->archiveUrl($manifest));
        $this->assertNull($channel->notesUrl($manifest));
    }
}
