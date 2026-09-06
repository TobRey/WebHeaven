<?php

declare(strict_types=1);

namespace WebAtze\Build;

use RuntimeException;
use WebAtze\Core\{Db, Logger};
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

        // Ohne gebauten Stand: der uebernommene.
        //
        // Eine fremde Website wird hier nie gebaut - sie kommt als
        // Archiv herein, wird bearbeitet und geht als Archiv hinaus.
        // "Es gibt noch keine gebaute Website" waere fuer genau diesen
        // Fall die falsche Auskunft: Es gibt sie, sie stammt nur nicht
        // aus dem Generator.
        if (!is_dir($source)) {
            $source = Uebernahme::ordner($project);
        }

        if (!is_dir($source)) {
            throw new RuntimeException(
                'Es liegt keine Website zum Packen bereit - weder eine gebaute noch '
                . 'ein hochgeladener Stand.'
            );
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

        // Und darunter, was der Bau nicht erzeugt.
        //
        // Der uebernommene Stand enthaelt Dateien, die es hier nie gab:
        // Bilder, die der Kunde hochgeladen hat, Anfragen, die bei ihm
        // eingegangen sind, sein eigenes data/. Faehrt das nicht mit,
        // loescht der naechste Upload beim Kunden genau das, was er
        // selbst erzeugt hat - und niemand merkt es, bis er danach
        // sucht.
        //
        // Der Bau hat Vorrang: Was er erzeugt hat, liegt schon im
        // Archiv und wird nicht ueberschrieben.
        if ($source !== Uebernahme::ordner($project)) {
            $files += self::ergaenzen($zip, Uebernahme::ordner($project));
        }

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

    /**
     * Aus dem uebernommenen Stand ergaenzen, was noch fehlt.
     *
     * Nur was fehlt: `locateName()` fragt das Archiv, ob es den Namen
     * schon kennt. Ohne diese Frage gaeben zwei Eintraege mit demselben
     * Namen ein Archiv, dessen Inhalt vom Auspacker abhaengt - und der
     * gebaute Stand ist der, der gelten soll.
     */
    private static function ergaenzen(ZipArchive $zip, string $ordner): int
    {
        if (!is_dir($ordner)) {
            return 0;
        }

        $anzahl = 0;

        $eintraege = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($ordner, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($eintraege as $eintrag) {
            /** @var \SplFileInfo $eintrag */
            if ($eintrag->isLink() || !$eintrag->isFile()) {
                continue;
            }

            $relativ = str_replace('\\', '/', substr($eintrag->getPathname(), strlen($ordner) + 1));

            if ($relativ === '' || str_contains($relativ, '..')) {
                continue;
            }

            if ($zip->locateName($relativ) !== false) {
                continue;
            }

            $zip->addFile($eintrag->getPathname(), $relativ);
            $anzahl++;
        }

        return $anzahl;
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
}
