<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Setup\FirstAccount;
use App\Services\Setup\SystemCheck;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

use function Laravel\Prompts\text;

/**
 * Einrichtung (A-06, B-05): bringt die App-Datenbank auf den neuesten Stand, zeigt die Systemprüfung und legt das erste
 * Konto an — genau ein Administratorkonto, nur solange es noch kein Konto gibt. Gibt einen Einrichtungslink aus, der
 * bis zur ersten Anmeldung gilt und direkt ins Onboarding mit Passkey führt. Ist das erste Konto angelegt, aber noch
 * nie angemeldet, stellt der Befehl einen neuen Link aus.
 */
#[Signature('phpdbadmin:install {--name= : Name des Kontos} {--email= : E-Mail-Adresse für die Anmeldung}')]
#[Description('Erstes Administratorkonto anlegen und den Link zur Ersteinrichtung ausgeben')]
class InstallCommand extends Command
{
    public function handle(FirstAccount $account, SystemCheck $check): int
    {
        if (! $this->prepare($check)) {
            return self::FAILURE;
        }

        if ($account->exists()) {
            return $this->reissueLink($account);
        }

        if (! $this->input->isInteractive() && (blank($this->option('name')) || blank($this->option('email')))) {
            $this->components->error('Name und Mail-Adresse des ersten Kontos fehlen: php artisan phpdbadmin:install --name="…" --email="…"');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: text('Name', required: true);
        $email = $this->option('email') ?: text('E-Mail-Adresse', required: true);

        try {
            $user = $account->create((string) $name, (string) $email);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $this->components->info('Administratorkonto für '.$user->email.' angelegt.');
        $this->printLink($account, $user);

        return self::SUCCESS;
    }

    /**
     * Es gibt schon ein Konto: nur dann ein neuer Link, wenn die Ersteinrichtung nie abgeschlossen wurde.
     */
    private function reissueLink(FirstAccount $account): int
    {
        $admin = $account->unfinishedAdmin();

        if ($admin !== null) {
            $this->components->warn('Die Ersteinrichtung für '.$admin->email.' ist noch nicht abgeschlossen — hier ein neuer Link.');
            $this->printLink($account, $admin);

            return self::SUCCESS;
        }

        $this->components->error('Es gibt schon ein Konto. Weitere Konten entstehen per Einladung (phpdbadmin:invite).');

        return self::FAILURE;
    }

    /**
     * Migrationen zuerst — die Prüfung zeigt dann den Stand danach. Ohne erreichbare App-Datenbank meldet das die
     * Prüfung.
     */
    private function prepare(SystemCheck $check): bool
    {
        // Nur ein fehlender Schlüssel wird erzeugt — ein vorhandener nie ersetzt, sonst wären gespeicherte Passwörter
        // der Verbindungen nicht mehr zu entschlüsseln.
        if (blank(config('app.key')) && is_file(base_path('.env'))) {
            $this->call('key:generate', ['--force' => true]);
        }

        try {
            DB::connection()->getPdo();
            $this->call('migrate', ['--force' => true]);
        } catch (Throwable) {
            // Die Prüfung erklärt, was fehlt.
        }

        $results = $check->run();
        CheckCommand::render($this, $results);

        if (! SystemCheck::passes($results)) {
            $this->components->error('Die Einrichtung bricht ab — beheben Sie zuerst die rot markierten Punkte (php artisan phpdbadmin:check).');

            return false;
        }

        return true;
    }

    private function printLink(FirstAccount $account, User $user): void
    {
        $url = $account->setupUrl($user);

        $this->newLine();
        $this->line('  <options=bold>Ersteinrichtung</> (gültig '.FirstAccount::LINK_MINUTES.' Minuten, nur bis zur ersten Anmeldung):');
        $this->line('  '.$url);
        $this->newLine();
        $this->components->bulletList([
            'Der Link meldet Sie an und legt gleich Ihren Passkey an.',
            'Abgelaufen? Den Befehl noch einmal ausführen — oder unter '.route('login').' mit einem Code per Mail anmelden.',
        ]);
    }
}
