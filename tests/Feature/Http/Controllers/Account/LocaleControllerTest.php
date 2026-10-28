<?php

namespace Tests\Feature\Http\Controllers\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Sprache (N-06): Wahl im Konto, sonst der Browser, sonst Deutsch.
 */
class LocaleControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_gets_the_language_of_the_browser(): void
    {
        $this->get(route('login'), ['Accept-Language' => 'en-US,en;q=0.9'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('locale', 'en')
                ->where('translations.heute :time', 'today :time'));
    }

    public function test_guest_with_other_language_gets_german(): void
    {
        $this->get(route('login'), ['Accept-Language' => 'fr-CH'])
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'de')->where('translations', []));
    }

    public function test_choice_in_account_wins_over_the_browser(): void
    {
        $user = User::factory()->create(['locale' => 'de']);

        $this->actingAs($user)->from(route('account'))->put(route('account.locale'), ['locale' => 'en'])
            ->assertRedirect(route('account'));

        $this->assertSame('en', $user->fresh()?->locale);
        $this->actingAs($user->fresh())->get(route('account'), ['Accept-Language' => 'de-CH'])
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }

    public function test_unknown_language_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('account.locale'), ['locale' => 'fr'])->assertSessionHasErrors('locale');
        $this->assertSame('de', $user->fresh()?->locale);
    }

    public function test_guest_cannot_choose(): void
    {
        $this->put(route('account.locale'), ['locale' => 'en'])->assertRedirect(route('login'));
    }
}
