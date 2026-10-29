<?php

namespace App\Console\Commands;

use App\Services\Release\ReleaseBuilder;
use App\Services\Release\ReleaseSignature;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Release für den Web-Installer bauen: `phpdbadmin-<version>.zip`, `latest.json` (Grösse, Prüfsumme, Ed25519-Signatur)
 * und `phpdbadmin-installer.php` mit dem öffentlichen Schlüssel. Hochgeladen wird von Hand als Release `v<version>`:
 * `gh release create v<version> <ausgabe>/*`.
 *
 * Schlüssel: `--key` oder `PHPDBADMIN_RELEASE_KEY` (Pfad oder Base64 des Geheimschlüssels). `--keygen=<pfad>` legt
 * einmalig ein Schlüsselpaar an und baut nichts.
 */
#[Signature('phpdbadmin:release
    {--out= : Ausgabeordner (Vorgabe storage/app/release)}
    {--key= : Geheimschlüssel für die Signatur — Pfad oder Base64 (sonst PHPDBADMIN_RELEASE_KEY)}
    {--vendor-from= : fertigen vendor-Ordner übernehmen statt composer install --no-dev}
    {--keygen= : neues Schlüsselpaar in diese Datei schreiben und den öffentlichen Schlüssel ausgeben}')]
#[Description('Release-Archiv mit Manifest und Web-Installer bauen')]
class ReleaseCommand extends Command
{
    public function handle(ReleaseBuilder $builder): int
    {
        if (filled($this->option('keygen'))) {
            return $this->keygen((string) $this->option('keygen'));
        }

        $out = (string) ($this->option('out') ?: storage_path('app/release'));
        $keyValue = (string) ($this->option('key') ?: getenv('PHPDBADMIN_RELEASE_KEY') ?: '');

        try {
            $secretKey = $keyValue === '' ? null : ReleaseSignature::loadSecretKey($keyValue);

            if ($secretKey === null) {
                $this->components->warn('Ohne Schlüssel bleibt das Release unsigniert — ein Installer mit öffentlichem Schlüssel lehnt es ab.');
            }

            $result = $builder->build(
                $out,
                $secretKey,
                filled($this->option('vendor-from')) ? (string) $this->option('vendor-from') : null,
                fn (string $step) => $this->components->task($step),
            );
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $manifest = $result->manifest;
        $this->newLine();
        $this->components->twoColumnDetail('Archiv', $result->zipPath);
        $this->components->twoColumnDetail('Grösse', number_format($manifest['size'] / 1024 / 1024, 1, '.', "'").' MB');
        $this->components->twoColumnDetail('SHA-256', $manifest['sha256']);
        $this->components->twoColumnDetail('Signatur', $manifest['signature'] === null ? '<fg=yellow>keine</>' : '<fg=green>Ed25519</>');
        $this->components->twoColumnDetail('Manifest', $result->manifestPath);
        $this->components->twoColumnDetail('Installer', $result->installerPath ?? '<fg=yellow>fehlt (resources/installer/phpdbadmin-installer.php)</>');
        $this->newLine();
        $this->line('  Hochladen: <options=bold>gh release create '.$manifest['tag'].' '.$out.'/* --title "phpdbadmin '.$manifest['version'].'"</>');

        return self::SUCCESS;
    }

    private function keygen(string $path): int
    {
        try {
            $publicKey = ReleaseSignature::generate($path);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Schlüsselpaar angelegt. Geheimschlüssel: '.$path.' (nur für den Besitzer lesbar, nie ins Repository).');
        $this->line('  Öffentlicher Schlüssel: <options=bold>'.$publicKey.'</>');

        return self::SUCCESS;
    }
}
