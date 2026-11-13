<?php

namespace App\Console\Commands;

use App\Services\Update\AppUpdater;
use App\Services\Update\UpdateChannel;
use App\Services\Update\UpdateFailed;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Aktualisieren (B-09) auf der Konsole: nachsehen, ob es eine neue Version gibt, und einen Austausch abschliessen, den
 * der Browser nicht mehr abgeschlossen hat (der Scheduler ruft `--finish` nach zwei Minuten auf). Austauschen selbst
 * geht nur über die Verwaltung — nur dort kennt phpdbadmin den Webordner des Web-Installers.
 */
#[Signature('phpdbadmin:update
    {--finish : einen Austausch abschliessen, der auf die Migrationen wartet}')]
#[Description('Neue Version suchen oder eine Aktualisierung abschliessen')]
class UpdateCommand extends Command
{
    public function handle(UpdateChannel $channel, AppUpdater $updater): int
    {
        return $this->option('finish') ? $this->finish($updater) : $this->check($channel, $updater);
    }

    private function check(UpdateChannel $channel, AppUpdater $updater): int
    {
        $status = $channel->check();

        $this->components->twoColumnDetail('Installiert', $status['current']);

        if ($status['error'] !== null) {
            $this->components->error($status['error']);

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Neueste Version', (string) $status['latest']);

        if (! $status['available']) {
            $this->components->info(__('phpdbadmin :version ist aktuell.', ['version' => $status['current']]));

            return self::SUCCESS;
        }

        foreach ([...$updater->blockers(), ...$updater->releaseBlockers($status)] as $blocker) {
            $this->components->warn($blocker['message']);
        }

        $this->components->info(__('Version :version ist erschienen. Aktualisieren: Verwaltung → Aktualisierung.', ['version' => $status['latest']]));

        return self::SUCCESS;
    }

    private function finish(AppUpdater $updater): int
    {
        if ($updater->waitingSince() === null) {
            $this->components->info(__('Keine Aktualisierung wartet auf ihren Abschluss.'));

            return self::SUCCESS;
        }

        try {
            $state = $updater->finish(null);
        } catch (UpdateFailed $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(__('phpdbadmin ist auf :version aktualisiert.', ['version' => $state['to']]));

        return self::SUCCESS;
    }
}
