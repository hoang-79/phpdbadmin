<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Starter-Kit bereinigt (A-01, A-04, A-05, B-01): kein Passwort, keine Registrierung, kein Zwei-Faktor, keine
 * E-Mail-Bestätigung, kein Dashboard, kein Profil.
 */
class StarterKitRemovalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function removedAddresses(): array
    {
        return [
            'Registrierung' => ['get', '/register'],
            'Registrierung absenden' => ['post', '/register'],
            'Passwort vergessen' => ['get', '/forgot-password'],
            'Reset-Link anfordern' => ['post', '/forgot-password'],
            'Passwort zurücksetzen' => ['get', '/reset-password/token'],
            'Passwort zurücksetzen absenden' => ['post', '/reset-password'],
            'Passwort bestätigen' => ['get', '/user/confirm-password'],
            'Passwort bestätigen absenden' => ['post', '/user/confirm-password'],
            'Passwort ändern' => ['put', '/user/password'],
            'Zwei-Faktor einschalten' => ['post', '/user/two-factor-authentication'],
            'Zwei-Faktor-Abfrage' => ['get', '/two-factor-challenge'],
            'E-Mail bestätigen' => ['get', '/email/verify'],
            'Dashboard' => ['get', '/dashboard'],
            'Profil' => ['get', '/settings/profile'],
            'Profil ändern' => ['patch', '/settings/profile'],
            'Konto löschen' => ['delete', '/settings/profile'],
            'Passwort-Einstellungen' => ['get', '/settings/password'],
        ];
    }

    #[DataProvider('removedAddresses')]
    public function test_removed_address_is_not_found(string $method, string $uri): void
    {
        $this->actingAs(User::factory()->create())
            ->call(strtoupper($method), $uri)
            ->assertNotFound();
    }

    public function test_password_login_is_not_accepted(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertMethodNotAllowed();

        $this->assertGuest();
    }

    public function test_app_database_has_no_password_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'password'));
        $this->assertFalse(Schema::hasColumn('users', 'two_factor_secret'));
        $this->assertFalse(Schema::hasColumn('users', 'email_verified_at'));
        $this->assertFalse(Schema::hasTable('password_reset_tokens'));
    }

    public function test_home_is_connection_overview_after_sign_in(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('connections/Index'));
    }

    public function test_home_sends_guests_to_login(): void
    {
        $this->get(route('home'))->assertRedirect(route('login'));
    }

    /**
     * Favicons aus dem Logo-Kit statt von Laravel — mit der Prüfsumme der Datei in der Adresse, damit Browser ein
     * zwischengespeichertes altes Symbol nach jeder Änderung ersetzen.
     */
    public function test_icons_come_from_the_brand_kit_and_their_address_follows_the_file(): void
    {
        $html = (string) $this->get(route('login'))->assertOk()->getContent();

        foreach (['favicon.svg' => 'favicon.svg', 'favicon.ico' => 'png/favicon.ico', 'apple-touch-icon.png' => 'png/apple-touch-icon.png'] as $file => $brand) {
            $this->assertFileEquals(resource_path('brand/'.$brand), public_path($file), $file);
            $this->assertStringContainsString('href="/'.$file.'?v='.substr((string) md5_file(public_path($file)), 0, 8).'"', $html, $file);
        }
    }
}
