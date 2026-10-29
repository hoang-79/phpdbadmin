<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Auth\InvitationSender;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Lädt eine Person ein (A-06) — wie «Person einladen» in der Verwaltung, für die Konsole.
 */
#[Signature('phpdbadmin:invite {email : E-Mail-Adresse} {--admin : als Administrator statt als Mitglied}')]
#[Description('Person per Mail zu einem Konto einladen')]
class InviteCommand extends Command
{
    public function handle(InvitationSender $invitations): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->components->error('Das ist keine gültige E-Mail-Adresse.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->components->error('Für '.$email.' gibt es schon ein Konto.');

            return self::FAILURE;
        }

        $role = $this->option('admin') ? UserRole::Admin : UserRole::Member;
        [$invitation, $url] = $invitations->send($email, $role);

        $this->components->info('Einladung an '.$email.' gesendet ('.$role->label().', gültig bis '
            .$invitation->expires_at->timezone((string) config('app.timezone'))->format('d.m.Y H:i').').');
        $this->components->bulletList(['Link, falls die Mail nicht ankommt: '.$url]);

        return self::SUCCESS;
    }
}
