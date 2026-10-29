<?php

namespace App\Services\Release;

use RuntimeException;

/**
 * Signatur der Releases (Ed25519): Der Web-Installer kennt den öffentlichen Schlüssel und installiert nur ein Archiv,
 * dessen Version, Dateiname, Grösse und Prüfsumme der Hersteller unterschrieben hat. Der Geheimschlüssel bleibt auf
 * dem Rechner, der die Releases baut — nie im Repository und nie bei GitHub.
 */
final class ReleaseSignature
{
    public const string FORMAT = 'phpdbadmin-release:1';

    /**
     * Unterschriebener Text — der Installer setzt ihn aus `latest.json` genau so zusammen.
     */
    public static function message(string $version, string $file, int $size, string $sha256): string
    {
        return implode("\n", [self::FORMAT, $version, $file, (string) $size, $sha256]);
    }

    /**
     * @param  non-empty-string  $secretKey
     * @return string Signatur als Base64
     */
    public static function sign(string $message, string $secretKey): string
    {
        return base64_encode(sodium_crypto_sign_detached($message, $secretKey));
    }

    public static function verify(string $message, string $signature, string $publicKey): bool
    {
        $signatureBytes = base64_decode($signature, true);
        $publicKeyBytes = base64_decode($publicKey, true);

        if ($signatureBytes === false || $publicKeyBytes === false
            || strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($publicKeyBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signatureBytes, $message, $publicKeyBytes);
    }

    /**
     * Geheimschlüssel aus einem Pfad (Datei mit Base64 oder den rohen 64 Bytes) oder direkt als Base64.
     *
     * @return non-empty-string
     *
     * @throws RuntimeException
     */
    public static function loadSecretKey(string $value): string
    {
        $value = trim($value);
        $content = is_file($value) ? (string) file_get_contents($value) : $value;

        if (strlen($content) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return $content;
        }

        $decoded = base64_decode(trim($content), true);

        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Der Schlüssel für die Signatur ist kein Ed25519-Geheimschlüssel (64 Bytes, roh oder Base64).');
        }

        return $decoded;
    }

    /**
     * @param  non-empty-string  $secretKey
     * @return string öffentlicher Schlüssel als Base64
     */
    public static function publicKeyFor(string $secretKey): string
    {
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($secretKey));
    }

    /**
     * Neues Schlüsselpaar: Geheimschlüssel als Base64 in eine Datei, die nur der Besitzer lesen darf.
     *
     * @return string öffentlicher Schlüssel als Base64
     *
     * @throws RuntimeException
     */
    public static function generate(string $path): string
    {
        if (file_exists($path)) {
            throw new RuntimeException(strtr('Unter :path liegt schon eine Datei — ein vorhandener Schlüssel wird nie überschrieben.', [':path' => $path]));
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException(strtr('Der Ordner :path lässt sich nicht anlegen.', [':path' => $directory]));
        }

        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);

        // Erst leer mit engen Rechten anlegen, dann schreiben — der Schlüssel ist nie für andere lesbar.
        touch($path);
        chmod($path, 0600);
        file_put_contents($path, base64_encode($secret).PHP_EOL);

        return self::publicKeyFor($secret);
    }
}
