<?php

namespace App\Console\Commands;

use App\Services\Setup\CheckLevel;
use App\Services\Setup\CheckResult;
use App\Services\Setup\SystemCheck;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Systemprüfung (B-05): zeigt, was für Installation und Betrieb fehlt, mit dem nächsten Schritt. Scheitert, wenn
 * phpdbadmin so nicht laufen kann.
 */
#[Signature('phpdbadmin:check')]
#[Description('Prüfen, ob phpdbadmin hier laufen kann und was für den Betrieb fehlt')]
class CheckCommand extends Command
{
    public function handle(SystemCheck $check): int
    {
        $results = $check->run();
        self::render($this, $results);

        if (! SystemCheck::passes($results)) {
            $this->components->error('phpdbadmin kann so nicht laufen — beheben Sie die rot markierten Punkte.');

            return self::FAILURE;
        }

        $this->components->info('Bereit.');

        return self::SUCCESS;
    }

    /**
     * @param  list<CheckResult>  $results
     */
    public static function render(Command $command, array $results): void
    {
        $command->newLine();

        foreach ($results as $result) {
            $status = match ($result->level) {
                CheckLevel::Ok => '<fg=green;options=bold>OK</>',
                CheckLevel::Warn => '<fg=yellow;options=bold>HINWEIS</>',
                CheckLevel::Fail => '<fg=red;options=bold>FEHLT</>',
            };

            $command->components->twoColumnDetail($result->title, $status);

            if ($result->hint !== null) {
                $command->line('  <fg=gray>→ '.$result->hint.'</>');
            }
        }

        $command->newLine();
    }
}
