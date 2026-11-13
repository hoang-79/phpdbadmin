<?php

namespace Tests\Feature\Console\Commands;

use App\Models\User;
use App\Services\Update\AppUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UpdateInstallation;
use Tests\TestCase;

/**
 * `phpdbadmin:update` (B-09): nach neuen Versionen sehen und — für den Scheduler — einen Austausch abschliessen, den
 * der Browser nicht mehr abgeschlossen hat.
 */
class UpdateCommandTest extends TestCase
{
    use RefreshDatabase;
    use UpdateInstallation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInstallation();
    }

    protected function tearDown(): void
    {
        $this->tearDownInstallation();

        parent::tearDown();
    }

    public function test_names_a_new_version_and_where_to_update(): void
    {
        $this->publishRelease('1.5.0');

        $this->artisan('phpdbadmin:update')
            ->expectsOutputToContain('Version 1.5.0 ist erschienen')
            ->assertSuccessful();
    }

    public function test_finish_completes_a_replacement_the_browser_left_behind(): void
    {
        $this->publishRelease('1.5.0');
        $admin = User::factory()->admin()->create();
        $updater = app(AppUpdater::class);
        $updater->download($admin);
        $updater->extract();
        $updater->replace('durchlass', $admin);
        config(['phpdbadmin.version' => '1.5.0']);

        $this->artisan('phpdbadmin:update', ['--finish' => true])
            ->expectsOutputToContain('auf 1.5.0 aktualisiert')
            ->assertSuccessful();

        $this->assertFalse($this->app->maintenanceMode()->active());
        $this->assertSame('finished', $updater->state()['step'] ?? null);
    }

    public function test_finish_without_a_waiting_update_does_nothing(): void
    {
        $this->artisan('phpdbadmin:update', ['--finish' => true])
            ->expectsOutputToContain('Keine Aktualisierung wartet')
            ->assertSuccessful();
    }
}
