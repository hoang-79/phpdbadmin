<?php

namespace Database\Factories;

use App\Enums\TransferFormat;
use App\Enums\TransferKind;
use App\Enums\TransferStatus;
use App\Models\DatabaseConnection;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transfer>
 */
class TransferFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'connection_id' => fn (array $attributes) => DatabaseConnection::factory()->create(['owner_id' => $attributes['user_id']])->id,
            'kind' => TransferKind::Export,
            'format' => TransferFormat::Csv,
            'status' => TransferStatus::Queued,
            'target' => 'shop.orders',
            'options' => ['scope' => 'table', 'schema' => 'shop', 'table' => 'orders'],
            'file_name' => 'orders.csv',
            'expires_at' => now()->addDay(),
        ];
    }

    /** Fertiger Export mit Datei auf der Disk (die Datei legt der Test selbst an). */
    public function finished(string $path): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransferStatus::Done,
            'progress' => 100,
            'file_path' => $path,
            'finished_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
