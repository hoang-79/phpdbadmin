<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Einladung zu einem Konto (A-06). Der Link führt zur Annahme; danach meldet sich die Person per Code oder Passkey an.
 * Die Adresse hat noch kein Konto und damit keine gewählte Sprache: Die Mail geht in der Sprache der Anfrage hinaus
 * (über die Konsole in der Sprache der Installation).
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
        $subject = __('Einladung zu :app', ['app' => $appName]);

        return (new MailMessage)
            ->subject($subject)
            ->view(['mail.html.invitation', 'mail.text.invitation'], [
                'subject' => $subject,
                'intro' => $this->inviterName === null
                    ? __('Die Administration lädt Sie zu :app ein.', ['app' => $appName])
                    : __(':name lädt Sie zu :app ein.', ['name' => $this->inviterName, 'app' => $appName]),
                'url' => $this->url,
                'validity' => __('Der Link gilt bis :time Uhr.', ['time' => $this->expiresAt->timezone((string) config('app.timezone'))->format('d.m.Y, H:i')]),
            ]);
    }
}
