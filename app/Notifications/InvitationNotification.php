<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Einladung zu einem Konto (A-06). Der Link führt zur Annahme; danach meldet sich die Person per Code oder Passkey an.
 */
class InvitationNotification extends Notification
{
    public function __construct(
        public readonly string $url,
        public readonly CarbonInterface $expiresAt,
        public readonly ?string $inviterName = null,
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
        $appName = (string) config('app.name');

        return (new MailMessage)
            ->subject('Einladung zu '.$appName)
            ->greeting('Guten Tag')
            ->line(($this->inviterName ?? 'Die Administration').' lädt Sie zu '.$appName.' ein.')
            ->action('Einladung annehmen', $this->url)
            ->line('Der Link gilt bis '.$this->expiresAt->timezone((string) config('app.timezone'))->format('d.m.Y, H:i').' Uhr.')
            ->line('Ein Passwort gibt es nicht: Sie melden sich mit einem Code per Mail oder mit einem Passkey an.');
    }
}
