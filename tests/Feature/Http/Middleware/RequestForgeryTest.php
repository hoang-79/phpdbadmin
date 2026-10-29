<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CSRF mit echter Middleware (T-15): Laravel schaltet die Prüfung in Tests sonst ab. Fremde Seiten können keine
 * schreibende Aktion im Namen eines angemeldeten Kontos auslösen.
 */
class RequestForgeryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    public function test_writing_request_from_a_foreign_site_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->withHeader('Sec-Fetch-Site', 'cross-site')
            ->put(route('account.locale'), ['locale' => 'en'])
            ->assertStatus(419);
    }

    public function test_writing_request_without_token_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->delete(route('account.sessions.destroy-others'))
            ->assertStatus(419);
    }

    public function test_same_origin_request_with_token_passes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['_token' => 'richtig'])
            ->put(route('account.locale'), ['locale' => 'en', '_token' => 'richtig'])
            ->assertRedirect();

        $this->assertSame('en', $user->fresh()?->locale);
    }
}
