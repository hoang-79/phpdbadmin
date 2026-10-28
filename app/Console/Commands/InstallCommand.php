<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\text;

/**
 * Legt das erste Konto an (A-06): genau ein Administratorkonto, nur solange es noch kein Konto gibt.
 */
#[Signature('phpdbadmin:install {--name= : Name des Kontos} {--email= : E-Mail-Adresse für die Anmeldung}')]
#[Description('Erstes Administratorkonto anlegen')]
class InstallCommand extends Command
{
    public function handle(AuditLog $audit): int
    {
        if (User::query()->exists()) {
            $this->components->error('Es gibt schon ein Konto. Weitere Konten entstehen per Einladung (phpdbadmin:invite).');

            return self::FAILURE;
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
        $this->components->bulletList([
            'Anmelden: '.route('login').' — mit einem Code per Mail',
            'Danach im Konto einen Passkey hinzufügen',
        ]);

        return self::SUCCESS;
    }
}
