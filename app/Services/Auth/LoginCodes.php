<?php

namespace App\Services\Auth;

use App\Models\LoginCode;
use App\Support\Fingerprint;

/**
 * Codes per Mail ausstellen und einlösen (A-02): sechs Ziffern, zehn Minuten, einmalig, nach fünf Fehlversuchen
 * gesperrt. Ein neuer Code macht ältere offene Codes derselben Adresse und desselben Zwecks ungültig.
 */
final class LoginCodes
{
    /**
     * @return string Code im Klartext (nur für die Mail)
     */
    public function issue(string $email, string $purpose, ?string $ip): string
    {
        $emailHash = Fingerprint::ofEmail($email);
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        LoginCode::query()
            ->where('email_hash', $emailHash)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        LoginCode::query()->create([
            'purpose' => $purpose,
            'email_hash' => $emailHash,
            'code_hash' => $this->hashOf($emailHash, $code),
            'expires_at' => now()->addMinutes((int) config('phpdbadmin.login.code_minutes')),
            'ip_hash' => Fingerprint::ofIp($ip),
        ]);

        return $code;
    }

    /**
     * Löst einen Code ein. Zwei gleichzeitige Anfragen mit demselben Code: nur eine gewinnt (bedingtes Update).
     */
    public function consume(string $email, string $purpose, string $code): bool
    {
        $emailHash = Fingerprint::ofEmail($email);

        $challenge = LoginCode::query()
            ->where('email_hash', $emailHash)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if ($challenge === null || ! $challenge->isOpen()) {
            return false;
        }

        if (! hash_equals($challenge->code_hash, $this->hashOf($emailHash, $code))) {
            $challenge->increment('attempts');

            if ($challenge->attempts >= (int) config('phpdbadmin.login.code_attempts')) {
                $challenge->forceFill(['consumed_at' => now()])->save();
            }

            return false;
        }

        return LoginCode::query()
            ->whereKey($challenge->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]) === 1;
    }

    private function hashOf(string $emailHash, string $code): string
    {
        return Fingerprint::of('code:'.$emailHash.':'.$code);
    }
}
