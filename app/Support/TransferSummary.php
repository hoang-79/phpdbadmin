<?php

namespace App\Support;

use App\Enums\TransferKind;
use App\Enums\TransferStatus;
use App\Models\Transfer;

/**
 * Ein Auftrag für die Oberfläche (Wireframe 11): Stand, Fortschritt, Datei, Fehler — Texte erst hier in der Sprache
 * der Anfrage, Pfade auf dem Server nie.
 *
 * @phpstan-import-type TransferMessage from Transfer
 *
 * @phpstan-type MessageView array{text: string, detail: string|null}
 */
final class TransferSummary
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Transfer $transfer): array
    {
        $routeParameters = ['connection' => $transfer->connection_id, 'transfer' => $transfer->id];

        return [
            'id' => $transfer->id,
            'kind' => $transfer->kind->value,
            'kindLabel' => $transfer->kind->label(),
            'format' => $transfer->format->value,
            'status' => $transfer->status->value,
            'statusLabel' => $transfer->status->label(),
            'active' => $transfer->status->isActive(),
            'dryRun' => $transfer->dry_run,
            'inBackground' => $transfer->in_background,
            'target' => $transfer->target,
            'fileName' => $transfer->file_name,
            'fileSize' => $transfer->file_size,
            'progress' => $transfer->progress,
            'rows' => $transfer->rows,
            'totalRows' => $transfer->total_rows,
            'createdAt' => $transfer->created_at?->toIso8601String(),
            'finishedAt' => $transfer->finished_at?->toIso8601String(),
            'expiresAt' => $transfer->expires_at->toIso8601String(),
            'error' => self::message($transfer->error),
            'downloadUrl' => $transfer->isDownloadable() ? route('workspace.transfers.download', $routeParameters) : null,
            'showUrl' => $transfer->kind === TransferKind::Import ? route('workspace.transfers.edit', $routeParameters) : null,
            'cancelUrl' => $transfer->status->isActive() ? route('workspace.transfers.cancel', $routeParameters) : null,
            'waitingForWorker' => $transfer->status === TransferStatus::Queued && $transfer->in_background
                && $transfer->updated_at !== null && $transfer->updated_at->lt(now()->subMinute()),
        ];
    }

    /**
     * Ergebnis eines Imports oder Probelaufs mit übersetzten Fehlern.
     *
     * @return array<string, mixed>|null
     */
    public static function result(Transfer $transfer): ?array
    {
        $result = $transfer->result;

        if ($result === null) {
            return null;
        }

        $errors = [];
        foreach ((array) ($result['errors'] ?? []) as $error) {
            if (is_array($error) && is_array($error['message'] ?? null)) {
                /** @var TransferMessage $message */
                $message = $error['message'];
                $errors[] = ['line' => (int) ($error['line'] ?? 0), ...(self::message($message) ?? ['text' => '', 'detail' => null])];
            }
        }

        return [...$result, 'errors' => $errors];
    }

    /**
     * @param  TransferMessage|null  $message
     * @return MessageView|null
     */
    public static function message(?array $message): ?array
    {
        if ($message === null) {
            return null;
        }

        return [
            'text' => self::translate($message['text'], $message['replace'] ?? []),
            'detail' => $message['detail'] ?? null,
        ];
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private static function translate(string $text, array $replace): string
    {
        $line = __($text, $replace);

        return is_string($line) ? $line : $text;
    }
}
