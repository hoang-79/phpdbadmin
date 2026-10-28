<?php

namespace Database\Factories;

use App\Enums\ConnectionEnvironment;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatabaseConnection>
 */
class DatabaseConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'name' => fake()->unique()->word(),
            'group' => null,
            'driver' => DatabaseDriver::MariaDb,
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => fake()->word(),
            'username' => 'app',
            'password' => 'Geheim-'.fake()->numerify('####'),
            'store_password' => true,
            'options' => ['sslmode' => 'disable'],
            'environment' => ConnectionEnvironment::Local,
            'read_only' => false,
        ];
    }

    public function sqlite(string $path): static
    {
        return $this->state(fn (array $attributes) => [
            'driver' => DatabaseDriver::Sqlite,
            'host' => null,
            'port' => null,
            'database' => null,
            'username' => null,
            'password' => null,
            'options' => null,
            'sqlite_path' => $path,
        ]);
    }

    public function production(): static
    {
        return $this->state(fn (array $attributes) => [
            'environment' => ConnectionEnvironment::Production,
            'read_only' => true,
        ]);
    }
}
