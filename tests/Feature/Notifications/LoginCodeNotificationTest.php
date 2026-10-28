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
}
