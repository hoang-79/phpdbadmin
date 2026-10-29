<?php

namespace App\Services\Release;

/**
 * Was `phpdbadmin:release` gebaut hat: Archiv, Manifest, Installer (falls vorhanden) und der Inhalt von `latest.json`.
 */
final readonly class ReleaseResult
{
    /**
     * @param  array{format: string, version: string, tag: string, file: string, size: int, sha256: string, php: string, extensions: list<string>, drivers: list<string>, installer_extensions: list<string>, published: string, signature: string|null}  $manifest
     */
    public function __construct(
        public string $zipPath,
        public string $manifestPath,
        public ?string $installerPath,
        public array $manifest,
    ) {}
}
