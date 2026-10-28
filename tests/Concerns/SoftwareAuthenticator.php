<?php

declare(strict_types=1);

namespace Tests\Concerns;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Ein Authenticator in Software für Tests der Passkeys (A-03; übernommen aus Basalto): ein EC-Schlüssel P-256, Attestation «none», echte
 * Signaturen über Authenticator-Daten und Client-Daten — so läuft die WebAuthn-Prüfung des Servers vollständig, ohne
 * Browser. Antworten haben die Form, die `@laravel/passkeys` im Browser schickt (base64url).
 */
final class SoftwareAuthenticator
{
    public readonly string $credentialId;

    private readonly OpenSSLAsymmetricKey $key;

    private int $counter = 0;

    private string $userHandle = '';

    public function __construct(private readonly string $origin, private readonly string $rpId)
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new RuntimeException('Kein EC-Schlüssel');
        }
        $this->key = $key;
        $this->credentialId = random_bytes(16);
    }

    /**
     * Antwort auf «Passkey anlegen» zu den Optionen des Servers.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function register(array $options): array
    {
        $user = is_array($options['user'] ?? null) ? $options['user'] : [];
        $this->userHandle = self::decode((string) ($user['id'] ?? ''));
        $client = $this->clientData('webauthn.create', (string) $options['challenge']);
        $details = openssl_pkey_get_details($this->key);
        $ec = is_array($details) && is_array($details['ec'] ?? null) ? $details['ec'] : [];
        $cose = self::map([[self::int(1), self::int(2)], [self::int(3), self::int(-7)], [self::int(-1), self::int(1)],
            [self::int(-2), self::bytes(str_pad((string) $ec['x'], 32, "\0", STR_PAD_LEFT))], [self::int(-3), self::bytes(str_pad((string) $ec['y'], 32, "\0", STR_PAD_LEFT))]]);
        // Flags: Benutzer anwesend (UP), bestätigt (UV), Daten der Anmeldeinformation (AT)
        $authData = hash('sha256', $this->rpId, true).chr(0x45).pack('N', 0).str_repeat("\0", 16).pack('n', strlen($this->credentialId)).$this->credentialId.$cose;
        $attestation = self::map([[self::text('fmt'), self::text('none')], [self::text('attStmt'), self::map([])], [self::text('authData'), self::bytes($authData)]]);

        return ['id' => self::encode($this->credentialId), 'rawId' => self::encode($this->credentialId), 'type' => 'public-key', 'clientExtensionResults' => [],
            'response' => ['clientDataJSON' => self::encode($client), 'attestationObject' => self::encode($attestation), 'transports' => ['internal']]];
    }

    /**
     * Antwort auf «Mit Passkey anmelden» — signiert mit dem Zähler, der jedes Mal steigt.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function assert(array $options): array
    {
        $client = $this->clientData('webauthn.get', (string) $options['challenge']);
        $this->counter++;
        $authData = hash('sha256', $this->rpId, true).chr(0x05).pack('N', $this->counter);
        openssl_sign($authData.hash('sha256', $client, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return ['id' => self::encode($this->credentialId), 'rawId' => self::encode($this->credentialId), 'type' => 'public-key', 'clientExtensionResults' => [],
            'response' => ['clientDataJSON' => self::encode($client), 'authenticatorData' => self::encode($authData), 'signature' => self::encode((string) $signature),
                'userHandle' => self::encode($this->userHandle)]];
    }

    private function clientData(string $type, string $challenge): string
    {
        return (string) json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $this->origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
    }

    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

    // CBOR (RFC 8949) — nur, was ein Authenticator braucht: Zahlen, Bytes, Text, Maps

    private static function head(int $major, int $length): string
    {
        return match (true) {
            $length < 24 => chr($major << 5 | $length),
            $length < 256 => chr($major << 5 | 24).chr($length),
            $length < 65536 => chr($major << 5 | 25).pack('n', $length),
            default => chr($major << 5 | 26).pack('N', $length),
        };
    }

    private static function int(int $value): string
    {
        return $value >= 0 ? self::head(0, $value) : self::head(1, -1 - $value);
    }

    private static function bytes(string $value): string
    {
        return self::head(2, strlen($value)).$value;
    }

    private static function text(string $value): string
    {
        return self::head(3, strlen($value)).$value;
    }

    /** @param  list<array{0: string, 1: string}>  $pairs  bereits kodierte Schlüssel und Werte */
    private static function map(array $pairs): string
    {
        return self::head(5, count($pairs)).implode('', array_map(static fn (array $pair): string => $pair[0].$pair[1], $pairs));
    }
}
