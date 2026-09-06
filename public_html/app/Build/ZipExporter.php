<?php

declare(strict_types=1);

namespace WebAtze\Build;

use RuntimeException;
use WebAtze\Core\{Audit, Db, Logger};
use ZipArchive;

/**
 * Packt eine fertige Website in ein ZIP.
 *
 * Das Paket wird immer erstellt – auch wenn die Website zusätzlich per
 * FTP hochgeladen wird. Es ist der Beleg, dass die Website dem Kunden
 * gehört und ohne uns weiterlebt.
 *
 * Jede Version bleibt erhalten. Wer eine Änderung rückgängig machen will,
 * lädt einfach das vorherige Paket herunter.
 */
final class ZipExporter
{
    /**
     * @return array{path:string, bytes:int, files:int, version:int}
     */
    public static function create(array $project): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(
                'Die PHP-Erweiterung "zip" fehlt auf diesem Server. '
                . 'Ohne sie lässt sich kein Paket schnüren.'
            );
        }

        $slug = (string) $project['slug'];
        $source = STORAGE_DIR . '/projects/' . $slug . '/dist';

        if (!is_dir($source)) {
            throw new RuntimeException('Es gibt noch keine gebaute Website zum Packen.');
        }

        $version = (int) Db::value(
            'SELECT COALESCE(MAX(version), 0) + 1 FROM builds WHERE project_id = :p',
            ['p' => (int) $project['id']],
            1
        );

        $dir = ensure_dir(STORAGE_DIR . '/zips/' . $slug);
        $name = sprintf('%s-v%d-%s.zip', $slug, $version, date('Y-m-d'));
        $path = $dir . '/' . $name;

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Die ZIP-Datei konnte nicht angelegt werden.');
        }

        $files = self::addDirectory($zip, $source, '');

        // Eine Anleitung, die auch in einem Jahr noch verständlich ist.
        $zip->addFromString('ANLEITUNG.txt', self::readme($project, $version));
        $files++;

        $zip->close();

        if (!is_file($path)) {
            throw new RuntimeException('Die ZIP-Datei wurde nicht geschrieben.');
        }

        $bytes = (int) filesize($path);

        Db::insert('builds', [
            'project_id' => (int) $project['id'],
            'version' => $version,
            'zip_path' => $slug . '/' . $name,
            'zip_bytes' => $bytes,
            'files_count' => $files,
            'notes' => '',
            'created_at' => Db::now(),
        ]);

        Logger::info('Paket erstellt', ['projekt' => $slug, 'version' => $version, 'bytes' => $bytes]);

        return ['path' => $path, 'bytes' => $bytes, 'files' => $files, 'version' => $version];
    }

    /**
     * Verzeichnis rekursiv hinzufügen.
     *
     * Symlinks werden übersprungen: Ein Verweis, der aus dem Ordner
     * hinauszeigt, würde beim Packen fremde Dateien mitnehmen.
     */
    private static function addDirectory(ZipArchive $zip, string $directory, string $prefix): int
    {
        $count = 0;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink()) {
                continue;
            }

            $relative = substr($item->getPathname(), strlen($directory) + 1);
            $relative = str_replace('\\', '/', $relative);

            // Zweite Sicherung gegen Ausbruch aus dem Verzeichnis
            if ($relative === '' || str_contains($relative, '..')) {
                continue;
            }

            $entry = $prefix === '' ? $relative : $prefix . '/' . $relative;

            if ($item->isDir()) {
                $zip->addEmptyDir($entry);
            } elseif ($item->isFile()) {
                $zip->addFile($item->getPathname(), $entry);
                $count++;
            }
        }

        return $count;
    }

    /** Was der Kunde im Paket vorfindet. */
    private static function readme(array $project, int $version): string
    {
        $name = (string) $project['name'];
        $domain = (string) ($project['domain'] ?? '');
        $date = date('d.m.Y');

        $domainLine = $domain !== ''
            ? "Vorgesehene Adresse: {$domain}\n"
            : '';

        return <<<TEXT
        {$name} – Website
        =============================================

        Version {$version}, erstellt am {$date}
        {$domainLine}
        WAS IST DAS HIER?
        -----------------
        Die vollständige Website. Alles, was hier drin liegt, gehört
        zusammen und gehört Ihnen. Es braucht keine Installation, keine
        Datenbank und kein besonderes Hosting.


        WIE KOMMT DIE WEBSITE INS NETZ?
        -------------------------------
        1. Bei Ihrem Hosting-Anbieter anmelden und den Dateimanager öffnen.
        2. In das Verzeichnis der Website wechseln. Es heisst je nach
           Anbieter public_html, httpdocs, www oder web.
        3. Den GESAMTEN Inhalt dieses Pakets dorthin hochladen –
           nicht den Ordner selbst, sondern das, was darin liegt.
        4. Fertig. Die Website ist unter Ihrer Adresse erreichbar.

        Wichtig: Die Datei index.html muss direkt im Verzeichnis liegen,
        nicht in einem Unterordner. Sonst zeigt der Browser eine
        Dateiliste statt der Website.


        WAS LIEGT WO?
        -------------
        index.html          Startseite
        *.html              die weiteren Seiten
        assets/css/         das Aussehen
        assets/js/          kleine Hilfen wie das Menü auf dem Handy
        assets/img/         alle Bilder
        .htaccess           Servereinstellungen (nicht löschen)
        robots.txt          Hinweise für Suchmaschinen
        sitemap.xml         Seitenverzeichnis für Suchmaschinen
        404.html            erscheint bei einer falschen Adresse


        ETWAS ÄNDERN
        ------------
        Texte und Bilder stehen direkt in den HTML-Dateien und lassen sich
        mit jedem Texteditor ändern. Farben und Schriften stehen gesammelt
        am Anfang von assets/css/site.css – eine Änderung dort wirkt auf
        der ganzen Website.

        Wer lieber nicht in Dateien schreibt, meldet sich einfach bei uns.


        BILDER AUSTAUSCHEN
        ------------------
        Neues Bild unter demselben Namen nach assets/img/ legen – fertig.
        Am besten in ähnlicher Grösse, damit das Layout stimmig bleibt.


        FRAGEN?
        -------
        Melden Sie sich. Wir helfen gerne weiter.
        TEXT;
    }

    // ==================================================================
    // Der aktuelle Stand vom Server des Kunden
    // ==================================================================

    /** So viele Live-Stände bleiben je Website liegen. */
    public const LIVE_BEHALTEN = 3;

    /**
     * Holt, was gerade wirklich auf dem Kundenserver liegt.
     *
     * Der Unterschied zu create() ist der springende Punkt: create()
     * packt storage/projects/<slug>/dist – also das, was hier zuletzt
     * gebaut wurde. Was tatsächlich beim Kunden liegt, ist etwas
     * anderes, sobald jemand dort etwas geändert hat: Bilder, die er
     * hochgeladen hat, Anfragen, die eingegangen sind, Texte, die er
     * im Backend umgeschrieben hat. Das steht in keinem gebauten Paket.
     *
     * Deshalb: wie eine Sicherung, nur eben jetzt und auf Knopfdruck.
     *
     * Zwei Wege fuehren hierher. Ueber FTP holt `FtpDeployer::fetchTree()`
     * den Stand, ueber HTTPS `Empfang::holen()` - beide liefern
     * dieselbe Ergebnisform, und alles danach ist fuer beide dasselbe:
     * Archiv, STAND.txt, Zeile in "builds", Aufraeumen.
     *
     * @param callable|null $onProgress fn(int $dateien, string $pfad)
     * @param string $weg 'ftp' oder 'https'
     * @param bool $liegenLassen nur fuer 'https': Empfaenger nicht entfernen
     * @return array{path:string, bytes:int, files:int, abgeschnitten:bool}
     */
    public static function pullLive(
        array $project,
        ?callable $onProgress = null,
        float $budget = 90.0,
        string $weg = 'ftp',
        bool $liegenLassen = false
    ): array {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Die PHP-Erweiterung "zip" fehlt auf diesem Server.');
        }

        $ueberHttps = $weg === 'https';

        // Ueber targetFor(), damit auch hier der gemeinsame
        // Hosting-Zugang greift und nicht nur beim Hochladen. Der Weg
        // ueber HTTPS braucht das nicht - er kennt nur die Adresse der
        // Website und die Empfangsdatei darauf.
        $target = $ueberHttps ? null : FtpDeployer::targetFor((int) $project['id']);

        if (!$ueberHttps && $target === null) {
            throw new RuntimeException(
                'Für diese Website sind keine Zugangsdaten hinterlegt. '
                . 'Ohne sie lässt sich nicht nachsehen, was dort liegt.'
            );
        }

        if ($ueberHttps && trim((string) ($project['domain'] ?? '')) === '') {
            throw new RuntimeException(
                'Diese Website hat keine Adresse. Ohne sie weiss der Weg über '
                . 'HTTPS nicht, wen er anrufen soll.'
            );
        }

        $slug = (string) $project['slug'];
        $dir = ensure_dir(STORAGE_DIR . '/zips/' . $slug);
        $name = sprintf('%s-live-%s.zip', $slug, date('Y-m-d-Hi'));
        $path = $dir . '/' . $name;

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Die ZIP-Datei konnte nicht angelegt werden.');
        }

        $ergebnis = $ueberHttps
            ? Empfang::holen($project, $zip, $budget, $onProgress, !$liegenLassen)
            : FtpDeployer::fetchTree((array) $target, $zip, $budget, $onProgress);

        if (!$ergebnis['ok']) {
            $zip->close();
            @unlink($path);
            self::tmpWeg($ergebnis['tmp'] ?? []);

            throw new RuntimeException($ergebnis['error']);
        }

        $zip->addFromString('STAND.txt', self::livehinweis($project, $ergebnis));

        $zip->close();

        // Erst nach close(): Vorher liest ZipArchive die Dateien noch.
        self::tmpWeg($ergebnis['tmp'] ?? []);

        if (!is_file($path)) {
            throw new RuntimeException('Die ZIP-Datei wurde nicht geschrieben.');
        }

        $bytes = (int) filesize($path);

        Db::insert('builds', [
            'project_id' => (int) $project['id'],
            // Version 0 heisst: nicht gebaut, sondern geholt. Eine
            // eigene Zählung wäre eine zweite Reihenfolge neben der
            // gebauten, und dann bedeutete "v3" zweierlei.
            'version' => 0,
            'zip_path' => $slug . '/' . $name,
            'zip_bytes' => $bytes,
            'files_count' => (int) $ergebnis['files'],
            'notes' => $ergebnis['abgeschnitten']
                ? 'Live-Stand, unvollständig (Grenze erreicht)'
                : 'Live-Stand vom Server',
            'created_at' => Db::now(),
        ]);

        self::liveAufraeumen((int) $project['id'], $slug);

        Audit::log('project.pulled', $slug, [
            'dateien' => $ergebnis['files'],
            'bytes' => $bytes,
        ]);

        return [
            'path' => $path,
            'bytes' => $bytes,
            'files' => (int) $ergebnis['files'],
            'abgeschnitten' => (bool) $ergebnis['abgeschnitten'],
            // Nur der Weg ueber HTTPS kennt das: Ob der Empfaenger
            // danach noch dort liegt oder sich weggeraeumt hat.
            'aufgeraeumt' => (bool) ($ergebnis['aufgeraeumt'] ?? false),
        ];
    }

    /**
     * Alte Live-Stände wegräumen.
     *
     * Ohne das füllt ein wiederholter Knopfdruck das Hosting-Konto: Ein
     * Live-Stand ist so gross wie die ganze Website, und niemand denkt
     * daran, ihn zu löschen. Drei bleiben – genug, um zu vergleichen,
     * wenig genug, um nicht wehzutun.
     */
    private static function liveAufraeumen(int $projectId, string $slug): void
    {
        $alte = Db::all(
            'SELECT id, zip_path FROM builds
              WHERE project_id = :p AND version = 0
              ORDER BY id DESC',
            ['p' => $projectId]
        );

        foreach (array_slice($alte, self::LIVE_BEHALTEN) as $eintrag) {
            $relativ = (string) $eintrag['zip_path'];

            // Nur, was unterhalb des eigenen Ordners liegt. Ein
            // fehlerhafter Eintrag darf nicht dazu führen, dass hier
            // beliebige Dateien verschwinden.
            if ($relativ !== ''
                && !str_contains($relativ, '..')
                && str_starts_with($relativ, $slug . '/')
            ) {
                @unlink(STORAGE_DIR . '/zips/' . $relativ);
            }

            Db::delete('builds', 'id = :id', ['id' => (int) $eintrag['id']]);
        }
    }

    /** @param array<int, string> $dateien */
    private static function tmpWeg(array $dateien): void
    {
        foreach ($dateien as $datei) {
            if (is_string($datei) && $datei !== '') {
                @unlink($datei);
            }
        }
    }

    /** @param array<string, mixed> $ergebnis */
    private static function livehinweis(array $project, array $ergebnis): string
    {
        $wann = date('d.m.Y H:i');
        $name = (string) $project['name'];
        $anzahl = (int) $ergebnis['files'];

        $text = <<<TEXT
        {$name} – Stand vom Server

        Geholt am {$wann}.
        {$anzahl} Dateien.

        Das hier ist nicht das gebaute Paket, sondern das, was in diesem
        Moment tatsächlich auf dem Server lag – einschliesslich allem,
        was seither dort hinzugekommen ist: hochgeladene Bilder,
        eingegangene Anfragen, im Backend geänderte Texte.
        TEXT;

        if (!empty($ergebnis['abgeschnitten'])) {
            $text .= "\n\n" . <<<TEXT
        Achtung: Es wurde nicht alles geholt. Eine der Grenzen war
        erreicht – Anzahl, Gesamtgrösse, Verschachtelungstiefe oder
        Zeit. Was fehlt, fehlt; als vollständige Sicherung taugt dieses
        Archiv deshalb nicht.
        TEXT;
        }

        return $text . "\n";
    }

    /** Alle Pakete eines Projekts, neuestes zuerst. */
    public static function listFor(int $projectId): array
    {
        // Nach id und nicht nach version: Live-Stände tragen alle die
        // Version 0, und nach version sortiert stünden sie sonst
        // allesamt am Ende, der neueste irgendwo dazwischen.
        return Db::all(
            'SELECT * FROM builds WHERE project_id = :p ORDER BY id DESC',
            ['p' => $projectId]
        );
    }

    /**
     * Vollständiger Pfad zu einem Paket.
     * Gibt null zurück, wenn der Eintrag nicht zum Projekt gehört oder
     * die Datei fehlt – so lässt sich über die Adresse nichts erraten.
     */
    public static function pathFor(int $projectId, int $buildId): ?string
    {
        $build = Db::first(
            'SELECT * FROM builds WHERE id = :id AND project_id = :p',
            ['id' => $buildId, 'p' => $projectId]
        );

        if ($build === null) {
            return null;
        }

        $relative = (string) $build['zip_path'];
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $path = STORAGE_DIR . '/zips/' . $relative;

        return is_file($path) ? $path : null;
    }
}
