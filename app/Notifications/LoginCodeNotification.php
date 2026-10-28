<?php

namespace App\Notifications;

use App\Models\LoginCode;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Der Code per Mail (A-02): sechs Ziffern, zehn Minuten gültig.
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

        return (new MailMessage)
            // Der Code steht bewusst nicht im Betreff: Betreffzeilen erscheinen auf gesperrten Bildschirmen.
            ->subject(($isLogin ? 'Ihr Anmeldecode für ' : 'Ihr Bestätigungscode für ').config('app.name'))
            ->greeting('Guten Tag')
            ->line($isLogin ? 'Ihr Code für die Anmeldung bei '.config('app.name').':' : 'Ihr Code zum Bestätigen:')
            ->line('**'.$this->code.'**')
            ->line('Er ist '.$this->minutes.' Minuten gültig und nur einmal verwendbar.')
            ->line('Haben Sie keinen Code angefordert, können Sie diese Nachricht ignorieren.');
    }
}
