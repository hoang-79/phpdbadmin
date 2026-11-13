<?php

namespace App\Services\Update;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Audit\Masking;
use Closure;
use FilesystemIterator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

/**
 * Aktualisieren über die Oberfläche (B-09) — für Installationen aus dem Release-Archiv (Web-Installer oder
 * hochgeladen), nicht für Git. Vier Schritte, je eine eigene Anfrage: So stösst keiner an die Zeitgrenze des Hosters,
 * und der letzte läuft schon im Code der neuen Version.
 *
 * 1. `download` — Manifest neu holen und seine Signatur prüfen, Version, PHP und Erweiterungen vergleichen, Archiv
 *    laden, Grösse und SHA-256 gegen das signierte Manifest prüfen.
 * 2. `extract` — entpacken mit Pfadprüfung (genau ein oberster Ordner, kein «..», keine Verknüpfungen), dann prüfen,
 *    ob das Archiv vollständig ist und die Version trägt, die das Manifest nennt.
 * 3. `replace` — Wartungsmodus an (das Konto, das aktualisiert, kommt mit einem Cookie durch), dann jeden Eintrag
 *    per Umbenennen tauschen; die bisherige Fassung kommt in die Sicherung. Scheitert ein Tausch, geht alles zurück.
 * 4. `finish` — im neuen Code: Migrationen, Zwischenspeicher, Wartungsmodus aus, Protokoll. Scheitert eine Migration,
 *    rollt phpdbadmin ihren Stapel zurück und holt die bisherige Fassung zurück.
 *
 * Unberührt bleiben `.env`, `storage/` (nur fehlende Ordner kommen dazu), die SQLite-Datei der App-Datenbank und alles,
 * was nicht im Archiv steht. Liegt der Webordner getrennt vom Programm (Web-Installer), ersetzt phpdbadmin dort nur die
 * Einträge aus `public/` des Archivs — nie `index.php`, `.htaccess` oder einen Eintrag mit Punkt am Anfang.
 *
 * **Vertrag zwischen den Versionen:** Den letzten Schritt führt die neue Version aus. Jede Version behält deshalb
 * `POST admin/update/finish`, die Antwort `{ok, version}` bzw. `{message, retry}` und den Zustand in
 * `storage/app/private/aktualisierung/zustand.json` mit `step`, `from`, `to`, `batch`, `cached` und `journal`.
 *
 * @phpstan-import-type UpdateStatus from UpdateChannel
 *
 * @phpstan-type JournalEntry array{target: string, backup: string|null}
 * @phpstan-type UpdateState array{step: string, from: string, to: string, file: string, size: int, sha256: string, user: int|null, startedAt: string, replacedAt?: string, finishedAt?: string, batch?: int, cached?: list<string>, journal?: list<JournalEntry>, message?: string}
 * @phpstan-type UpdateBlocker array{key: string, message: string}
 */
final class AppUpdater
{
    public const array STEPS = ['download', 'extract', 'replace', 'finish'];

    /** Zwischenspeicher in `bootstrap/cache`: Wer sie vorher hatte, bekommt sie im neuen Code wieder. */
    private const array CACHES = ['config.php' => 'config:cache', 'routes-v7.php' => 'route:cache', 'events.php' => 'event:cache'];

    /** Was die Aktualisierung ändert — jeder davon muss für PHP beschreibbar sein. */
    private const array TARGETS = ['', 'app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'vendor'];

    private readonly string $base;

    private readonly string $public;

    public function __construct(
        private readonly UpdateChannel $channel,
        private readonly AuditLog $audit,
        ?string $basePath = null,
        ?string $publicPath = null,
    ) {
        $this->base = rtrim($basePath ?? base_path(), '/');
        $this->public = rtrim($publicPath ?? public_path(), '/');
    }

    /**
     * Was das Aktualisieren über die Oberfläche hier verhindert, gleich welches Release.
     *
     * @return list<UpdateBlocker>
     */
    public function blockers(): array
    {
        $blockers = [];

        if (file_exists($this->base.'/.git')) {
            $blockers[] = ['key' => 'git', 'message' => __('Diese Installation stammt aus Git. Aktualisieren Sie sie auf der Konsole mit «git pull» und danach «composer upgrade».')];
        }

        if (! class_exists(ZipArchive::class)) {
            $blockers[] = ['key' => 'zip', 'message' => __('PHP fehlt die Erweiterung zip — ohne sie lässt sich das Archiv nicht entpacken.')];
        }

        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            $blockers[] = ['key' => 'sodium', 'message' => __('PHP fehlt die Erweiterung sodium — ohne sie lässt sich die Signatur nicht prüfen.')];
        }

        if ($this->channel->publicKey() === '') {
            $blockers[] = ['key' => 'key', 'message' => __('Dieser Installation fehlt der öffentliche Schlüssel der Releases (PHPDBADMIN_RELEASE_PUBLIC_KEY) — ohne ihn nimmt sie kein Release an.')];
        }

        $locked = $this->unwritable();

        if ($locked !== []) {
            $blockers[] = ['key' => 'writable', 'message' => __('PHP darf diese Ordner nicht ändern: :paths. Geben Sie dem Webserver dort Schreibrecht, oder laden Sie die neue Version selbst hoch.', ['paths' => implode(', ', $locked)])];
        }

        if ($this->opcacheBlocks()) {
            $blockers[] = ['key' => 'opcache', 'message' => __('Der Server hält PHP-Dateien im Zwischenspeicher (OPcache), ohne sie neu zu lesen, und phpdbadmin darf ihn nicht leeren. Bitten Sie den Hoster, «opcache.validate_timestamps» einzuschalten.')];
        }

        return $blockers;
    }

    /**
     * Was das neueste Release verlangt und dieses PHP nicht hat.
     *
     * @param  UpdateStatus  $status
     * @return list<UpdateBlocker>
     */
    public function releaseBlockers(array $status): array
    {
        if (! $status['available'] || $status['latest'] === null) {
            return [];
        }

        $blockers = [];

        if ($status['php'] !== null && version_compare(PHP_VERSION, $status['php'], '<')) {
            $blockers[] = ['key' => 'php', 'message' => __('Version :version braucht PHP :need, hier läuft :have. Stellen Sie zuerst die PHP-Version um.', ['version' => $status['latest'], 'need' => $status['php'], 'have' => PHP_VERSION])];
        }

        $missing = array_values(array_filter($status['extensions'], fn (string $extension): bool => ! extension_loaded($extension)));

        if ($missing !== []) {
            $blockers[] = ['key' => 'extensions', 'message' => __('Version :version braucht diese PHP-Erweiterungen: :extensions.', ['version' => $status['latest'], 'extensions' => implode(', ', $missing)])];
        }

        return $blockers;
    }

    /**
     * Schritt 1: Release prüfen und laden.
     *
     * @return UpdateState
     *
     * @throws UpdateFailed
     */
    public function download(?User $user): array
    {
        return $this->locked(function () use ($user): array {
            $this->ensureReady();
            $previous = $this->state();

            if ($previous !== null && $previous['step'] === 'replaced') {
                throw new UpdateFailed(__('Die Aktualisierung auf :version ist noch nicht abgeschlossen.', ['version' => $previous['to']]));
            }

            $manifest = $this->channel->latest();
            $current = $this->channel->currentVersion();

            if (! version_compare($manifest->version, $current, '>')) {
                throw new UpdateFailed(__('phpdbadmin :version ist aktuell.', ['version' => $current]));
            }

            $this->ensureRequirements($manifest->version, $manifest->php, $manifest->missingExtensions());

            $work = $this->work();
            $this->ensureDirectory($work, 0700);
            $this->removePath($work.'/neu');

            foreach (glob($work.'/phpdbadmin-*.zip') ?: [] as $leftover) {
                @unlink($leftover);
            }

            // Archiv, entpackt und Sicherung der bisherigen Fassung: rund das Zehnfache des Archivs.
            $free = @disk_free_space($work);

            if ($free !== false && $free < $manifest->size * 12) {
                throw new UpdateFailed(__('Zu wenig Platz: Für das Aktualisieren braucht es etwa :need MB frei, es sind :have MB.', [
                    'need' => (int) ceil($manifest->size * 12 / 1048576), 'have' => (int) floor($free / 1048576),
                ]));
            }

            $zip = $work.'/'.$manifest->file;

            try {
                $response = $this->channel->http(300)->sink($zip)->get($this->channel->archiveUrl($manifest));
            } catch (ConnectionException $exception) {
                @unlink($zip);

                throw new UpdateFailed(__('Das Archiv liess sich nicht laden: :reason', ['reason' => $exception->getMessage()]));
            }

            if (! $response->successful()) {
                @unlink($zip);

                throw new UpdateFailed(__('Das Archiv liess sich nicht laden: :reason', ['reason' => 'HTTP '.$response->status()]));
            }

            clearstatcache(true, $zip);

            if (! is_file($zip) || filesize($zip) !== $manifest->size) {
                @unlink($zip);

                throw new UpdateFailed(__('Das Archiv ist nicht so gross wie im signierten Manifest — es wird nicht verwendet.'));
            }

            if (! hash_equals($manifest->sha256, (string) hash_file('sha256', $zip))) {
                @unlink($zip);

                throw new UpdateFailed(__('Die Prüfsumme des Archivs stimmt nicht mit dem signierten Manifest überein — es wird nicht verwendet.'));
            }

            $state = ['step' => 'downloaded', 'from' => $current, 'to' => $manifest->version, 'file' => $manifest->file,
                'size' => $manifest->size, 'sha256' => $manifest->sha256, 'user' => $user?->id, 'startedAt' => Date::now()->toIso8601String()];
            $this->save($state);

            return $state;
        });
    }

    /**
     * Schritt 2: entpacken und das Archiv selbst prüfen.
     *
     * @return UpdateState
     *
     * @throws UpdateFailed
     */
    public function extract(): array
    {
        return $this->locked(function (): array {
            $this->ensureReady();
            $state = $this->expect('downloaded');
            $work = $this->work();
            $zipPath = $work.'/'.$state['file'];

            if (! is_file($zipPath) || ! hash_equals($state['sha256'], (string) hash_file('sha256', $zipPath))) {
                throw new UpdateFailed(__('Das geladene Archiv fehlt oder wurde verändert — bitte von vorne beginnen.'));
            }

            $root = 'phpdbadmin-'.$state['to'];
            $staging = $work.'/neu';
            $zip = new ZipArchive;

            if ($zip->open($zipPath) !== true) {
                throw new UpdateFailed(__('Das Archiv lässt sich nicht öffnen.'));
            }

            try {
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $name = (string) $zip->getNameIndex($index);

                    if (! $this->safeEntry($zip, $index, $name, $root)) {
                        throw new UpdateFailed(__('Das Archiv enthält einen unzulässigen Pfad (:path) — es wird nicht entpackt.', ['path' => $name]));
                    }
                }

                $this->removePath($staging);
                $this->ensureDirectory($staging, 0700);

                if (! $zip->extractTo($staging)) {
                    throw new UpdateFailed(__('Das Archiv liess sich nicht entpacken.'));
                }
            } finally {
                $zip->close();
            }

            $new = $staging.'/'.$root;

            foreach (['artisan', 'bootstrap/app.php', 'config/phpdbadmin.php', 'composer.json', 'vendor/autoload.php', 'public/build/manifest.json'] as $required) {
                if (! is_file($new.'/'.$required)) {
                    throw new UpdateFailed(__('Im Archiv fehlt :path — es ist kein vollständiges Release.', ['path' => $required]));
                }
            }

            $found = $this->versionIn($new.'/config/phpdbadmin.php');

            if ($found !== $state['to']) {
                throw new UpdateFailed(__('Das Archiv trägt die Version :found statt :expected — es wird nicht verwendet.', ['found' => $found ?? '?', 'expected' => $state['to']]));
            }

            $this->ensureRequirements($state['to'], $this->minimumPhp($new.'/composer.json'), []);

            $state['step'] = 'extracted';
            $this->save($state);

            return $state;
        });
    }

    /**
     * Schritt 3: Wartungsmodus an, Programm austauschen. Läuft noch im alten Code; jede spätere Anfrage lädt den neuen.
     * Was diese Anfrage danach noch braucht, ist vor dem Tausch geladen.
     *
     * @param  string  $secret  Durchlass durch den Wartungsmodus (Cookie für das Konto, das aktualisiert)
     * @return int Sekunden, die der Browser vor dem Abschluss warten soll, weil OPcache sich nicht leeren lässt
     *
     * @throws UpdateFailed
     */
    public function replace(string $secret, ?User $user): int
    {
        return $this->locked(function () use ($secret, $user): int {
            $this->ensureReady();
            $state = $this->expect('extracted');
            $work = $this->work();
            $new = $work.'/neu/phpdbadmin-'.$state['to'];

            if (! is_dir($new)) {
                throw new UpdateFailed(__('Das entpackte Release fehlt — bitte von vorne beginnen.'));
            }

            foreach ([...glob($work.'/sicherung-*', GLOB_ONLYDIR) ?: [], ...glob($this->public.'/.phpdbadmin-sicherung-*', GLOB_ONLYDIR) ?: []] as $older) {
                $this->removePath($older);
            }

            $plan = $this->plan($new, $work.'/sicherung-'.$state['from'], $this->public.'/.phpdbadmin-sicherung-'.$state['from']);
            $state['batch'] = $this->latestBatch();
            $state['cached'] = array_values(array_filter(array_keys(self::CACHES), fn (string $file): bool => is_file($this->base.'/bootstrap/cache/'.$file)));
            $replacedAt = Date::now()->toIso8601String();
            $wait = $this->opcacheWait();

            $this->down($secret);
            $journal = [];

            try {
                foreach ($plan as [$source, $target, $backup, $copy]) {
                    $this->swap($source, $target, $backup, $copy, $journal);
                }

                $this->completeStorage($new.'/storage');
            } catch (Throwable $exception) {
                $this->discardAttempt($state, $this->restore($journal));
                $this->up();
                $this->fail($state, 'replace', $exception, $user);

                throw new UpdateFailed(__('Der Austausch ist gescheitert; die bisherige Version :version läuft weiter: :reason', ['version' => $state['from'], 'reason' => $exception->getMessage()]));
            }

            $this->save([...$state, 'step' => 'replaced', 'replacedAt' => $replacedAt, 'journal' => $journal]);
            $this->resetOpcache();

            return $wait;
        });
    }

    /**
     * Schritt 4, im Code der neuen Version: Migrationen, Zwischenspeicher, Wartungsmodus aus.
     *
     * @return UpdateState
     *
     * @throws UpdateFailed
     */
    public function finish(?User $user): array
    {
        return $this->locked(function () use ($user): array {
            $state = $this->expect('replaced');
            $running = $this->channel->currentVersion();

            if ($running !== $state['to']) {
                throw new UpdateFailed(__('Der Server führt noch Version :version aus — einen Moment.', ['version' => $running]), retry: true);
            }

            try {
                if (Artisan::call('migrate', ['--force' => true]) !== 0) {
                    throw new RuntimeException(trim(Artisan::output()));
                }
            } catch (Throwable $exception) {
                $this->recover($state, $exception, $user);
            }

            foreach ($state['cached'] ?? [] as $file) {
                if (isset(self::CACHES[$file])) {
                    Artisan::call(self::CACHES[$file]);
                }
            }

            Artisan::call('view:clear');
            $this->up();
            $this->audit->record('app.updated', $user, $state['to'], ['from' => $state['from'], 'to' => $state['to']]);

            $work = $this->work();
            $this->removePath($work.'/neu');
            @unlink($work.'/'.$state['file']);

            // Im Webordner lag nur Gebautes — die Sicherung des Programms hat dieselben Dateien unter public/.
            foreach (glob($this->public.'/.phpdbadmin-sicherung-*', GLOB_ONLYDIR) ?: [] as $copy) {
                $this->removePath($copy);
            }

            $this->channel->forget();
            $finished = [...$state, 'step' => 'finished', 'finishedAt' => Date::now()->toIso8601String()];
            unset($finished['journal']);
            $this->save($finished);

            return $finished;
        });
    }

    /**
     * Zustand für die Oberfläche — ohne Pfade.
     *
     * @return array{step: string, from: string, to: string, startedAt: string, finishedAt: string|null, message: string|null, backup: string|null}|null
     */
    public function summary(): ?array
    {
        $state = $this->state();

        if ($state === null) {
            return null;
        }

        $backup = 'storage/app/private/aktualisierung/sicherung-'.$state['from'];

        return [
            'step' => $state['step'],
            'from' => $state['from'],
            'to' => $state['to'],
            'startedAt' => $state['startedAt'],
            'finishedAt' => $state['finishedAt'] ?? null,
            'message' => $state['message'] ?? null,
            'backup' => $state['step'] === 'finished' && is_dir($this->work().'/sicherung-'.$state['from']) ? $backup : null,
        ];
    }

    /**
     * Seit wann ein Austausch auf seinen Abschluss wartet — für den Scheduler, falls der Browser ihn nicht mehr schickt.
     */
    public function waitingSince(): ?int
    {
        $state = $this->state();

        return ($state['step'] ?? null) === 'replaced' && isset($state['replacedAt']) ? (int) strtotime($state['replacedAt']) : null;
    }

    /**
     * @return UpdateState|null
     */
    public function state(): ?array
    {
        $path = $this->work().'/zustand.json';

        if (! is_file($path)) {
            return null;
        }

        $state = json_decode((string) file_get_contents($path), true);

        /** @var UpdateState|null */
        return is_array($state) && isset($state['step'], $state['from'], $state['to'], $state['file']) ? $state : null;
    }

    // ── Prüfen ──

    /**
     * @throws UpdateFailed
     */
    private function ensureReady(): void
    {
        $blockers = $this->blockers();

        if ($blockers !== []) {
            throw new UpdateFailed($blockers[0]['message']);
        }

        // Mehr Zeit nur, wo es eine Grenze gibt — auf der Konsole bleibt es ohne (wie TransferRunner).
        if ((int) ini_get('max_execution_time') > 0) {
            @set_time_limit(600);
        }

        ignore_user_abort(true);
    }

    /**
     * @param  list<string>  $missingExtensions
     *
     * @throws UpdateFailed
     */
    private function ensureRequirements(string $version, string $php, array $missingExtensions): void
    {
        if (version_compare(PHP_VERSION, $php, '<')) {
            throw new UpdateFailed(__('Version :version braucht PHP :need, hier läuft :have. Stellen Sie zuerst die PHP-Version um.', ['version' => $version, 'need' => $php, 'have' => PHP_VERSION]));
        }

        if ($missingExtensions !== []) {
            throw new UpdateFailed(__('Version :version braucht diese PHP-Erweiterungen: :extensions.', ['version' => $version, 'extensions' => implode(', ', $missingExtensions)]));
        }
    }

    /**
     * @return UpdateState
     *
     * @throws UpdateFailed
     */
    private function expect(string $step): array
    {
        $state = $this->state();

        if ($state === null || $state['step'] !== $step) {
            throw new UpdateFailed(__('Dieser Schritt passt nicht zum Stand der Aktualisierung — bitte die Seite neu laden.'));
        }

        return $state;
    }

    /**
     * Pfad im Archiv: unter dem einen obersten Ordner, ohne «..», absolute Pfade, Laufwerke und Verknüpfungen.
     */
    private function safeEntry(ZipArchive $zip, int $index, string $name, string $root): bool
    {
        $normalized = str_replace('\\', '/', $name);
        $opsys = 0;
        $attributes = 0;
        $link = $zip->getExternalAttributesIndex($index, $opsys, $attributes)
            && $opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000;

        return ! $link
            && ($normalized === $root.'/' || str_starts_with($normalized, $root.'/'))
            && preg_match('#(^|/)\.\.(/|$)#', $normalized) !== 1
            && ! str_contains($normalized, "\0")
            && preg_match('/^[A-Za-z]:/', $normalized) !== 1;
    }

    /** Version aus `config/phpdbadmin.php` des Archivs — gelesen, nicht ausgeführt. */
    private function versionIn(string $config): ?string
    {
        return preg_match("/'version'\s*=>\s*'([^']+)'/", (string) file_get_contents($config), $match) === 1 ? $match[1] : null;
    }

    /** Untergrenze aus `composer.json` des Archivs («^8.5» → 8.5.0), wie beim Bauen des Releases. */
    private function minimumPhp(string $composer): string
    {
        $data = json_decode((string) file_get_contents($composer), true);
        $constraint = is_array($data) && is_array($data['require'] ?? null) ? (string) ($data['require']['php'] ?? '') : '';

        return preg_match('/(\d+)\.(\d+)(?:\.(\d+))?/', $constraint, $match) === 1 ? $match[1].'.'.$match[2].'.'.($match[3] ?? '0') : '8.5.0';
    }

    /**
     * @return list<string>
     */
    private function unwritable(): array
    {
        $paths = array_map(fn (string $target): string => $target === '' ? $this->base : $this->base.'/'.$target, self::TARGETS);
        $paths[] = storage_path('app/private');

        if ($this->separateWebFolder()) {
            $paths[] = $this->public;
            $paths[] = $this->public.'/build';
        }

        $locked = [];

        foreach (array_unique($paths) as $path) {
            if (file_exists($path) && ! is_writable($path)) {
                $locked[] = match (true) {
                    $path === $this->base => __('Programmordner'),
                    $path === $this->public => __('Webordner'),
                    str_starts_with($path, $this->base.'/') => substr($path, strlen($this->base) + 1),
                    default => $path,
                };
            }
        }

        return $locked;
    }

    private function separateWebFolder(): bool
    {
        return (realpath($this->public) ?: $this->public) !== (realpath($this->base.'/public') ?: $this->base.'/public');
    }

    private function opcacheEnabled(): bool
    {
        return function_exists('opcache_get_status')
            && filter_var(ini_get(PHP_SAPI === 'cli' ? 'opcache.enable_cli' : 'opcache.enable'), FILTER_VALIDATE_BOOL);
    }

    private function canResetOpcache(): bool
    {
        $restricted = (string) ini_get('opcache.restrict_api');

        return function_exists('opcache_reset') && ($restricted === '' || str_starts_with(__FILE__, $restricted));
    }

    private function opcacheBlocks(): bool
    {
        return $this->opcacheEnabled() && ! filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOL) && ! $this->canResetOpcache();
    }

    private function opcacheWait(): int
    {
        return $this->opcacheEnabled() && ! $this->canResetOpcache() ? max(0, (int) ini_get('opcache.revalidate_freq')) + 1 : 0;
    }

    private function resetOpcache(): void
    {
        if ($this->opcacheEnabled() && $this->canResetOpcache()) {
            @opcache_reset();
        }
    }

    // ── Austauschen ──

    /**
     * Was wohin kommt: jeder oberste Eintrag des Archivs als Ganzes, aus `database/` und `public/` jeder Eintrag
     * einzeln (dort liegen die SQLite-Datei und Eigenes), `storage/` gar nicht. Danach Kopien für den getrennten
     * Webordner.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: bool}> Quelle, Ziel, Sicherung, kopieren statt verschieben
     */
    private function plan(string $new, string $backup, string $webBackup): array
    {
        $plan = [];

        foreach ($this->children($new) as $name) {
            if ($name === 'storage') {
                continue;
            }

            if ($name === 'database' || $name === 'public') {
                foreach ($this->children($new.'/'.$name) as $child) {
                    $plan[] = [$new.'/'.$name.'/'.$child, $this->base.'/'.$name.'/'.$child, $backup.'/'.$name.'/'.$child, false];
                }

                continue;
            }

            $plan[] = [$new.'/'.$name, $this->base.'/'.$name, $backup.'/'.$name, false];
        }

        if ($this->separateWebFolder()) {
            foreach ($this->children($new.'/public') as $child) {
                $target = $this->public.'/'.$child;

                if (str_starts_with($child, '.') || $child === 'index.php' || str_starts_with($this->base.'/', $target.'/')) {
                    continue;
                }

                $plan[] = [$this->base.'/public/'.$child, $target, $webBackup.'/'.$child, true];
            }
        }

        return $plan;
    }

    /**
     * Bisheriges beiseite (nur umbenennen — scheitert das, bleibt es, wie es war), Neues an seinen Platz.
     *
     * @param  list<JournalEntry>  $journal
     */
    private function swap(string $source, string $target, string $backup, bool $copy, array &$journal): void
    {
        if (file_exists($target) || is_link($target)) {
            $this->ensureDirectory(dirname($backup));

            if (! @rename($target, $backup)) {
                throw new RuntimeException(__(':path lässt sich nicht beiseitelegen.', ['path' => $this->label($target)]));
            }

            $journal[] = ['target' => $target, 'backup' => $backup];
        } else {
            $journal[] = ['target' => $target, 'backup' => null];
        }

        $this->ensureDirectory(dirname($target));

        if ($copy) {
            $this->copyPath($source, $target);
        } elseif (! @rename($source, $target)) {
            // Anderes Dateisystem für storage: kopieren, dann das Entpackte entfernen.
            $this->copyPath($source, $target);
            $this->removePath($source);
        }
    }

    /**
     * Zurück zur bisherigen Fassung — so weit es geht; was nicht zurückkam, steht in der Rückgabe.
     *
     * @param  list<JournalEntry>  $journal
     * @return list<string>
     */
    private function restore(array $journal): array
    {
        $failed = [];

        foreach (array_reverse($journal) as $entry) {
            try {
                if (file_exists($entry['target']) || is_link($entry['target'])) {
                    $this->removePath($entry['target']);
                }

                if ($entry['backup'] !== null && ! @rename($entry['backup'], $entry['target'])) {
                    $failed[] = $this->label($entry['target']);
                }
            } catch (Throwable) {
                $failed[] = $this->label($entry['target']);
            }
        }

        return $failed;
    }

    /**
     * Die Migration ist gescheitert: ihren Stapel zurückrollen, bisherige Fassung zurück, Wartungsmodus aus.
     *
     * @param  UpdateState  $state
     *
     * @throws UpdateFailed
     */
    private function recover(array $state, Throwable $exception, ?User $user): never
    {
        // Über den Migrator, nicht `migrate:rollback`: Den Befehl sperrt DB::prohibitDestructiveCommands() in
        // Produktion — zurückgerollt wird hier nur, was diese Aktualisierung eben angelegt hat.
        try {
            $batch = (int) ($state['batch'] ?? 0);
            $migrator = app('migrator');
            $paths = [...$migrator->paths(), database_path('migrations')];

            for ($current = $this->latestBatch(); $current > $batch; $current--) {
                $migrator->rollback($paths, ['batch' => $current]);
            }
        } catch (Throwable $rollback) {
            report($rollback);
        }

        // Vor dem Zurückholen: Danach liegt der alte Code unter denselben Pfaden.
        $this->fail($state, 'finish', $exception, $user);
        $failed = $this->restore($state['journal'] ?? []);
        $this->discardAttempt($state, $failed);
        $this->resetOpcache();
        $this->up();

        $message = __('Die App-Datenbank liess sich nicht anpassen; die bisherige Version :version läuft wieder: :reason', ['version' => $state['from'], 'reason' => Masking::exception($exception)]);

        if ($failed !== []) {
            $message .= ' '.__('Nicht zurückgekommen: :paths — die Sicherung liegt in storage/app/private/aktualisierung.', ['paths' => implode(', ', $failed)]);
        }

        throw new UpdateFailed($message);
    }

    /**
     * Nach dem Zurückholen: Was vom Versuch bleibt (leere Ordner der Sicherung, Entpacktes, Archiv), kommt weg — ausser
     * etwas kam nicht zurück; dann bleibt die Sicherung, wie sie ist.
     *
     * @param  UpdateState  $state
     * @param  list<string>  $failed
     */
    private function discardAttempt(array $state, array $failed): void
    {
        if ($failed !== []) {
            return;
        }

        $work = $this->work();
        $this->removePath($work.'/sicherung-'.$state['from']);
        $this->removePath($this->public.'/.phpdbadmin-sicherung-'.$state['from']);
        $this->removePath($work.'/neu');
        @unlink($work.'/'.$state['file']);
    }

    /**
     * @param  UpdateState  $state
     */
    private function fail(array $state, string $step, Throwable $exception, ?User $user): void
    {
        unset($state['journal']);
        $this->save([...$state, 'step' => 'failed', 'message' => Masking::exception($exception)]);
        $this->audit->record('app.update_failed', $user, $state['to'], ['from' => $state['from'], 'to' => $state['to'], 'step' => $step], error: $exception);
    }

    /** Ordner, die das neue Release in `storage/` mitbringt, anlegen — Vorhandenes bleibt. */
    private function completeStorage(string $newStorage): void
    {
        if (! is_dir($newStorage)) {
            return;
        }

        /** @var SplFileInfo $item */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($newStorage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $target = $this->base.'/storage/'.substr($item->getPathname(), strlen($newStorage) + 1);

            if ($item->isDir()) {
                $this->ensureDirectory($target);
            } elseif (! file_exists($target)) {
                @copy($item->getPathname(), $target);
            }
        }
    }

    private function down(string $secret): void
    {
        $code = Artisan::call('down', ['--secret' => $secret, '--retry' => 60, '--refresh' => 15, '--render' => 'maintenance']);

        if ($code !== 0) {
            throw new UpdateFailed(__('Der Wartungsmodus liess sich nicht einschalten: :reason', ['reason' => trim(Artisan::output())]));
        }
    }

    /** Wie `php artisan up` — ohne einen Befehl, der nach dem Tausch erst geladen werden müsste. */
    private function up(): void
    {
        $maintenance = app()->maintenanceMode();

        if ($maintenance->active()) {
            $maintenance->deactivate();
        }

        if (is_file(storage_path('framework/maintenance.php'))) {
            @unlink(storage_path('framework/maintenance.php'));
        }
    }

    private function latestBatch(): int
    {
        try {
            return (int) DB::table((string) config('database.migrations.table', 'migrations'))->max('batch');
        } catch (Throwable) {
            return 0;
        }
    }

    // ── Dateien ──

    private function work(): string
    {
        return storage_path('app/private/aktualisierung');
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function save(array $state): void
    {
        $this->ensureDirectory($this->work(), 0700);
        $path = $this->work().'/zustand.json';
        file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
        @chmod($path, 0600);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws UpdateFailed
     */
    private function locked(Closure $callback): mixed
    {
        $lock = Cache::lock('phpdbadmin.update', 900);

        if (! $lock->get()) {
            throw new UpdateFailed(__('Eine Aktualisierung läuft gerade schon.'));
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * @return list<string>
     */
    private function children(string $directory): array
    {
        $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        sort($entries, SORT_STRING);

        return $entries;
    }

    private function ensureDirectory(string $directory, int $mode = 0755): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, $mode, true) && ! is_dir($directory)) {
            throw new RuntimeException(__('Der Ordner :path lässt sich nicht anlegen.', ['path' => $this->label($directory)]));
        }
    }

    private function copyPath(string $from, string $to): void
    {
        if (! is_dir($from)) {
            if (! @copy($from, $to)) {
                throw new RuntimeException(__(':path lässt sich nicht schreiben.', ['path' => $this->label($to)]));
            }

            return;
        }

        $this->ensureDirectory($to);

        /** @var SplFileInfo $item */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $target = $to.'/'.substr($item->getPathname(), strlen($from) + 1);

            if ($item->isDir()) {
                $this->ensureDirectory($target);
            } elseif (! @copy($item->getPathname(), $target)) {
                throw new RuntimeException(__(':path lässt sich nicht schreiben.', ['path' => $this->label($target)]));
            }
        }
    }

    /** Entfernen — nur innerhalb des Programms oder des Webordners. */
    private function removePath(string $path): void
    {
        if (! str_starts_with($path, $this->base.'/') && ! str_starts_with($path, $this->public.'/') && ! str_starts_with($path, $this->work().'/')) {
            throw new LogicException('Ausserhalb der Installation wird nichts entfernt: '.$path);
        }

        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        /** @var SplFileInfo $item */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }

    private function label(string $path): string
    {
        return match (true) {
            str_starts_with($path, $this->base.'/') => substr($path, strlen($this->base) + 1),
            str_starts_with($path, $this->public.'/') => substr($path, strlen($this->public) + 1),
            default => basename($path),
        };
    }
}
