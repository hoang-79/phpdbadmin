<?php

namespace App\Models;

use App\Enums\TransferFormat;
use App\Enums\TransferKind;
use App\Enums\TransferStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TransferFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Ein Export, Import, eine Sicherung oder Wiederherstellung (E-01 bis E-03, B-07). Dateien liegen auf der Disk
 * «local» unter `transfers/<Kennung>/` und verschwinden mit dem Datensatz nach der Frist (Vorgabe 24 Stunden,
 * täglicher `model:prune`). Herunterladen und Ansehen nur für das eigene Konto.
 *
 * Texte im Ergebnis und im Fehler sind deutsche Schlüssel mit Platzhaltern (TransferMessage) — übersetzt wird erst
 * bei der Ausgabe, damit ein Auftrag im Hintergrund nicht in der Sprache des Workers spricht.
 *
 * @phpstan-type TransferMessage array{text: string, replace?: array<string, string|int>, detail?: string|null}
 *
 * @property string $id
 * @property int $user_id
 * @property string $connection_id
 * @property TransferKind $kind
 * @property TransferFormat $format
 * @property TransferStatus $status
 * @property bool $dry_run
 * @property bool $in_background
 * @property string|null $target
 * @property array<string, mixed>|null $options
 * @property int $progress
 * @property int $rows
 * @property int|null $total_rows
 * @property string|null $file_name
 * @property string|null $file_path
 * @property int|null $file_size
 * @property array<string, mixed>|null $result
 * @property TransferMessage|null $error
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read DatabaseConnection $databaseConnection
 */
class Transfer extends Model
{
    /** @use HasFactory<TransferFactory> */
    use HasFactory, HasUlids, Prunable;

    public const string DISK = 'local';

    public const string FOLDER = 'transfers';

    protected $fillable = [
        'kind', 'format', 'status', 'dry_run', 'in_background', 'target', 'options', 'progress', 'rows', 'total_rows',
        'file_name', 'file_path', 'file_size', 'result', 'error', 'started_at', 'finished_at', 'expires_at',
    ];

    protected $attributes = [
        'progress' => 0,
        'rows' => 0,
        'dry_run' => false,
        'in_background' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TransferKind::class,
            'format' => TransferFormat::class,
            'status' => TransferStatus::class,
            'dry_run' => 'boolean',
            'in_background' => 'boolean',
            'options' => 'array',
            'progress' => 'integer',
            'rows' => 'integer',
            'total_rows' => 'integer',
            'file_size' => 'integer',
            'result' => 'array',
            'error' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Die Zielverbindung — nicht «connection», das ist bei Eloquent der Name der Datenbankverbindung des Modells.
     *
     * @return BelongsTo<DatabaseConnection, $this>
     */
    public function databaseConnection(): BelongsTo
    {
        return $this->belongsTo(DatabaseConnection::class, 'connection_id');
    }

    /**
     * Abgelaufene Aufträge — mit Datei (pruning).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }

    /** Vor dem Löschen des Datensatzes: der ganze Ordner des Auftrags. */
    protected function pruning(): void
    {
        $this->deleteFiles();
    }

    public function directory(): string
    {
        return self::FOLDER.'/'.$this->id;
    }

    public function deleteFiles(): void
    {
        Storage::disk(self::DISK)->deleteDirectory($this->directory());
    }

    /** Liegt die Datei zum Herunterladen bereit? */
    public function isDownloadable(): bool
    {
        return $this->kind->producesFile()
            && $this->status === TransferStatus::Done
            && $this->file_path !== null
            && $this->expires_at->isFuture()
            && Storage::disk(self::DISK)->exists($this->file_path);
    }

    /**
     * Datenbank, mit der der Auftrag die Verbindung öffnet: die gewählte (PostgreSQL mit «alle Datenbanken») bzw. bei
     * MySQL/MariaDB ohne Datenbankname das Ziel-Schema — dann gelten Namen ohne Schema in einer SQL-Datei dort.
     */
    public function openDatabase(): ?string
    {
        return $this->option('open_database') ?? $this->option('database');
    }

    /**
     * Einstellung aus `options` als Text.
     */
    public function option(string $key, ?string $default = null): ?string
    {
        $value = $this->options[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    public function flag(string $key, bool $default = false): bool
    {
        return (bool) ($this->options[$key] ?? $default);
    }
}
