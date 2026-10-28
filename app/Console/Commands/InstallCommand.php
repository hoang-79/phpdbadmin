<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\text;

/**
 * Legt das erste Konto an (A-06): genau ein Administratorkonto, nur solange es noch kein Konto gibt. Gibt einen
 * Einrichtungslink aus, der bis zur ersten Anmeldung gilt und direkt ins Onboarding mit Passkey führt. Ist das erste
 * Konto angelegt, aber noch nie angemeldet, stellt der Befehl einen neuen Link aus.
 */
#[Signature('phpdbadmin:install {--name= : Name des Kontos} {--email= : E-Mail-Adresse für die Anmeldung}')]
#[Description('Erstes Administratorkonto anlegen und den Link zur Ersteinrichtung ausgeben')]
class InstallCommand extends Command
{
    /** Gültigkeit des Einrichtungslinks in Minuten. */
    private const int LINK_MINUTES = 30;

    public function handle(AuditLog $audit): int
    {
        if (User::query()->exists()) {
            return $this->reissueLink();
        }

        $name = $this->option('name') ?: text('Name', required: true);
        $email = $this->option('email') ?: text('E-Mail-Adresse', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email],
            ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => trim((string) $name),
            'email' => mb_strtolower(trim((string) $email)),
            'role' => UserRole::Admin,
        ]);

        $audit->record('account.installed', $user, target: $user->email);

        $this->components->info('Administratorkonto für '.$user->email.' angelegt.');
        $this->printLink($user);

        return self::SUCCESS;
    }

    /**
     * Es gibt schon ein Konto: nur dann ein neuer Link, wenn die Ersteinrichtung nie abgeschlossen wurde.
     */
    private function reissueLink(): int
    {
        $admin = User::query()->where('role', UserRole::Admin->value)->oldest('id')->first();

        if (User::query()->count() === 1 && $admin !== null && $admin->last_login_at === null) {
            $this->components->warn('Die Ersteinrichtung für '.$admin->email.' ist noch nicht abgeschlossen — hier ein neuer Link.');
            $this->printLink($admin);

            return self::SUCCESS;
        }

        $this->components->error('Es gibt schon ein Konto. Weitere Konten entstehen per Einladung (phpdbadmin:invite).');

        return self::FAILURE;
    }

    private function printLink(User $user): void
    {
        $url = URL::temporarySignedRoute('setup.show', now()->addMinutes(self::LINK_MINUTES), ['user' => $user->id]);

        $this->newLine();
        $this->line('  <options=bold>Ersteinrichtung</> (gültig '.self::LINK_MINUTES.' Minuten, nur bis zur ersten Anmeldung):');
        $this->line('  '.$url);
        $this->newLine();
        $this->components->bulletList([
            'Der Link meldet Sie an und legt gleich Ihren Passkey an.',
            'Abgelaufen? Den Befehl noch einmal ausführen — oder unter '.route('login').' mit einem Code per Mail anmelden.',
        ]);
    }
}
