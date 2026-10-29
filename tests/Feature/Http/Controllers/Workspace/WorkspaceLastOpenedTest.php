<?php

namespace Tests\Feature\Http\Controllers\Workspace;

use App\Enums\ConnectionPermission;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * «Zuletzt geöffnet» (V-06): Öffnen des Arbeitsbildschirms merkt sich den Zeitpunkt je Konto.
 */
class WorkspaceLastOpenedTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/zuletzt-'.getmypid());
        @mkdir($this->folder, 0777, true);
        touch($this->folder.'/notizen.sqlite');
        config(['phpdbadmin.connections.sqlite_paths' => [$this->folder]]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    public function test_opening_the_workspace_records_last_opened_for_this_account(): void
    {
        $this->freezeSecond();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $connection = DatabaseConnection::factory()->for($owner, 'owner')->sqlite($this->folder.'/notizen.sqlite')->create(['shared' => true]);
        $connection->members()->attach($member, ['permission' => ConnectionPermission::Read->value]);

        $this->actingAs($owner)->get(route('workspace', $connection))->assertRedirect();

        $this->assertTrue(now()->equalTo($connection->membershipOf($owner)?->last_opened_at));
        $this->assertNull($connection->membershipOf($member)?->last_opened_at);
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('connections.0.lastOpenedAt', now()->toIso8601String()));
    }

    public function test_opening_again_moves_the_time_and_keeps_the_favorite(): void
    {
        $owner = User::factory()->create();
        $connection = DatabaseConnection::factory()->for($owner, 'owner')->sqlite($this->folder.'/notizen.sqlite')->create();
        $connection->markFavorite($owner, true);
        $this->travel(-1)->days(fn () => $connection->markOpenedBy($owner));

        $this->actingAs($owner)->get(route('workspace', $connection));

        $membership = $connection->membershipOf($owner);
        $this->assertTrue($membership?->favorite);
        $this->assertTrue($membership->last_opened_at?->isToday());
    }
}
