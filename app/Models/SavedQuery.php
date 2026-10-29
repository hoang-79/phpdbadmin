<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Gespeicherte Abfrage mit Namen (Q-04), je Konto und Verbindung.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $connection_id
 * @property string $name
 * @property string $sql
 * @property bool $shared
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SavedQuery extends Model
{
    protected $fillable = ['user_id', 'connection_id', 'name', 'sql', 'shared'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['shared' => 'boolean'];
    }
}
