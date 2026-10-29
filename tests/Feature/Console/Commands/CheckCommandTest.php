<?php

namespace Tests\Feature\Console\Commands;

use App\Services\Setup\SystemCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Systemprüfung (B-05): Hindernisse lassen den Befehl scheitern, Betriebslücken sind Hinweise mit dem nächsten Schritt.
 */
class CheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_app_key_fails_with_the_command_to_fix_it(): void
    {
        config(['app.key' => '']);

        $this->artisan('phpdbadmin:check')
            ->expectsOutputToContain('APP_KEY fehlt')
            ->expectsOutputToContain('php artisan key:generate')
            ->assertFailed();
    }

    public function test_debug_in_production_fails(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['app.debug' => true]);

        $this->artisan('phpdbadmin:check')
            ->expectsOutputToContain('APP_DEBUG ist in Produktion eingeschaltet')
            ->assertFailed();
    }

    public function test_operational_gaps_are_hints_with_the_next_step(): void
    {
        config(['mail.default' => 'log', 'queue.default' => 'database', 'app.url' => 'http://dbadmin.example.com']);

        $this->artisan('phpdbadmin:check')
            ->expectsOutputToContain('Mail geht ins Log')
            ->expectsOutputToContain('Kein Worker der Warteschlange aktiv')
            ->expectsOutputToContain('Scheduler läuft nicht')
            ->expectsOutputToContain('Passkeys gehen nur über HTTPS');
    }

    public function test_worker_and_scheduler_count_once_they_report_in(): void
    {
        config(['queue.default' => 'database']);
        event(new JobPopping('database', 'default'));
        Cache::put(SystemCheck::SCHEDULE_HEARTBEAT, now()->getTimestamp());

        $this->artisan('phpdbadmin:check')
            ->expectsOutputToContain('Worker der Warteschlange aktiv')
            ->expectsOutputToContain('Scheduler läuft')
            ->doesntExpectOutputToContain('Kein Worker');
    }
}
