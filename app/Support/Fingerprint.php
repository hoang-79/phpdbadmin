<?php

namespace App\Support;

/**
 * Prüfsumme für Werte, die nur verglichen, nie gelesen werden müssen (Anmeldecodes, Einladungstoken, Adressen,
 * IP-Adressen): HMAC-SHA256 mit dem APP_KEY. Ohne den Schlüssel lässt sich aus der Datenbank nichts zurückrechnen.
 */
final class Fingerprint
{
    public static function of(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    public static function ofEmail(string $email): string
    {
        return self::of('email:'.mb_strtolower(trim($email)));
    }

    public static function ofIp(?string $ip): ?string
    {
        return $ip === null ? null : self::of('ip:'.$ip);
    }
}
