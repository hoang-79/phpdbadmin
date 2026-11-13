<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminPage;
use App\Http\Controllers\Controller;
use App\Services\Update\AppUpdater;
use App\Services\Update\UpdateChannel;
use App\Services\Update\UpdateFailed;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Verwaltung · Aktualisierung (B-09): installierte und neueste Version, was das Aktualisieren hier verhindert, und die
 * vier Schritte. Der Stand der Versionen kommt nachgeladen (die Seite wartet nicht auf GitHub).
 *
 * `POST admin/update/finish` führt der Code der neuen Version aus — die Adresse und die Antwort bleiben in jeder
 * Version gleich (AppUpdater).
 */
class UpdateController extends Controller
{
    use AdminPage;

    public function __construct(
        private readonly UpdateChannel $channel,
        private readonly AppUpdater $updater,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/Update', [
            'tabs' => $this->tabs(),
            'current' => $this->channel->currentVersion(),
            'automatic' => (bool) config('phpdbadmin.update.check'),
            'blockers' => $this->updater->blockers(),
            'state' => $this->updater->summary(),
            'status' => Inertia::defer(function (): ?array {
                $status = $this->channel->status(network: (bool) config('phpdbadmin.update.check'));

                return $status === null ? null : [...$status, 'blockers' => $this->updater->releaseBlockers($status)];
            }),
        ]);
    }

    public function check(): RedirectResponse
    {
        $status = $this->channel->check();

        Inertia::flash('toast', $status['error'] !== null
            ? ['type' => 'error', 'message' => $status['error']]
            : ['type' => 'success', 'message' => $status['available']
                ? __('Version :version ist erschienen.', ['version' => $status['latest']])
                : __('phpdbadmin :version ist aktuell.', ['version' => $status['current']])]);

        return back();
    }

    public function step(Request $request, string $step): JsonResponse
    {
        $user = $this->actor($request);

        try {
            return match ($step) {
                'download' => $this->answer($step, $this->updater->download($user)['to']),
                'extract' => $this->answer($step, $this->updater->extract()['to']),
                'replace' => $this->replace($request),
                default => $this->answer($step, $this->updater->finish($user)['to']),
            };
        } catch (UpdateFailed $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage(), 'retry' => $exception->retry], $exception->retry ? 409 : 422);
        }
    }

    /**
     * Antwort und Cookie entstehen vor dem Tausch: Danach lädt diese Anfrage möglichst nichts mehr aus dem Programm.
     */
    private function replace(Request $request): JsonResponse
    {
        $secret = Str::random(40);
        $response = new JsonResponse(['ok' => true, 'step' => 'replace', 'wait' => 0]);
        $response->withCookie(MaintenanceModeBypassCookie::create($secret));

        $wait = $this->updater->replace($secret, $this->actor($request));

        return $response->setData(['ok' => true, 'step' => 'replace', 'wait' => $wait]);
    }

    private function answer(string $step, string $version): JsonResponse
    {
        return response()->json(['ok' => true, 'step' => $step, 'version' => $version]);
    }
}
