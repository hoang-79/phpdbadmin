<?php

namespace App\Services\Auth;

/**
 * Einrichtungsschlüssel für die Ersteinrichtung im Browser (A-06): eine Datei auf dem Server, die nur lesen kann, wer
 * Zugriff auf die Installation hat (Dateimanager des Hostings, FTP, Editor). So kann nicht der erste Besucher einer
 * frisch installierten Seite Administrator werden. Die Datei entsteht beim ersten Aufruf der Einrichtung und wird
 * nach der Einrichtung gelöscht.
 */
final class SetupKey
{
    /** Ohne leicht verwechselbare Zeichen (0/O, 1/I/L). */
    private const string ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function path(): string
    {
        return (string) config('phpdbadmin.setup.key_path');
    }

    /** Pfad, wie er in der Oberfläche steht: relativ zum Ordner der Installation, wo möglich. */
    public function displayPath(): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($this->path(), $base) ? substr($this->path(), strlen($base)) : $this->path();
    }

    /**
     * Legt die Datei an, falls es sie noch nicht gibt — nie überschreiben, sonst gälte ein schon gelesener Schlüssel
     * nicht mehr.
     */
    public function ensure(): void
    {
        if ($this->stored() !== null) {
            return;
        }

        @mkdir(dirname($this->path()), 0700, true);
        @unlink($this->path());

        $handle = @fopen($this->path(), 'x');

        if ($handle === false) {
            return;
        }

        $key = implode('-', [$this->block(), $this->block(), $this->block()]);

        // In der Sprache der Person, die die Ersteinrichtung öffnet — sie liest die Datei als Nächstes.
        fwrite($handle, __('Einrichtungsschlüssel für phpdbadmin')."\n\n{$key}\n\n"
            .__('Im Formular der Ersteinrichtung eingeben. Die Datei wird danach gelöscht.')."\n");
        fclose($handle);
        @chmod($this->path(), 0600);
    }

    public function matches(string $input): bool
    {
        $stored = $this->stored();

        return $stored !== null && hash_equals($stored, self::normalize($input));
    }

    public function forget(): void
    {
        @unlink($this->path());
    }

    /** Gross- und Kleinschreibung, Leerzeichen und Bindestriche spielen keine Rolle. */
    public static function normalize(string $input): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($input));
    }

    private function stored(): ?string
    {
        $content = is_file($this->path()) ? (string) file_get_contents($this->path()) : '';

        return preg_match('/^([A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4})$/m', $content, $match) === 1
            ? self::normalize($match[1])
            : null;
    }

    private function block(): string
    {
        $block = '';

        for ($index = 0; $index < 4; $index++) {
            $block .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $block;
    }
}
