<?php

namespace App\Services\Update;

use App\Services\Release\ReleaseSignature;

/**
 * `latest.json` eines Releases (Format phpdbadmin-release:1, gebaut von `phpdbadmin:release`), auf seine Form geprüft.
 * Echt ist es erst mit `isSignedBy()`: Die Signatur deckt Version, Dateiname, Grösse und Prüfsumme — PHP-Version und
 * Erweiterungen sind nur Hinweise; massgebend ist, was im Archiv steht.
 */
final readonly class ReleaseManifest
{
    /**
     * @param  list<string>  $extensions
     */
    public function __construct(
        public string $version,
        public string $tag,
        public string $file,
        public int $size,
        public string $sha256,
        public ?string $signature,
        public string $php,
        public array $extensions,
        public ?string $published,
    ) {}

    /**
     * @throws UpdateFailed
     */
    public static function fromArray(mixed $data): self
    {
        $invalid = new UpdateFailed(__('Das Manifest des neuesten Releases ist unvollständig oder hat eine unbekannte Form.'));

        if (! is_array($data) || ($data['format'] ?? null) !== ReleaseSignature::FORMAT) {
            throw $invalid;
        }

        $version = $data['version'] ?? null;
        $file = $data['file'] ?? null;
        $size = $data['size'] ?? null;
        $sha256 = $data['sha256'] ?? null;
        $signature = $data['signature'] ?? null;

        if (! is_string($version) || preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/', $version) !== 1
            || $file !== 'phpdbadmin-'.$version.'.zip'
            || ! is_int($size) || $size <= 0
            || ! is_string($sha256) || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1
            || ($signature !== null && ! is_string($signature))) {
            throw $invalid;
        }

        $tag = is_string($data['tag'] ?? null) && preg_match('/^[0-9A-Za-z.\-]+$/', $data['tag']) === 1 ? $data['tag'] : 'v'.$version;
        $php = is_string($data['php'] ?? null) && preg_match('/^\d+\.\d+\.\d+$/', $data['php']) === 1 ? $data['php'] : '8.5.0';
        $extensions = array_values(array_filter(
            is_array($data['extensions'] ?? null) ? $data['extensions'] : [],
            fn (mixed $extension): bool => is_string($extension) && preg_match('/^[a-z0-9_]+$/', $extension) === 1,
        ));
        $published = is_string($data['published'] ?? null) ? $data['published'] : null;

        return new self($version, $tag, $file, $size, $sha256, $signature, $php, $extensions, $published);
    }

    public function isSignedBy(string $publicKey): bool
    {
        return $this->signature !== null && $publicKey !== ''
            && ReleaseSignature::verify(ReleaseSignature::message($this->version, $this->file, $this->size, $this->sha256), $this->signature, $publicKey);
    }

    /**
     * Erweiterungen, die dieses PHP nicht geladen hat.
     *
     * @return list<string>
     */
    public function missingExtensions(): array
    {
        return array_values(array_filter($this->extensions, fn (string $extension): bool => ! extension_loaded($extension)));
    }
}
