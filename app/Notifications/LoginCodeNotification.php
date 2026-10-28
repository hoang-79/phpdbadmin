<?php

namespace App\Notifications;

use App\Models\LoginCode;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Der Code per Mail (A-02): sechs Ziffern, zehn Minuten gültig. In der Sprache des Kontos (User::preferredLocale).
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

        return (new MailMessage)
            // Der Code steht bewusst nicht im Betreff: Betreffzeilen erscheinen auf gesperrten Bildschirmen.
            ->subject($isLogin ? __('Ihr Anmeldecode für :app', ['app' => $appName]) : __('Ihr Bestätigungscode für :app', ['app' => $appName]))
            ->greeting(__('Guten Tag'))
            ->line($isLogin ? __('Ihr Code für die Anmeldung bei :app:', ['app' => $appName]) : __('Ihr Code zum Bestätigen:'))
            ->line('**'.$this->code.'**')
            ->line(__('Er ist :minutes Minuten gültig und nur einmal verwendbar.', ['minutes' => $this->minutes]))
            ->line(__('Haben Sie keinen Code angefordert, können Sie diese Nachricht ignorieren.'));
    }
}
