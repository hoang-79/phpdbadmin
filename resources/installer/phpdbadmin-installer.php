<?php

/**
 * phpdbadmin — Web-Installer.
 *
 * Eine Datei, keine Abhängigkeiten: in den leeren Ordner der eigenen (Sub-)Domain hochladen und im Browser öffnen.
 *
 * Der Installer prüft den Server wie eine Fachperson — er misst, statt zu raten: PHP-Version und Erweiterungen,
 * Datenbanktreiber, Speicher, Platz, HTTPS, Webserver, Inhalt des Ordners, und er erkennt den Hoster an Rechnername,
 * Pfaden und Kennzeichen. Jeden Befund erklärt er in Klartext. Was er selbst lösen kann, löst er nach Bestätigung:
 * die PHP-Version per .htaccess (erst in einem Testordner ausprobiert, mit Sicherung), fremde Dateien beiseite legen
 * (nichts gelöscht), den Namen des Datenbank-Servers finden, eine fehlende Datenbank anlegen. Für den Rest zeigt er die
 * Schritte im Verwaltungsbereich des erkannten Hosters und eine fertige Nachricht an dessen Support.
 *
 * Danach lädt er das Release von GitHub, prüft Grösse, SHA-256 und Ed25519-Signatur, entpackt mit Pfadprüfung, legt
 * das Programm wenn möglich ausserhalb des Webordners ab, schreibt die .env, richtet die App-Datenbank und das erste
 * Konto ein, prüft die fertige Installation vom Browser aus und löscht sich selbst. Am Ende steht der Link zur
 * Ersteinrichtung (Passkey).
 *
 * Bewusst in PHP-7.3-Syntax: Auf einem zu alten PHP soll eine Erklärung erscheinen, kein Syntaxfehler. Daten des
 * Servers verlassen ihn nicht; einzige Gegenstelle ist GitHub (Release) und — auf Wunsch — der eigene Mail-Server.
 *
 * Quelle: resources/installer/phpdbadmin-installer.php · Lizenz: MIT (wie phpdbadmin).
 */
define('PDA_REPOSITORY', '__PHPDBADMIN_REPOSITORY__');
define('PDA_PUBLIC_KEY', '__PHPDBADMIN_PUBLIC_KEY__');
define('PDA_MIN_PHP', '8.5.0');
define('PDA_STATE', '.phpdbadmin-installer-state.php');
define('PDA_IDLE', 1800);
define('PDA_DISK_MB', 200);
define('PDA_STATE_GUARD', "<?php http_response_code(404); exit; __halt_compiler();\n");

@ini_set('display_errors', '0');
@set_time_limit(300);

// ─── Kleine Hilfen (PHP 7.3) ─────────────────────────────────────────────────────────────────────────────────────────

function pda_repository(): string
{
    return strpos(PDA_REPOSITORY, '__') === 0 ? 'hoang-79/phpdbadmin' : PDA_REPOSITORY;
}

function pda_public_key(): string
{
    return strpos(PDA_PUBLIC_KEY, '__') === 0 ? '' : PDA_PUBLIC_KEY;
}

function pda_lower(string $text): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
}

function pda_e($text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function pda_starts(string $haystack, string $needle): bool
{
    return $needle === '' || strpos($haystack, $needle) === 0;
}

function pda_contains(string $haystack, string $needle): bool
{
    return $needle === '' || strpos($haystack, $needle) !== false;
}

/** «256M» → Bytes; -1 = unbegrenzt. */
function pda_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return -1;
    }
    $unit = strtolower(substr($value, -1));
    $number = (int) $value;
    if ($unit === 'g') {
        return $number * 1024 * 1024 * 1024;
    }
    if ($unit === 'm') {
        return $number * 1024 * 1024;
    }
    if ($unit === 'k') {
        return $number * 1024;
    }

    return $number;
}

function pda_megabytes(int $bytes): string
{
    return number_format($bytes / 1048576, $bytes < 10485760 ? 1 : 0, '.', "'").' MB';
}

/** Ordner samt Inhalt entfernen — nur unterhalb von $within. */
function pda_remove(string $path, string $within): void
{
    $real = realpath($path);
    $base = realpath($within);
    if ($real === false || $base === false || ! pda_starts($real, $base) || $real === $base) {
        return;
    }
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            pda_remove($path.DIRECTORY_SEPARATOR.$entry, $within);
        }
    }
    @rmdir($path);
}

/** Ordner kopieren (für Verschieben über Dateisystemgrenzen). */
function pda_copy(string $from, string $to): void
{
    if (is_dir($from)) {
        if (! is_dir($to) && ! @mkdir($to, 0755, true)) {
            throw new RuntimeException(PdaText::t('err_mkdir', ['path' => $to]));
        }
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                pda_copy($from.'/'.$entry, $to.'/'.$entry);
            }
        }

        return;
    }
    if (! @copy($from, $to)) {
        throw new RuntimeException(PdaText::t('err_copy', ['path' => $to]));
    }
}

// ─── Texte (Deutsch, Englisch) ───────────────────────────────────────────────────────────────────────────────────────

final class PdaText
{
    /** @var string */
    private static $lang = 'de';

    public static function detect(): void
    {
        $chosen = isset($_GET['lang']) ? (string) $_GET['lang'] : (isset($_COOKIE['pda_lang']) ? (string) $_COOKIE['pda_lang'] : '');
        if (in_array($chosen, ['de', 'en'], true)) {
            self::$lang = $chosen;
            if (isset($_GET['lang'])) {
                @setcookie('pda_lang', $chosen, 0, '', '', false, true);
            }

            return;
        }
        $accept = strtolower(isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '');
        if ($accept === '' || pda_starts($accept, 'de')) {
            self::$lang = 'de';
        } elseif (pda_starts($accept, 'en') || ! pda_contains($accept, 'de')) {
            self::$lang = 'en';
        } else {
            self::$lang = 'de';
        }
    }

    public static function lang(): string
    {
        return self::$lang;
    }

    public static function t(string $key, array $replace = []): string
    {
        $texts = self::$lang === 'en' ? self::EN : self::DE;
        $text = isset($texts[$key]) ? $texts[$key] : (isset(self::DE[$key]) ? self::DE[$key] : $key);
        foreach ($replace as $name => $value) {
            $text = str_replace(':'.$name, (string) $value, $text);
        }

        return $text;
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::$lang === 'en' ? self::EN + self::DE : self::DE;
    }

    const DE = [
        'title' => 'phpdbadmin einrichten',
        'lead' => 'Der Installer prüft diesen Server, löst, was er lösen kann, lädt phpdbadmin und richtet es ein. Rund zwei Minuten, ohne Konsole.',
        'release_loading' => 'Release wird gesucht …',
        'release_found' => 'phpdbadmin :version · :size · :signed',
        'release_signed' => 'signiert',
        'release_unsigned' => 'ohne Signatur',
        'release_local' => 'aus diesem Ordner',
        'release_failed' => 'Das Release ist nicht erreichbar: :reason',
        'release_failed_hint' => 'Der Server muss github.com über HTTPS erreichen. Geht das nicht, laden Sie latest.json und das ZIP von der Release-Seite herunter und legen beide neben diesen Installer.',
        'checks' => 'Prüfung des Servers',
        'checks_passed' => ':passed von :total Punkten erfüllt',
        'detected' => 'Erkannt: :hoster · PHP :php (:sapi) · :server',
        'hoster_generic' => 'unbekannter Hoster',
        'all_good' => 'Alles bereit.',
        'blocking' => 'Bevor es weitergeht',
        'warnings' => 'Hinweise',
        'recheck' => 'Erneut prüfen',
        'fix' => 'Lösen',
        'manual' => 'Selbst lösen bei :hoster',
        'manual_generic' => 'Selbst lösen',
        'help_link' => 'Anleitung des Hosters',
        'support' => 'Nachricht an den Support',
        'support_copy' => 'Kopieren',
        'support_copied' => 'Kopiert.',
        'support_text' => "Guten Tag\n\nIch möchte phpdbadmin (https://github.com/:repo) unter :url installieren. Der Installer meldet:\n\n:problems\n\nTechnische Angaben: PHP :php (:sapi), Server :server, Ordner :dir.\n\nKönnen Sie mir dabei helfen?\n\nFreundliche Grüsse",
        'ask_yes' => 'Ja, ausführen',
        'ask_no' => 'Abbrechen',
        'confirm_php' => 'Der Installer probiert die PHP-Einstellung zuerst in einem Testordner aus und übernimmt sie nur, wenn sie wirkt. Die bisherige .htaccess wird gesichert. Fortfahren?',
        'php_testing' => 'Versuch :n von :count …',
        'php_applied' => 'PHP :version ist eingestellt. Die Seite lädt neu …',
        'php_failed' => 'Keine der Einstellungen wirkt hier. Bitte stellen Sie PHP im Verwaltungsbereich um (Schritte unten).',
        'confirm_move' => 'Die fremden Dateien werden in den Ordner :folder verschoben — gelöscht wird nichts. Fortfahren?',
        'moving' => 'Dateien werden verschoben …',
        'moved' => 'Verschoben nach :folder.',

        'p_php_title' => 'PHP :have ist zu alt',
        'p_php_text' => 'phpdbadmin braucht PHP :min oder neuer.',
        'p_php_fixable' => 'Auf dem Server liegt PHP :versions — der Installer kann diesen Ordner selbst darauf umstellen.',
        'p_php_cyon_depth' => 'Dieser Ordner liegt :depth Ebenen unter public_html. Bis hierhin reicht die Einstellung des PHP-Versionsmanagers von cyon nicht — es gilt der Standard des Servers. Die Zeile in der .htaccess löst das.',
        'p_ext_title' => 'PHP-Erweiterungen fehlen: :list',
        'p_ext_text' => 'Ohne diese Erweiterungen läuft phpdbadmin nicht. Beim Hoster lassen sie sich meist in den PHP-Einstellungen einschalten.',
        'p_driver_title' => 'Kein Datenbanktreiber',
        'p_driver_text' => 'Es fehlen pdo_sqlite, pdo_mysql und pdo_pgsql — phpdbadmin braucht mindestens einen für die eigene Datenbank.',
        'p_sqlite_title' => 'SQLite fehlt — MariaDB/MySQL geht',
        'p_sqlite_text' => 'Für die eigene Datenbank von phpdbadmin nehmen Sie dann eine MariaDB- oder MySQL-Datenbank des Hosters (im Formular unten).',
        'p_targets_title' => 'Verwalten lassen sich hier: :list',
        'p_targets_text' => 'Für die übrigen Datenbanktypen fehlt der PHP-Treiber (:missing).',
        'p_download_title' => 'Herunterladen nicht möglich',
        'p_download_text' => 'Weder curl noch allow_url_fopen mit OpenSSL ist verfügbar. Alternative: latest.json und das ZIP des Releases neben diesen Installer legen.',
        'p_zip_title' => 'Entpacken nicht möglich (ZipArchive fehlt)',
        'p_zip_text' => 'Die Erweiterung zip fehlt. Beim Hoster in den PHP-Einstellungen einschalten.',
        'p_sodium_title' => 'Signatur nicht prüfbar (sodium fehlt)',
        'p_sodium_text' => 'Ohne die Erweiterung sodium kann der Installer die Echtheit des Release nicht prüfen und bricht deshalb ab.',
        'p_writable_title' => 'Der Ordner ist nicht beschreibbar',
        'p_writable_text' => 'PHP darf in :dir keine Dateien anlegen. Rechte im Dateimanager auf 755 (Ordner) setzen oder den Hoster fragen.',
        'p_disk_title' => 'Zu wenig Platz (:free frei)',
        'p_disk_text' => 'phpdbadmin braucht beim Einrichten rund :need.',
        'p_memory_title' => 'Wenig Arbeitsspeicher für PHP (:limit)',
        'p_memory_text' => 'Empfohlen sind 256 MB (memory_limit). Es kann trotzdem reichen.',
        'p_https_title' => 'Die Seite läuft ohne HTTPS',
        'p_https_text' => 'Passkeys funktionieren nur über HTTPS, und Zugangsdaten zu Datenbanken gehören nicht unverschlüsselt ins Netz. Schalten Sie zuerst ein Zertifikat ein (bei fast allen Hostern kostenlos) und öffnen Sie dann https://:host.',
        'p_subfolder_title' => 'phpdbadmin braucht eine eigene Adresse',
        'p_subfolder_text' => 'Der Installer liegt unter :path. phpdbadmin läuft nur direkt auf einer (Sub-)Domain, zum Beispiel db.ihre-domain.ch. Legen Sie eine Subdomain an, deren Ordner (Dokumentstamm) dieser Ordner ist, und öffnen Sie den Installer dort.',
        'p_files_title' => 'Der Ordner ist nicht leer',
        'p_files_text' => 'Hier liegen schon Dateien (:list). Der Installer kann sie in einen eigenen Ordner verschieben — gelöscht wird nichts.',
        'p_installed_title' => 'phpdbadmin ist hier schon installiert',
        'p_installed_text' => 'Der Installer richtet nur leere Ordner ein. Löschen Sie diese Datei wieder (:file).',
        'p_nginx_title' => 'Webserver ohne .htaccess (:server)',
        'p_nginx_text' => 'Der Installer legt das Programm deshalb ausserhalb des Webordners ab. Ist das nicht möglich, bricht er ab.',
        'p_nginx_fail_text' => 'Ausserhalb des Webordners ist hier kein beschreibbarer Platz (:home) — ohne .htaccess wären Programm und Einstellungen von aussen lesbar. Lassen Sie den Dokumentstamm auf einen Unterordner zeigen oder fragen Sie den Hoster.',
        'p_time_title' => 'Kurze Laufzeit für PHP (:seconds s)',
        'p_time_text' => 'max_execution_time ist knapp. Der Installer arbeitet in Schritten; reicht es nicht, beim Hoster auf 120 s erhöhen.',

        'step_php' => 'PHP-Version',
        'step_ext' => 'PHP-Erweiterungen',
        'step_ssl' => 'Zertifikat (HTTPS)',
        'step_docroot' => 'Subdomain und Dokumentstamm',
        'step_files' => 'Dateien',
        'menu_in' => 'Im :panel unter :menu.',
        'menu_generic_php' => 'Im Verwaltungsbereich des Hosters die PHP-Version dieses Ordners bzw. dieser Domain auf :min oder neuer stellen.',
        'menu_generic_ext' => 'Im Verwaltungsbereich die PHP-Erweiterungen einschalten (oft «PHP-Einstellungen» oder «Select PHP Version» › «Extensions»).',
        'menu_generic_ssl' => 'Im Verwaltungsbereich ein Zertifikat einschalten (Let’s Encrypt oder AutoSSL).',
        'menu_generic_docroot' => 'Im Verwaltungsbereich eine Subdomain anlegen und als Ordner (Dokumentstamm) diesen Ordner wählen: :dir',
        'then_reload' => 'Danach hier «Erneut prüfen».',

        'form' => 'Einrichten',
        'account' => 'Ihr Konto',
        'name' => 'Name',
        'email' => 'E-Mail-Adresse',
        'email_hint' => 'Anmeldung per Passkey oder Code an diese Adresse — phpdbadmin kennt keine Passwörter.',
        'language' => 'Sprache',
        'appdb' => 'Datenbank von phpdbadmin',
        'appdb_hint' => 'Hier speichert phpdbadmin Konten, Verbindungen (Passwörter verschlüsselt) und Protokoll — nicht Ihre Daten.',
        'appdb_sqlite' => 'SQLite-Datei (empfohlen, nichts einzutragen)',
        'appdb_mysql' => 'MariaDB oder MySQL',
        'appdb_pgsql' => 'PostgreSQL',
        'db_host' => 'Server',
        'db_port' => 'Port',
        'db_name' => 'Datenbank',
        'db_user' => 'Benutzer',
        'db_password' => 'Passwort',
        'db_test' => 'Verbindung prüfen',
        'db_ok' => 'Verbindung steht (:version).',
        'db_host_changed' => 'Verbindung steht über :host — übernommen.',
        'db_missing' => 'Die Datenbank «:name» gibt es noch nicht.',
        'db_create' => 'Jetzt anlegen',
        'db_created' => 'Datenbank «:name» angelegt.',
        'db_refused' => 'Der Server ist unter :host nicht erreichbar.',
        'db_denied' => 'Benutzer oder Passwort stimmen nicht.',
        'db_prefix' => 'Bei diesem Hoster beginnen Datenbank und Benutzer meist mit dem Kontonamen und einem Unterstrich (z. B. :example).',
        'db_hint_host' => 'Server bei :hoster meist: :host',
        'mail' => 'Mails (Anmeldecodes, Einladungen)',
        'mail_hint' => 'Ohne Mail melden Sie sich nur per Passkey an. SMTP des eigenen Postfachs ist am zuverlässigsten.',
        'mail_smtp' => 'SMTP (empfohlen)',
        'mail_sendmail' => 'Mail des Servers (sendmail)',
        'mail_later' => 'Später einrichten',
        'mail_host' => 'SMTP-Server',
        'mail_port' => 'Port',
        'mail_user' => 'Benutzer',
        'mail_password' => 'Passwort',
        'mail_from' => 'Absender',
        'mail_test' => 'Test-Mail an meine Adresse',
        'mail_sent' => 'Test-Mail ist unterwegs — bitte im Postfach nachsehen.',
        'mail_subject' => 'phpdbadmin: Test-Mail aus dem Installer',
        'mail_body' => 'Diese Mail kommt aus dem Installer von phpdbadmin. Die Einstellungen stimmen.',
        'advanced' => 'Weitere Einstellungen',
        'location' => 'Ablage des Programms',
        'location_outside' => 'Ausserhalb des Webordners (empfohlen): :path',
        'location_inside' => 'Im Webordner, geschützt: :path',
        'allowed_hosts' => 'Datenbank-Server, zu denen phpdbadmin verbinden darf',
        'allowed_hosts_hint' => 'Namen, Adressen oder Netze, mit Komma getrennt. Schützt das interne Netz des Hosters.',
        'install' => 'phpdbadmin installieren',
        'required' => 'Bitte ausfüllen.',

        'run_download' => 'Release herunterladen und Echtheit prüfen',
        'run_extract' => 'Entpacken',
        'run_configure' => 'Einstellungen schreiben',
        'run_place' => 'Dateien an ihren Platz',
        'run_setup' => 'Datenbank und Konto einrichten',
        'run_check' => 'Installation von aussen prüfen',
        'run_finish' => 'Aufräumen',
        'done_title' => 'phpdbadmin ist installiert',
        'done_text' => 'Weiter zur Ersteinrichtung: Der Link meldet Sie an und legt Ihren Passkey an. Er gilt 30 Minuten.',
        'done_button' => 'Zur Ersteinrichtung',
        'done_existing' => 'Es gab schon ein Konto — melden Sie sich wie gewohnt an.',
        'done_login' => 'Zur Anmeldung',
        'done_checks' => 'Systemprüfung von phpdbadmin',
        'done_cron' => 'Für den Betrieb: Cronjob einrichten',
        'done_cron_text' => 'Im :panel unter «Cronjobs» bzw. «Geplante Aufgaben» jede Minute ausführen lassen:',
        'done_cron_generic' => 'Beim Hoster einen Cronjob anlegen, der jede Minute läuft:',
        'done_cron_worker' => 'Optional, für grosse Exporte im Hintergrund — ebenfalls jede Minute:',
        'done_where' => 'Programm: :core · Webordner: :docroot',
        'post_down' => 'phpdbadmin antwortet noch nicht',
        'post_down_text' => 'Unter :url kam keine Antwort von phpdbadmin. Häufig hilft es, die Seite in einer Minute neu zu laden; sonst steht der Fehler in :log.',
        'post_exposed' => 'Achtung: Programmdateien sind von aussen lesbar',
        'post_exposed_text' => ':url ist abrufbar. Der Webserver beachtet die .htaccess nicht. Verschieben Sie den Ordner :core sofort ausserhalb des Webordners oder fragen Sie den Hoster.',
        'error' => 'Das hat nicht geklappt',
        'error_details' => 'Technische Angaben',
        'error_retry' => 'Erneut versuchen',

        'owner_busy' => 'Die Installation läuft in einem anderen Browser',
        'owner_busy_text' => 'Nur ein Browser kann diesen Installer bedienen. Nach 30 Minuten ohne Aktivität wird er wieder frei.',
        'session_lost' => 'Die Sitzung des Installers ist abgelaufen. Bitte die Seite neu laden.',

        'err_mkdir' => 'Der Ordner :path liess sich nicht anlegen.',
        'err_copy' => 'Die Datei :path liess sich nicht schreiben.',
        'err_download' => 'Herunterladen gescheitert: :reason',
        'err_size' => 'Die Datei hat nicht die erwartete Grösse — abgebrochen.',
        'err_hash' => 'Die Prüfsumme stimmt nicht — die Datei ist beschädigt oder verändert. Abgebrochen.',
        'err_signature' => 'Die Signatur des Release ist ungültig — abgebrochen. Nichts wurde installiert.',
        'err_unsigned' => 'Das Release ist nicht signiert — dieser Installer verlangt eine Signatur. Abgebrochen.',
        'err_manifest' => 'Die Angaben zum Release sind unvollständig.',
        'err_zip' => 'Das Archiv lässt sich nicht öffnen.',
        'err_zip_path' => 'Das Archiv enthält einen unzulässigen Pfad (:path) — abgebrochen.',
        'err_zip_layout' => 'Das Archiv hat nicht den erwarteten Aufbau.',
        'err_step' => 'Dieser Schritt kommt zu früh. Bitte die Seite neu laden.',
        'err_bridge' => 'Die Einrichtung im Programm ist gescheitert: :reason',
        'err_memory' => 'PHP hatte zu wenig Arbeitsspeicher. memory_limit beim Hoster auf 256 MB erhöhen.',
        'err_time' => 'Die Zeit für PHP war zu kurz. max_execution_time beim Hoster auf 120 s erhöhen und erneut versuchen.',
        'err_space' => 'Auf dem Server ist kein Platz mehr frei.',
        'err_permission' => 'PHP durfte eine Datei nicht schreiben. Rechte des Ordners prüfen (755).',
        'err_db_driver' => 'Der PHP-Treiber für diese Datenbank fehlt.',
        'err_location' => 'Der Ablageort :path ist nicht beschreibbar.',
        'err_location_taken' => 'Am Ablageort :path liegt schon etwas.',
    ];

    const EN = [
        'title' => 'Set up phpdbadmin',
        'lead' => 'The installer checks this server, fixes what it can, downloads phpdbadmin and sets it up. About two minutes, no console.',
        'release_loading' => 'Looking for the release …',
        'release_found' => 'phpdbadmin :version · :size · :signed',
        'release_signed' => 'signed',
        'release_unsigned' => 'unsigned',
        'release_local' => 'from this folder',
        'release_failed' => 'The release cannot be reached: :reason',
        'release_failed_hint' => 'The server must reach github.com over HTTPS. If it cannot, download latest.json and the ZIP from the release page and put both next to this installer.',
        'checks' => 'Server check',
        'checks_passed' => ':passed of :total points met',
        'detected' => 'Detected: :hoster · PHP :php (:sapi) · :server',
        'hoster_generic' => 'unknown host',
        'all_good' => 'All set.',
        'blocking' => 'Before you continue',
        'warnings' => 'Notes',
        'recheck' => 'Check again',
        'fix' => 'Fix it',
        'manual' => 'Fix it yourself at :hoster',
        'manual_generic' => 'Fix it yourself',
        'help_link' => 'Host’s guide',
        'support' => 'Message to support',
        'support_copy' => 'Copy',
        'support_copied' => 'Copied.',
        'support_text' => "Hello\n\nI would like to install phpdbadmin (https://github.com/:repo) at :url. The installer reports:\n\n:problems\n\nTechnical details: PHP :php (:sapi), server :server, folder :dir.\n\nCould you help me with this?\n\nKind regards",
        'ask_yes' => 'Yes, go ahead',
        'ask_no' => 'Cancel',
        'confirm_php' => 'The installer first tries the PHP setting in a test folder and only applies it if it works. The current .htaccess is backed up. Continue?',
        'php_testing' => 'Attempt :n of :count …',
        'php_applied' => 'PHP :version is set. Reloading …',
        'php_failed' => 'None of the settings works here. Please switch PHP in your control panel (steps below).',
        'confirm_move' => 'The other files will be moved into :folder — nothing is deleted. Continue?',
        'moving' => 'Moving files …',
        'moved' => 'Moved to :folder.',
        'p_php_title' => 'PHP :have is too old',
        'p_php_text' => 'phpdbadmin needs PHP :min or newer.',
        'p_php_fixable' => 'PHP :versions is available on this server — the installer can switch this folder to it.',
        'p_php_cyon_depth' => 'This folder is :depth levels below public_html. cyon’s PHP version manager does not reach that far — the server default applies. A line in .htaccess solves it.',
        'p_ext_title' => 'Missing PHP extensions: :list',
        'p_ext_text' => 'phpdbadmin does not run without them. Hosts usually let you enable them in the PHP settings.',
        'p_driver_title' => 'No database driver',
        'p_driver_text' => 'pdo_sqlite, pdo_mysql and pdo_pgsql are all missing — phpdbadmin needs at least one for its own database.',
        'p_sqlite_title' => 'SQLite missing — MariaDB/MySQL works',
        'p_sqlite_text' => 'Use a MariaDB or MySQL database of your host for phpdbadmin’s own database (form below).',
        'p_targets_title' => 'Manageable here: :list',
        'p_targets_text' => 'The PHP driver for the other database types is missing (:missing).',
        'p_download_title' => 'Downloading is not possible',
        'p_download_text' => 'Neither curl nor allow_url_fopen with OpenSSL is available. Alternative: put latest.json and the release ZIP next to this installer.',
        'p_zip_title' => 'Cannot unpack (ZipArchive missing)',
        'p_zip_text' => 'The zip extension is missing. Enable it in your host’s PHP settings.',
        'p_sodium_title' => 'Cannot check the signature (sodium missing)',
        'p_sodium_text' => 'Without the sodium extension the installer cannot verify the release and stops.',
        'p_writable_title' => 'The folder is not writable',
        'p_writable_text' => 'PHP may not create files in :dir. Set permissions to 755 (folders) in the file manager or ask your host.',
        'p_disk_title' => 'Not enough space (:free free)',
        'p_disk_text' => 'phpdbadmin needs about :need while setting up.',
        'p_memory_title' => 'Little memory for PHP (:limit)',
        'p_memory_text' => '256 MB (memory_limit) is recommended. It may still be enough.',
        'p_https_title' => 'The site runs without HTTPS',
        'p_https_text' => 'Passkeys only work over HTTPS, and database credentials do not belong on the net unencrypted. Enable a certificate first (free at almost every host), then open https://:host.',
        'p_subfolder_title' => 'phpdbadmin needs its own address',
        'p_subfolder_text' => 'The installer sits at :path. phpdbadmin only runs directly on a (sub)domain, e.g. db.your-domain.com. Create a subdomain whose folder (document root) is this folder and open the installer there.',
        'p_files_title' => 'The folder is not empty',
        'p_files_text' => 'There are files here already (:list). The installer can move them into a folder of their own — nothing is deleted.',
        'p_installed_title' => 'phpdbadmin is already installed here',
        'p_installed_text' => 'The installer only sets up empty folders. Delete this file again (:file).',
        'p_nginx_title' => 'Web server without .htaccess (:server)',
        'p_nginx_text' => 'The installer therefore places the program outside the web folder. If that is not possible, it stops.',
        'p_nginx_fail_text' => 'There is no writable place outside the web folder (:home) — without .htaccess the program and settings would be readable from outside. Point the document root to a subfolder or ask your host.',
        'p_time_title' => 'Short PHP time limit (:seconds s)',
        'p_time_text' => 'max_execution_time is tight. The installer works in steps; if it is not enough, raise it to 120 s at your host.',
        'step_php' => 'PHP version',
        'step_ext' => 'PHP extensions',
        'step_ssl' => 'Certificate (HTTPS)',
        'step_docroot' => 'Subdomain and document root',
        'step_files' => 'Files',
        'menu_in' => 'In :panel under :menu.',
        'menu_generic_php' => 'In your host’s control panel, set the PHP version of this folder or domain to :min or newer.',
        'menu_generic_ext' => 'In the control panel, enable the PHP extensions (often “PHP settings” or “Select PHP Version” › “Extensions”).',
        'menu_generic_ssl' => 'In the control panel, enable a certificate (Let’s Encrypt or AutoSSL).',
        'menu_generic_docroot' => 'In the control panel, create a subdomain and choose this folder as its document root: :dir',
        'then_reload' => 'Then “Check again” here.',
        'form' => 'Set up',
        'account' => 'Your account',
        'name' => 'Name',
        'email' => 'Email address',
        'email_hint' => 'Sign in with a passkey or a code sent to this address — phpdbadmin has no passwords.',
        'language' => 'Language',
        'appdb' => 'phpdbadmin’s database',
        'appdb_hint' => 'phpdbadmin keeps accounts, connections (passwords encrypted) and the audit log here — not your data.',
        'appdb_sqlite' => 'SQLite file (recommended, nothing to enter)',
        'appdb_mysql' => 'MariaDB or MySQL',
        'appdb_pgsql' => 'PostgreSQL',
        'db_host' => 'Server',
        'db_port' => 'Port',
        'db_name' => 'Database',
        'db_user' => 'User',
        'db_password' => 'Password',
        'db_test' => 'Test connection',
        'db_ok' => 'Connected (:version).',
        'db_host_changed' => 'Connected via :host — taken over.',
        'db_missing' => 'The database “:name” does not exist yet.',
        'db_create' => 'Create it now',
        'db_created' => 'Database “:name” created.',
        'db_refused' => 'The server cannot be reached at :host.',
        'db_denied' => 'User or password is wrong.',
        'db_prefix' => 'At this host, database and user usually start with the account name and an underscore (e.g. :example).',
        'db_hint_host' => 'Server at :hoster usually: :host',
        'mail' => 'Email (sign-in codes, invitations)',
        'mail_hint' => 'Without email you can only sign in with a passkey. SMTP of your own mailbox is the most reliable.',
        'mail_smtp' => 'SMTP (recommended)',
        'mail_sendmail' => 'Server mail (sendmail)',
        'mail_later' => 'Set up later',
        'mail_host' => 'SMTP server',
        'mail_port' => 'Port',
        'mail_user' => 'User',
        'mail_password' => 'Password',
        'mail_from' => 'Sender',
        'mail_test' => 'Send a test email to my address',
        'mail_sent' => 'Test email is on its way — please check your inbox.',
        'mail_subject' => 'phpdbadmin: test email from the installer',
        'mail_body' => 'This email comes from the phpdbadmin installer. The settings work.',
        'advanced' => 'More settings',
        'location' => 'Where the program goes',
        'location_outside' => 'Outside the web folder (recommended): :path',
        'location_inside' => 'Inside the web folder, protected: :path',
        'allowed_hosts' => 'Database servers phpdbadmin may connect to',
        'allowed_hosts_hint' => 'Names, addresses or networks, separated by commas. Protects your host’s internal network.',
        'install' => 'Install phpdbadmin',
        'required' => 'Please fill in.',
        'run_download' => 'Download the release and verify it',
        'run_extract' => 'Unpack',
        'run_configure' => 'Write settings',
        'run_place' => 'Move files into place',
        'run_setup' => 'Set up database and account',
        'run_check' => 'Check the installation from outside',
        'run_finish' => 'Clean up',
        'done_title' => 'phpdbadmin is installed',
        'done_text' => 'Continue to the first setup: the link signs you in and creates your passkey. It is valid for 30 minutes.',
        'done_button' => 'Go to first setup',
        'done_existing' => 'There was an account already — sign in as usual.',
        'done_login' => 'Go to sign-in',
        'done_checks' => 'phpdbadmin’s system check',
        'done_cron' => 'For operation: set up a cron job',
        'done_cron_text' => 'In :panel under “Cron jobs” or “Scheduled tasks”, run every minute:',
        'done_cron_generic' => 'At your host, create a cron job that runs every minute:',
        'done_cron_worker' => 'Optional, for large exports in the background — also every minute:',
        'done_where' => 'Program: :core · Web folder: :docroot',
        'post_down' => 'phpdbadmin does not answer yet',
        'post_down_text' => 'There was no answer from phpdbadmin at :url. Reloading in a minute often helps; otherwise the error is in :log.',
        'post_exposed' => 'Warning: program files can be read from outside',
        'post_exposed_text' => ':url can be fetched. The web server ignores .htaccess. Move the folder :core outside the web folder right away or ask your host.',
        'error' => 'That did not work',
        'error_details' => 'Technical details',
        'error_retry' => 'Try again',
        'owner_busy' => 'The installation is running in another browser',
        'owner_busy_text' => 'Only one browser can operate this installer. It becomes free again after 30 minutes without activity.',
        'session_lost' => 'The installer session has expired. Please reload the page.',
        'err_mkdir' => 'The folder :path could not be created.',
        'err_copy' => 'The file :path could not be written.',
        'err_download' => 'Download failed: :reason',
        'err_size' => 'The file does not have the expected size — stopped.',
        'err_hash' => 'The checksum does not match — the file is damaged or altered. Stopped.',
        'err_signature' => 'The release signature is invalid — stopped. Nothing was installed.',
        'err_unsigned' => 'The release is not signed — this installer requires a signature. Stopped.',
        'err_manifest' => 'The release information is incomplete.',
        'err_zip' => 'The archive cannot be opened.',
        'err_zip_path' => 'The archive contains a forbidden path (:path) — stopped.',
        'err_zip_layout' => 'The archive does not have the expected layout.',
        'err_step' => 'This step comes too early. Please reload the page.',
        'err_bridge' => 'Setting up the program failed: :reason',
        'err_memory' => 'PHP ran out of memory. Raise memory_limit to 256 MB at your host.',
        'err_time' => 'PHP ran out of time. Raise max_execution_time to 120 s at your host and try again.',
        'err_space' => 'The server is out of disk space.',
        'err_permission' => 'PHP was not allowed to write a file. Check the folder permissions (755).',
        'err_db_driver' => 'The PHP driver for this database is missing.',
        'err_location' => 'The location :path is not writable.',
        'err_location_taken' => 'There is something at :path already.',
    ];
}

// ─── Hoster ──────────────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * Bekannte Hoster und Verwaltungsoberflächen. Stand der Hilfe der Hoster: 28.10.2026 (Recherche für den Installer von
 * Basalto, für phpdbadmin übernommen). Erkannt wird in drei Durchgängen — Rechnername, Pfad, Kennzeichen-Dateien —,
 * je in der Reihenfolge der Tabelle: Hoster vor den Oberflächen, die sie nutzen. `php_lines` sind die .htaccess-Zeilen
 * des Hosters für eine PHP-Version ({xx} = 85, {x.y} = 8.5); wirken müssen sie trotzdem erst im Testordner.
 */
final class PdaHosters
{
    const NONE = ['name' => '', 'host' => null, 'path' => null, 'os' => null, 'markers' => [], 'panel' => null, 'php' => null, 'ext' => null,
        'ssl' => null, 'docroot' => null, 'php_lines' => [], 'php_file' => '.htaccess', 'exclusive' => false, 'db_host' => null, 'db_hosts' => [],
        'db_hint' => null, 'prefix' => false, 'cron' => true, 'help' => null];

    const LINES_ALT_LITESPEED = ["<FilesMatch \"\\.(php|phtml)$\">\nSetHandler application/x-httpd-alt-php{xx}___lsphp\n</FilesMatch>", 'AddHandler application/x-httpd-alt-php{xx}___lsphp .php'];

    const LINES_ALT_APACHE = ['AddHandler application/x-httpd-alt-php{xx} .php .phtml', "<FilesMatch \"\\.(?:html|php)$\">\nSetHandler application/x-httpd-alt-php{xx}\n</FilesMatch>"];

    const LINES_EA_LITESPEED = ['AddHandler application/x-httpd-ea-php{xx}___lsphp .php .php8 .phtml'];

    const LINES_EA_APACHE = ["<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php{xx} .php .php8 .phtml\n</IfModule>"];

    const ALL = [
        // Schweiz
        'cyon' => ['name' => 'cyon', 'host' => '/\.cyon\.(net|ch)$/', 'panel' => 'my.cyon', 'php' => '«Erweitert» › «PHP-Versionsmanager»', 'ext' => '«Erweitert» › «PHP-Einstellungen»',
            'ssl' => '«Kostenlose SSL-Zertifikate» › «SSL verwalten»', 'docroot' => '«Webhosting» › «Domains»', 'php_lines' => ['AddHandler application/x-httpd-php{xx} .php'],
            'exclusive' => true, 'db_host' => 'localhost', 'prefix' => true, 'help' => 'https://www.cyon.ch/support/article/685-php-version-eines-verzeichnisses-andern'],
        'hostpoint' => ['name' => 'Hostpoint', 'host' => '/\.hostpoint\.ch$/', 'path' => '#^/home/[^/]+/(www/[^/]+|public_html)#', 'os' => 'FreeBSD', 'panel' => 'Hostpoint Control Panel',
            'php' => '«Websites» › «Web-Einstellungen»', 'ext' => '«Websites» › «Web-Einstellungen» › «PHP-Konfiguration»', 'ssl' => '«Websites» › «SSL-Verschlüsselung» › «FreeSSL»',
            'docroot' => '«Websites»', 'php_lines' => ['Use php-fpm php{xx}'], 'exclusive' => true, 'db_hosts' => ['{user}.mysql.db.hostpoint.ch', 'localhost'],
            'db_hint' => '{user}.mysql.db.hostpoint.ch', 'prefix' => true, 'help' => 'https://support.hostpoint.ch/de/produkte/webhosting/haeufig-gestellte-fragen/php-konfiguration'],
        'infomaniak' => ['name' => 'Infomaniak', 'host' => '/infomaniak/', 'path' => '#^/home/clients/[0-9a-f]{32}/#', 'panel' => 'Infomaniak Manager', 'php' => '«Advanced» › «Edit»',
            'ext' => '«Manage advanced settings» › «PHP / Apache»', 'ssl' => '«⋮» › «Install a certificate»', 'docroot' => '«Advanced settings» › «Manage»',
            'db_hint' => 'XXXX.myd.infomaniak.com', 'help' => 'https://www.infomaniak.com/en/support/faq/2053/change-the-php-version-for-a-website'],
        'metanet' => ['name' => 'Metanet', 'host' => '/(metanet\.ch|sui-inter\.net)$/', 'panel' => 'Plesk', 'php' => '«Websites & Domains» › «PHP-Einstellungen»',
            'ext' => '«Websites & Domains» › «PHP-Einstellungen»', 'ssl' => '«Websites & Domains» › «SSL/TLS-Zertifikate»', 'docroot' => '«Hosting-Einstellungen» › «Dokumentstamm»',
            'db_host' => 'localhost', 'help' => 'https://www.metanet.ch/de/support/webhosting-e-mail/php/plesk-php-version-und-konfiguration-aendern'],
        'green' => ['name' => 'green.ch', 'host' => '/\.servicehoster\.ch$/', 'path' => '#^/home/h\d+/#', 'panel' => 'my.green.ch', 'php' => '«Alle Domänen» › «Domains» › «Aktionen» › «PHP Einstellungen»',
            'ext' => '«Domains» › «Aktionen» › «PHP Einstellungen»', 'ssl' => '«WEBHOSTING» › Schloss-Symbol › «Let’s Encrypt»', 'docroot' => '«Domains» › «Aktionen» › «Domain bearbeiten» › «Dokumentenstamm»',
            'db_host' => 'localhost', 'help' => 'https://www.green.ch/de/php-version-und-einstellungen'],
        // Deutschland
        'allinkl' => ['name' => 'ALL-INKL.COM', 'path' => '#^/www/htdocs/w[0-9a-z]+/#', 'panel' => 'KAS', 'php' => '«Domain» › «Bearbeiten» › «PHP-Version»',
            'ssl' => '«Domain» › «Bearbeiten» › «SSL-Schutz»', 'docroot' => '«Domain» › «Bearbeiten» › «Ziel»', 'php_lines' => ['AddHandler php{xx}-cgi .php'], 'exclusive' => true,
            'db_host' => 'localhost', 'help' => 'https://all-inkl.com/wichtig/anleitungen/skripte/sonstiges/migration-der-php-version/pruefung-der-php-version-im-kas_436.html'],
        'ionos' => ['name' => 'IONOS', 'path' => '#^/(kunden/)?homepages/\d+/d\d+/htdocs#', 'panel' => 'IONOS', 'php' => '«Menü» › «Hosting» › «PHP» › «Verwalten»', 'ssl' => '«Menü» › «Domains & SSL»',
            'docroot' => '«Domains & SSL»', 'db_hint' => 'dbNNNNNNNNN.hosting-data.io', 'help' => 'https://www.ionos.de/hilfe/index.php?id=2912'],
        'strato' => ['name' => 'STRATO', 'path' => '#^/mnt/rid/\d+/\d+/\d+/htdocs#', 'panel' => 'STRATO Kunden-Login', 'php' => '«Datenbanken und Webspace» › «PHP-Version einstellen»',
            'ssl' => '«SSL verwalten»', 'docroot' => '«Domains» › «Domainverwaltung»', 'db_hint' => 'database-NNNNNNNNNN.webspace-host.com',
            'help' => 'https://www.strato.de/faq/hosting/wie-aendere-ich-meine-php-einstellungen-im-hosting-paket/'],
        'hetzner' => ['name' => 'Hetzner', 'host' => '/^www\d+\.your-server\.de$/', 'path' => '#^/usr/www/users/[^/]+/#', 'panel' => 'konsoleH', 'php' => '«Einstellungen» › «PHP-Konfiguration»',
            'ext' => '«Einstellungen» › «PHP-Konfiguration»', 'ssl' => '«Einstellungen» › «SSL Manager»',
            'php_lines' => ['FcgidWrapper "/home/httpd/cgi-bin/php{xx}-fcgi-starter.fcgi" .php'], 'exclusive' => true, 'db_hint' => 'mysqlNNN.your-server.de',
            'help' => 'https://docs.hetzner.com/de/managed/webserver/php-configuration/'],
        'netcup' => ['name' => 'netcup', 'path' => '#^/var/www/vhosts/hosting\d+\.[a-z0-9]+\.netcup\.net/#', 'panel' => 'netcup CCP', 'php' => '«Produkte» › Lupe › Domain › «PHP»',
            'ssl' => '«Websites & Domains» › «SSL/TLS-Zertifikate»', 'docroot' => '«Einstellungen bearbeiten» › «Dokumentenstamm ändern»',
            'help' => 'https://www.netcup.com/de/helpcenter/dokumentation/webhosting/php-einstellungen'],
        'hosteurope' => ['name' => 'Host Europe', 'host' => '/^wp\d+\.webpack\.hosteurope\.de$/', 'path' => '#^/is/htdocs/wp\d+_[A-Z0-9]+/#', 'panel' => 'Host Europe KIS',
            'php' => '«Konfigurieren» › «Skripte & Datenbanken» › «PHP-Versionen»', 'ext' => '«Skripte & Datenbanken» › «Skript-Einstellungen»', 'ssl' => '«Sicherheit & SSL» › «SSL administrieren»',
            'docroot' => '«Domains» › «Domainzuordnung & -verwaltung»', 'db_host' => 'localhost', 'help' => 'https://www.hosteurope.de/faq/webhosting/skripte/php-version-wechseln/'],
        'domainfactory' => ['name' => 'DomainFactory', 'path' => '#^/kunden/\d+_\d+/#', 'panel' => 'DomainFactory Kundenmenü', 'php' => '«PHP-Einstellungen» › «PHP-Version editieren»',
            'ssl' => '«SSL-Zertifikate»', 'docroot' => '«Subdomains» › «Editieren» › «Pfad der Subdomain»', 'db_host' => 'localhost', 'db_hosts' => ['localhost', 'mariadb106', 'mysql'],
            'help' => 'https://www.df.eu/de/support/df-faq/webhosting/skriptsprachen/php/'],
        // International
        'hostinger' => ['name' => 'Hostinger', 'path' => '#^/home/u\d+/(domains/[^/]+/)?public_html#', 'panel' => 'hPanel', 'php' => '«Websites» › «Dashboard» › «PHP Configuration»',
            'ext' => '«PHP Configuration» › «PHP extensions»', 'ssl' => '«Security» › «SSL»', 'php_lines' => ["<FilesMatch \"\\.(php4|php5|php3|php2|php|phtml)$\">\nSetHandler application/x-lsphp{xx}\n</FilesMatch>"],
            'exclusive' => true, 'db_host' => 'localhost', 'prefix' => true, 'help' => 'https://www.hostinger.com/support/1575755-how-to-change-the-php-version-of-your-hostinger-hosting-plan/'],
        'siteground' => ['name' => 'SiteGround', 'path' => '#^/home/customer/www/[^/]+/public_html#', 'panel' => 'Site Tools', 'php' => '«Devs» › «PHP Manager»',
            'ext' => '«Devs» › «PHP Manager» › «PHP Variables»', 'ssl' => '«Security» › «SSL Manager»', 'db_host' => 'localhost', 'help' => 'https://www.siteground.com/kb/what-is-managed-php'],
        'ovh' => ['name' => 'OVHcloud', 'path' => '#^/homez\.\d+/#', 'panel' => 'OVHcloud Control Panel', 'php' => '«Hosting plans» › «Global PHP version» › «Modify configuration»',
            'ssl' => '«SSL certificates»', 'docroot' => '«My sites» › «Edit site» › «Root folder»', 'php_file' => '.ovhconfig', 'php_lines' => ["app.engine=php\napp.engine.version={x.y}\ncontainer.image=stable64"],
            'exclusive' => true, 'db_hint' => 'NAME.mysql.db', 'help' => 'https://docs.ovhcloud.com/en/guides/web-cloud/web-hosting/configure-your-web-hosting'],
        'forge' => ['name' => 'Laravel Forge', 'path' => '#^/home/forge/#', 'markers' => ['/etc/nginx/sites-available/000-catch-all'], 'panel' => 'Laravel Forge', 'php' => 'Site › «Settings» › PHP',
            'ssl' => 'Site › «Domains»', 'db_host' => '127.0.0.1', 'help' => 'https://laravel.com/forge/docs/sites/the-basics'],
        'herd' => ['name' => 'Laravel Herd', 'path' => '#/Library/Application Support/Herd/|^/Users/[^/]+/(Herd|Development|Sites)/#', 'panel' => 'Herd', 'php' => '«Sites» › Site › «PHP»',
            'ssl' => '«Sites» › Site › «Secure»', 'db_host' => '127.0.0.1', 'cron' => false, 'help' => 'https://herd.laravel.com/docs/macos/getting-started/sites'],
        // Verwaltungsoberflächen
        'plesk' => ['name' => 'Plesk', 'path' => '#^/var/www/vhosts/#', 'markers' => ['/usr/local/psa'], 'panel' => 'Plesk', 'php' => '«Websites & Domains» › «PHP»',
            'ext' => '«Websites & Domains» › «PHP»', 'ssl' => '«SSL/TLS Certificates» › «Install»', 'docroot' => '«Hosting & DNS» › «Hosting» › «Document root»', 'db_host' => 'localhost',
            'help' => 'https://docs.plesk.com/en-US/obsidian/administrator-guide/website-management/websites-and-domains/hosting-settings/web-scripting-settings/php-settings.70742/'],
        'directadmin' => ['name' => 'DirectAdmin', 'path' => '#^/home\d*/[^/]+/domains/[^/]+/public_html#', 'markers' => ['/usr/local/directadmin'], 'panel' => 'DirectAdmin',
            'php' => '«Domain Setup» › «PHP Version Selector»', 'ext' => '«PHP Settings»', 'ssl' => '«SSL Certificates»', 'db_host' => 'localhost', 'prefix' => true,
            'help' => 'https://docs.directadmin.com/webservices/php/multiple-php.html'],
        'cpanel' => ['name' => 'cPanel', 'path' => '#^/home\d*/[^/]+/public_html#', 'markers' => ['/usr/local/cpanel'], 'panel' => 'cPanel', 'php' => '«Software» › «MultiPHP Manager» oder «Select PHP Version»',
            'ext' => '«Software» › «Select PHP Version» › «Extensions»', 'ssl' => '«Security» › «SSL/TLS Status» › «Run AutoSSL»', 'docroot' => '«Domains» › «Domains» › «Manage»',
            'db_host' => 'localhost', 'prefix' => true, 'help' => 'https://docs.cpanel.net/cpanel/software/multiphp-manager-for-cpanel/'],
    ];

    public static function profile(string $id): array
    {
        return array_replace(self::NONE, isset(self::ALL[$id]) ? self::ALL[$id] : []);
    }

    /** @return list<string> */
    public static function markers(): array
    {
        $markers = [];
        foreach (self::ALL as $entry) {
            foreach (isset($entry['markers']) ? $entry['markers'] : [] as $marker) {
                $markers[] = $marker;
            }
        }

        return array_values(array_unique($markers));
    }

    public static function detect(array $facts): string
    {
        $paths = array_filter([(string) $facts['docroot'], (string) $facts['dir']]);
        foreach (['host', 'path', 'markers'] as $signal) {
            foreach (self::ALL as $id => $entry) {
                if (isset($entry['os']) && $entry['os'] !== $facts['os']) {
                    continue;
                }
                if ($signal === 'host' && isset($entry['host']) && preg_match($entry['host'], (string) $facts['machine']) === 1) {
                    return $id;
                }
                if ($signal === 'path' && isset($entry['path'])) {
                    foreach ($paths as $path) {
                        if (preg_match($entry['path'], $path) === 1) {
                            return $id;
                        }
                    }
                }
                if ($signal === 'markers' && isset($entry['markers'])) {
                    foreach ($entry['markers'] as $marker) {
                        if (! empty($facts['markers'][$marker])) {
                            return $id;
                        }
                    }
                }
            }
        }

        return 'generic';
    }
}

// ─── Tatsachen ───────────────────────────────────────────────────────────────────────────────────────────────────────

final class PdaFacts
{
    /** Erweiterungen, die phpdbadmin zum Laufen braucht (ohne Datenbanktreiber) — wie `extensions` in latest.json. */
    const EXTENSIONS = ['ctype', 'dom', 'fileinfo', 'filter', 'iconv', 'libxml', 'mbstring', 'openssl', 'pdo', 'session', 'tokenizer'];

    /** Dateien, die ein Hoster in einen neuen Ordner legt — sie dürfen bleiben. */
    const ALLOWED = ['.htaccess', '.well-known', 'cgi-bin', '.user.ini', 'php.ini', '.ftpquota', 'error_log', '.DS_Store', 'latest.json', '.ovhconfig'];

    /** Eigene Dateien des Installers (Präfixe). */
    const OWN = ['.phpdbadmin', 'phpdbadmin-'];

    public static function collect(string $dir, string $self): array
    {
        $server = isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
        $sapi = PHP_SAPI;
        $home = self::home();
        $markers = [];
        foreach (PdaHosters::markers() as $marker) {
            $markers[$marker] = self::exists($marker);
        }
        $versions = [];
        foreach (['8.5', '8.6'] as $version) {
            $xx = str_replace('.', '', $version);
            $fpm = '';
            foreach (['/run/php/php'.$version.'-fpm.sock', '/var/run/php/php'.$version.'-fpm.sock', '/run/php-fpm/php'.$xx.'.sock'] as $socket) {
                if (self::exists($socket)) {
                    $fpm = $socket;
                    break;
                }
            }
            $versions[$version] = ['alt' => self::exists('/opt/alt/php'.$xx), 'ea' => self::exists('/opt/cpanel/ea-php'.$xx), 'plesk' => self::exists('/opt/plesk/php/'.$version), 'fpm' => $fpm];
        }
        $memory = pda_bytes((string) ini_get('memory_limit'));
        $free = @disk_free_space($dir);
        $url = self::url();

        return [
            'dir' => $dir,
            'self' => basename($self),
            'docroot' => isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/') : '',
            'machine' => (string) (function_exists('gethostname') ? gethostname() : php_uname('n')),
            'os' => PHP_OS_FAMILY,
            'server' => $server,
            'sapi' => $sapi,
            'php' => PHP_VERSION,
            'modern' => version_compare(PHP_VERSION, PDA_MIN_PHP, '>='),
            'missing' => array_values(array_filter(self::EXTENSIONS, function (string $ext): bool {
                return ! extension_loaded($ext);
            })),
            'drivers' => ['sqlite' => extension_loaded('pdo_sqlite'), 'mysql' => extension_loaded('pdo_mysql'), 'pgsql' => extension_loaded('pdo_pgsql')],
            'zip' => class_exists('ZipArchive'),
            'sodium' => function_exists('sodium_crypto_sign_verify_detached'),
            'download' => function_exists('curl_init') || ((bool) ini_get('allow_url_fopen') && extension_loaded('openssl')),
            'writable' => is_writable($dir),
            'free' => $free === false ? null : (int) $free,
            'memory' => $memory,
            'time' => (int) ini_get('max_execution_time'),
            'https' => $url['https'],
            'host' => $url['host'],
            'path' => $url['path'],
            'url' => $url['url'],
            'home' => $home,
            'home_writable' => $home !== '' && is_writable($home),
            'htaccess' => ! (pda_contains(strtolower($server), 'nginx') && ! pda_contains(strtolower($server), 'apache')),
            'litespeed' => pda_contains(strtolower($server), 'litespeed') || $sapi === 'litespeed',
            'markers' => $markers,
            'versions' => $versions,
            'user' => self::user(),
            'below_public_html' => self::belowPublicHtml($dir),
            'entries' => self::entries($dir, basename($self)),
            'installed' => self::installed($dir),
            'local_release' => is_file($dir.'/latest.json'),
        ];
    }

    /** @return array{https: bool, host: string, path: string, url: string} */
    public static function url(): array
    {
        $https = (! empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443');
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : 'localhost';
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host) ?: 'localhost';
        $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/';
        $path = rtrim(str_replace('\\', '/', dirname($script)), '/');

        return ['https' => $https, 'host' => $host, 'path' => $path === '' ? '/' : $path, 'url' => ($https ? 'https://' : 'http://').$host.($path === '' ? '' : $path)];
    }

    public static function home(): string
    {
        $home = (string) getenv('HOME');
        if ($home === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            $home = is_array($info) && isset($info['dir']) ? (string) $info['dir'] : '';
        }

        if ($home === '' || ! self::exists($home)) {
            return '';
        }
        // Wie __DIR__ ohne symbolische Links (macOS: /var → /private/var), sonst passt der Vergleich nie.
        $real = @realpath($home);

        return rtrim($real !== false ? $real : $home, '/');
    }

    private static function user(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (is_array($info) && isset($info['name'])) {
                return (string) $info['name'];
            }
        }

        return (string) get_current_user();
    }

    /** Wie tief liegt der Ordner unter public_html? (cyon: der Versionsmanager reicht nur eine Ebene.) */
    private static function belowPublicHtml(string $dir): ?string
    {
        $position = strpos($dir, '/public_html/');

        return $position === false ? null : substr($dir, $position + strlen('/public_html/'));
    }

    /** @return list<string> fremde Einträge im Ordner */
    private static function entries(string $dir, string $self): array
    {
        $foreign = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === $self || in_array($entry, self::ALLOWED, true)) {
                continue;
            }
            foreach (self::OWN as $prefix) {
                if (pda_starts($entry, $prefix)) {
                    continue 2;
                }
            }
            $foreign[] = $entry;
        }

        return $foreign;
    }

    private static function installed(string $dir): bool
    {
        $index = $dir.'/index.php';

        return is_file($index) && pda_contains((string) @file_get_contents($index, false, null, 0, 2000), 'phpdbadmin-installer:index');
    }

    /** Gibt es den Pfad? — ohne Warnung bei open_basedir. */
    public static function exists(string $path): bool
    {
        $restriction = (string) ini_get('open_basedir');
        if ($restriction !== '') {
            $allowed = false;
            foreach (explode(PATH_SEPARATOR, $restriction) as $base) {
                if ($base !== '' && pda_starts($path, rtrim($base, '/'))) {
                    $allowed = true;
                    break;
                }
            }
            if (! $allowed) {
                return false;
            }
        }

        return @file_exists($path);
    }
}

// ─── Diagnose ────────────────────────────────────────────────────────────────────────────────────────────────────────

final class PdaDiagnosis
{
    /** @var array */
    private $facts;

    /** @var string */
    public $hoster;

    /** @var array */
    private $profile;

    public function __construct(array $facts)
    {
        $this->facts = $facts;
        $this->hoster = PdaHosters::detect($facts);
        $this->profile = PdaHosters::profile($this->hoster);
    }

    public function hosterName(): string
    {
        return $this->hoster === 'generic' ? PdaText::t('hoster_generic') : (string) $this->profile['name'];
    }

    public function profile(): array
    {
        return $this->profile;
    }

    /** Schritte im Verwaltungsbereich für einen Bereich (php, ext, ssl, docroot). */
    public function steps(string $area): array
    {
        $menu = $this->profile[$area];
        $generic = [
            'php' => PdaText::t('menu_generic_php', ['min' => PDA_MIN_PHP]),
            'ext' => PdaText::t('menu_generic_ext'),
            'ssl' => PdaText::t('menu_generic_ssl'),
            'docroot' => PdaText::t('menu_generic_docroot', ['dir' => $this->facts['dir']]),
        ];
        $steps = [];
        if (is_string($menu) && $menu !== '') {
            $steps[] = PdaText::t('menu_in', ['panel' => (string) $this->profile['panel'], 'menu' => $menu]);
            if ($area === 'docroot') {
                $steps[] = $generic['docroot'];
            }
        } else {
            $steps[] = $generic[$area];
        }
        $steps[] = PdaText::t('then_reload');

        return $steps;
    }

    /**
     * .htaccess-Zeilen, die diesen Ordner auf PHP 8.5+ stellen könnten — höchstens sechs, je nach Hoster und dem,
     * was auf dem Server liegt. Welche wirkt, zeigt der Testordner. Ohne .htaccess (nginx) keine.
     *
     * @return list<array{version: string, file: string, lines: list<string>}>
     */
    public function phpCandidates(): array
    {
        if (! $this->facts['htaccess']) {
            return [];
        }
        $candidates = [];
        foreach ($this->facts['versions'] as $version => $have) {
            $templates = $this->profile['php_lines'];
            if (! $this->profile['exclusive']) {
                if ($have['ea']) {
                    $templates = array_merge($templates, $this->facts['litespeed'] ? PdaHosters::LINES_EA_LITESPEED : PdaHosters::LINES_EA_APACHE);
                }
                if ($have['alt']) {
                    $templates = array_merge($templates, $this->facts['litespeed'] ? PdaHosters::LINES_ALT_LITESPEED : PdaHosters::LINES_ALT_APACHE);
                }
                if ($have['fpm'] !== '' && ! $this->facts['litespeed']) {
                    $templates[] = "<FilesMatch \"\\.php$\">\n  SetHandler \"proxy:unix:".$have['fpm']."|fcgi://localhost\"\n</FilesMatch>";
                }
            }
            foreach ($templates as $template) {
                $candidates[] = ['version' => (string) $version, 'file' => (string) $this->profile['php_file'],
                    'lines' => explode("\n", strtr($template, ['{xx}' => str_replace('.', '', (string) $version), '{x.y}' => (string) $version]))];
            }
        }
        $seen = [];
        $unique = [];
        foreach ($candidates as $candidate) {
            $key = implode("\n", $candidate['lines']);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $candidate;
            }
        }

        return array_slice($unique, 0, 6);
    }

    /** Wohin das Programm kommt: ausserhalb des Webordners, wenn möglich. */
    public function location(): array
    {
        $f = $this->facts;
        $inside = $f['dir'].'/.phpdbadmin';
        $site = preg_replace('/[^a-z0-9.\-]/', '-', strtolower(preg_replace('/:\d+$/', '', $f['host']))) ?: 'site';
        $outside = '';
        if ($f['home_writable'] && $f['home'] !== '' && pda_starts($f['dir'], $f['home'].'/') && $f['dir'] !== $f['home']) {
            $outside = $f['home'].'/phpdbadmin-app/'.$site;
        }

        return ['outside' => $outside, 'inside' => $inside, 'default' => $outside !== '' ? 'outside' : 'inside'];
    }

    /**
     * Befunde: blockierende zuerst. Jeder mit Titel, Text, Schritten beim Hoster, ggf. einer Lösung.
     *
     * @return list<array<string, mixed>>
     */
    public function problems(): array
    {
        $f = $this->facts;
        $problems = [];
        if ($f['installed']) {
            $problems[] = $this->problem('installed', 'fail', PdaText::t('p_installed_title'), PdaText::t('p_installed_text', ['file' => $f['self']]), [], null, false);

            return $problems;
        }
        if ($f['path'] !== '/') {
            $problems[] = $this->problem('subfolder', 'fail', PdaText::t('p_subfolder_title'), PdaText::t('p_subfolder_text', ['path' => $f['path']]), $this->steps('docroot'), null, true);
        }
        if (! $f['modern']) {
            $candidates = $this->phpCandidates();
            $text = PdaText::t('p_php_text', ['min' => PDA_MIN_PHP]);
            $notes = [];
            $versions = array_keys(array_filter($f['versions'], function (array $have): bool {
                return $have['alt'] || $have['ea'] || $have['plesk'] || $have['fpm'] !== '';
            }));
            if ($versions !== []) {
                $notes[] = PdaText::t('p_php_fixable', ['versions' => implode(', ', $versions)]);
            }
            if ($this->hoster === 'cyon' && $f['below_public_html'] !== null && substr_count(trim((string) $f['below_public_html'], '/'), '/') >= 1) {
                $notes[] = PdaText::t('p_php_cyon_depth', ['depth' => substr_count(trim((string) $f['below_public_html'], '/'), '/') + 1]);
            }
            $problems[] = $this->problem('php', 'fail', PdaText::t('p_php_title', ['have' => PHP_VERSION]), $text, $this->steps('php'), $candidates === [] ? null : 'php', true, $notes, ['count' => count($candidates)]);
        }
        if ($f['missing'] !== []) {
            $problems[] = $this->problem('ext', 'fail', PdaText::t('p_ext_title', ['list' => implode(', ', $f['missing'])]), PdaText::t('p_ext_text'), $this->steps('ext'), null, true);
        }
        if (! in_array(true, $f['drivers'], true)) {
            $problems[] = $this->problem('driver', 'fail', PdaText::t('p_driver_title'), PdaText::t('p_driver_text'), $this->steps('ext'), null, true);
        } else {
            if (! $f['drivers']['sqlite'] && ($f['drivers']['mysql'] || $f['drivers']['pgsql'])) {
                $problems[] = $this->problem('sqlite', 'warn', PdaText::t('p_sqlite_title'), PdaText::t('p_sqlite_text'), [], null, false);
            }
            $names = ['mysql' => 'MariaDB/MySQL', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite'];
            $have = [];
            $missing = [];
            foreach ($names as $key => $name) {
                if ($f['drivers'][$key]) {
                    $have[] = $name;
                } else {
                    $missing[] = 'pdo_'.$key;
                }
            }
            if ($missing !== []) {
                $problems[] = $this->problem('targets', 'warn', PdaText::t('p_targets_title', ['list' => implode(', ', $have)]), PdaText::t('p_targets_text', ['missing' => implode(', ', $missing)]), $this->steps('ext'), null, false);
            }
        }
        if (! $f['zip']) {
            $problems[] = $this->problem('zip', 'fail', PdaText::t('p_zip_title'), PdaText::t('p_zip_text'), $this->steps('ext'), null, true);
        }
        if (pda_public_key() !== '' && ! $f['sodium']) {
            $problems[] = $this->problem('sodium', 'fail', PdaText::t('p_sodium_title'), PdaText::t('p_sodium_text'), $this->steps('ext'), null, true);
        }
        if (! $f['download'] && ! $f['local_release']) {
            $problems[] = $this->problem('download', 'fail', PdaText::t('p_download_title'), PdaText::t('p_download_text'), [], null, true);
        }
        if (! $f['writable']) {
            $problems[] = $this->problem('writable', 'fail', PdaText::t('p_writable_title'), PdaText::t('p_writable_text', ['dir' => $f['dir']]), [], null, true);
        }
        if ($f['free'] !== null && $f['free'] < PDA_DISK_MB * 1048576) {
            $problems[] = $this->problem('disk', 'fail', PdaText::t('p_disk_title', ['free' => pda_megabytes($f['free'])]), PdaText::t('p_disk_text', ['need' => PDA_DISK_MB.' MB']), [], null, true);
        }
        if ($f['entries'] !== []) {
            $list = implode(', ', array_slice($f['entries'], 0, 6)).(count($f['entries']) > 6 ? ' …' : '');
            $problems[] = $this->problem('files', 'fail', PdaText::t('p_files_title'), PdaText::t('p_files_text', ['list' => $list]), [], 'move', false);
        }
        $location = $this->location();
        if (! $f['htaccess']) {
            $problems[] = $location['outside'] === ''
                ? $this->problem('nginx', 'fail', PdaText::t('p_nginx_title', ['server' => $f['server']]), PdaText::t('p_nginx_fail_text', ['home' => $f['home'] ?: '–']), $this->steps('docroot'), null, true)
                : $this->problem('nginx', 'info', PdaText::t('p_nginx_title', ['server' => $f['server']]), PdaText::t('p_nginx_text'), [], null, false);
        }
        if (! $f['https'] && ! preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $f['host'])) {
            $problems[] = $this->problem('https', 'warn', PdaText::t('p_https_title'), PdaText::t('p_https_text', ['host' => preg_replace('/:\d+$/', '', $f['host'])]), $this->steps('ssl'), null, false);
        }
        if ($f['memory'] !== -1 && $f['memory'] < 256 * 1048576) {
            $level = $f['memory'] < 128 * 1048576 ? 'fail' : 'warn';
            $problems[] = $this->problem('memory', $level, PdaText::t('p_memory_title', ['limit' => pda_megabytes($f['memory'])]), PdaText::t('p_memory_text'), $this->steps('ext'), null, $level === 'fail');
        }
        if ($f['time'] > 0 && $f['time'] < 30) {
            $problems[] = $this->problem('time', 'warn', PdaText::t('p_time_title', ['seconds' => $f['time']]), PdaText::t('p_time_text'), $this->steps('ext'), null, false);
        }
        $order = ['fail' => 0, 'warn' => 1, 'info' => 2];
        usort($problems, function (array $a, array $b) use ($order): int {
            return $order[$a['level']] - $order[$b['level']];
        });

        return $problems;
    }

    private function problem(string $id, string $level, string $title, string $text, array $steps, ?string $fix, bool $support, array $notes = [], array $data = []): array
    {
        return ['id' => $id, 'level' => $level, 'title' => $title, 'text' => $text, 'steps' => $steps, 'fix' => $fix, 'support' => $support, 'notes' => $notes, 'data' => $data];
    }

    /** Fertige Nachricht an den Support — mit allen Angaben, die er braucht. */
    public function supportMessage(array $problems): string
    {
        $lines = [];
        foreach ($problems as $problem) {
            if ($problem['support']) {
                $lines[] = '- '.$problem['title'].': '.$problem['text'];
            }
        }

        return PdaText::t('support_text', ['repo' => pda_repository(), 'url' => $this->facts['url'], 'problems' => implode("\n", $lines),
            'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'server' => $this->facts['server'] ?: '–', 'dir' => $this->facts['dir']]);
    }

    /** Kandidaten für den Datenbank-Server, der Reihe nach. */
    public function databaseHosts(string $given, string $database): array
    {
        $hosts = [$given];
        foreach ($this->profile['db_hosts'] as $template) {
            $hosts[] = strtr($template, ['{user}' => $this->facts['user'], '{db}' => $database]);
        }
        if ($given === 'localhost') {
            $hosts[] = '127.0.0.1';
        } elseif ($given === '127.0.0.1') {
            $hosts[] = 'localhost';
        }
        if (is_string($this->profile['db_host'])) {
            $hosts[] = $this->profile['db_host'];
        }

        return array_values(array_unique(array_filter($hosts, function (string $host): bool {
            return $host !== '';
        })));
    }
}

// ─── HTTP ────────────────────────────────────────────────────────────────────────────────────────────────────────────

final class PdaHttp
{
    /** Holt eine Adresse (nur HTTPS, Weiterleitungen nur zu github.com und dessen Ablagen) — als Text oder in eine Datei. */
    public static function get(string $url, ?string $toFile = null, int $timeout = 30): string
    {
        if (! preg_match('#^https://#', $url)) {
            throw new RuntimeException('HTTPS required');
        }
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            $file = null;
            $options = [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'phpdbadmin-installer', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FAILONERROR => true];
            if (defined('CURLOPT_PROTOCOLS')) {
                $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
                $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
            }
            if ($toFile !== null) {
                $file = fopen($toFile, 'wb');
                if ($file === false) {
                    throw new RuntimeException(PdaText::t('err_copy', ['path' => $toFile]));
                }
                $options[CURLOPT_FILE] = $file;
            } else {
                $options[CURLOPT_RETURNTRANSFER] = true;
            }
            curl_setopt_array($handle, $options);
            $body = curl_exec($handle);
            $error = curl_error($handle);
            $effective = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
            curl_close($handle);
            if ($file !== null) {
                fclose($file);
            }
            if ($body === false) {
                throw new RuntimeException($error !== '' ? $error : 'HTTP');
            }
            self::guardHost($effective);

            return $toFile !== null ? '' : (string) $body;
        }
        $context = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => 'phpdbadmin-installer', 'follow_location' => 1, 'max_redirects' => 5, 'ignore_errors' => false],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $last = error_get_last();
            throw new RuntimeException($last !== null ? (string) $last['message'] : 'HTTP');
        }
        if ($toFile !== null) {
            if (@file_put_contents($toFile, $body) === false) {
                throw new RuntimeException(PdaText::t('err_copy', ['path' => $toFile]));
            }

            return '';
        }

        return $body;
    }

    private static function guardHost(string $url): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== '' && $host !== 'github.com' && $host !== 'api.github.com' && ! preg_match('/(^|\.)githubusercontent\.com$/', $host)) {
            throw new RuntimeException('Unexpected host '.$host);
        }
    }
}

// ─── Installer ───────────────────────────────────────────────────────────────────────────────────────────────────────

final class PdaInstaller
{
    /** @var string */
    private $dir;

    /** @var string */
    private $file;

    /** @var array|null */
    private $facts = null;

    public function __construct(string $dir, string $file)
    {
        $this->dir = $dir;
        $this->file = $file;
    }

    public function handle(): void
    {
        PdaText::detect();
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') === 'POST') {
            $this->action(isset($_POST['action']) ? (string) $_POST['action'] : '');

            return;
        }
        $this->page();
    }

    private function facts(): array
    {
        if ($this->facts === null) {
            $this->facts = PdaFacts::collect($this->dir, $this->file);
        }

        return $this->facts;
    }

    // ── Besitz: der erste Browser, der eine Aktion auslöst, bedient den Installer ──

    private function state(): array
    {
        $path = $this->dir.'/'.PDA_STATE;
        if (! is_file($path)) {
            return [];
        }
        $raw = (string) @file_get_contents($path);
        $json = json_decode(substr($raw, strlen(PDA_STATE_GUARD)), true);

        return is_array($json) ? $json : [];
    }

    private function save(array $state): void
    {
        $state['seen'] = time();
        if (@file_put_contents($this->dir.'/'.PDA_STATE, PDA_STATE_GUARD.json_encode($state), LOCK_EX) === false) {
            throw new RuntimeException(PdaText::t('p_writable_text', ['dir' => $this->dir]));
        }
    }

    /** Besitz prüfen oder übernehmen; gibt den Zustand zurück. */
    private function claim(): array
    {
        $state = $this->state();
        $cookie = isset($_COOKIE['pda_owner']) ? (string) $_COOKIE['pda_owner'] : '';
        $posted = isset($_POST['token']) ? (string) $_POST['token'] : '';
        $owner = isset($state['owner']) ? (string) $state['owner'] : '';
        $fresh = isset($state['seen']) && time() - (int) $state['seen'] < PDA_IDLE;
        if ($owner !== '' && $fresh) {
            if ($cookie === '' || ! hash_equals($owner, hash('sha256', $cookie)) || ! hash_equals($cookie, $posted)) {
                $this->json(['ok' => false, 'error' => PdaText::t('owner_busy'), 'hint' => PdaText::t('owner_busy_text'), 'busy' => true], 409);
            }

            return $state;
        }
        // Frei (noch nie oder seit 30 Minuten still): dieser Browser übernimmt — nur mit dem Formular-Token aus der Seite.
        if ($posted === '' || ! hash_equals($posted, $cookie)) {
            $this->json(['ok' => false, 'error' => PdaText::t('session_lost'), 'lost' => true], 403);
        }
        $state = ['owner' => hash('sha256', $cookie)];
        $this->save($state);

        return $state;
    }

    // ── Aktionen ──

    private function action(string $action): void
    {
        try {
            if ($action === 'release') {
                $this->json(['ok' => true, 'release' => $this->manifestSummary($this->manifest())]);
            }
            $state = $this->claim();
            switch ($action) {
                case 'fix_php_probe':
                    $this->json($this->phpProbe((int) (isset($_POST['candidate']) ? $_POST['candidate'] : 0)));
                    // no break
                case 'fix_php_apply':
                    $this->json($this->phpApply((int) (isset($_POST['candidate']) ? $_POST['candidate'] : 0)));
                    // no break
                case 'fix_php_cleanup':
                    $this->cleanupProbes();
                    $this->json(['ok' => true]);
                    // no break
                case 'fix_move':
                    $this->json($this->moveAside());
                    // no break
                case 'db_test':
                    $this->json($this->dbTest(false));
                    // no break
                case 'db_create':
                    $this->json($this->dbTest(true));
                    // no break
                case 'mail_test':
                    $this->json($this->mailTest());
                    // no break
                case 'download':
                    $this->json($this->download($state));
                    // no break
                case 'extract':
                    $this->json($this->extract($state));
                    // no break
                case 'configure':
                    $this->json($this->configure($state));
                    // no break
                case 'place':
                    $this->json($this->place($state));
                    // no break
                case 'setup':
                    $this->json($this->setup($state));
                    // no break
                case 'finish':
                    $this->json($this->finish($state));
                    // no break
                default:
                    $this->json(['ok' => false, 'error' => 'unknown action'], 400);
            }
        } catch (Throwable $e) {
            $this->json(['ok' => false] + self::explain($e), 200);
        }
    }

    /** Fehler in Klartext mit Abhilfe; die technische Meldung aufklappbar. */
    private static function explain(Throwable $e): array
    {
        $message = $e->getMessage();
        $lower = strtolower($message);
        $hint = null;
        if (pda_contains($lower, 'allowed memory size')) {
            $hint = PdaText::t('err_memory');
        } elseif (pda_contains($lower, 'maximum execution time')) {
            $hint = PdaText::t('err_time');
        } elseif (pda_contains($lower, 'no space left')) {
            $hint = PdaText::t('err_space');
        } elseif (pda_contains($lower, 'permission denied')) {
            $hint = PdaText::t('err_permission');
        } elseif (pda_contains($lower, 'could not find driver')) {
            $hint = PdaText::t('err_db_driver');
        }

        return ['error' => $e instanceof RuntimeException && $hint === null ? $message : PdaText::t('error'), 'hint' => $hint, 'details' => get_class($e).': '.$message];
    }

    // ── Release ──

    /** Manifest: liegt latest.json neben dem Installer, gilt es (ohne Netz); sonst das neueste Release auf GitHub. */
    private function manifest(): array
    {
        $local = $this->dir.'/latest.json';
        if (is_file($local)) {
            $manifest = json_decode((string) file_get_contents($local), true);
            $source = 'local';
        } else {
            $url = 'https://github.com/'.pda_repository().'/releases/latest/download/latest.json';
            try {
                $manifest = json_decode(PdaHttp::get($url, null, 20), true);
            } catch (Throwable $e) {
                throw new RuntimeException(PdaText::t('release_failed', ['reason' => $e->getMessage()]).' '.PdaText::t('release_failed_hint'));
            }
            $source = 'github';
        }
        if (! is_array($manifest) || ($manifest['format'] ?? '') !== 'phpdbadmin-release:1' || ! isset($manifest['version'], $manifest['file'], $manifest['size'], $manifest['sha256'])
            || ! preg_match('/^phpdbadmin-[0-9A-Za-z.\-]+\.zip$/', (string) $manifest['file']) || ! preg_match('/^[0-9a-f]{64}$/', (string) $manifest['sha256'])) {
            throw new RuntimeException(PdaText::t('err_manifest'));
        }
        $manifest['source'] = $source;

        return $manifest;
    }

    private function manifestSummary(array $manifest): array
    {
        return ['version' => (string) $manifest['version'], 'size' => pda_megabytes((int) $manifest['size']),
            'signed' => ! empty($manifest['signature']), 'local' => $manifest['source'] === 'local',
            'text' => PdaText::t('release_found', ['version' => $manifest['version'], 'size' => pda_megabytes((int) $manifest['size']),
                'signed' => $manifest['source'] === 'local' ? PdaText::t('release_local').', '.PdaText::t(! empty($manifest['signature']) ? 'release_signed' : 'release_unsigned')
                    : PdaText::t(! empty($manifest['signature']) ? 'release_signed' : 'release_unsigned')])];
    }

    /** Echtheit: Ed25519 über «phpdbadmin-release:1\nversion\ndatei\ngrösse\nsha256». */
    public static function verifySignature(array $manifest, string $publicKey): bool
    {
        if ($publicKey === '') {
            return true;
        }
        if (empty($manifest['signature'])) {
            throw new RuntimeException(PdaText::t('err_unsigned'));
        }
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException(PdaText::t('p_sodium_text'));
        }
        $message = "phpdbadmin-release:1\n".$manifest['version']."\n".$manifest['file']."\n".$manifest['size']."\n".$manifest['sha256'];
        $signature = base64_decode((string) $manifest['signature'], true);
        $key = base64_decode($publicKey, true);
        if ($signature === false || $key === false || strlen($signature) !== 64 || strlen($key) !== 32) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $message, $key);
    }

    private function download(array $state): array
    {
        $manifest = $this->manifest();
        if (! self::verifySignature($manifest, pda_public_key())) {
            throw new RuntimeException(PdaText::t('err_signature'));
        }
        $target = $this->dir.'/.phpdbadmin-download.zip';
        if ($manifest['source'] === 'local') {
            $local = $this->dir.'/'.$manifest['file'];
            if (! is_file($local) || ! @copy($local, $target)) {
                throw new RuntimeException(PdaText::t('err_download', ['reason' => $manifest['file']]));
            }
        } else {
            $tag = isset($manifest['tag']) ? (string) $manifest['tag'] : 'v'.$manifest['version'];
            $url = 'https://github.com/'.pda_repository().'/releases/download/'.rawurlencode($tag).'/'.rawurlencode((string) $manifest['file']);
            try {
                PdaHttp::get($url, $target, 240);
            } catch (Throwable $e) {
                @unlink($target);
                throw new RuntimeException(PdaText::t('err_download', ['reason' => $e->getMessage()]));
            }
        }
        if (filesize($target) !== (int) $manifest['size']) {
            @unlink($target);
            throw new RuntimeException(PdaText::t('err_size'));
        }
        if (! hash_equals((string) $manifest['sha256'], (string) hash_file('sha256', $target))) {
            @unlink($target);
            throw new RuntimeException(PdaText::t('err_hash'));
        }
        $state['step'] = 'download';
        $state['version'] = (string) $manifest['version'];
        $this->save($state);

        return ['ok' => true];
    }

    /** Entpacken in einen Zwischenordner — jeder Pfad geprüft, keine symbolischen Links. */
    private function extract(array $state): array
    {
        $this->expect($state, 'download');
        $zip = new ZipArchive;
        if ($zip->open($this->dir.'/.phpdbadmin-download.zip') !== true) {
            throw new RuntimeException(PdaText::t('err_zip'));
        }
        $staging = $this->dir.'/.phpdbadmin-staging';
        pda_remove($staging, $this->dir);
        if (! @mkdir($staging, 0755, true)) {
            throw new RuntimeException(PdaText::t('err_mkdir', ['path' => $staging]));
        }
        $root = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $normalized = str_replace('\\', '/', $name);
            if ($normalized === '' || $normalized[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $normalized) || pda_contains($normalized, "\0") || preg_match('/^[A-Za-z]:/', $normalized)) {
                $zip->close();
                throw new RuntimeException(PdaText::t('err_zip_path', ['path' => $name]));
            }
            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes) && $opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                $zip->close();
                throw new RuntimeException(PdaText::t('err_zip_path', ['path' => $name]));
            }
            $top = explode('/', $normalized)[0];
            if ($root === null) {
                $root = $top;
            } elseif ($top !== $root) {
                $zip->close();
                throw new RuntimeException(PdaText::t('err_zip_layout'));
            }
        }
        if ($root === null || ! preg_match('/^phpdbadmin-[0-9A-Za-z.\-]+$/', $root) || ! $zip->extractTo($staging)) {
            $zip->close();
            throw new RuntimeException(PdaText::t('err_zip_layout'));
        }
        $zip->close();
        $core = $staging.'/'.$root;
        foreach (['artisan', 'bootstrap/app.php', 'bootstrap/phpdbadmin-install.php', 'vendor/autoload.php', 'public/build/manifest.json'] as $required) {
            if (! is_file($core.'/'.$required)) {
                throw new RuntimeException(PdaText::t('err_zip_layout').' ('.$required.')');
            }
        }
        $state['step'] = 'extract';
        $state['staged'] = $core;
        $this->save($state);

        return ['ok' => true];
    }

    /** .env schreiben — Schlüssel neu, Adresse aus dem Browser, Datenbank und Mail aus dem Formular. */
    private function configure(array $state): array
    {
        $this->expect($state, 'extract');
        $in = $this->input();
        $facts = $this->facts();
        $diagnosis = new PdaDiagnosis($facts);
        $location = $diagnosis->location();
        $choice = $in['location'] === 'inside' || $location['outside'] === '' ? 'inside' : 'outside';
        $core = $choice === 'outside' ? $location['outside'] : $location['inside'];
        if (file_exists($core)) {
            throw new RuntimeException(PdaText::t('err_location_taken', ['path' => $core]));
        }
        $parent = dirname($core);
        if (! is_dir($parent) && ! @mkdir($parent, 0755, true)) {
            throw new RuntimeException(PdaText::t('err_location', ['path' => $parent]));
        }
        if (! is_writable($parent)) {
            throw new RuntimeException(PdaText::t('err_location', ['path' => $parent]));
        }
        $env = [
            'APP_NAME' => 'phpdbadmin',
            'APP_ENV' => 'production',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_DEBUG' => 'false',
            'APP_URL' => rtrim($facts['url'], '/'),
            'APP_LOCALE' => $in['locale'],
            'APP_FALLBACK_LOCALE' => $in['locale'] === 'de' ? 'en' : 'de',
            'APP_MAINTENANCE_DRIVER' => 'file',
            'LOG_CHANNEL' => 'daily',
            'LOG_LEVEL' => 'warning',
            'SESSION_DRIVER' => 'database',
            'SESSION_LIFETIME' => '120',
            'SESSION_SECURE_COOKIE' => $facts['https'] ? 'true' : 'false',
            'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'database',
            'FILESYSTEM_DISK' => 'local',
        ];
        if ($in['db'] === 'sqlite') {
            $env['DB_CONNECTION'] = 'sqlite';
            $env['DB_DATABASE'] = $core.'/database/database.sqlite';
            if (! is_file($state['staged'].'/database/database.sqlite')) {
                @touch($state['staged'].'/database/database.sqlite');
            }
        } else {
            $env['DB_CONNECTION'] = $in['db'] === 'pgsql' ? 'pgsql' : ($in['db_kind'] === 'mariadb' ? 'mariadb' : 'mysql');
            $env['DB_HOST'] = $in['db_host'];
            $env['DB_PORT'] = $in['db_port'];
            $env['DB_DATABASE'] = $in['db_name'];
            $env['DB_USERNAME'] = $in['db_user'];
            $env['DB_PASSWORD'] = $in['db_password'];
        }
        if ($in['mail'] === 'smtp') {
            $env['MAIL_MAILER'] = 'smtp';
            $env['MAIL_SCHEME'] = $in['mail_port'] === '465' ? 'smtps' : 'smtp';
            $env['MAIL_HOST'] = $in['mail_host'];
            $env['MAIL_PORT'] = $in['mail_port'];
            $env['MAIL_USERNAME'] = $in['mail_user'];
            $env['MAIL_PASSWORD'] = $in['mail_password'];
        } else {
            $env['MAIL_MAILER'] = $in['mail'] === 'sendmail' ? 'sendmail' : 'log';
        }
        $env['MAIL_FROM_ADDRESS'] = $in['mail_from'] !== '' ? $in['mail_from'] : $in['email'];
        $env['MAIL_FROM_NAME'] = 'phpdbadmin';
        $env['PHPDBADMIN_ALLOWED_HOSTS'] = $in['allowed_hosts'];
        $lines = ['# phpdbadmin — geschrieben vom Web-Installer am '.gmdate('Y-m-d H:i').' UTC. APP_KEY nie ändern: Er verschlüsselt die Passwörter der Verbindungen.'];
        foreach ($env as $key => $value) {
            $lines[] = $key.'='.self::envValue((string) $value);
        }
        if (@file_put_contents($state['staged'].'/.env', implode("\n", $lines)."\n") === false) {
            throw new RuntimeException(PdaText::t('err_copy', ['path' => '.env']));
        }
        @chmod($state['staged'].'/.env', 0600);
        $state['step'] = 'configure';
        $state['core'] = $core;
        $state['layout'] = $choice;
        $this->save($state);

        return ['ok' => true];
    }

    /** Programm an seinen Platz, Inhalt von public/ in den Webordner, index.php und .htaccess dazu. */
    private function place(array $state): array
    {
        $this->expect($state, 'configure');
        $staged = (string) $state['staged'];
        $core = (string) $state['core'];
        if (! @rename($staged, $core)) {
            pda_copy($staged, $core);
            pda_remove($staged, $this->dir);
        }
        foreach (scandir($core.'/public') ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'index.php' || $entry === '.htaccess') {
                continue;
            }
            pda_copy($core.'/public/'.$entry, $this->dir.'/'.$entry);
        }
        $index = "<?php\n\n// phpdbadmin-installer:index — erzeugt vom Web-Installer. Das Programm liegt in ".$core."\n\n"
            ."use Illuminate\\Http\\Request;\n\n"
            ."define('LARAVEL_START', microtime(true));\n\n"
            .'$core = '.var_export($core, true).";\n\n"
            ."if (file_exists(\$maintenance = \$core.'/storage/framework/maintenance.php')) {\n    require \$maintenance;\n}\n\n"
            ."require \$core.'/vendor/autoload.php';\n\n"
            ."\$app = require_once \$core.'/bootstrap/app.php';\n"
            ."\$app->usePublicPath(__DIR__);\n"
            ."\$app->handleRequest(Request::capture());\n";
        if (@file_put_contents($this->dir.'/index.php', $index) === false) {
            throw new RuntimeException(PdaText::t('err_copy', ['path' => 'index.php']));
        }
        $existing = is_file($this->dir.'/.htaccess') ? (string) file_get_contents($this->dir.'/.htaccess') : '';
        $kept = self::block($existing, 'phpdbadmin-php');
        $laravel = is_file($core.'/public/.htaccess') ? (string) file_get_contents($core.'/public/.htaccess') : '';
        $guard = "# BEGIN phpdbadmin-guard\n"
            ."# Nichts mit Punkt am Anfang ausliefern (.env, Zwischenstände, das Programm im Webordner).\n"
            ."<IfModule mod_alias.c>\n    RedirectMatch 404 /\\.(?!well-known/)\n</IfModule>\n"
            ."<IfModule mod_authz_core.c>\n    <FilesMatch \"^\\.\">\n        Require all denied\n    </FilesMatch>\n</IfModule>\n"
            ."# END phpdbadmin-guard\n";
        $content = ($kept !== null ? $kept."\n\n" : '').$guard."\n".$laravel;
        if ($existing !== '' && ! is_file($this->dir.'/.htaccess.phpdbadmin-vorher')) {
            @copy($this->dir.'/.htaccess', $this->dir.'/.htaccess.phpdbadmin-vorher');
        }
        if (@file_put_contents($this->dir.'/.htaccess', $content) === false) {
            throw new RuntimeException(PdaText::t('err_copy', ['path' => '.htaccess']));
        }
        $state['step'] = 'place';
        $this->save($state);

        return ['ok' => true];
    }

    /** Im Programm einrichten: Brücke laden (migrieren, erstes Konto, Link zur Ersteinrichtung, Systemprüfung). */
    private function setup(array $state): array
    {
        $this->expect($state, 'place');
        $in = $this->input();
        $core = (string) $state['core'];
        $bridge = require $core.'/bootstrap/phpdbadmin-install.php';
        if (! is_callable($bridge)) {
            throw new RuntimeException(PdaText::t('err_zip_layout'));
        }
        ob_start();
        try {
            $result = $bridge(['name' => $in['name'], 'email' => $in['email'], 'locale' => $in['locale'], 'publicPath' => $this->dir]);
        } catch (Throwable $e) {
            ob_end_clean();
            throw new RuntimeException(PdaText::t('err_bridge', ['reason' => $e->getMessage()]));
        }
        ob_end_clean();
        $state['step'] = 'setup';
        $this->save($state);

        return ['ok' => true, 'version' => isset($result['version']) ? (string) $result['version'] : '', 'setupUrl' => isset($result['setupUrl']) ? $result['setupUrl'] : null,
            'existing' => ! empty($result['existing']), 'checks' => isset($result['checks']) && is_array($result['checks']) ? $result['checks'] : [],
            'cron' => $this->cron($core), 'where' => PdaText::t('done_where', ['core' => $core, 'docroot' => $this->dir]),
            'inside' => $state['layout'] === 'inside', 'probe' => $state['layout'] === 'inside' ? '.phpdbadmin/.env' : '.phpdbadmin-installer-state.php'];
    }

    /** Cron-Zeilen mit dem PHP der Konsole (nicht dem des Webservers, wenn es sich unterscheiden lässt). */
    private function cron(string $core): array
    {
        $php = 'php';
        foreach (['/opt/alt/php85/usr/bin/php', '/opt/cpanel/ea-php85/root/usr/bin/php', '/opt/plesk/php/8.5/bin/php', '/usr/local/bin/php85', '/usr/bin/php8.5'] as $candidate) {
            if (PdaFacts::exists($candidate)) {
                $php = $candidate;
                break;
            }
        }
        if ($php === 'php' && PHP_BINARY !== '' && pda_contains(basename(PHP_BINARY), 'php') && ! pda_contains(PHP_BINARY, 'fpm') && ! pda_contains(PHP_BINARY, 'cgi')) {
            $php = PHP_BINARY;
        }
        $diagnosis = new PdaDiagnosis($this->facts());
        $profile = $diagnosis->profile();
        // Nur wo der Verwaltungsbereich Cronjobs anbietet, steht er im Text.
        $panel = is_string($profile['panel']) && $profile['cron'] ? $profile['panel'] : '';

        return ['text' => $panel !== '' ? PdaText::t('done_cron_text', ['panel' => $panel]) : PdaText::t('done_cron_generic'),
            'schedule' => '* * * * * cd '.escapeshellarg($core).' && '.$php.' artisan schedule:run >> /dev/null 2>&1',
            'worker' => '* * * * * cd '.escapeshellarg($core).' && '.$php.' artisan queue:work --stop-when-empty --tries=1 --max-time=55 >> /dev/null 2>&1'];
    }

    /** Aufräumen: Zwischenstände, Testordner, Zustand — und der Installer selbst. */
    private function finish(array $state): array
    {
        $this->expect($state, 'setup');
        @unlink($this->dir.'/.phpdbadmin-download.zip');
        pda_remove($this->dir.'/.phpdbadmin-staging', $this->dir);
        $this->cleanupProbes();
        @unlink($this->dir.'/'.PDA_STATE);
        if (is_file($this->dir.'/latest.json')) {
            @unlink($this->dir.'/latest.json');
        }
        foreach (glob($this->dir.'/phpdbadmin-*.zip') ?: [] as $zip) {
            @unlink($zip);
        }
        @unlink($this->file);

        return ['ok' => true];
    }

    private function expect(array $state, string $previous): void
    {
        $order = ['download', 'extract', 'configure', 'place', 'setup'];
        $have = isset($state['step']) ? array_search($state['step'], $order, true) : false;
        $need = array_search($previous, $order, true);
        if ($have === false || $have < $need) {
            throw new RuntimeException(PdaText::t('err_step'));
        }
    }

    // ── Selbsthilfe ──

    /** PHP: Zeilen in einem Testordner ablegen; der Browser fragt dort probe.php ab. */
    private function phpProbe(int $index): array
    {
        $candidates = (new PdaDiagnosis($this->facts()))->phpCandidates();
        if (! isset($candidates[$index])) {
            return ['ok' => false, 'error' => PdaText::t('php_failed')];
        }
        $this->cleanupProbes();
        $folder = '.phpdbadmin-probe-'.$index;
        $path = $this->dir.'/'.$folder;
        if (! @mkdir($path, 0755)) {
            throw new RuntimeException(PdaText::t('err_mkdir', ['path' => $path]));
        }
        $candidate = $candidates[$index];
        file_put_contents($path.'/'.$candidate['file'], implode("\n", $candidate['lines'])."\n");
        file_put_contents($path.'/probe.php', "<?php header('Content-Type: text/plain'); echo PHP_VERSION;\n");

        return ['ok' => true, 'url' => $folder.'/probe.php', 'version' => $candidate['version']];
    }

    /** Wirkte der Testordner, kommt die Zeile in die .htaccess dieses Ordners — mit Sicherung. */
    private function phpApply(int $index): array
    {
        $candidates = (new PdaDiagnosis($this->facts()))->phpCandidates();
        if (! isset($candidates[$index])) {
            return ['ok' => false, 'error' => PdaText::t('php_failed')];
        }
        $candidate = $candidates[$index];
        $target = $this->dir.'/'.$candidate['file'];
        $existing = is_file($target) ? (string) file_get_contents($target) : '';
        if ($existing !== '' && ! is_file($target.'.phpdbadmin-vorher')) {
            @copy($target, $target.'.phpdbadmin-vorher');
        }
        $block = "# BEGIN phpdbadmin-php\n".implode("\n", $candidate['lines'])."\n# END phpdbadmin-php";
        $content = $candidate['file'] === '.htaccess' ? $block."\n\n".self::withoutBlock($existing, 'phpdbadmin-php') : implode("\n", $candidate['lines'])."\n";
        if (@file_put_contents($target, $content) === false) {
            throw new RuntimeException(PdaText::t('err_copy', ['path' => $target]));
        }
        $this->cleanupProbes();

        return ['ok' => true, 'message' => PdaText::t('php_applied', ['version' => $candidate['version']])];
    }

    private function cleanupProbes(): void
    {
        foreach (glob($this->dir.'/.phpdbadmin-probe-*') ?: [] as $probe) {
            pda_remove($probe, $this->dir);
        }
    }

    /** Fremde Dateien beiseite legen — nichts wird gelöscht. */
    private function moveAside(): array
    {
        $entries = $this->facts()['entries'];
        $folder = '.phpdbadmin-vorher-'.date('Ymd-His');
        if (! @mkdir($this->dir.'/'.$folder, 0755)) {
            throw new RuntimeException(PdaText::t('err_mkdir', ['path' => $folder]));
        }
        foreach ($entries as $entry) {
            if (! @rename($this->dir.'/'.$entry, $this->dir.'/'.$folder.'/'.$entry)) {
                throw new RuntimeException(PdaText::t('err_copy', ['path' => $entry]));
            }
        }

        return ['ok' => true, 'message' => PdaText::t('moved', ['folder' => $folder])];
    }

    /** Datenbank prüfen: Server-Namen durchprobieren, fehlende Datenbank melden — und auf Wunsch anlegen. */
    private function dbTest(bool $create): array
    {
        $in = $this->input(false);
        if ($in['db'] === 'sqlite') {
            return ['ok' => true];
        }
        $driver = $in['db'] === 'pgsql' ? 'pgsql' : 'mysql';
        if (! extension_loaded('pdo_'.$driver)) {
            return ['ok' => false, 'error' => PdaText::t('err_db_driver')];
        }
        $diagnosis = new PdaDiagnosis($this->facts());
        $last = null;
        $denied = false;
        foreach ($diagnosis->databaseHosts($in['db_host'], $in['db_name']) as $host) {
            try {
                $pdo = self::connect($driver, $host, $in['db_port'], $create ? '' : $in['db_name'], $in['db_user'], $in['db_password']);
                if ($create) {
                    $quoted = $driver === 'pgsql' ? '"'.str_replace('"', '""', $in['db_name']).'"' : '`'.str_replace('`', '``', $in['db_name']).'`';
                    $pdo->exec('CREATE DATABASE '.$quoted.($driver === 'mysql' ? ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' : ''));

                    return ['ok' => true, 'message' => PdaText::t('db_created', ['name' => $in['db_name']]), 'host' => $host];
                }
                $version = (string) $pdo->query($driver === 'pgsql' ? 'select version()' : 'select version()')->fetchColumn();
                $kind = $driver === 'mysql' && stripos($version, 'mariadb') !== false ? 'mariadb' : $driver;

                return ['ok' => true, 'message' => $host !== $in['db_host'] ? PdaText::t('db_host_changed', ['host' => $host]) : PdaText::t('db_ok', ['version' => self::shortVersion($version)]),
                    'host' => $host, 'kind' => $kind];
            } catch (PDOException $e) {
                $last = $e;
                $message = strtolower($e->getMessage());
                if (pda_contains($message, 'access denied') || pda_contains($message, 'password authentication failed') || pda_contains($message, '1045')) {
                    $denied = true;
                    break;
                }
                if (pda_contains($message, 'unknown database') || pda_contains($message, '1049') || (pda_contains($message, 'database') && pda_contains($message, 'does not exist'))) {
                    return ['ok' => false, 'missing' => true, 'error' => PdaText::t('db_missing', ['name' => $in['db_name']]), 'host' => $host];
                }
            }
        }
        $profile = $diagnosis->profile();
        $hints = [];
        if ($denied && $profile['prefix']) {
            $hints[] = PdaText::t('db_prefix', ['example' => $this->facts()['user'].'_phpdbadmin']);
        }
        if (is_string($profile['db_hint']) && $profile['db_hint'] !== '') {
            $hints[] = PdaText::t('db_hint_host', ['hoster' => $profile['name'], 'host' => strtr($profile['db_hint'], ['{user}' => $this->facts()['user']])]);
        }

        return ['ok' => false, 'error' => $denied ? PdaText::t('db_denied') : PdaText::t('db_refused', ['host' => $in['db_host']]),
            'hint' => $hints === [] ? null : implode(' ', $hints), 'details' => $last !== null ? $last->getMessage() : null];
    }

    private static function connect(string $driver, string $host, string $port, string $database, string $user, string $password): PDO
    {
        $dsn = $driver === 'pgsql'
            ? 'pgsql:host='.$host.';port='.($port !== '' ? $port : '5432').';dbname='.($database !== '' ? $database : 'postgres').';connect_timeout=5'
            : 'mysql:host='.$host.';port='.($port !== '' ? $port : '3306').($database !== '' ? ';dbname='.$database : '').';charset=utf8mb4';

        return new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    }

    private static function shortVersion(string $version): string
    {
        return preg_match('/(MariaDB|PostgreSQL)?[^\d]*(\d+\.\d+(\.\d+)?)/', $version, $m) === 1 ? trim(($m[1] !== '' ? $m[1].' ' : (stripos($version, 'postgres') !== false ? 'PostgreSQL ' : 'MySQL ')).$m[2]) : $version;
    }

    /** Test-Mail über SMTP (STARTTLS oder TLS ab Port 465) bzw. sendmail — an die eigene Adresse. */
    private function mailTest(): array
    {
        $in = $this->input(false);
        $subject = PdaText::t('mail_subject');
        $body = PdaText::t('mail_body');
        $from = $in['mail_from'] !== '' ? $in['mail_from'] : $in['email'];
        if (! filter_var($in['email'], FILTER_VALIDATE_EMAIL) || ! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => PdaText::t('required')];
        }
        if ($in['mail'] === 'sendmail') {
            $sent = @mail($in['email'], $subject, $body, 'From: phpdbadmin <'.$from.'>');

            return $sent ? ['ok' => true, 'message' => PdaText::t('mail_sent')] : ['ok' => false, 'error' => PdaText::t('error'), 'details' => 'mail() returned false'];
        }
        $smtp = new PdaSmtp($in['mail_host'], (int) ($in['mail_port'] !== '' ? $in['mail_port'] : '587'));
        $smtp->send($in['mail_user'], $in['mail_password'], $from, $in['email'], $subject, $body);

        return ['ok' => true, 'message' => PdaText::t('mail_sent')];
    }

    // ── Eingaben ──

    private function input(bool $strict = true): array
    {
        $get = function (string $key, string $default = ''): string {
            return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
        };
        $in = [
            'name' => function_exists('mb_substr') ? mb_substr($get('name'), 0, 255) : substr($get('name'), 0, 255),
            'email' => pda_lower($get('email')),
            'locale' => in_array($get('locale'), ['de', 'en'], true) ? $get('locale') : PdaText::lang(),
            'db' => in_array($get('db'), ['sqlite', 'mysql', 'pgsql'], true) ? $get('db') : 'sqlite',
            'db_kind' => $get('db_kind') === 'mariadb' ? 'mariadb' : 'mysql',
            'db_host' => $get('db_host', 'localhost'),
            'db_port' => preg_replace('/\D/', '', $get('db_port')),
            'db_name' => $get('db_name'),
            'db_user' => $get('db_user'),
            'db_password' => isset($_POST['db_password']) ? (string) $_POST['db_password'] : '',
            'mail' => in_array($get('mail'), ['smtp', 'sendmail', 'later'], true) ? $get('mail') : 'later',
            'mail_host' => $get('mail_host'),
            'mail_port' => preg_replace('/\D/', '', $get('mail_port', '587')),
            'mail_user' => $get('mail_user'),
            'mail_password' => isset($_POST['mail_password']) ? (string) $_POST['mail_password'] : '',
            'mail_from' => pda_lower($get('mail_from')),
            'location' => $get('location') === 'inside' ? 'inside' : 'outside',
            'allowed_hosts' => preg_replace('/[^A-Za-z0-9.,:\/\-\[\] ]/', '', $get('allowed_hosts', '127.0.0.1,::1,localhost')),
        ];
        if ($strict) {
            if ($in['name'] === '' || ! filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException(PdaText::t('required').' ('.PdaText::t('name').', '.PdaText::t('email').')');
            }
            if ($in['db'] !== 'sqlite' && ($in['db_host'] === '' || $in['db_name'] === '' || $in['db_user'] === '')) {
                throw new RuntimeException(PdaText::t('required').' ('.PdaText::t('appdb').')');
            }
            if ($in['mail'] === 'smtp' && $in['mail_host'] === '') {
                throw new RuntimeException(PdaText::t('required').' ('.PdaText::t('mail_host').')');
            }
        }

        return $in;
    }

    /** Wert für die .env: ohne Anführungszeichen, wo es geht, sonst einfach (wörtlich) oder doppelt mit Escapes. */
    private static function envValue(string $value): string
    {
        if ($value === '' || preg_match('#^[A-Za-z0-9_./:@+,=\-]+$#', $value) === 1) {
            return $value;
        }
        if (! pda_contains($value, "'") && ! pda_contains($value, "\n")) {
            return "'".$value."'";
        }

        return '"'.str_replace(['\\', '"', '$', "\n"], ['\\\\', '\\"', '\\$', '\\n'], $value).'"';
    }

    /** Inhalt eines markierten Blocks (# BEGIN name … # END name) oder null. */
    private static function block(string $text, string $name): ?string
    {
        return preg_match('/# BEGIN '.preg_quote($name, '/').'\n.*?# END '.preg_quote($name, '/').'/s', $text, $m) === 1 ? $m[0] : null;
    }

    private static function withoutBlock(string $text, string $name): string
    {
        return trim((string) preg_replace('/# BEGIN '.preg_quote($name, '/').'\n.*?# END '.preg_quote($name, '/').'\n*/s', '', $text));
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ── Seite ──

    private function page(): void
    {
        $facts = $this->facts();
        $diagnosis = new PdaDiagnosis($facts);
        $problems = $diagnosis->problems();
        $blocking = array_values(array_filter($problems, function (array $p): bool {
            return $p['level'] === 'fail';
        }));
        $notes = array_values(array_filter($problems, function (array $p): bool {
            return $p['level'] !== 'fail';
        }));
        $location = $diagnosis->location();
        $profile = $diagnosis->profile();
        // Formular-Token: im Cookie und in der Seite — nur dieser Browser kann den Installer übernehmen.
        $token = isset($_COOKIE['pda_owner']) && preg_match('/^[0-9a-f]{64}$/', (string) $_COOKIE['pda_owner']) ? (string) $_COOKIE['pda_owner'] : bin2hex(random_bytes(32));
        setcookie('pda_owner', $token, ['expires' => 0, 'path' => '', 'secure' => $facts['https'], 'httponly' => true, 'samesite' => 'Strict']);
        header('Content-Type: text/html; charset=utf-8');
        $t = function (string $key, array $replace = []): string {
            return pda_e(PdaText::t($key, $replace));
        };
        $allowed = '127.0.0.1,::1,localhost';
        $hostHint = is_string($profile['db_hint']) && $profile['db_hint'] !== '' ? strtr($profile['db_hint'], ['{user}' => $facts['user']]) : '';
        if ($hostHint !== '' && ! pda_contains($hostHint, 'X') && ! pda_contains($hostHint, 'N')) {
            $allowed .= ','.$hostHint;
        }
        $lang = PdaText::lang();
        $other = $lang === 'de' ? 'en' : 'de';
        ?><!DOCTYPE html>
<html lang="<?= pda_e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $t('title') ?></title>
<style>
:root{--bg:#f6f7f9;--card:#fff;--text:#1c2534;--muted:#5f6b7d;--line:#dde2e8;--accent:#0b6e82;--accent-text:#fff;--accent-soft:#e3f2f5;--ok:#1d7a46;--warn:#9a5b00;--danger:#b3261e;--danger-soft:#fbeaea;--mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
@media (prefers-color-scheme:dark){:root{--bg:#0b1120;--card:#111a2c;--text:#e5e9f0;--muted:#8c98ad;--line:#2a3550;--accent:#4fc3d9;--accent-text:#062a33;--accent-soft:#0f3540;--ok:#5bd08f;--warn:#f2b84b;--danger:#ff8a80;--danger-soft:#3a1416}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
main{max-width:720px;margin:0 auto;padding:32px 16px 64px}header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px}
.logo{display:flex;align-items:center;gap:8px;font:600 16px var(--mono)}.logo b{color:var(--accent)}.logo svg{width:26px;height:26px}
a{color:var(--accent)}h1{font-size:22px;margin:0 0 6px}h2{font-size:16px;margin:0 0 12px}p{margin:0 0 10px}.muted{color:var(--muted)}.small{font-size:13px}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:20px;margin:16px 0}
.problem{border-left:3px solid var(--line);padding:2px 0 2px 14px;margin:14px 0}.problem.fail{border-color:var(--danger)}.problem.warn{border-color:var(--warn)}.problem.info{border-color:var(--accent)}
.problem h3{font-size:15px;margin:0 0 4px}details{margin-top:8px}summary{cursor:pointer;color:var(--accent);font-size:14px}ol{margin:8px 0;padding-left:20px}
button,.button{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);background:var(--card);color:var(--text);border-radius:6px;padding:7px 14px;font:inherit;font-size:14px;cursor:pointer;text-decoration:none}
button.primary,.button.primary{background:var(--accent);border-color:var(--accent);color:var(--accent-text);font-weight:600}button:disabled{opacity:.55;cursor:default}
label{display:block;font-size:13px;font-weight:600;margin:12px 0 4px}input,select,textarea{width:100%;font:inherit;font-size:14px;color:var(--text);background:var(--card);border:1px solid var(--line);border-radius:6px;padding:8px 10px}
input:focus,select:focus,textarea:focus,button:focus-visible,summary:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.grid{display:grid;grid-template-columns:2fr 1fr;gap:0 12px}.radio{display:flex;gap:8px;align-items:flex-start;font-weight:400;font-size:14px;margin:6px 0}.radio input{width:auto;margin-top:4px}
.hint{font-size:12px;color:var(--muted);margin-top:4px}.status{font-size:13px;margin-top:8px}.status.ok{color:var(--ok)}.status.fail{color:var(--danger)}
.steps{list-style:none;padding:0;margin:0}.steps li{padding:6px 0 6px 28px;position:relative;color:var(--muted)}.steps li:before{content:"";position:absolute;left:4px;top:11px;width:12px;height:12px;border-radius:50%;border:2px solid var(--line)}
.steps li.run{color:var(--text)}.steps li.run:before{border-color:var(--accent);border-top-color:transparent;animation:spin .8s linear infinite}.steps li.done{color:var(--text)}.steps li.done:before{background:var(--ok);border-color:var(--ok)}
.steps li.fail{color:var(--danger)}.steps li.fail:before{background:var(--danger);border-color:var(--danger)}@keyframes spin{to{transform:rotate(360deg)}}@media (prefers-reduced-motion:reduce){.steps li.run:before{animation:none}}
.alert{background:var(--danger-soft);border:1px solid var(--danger);border-radius:10px;padding:14px 16px;margin:16px 0}pre,code{font-family:var(--mono);font-size:12.5px}pre{white-space:pre-wrap;word-break:break-all;background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:10px;margin:6px 0}
.check{display:flex;gap:8px;font-size:14px;margin:4px 0}.check .ok{color:var(--ok)}.check .warn{color:var(--warn)}.check .fail{color:var(--danger)}.release{font-size:13px;color:var(--muted)}
.lang{font-size:13px}dialog{border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--text);max-width:440px;padding:20px}dialog::backdrop{background:rgb(0 0 0/.45)}[hidden]{display:none!important}
</style>
</head>
<body>
<main>
<header>
    <div class="logo"><svg viewBox="0 0 64 64" aria-hidden="true"><rect width="64" height="64" rx="14" fill="#0b6e82"/><g transform="translate(7.763 50.947) scale(0.52632)" fill="#fff"><path d="M25.00 1.00Q17.00 1.00 12.05 -4.70Q7.10 -10.40 7.10 -20.00L7.10 -34.90Q7.10 -44.60 12.00 -50.30Q16.90 -56.00 25.00 -56.00Q30.70 -56.00 34.65 -53.10Q38.60 -50.20 40.20 -45.00L39.80 -57.60L39.80 -73.00L52.30 -73.00L52.30 -0.00L40.10 -0.00L40.10 -10.00Q38.60 -4.80 34.70 -1.90Q30.80 1.00 25.00 1.00ZM29.70 -9.80Q34.50 -9.80 37.15 -12.70Q39.80 -15.60 39.80 -20.90L39.80 -34.10Q39.80 -39.40 37.15 -42.30Q34.50 -45.20 29.70 -45.20Q24.90 -45.20 22.25 -42.60Q19.60 -40.00 19.60 -34.60L19.60 -20.40Q19.60 -15.00 22.25 -12.40Q24.90 -9.80 29.70 -9.80Z"/><path d="M 67.10 1.00Q 61.30 1.00 57.40 -1.90Q 53.50 -4.80 52.00 -10.00L 52.00 0.00L 39.80 0.00L 39.80 -73.00L 52.30 -73.00L 52.30 -57.60L 51.90 -45.00Q 53.50 -50.20 57.45 -53.10Q 61.40 -56.00 67.10 -56.00Q 75.20 -56.00 80.10 -50.30Q 85.00 -44.60 85.00 -34.90L 85.00 -20.00Q 85.00 -10.40 80.05 -4.70Q 75.10 1.00 67.10 1.00ZM 62.40 -9.80Q 67.20 -9.80 69.85 -12.40Q 72.50 -15.00 72.50 -20.40L 72.50 -34.60Q 72.50 -40.00 69.85 -42.60Q 67.20 -45.20 62.40 -45.20Q 57.60 -45.20 54.95 -42.30Q 52.30 -39.40 52.30 -34.10L 52.30 -20.90Q 52.30 -15.60 54.95 -12.70Q 57.60 -9.80 62.40 -9.80Z"/></g></svg><span>php<b>db</b>admin</span></div>
    <a class="lang" href="?lang=<?= pda_e($other) ?>"><?= $other === 'en' ? 'English' : 'Deutsch' ?></a>
</header>
<h1><?= $t('title') ?></h1>
<p class="muted"><?= $t('lead') ?></p>
<p class="release" id="release"><?= $t('release_loading') ?></p>

<section class="card" aria-labelledby="pruefung">
    <h2 id="pruefung"><?= $t('checks') ?></h2>
    <p class="small muted"><?= $t('detected', ['hoster' => $diagnosis->hosterName(), 'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'server' => $facts['server'] ?: '–']) ?></p>
    <?php if ($problems === []) { ?>
        <p class="status ok"><?= $t('all_good') ?></p>
    <?php } ?>
    <?php if ($blocking !== []) { ?><h2 style="margin-top:16px"><?= $t('blocking') ?></h2><?php } ?>
    <?php foreach (array_merge($blocking, $notes) as $index => $p) {
        if ($index === count($blocking) && $notes !== []) {
            echo '<h2 style="margin-top:16px">'.$t('warnings').'</h2>';
        } ?>
        <div class="problem <?= pda_e($p['level']) ?>" data-problem="<?= pda_e($p['id']) ?>">
            <h3><?= pda_e($p['title']) ?></h3>
            <p><?= pda_e($p['text']) ?></p>
            <?php foreach ($p['notes'] as $note) { ?><p class="small"><?= pda_e($note) ?></p><?php } ?>
            <?php if ($p['fix'] !== null) { ?>
                <button type="button" class="primary" data-fix="<?= pda_e($p['fix']) ?>" data-count="<?= pda_e(isset($p['data']['count']) ? $p['data']['count'] : 0) ?>"><?= $t('fix') ?></button>
                <p class="status" hidden></p>
            <?php } ?>
            <?php if ($p['steps'] !== []) { ?>
                <details class="manual" <?= $p['fix'] === null && $p['level'] === 'fail' ? 'open' : '' ?>>
                    <summary><?= $diagnosis->hoster === 'generic' ? $t('manual_generic') : $t('manual', ['hoster' => $diagnosis->hosterName()]) ?></summary>
                    <ol><?php foreach ($p['steps'] as $step) { ?><li><?= pda_e($step) ?></li><?php } ?></ol>
                    <?php if (is_string($profile['help'])) { ?><p class="small"><a href="<?= pda_e($profile['help']) ?>" target="_blank" rel="noopener"><?= $t('help_link') ?></a></p><?php } ?>
                </details>
            <?php } ?>
        </div>
    <?php } ?>
    <?php if (array_filter($problems, function (array $p): bool {
        return $p['support'];
    }) !== []) { ?>
        <details>
            <summary><?= $t('support') ?></summary>
            <textarea id="support" rows="10" readonly><?= pda_e($diagnosis->supportMessage($problems)) ?></textarea>
            <button type="button" id="copy-support"><?= $t('support_copy') ?></button>
        </details>
    <?php } ?>
    <p style="margin-top:14px"><a class="button" href="?"><?= $t('recheck') ?></a></p>
</section>

<?php if ($blocking === []) { ?>
<section class="card" id="form-card" aria-labelledby="einrichten">
    <h2 id="einrichten"><?= $t('form') ?></h2>
    <form id="form" novalidate>
        <h2 style="font-size:14px;margin-top:4px"><?= $t('account') ?></h2>
        <label for="name"><?= $t('name') ?></label>
        <input id="name" name="name" autocomplete="name" required>
        <label for="email"><?= $t('email') ?></label>
        <input id="email" name="email" type="email" autocomplete="email" required>
        <p class="hint"><?= $t('email_hint') ?></p>
        <label for="locale"><?= $t('language') ?></label>
        <select id="locale" name="locale"><option value="de" <?= $lang === 'de' ? 'selected' : '' ?>>Deutsch</option><option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>English</option></select>

        <h2 style="font-size:14px;margin-top:22px"><?= $t('appdb') ?></h2>
        <p class="hint"><?= $t('appdb_hint') ?></p>
        <?php if ($facts['drivers']['sqlite']) { ?><label class="radio"><input type="radio" name="db" value="sqlite" checked> <?= $t('appdb_sqlite') ?></label><?php } ?>
        <?php if ($facts['drivers']['mysql']) { ?><label class="radio"><input type="radio" name="db" value="mysql" <?= $facts['drivers']['sqlite'] ? '' : 'checked' ?>> <?= $t('appdb_mysql') ?></label><?php } ?>
        <?php if ($facts['drivers']['pgsql']) { ?><label class="radio"><input type="radio" name="db" value="pgsql" <?= $facts['drivers']['sqlite'] || $facts['drivers']['mysql'] ? '' : 'checked' ?>> <?= $t('appdb_pgsql') ?></label><?php } ?>
        <div id="db-fields" hidden>
            <div class="grid">
                <div><label for="db_host"><?= $t('db_host') ?></label><input id="db_host" name="db_host" value="<?= pda_e(is_string($profile['db_host']) ? $profile['db_host'] : 'localhost') ?>"></div>
                <div><label for="db_port"><?= $t('db_port') ?></label><input id="db_port" name="db_port" inputmode="numeric" placeholder="3306"></div>
            </div>
            <?php if ($hostHint !== '') { ?><p class="hint"><?= $t('db_hint_host', ['hoster' => (string) $profile['name'], 'host' => $hostHint]) ?></p><?php } ?>
            <label for="db_name"><?= $t('db_name') ?></label><input id="db_name" name="db_name" autocomplete="off">
            <label for="db_user"><?= $t('db_user') ?></label><input id="db_user" name="db_user" autocomplete="off">
            <label for="db_password"><?= $t('db_password') ?></label><input id="db_password" name="db_password" type="password" autocomplete="new-password">
            <?php if ($profile['prefix']) { ?><p class="hint"><?= $t('db_prefix', ['example' => $facts['user'].'_phpdbadmin']) ?></p><?php } ?>
            <input type="hidden" name="db_kind" value="mysql">
            <p><button type="button" id="db-test"><?= $t('db_test') ?></button> <button type="button" id="db-create" hidden><?= $t('db_create') ?></button></p>
            <p class="status" id="db-status" hidden></p>
        </div>

        <h2 style="font-size:14px;margin-top:22px"><?= $t('mail') ?></h2>
        <p class="hint"><?= $t('mail_hint') ?></p>
        <label class="radio"><input type="radio" name="mail" value="smtp" checked> <?= $t('mail_smtp') ?></label>
        <label class="radio"><input type="radio" name="mail" value="sendmail"> <?= $t('mail_sendmail') ?></label>
        <label class="radio"><input type="radio" name="mail" value="later"> <?= $t('mail_later') ?></label>
        <div id="smtp-fields">
            <div class="grid">
                <div><label for="mail_host"><?= $t('mail_host') ?></label><input id="mail_host" name="mail_host" placeholder="mail.<?= pda_e(preg_replace('/^(www\.|db\.)/', '', preg_replace('/:\d+$/', '', $facts['host']))) ?>"></div>
                <div><label for="mail_port"><?= $t('mail_port') ?></label><input id="mail_port" name="mail_port" inputmode="numeric" value="587"></div>
            </div>
            <label for="mail_user"><?= $t('mail_user') ?></label><input id="mail_user" name="mail_user" autocomplete="off">
            <label for="mail_password"><?= $t('mail_password') ?></label><input id="mail_password" name="mail_password" type="password" autocomplete="new-password">
        </div>
        <div id="mail-common">
            <label for="mail_from"><?= $t('mail_from') ?></label><input id="mail_from" name="mail_from" type="email" placeholder="phpdbadmin@<?= pda_e(preg_replace('/^(www\.|db\.)/', '', preg_replace('/:\d+$/', '', $facts['host']))) ?>">
            <p><button type="button" id="mail-test"><?= $t('mail_test') ?></button></p>
            <p class="status" id="mail-status" hidden></p>
        </div>

        <details style="margin-top:18px">
            <summary><?= $t('advanced') ?></summary>
            <label><?= $t('location') ?></label>
            <?php if ($location['outside'] !== '') { ?><label class="radio"><input type="radio" name="location" value="outside" checked> <?= $t('location_outside', ['path' => $location['outside']]) ?></label><?php } ?>
            <?php if ($facts['htaccess'] || $location['outside'] === '') { ?><label class="radio"><input type="radio" name="location" value="inside" <?= $location['outside'] === '' ? 'checked' : '' ?>> <?= $t('location_inside', ['path' => $location['inside']]) ?></label><?php } ?>
            <label for="allowed_hosts"><?= $t('allowed_hosts') ?></label>
            <input id="allowed_hosts" name="allowed_hosts" value="<?= pda_e($allowed) ?>">
            <p class="hint"><?= $t('allowed_hosts_hint') ?></p>
        </details>

        <p style="margin-top:20px"><button type="submit" class="primary" id="install"><?= $t('install') ?></button></p>
        <p class="status fail" id="form-status" hidden></p>
    </form>
</section>

<section class="card" id="run" hidden aria-live="polite">
    <ul class="steps" id="steps">
        <li data-step="download"><?= $t('run_download') ?></li>
        <li data-step="extract"><?= $t('run_extract') ?></li>
        <li data-step="configure"><?= $t('run_configure') ?></li>
        <li data-step="place"><?= $t('run_place') ?></li>
        <li data-step="setup"><?= $t('run_setup') ?></li>
        <li data-step="check"><?= $t('run_check') ?></li>
        <li data-step="finish"><?= $t('run_finish') ?></li>
    </ul>
</section>
<div id="error" class="alert" hidden role="alert"></div>
<section class="card" id="done" hidden></section>
<?php } ?>
<dialog id="ask" aria-labelledby="ask-text">
    <form method="dialog">
        <p id="ask-text"></p>
        <p style="display:flex;gap:8px;justify-content:flex-end;margin:16px 0 0"><button value="no"><?= $t('ask_no') ?></button><button value="yes" class="primary"><?= $t('ask_yes') ?></button></p>
    </form>
</dialog>
</main>
<script>
<?php // Texte und Token für das Skript — alles andere steht im Skript selbst.?>
var T = <?= json_encode(PdaText::all(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var TOKEN = <?= json_encode($token) ?>;
<?= self::script() ?>
</script>
</body>
</html>
<?php
    }

    private static function script(): string
    {
        return <<<'JS'
(function () {
    function fmt(text, values) { return String(text).replace(/:(\w+)/g, function (m, k) { return values && values[k] !== undefined ? values[k] : m; }); }
    function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
    function post(data) {
        var body = data instanceof FormData ? data : new FormData();
        if (!(data instanceof FormData)) { for (var k in data) { body.append(k, data[k]); } }
        body.append('token', TOKEN);
        return fetch(location.pathname, { method: 'POST', body: body, credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .catch(function (e) { return { ok: false, error: T.error, details: String(e) }; });
    }
    // Rückfrage im eigenen Dialog (ohne window.confirm, das manche Browser unterdrücken).
    function ask(text) {
        var dialog = document.getElementById('ask');
        if (!dialog || !dialog.showModal) { return Promise.resolve(window.confirm(text)); }
        document.getElementById('ask-text').textContent = text;
        dialog.returnValue = '';
        return new Promise(function (resolve) {
            dialog.addEventListener('close', function once() { dialog.removeEventListener('close', once); resolve(dialog.returnValue === 'yes'); });
            dialog.showModal();
        });
    }
    function say(el, text, cls) { el.textContent = text; el.className = 'status' + (cls ? ' ' + cls : ''); el.hidden = false; }
    function versionAtLeast(have, need) {
        var a = String(have).split('.'), b = String(need).split('.');
        for (var i = 0; i < 3; i++) { var d = (parseInt(a[i] || '0', 10)) - (parseInt(b[i] || '0', 10)); if (d) { return d > 0; } }
        return true;
    }

    // Release anzeigen
    var release = document.getElementById('release');
    post({ action: 'release' }).then(function (j) {
        if (j.ok) { release.textContent = j.release.text; } else { release.textContent = j.error; release.style.color = 'var(--danger)'; }
    });

    // Support-Nachricht kopieren
    var copy = document.getElementById('copy-support');
    if (copy) {
        copy.addEventListener('click', function () {
            var area = document.getElementById('support'); area.select();
            (navigator.clipboard ? navigator.clipboard.writeText(area.value) : Promise.resolve(document.execCommand('copy')))
                .then(function () { copy.textContent = T.support_copied; });
        });
    }

    // Selbsthilfe: PHP-Version (Testordner, vom Browser abgefragt) und fremde Dateien
    Array.prototype.forEach.call(document.querySelectorAll('[data-fix]'), function (button) {
        var status = button.parentNode.querySelector('.status');
        button.addEventListener('click', function () {
            if (button.dataset.fix === 'move') {
                ask(T.confirm_move.replace(':folder', '.phpdbadmin-vorher-…')).then(function (yes) {
                    if (!yes) { return; }
                    button.disabled = true; say(status, T.moving);
                    post({ action: 'fix_move' }).then(function (j) {
                        if (!j.ok) { say(status, j.error, 'fail'); button.disabled = false; return; }
                        say(status, j.message, 'ok'); wait(1200).then(function () { location.reload(); });
                    });
                });
                return;
            }
            ask(T.confirm_php).then(function (yes) { if (yes) { fixPhp(); } });
        });
        function fixPhp() {
            button.disabled = true;
            var count = Number(button.dataset.count), i = 0;
            function fail() {
                say(status, T.php_failed, 'fail'); button.disabled = false;
                var manual = button.parentNode.querySelector('details.manual'); if (manual) { manual.open = true; }
                post({ action: 'fix_php_cleanup' });
            }
            function attempt() {
                if (i >= count) { return fail(); }
                say(status, fmt(T.php_testing, { n: i + 1, count: count }));
                return post({ action: 'fix_php_probe', candidate: i }).then(function (j) {
                    if (!j.ok) { return fail(); }
                    return fetch(j.url + '?t=' + Date.now(), { cache: 'no-store' }).then(function (r) { return r.ok ? r.text() : ''; }).catch(function () { return ''; })
                        .then(function (version) {
                            if (/^\d+\.\d+/.test(version) && versionAtLeast(version.trim(), '8.5.0')) {
                                return post({ action: 'fix_php_apply', candidate: i }).then(function (a) {
                                    if (!a.ok) { return fail(); }
                                    say(status, a.message, 'ok'); return wait(1500).then(function () { location.reload(); });
                                });
                            }
                            i++; return attempt();
                        });
                });
            }
            attempt();
        }
    });

    var form = document.getElementById('form');
    if (!form) { return; }
    var dbFields = document.getElementById('db-fields'), smtp = document.getElementById('smtp-fields'), mailCommon = document.getElementById('mail-common');
    function value(name) { var el = form.elements[name]; if (el && el.length !== undefined && el.tagName === undefined) { for (var i = 0; i < el.length; i++) { if (el[i].checked) { return el[i].value; } } return ''; } return el ? el.value : ''; }
    function sync() {
        var db = value('db');
        dbFields.hidden = db === 'sqlite' || db === '';
        if (!form.db_port.value || form.db_port.value === '3306' || form.db_port.value === '5432') { form.db_port.placeholder = db === 'pgsql' ? '5432' : '3306'; }
        smtp.hidden = value('mail') !== 'smtp';
        mailCommon.hidden = value('mail') === 'later';
    }
    form.addEventListener('change', sync); sync();

    var dbStatus = document.getElementById('db-status'), dbCreate = document.getElementById('db-create');
    function dbCheck(create) {
        var data = new FormData(form); data.append('action', create ? 'db_create' : 'db_test');
        say(dbStatus, '…');
        return post(data).then(function (j) {
            dbCreate.hidden = !j.missing;
            if (j.host) { form.db_host.value = j.host; }
            if (j.kind) { form.db_kind.value = j.kind; }
            if (j.ok) { say(dbStatus, j.message || T.db_ok, 'ok'); if (create) { return dbCheck(false); } return true; }
            say(dbStatus, j.error + (j.hint ? ' ' + j.hint : ''), 'fail');
            return false;
        });
    }
    document.getElementById('db-test').addEventListener('click', function () { dbCheck(false); });
    dbCreate.addEventListener('click', function () { dbCheck(true); });

    var mailStatus = document.getElementById('mail-status');
    document.getElementById('mail-test').addEventListener('click', function () {
        var data = new FormData(form); data.append('action', 'mail_test'); say(mailStatus, '…');
        post(data).then(function (j) { say(mailStatus, j.ok ? j.message : (j.error + (j.details ? ' — ' + j.details : '')), j.ok ? 'ok' : 'fail'); });
    });

    var run = document.getElementById('run'), steps = document.getElementById('steps').children, errorBox = document.getElementById('error'), done = document.getElementById('done');
    function mark(name, cls) { for (var i = 0; i < steps.length; i++) { if (steps[i].dataset.step === name) { steps[i].className = cls; } } }
    function line(parent, tag, text, cls) { var el = document.createElement(tag); if (text !== undefined) { el.textContent = text; } if (cls) { el.className = cls; } parent.appendChild(el); return el; }
    function showError(j) {
        errorBox.textContent = ''; errorBox.hidden = false;
        line(errorBox, 'h2', T.error); line(errorBox, 'p', j.error || T.error);
        if (j.hint) { line(errorBox, 'p', j.hint); }
        if (j.details) { var d = line(errorBox, 'details'); line(d, 'summary', T.error_details); line(d, 'pre', j.details); }
        var retry = line(errorBox, 'button', T.error_retry); retry.type = 'button'; retry.addEventListener('click', function () { location.reload(); });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var formStatus = document.getElementById('form-status');
        if (!form.elements['name'].value.trim() || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(form.elements['email'].value.trim())) { say(formStatus, T.required + ' (' + T.name + ', ' + T.email + ')', 'fail'); return; }
        formStatus.hidden = true;
        var check = value('db') === 'sqlite' ? Promise.resolve(true) : dbCheck(false);
        check.then(function (ok) {
            if (!ok) { return; }
            document.getElementById('form-card').hidden = true; run.hidden = false; errorBox.hidden = true;
            var order = ['download', 'extract', 'configure', 'place', 'setup'], i = 0, result = null;
            function next() {
                if (i === order.length) { return verify(); }
                var step = order[i++]; mark(step, 'run');
                var data = new FormData(form); data.append('action', step);
                return post(data).then(function (j) {
                    if (!j.ok) { mark(step, 'fail'); showError(j); return; }
                    if (step === 'setup') { result = j; }
                    mark(step, 'done'); return next();
                });
            }
            // Von aussen prüfen: antwortet phpdbadmin (/up)? Ist das Programm im Webordner geschützt?
            function verify() {
                mark('check', 'run');
                var base = location.href.replace(/[^\/]*$/, '');
                var warnings = [];
                function poll(n) {
                    return fetch(base + 'up', { cache: 'no-store' }).then(function (r) { return r.ok; }).catch(function () { return false; })
                        .then(function (ok) { return ok || n <= 0 ? ok : wait(1500).then(function () { return poll(n - 1); }); });
                }
                return poll(10).then(function (up) {
                    if (!up) { warnings.push([T.post_down, fmt(T.post_down_text, { url: base + 'up', log: 'storage/logs' })]); }
                    if (!result.inside) { return; }
                    return fetch(base + result.probe, { cache: 'no-store' }).then(function (r) { return r.status === 200; }).catch(function () { return false; })
                        .then(function (exposed) { if (exposed) { warnings.push([T.post_exposed, fmt(T.post_exposed_text, { url: base + result.probe, core: '.phpdbadmin' })]); } });
                }).then(function () {
                    mark('check', warnings.length ? 'fail' : 'done'); mark('finish', 'run');
                    return post({ action: 'finish' }).then(function () { mark('finish', 'done'); finished(warnings); });
                });
            }
            function finished(warnings) {
                done.hidden = false; done.textContent = '';
                line(done, 'h2', T.done_title);
                warnings.forEach(function (w) { var a = line(done, 'div', undefined, 'alert'); line(a, 'h2', w[0]); line(a, 'p', w[1]); });
                if (result.setupUrl) {
                    line(done, 'p', T.done_text);
                    var go = line(done, 'a', T.done_button, 'button primary'); go.href = result.setupUrl;
                } else {
                    line(done, 'p', T.done_existing);
                    var login = line(done, 'a', T.done_login, 'button primary'); login.href = location.href.replace(/[^\/]*$/, '') + 'login';
                }
                if (result.checks && result.checks.length) {
                    line(done, 'h2', T.done_checks).style.marginTop = '22px';
                    result.checks.forEach(function (c) {
                        var row = line(done, 'div', undefined, 'check');
                        line(row, 'span', c.level === 'ok' ? '✓' : (c.level === 'warn' ? '!' : '✕'), c.level);
                        var text = line(row, 'span', c.title + (c.hint ? ' — ' + c.hint : ''));
                    });
                }
                line(done, 'h2', T.done_cron).style.marginTop = '22px';
                line(done, 'p', result.cron.text, 'small');
                line(done, 'pre', result.cron.schedule);
                line(done, 'p', T.done_cron_worker, 'small');
                line(done, 'pre', result.cron.worker);
                line(done, 'p', result.where, 'small muted');
                done.scrollIntoView({ behavior: 'smooth' });
            }
            next();
        });
    });
})();
JS;
    }
}

// ─── SMTP (nur für die Test-Mail) ────────────────────────────────────────────────────────────────────────────────────

final class PdaSmtp
{
    /** @var string */
    private $host;

    /** @var int */
    private $port;

    /** @var resource|null */
    private $socket = null;

    public function __construct(string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function send(string $user, string $password, string $from, string $to, string $subject, string $body): void
    {
        $implicit = $this->port === 465;
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->host]]);
        $socket = @stream_socket_client(($implicit ? 'ssl://' : 'tcp://').$this->host.':'.$this->port, $errno, $error, 10, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException('SMTP '.$this->host.':'.$this->port.' — '.$error);
        }
        stream_set_timeout($socket, 15);
        $this->socket = $socket;
        try {
            $this->expect(220);
            $ehlo = $this->command('EHLO phpdbadmin-installer', 250);
            if (! $implicit && stripos($ehlo, 'STARTTLS') !== false) {
                $this->command('STARTTLS', 220);
                $method = defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (! @stream_socket_enable_crypto($socket, true, $method)) {
                    throw new RuntimeException('STARTTLS');
                }
                $this->command('EHLO phpdbadmin-installer', 250);
            }
            if ($user !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($user), 334);
                $this->command(base64_encode($password), 235);
            }
            $this->command('MAIL FROM:<'.$from.'>', 250);
            $this->command('RCPT TO:<'.$to.'>', 250);
            $this->command('DATA', 354);
            $headers = 'From: phpdbadmin <'.$from.">\r\nTo: <".$to.">\r\nSubject: =?UTF-8?B?".base64_encode($subject)."?=\r\nDate: ".date('r')
                ."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
            $this->command($headers."\r\n".str_replace("\n.", "\n..", $body)."\r\n.", 250);
            $this->command('QUIT', 221);
        } finally {
            fclose($socket);
        }
    }

    private function command(string $line, int $code): string
    {
        fwrite($this->socket, $line."\r\n");

        return $this->expect($code);
    }

    private function expect(int $code): string
    {
        $response = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        if ((int) substr($response, 0, 3) !== $code) {
            throw new RuntimeException('SMTP: '.trim($response !== '' ? $response : 'keine Antwort'));
        }

        return $response;
    }
}

(new PdaInstaller(__DIR__, __FILE__))->handle();
