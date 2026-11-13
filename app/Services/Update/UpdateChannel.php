<?php

namespace App\Services\Update;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/**
 * Woher neue Versionen kommen (B-09): das Manifest des neuesten Releases bei GitHub (oder unter
 * `phpdbadmin.update.manifest_url`), angenommen nur mit gültiger Signatur. Der Stand liegt 12 Stunden im
 * Zwischenspeicher, ein Fehler eine Stunde — so fragt phpdbadmin selten nach draussen, und nie für ein Konto, das
 * nicht verwaltet.
 *
 * @phpstan-type UpdateStatus array{current: string, latest: string|null, available: bool, published: string|null, notesUrl: string|null, php: string|null, extensions: list<string>, error: string|null, checkedAt: string}
 */
final class UpdateChannel
{
    public const string CACHE_KEY = 'phpdbadmin.update.status';

    private const int HOURS_VALID = 12;

    public function currentVersion(): string
    {
        return (string) config('phpdbadmin.version');
    }

    public function publicKey(): string
    {
        return (string) config('phpdbadmin.release.public_key');
    }

    public function manifestUrl(): string
    {
        $custom = config('phpdbadmin.update.manifest_url');

        return is_string($custom) && $custom !== ''
            ? $custom
            : 'https://github.com/'.config('phpdbadmin.release.repository').'/releases/latest/download/latest.json';
    }

    /**
     * Bei GitHub das Archiv unter dem Tag des Manifests (wie der Web-Installer); bei einer eigenen Quelle neben dem
     * Manifest.
     */
    public function archiveUrl(ReleaseManifest $manifest): string
    {
        $custom = config('phpdbadmin.update.manifest_url');

        if (is_string($custom) && $custom !== '') {
            return Str::beforeLast($custom, '/').'/'.rawurlencode($manifest->file);
        }

        return 'https://github.com/'.config('phpdbadmin.release.repository').'/releases/download/'.rawurlencode($manifest->tag).'/'.rawurlencode($manifest->file);
    }

    public function notesUrl(ReleaseManifest $manifest): ?string
    {
        $custom = config('phpdbadmin.update.manifest_url');

        return is_string($custom) && $custom !== ''
            ? null
            : 'https://github.com/'.config('phpdbadmin.release.repository').'/releases/tag/'.rawurlencode($manifest->tag);
    }

    /**
     * Das neueste Release — nur, wenn seine Signatur zum Schlüssel dieser Installation passt.
     *
     * @throws UpdateFailed
     */
    public function latest(): ReleaseManifest
    {
        try {
            $response = $this->http(20)->accept('application/json')->get($this->manifestUrl());
        } catch (ConnectionException $exception) {
            throw new UpdateFailed(__('Die Quelle der Releases ist nicht erreichbar: :reason', ['reason' => $exception->getMessage()]));
        }

        if (! $response->successful()) {
            throw new UpdateFailed(__('Die Quelle der Releases antwortet mit HTTP :status.', ['status' => $response->status()]));
        }

        try {
            $data = json_decode($response->body(), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }

        $manifest = ReleaseManifest::fromArray($data);

        if ($this->publicKey() === '') {
            throw new UpdateFailed(__('Dieser Installation fehlt der öffentliche Schlüssel der Releases (PHPDBADMIN_RELEASE_PUBLIC_KEY) — ohne ihn nimmt sie kein Release an.'));
        }

        if (! $manifest->isSignedBy($this->publicKey())) {
            throw new UpdateFailed(__('Die Signatur des neuesten Releases passt nicht zum Schlüssel dieser Installation — es wird nicht angeboten.'));
        }

        return $manifest;
    }

    /**
     * Stand der Versionen: aus dem Zwischenspeicher, sonst neu gefragt — ohne `$network` nie nach draussen.
     *
     * @return UpdateStatus|null
     */
    public function status(bool $network = true): ?array
    {
        $cached = $this->cached();

        if ($cached !== null || ! $network) {
            return $cached;
        }

        return $this->check();
    }

    /**
     * Jetzt nachsehen und den Stand ablegen.
     *
     * @return UpdateStatus
     */
    public function check(): array
    {
        $current = $this->currentVersion();
        $status = ['current' => $current, 'latest' => null, 'available' => false, 'published' => null, 'notesUrl' => null,
            'php' => null, 'extensions' => [], 'error' => null, 'checkedAt' => Date::now()->toIso8601String()];

        try {
            $manifest = $this->latest();
            $status = [...$status, 'latest' => $manifest->version, 'available' => version_compare($manifest->version, $current, '>'),
                'published' => $manifest->published, 'notesUrl' => $this->notesUrl($manifest), 'php' => $manifest->php,
                'extensions' => $manifest->extensions];
            Cache::put(self::CACHE_KEY, $status, Date::now()->addHours(self::HOURS_VALID));
        } catch (UpdateFailed $exception) {
            $status['error'] = $exception->getMessage();
            Cache::put(self::CACHE_KEY, $status, Date::now()->addHour());
        }

        return $status;
    }

    /**
     * Abgelegter Stand dieser Version — nach einer Aktualisierung gilt der alte nicht mehr.
     *
     * @return UpdateStatus|null
     */
    public function cached(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        /** @var UpdateStatus|null */
        return is_array($cached) && ($cached['current'] ?? null) === $this->currentVersion() ? $cached : null;
    }

    /**
     * Fehlt der Stand, nach der Antwort nachsehen (die Seite wartet nicht darauf) — höchstens einmal je Minute.
     */
    public function refreshAfterResponse(): void
    {
        if (! config('phpdbadmin.update.check') || $this->cached() !== null || ! Cache::add(self::CACHE_KEY.'.running', true, 60)) {
            return;
        }

        app()->terminating(function (): void {
            try {
                $this->check();
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function http(int $timeout): PendingRequest
    {
        return Http::timeout($timeout)->connectTimeout(10)->withUserAgent('phpdbadmin/'.$this->currentVersion());
    }
}
