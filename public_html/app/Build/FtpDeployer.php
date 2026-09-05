<?php

declare(strict_types=1);

namespace WebAtze\Build;

use phpseclib3\Net\SFTP;
use WebAtze\Core\{Crypto, Db, Http, Logger};

/**
 * Lädt eine fertige Website auf das Hosting des Kunden.
 *
 * Drei Wege, je nach Anbieter:
 *   sftp  verschlüsselt über SSH – die beste Wahl, wo verfügbar
 *   ftps  FTP mit Verschlüsselung
 *   ftp   unverschlüsselt; nur, wenn der Anbieter nichts anderes kann
 *
 * Zugangsdaten werden verschlüsselt gespeichert (libsodium) und tauchen
 * niemals in einem Protokoll auf.
 *
 * Nach dem Hochladen wird geprüft, ob die Startseite wirklich erreichbar
 * ist. Ohne diese Prüfung würde man erst beim Kunden merken, dass das
 * Verzeichnis falsch war.
 */
final class FtpDeployer
{
    /** Beim Herunterladen für die Sicherung: höchstens so viele Dateien. */
    private const MAX_FETCH_FILES = 500;

    /** Und keine einzelne grösser als das. */
    private const MAX_FETCH_BYTES = 8 * 1024 * 1024;

    /** Beim Holen des ganzen Stands: höchstens so viele Dateien. */
    private const MAX_TREE_FILES = 5000;

    /** Und zusammen höchstens so viel. */
    private const MAX_TREE_BYTES = 200 * 1024 * 1024;

    /** Und keine Verschachtelung tiefer als das. */
    private const MAX_TREE_DEPTH = 8;

    /**
     * Was beim Holen des ganzen Stands übersprungen wird.
     *
     * Sicherungen einer Sicherung sind der klassische Weg, aus einer
     * 20-MB-Website ein 400-MB-Archiv zu machen - und das Ergebnis
     * enthält dieselben Daten dreimal.
     */
    private const UEBERGEHEN = [
        'data/backups',
        'data/tmp',
        'data/cache',
        '.git',
        'node_modules',
        '.well-known/acme-challenge',
        'cgi-bin',
    ];

    /**
     * @param callable|null $onProgress fn(int $erledigt, int $gesamt, string $datei)
     * @return array{ok:bool, files:int, error:string, retryable:bool, verified:bool, url:string}
     */
    public static function deploy(array $project, ?callable $onProgress = null, float $budget = 120.0): array
    {
        $target = self::targetFor((int) $project['id']);

        if ($target === null) {
            return self::error('Für dieses Projekt sind keine Zugangsdaten hinterlegt.', false);
        }

        $password = Crypto::decrypt((string) ($target['secret'] ?? ''));
        if ($password === null) {
            return self::error(
                'Die gespeicherten Zugangsdaten lassen sich nicht entschlüsseln. '
                . 'Wurde der Schlüssel in app/config.php geändert? Bitte neu eingeben.',
                false
            );
        }

        $source = STORAGE_DIR . '/projects/' . (string) $project['slug'] . '/dist';
        if (!is_dir($source)) {
            return self::error('Es gibt noch keine gebaute Website zum Hochladen.', false);
        }

        $files = self::collect($source);
        if ($files === []) {
            return self::error('Der Ordner mit der Website ist leer.', false);
        }

        $protocol = (string) $target['protocol'];

        $result = match ($protocol) {
            'sftp' => self::viaSftp($target, $password, $source, $files, $onProgress, $budget),
            default => self::viaFtp($target, $password, $source, $files, $onProgress, $budget, $protocol === 'ftps'),
        };

        // Passwort so früh wie möglich aus dem Speicher nehmen
        Crypto::wipe($password);

        Db::update('deploy_targets', [
            'last_result' => mb_substr($result['ok'] ? sprintf('%d Dateien hochgeladen.', $result['files']) : $result['error'], 0, 500),
            'last_deployed_at' => $result['ok'] ? Db::now() : null,
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => (int) $target['id']]);

        // Nachsehen, ob die Website wirklich da ist
        if ($result['ok']) {
            $check = self::verify($project);
            $result['verified'] = $check['ok'];
            $result['url'] = $check['url'];

            if (!$check['ok'] && $check['url'] !== '') {
                $result['error'] = 'Die Dateien sind oben, aber ' . $check['url']
                    . ' antwortet noch nicht. Das kann an der DNS-Umstellung liegen '
                    . 'oder daran, dass das Zielverzeichnis nicht stimmt.';
            }
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // SFTP
    // ------------------------------------------------------------------

    private static function viaSftp(
        array $target,
        string $password,
        string $source,
        array $files,
        ?callable $onProgress,
        float $budget
    ): array {
        if (!class_exists(SFTP::class)) {
            return self::error(
                'Für SFTP fehlt die mitgelieferte Bibliothek. '
                . 'Bitte FTP mit Verschlüsselung wählen oder das Paket vollständig hochladen.',
                false
            );
        }

        $sauber = self::normalizeHost((string) $target['host'], (int) $target['port'] ?: 22);
        $host = $sauber['host'];
        $port = $sauber['port'];

        try {
            $sftp = new SFTP($host, $port, 20);

            if (!$sftp->login((string) $target['username'], $password)) {
                return self::error('Anmeldung abgelehnt. Bitte Benutzername und Passwort prüfen.', false);
            }
        } catch (\Throwable $e) {
            Logger::warning('SFTP-Verbindung fehlgeschlagen', ['host' => $host]);
            return self::error('Keine Verbindung zu ' . $host . ':' . $port . '.', true);
        }

        $remoteRoot = self::cleanPath((string) $target['remote_path']);
        $started = microtime(true);
        $done = 0;

        foreach ($files as $relative) {
            if (microtime(true) - $started > $budget) {
                return self::error(
                    sprintf('Zeit abgelaufen nach %d von %d Dateien. Der Auftrag wird fortgesetzt.', $done, count($files)),
                    true
                );
            }

            $remote = $remoteRoot . '/' . $relative;
            $directory = dirname($remote);

            if (!$sftp->is_dir($directory)) {
                $sftp->mkdir($directory, -1, true);
            }

            if (!$sftp->put($remote, $source . '/' . $relative, SFTP::SOURCE_LOCAL_FILE)) {
                return self::error('Die Datei ' . $relative . ' liess sich nicht schreiben.', true);
            }

            $done++;
            if ($onProgress !== null) {
                $onProgress($done, count($files), $relative);
            }
        }

        $sftp->disconnect();

        return ['ok' => true, 'files' => $done, 'error' => '', 'retryable' => false, 'verified' => false, 'url' => ''];
    }

    // ------------------------------------------------------------------
    // FTP und FTPS
    // ------------------------------------------------------------------

    private static function viaFtp(
        array $target,
        string $password,
        string $source,
        array $files,
        ?callable $onProgress,
        float $budget,
        bool $secure
    ): array {
        if (!function_exists('ftp_connect')) {
            return self::error(
                'Dieser Server kann kein FTP - die passende PHP-Erweiterung ist nicht '
                . 'eingebaut. Stelle unter Veroeffentlichen auf SFTP um (Port 22). '
                . 'Das bringt WebAtze selbst mit und funktioniert ueberall.',
                false
            );
        }

        $sauber = self::normalizeHost((string) $target['host'], (int) $target['port'] ?: 21);
        $host = $sauber['host'];
        $port = $sauber['port'];

        $connection = $secure
            ? @ftp_ssl_connect($host, $port, 20)
            : @ftp_connect($host, $port, 20);

        if ($connection === false) {
            return self::error(
                'Keine Verbindung zu ' . $host . ':' . $port . '.'
                . ($secure ? ' Unterstützt der Anbieter FTP mit Verschlüsselung?' : ''),
                true
            );
        }

        if (!@ftp_login($connection, (string) $target['username'], $password)) {
            @ftp_close($connection);
            return self::error('Anmeldung abgelehnt. Bitte Benutzername und Passwort prüfen.', false);
        }

        // Fast alle Hosting-Anbieter brauchen den passiven Modus.
        @ftp_pasv($connection, true);

        // Und manche nennen darin eine interne Adresse, weil sie hinter
        // NAT stehen - auf geteiltem Hosting eher Regel als Ausnahme.
        // Der Verbindungsversuch geht dann an eine Adresse, die es von
        // hier aus nicht gibt, und das sieht aus wie eine Zeitueber-
        // schreitung. Merkt man an einer Auflistung, die leer bleibt,
        // obwohl die Anmeldung sass.
        if (defined('FTP_USEPASVADDRESS') && @ftp_nlist($connection, '.') === false) {
            @ftp_set_option($connection, FTP_USEPASVADDRESS, false);
        }

        $remoteRoot = self::cleanPath((string) $target['remote_path']);
        $created = [];
        $started = microtime(true);
        $done = 0;

        foreach ($files as $relative) {
            if (microtime(true) - $started > $budget) {
                @ftp_close($connection);
                return self::error(
                    sprintf('Zeit abgelaufen nach %d von %d Dateien. Der Auftrag wird fortgesetzt.', $done, count($files)),
                    true
                );
            }

            $remote = $remoteRoot . '/' . $relative;
            $directory = dirname($remote);

            if (!isset($created[$directory])) {
                self::ensureRemoteDir($connection, $directory);
                $created[$directory] = true;
            }

            if (!@ftp_put($connection, $remote, $source . '/' . $relative, FTP_BINARY)) {
                @ftp_close($connection);
                return self::error(
                    'Die Datei ' . $relative . ' liess sich nicht schreiben. '
                    . 'Stimmt das Zielverzeichnis, und ist genug Platz frei?',
                    true
                );
            }

            $done++;
            if ($onProgress !== null) {
                $onProgress($done, count($files), $relative);
            }
        }

        @ftp_close($connection);

        return ['ok' => true, 'files' => $done, 'error' => '', 'retryable' => false, 'verified' => false, 'url' => ''];
    }

    // ------------------------------------------------------------------
    // Ein fertiges Archiv hochladen
    // ------------------------------------------------------------------

    /**
     * Ein ZIP direkt auf den Server des Kunden schieben.
     *
     * Für den Weg ohne den eingebauten Generator: Auftragstext
     * kopieren, die Website anderswo bauen lassen, das Ergebnis als
     * ZIP hier hochladen. Von hier an ist es dasselbe wie ein selbst
     * gebautes Paket.
     *
     * **Das Archiv wird bei uns nie ausgepackt.** Jeder Eintrag geht
     * als Datenstrom direkt aus dem ZIP auf das FTP. Das ist kein
     * Umweg, sondern der Punkt: Eine Kundenwebsite enthält PHP – ihr
     * Backend, das Kontaktformular, die Brücke –, und ausgepackte
     * fremde PHP-Dateien auf dem eigenen Webserver sind eine
     * Hintertür, ganz gleich wie gut der Ordner gesperrt ist. So
     * berührt der Inhalt unsere Festplatte nur als Archiv, das
     * niemand ausführt.
     *
     * @param callable|null $onProgress fn(int $erledigt, int $gesamt, string $datei)
     * @return array{ok:bool, files:int, error:string, retryable:bool, verified:bool, url:string}
     */
    public static function deployZip(
        array $project,
        string $zipPfad,
        ?callable $onProgress = null,
        float $budget = 120.0
    ): array {
        if (!class_exists(\ZipArchive::class)) {
            return self::error('Dieser Server kann keine ZIP-Dateien lesen.', false);
        }

        $target = self::targetFor((int) $project['id']);

        if ($target === null) {
            return self::error('Für dieses Projekt sind keine Zugangsdaten hinterlegt.', false);
        }

        $zip = new \ZipArchive();

        if ($zip->open($zipPfad) !== true) {
            return self::error('Das ist keine lesbare ZIP-Datei.', false);
        }

        $plan = self::archivPlan($zip);

        if ($plan['error'] !== '') {
            $zip->close();

            return self::error($plan['error'], false);
        }

        if ($plan['dateien'] === []) {
            $zip->close();

            return self::error('In diesem Archiv ist keine einzige Datei.', false);
        }

        $password = Crypto::decrypt((string) ($target['secret'] ?? ''));

        if ($password === null) {
            $zip->close();

            return self::error('Die gespeicherten Zugangsdaten lassen sich nicht entschlüsseln.', false);
        }

        try {
            $result = (string) $target['protocol'] === 'sftp'
                ? self::zipViaSftp($target, $password, $zip, $plan, $onProgress, $budget)
                : self::zipViaFtp($target, $password, $zip, $plan, $onProgress, $budget,
                    (string) $target['protocol'] === 'ftps');
        } finally {
            Crypto::wipe($password);
            $zip->close();
        }

        Db::update('deploy_targets', [
            'last_result' => mb_substr($result['ok']
                ? sprintf('%d Dateien aus dem Archiv hochgeladen.', $result['files'])
                : $result['error'], 0, 500),
            'last_deployed_at' => $result['ok'] ? Db::now() : null,
            'updated_at' => Db::now(),
        ], 'project_id = :p', ['p' => (int) $project['id']]);

        return $result;
    }

    /**
     * Was im Archiv steht und wohin es gehört.
     *
     * Zwei Dinge werden dabei entschieden, und beide sind wichtig:
     *
     * Erstens fliegt jeder Eintrag raus, der aus dem Zielordner
     * ausbrechen könnte – absolute Pfade, „..“, Laufwerksbuchstaben,
     * Nullbytes. Ein ZIP ist eine Liste von Namen, und diese Namen
     * kommen von aussen.
     *
     * Zweitens: Liegt alles in einem einzigen Ordner – und genau so
     * packen die meisten –, wird der weggeschnitten. Sonst landete die
     * Website unter `/public_html/meine-website/` statt in
     * `/public_html`, und niemand fände sie.
     *
     * @return array{dateien:array<int, array{index:int, ziel:string, bytes:int}>, error:string, stamm:string}
     */
    private static function archivPlan(\ZipArchive $zip): array
    {
        $roh = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                continue;
            }

            $name = str_replace('\\', '/', (string) $stat['name']);

            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }

            // Was Mac und Windows beilegen und was auf keinem Server
            // etwas zu suchen hat.
            //
            // Geprueft wird je Pfadabschnitt und nicht am Anfang des
            // ganzen Namens: Packt jemand seinen Website-Ordner ein,
            // heisst der Eintrag "meine-website/__MACOSX/._x" - und
            // eine Pruefung auf den Anfang laesst ihn durch. Genau das
            // ist passiert.
            if (self::archivMuell($name)) {
                continue;
            }

            $grund = self::archivEintragPruefen($name);

            if ($grund !== '') {
                return ['dateien' => [], 'error' => $grund, 'stamm' => ''];
            }

            $roh[] = ['index' => $i, 'name' => $name, 'bytes' => (int) $stat['size']];
        }

        if ($roh === []) {
            return ['dateien' => [], 'error' => '', 'stamm' => ''];
        }

        $stamm = self::gemeinsamerStamm(array_column($roh, 'name'));
        $dateien = [];

        foreach ($roh as $eintrag) {
            $ziel = $stamm === '' ? $eintrag['name'] : substr($eintrag['name'], strlen($stamm) + 1);

            if ($ziel === '' || $ziel === false) {
                continue;
            }

            $dateien[] = ['index' => $eintrag['index'], 'ziel' => $ziel, 'bytes' => $eintrag['bytes']];
        }

        return ['dateien' => $dateien, 'error' => '', 'stamm' => $stamm];
    }

    /**
     * Beipack, der auf keinem Webserver etwas zu suchen hat.
     *
     * macOS legt `__MACOSX` und `._name` an, Windows `Thumbs.db`, die
     * Editoren ihre eigenen Ordner. Nichts davon tut etwas, alles davon
     * liegt danach sichtbar auf der Kundenwebsite.
     */
    private static function archivMuell(string $name): bool
    {
        foreach (explode('/', $name) as $teil) {
            if ($teil === '__MACOSX'
                || $teil === '.DS_Store'
                || $teil === 'Thumbs.db'
                || $teil === '.git'
                || $teil === 'node_modules'
                || str_starts_with($teil, '._')
            ) {
                return true;
            }
        }

        return false;
    }

    /** Warum ein Eintrag nicht durchkommt – oder ein leerer Text. */
    private static function archivEintragPruefen(string $name): string
    {
        if (str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name) === 1) {
            return 'Das Archiv enthält einen absoluten Pfad (' . $name . '). '
                . 'So etwas gehört nicht in ein Website-Archiv.';
        }

        foreach (explode('/', $name) as $teil) {
            if ($teil === '..') {
                return 'Das Archiv enthält einen Pfad, der aus dem Zielordner '
                    . 'ausbricht (' . $name . ').';
            }
        }

        if (str_contains($name, "\0")) {
            return 'Das Archiv enthält einen Pfad mit einem Nullbyte.';
        }

        return '';
    }

    /**
     * Der eine Ordner, in dem alles liegt – oder nichts.
     *
     * @param array<int, string> $namen
     */
    private static function gemeinsamerStamm(array $namen): string
    {
        $erster = explode('/', $namen[0]);

        if (count($erster) < 2) {
            return '';
        }

        $stamm = $erster[0];

        foreach ($namen as $name) {
            if (!str_starts_with($name, $stamm . '/')) {
                return '';
            }
        }

        return $stamm;
    }

    /**
     * @param array{dateien:array<int, array{index:int, ziel:string, bytes:int}>} $plan
     * @return array{ok:bool, files:int, error:string, retryable:bool, verified:bool, url:string}
     */
    private static function zipViaFtp(
        array $target,
        string $password,
        \ZipArchive $zip,
        array $plan,
        ?callable $onProgress,
        float $budget,
        bool $secure
    ): array {
        if (!function_exists('ftp_connect')) {
            return self::error('Dieser Server kann kein FTP.', false);
        }

        if ($secure && !function_exists('ftp_ssl_connect')) {
            return self::error('Dieses PHP kann kein verschlüsseltes FTP (es fehlt OpenSSL).', false);
        }

        $sauber = self::normalizeHost((string) $target['host'], (int) $target['port'] ?: 21);

        $verbindung = $secure
            ? @ftp_ssl_connect($sauber['host'], $sauber['port'], 20)
            : @ftp_connect($sauber['host'], $sauber['port'], 20);

        if ($verbindung === false) {
            return self::error('Keine Verbindung zu ' . $sauber['host'] . '.', true);
        }

        if (!@ftp_login($verbindung, (string) $target['username'], $password)) {
            @ftp_close($verbindung);

            return self::error('Anmeldung abgelehnt. Bitte Benutzername und Passwort prüfen.', false);
        }

        @ftp_pasv($verbindung, true);

        if (defined('FTP_USEPASVADDRESS') && @ftp_nlist($verbindung, '.') === false) {
            @ftp_set_option($verbindung, FTP_USEPASVADDRESS, false);
        }

        $wurzel = self::cleanPath((string) $target['remote_path']);
        $angelegt = [];
        $begonnen = microtime(true);
        $fertig = 0;
        $gesamt = count($plan['dateien']);

        foreach ($plan['dateien'] as $datei) {
            if (microtime(true) - $begonnen > $budget) {
                @ftp_close($verbindung);

                return self::error(sprintf(
                    'Zeit abgelaufen nach %d von %d Dateien. Der Auftrag wird fortgesetzt.',
                    $fertig,
                    $gesamt
                ), true);
            }

            $fern = $wurzel . '/' . $datei['ziel'];
            $ordner = dirname($fern);

            if (!isset($angelegt[$ordner])) {
                self::ensureRemoteDir($verbindung, $ordner);
                $angelegt[$ordner] = true;
            }

            // Direkt aus dem Archiv in die Leitung - ohne Umweg über
            // die eigene Festplatte.
            $strom = $zip->getStreamIndex($datei['index']);

            if ($strom === false) {
                @ftp_close($verbindung);

                return self::error('Die Datei ' . $datei['ziel'] . ' liess sich nicht lesen.', false);
            }

            $ok = @ftp_fput($verbindung, $fern, $strom, FTP_BINARY);

            fclose($strom);

            if (!$ok) {
                @ftp_close($verbindung);

                return self::error(
                    'Die Datei ' . $datei['ziel'] . ' liess sich nicht schreiben. '
                    . 'Stimmt das Zielverzeichnis, und ist genug Platz frei?',
                    true
                );
            }

            $fertig++;

            if ($onProgress !== null) {
                $onProgress($fertig, $gesamt, $datei['ziel']);
            }
        }

        @ftp_close($verbindung);

        return ['ok' => true, 'files' => $fertig, 'error' => '',
                'retryable' => false, 'verified' => false, 'url' => ''];
    }

    /** @return array{ok:bool, files:int, error:string, retryable:bool, verified:bool, url:string} */
    private static function zipViaSftp(
        array $target,
        string $password,
        \ZipArchive $zip,
        array $plan,
        ?callable $onProgress,
        float $budget
    ): array {
        if (!class_exists(SFTP::class)) {
            return self::error('Die Bibliothek für SFTP fehlt im Paket.', false);
        }

        $sauber = self::normalizeHost((string) $target['host'], (int) $target['port'] ?: 22);
        $sftp = new SFTP($sauber['host'], $sauber['port'], 20);

        if (!$sftp->login((string) $target['username'], $password)) {
            return self::error('Anmeldung abgelehnt. Bitte Benutzername und Passwort prüfen.', false);
        }

        $wurzel = self::cleanPath((string) $target['remote_path']);
        $angelegt = [];
        $begonnen = microtime(true);
        $fertig = 0;
        $gesamt = count($plan['dateien']);

        foreach ($plan['dateien'] as $datei) {
            if (microtime(true) - $begonnen > $budget) {
                $sftp->disconnect();

                return self::error(sprintf(
                    'Zeit abgelaufen nach %d von %d Dateien. Der Auftrag wird fortgesetzt.',
                    $fertig,
                    $gesamt
                ), true);
            }

            $fern = $wurzel . '/' . $datei['ziel'];
            $ordner = dirname($fern);

            if (!isset($angelegt[$ordner])) {
                $sftp->mkdir($ordner, -1, true);
                $angelegt[$ordner] = true;
            }

            $inhalt = $zip->getFromIndex($datei['index']);

            if ($inhalt === false || !$sftp->put($fern, $inhalt)) {
                $sftp->disconnect();

                return self::error('Die Datei ' . $datei['ziel'] . ' liess sich nicht schreiben.', true);
            }

            $fertig++;

            if ($onProgress !== null) {
                $onProgress($fertig, $gesamt, $datei['ziel']);
            }
        }

        $sftp->disconnect();

        return ['ok' => true, 'files' => $fertig, 'error' => '',
                'retryable' => false, 'verified' => false, 'url' => ''];
    }

    // ------------------------------------------------------------------
    // Herunterladen (für die Sicherung)
    // ------------------------------------------------------------------

    /**
     * Einen Ordner vom Server des Kunden holen.
     *
     * Gedacht für die tägliche Sicherung: Nur "data" interessiert, dort
     * liegen die Anfragen und die Änderungen des Kunden. Alles andere
     * lässt sich neu bauen.
     *
     * Bewusst eng gefasst: kein Ausstieg aus dem Ordner, eine Grenze für
     * Anzahl und Grösse, ein Zeitbudget. Ein Server, der zu viel liefert,
     * darf den Worker nicht ausbremsen.
     *
     * @return array<string, string> Pfad innerhalb des Ordners => Inhalt
     */
    public static function fetchDirectory(array $target, string $folder, float $budget = 25.0): array
    {
        $folder = trim($folder, '/');

        if ($folder === '' || str_contains($folder, '..')) {
            return [];
        }

        $password = Crypto::decrypt((string) ($target['secret'] ?? ''));
        if ($password === null) {
            return [];
        }

        $remote = self::cleanPath((string) $target['remote_path']) . '/' . $folder;

        try {
            $files = (string) $target['protocol'] === 'sftp'
                ? self::fetchViaSftp($target, $password, $remote, $budget)
                : self::fetchViaFtp($target, $password, $remote, $budget, (string) $target['protocol'] === 'ftps');
        } finally {
            Crypto::wipe($password);
        }

        return $files;
    }

    /**
     * Den ganzen Stand vom Kundenserver holen - direkt in ein Archiv.
     *
     * fetchDirectory() daneben war fuer die taegliche Sicherung
     * gemacht und taugt dafuer nicht: ueber FTP nicht rekursiv (die
     * Unterordner fielen einfach weg), ein leeres Ordnerargument wurde
     * abgelehnt, jeder Fehler endete in einem stillen leeren Feld - von
     * "der Ordner ist leer" nicht zu unterscheiden -, und alles landete
     * im Arbeitsspeicher. Auf geteiltem Hosting mit 128 MB ist das bei
     * einer echten Website das Ende des Auftrags.
     *
     * Deshalb hier: rekursiv, mit Grenzen an allen Enden, und jede
     * Datei wandert sofort ins Archiv statt in ein Array.
     *
     * @param callable|null $onProgress fn(int $dateien, string $pfad)
     * @return array{ok:bool, files:int, bytes:int, error:string, abgeschnitten:bool}
     */
    public static function fetchTree(
        array $target,
        \ZipArchive $zip,
        float $budget = 90.0,
        ?callable $onProgress = null
    ): array {
        $password = Crypto::decrypt((string) ($target['secret'] ?? ''));

        if ($password === null) {
            return self::baumFehler('Die gespeicherten Zugangsdaten lassen sich nicht entschluesseln.');
        }

        $wurzel = self::cleanPath((string) $target['remote_path']);

        try {
            return (string) $target['protocol'] === 'sftp'
                ? self::treeViaSftp($target, $password, $wurzel, $zip, $budget, $onProgress)
                : self::treeViaFtp($target, $password, $wurzel, $zip, $budget, $onProgress,
                    (string) $target['protocol'] === 'ftps');
        } catch (\Throwable $e) {
            Logger::warning('Herunterladen fehlgeschlagen', ['grund' => $e->getMessage()]);

            return self::baumFehler(self::kurz($e->getMessage()));
        } finally {
            Crypto::wipe($password);
        }
    }

    /** @return array{ok:bool, files:int, bytes:int, error:string, abgeschnitten:bool} */
    private static function treeViaFtp(
        array $target,
        string $password,
        string $wurzel,
        \ZipArchive $zip,
        float $budget,
        ?callable $onProgress,
        bool $verschluesselt
    ): array {
        if (!function_exists('ftp_connect')) {
            return self::baumFehler('Dieser Server kann kein FTP.');
        }

        if ($verschluesselt && !function_exists('ftp_ssl_connect')) {
            return self::baumFehler('Dieses PHP kann kein verschluesseltes FTP.');
        }

        $sauber = self::normalizeHost((string) $target['host'], (int) $target['port'] ?: 21);

        $verbindung = $verschluesselt
            ? @ftp_ssl_connect($sauber['host'], $sauber['port'], 20)
            : @ftp_connect($sauber['host'], $sauber['port'], 20);

        if ($verbindung === false) {
            return self::baumFehler('Keine Verbindung zu ' . $sauber['host'] . '.');
        }

        if (!@ftp_login($verbindung, (string) $target['username'], $password)) {
            @ftp_close($verbindung);

            return self::baumFehler('Die Anmeldung wurde abgelehnt.');
        }

        @ftp_pasv($verbindung, true);

        if (defined('FTP_USEPASVADDRESS') && @ftp_nlist($verbindung, '.') === false) {
            @ftp_set_option($verbindung, FTP_USEPASVADDRESS, false);
        }

        $stand = self::neuerStand();
        $ende = microtime(true) + $budget;

        self::ftpEinsammeln($verbindung, $wurzel, '', $zip, $stand, $ende, 0, $onProgress);

        @ftp_close($verbindung);

        return self::baumErgebnis($stand);
    }

    /**
     * Ein Verzeichnis und alles darunter.
     *
     * @param array<string, mixed> $stand
     */
    private static function ftpEinsammeln(
        $verbindung,
        string $wurzel,
        string $unterPfad,
        \ZipArchive $zip,
        array &$stand,
        float $ende,
        int $tiefe,
        ?callable $onProgress
    ): void {
        if ($tiefe > self::MAX_TREE_DEPTH || !self::baumDarfWeiter($stand, $ende)) {
            return;
        }

        $voll = rtrim($wurzel . '/' . $unterPfad, '/');
        $eintraege = @ftp_nlist($verbindung, $voll === '' ? '/' : $voll);

        if (!is_array($eintraege)) {
            // Ein Verzeichnis, das sich nicht auflisten laesst, ist
            // eine Auskunft und kein Nichts. Ohne diese Notiz saehe ein
            // gesperrter Ordner aus wie ein leerer.
            $stand['abgeschnitten'] = true;

            return;
        }

        $ordner = [];

        foreach ($eintraege as $eintrag) {
            if (!self::baumDarfWeiter($stand, $ende)) {
                return;
            }

            $name = basename((string) $eintrag);

            if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '..')) {
                continue;
            }

            $relativ = $unterPfad === '' ? $name : $unterPfad . '/' . $name;

            if (self::baumUebergehen($relativ)) {
                continue;
            }

            $fern = $voll . '/' . $name;

            // ftp_size gibt bei einem Verzeichnis -1 zurueck. Das ist
            // die Unterscheidung, die ueber blankes FTP zu haben ist -
            // ftp_mlsd gibt es nicht ueberall.
            $groesse = @ftp_size($verbindung, $fern);

            if ($groesse < 0) {
                $ordner[] = $relativ;

                continue;
            }

            if ($groesse > self::MAX_FETCH_BYTES) {
                $stand['abgeschnitten'] = true;

                continue;
            }

            $tmp = @tempnam(sys_get_temp_dir(), 'wa-pull');

            if ($tmp === false) {
                $stand['abgeschnitten'] = true;

                continue;
            }

            if (@ftp_get($verbindung, $tmp, $fern, FTP_BINARY)) {
                $zip->addFile($tmp, $relativ);

                // Die Datei muss bis zum close() des Archivs liegen
                // bleiben - ZipArchive liest sie erst dann. Weggeraeumt
                // wird sie vom Aufrufer.
                $stand['tmp'][] = $tmp;
                $stand['files']++;
                $stand['bytes'] += (int) $groesse;

                if ($onProgress !== null) {
                    $onProgress($stand['files'], $relativ);
                }
            } else {
                @unlink($tmp);
                $stand['abgeschnitten'] = true;
            }
        }

        foreach ($ordner as $relativ) {
            self::ftpEinsammeln($verbindung, $wurzel, $relativ, $zip, $stand, $ende, $tiefe + 1, $onProgress);
        }
    }

    /** @return array{ok:bool, files:int, bytes:int, error:string, abgeschnitten:bool} */
    private static function treeViaSftp(
        array $target,
        string $password,
        string $wurzel,
        \ZipArchive $zip,
        float $budget,
        ?callable $onProgress
    ): array {
        if (!class_exists(SFTP::class)) {
            return self::baumFehler('Die Bibliothek fuer SFTP fehlt im Paket.');
        }

        $sauber = self::normalizeHost((string) $target['host'], (int) $target['port'] ?: 22);
        $sftp = new SFTP($sauber['host'], $sauber['port'], 20);

        if (!$sftp->login((string) $target['username'], $password)) {
            return self::baumFehler('Die Anmeldung wurde abgelehnt.');
        }

        $stand = self::neuerStand();
        $ende = microtime(true) + $budget;

        // phpseclib kann rekursiv auflisten - das erspart einen Aufruf
        // je Verzeichnis, und ueber eine Leitung mit Latenz ist das der
        // Unterschied zwischen Sekunden und Minuten.
        $liste = $sftp->nlist($wurzel, true);

        foreach (is_array($liste) ? $liste : [] as $eintrag) {
            if (!self::baumDarfWeiter($stand, $ende)) {
                break;
            }

            $relativ = ltrim((string) $eintrag, './');

            if ($relativ === '' || str_contains($relativ, '..') || self::baumUebergehen($relativ)) {
                continue;
            }

            if (substr_count($relativ, '/') > self::MAX_TREE_DEPTH) {
                continue;
            }

            $fern = rtrim($wurzel, '/') . '/' . $relativ;

            if ($sftp->is_dir($fern)) {
                continue;
            }

            $inhalt = $sftp->get($fern);

            if (!is_string($inhalt)) {
                $stand['abgeschnitten'] = true;

                continue;
            }

            if (strlen($inhalt) > self::MAX_FETCH_BYTES) {
                $stand['abgeschnitten'] = true;

                continue;
            }

            $zip->addFromString($relativ, $inhalt);

            $stand['files']++;
            $stand['bytes'] += strlen($inhalt);

            if ($onProgress !== null) {
                $onProgress($stand['files'], $relativ);
            }
        }

        $sftp->disconnect();

        return self::baumErgebnis($stand);
    }

    /** @return array<string, mixed> */
    private static function neuerStand(): array
    {
        return ['files' => 0, 'bytes' => 0, 'abgeschnitten' => false, 'tmp' => []];
    }

    /**
     * Ist noch Luft?
     *
     * @param array<string, mixed> $stand
     */
    private static function baumDarfWeiter(array &$stand, float $ende): bool
    {
        if ($stand['files'] >= self::MAX_TREE_FILES
            || $stand['bytes'] >= self::MAX_TREE_BYTES
            || microtime(true) >= $ende
        ) {
            $stand['abgeschnitten'] = true;

            return false;
        }

        return true;
    }

    /**
     * Was beim Herunterladen nichts zu suchen hat.
     *
     * Sicherungen einer Sicherung sind der klassische Weg, aus einer
     * 20-MB-Website ein 400-MB-Archiv zu machen.
     */
    private static function baumUebergehen(string $relativ): bool
    {
        foreach (self::UEBERGEHEN as $muster) {
            if ($relativ === $muster || str_starts_with($relativ, $muster . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $stand
     * @return array{ok:bool, files:int, bytes:int, error:string, abgeschnitten:bool, tmp:array<int,string>}
     */
    private static function baumErgebnis(array $stand): array
    {
        return [
            'ok' => $stand['files'] > 0,
            'files' => (int) $stand['files'],
            'bytes' => (int) $stand['bytes'],
            'error' => $stand['files'] > 0
                ? ''
                : 'Es kam keine einzige Datei an. Stimmen Zugang und Verzeichnis?',
            'abgeschnitten' => (bool) $stand['abgeschnitten'],
            'tmp' => $stand['tmp'] ?? [],
        ];
    }

    /** @return array{ok:bool, files:int, bytes:int, error:string, abgeschnitten:bool, tmp:array<int,string>} */
    private static function baumFehler(string $text): array
    {
        return [
            'ok' => false, 'files' => 0, 'bytes' => 0,
            'error' => $text, 'abgeschnitten' => false, 'tmp' => [],
        ];
    }

    /** @return array<string, string> */
    private static function fetchViaSftp(array $target, string $password, string $remote, float $budget): array
    {
        if (!class_exists(SFTP::class)) {
            return [];
        }

        $sauber = self::normalizeHost((string) $target['host'], (int) $target['port'] ?: 22);
        $sftp = new SFTP($sauber['host'], $sauber['port'], 20);

        if (!$sftp->login((string) $target['username'], $password)) {
            return [];
        }

        $out = [];
        $started = microtime(true);

        $list = $sftp->nlist($remote, true);

        foreach (is_array($list) ? $list : [] as $entry) {
            if (microtime(true) - $started > $budget || count($out) >= self::MAX_FETCH_FILES) {
                break;
            }

            $name = ltrim((string) $entry, './');

            if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '..')) {
                continue;
            }

            if ($sftp->is_dir($remote . '/' . $name)) {
                continue;
            }

            $content = $sftp->get($remote . '/' . $name);

            if (is_string($content) && strlen($content) <= self::MAX_FETCH_BYTES) {
                $out[$name] = $content;
            }
        }

        $sftp->disconnect();

        return $out;
    }

    /** @return array<string, string> */
    private static function fetchViaFtp(
        array $target,
        string $password,
        string $remote,
        float $budget,
        bool $secure
    ): array {
        if (!function_exists('ftp_connect')) {
            return [];
        }

        $sauber = self::normalizeHost((string) $target['host'], (int) $target['port'] ?: 21);
        $host = $sauber['host'];
        $port = $sauber['port'];

        $connection = $secure ? @ftp_ssl_connect($host, $port, 20) : @ftp_connect($host, $port, 20);

        if ($connection === false) {
            return [];
        }

        if (!@ftp_login($connection, (string) $target['username'], $password)) {
            @ftp_close($connection);
            return [];
        }

        @ftp_pasv($connection, true);

        $out = [];
        $started = microtime(true);
        $names = @ftp_nlist($connection, $remote);

        // Leere Antwort trotz sitzender Anmeldung: fast immer eine
        // interne Adresse aus dem Passivmodus. Einmal ohne sie.
        if ($names === false && defined('FTP_USEPASVADDRESS')) {
            @ftp_set_option($connection, FTP_USEPASVADDRESS, false);
            $names = @ftp_nlist($connection, $remote);
        }

        foreach (is_array($names) ? $names : [] as $entry) {
            if (microtime(true) - $started > $budget || count($out) >= self::MAX_FETCH_FILES) {
                break;
            }

            $name = basename((string) $entry);

            if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '..')) {
                continue;
            }

            $size = @ftp_size($connection, $remote . '/' . $name);

            // -1 heisst: kein Grössenwert – meistens ein Unterordner.
            if ($size < 0 || $size > self::MAX_FETCH_BYTES) {
                continue;
            }

            $stream = fopen('php://temp', 'r+');

            if ($stream === false) {
                continue;
            }

            if (@ftp_fget($connection, $stream, $remote . '/' . $name, FTP_BINARY)) {
                rewind($stream);
                $out[$name] = (string) stream_get_contents($stream);
            }

            fclose($stream);
        }

        @ftp_close($connection);

        return $out;
    }

    /** Verzeichnisse Stück für Stück anlegen. */
    private static function ensureRemoteDir($connection, string $path): void
    {
        $parts = array_filter(explode('/', trim($path, '/')));
        $current = '';

        foreach ($parts as $part) {
            $current .= '/' . $part;
            if (@ftp_chdir($connection, $current)) {
                continue;
            }
            @ftp_mkdir($connection, $current);
        }
        @ftp_chdir($connection, '/');
    }

    // ------------------------------------------------------------------

    /** Zugangsdaten speichern – das Passwort verschlüsselt. */
    public static function saveTarget(int $projectId, array $data): int
    {
        $existing = Db::first(
            'SELECT id, secret FROM deploy_targets WHERE project_id = :p ORDER BY id DESC LIMIT 1',
            ['p' => $projectId]
        );

        $password = (string) ($data['password'] ?? '');

        // Leeres Feld heisst "unverändert lassen", nicht "löschen".
        $secret = $password !== ''
            ? Crypto::encrypt($password)
            : (string) ($existing['secret'] ?? '');

        // Was hier ankommt, ist kopiert - und oft ist mehr mitgekommen
        // als der Servername. Einmal zurechtruecken, bevor es in die
        // Datenbank geht: Sonst steht der Fehler dauerhaft darin und der
        // Test sucht ihn jedes Mal neu.
        $sauber = self::normalizeHost(
            (string) ($data['host'] ?? ''),
            (int) ($data['port'] ?? 22)
        );

        $values = [
            'protocol' => in_array($data['protocol'] ?? '', ['ftp', 'ftps', 'sftp'], true) ? $data['protocol'] : 'sftp',
            'host' => $sauber['host'],
            'port' => $sauber['port'],
            'username' => mb_substr(trim((string) ($data['username'] ?? '')), 0, 190),
            'secret' => $secret,
            'remote_path' => self::cleanPath((string) ($data['path'] ?? '/public_html')),
            // Null und nicht 0: Die Spalte ist eine Verknuepfung, und
            // "keine" heisst dort NULL. Eine 0 zeigte auf ein Konto,
            // das es nicht gibt.
            'hosting_account_id' => ((int) ($data['hosting_account_id'] ?? 0)) ?: null,
            'updated_at' => Db::now(),
        ];

        if ($existing !== null) {
            Db::update('deploy_targets', $values, 'id = :id', ['id' => (int) $existing['id']]);
            return (int) $existing['id'];
        }

        return Db::insert('deploy_targets', array_merge($values, [
            'project_id' => $projectId,
            'created_at' => Db::now(),
        ]));
    }

    /**
     * Das Ziel einer Website - mit dem Hosting-Zugang aufgeloest.
     *
     * Alle Websites liegen auf demselben Konto: Server, Benutzername
     * und Passwort sind jedes Mal dieselben, nur das Verzeichnis
     * unterscheidet sich. Steht ein Hosting-Zugang am Ziel, kommen die
     * drei von dort und nur `remote_path` von der Website.
     *
     * Steht keiner daran, bleibt alles wie bisher. Das ist die
     * Bedingung dafuer, diese Aenderung ueberhaupt einspielen zu
     * duerfen: Was hinterlegt ist, funktioniert unveraendert weiter.
     */
    public static function targetFor(int $projectId): ?array
    {
        $target = Db::first(
            'SELECT * FROM deploy_targets WHERE project_id = :p ORDER BY id DESC LIMIT 1',
            ['p' => $projectId]
        );

        return $target === null ? null : self::withAccount($target);
    }

    /**
     * Die Zugangsdaten eines gemeinsamen Kontos einsetzen.
     *
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    public static function withAccount(array $target): array
    {
        $kontoId = (int) ($target['hosting_account_id'] ?? 0);

        if ($kontoId <= 0) {
            return $target;
        }

        $konto = Db::first('SELECT * FROM hosting_accounts WHERE id = :id', ['id' => $kontoId]);

        if ($konto === null) {
            // Das Konto ist weg, das Ziel zeigt ins Leere. Lieber mit
            // dem, was am Ziel selbst steht, weitermachen als gar
            // nichts - und der Verbindungstest sagt dann, was fehlt.
            return $target;
        }

        foreach (['protocol', 'host', 'port', 'username', 'secret'] as $feld) {
            $target[$feld] = $konto[$feld];
        }

        return $target;
    }

    /**
     * Den Servernamen zurechtruecken.
     *
     * In dieses Feld wird kopiert, was der Anbieter irgendwo anzeigt -
     * und das ist oft mehr als ein Servername: ein "ftp://" davor, ein
     * Pfad dahinter, ein ":21" am Ende. Wortwoertlich verwendet ergibt
     * jedes davon denselben roten Kasten, und keiner sagt warum.
     *
     * @return array{host:string, port:int, hinweis:string}
     */
    public static function normalizeHost(string $host, int $port): array
    {
        $roh = trim($host);
        $hinweis = '';

        if (preg_match('#^([a-z][a-z0-9+.\-]*)://#i', $roh, $treffer) === 1) {
            $roh = substr($roh, strlen($treffer[0]));
            $hinweis = 'Das „' . $treffer[1] . '://“ gehoert nicht in dieses Feld.';
        }

        // "benutzer:passwort@server" - der Teil davor ist kein Server.
        $at = strrpos($roh, '@');

        if ($at !== false) {
            $roh = substr($roh, $at + 1);
            $hinweis = 'Der Benutzername gehoert in sein eigenes Feld, nicht vor den Server.';
        }

        $schraeg = strpos($roh, '/');

        if ($schraeg !== false) {
            $roh = substr($roh, 0, $schraeg);
            $hinweis = 'Der Pfad hinter dem Servernamen gehoert ins Feld „Verzeichnis“.';
        }

        // Ein angehaengter Port wandert ins Portfeld. Die Bedingung
        // schuetzt IPv6-Adressen, die selbst voller Doppelpunkte sind.
        if (preg_match('/^([^:]+):(\d{1,5})$/', $roh, $treffer) === 1) {
            $roh = $treffer[1];
            $port = max(1, min(65535, (int) $treffer[2]));
            $hinweis = 'Der Port stand am Servernamen und ist jetzt im Portfeld.';
        }

        $roh = trim($roh, " \t\n\r\0\x0B.");

        return [
            'host' => mb_substr($roh, 0, 190),
            'port' => max(1, min(65535, $port)),
            'hinweis' => $hinweis,
        ];
    }

    /**
     * Loest dieser Name auf?
     *
     * gethostbyname() gibt bei Misserfolg die Eingabe unveraendert
     * zurueck - das ist die Pruefung, die ohne zusaetzliche Erweiterung
     * ueberall funktioniert.
     */
    private static function loestAuf(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return @gethostbyname($host) !== $host;
    }

    /**
     * Die Verbindung pruefen - Stufe fuer Stufe.
     *
     * Frueher war das Ergebnis ein einziges Ja oder Nein, und es war am
     * Ende schlicht der Rueckgabewert von ftp_chdir(). Eine tadellose
     * Anmeldung mit falschem Ordner sah damit exakt so aus wie ein
     * Server, den es gar nicht gibt. Beides "Verbindung fehlgeschlagen",
     * beide Male dieselbe Ratlosigkeit.
     *
     * Jetzt wird jede Stufe einzeln gemeldet: Servername, Verbindung,
     * Anmeldung, Passivmodus, Startordner, Inhalt, Zielordner,
     * Schreibprobe. Die erste rote Stufe ist die Diagnose - und die
     * gruenen davor sind der Beweis, dass alles andere stimmt.
     *
     * @return array{ok:bool, message:string, stufen:array<int, array{name:string, ok:bool, info:string}>, ordner:array<int,string>, vorschlag:string}
     */
    public static function test(int $projectId): array
    {
        $target = self::targetFor($projectId);

        if ($target === null) {
            return self::pruefErgebnis(false, 'Es sind keine Zugangsdaten hinterlegt.');
        }

        $password = Crypto::decrypt((string) ($target['secret'] ?? ''));

        if ($password === null) {
            return self::pruefErgebnis(false, 'Die Zugangsdaten lassen sich nicht entschluesseln.');
        }

        $sauber = self::normalizeHost((string) $target['host'], (int) $target['port']);
        $host = $sauber['host'];
        $port = $sauber['port'];
        $user = (string) $target['username'];
        $protokoll = (string) $target['protocol'];
        $pfad = self::cleanPath((string) $target['remote_path']);

        // Ein Handschlag ueber SFTP kann auf schwachen Servern dauern.
        @set_time_limit(90);

        $stufen = [];

        // ------------------------------------------------- Stufe: Name
        if (!self::loestAuf($host)) {
            $stufen[] = self::stufe('Servername', false, self::namensHilfe($host));

            Crypto::wipe($password);

            return self::pruefErgebnis(
                false,
                self::namensHilfe($host),
                [],
                '',
                $stufen,
                // Zum Anklicken statt zum Abtippen: Der Name, der
                // auflöst, ist die halbe Antwort - er soll nicht noch
                // einmal von Hand richtig getroffen werden müssen.
                self::namensAlternative($host)
            );
        }

        $stufen[] = self::stufe('Servername', true, $host . ' loest auf.');

        if ($sauber['hinweis'] !== '') {
            $stufen[] = self::stufe('Hinweis', true, $sauber['hinweis']);
        }

        try {
            return $protokoll === 'sftp'
                ? self::testSftp($host, $port, $user, $password, $pfad, $stufen)
                : self::testFtp($host, $port, $user, $password, $pfad, $protokoll === 'ftps', $stufen);
        } catch (\Throwable $e) {
            Logger::warning('Verbindungstest fehlgeschlagen', [
                'host' => $host,
                'protokoll' => $protokoll,
                'grund' => $e->getMessage(),
            ]);

            $stufen[] = self::stufe('Abbruch', false, self::kurz($e->getMessage()));

            return self::pruefErgebnis(
                false,
                'Die Verbindung ist fehlgeschlagen: ' . self::kurz($e->getMessage()),
                [],
                '',
                $stufen
            );
        } finally {
            Crypto::wipe($password);
        }
    }

    /**
     * Was tun, wenn es den Servernamen nicht gibt?
     *
     * Genau hier faellt der haeufigste Fall an: "ftp." vor die Domain
     * geschrieben, weil es bei manchen Anbietern so heisst. Bei cPanel -
     * und damit bei GoDaddy - gibt es diesen Eintrag nicht.
     */
    /**
     * Der andere Name - der, der auflöst.
     *
     * GoDaddy zeigt in cPanel `ftp.deine-domain.ch` an. Das ist keine
     * Erfindung, das steht dort wirklich; nur führt die DNS-Zone den
     * Eintrag nicht immer, und dann gibt es den Server schlicht nicht.
     * Umgekehrt kommt genauso vor: Bei manchen Anbietern gibt es den
     * `ftp`-Eintrag, und die blosse Domain zeigt woandershin.
     *
     * Deshalb wird beides probiert und der genannt, der antwortet -
     * statt einer Regel, die bei jedem zweiten Anbieter falsch ist.
     */
    private static function namensAlternative(string $host): string
    {
        $andere = str_starts_with($host, 'ftp.')
            ? substr($host, 4)
            : 'ftp.' . $host;

        // Bei einer nackten Domain wie "beispiel" gibt es nichts
        // abzuschneiden, und "ftp.beispiel" waere geraten.
        if ($andere === '' || !str_contains($andere, '.')) {
            return '';
        }

        return self::loestAuf($andere) ? $andere : '';
    }

    private static function namensHilfe(string $host): string
    {
        $meldung = 'Diesen Servernamen gibt es nicht: ' . $host
            . '. Es wurde also nie ein Server abgewiesen - es wurde keiner gefunden.';

        $andere = self::namensAlternative($host);

        if ($andere !== '') {
            return $meldung . ' Aber ' . $andere . ' loest auf - trage den ein.'
                . (str_starts_with($host, 'ftp.')
                    ? ' GoDaddy zeigt zwar „ftp.“ davor an; den Eintrag gibt es'
                        . ' in der DNS-Zone aber nicht immer, und dann geht nur die'
                        . ' Domain selbst.'
                    : '');
        }

        if (str_starts_with($host, 'ftp.')) {
            return $meldung . ' Auch ' . substr($host, 4) . ' antwortet nicht.'
                . ' In cPanel steht der Servername rechts unter „Allgemeine'
                . ' Informationen“ - der geht immer, auch wenn er lang aussieht.';
        }

        return $meldung . ' Tippfehler? Sonst hilft der Servername aus cPanel'
            . ' (rechts unter „Allgemeine Informationen“) oder die Domain selbst.';
    }

    /** Eine einzelne Stufe des Tests. */
    private static function stufe(string $name, bool $ok, string $info): array
    {
        return ['name' => $name, 'ok' => $ok, 'info' => $info];
    }

    /**
     * Der Ordner, der vermutlich gemeint ist.
     *
     * Zwei Faelle, und sie sehen von aussen gleich aus:
     *
     * Ein cPanel-Unterkonto (Benutzername mit @) wird beim Anlegen auf
     * sein Verzeichnis festgenagelt. Nach der Anmeldung ist man bereits
     * darin - der Pfad, den man in cPanel gesehen hat, existiert von
     * dort aus nicht mehr, weil er die Wurzel geworden ist. Richtig ist
     * dann "/".
     *
     * Das Hauptkonto sieht dagegen public_html neben sich liegen. Dann
     * ist der volle Pfad richtig.
     *
     * Unterschieden wird an dem, was oben liegt: eine Startseite an der
     * Wurzel heisst festgenagelt, ein public_html daneben heisst
     * Hauptkonto.
     *
     * @param array<int, string> $obenAuf Namen im Startordner
     */
    private static function ordnerVorschlag(array $obenAuf, string $heim, string $user): string
    {
        $klein = array_map('mb_strtolower', $obenAuf);

        foreach (['public_html', 'httpdocs', 'www', 'web'] as $name) {
            if (in_array($name, $klein, true)) {
                return self::cleanPath(rtrim($heim, '/') . '/' . $name);
            }
        }

        // Keine Web-Wurzel daneben, aber eine Startseite darin: Das
        // Konto sitzt bereits im Zielordner.
        foreach (['index.html', 'index.php', 'index.htm', 'wp-config.php', 'assets'] as $name) {
            if (in_array($name, $klein, true)) {
                return $heim === '' ? '/' : self::cleanPath($heim);
            }
        }

        // Ein Unterkonto ohne erkennbaren Inhalt: Die @-Form ist bei
        // cPanel der zuverlaessigste Hinweis auf ein festgenageltes Konto.
        if (str_contains($user, '@')) {
            return $heim === '' ? '/' : self::cleanPath($heim);
        }

        return '';
    }

    /** @return array{ok:bool, message:string, stufen:array<int, array{name:string, ok:bool, info:string}>, ordner:array<int,string>, vorschlag:string} */
    private static function testSftp(
        string $host,
        int $port,
        string $user,
        string $password,
        string $pfad,
        array $stufen = []
    ): array {
        if (!class_exists(SFTP::class)) {
            $stufen[] = self::stufe('Verbindung', false, 'Die Bibliothek fuer SFTP fehlt im Paket.');

            return self::pruefErgebnis(false, 'Die Bibliothek fuer SFTP fehlt im Paket.', [], '', $stufen);
        }

        $sftp = new SFTP($host, $port ?: 22, 20);

        if (!$sftp->isConnected()) {
            $meldung = 'Der Server ist da, nimmt auf Port ' . ($port ?: 22)
                . ' aber keine SSH-Verbindung an. Bei cPanel ist SFTP oft nicht'
                . ' freigeschaltet - dann ist FTP auf Port 21 der richtige Weg.';
            $stufen[] = self::stufe('Verbindung', false, $meldung);

            return self::pruefErgebnis(false, $meldung, [], '', $stufen);
        }

        $stufen[] = self::stufe('Verbindung', true, 'Port ' . ($port ?: 22) . ' antwortet.');

        if (!$sftp->login($user, $password)) {
            $meldung = self::anmeldeHilfe($user);
            $stufen[] = self::stufe('Anmeldung', false, $meldung);

            return self::pruefErgebnis(false, $meldung, [], '', $stufen);
        }

        $stufen[] = self::stufe('Anmeldung', true, 'Benutzer ' . $user . ' angenommen.');

        $daHeim = (string) ($sftp->pwd() ?: '/');
        $stufen[] = self::stufe('Startordner', true, 'Nach der Anmeldung stehst du in ' . $daHeim . '.');

        // Dieselbe Unterscheidung wie bei FTP: false heisst gescheitert,
        // ein leeres Feld heisst leer.
        $roh = $sftp->nlist($daHeim);
        $inhalt = ['gelesen' => $roh !== false, 'namen' => self::nurNamen((array) ($roh ?: []))];
        $obenAuf = $inhalt['namen'];

        $stufen[] = self::stufe(
            'Inhalt lesen',
            $inhalt['gelesen'],
            self::inhaltMeldung($inhalt, $daHeim)
        );

        $ordner = self::verzeichnisseSftp($sftp, $pfad, $daHeim);
        $vorhanden = $sftp->is_dir($pfad);

        $stufen[] = self::stufe(
            'Zielordner',
            $vorhanden,
            $vorhanden
                ? $pfad . ' ist vorhanden.'
                : $pfad . ' gibt es von diesem Zugang aus nicht.'
        );

        // Wie bei FTP: eigene Frage, eigenes Urteil. Die Schreibprobe
        // darf nicht sagen, den Ordner gebe es nicht.
        $schreibbar = null;

        if ($vorhanden) {
            $probe = rtrim($pfad, '/') . '/.webatze-probe-' . bin2hex(random_bytes(4));
            $schreibbar = (bool) $sftp->put($probe, 'webatze');

            if ($schreibbar) {
                $sftp->delete($probe);
            }

            $stufen[] = self::stufe(
                'Schreibprobe',
                $schreibbar,
                $schreibbar
                    ? 'Datei angelegt und wieder entfernt - der Zugang darf schreiben.'
                    : self::schreibHilfe($pfad)
            );
        }

        $sftp->disconnect();

        $vorschlag = $vorhanden ? '' : self::ordnerVorschlag($obenAuf, $daHeim, $user);

        return self::pruefErgebnis(
            (bool) $vorhanden,
            self::endMeldung((bool) $vorhanden, $schreibbar, $pfad, $vorschlag, $user),
            $ordner,
            $vorschlag !== '' ? $vorschlag : self::vorschlagen($ordner, $pfad),
            $stufen
        );
    }

    /** @return array{ok:bool, message:string, stufen:array<int, array{name:string, ok:bool, info:string}>, ordner:array<int,string>, vorschlag:string} */
    private static function testFtp(
        string $host,
        int $port,
        string $user,
        string $password,
        string $pfad,
        bool $verschluesselt,
        array $stufen = []
    ): array {
        if (!function_exists('ftp_connect')) {
            $meldung = 'Dieser Server kann kein FTP - die passende PHP-Erweiterung ist nicht '
                . 'eingebaut. Stelle im Formular auf SFTP um (Port 22). Das bringt '
                . 'WebAtze selbst mit und ist ausserdem verschluesselt.';
            $stufen[] = self::stufe('Verbindung', false, $meldung);

            return self::pruefErgebnis(false, $meldung, [], '', $stufen);
        }

        // Ohne diese Pruefung ist ein PHP ohne OpenSSL kein Fehler,
        // den man melden kann, sondern ein Absturz.
        if ($verschluesselt && !function_exists('ftp_ssl_connect')) {
            $meldung = 'Dieses PHP kann kein verschluesseltes FTP (es fehlt OpenSSL). '
                . 'Stelle auf einfaches FTP um oder - besser - auf SFTP.';
            $stufen[] = self::stufe('Verbindung', false, $meldung);

            return self::pruefErgebnis(false, $meldung, [], '', $stufen);
        }

        $port = $port ?: 21;

        $verbindung = $verschluesselt
            ? @ftp_ssl_connect($host, $port, 15)
            : @ftp_connect($host, $port, 15);

        if ($verbindung === false) {
            $meldung = 'Der Servername stimmt, aber auf Port ' . $port . ' antwortet nichts. '
                . ($port === 22
                    ? 'Port 22 ist SSH - fuer FTP ist es 21.'
                    : 'Ist der Port richtig? FTP ist 21, FTP mit Verschluesselung meist auch 21, SFTP ist 22.');
            $stufen[] = self::stufe('Verbindung', false, $meldung);

            return self::pruefErgebnis(false, $meldung, [], '', $stufen);
        }

        $stufen[] = self::stufe('Verbindung', true, 'Port ' . $port . ' antwortet.');

        if (!@ftp_login($verbindung, $user, $password)) {
            @ftp_close($verbindung);

            $meldung = self::anmeldeHilfe($user);
            $stufen[] = self::stufe('Anmeldung', false, $meldung);

            return self::pruefErgebnis(false, $meldung, [], '', $stufen);
        }

        $stufen[] = self::stufe('Anmeldung', true, 'Benutzer ' . $user . ' angenommen.');

        $passiv = @ftp_pasv($verbindung, true);
        $stufen[] = self::stufe(
            'Passivmodus',
            (bool) $passiv,
            $passiv
                ? 'Passivmodus aktiv - das ist der Modus, der durch Firewalls kommt.'
                : 'Der Server lehnt den Passivmodus ab. Ohne ihn scheitert jede Uebertragung.'
        );

        $daHeim = (string) (@ftp_pwd($verbindung) ?: '/');
        $stufen[] = self::stufe('Startordner', true, 'Nach der Anmeldung stehst du in ' . $daHeim . '.');

        $inhalt = self::inhaltOben($verbindung, $daHeim);

        /**
         * Nach einem gescheiterten Datentransfer ist die Leitung hin.
         *
         * Nachgemessen gegen einen echten FTPS-Server: Bricht eine
         * Uebertragung ab, liegt der Steuerkanal danach um eine Antwort
         * versetzt. ftp_chdir bekommt das "226 Fertig" des vorigen
         * Befehls und meldet false, ftp_pwd bekommt das "250 Ok" von
         * chdir und meldet false. Jede Stufe nach dem ersten Fehlschlag
         * war damit erfunden - der Zielordner "gibt es nicht", obwohl
         * der Server ihn zwei Zeilen weiter mit 250 bestaetigt hatte.
         *
         * Ohne Verschluesselung tritt das nicht auf; mit ist es der
         * Normalfall, weil viele FTP-Server die Datenverbindung ohne
         * sauberen TLS-Abschluss schliessen.
         *
         * Also: ab hier auf einer frischen Leitung weiterarbeiten.
         */
        if (!$inhalt['gelesen']) {
            $neu = self::neueLeitung($verbindung, $host, $port, $user, $password, $verschluesselt);

            if ($neu === null) {
                $stufen[] = self::stufe('Inhalt lesen', false, self::inhaltMeldung($inhalt, $daHeim));
                $stufen[] = self::stufe('Neue Leitung', false,
                    'Nach dem Fehlschlag liess sich keine zweite Verbindung aufbauen. '
                    . 'Mehr laesst sich von hier aus nicht feststellen.');

                return self::pruefErgebnis(false, self::inhaltMeldung($inhalt, $daHeim), [], '', $stufen);
            }

            $verbindung = $neu;

            // Meldet der Server im Passivmodus eine interne Adresse - auf
            // geteiltem Hosting hinter NAT die Regel -, laeuft die
            // Datenverbindung ins Leere. Dann noch einmal, mit der
            // Adresse, die wir kennen. Auf der frischen Leitung.
            if (defined('FTP_USEPASVADDRESS')) {
                @ftp_set_option($verbindung, FTP_USEPASVADDRESS, false);
                $zweiter = self::inhaltOben($verbindung, $daHeim);

                if ($zweiter['gelesen']) {
                    $inhalt = $zweiter;
                    $stufen[] = self::stufe(
                        'Passivadresse',
                        true,
                        'Der Server nannte im Passivmodus eine interne Adresse. '
                        . 'Ich habe stattdessen die bekannte verwendet - das ist beim '
                        . 'Hochladen genauso noetig.'
                    );
                } else {
                    $verbindung = self::neueLeitung($verbindung, $host, $port, $user, $password, $verschluesselt)
                        ?? $verbindung;
                }
            }
        }

        $obenAuf = $inhalt['namen'];

        $stufen[] = self::stufe(
            'Inhalt lesen',
            $inhalt['gelesen'],
            self::inhaltMeldung($inhalt, $daHeim)
        );

        // Ist das Lesen gescheitert, wird jetzt genau nachgesehen, woran.
        //
        // "Meist eine blockierte Datenverbindung" ist eine Vermutung, und
        // mit einer Vermutung geht man nicht zum Hoster. Die naechste
        // Stufe fragt den Server selbst: Welche Adresse und welchen Port
        // nennt er im Passivmodus, und was passiert, wenn man dort
        // anklopft? Abgewiesen, keine Antwort oder offen - drei
        // verschiedene Ursachen mit drei verschiedenen Zustaendigkeiten.
        if (!$inhalt['gelesen']) {
            $stufen[] = self::datenStufe($verbindung, $host, $port);
            $stufen[] = self::merkmalStufe($verbindung, $verschluesselt);
        }

        /**
         * Erst fragen, was zaehlt - erkundet wird ganz zuletzt.
         *
         * Vorher stand das Erkunden hier oben, und es hat den Test
         * ueber verschluesselte Verbindungen zuverlaessig ruiniert:
         * verzeichnisseFtp probiert der Reihe nach Pfade durch, die es
         * meist nicht gibt. Jeder Fehlversuch bricht eine Datenver-
         * bindung ab, und danach liegt der Steuerkanal um eine Antwort
         * versetzt (gemessen). Die naechste Frage - "gibt es den
         * Zielordner?" - bekam dann die Antwort der vorletzten und
         * meldete "gibt es nicht", waehrend der Server zwei Zeilen
         * weiter unten "250 Ok" gesagt hatte.
         *
         * Also: chdir und Schreibprobe zuerst, auf der unversehrten
         * Leitung. Das Erkunden kommt danach, auf einer frischen - und
         * nur dann, wenn es etwas vorzuschlagen gibt.
         */
        $vorhanden = @ftp_chdir($verbindung, $pfad);

        $stufen[] = self::stufe(
            'Zielordner',
            (bool) $vorhanden,
            $vorhanden
                ? $pfad . ' ist vorhanden.'
                : $pfad . ' gibt es von diesem Zugang aus nicht.'
        );

        // Die Schreibprobe ist eine eigene Frage und darf das Urteil
        // ueber den Ordner nicht ueberschreiben.
        //
        // Genau das tat sie: $vorhanden wurde mit dem Ergebnis der
        // Schreibprobe ueberschrieben, und dann meldete die
        // Zusammenfassung "den Ordner gibt es nicht" - obwohl die Stufe
        // darueber gruen war und der Ordner sehr wohl da. Eine Meldung,
        // die der Stufenkette direkt widerspricht, ist schlimmer als
        // gar keine: Man sucht dann an der falschen Stelle.
        $schreibbar = null;

        if ($vorhanden) {
            $schreibbar = self::schreibprobeFtp($verbindung, $pfad);

            $stufen[] = self::stufe(
                'Schreibprobe',
                $schreibbar,
                $schreibbar
                    ? 'Datei angelegt und wieder entfernt - der Zugang darf schreiben.'
                    : self::schreibHilfe($pfad)
            );
        }

        // Jetzt erst erkunden - und nur, wenn ein Vorschlag gebraucht
        // wird. Steht der Ordner, ist die Liste nur Beiwerk und das
        // Risiko einer zerschossenen Leitung nicht wert.
        $ordner = [];

        if (!$vorhanden) {
            $frisch = self::neueLeitung($verbindung, $host, $port, $user, $password, $verschluesselt);

            if ($frisch !== null) {
                $verbindung = $frisch;
                $ordner = self::verzeichnisseFtp($verbindung, $pfad, $daHeim);
            }
        }

        @ftp_close($verbindung);

        $vorschlag = $vorhanden ? '' : self::ordnerVorschlag($obenAuf, $daHeim, $user);

        return self::pruefErgebnis(
            (bool) $vorhanden,
            self::endMeldung((bool) $vorhanden, $schreibbar, $pfad, $vorschlag, $user),
            $ordner,
            $vorschlag !== '' ? $vorschlag : self::vorschlagen($ordner, $pfad),
            $stufen
        );
    }

    /**
     * Die Zusammenfassung - und zwar dieselbe Auskunft wie die Stufen.
     *
     * Der Ordner und das Schreiben sind zwei Fragen. Ein Zugang, der
     * lesen aber nicht schreiben darf, ist zum Stand-Holen vollkommen
     * brauchbar und nur zum Hochladen nicht - das gehoert gesagt,
     * statt beides in ein rotes "geht nicht" zu werfen.
     */
    private static function endMeldung(
        bool $vorhanden,
        ?bool $schreibbar,
        string $pfad,
        string $vorschlag,
        string $user
    ): string {
        if (!$vorhanden) {
            return self::zielHilfe($pfad, $vorschlag, $user);
        }

        if ($schreibbar === false) {
            return 'Angemeldet, und ' . $pfad . ' ist da - aber das Schreiben hat nicht '
                . 'geklappt. Zum Herunterladen des aktuellen Stands reicht das; zum '
                . 'Hochladen nicht. ' . self::schreibHilfe($pfad);
        }

        return 'Alles bereit: angemeldet, ' . $pfad . ' vorhanden und beschreibbar.';
    }

    /**
     * Warum das Schreiben scheitern kann.
     *
     * Zwei Ursachen, und sie sehen von aussen gleich aus: Entweder darf
     * der Zugang dort nicht schreiben, oder die Datenverbindung kommt
     * nicht zustande. Das Auflisten geht dabei manchmal trotzdem - es
     * benutzt dieselbe Art Verbindung, aber nicht dieselbe.
     */
    private static function schreibHilfe(string $pfad): string
    {
        return 'Entweder darf dieser Zugang in ' . $pfad . ' nicht schreiben - bei '
            . 'cPanel ist das Heimatverzeichnis oft schreibgeschuetzt, dann gehoert '
            . 'public_html an den Pfad -, oder die Datenverbindung wird unterwegs '
            . 'blockiert. Mit FTP mit Verschluesselung (Port 21) geht Letzteres '
            . 'haeufiger durch.';
    }

    /**
     * Was im Startordner liegt - und ob wir ueberhaupt nachsehen konnten.
     *
     * Der Unterschied ist der ganze Punkt. Vorher stand hier eine
     * Namensliste, und "leer" hiess damit dasselbe wie "gescheitert":
     *
     *     Inhalt lesen: Der Startordner liess sich nicht auflisten -
     *     meist eine blockierte Datenverbindung.
     *
     * Genau das bekam ein frisch angelegter FTP-Zugang zu sehen, dessen
     * Ordner fuer die neue Website noch leer war. Die Datenverbindung
     * stand tadellos - die Schreibprobe zwei Stufen weiter unten lief ja
     * durch -, aber die Meldung schickte ihn auf die Suche nach einer
     * Firewall, die es nicht gab. Ein leerer Ordner ist der Normalfall,
     * bevor die erste Website hochgeht.
     *
     * ftp_nlist gibt false zurueck, wenn die Datenverbindung scheitert,
     * und ein leeres Feld, wenn der Ordner leer ist. Manche Server
     * antworten auf einen leeren Ordner allerdings auch mit false -
     * deshalb fragt ftp_rawlist noch einmal nach. Kommt von dort ein
     * Feld, hat die Datenverbindung gestanden.
     *
     * @return array{gelesen:bool, namen:array<int, string>}
     */
    private static function inhaltOben($verbindung, string $heim): array
    {
        // Was PHP dabei bemaengelt, ist die halbe Diagnose: "SSL read
        // failed" ist ein anderes Gespraech mit dem Hoster als
        // "php_connect_nonb() failed". Ohne das Einfangen landet der
        // Satz im Fehlerprotokoll des Servers - also dort, wo ihn
        // niemand sucht.
        $grund = '';
        $sammeln = static function (int $n, string $text) use (&$grund): bool {
            if ($grund === '') {
                $grund = trim((string) preg_replace('/^ftp_\w+\(\):\s*/', '', $text));
            }

            return true;
        };

        set_error_handler($sammeln);

        try {
            $roh = ftp_nlist($verbindung, $heim);

            // Manche Server antworten auf NLST in einem leeren Ordner mit
            // einem Fehler statt mit einer leeren Liste. LIST fragt
            // dasselbe noch einmal anders.
            if ($roh === false) {
                $roh = self::ausRohzeilen(ftp_rawlist($verbindung, $heim));
            }
        } finally {
            restore_error_handler();
        }

        if ($roh === false) {
            return ['gelesen' => false, 'namen' => [], 'grund' => $grund];
        }

        return ['gelesen' => true, 'namen' => self::nurNamen((array) $roh), 'grund' => ''];
    }

    /**
     * Eine frische Leitung, weil die alte nichts mehr taugt.
     *
     * Aufgemacht wird genau wie beim ersten Mal - dieselben Angaben,
     * derselbe Passivmodus. Geht das nicht, gibt es null; dann ist auch
     * nichts mehr zu messen.
     *
     * @return resource|\FTP\Connection|null
     */
    private static function neueLeitung(
        $alt,
        string $host,
        int $port,
        string $user,
        string $password,
        bool $verschluesselt
    ) {
        // Dieselbe Wache wie beim ersten Verbinden. Ohne die FTP-
        // Erweiterung ist ein Aufruf hier kein Fehler, den man melden
        // kann, sondern ein Absturz - und ein privater Helfer soll sich
        // nicht darauf verlassen, dass der Aufrufer schon nachgesehen hat.
        if (!function_exists('ftp_connect')) {
            return null;
        }

        if ($verschluesselt && !function_exists('ftp_ssl_connect')) {
            return null;
        }

        @ftp_close($alt);

        $neu = $verschluesselt
            ? @ftp_ssl_connect($host, $port, 15)
            : @ftp_connect($host, $port, 15);

        if ($neu === false) {
            return null;
        }

        if (!@ftp_login($neu, $user, $password)) {
            @ftp_close($neu);

            return null;
        }

        @ftp_pasv($neu, true);

        return $neu;
    }

    /**
     * Die Datenverbindung von Hand aufbauen und berichten, was passiert.
     *
     * ftp_nlist sagt nur "false". Das ist zu wenig, um jemanden damit
     * zum Hoster zu schicken. Also PASV selbst schicken, die genannte
     * Adresse auslesen und dort anklopfen. Was dabei herauskommt,
     * benennt die Ursache:
     *
     *   verbunden       - der Weg steht; dann liegt es woanders
     *   abgewiesen      - der Server hat den Port nicht geoeffnet
     *   keine Antwort   - eine Firewall verschluckt die Pakete
     *   interne Adresse - NAT: der Server nennt eine Adresse aus seinem
     *                     eigenen Netz, die von aussen niemand erreicht
     *
     * @return array{name:string, ok:bool, info:string}
     */
    private static function datenStufe($verbindung, string $host, int $port): array
    {
        // Dreimal, nicht einmal.
        //
        // Ein einzelner Port kann zufaellig belegt sein - drei
        // verschiedene aus demselben Bereich sind es nicht. Wenn alle
        // drei in dieselbe Zeitueberschreitung laufen, ist der ganze
        // Passivbereich zu, und genau das ist der Satz, den der Hoster
        // nicht mehr wegdiskutieren kann.
        $versuche = [];
        $offen = null;

        for ($i = 0; $i < 3; $i++) {
            $versuch = self::passivProbe($verbindung, $host);

            if ($versuch === null) {
                break;
            }

            $versuche[] = $versuch;

            if ($versuch['offen']) {
                $offen = $versuch;
                break;
            }
        }

        if ($versuche === []) {
            return self::stufe(
                'Datenverbindung',
                false,
                'Der Server hat auf PASV nicht wie erwartet geantwortet.'
            );
        }

        $adresse = $versuche[0]['adresse'];
        $ports = implode(', ', array_map(static fn (array $v): string => (string) $v['port'], $versuche));
        $intern = $versuche[0]['intern'];

        $wo = 'Der Server nennt ' . $adresse
            . (count($versuche) > 1 ? ', Ports ' . $ports : ':' . $versuche[0]['port']);

        if ($intern) {
            $wo .= ' - eine interne Adresse, von aussen nicht erreichbar. Geklopft habe ich '
                . 'deshalb an ' . $host;
        }

        if ($offen !== null) {
            return self::stufe(
                'Datenverbindung',
                true,
                $wo . '. Auf Port ' . $offen['port'] . ' steht die Verbindung. Der Weg ist '
                . 'also frei - dann liegt es nicht am Netz, sondern am Auflisten selbst.'
            );
        }

        $abgewiesen = $versuche[0]['abgewiesen'];

        return self::stufe(
            'Datenverbindung',
            false,
            $wo . '. Keiner davon nimmt eine Verbindung an (' . $versuche[0]['text'] . '). '
            . ($abgewiesen
                ? 'Abgewiesen heisst: Der Server oeffnet den Port nicht.'
                : 'Keine Antwort heisst: Die Pakete werden unterwegs verworfen, typisch fuer '
                    . 'eine Firewall.')
            . ' Port ' . $port . ' steht, ' . count($versuche) . ' Ports aus dem '
            . 'Passivbereich stehen nicht - das kann nur der Hoster aendern.'
        );
    }

    /**
     * Einmal PASV schicken und an der genannten Stelle anklopfen.
     *
     * @return array{adresse:string, port:int, intern:bool, offen:bool, abgewiesen:bool, text:string}|null
     */
    private static function passivProbe($verbindung, string $host): ?array
    {
        $antwort = @ftp_raw($verbindung, 'PASV');
        $zeile = trim((string) ($antwort[0] ?? ''));

        if (!preg_match('/\((\d+),(\d+),(\d+),(\d+),(\d+),(\d+)\)/', $zeile, $t)) {
            return null;
        }

        $adresse = $t[1] . '.' . $t[2] . '.' . $t[3] . '.' . $t[4];
        $port = ((int) $t[5] << 8) + (int) $t[6];
        $intern = self::internesNetz($adresse);

        $fehler = 0;
        $text = '';
        $sock = @fsockopen($intern ? $host : $adresse, $port, $fehler, $text, 8.0);

        if (is_resource($sock)) {
            fclose($sock);

            return ['adresse' => $adresse, 'port' => $port, 'intern' => $intern,
                'offen' => true, 'abgewiesen' => false, 'text' => 'offen'];
        }

        // "Connection refused" heisst: da ist ein Rechner, aber kein
        // offener Port. Eine Zeitueberschreitung heisst: die Pakete
        // verschwinden unterwegs. Zwei verschiedene Gespraeche mit dem
        // Hoster.
        return [
            'adresse' => $adresse,
            'port' => $port,
            'intern' => $intern,
            'offen' => false,
            'abgewiesen' => $fehler === 111 || stripos($text, 'refused') !== false,
            'text' => $text !== '' ? $text : 'Zeitueberschreitung',
        ];
    }

    /**
     * Was der Server von sich aus anbietet (FEAT).
     *
     * Beantwortet nebenbei die Frage, die der Hoster offen liess: Kann
     * dieses Konto ueberhaupt verschluesseltes FTP? Steht AUTH TLS in
     * der Liste, ja - und dann ist ein Versuch damit sinnvoll.
     *
     * @return array{name:string, ok:bool, info:string}
     */
    private static function merkmalStufe($verbindung, bool $verschluesselt): array
    {
        $roh = (array) (@ftp_raw($verbindung, 'FEAT') ?: []);
        $text = implode(' ', array_map('trim', $roh));

        if ($text === '') {
            return self::stufe('Server kann', true, 'Der Server beantwortet FEAT nicht.');
        }

        $tls = stripos($text, 'AUTH TLS') !== false || stripos($text, 'AUTH SSL') !== false;

        if ($verschluesselt) {
            return self::stufe('Server kann', true,
                'Diese Verbindung laeuft bereits verschluesselt.'
                . ($tls ? '' : ' Der Server fuehrt AUTH TLS allerdings nicht in seiner Liste.'));
        }

        return self::stufe(
            'Server kann',
            true,
            $tls
                ? 'Der Server bietet AUTH TLS an - „FTP mit Verschluesselung“ (Port 21) ist '
                    . 'hier also moeglich und einen Versuch wert.'
                : 'Der Server bietet kein AUTH TLS an - verschluesseltes FTP faellt hier weg.'
        );
    }

    /** Adressen, die nur im eigenen Netz gelten. */
    private static function internesNetz(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * Aus den rohen Zeilen von LIST die Namen holen.
     *
     * Eine Zeile sieht aus wie
     * "drwxr-xr-x 2 web web 4096 Sep 5 12:00 assets" - gebraucht wird
     * nur das letzte Feld.
     *
     * @param array<int, string>|false $zeilen
     * @return array<int, string>|false
     */
    private static function ausRohzeilen($zeilen)
    {
        if (!is_array($zeilen)) {
            return false;
        }

        $namen = [];

        foreach ($zeilen as $zeile) {
            $zeile = trim((string) $zeile);

            if ($zeile === '' || str_starts_with($zeile, 'total ')) {
                continue;
            }

            $felder = preg_split('/\s+/', $zeile, 9);

            $namen[] = (is_array($felder) && count($felder) === 9) ? $felder[8] : $zeile;
        }

        return $namen;
    }

    /**
     * Aus Pfaden Namen machen, ohne "." und "..".
     *
     * @param array<int, mixed> $eintraege
     * @return array<int, string>
     */
    private static function nurNamen(array $eintraege): array
    {
        $namen = [];

        foreach ($eintraege as $eintrag) {
            $name = basename((string) $eintrag);

            if ($name !== '' && $name !== '.' && $name !== '..') {
                $namen[] = $name;
            }
        }

        return array_values(array_unique($namen));
    }

    /**
     * Drei Faelle, drei Saetze - und nur einer davon ist ein Fehler.
     *
     * @param array{gelesen:bool, namen:array<int, string>} $inhalt
     */
    private static function inhaltMeldung(array $inhalt, string $heim): string
    {
        if (!$inhalt['gelesen']) {
            $grund = (string) ($inhalt['grund'] ?? '');

            // Der Wortlaut von PHP gehoert dazu. "SSL read failed" heisst
            // etwas anderes als "php_connect_nonb() failed", und wer das
            // an den Hoster weiterreicht, bekommt eine andere Antwort als
            // auf "geht nicht".
            $satz = 'Der Startordner liess sich nicht auflisten';

            if ($grund !== '') {
                $satz .= ' (' . $grund . ')';
            }

            if (stripos($grund, 'ssl') !== false || stripos($grund, 'tls') !== false) {
                return $satz . '. Der Abbruch kommt aus der Verschluesselung, nicht aus '
                    . 'dem Netz: Viele cPanel-Server verlangen, dass die Datenverbindung '
                    . 'die TLS-Sitzung der Steuerverbindung wiederverwendet - das kann PHP '
                    . 'nicht. Stell die Verbindungsart einmal auf „FTP“ ohne '
                    . 'Verschluesselung um; laeuft es dann durch, war es genau das.';
            }

            return $satz . '. Die naechste Zeile sagt, woran es lag.';
        }

        if ($inhalt['namen'] === []) {
            return $heim . ' ist leer - lesen liess er sich aber. Bei einem frisch '
                . 'angelegten Ordner ist das genau richtig.';
        }

        return count($inhalt['namen']) . ' Eintraege im Startordner: '
            . implode(', ', array_slice($inhalt['namen'], 0, 8));
    }

    /**
     * Darf dieser Zugang dort wirklich schreiben?
     *
     * Ohne diese Probe heisst "gruen" nur, dass der Ordner existiert.
     * Ein nur lesender Zugang faellt dann erst beim Hochladen auf - also
     * genau dann, wenn es eilig ist.
     */
    private static function schreibprobeFtp($verbindung, string $pfad): bool
    {
        $name = '.webatze-probe-' . bin2hex(random_bytes(4));
        $ziel = rtrim($pfad, '/') . '/' . $name;
        $tmp = tempnam(sys_get_temp_dir(), 'wa');

        if ($tmp === false) {
            return false;
        }

        file_put_contents($tmp, 'webatze');

        $ok = @ftp_put($verbindung, $ziel, $tmp, FTP_BINARY);

        // Noch einmal ohne die Adresse, die der Server im Passivmodus
        // nennt. Auf geteiltem Hosting steht dort haeufig eine interne
        // NAT-Adresse; die Auflistung kommt damit manchmal trotzdem
        // durch, das Hochladen nicht - und dann sieht es aus, als
        // duerfte der Zugang nicht schreiben.
        if (!$ok && defined('FTP_USEPASVADDRESS')) {
            @ftp_set_option($verbindung, FTP_USEPASVADDRESS, false);
            $ok = @ftp_put($verbindung, $ziel, $tmp, FTP_BINARY);
        }

        @unlink($tmp);

        if ($ok) {
            @ftp_delete($verbindung, $ziel);
        }

        return (bool) $ok;
    }

    private static function anmeldeHilfe(string $user): string
    {
        $meldung = 'Der Server ist erreichbar, lehnt aber die Anmeldung ab. '
            . 'Benutzername oder Passwort stimmt nicht.';

        if (!str_contains($user, '@')) {
            return $meldung . ' Bei cPanel gehoert der volle Name dazu, also'
                . ' benutzer@deine-domain.ch statt nur benutzer.';
        }

        return $meldung . ' Das Passwort ist das, das beim Anlegen des FTP-Kontos'
            . ' vergeben wurde - nicht das cPanel-Passwort. In cPanel unter'
            . ' „FTP-Konten“ laesst es sich neu setzen.';
    }

    /** Was tun, wenn der Zielordner nicht passt? */
    private static function zielHilfe(string $pfad, string $vorschlag, string $user): string
    {
        $meldung = 'Anmeldung geklappt - aber ' . $pfad . ' gibt es von diesem Zugang aus nicht.';

        if ($vorschlag !== '' && $vorschlag !== $pfad) {
            $meldung .= ' Trage ' . $vorschlag . ' ein.';
        }

        if (str_contains($user, '@')) {
            $meldung .= ' Ein cPanel-Unterkonto (Name mit @) sitzt bereits in seinem Ordner:'
                . ' Was in cPanel als Verzeichnis stand, ist von hier aus die Wurzel „/“.';
        }

        return $meldung;
    }

    /**
     * Welche Verzeichnisse gibt es dort?
     *
     * Gesucht wird an drei Stellen: im gewuenschten Pfad, in dessen
     * Elternverzeichnis und im Heimatverzeichnis des Zugangs. Bei einer
     * Subdomain liegt der Ordner meist unter public_html und heisst wie
     * die Subdomain - wer das sieht, muss nicht mehr raten.
     *
     * @return array<int, string>
     */
    private static function verzeichnisseSftp(SFTP $sftp, string $pfad, string $heim): array
    {
        $gefunden = [];

        foreach (self::suchorte($pfad, $heim) as $ort) {
            $liste = $sftp->nlist($ort);

            if (!is_array($liste)) {
                continue;
            }

            foreach ($liste as $name) {
                $name = (string) $name;

                if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                    continue;
                }

                $voll = rtrim($ort, '/') . '/' . basename($name);

                if ($sftp->is_dir($voll)) {
                    $gefunden[] = $voll;
                }
            }
        }

        return self::aufraeumen($gefunden);
    }

    /** @return array<int, string> */
    private static function verzeichnisseFtp($verbindung, string $pfad, string $heim): array
    {
        $gefunden = [];

        foreach (self::suchorte($pfad, $heim) as $ort) {
            $liste = @ftp_rawlist($verbindung, $ort);

            if (!is_array($liste)) {
                continue;
            }

            foreach ($liste as $zeile) {
                // "drwxr-xr-x 2 user group 4096 Jan 1 12:00 name"
                if (!str_starts_with((string) $zeile, 'd')) {
                    continue;
                }

                $teile = preg_split('/\s+/', (string) $zeile, 9);
                $name = trim((string) ($teile[8] ?? ''));

                if ($name === '' || $name === '.' || $name === '..' || str_starts_with($name, '.')) {
                    continue;
                }

                $gefunden[] = rtrim($ort, '/') . '/' . $name;
            }
        }

        return self::aufraeumen($gefunden);
    }

    /**
     * Wo lohnt sich das Nachsehen?
     *
     * @return array<int, string>
     */
    private static function suchorte(string $pfad, string $heim): array
    {
        $orte = [$heim, $pfad, dirname($pfad)];

        // Bei einer Subdomain liegt der Ordner fast immer hier.
        foreach ([$heim, $pfad] as $basis) {
            $orte[] = rtrim($basis, '/') . '/public_html';
        }

        $sauber = [];

        foreach ($orte as $ort) {
            $ort = self::cleanPath($ort);

            if ($ort !== '' && !in_array($ort, $sauber, true)) {
                $sauber[] = $ort;
            }
        }

        return array_slice($sauber, 0, 5);
    }

    /**
     * Welcher Ordner passt am ehesten zum gewuenschten Pfad?
     *
     * @param array<int, string> $ordner
     */
    private static function vorschlagen(array $ordner, string $pfad): string
    {
        if ($ordner === []) {
            return '';
        }

        $gesucht = mb_strtolower(basename($pfad));

        foreach ($ordner as $eintrag) {
            if (mb_strtolower(basename($eintrag)) === $gesucht) {
                return $eintrag;
            }
        }

        return '';
    }

    /**
     * @param array<int, string> $liste
     * @return array<int, string>
     */
    private static function aufraeumen(array $liste): array
    {
        $liste = array_values(array_unique($liste));
        sort($liste);

        return array_slice($liste, 0, 40);
    }

    /**
     * @param array<int, string> $ordner
     * @param array<int, array{name:string, ok:bool, info:string}> $stufen
     * @return array{ok:bool, message:string, stufen:array<int, array{name:string, ok:bool, info:string}>, ordner:array<int,string>, vorschlag:string}
     */
    private static function pruefErgebnis(
        bool $ok,
        string $message,
        array $ordner = [],
        string $vorschlag = '',
        array $stufen = [],
        string $vorschlagHost = ''
    ): array {
        // Der Vorschlag gehört in die Liste zum Anklicken - sonst steht
        // in der Meldung "trage / ein" und daneben lauter Ordner, unter
        // denen "/" nicht ist. Genau das kam heraus, weil die Liste nur
        // Unterverzeichnisse sammelt und "/" keines ist.
        if ($vorschlag !== '' && !in_array($vorschlag, $ordner, true)) {
            array_unshift($ordner, $vorschlag);
        }

        return [
            'ok' => $ok,
            'message' => $message,
            'stufen' => $stufen,
            'ordner' => $ordner,
            'vorschlag' => $vorschlag,
            'vorschlagHost' => $vorschlagHost,
        ];
    }

    /** Technische Meldungen kurz halten - der Rest steht im Protokoll. */
    private static function kurz(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($text) > 160 ? mb_substr($text, 0, 157) . '…' : $text;
    }

    /** Ist die Website nach dem Hochladen erreichbar? */
    private static function verify(array $project): array
    {
        $domain = trim((string) ($project['domain'] ?? ''));
        if ($domain === '') {
            return ['ok' => false, 'url' => ''];
        }

        $url = 'https://' . $domain . '/';
        $response = Http::get($url, 12);

        if (!$response['ok']) {
            $response = Http::get('http://' . $domain . '/', 12);
            $url = 'http://' . $domain . '/';
        }

        return [
            'ok' => $response['ok'] && str_contains($response['body'], '<html'),
            'url' => $url,
        ];
    }

    /** @return list<string> Pfade relativ zum Quellordner */
    private static function collect(string $directory): array
    {
        $files = [];

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($directory) + 1));
            if (!str_contains($relative, '..')) {
                $files[] = $relative;
            }
        }

        sort($files);
        return $files;
    }

    private static function cleanPath(string $path): string
    {
        $path = '/' . trim(str_replace('\\', '/', $path), '/');
        $path = preg_replace('#/+#', '/', $path) ?? '/';

        // Kein Ausbrechen aus dem Zielverzeichnis
        $path = str_replace('..', '', $path);

        return rtrim($path, '/') ?: '/';
    }

    private static function error(string $message, bool $retryable): array
    {
        return [
            'ok' => false, 'files' => 0, 'error' => $message,
            'retryable' => $retryable, 'verified' => false, 'url' => '',
        ];
    }
}
