<?php

namespace Tests\Feature\Notifications;

use App\Models\LoginCode;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Der Code per Mail (A-02) geht in der Sprache des Kontos hinaus (N-06), nicht in der Sprache der Installation.
 */
class LoginCodeNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_goes_out_in_the_language_of_the_account(): void
    {
        config(['app.name' => 'phpdbadmin']);
        $this->app->setLocale('de');
        $user = User::factory()->create(['locale' => 'en']);

        $user->notify(new LoginCodeNotification('123456', LoginCode::PURPOSE_LOGIN, 10));

        $message = Mail::getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $message);
        $this->assertSame('Your sign-in code for phpdbadmin', $message->getSubject());
        $this->assertStringContainsString('It is valid for 10 minutes and can only be used once.', (string) $message->getTextBody());
    }

    public function test_mail_shows_one_box_per_digit_and_the_whole_code_for_copying(): void
    {
        $user = User::factory()->create(['locale' => 'de']);

        $user->notify(new LoginCodeNotification('482915', LoginCode::PURPOSE_LOGIN, 10));

        $message = Mail::getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $message);
        $html = (string) $message->getHtmlBody();

        preg_match_all('/<td[^>]*>(\d)<\/td>/', $html, $boxes);
        $this->assertSame(['4', '8', '2', '9', '1', '5'], $boxes[1]);
        $this->assertMatchesRegularExpression('/user-select:all;">482915<\/span>/', $html, 'der Code am Stück zum Markieren');
        $this->assertStringContainsString('482915', (string) $message->getTextBody());
    }

    public function test_logo_is_embedded_and_the_preview_line_keeps_the_code_hidden(): void
    {
        $user = User::factory()->create(['locale' => 'de']);

        $user->notify(new LoginCodeNotification('482915', LoginCode::PURPOSE_LOGIN, 10));

        $message = Mail::getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $message);
        $html = (string) $message->getHtmlBody();

        $inline = array_values(array_filter($message->getAttachments(), fn ($part): bool => $part->getDisposition() === 'inline'));
        $this->assertCount(1, $inline, 'das Logo als eingebettetes Bild');
        $this->assertStringContainsString('src="cid:'.$inline[0]->getContentId().'"', $html);

        // Die Vorschauzeile erscheint auf gesperrten Bildschirmen — wie der Betreff nie mit Code.
        $this->assertSame(1, preg_match('/<div style="display:none[^>]*>(.*?)<\/div>/s', $html, $preview));
        $this->assertStringNotContainsString('482915', $preview[1]);
        $this->assertStringContainsString('Gültig 10 Minuten', $preview[1]);
    }
}
