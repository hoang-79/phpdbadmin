<?php

namespace Tests\Feature\Models;

use App\Models\Transfer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Frist der Exportdateien (Datenmodell § 4): der tägliche Aufräumlauf löscht abgelaufene Aufträge samt Ordner.
 */
class TransferTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_prune_removes_expired_transfers_with_their_files_and_keeps_current_ones(): void
    {
        Storage::fake(Transfer::DISK);
        $expired = Transfer::factory()->expired()->create();
        $current = Transfer::factory()->create();
        Storage::disk(Transfer::DISK)->put($expired->directory().'/orders.csv', 'alt');
        Storage::disk(Transfer::DISK)->put($current->directory().'/orders.csv', 'neu');

        $this->artisan('model:prune', ['--model' => [Transfer::class]])->assertSuccessful();

        $this->assertModelMissing($expired);
        $this->assertModelExists($current);
        Storage::disk(Transfer::DISK)->assertMissing($expired->directory().'/orders.csv');
        Storage::disk(Transfer::DISK)->assertExists($current->directory().'/orders.csv');
    }
}
