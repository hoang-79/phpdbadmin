<?php

namespace App\Services\Audit;

use App\Models\AuditEntry;
use App\Models\User;
use App\Support\Fingerprint;
use Illuminate\Http\Request;

/**
 * Schreibt Einträge ins Protokoll. IP-Adressen und fremde E-Mail-Adressen stehen nur als Prüfsumme darin (A-09).
 */
final class AuditLog
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $action, ?User $user = null, ?string $target = null, array $context = []): AuditEntry
    {
        return AuditEntry::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'target' => $target,
            'context' => $context === [] ? null : $context,
            'ip_hash' => Fingerprint::ofIp($this->request->ip()),
        ]);
    }
}
