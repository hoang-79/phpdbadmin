<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Ein Administratorkonto für die lokale Entwicklung; Anmeldung per Code (Mail in Herd).
     */
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Test Admin',
            'email' => 'admin@phpdbadmin.test',
        ]);
    }
}
