<?php

namespace Tests\Feature\Http\Controllers\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Aktive Sitzungen (A-08): eigene sehen, fremde beenden — eine beendete Sitzung ist abgemeldet.
 */
class SessionControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
        $this->user = User::factory()->create();
    }

    public function test_lists_only_own_sessions_without_their_ids(): void
    {
        $phone = $this->sessionOf($this->user, 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1');
        $this->sessionOf(User::factory()->create());

        $response = $this->actingAs($this->user)->get(route('account'));

        $response->assertInertia(fn (Assert $page) => $page
            ->has('sessions', 1)
            ->where('sessions.0.browser', 'Safari')
            ->where('sessions.0.os', 'iPhone')
            ->where('sessions.0.current', false));
        $this->assertStringNotContainsString($phone, (string) $response->getContent());
    }

    public function test_an_ended_session_is_signed_out(): void
    {
        $laptop = $this->sessionOf($this->user);
        $this->withCookie(config('session.cookie'), $laptop)->get(route('account'))->assertOk();
        $this->asNextProcess();

        $key = $this->actingAs($this->user)->get(route('account'))->inertiaProps('sessions.0.key');
        $this->actingAs($this->user)->delete(route('account.sessions.destroy', $key))->assertRedirect();
        $this->asNextProcess();

        $this->assertDatabaseMissing('sessions', ['id' => $laptop]);
        $this->withCookie(config('session.cookie'), $laptop)->get(route('account'))->assertRedirect(route('login'));
        $this->assertDatabaseHas('audit_log', ['action' => 'account.session_ended', 'user_id' => $this->user->id]);
    }

    public function test_ending_all_others_keeps_the_current_session_and_spares_other_accounts(): void
    {
        $others = [$this->sessionOf($this->user), $this->sessionOf($this->user)];
        $foreign = $this->sessionOf(User::factory()->create());

        $this->actingAs($this->user)->delete(route('account.sessions.destroy-others'))->assertRedirect();

        $remaining = DB::table('sessions')->where('user_id', $this->user->id)->pluck('id')->all();
        $this->assertCount(1, $remaining, 'Nur die aktuelle Sitzung bleibt.');
        $this->assertEmpty(array_intersect($others, $remaining));
        $this->assertDatabaseHas('sessions', ['id' => $foreign]);
        $this->assertDatabaseHas('audit_log', ['action' => 'account.sessions_ended', 'user_id' => $this->user->id]);
    }

    public function test_a_session_of_another_account_cannot_be_ended(): void
    {
        $foreign = $this->sessionOf(User::factory()->create());
        $key = substr(hash_hmac('sha256', $foreign, (string) config('app.key')), 0, 32);

        $this->actingAs($this->user)->delete(route('account.sessions.destroy', $key))->assertNotFound();

        $this->assertDatabaseHas('sessions', ['id' => $foreign]);
    }

    public function test_without_database_sessions_there_is_no_list(): void
    {
        config(['session.driver' => 'array']);

        $this->actingAs($this->user)->get(route('account'))
            ->assertInertia(fn (Assert $page) => $page->where('sessions', null));
        $this->actingAs($this->user)->delete(route('account.sessions.destroy-others'))->assertNotFound();
    }

    /**
     * Jede echte Anfrage beginnt in einem frischen Prozess. Im Test bleiben Wächter, Sitzungsobjekt (samt geladener
     * Werte) und Cookies sonst von der vorigen Anfrage übrig — und ein abgemeldetes Gerät wirkte noch angemeldet.
     */
    private function asNextProcess(): void
    {
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->defaultCookies = [];
    }

    /** Eine Sitzung wie von einem anderen Gerät: angemeldet, gerade aktiv. */
    private function sessionOf(User $user, string $agent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/141.0 Safari/537.36'): string
    {
        $id = Str::random(40);

        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '192.0.2.10',
            'user_agent' => $agent,
            'payload' => base64_encode((string) json_encode(['_token' => Str::random(40), Auth::guard('web')->getName() => $user->id])),
            'last_activity' => now()->getTimestamp(),
        ]);

        return $id;
    }
}
