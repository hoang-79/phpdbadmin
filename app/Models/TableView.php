<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gemerkte Rasteransicht einer Tabelle für ein Konto (D-01): ausgeblendete Spalten und Breiten.
 *
 * @property int $id
 * @property int $user_id
 * @property string $connection_id
 * @property string|null $database
 * @property string $schema
 * @property string $table
 * @property string $object_hash
 * @property array{hidden?: list<string>, widths?: array<string, int>}|null $columns
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class TableView extends Model
{
    protected $fillable = ['user_id', 'connection_id', 'database', 'schema', 'table', 'object_hash', 'columns'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'columns' => 'array',
        ];
    }

    public static function hashFor(?string $database, string $schema, string $table): string
    {
        return hash('sha256', json_encode([$database, $schema, $table], JSON_THROW_ON_ERROR));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<DatabaseConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(DatabaseConnection::class, 'connection_id');
    }
}
