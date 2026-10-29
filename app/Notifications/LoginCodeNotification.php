<?php

namespace App\Notifications;

use App\Models\LoginCode;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Der Code per Mail (A-02): sechs Ziffern, zehn Minuten gültig. In der Sprache des Kontos (User::preferredLocale).
 * Eigene Vorlage (resources/views/mail): ein Kästchen je Ziffer, darunter der Code am Stück zum Markieren.
 */
class LoginCodeNotification extends Notification
{
    public function __construct(
        public readonly string $code,
        public readonly string $purpose,
        public readonly int $minutes,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isLogin = $this->purpose === LoginCode::PURPOSE_LOGIN;
        $appName = (string) config('app.name');
        // Der Code steht bewusst nicht im Betreff: Betreffzeilen erscheinen auf gesperrten Bildschirmen.
        $subject = $isLogin ? __('Ihr Anmeldecode für :app', ['app' => $appName]) : __('Ihr Bestätigungscode für :app', ['app' => $appName]);

        return (new MailMessage)
            ->subject($subject)
            ->view(['mail.html.login-code', 'mail.text.login-code'], [
                'subject' => $subject,
                'intro' => $isLogin ? __('Ihr Code für die Anmeldung bei :app:', ['app' => $appName]) : __('Ihr Code zum Bestätigen:'),
                'code' => $this->code,
                'minutes' => $this->minutes,
            ]);
    }
}
