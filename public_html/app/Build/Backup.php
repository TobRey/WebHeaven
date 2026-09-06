<?php

declare(strict_types=1);

namespace WebAtze\Build;

use Throwable;
use WebAtze\Core\{Config, Db, Logger, Settings};
use ZipArchive;

/**
 * Tägliche Sicherungen – von den Kundenseiten und von WebAtze selbst.
 *
 * Eine Sicherung, die von Hand angestossen werden muss, wird irgendwann
 * vergessen. Diese hier läuft mit der stündlichen Pflege mit: Einmal am
 * Tag wird WebAtze gesichert, und bei jedem Durchgang zusätzlich die
 * Kundenseite, deren Sicherung am längsten zurückliegt. So verteilt
 * sich die Arbeit über den Tag, statt zu einer Uhrzeit alles auf
 * einmal zu verlangen – auf einem gemieteten Hosting ist das der
 * Unterschied zwischen "läuft" und "Zeitlimit".
 *
 * Von der eigenen Datenbank bleiben die letzten 14 Tage liegen, dazu je
 * der Monatserste der letzten sechs Monate. Zwei Wochen decken den
 * Normalfall ab ("gestern war noch alles gut"), die Monatsstände den
 * seltenen ("das ist schon seit Februar falsch"). Sie ist klein genug
 * dafür.
 *
 * Von einer Kundenwebsite bleiben nur die neuesten Stände – wie viele,
 * steht im Wartungscenter, Standard sind zwei. Beim Anlegen einer neuen
 * Sicherung fällt die älteste weg. Eins wäre auch möglich, ist aber
 * riskant: Wird eine schon beschädigte Seite gesichert, überschreibt sie
 * den letzten guten Stand.
 */
final class Backup
{
    /** So viele Tagesstände bleiben liegen. */
    private const KEEP_DAILY = 14;

    /** Dazu je der Erste eines Monats, so viele Monate zurück. */
    private const KEEP_MONTHLY = 6;

    /** Grösser als das wird eine einzelne Sicherung nicht. */
    private const MAX_BYTES = 200 * 1024 * 1024;

    // ------------------------------------------------------------- Anstoss

    /**
     * Der stündliche Anstoss aus der Pflege.
     *
     * @return array{eigen:bool, projekt:string, geloescht:int}
     */
    public static function tick(): array
    {
        $result = ['eigen' => false, 'dateien' => false, 'projekt' => '', 'geloescht' => 0];

        if (!Config::get('backups_enabled', true)) {
            return $result;
        }

        try {
            $result['eigen'] = self::daily();
            $result['dateien'] = self::dailyFiles();
            $result['projekt'] = self::nextProject();
            $result['geloescht'] = self::prune();
        } catch (Throwable $e) {
            // Eine misslungene Sicherung darf den Worker nicht anhalten.
            Logger::warning('Sicherung fehlgeschlagen: ' . $e->getMessage());
        }

        return $result;
    }

    // -------------------------------------------------------- WebAtze selbst

    /**
     * Einmal am Tag: die eigene Datenbank und die hochgeladenen Dateien.
     *
     * Die gebauten Websites sind nicht dabei – sie entstehen aus der
     * Datenbank neu. Was nur einmal existiert, sind die Daten und die
     * Logos, die der Kunde geschickt hat.
     */
    public static function daily(): bool
    {
        $today = date('Y-m-d');

        if (Settings::get('backup_self_on', '') === $today) {
            return false;
        }

        Settings::put('backup_self_on', $today);

        $dir = STORAGE_DIR . '/backups/webatze';
        ensure_dir($dir);

        $path = $dir . '/webatze-' . $today . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            Logger::warning('Sicherung: Das Archiv liess sich nicht anlegen.', ['pfad' => $path]);
            return false;
        }

        $files = 0;

        $zip->addFromString('datenbank.sql', self::dumpDatabase());
        $files++;

        $zip->addFromString('LIESMICH.txt', self::readme($today));
        $files++;

        foreach (['uploads', 'assets'] as $folder) {
            $source = STORAGE_DIR . '/' . $folder;
            if (is_dir($source)) {
                $files += self::addTree($zip, $source, $folder);
            }
        }

        $zip->close();

        self::record('self', null, $path, $files, 'Datenbank und hochgeladene Dateien');

        return true;
    }

    /**
     * Einmal am Tag: alle Dateien der Installation.
     *
     * Die Datenbanksicherung daneben reicht nicht. Sie bringt die Daten
     * zurueck, aber nicht die Anwendung - und wer nach einem Ausfall vor
     * einem leeren Webspace steht, braucht beides. Deshalb ein zweites
     * Archiv: alle Dateien, benannt mit Datum, getrennt herunterladbar.
     *
     * Nicht dabei sind die Sicherungen selbst (sonst packte sich das
     * Archiv in sich hinein) und die Vorschauen (sie werden nach 24
     * Stunden ohnehin geloescht und sind riesig).
     *
     * Dabei ist app/config.php - anders als in der Datenbanksicherung.
     * Das ist eine Abwaegung: Ohne den Schluessel darin ist jedes
     * Passwort im Tresor nach einem Ausfall unwiederbringlich. Die
     * Trennung liegt darin, dass Schluessel und verschluesselte Daten
     * in ZWEI Archiven liegen - wer nur eines hat, hat nichts.
     */
    public static function dailyFiles(): bool
    {
        $heute = date('Y-m-d');

        if (Settings::get('backup_files_on', '') === $heute) {
            return false;
        }

        Settings::put('backup_files_on', $heute);

        $ordner = STORAGE_DIR . '/backups/dateien';
        ensure_dir($ordner);

        $name = self::slug((string) Settings::get('company_name', 'webatze'));
        $pfad = $ordner . '/' . $name . '-dateien-' . $heute . '.zip';

        $zip = new ZipArchive();

        if ($zip->open($pfad, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            Logger::warning('Dateisicherung: Das Archiv liess sich nicht anlegen.', ['pfad' => $pfad]);

            return false;
        }

        $zip->addFromString('LIESMICH.txt', self::readmeFiles($heute));

        $wurzel = dirname(APP_DIR);

        $ueberspringen = [
            STORAGE_DIR . '/backups',
            STORAGE_DIR . '/previews',
            STORAGE_DIR . '/cache',
            STORAGE_DIR . '/logs',
        ];

        $dateien = 1 + self::addTree($zip, $wurzel, 'website', $ueberspringen);

        $zip->close();

        self::record('files', null, $pfad, $dateien, 'Alle Dateien der Installation');

        Logger::info('Dateisicherung angelegt.', ['dateien' => $dateien, 'pfad' => basename($pfad)]);

        return true;
    }

    /** Aus einem Firmennamen einen brauchbaren Dateinamen machen. */
    private static function slug(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $name = preg_replace('/[^a-z0-9]+/', '-', $name) ?? '';
        $name = trim($name, '-');

        return $name !== '' ? mb_substr($name, 0, 40) : 'webatze';
    }

    /**
     * Die Datenbank als SQL.
     *
     * mysqldump gibt es auf einem gemieteten Hosting nicht. Also lesen
     * wir die Tabellen selbst aus und schreiben INSERT-Zeilen. Das ist
     * langsamer, läuft dafür überall und auch auf SQLite – wo die Tests
     * laufen, wird also dasselbe geprüft wie im Betrieb.
     */
    public static function dumpDatabase(): string
    {
        $out = "-- Sicherung der WebAtze-Datenbank\n"
            . '-- ' . date('d.m.Y H:i') . "\n"
            . "--\n"
            . "-- Passwörter stehen hier nur als Prüfwert, die FTP-Zugänge\n"
            . "-- nur verschlüsselt. Der Schlüssel dazu liegt in app/config.php\n"
            . "-- und ist absichtlich nicht Teil dieser Datei: Wer die Sicherung\n"
            . "-- in die Hand bekommt, hat damit noch keinen Zugang.\n\n";

        foreach (self::tables() as $table) {
            $out .= "\n-- ---- " . $table . " ----\n";

            $rows = Db::all('SELECT * FROM ' . Db::quoteIdentifier($table));

            if ($rows === []) {
                $out .= "-- (leer)\n";
                continue;
            }

            foreach ($rows as $row) {
                $columns = [];
                $values = [];

                foreach ($row as $column => $value) {
                    $columns[] = Db::quoteIdentifier((string) $column);
                    $values[] = self::literal($value);
                }

                $out .= 'INSERT INTO ' . Db::quoteIdentifier($table)
                    . ' (' . implode(', ', $columns) . ') VALUES ('
                    . implode(', ', $values) . ");\n";
            }
        }

        return $out;
    }

    /** @return array<int, string> */
    private static function tables(): array
    {
        $names = [];

        if (Db::isSqlite()) {
            $rows = Db::all("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name");
            foreach ($rows as $row) {
                $name = (string) $row['name'];
                if (!str_starts_with($name, 'sqlite_')) {
                    $names[] = $name;
                }
            }

            return $names;
        }

        foreach (Db::all('SHOW TABLES') as $row) {
            $names[] = (string) reset($row);
        }

        sort($names);

        return $names;
    }

    /** Ein Wert so, wie er in einer SQL-Zeile stehen darf. */
    private static function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return "'" . str_replace(
            ["\\", "'", "\r", "\n", "\0"],
            ["\\\\", "''", '\r', '\n', ''],
            (string) $value
        ) . "'";
    }

    // ----------------------------------------------------------- Kundenseiten

    /**
     * Die Kundenseite sichern, deren Sicherung am längsten her ist.
     *
     * @return string Slug der gesicherten Website, sonst ''
     */
    public static function nextProject(): string
    {
        $since = date('Y-m-d H:i:s', time() - 20 * 3600);

        // Nicht auf den Zustand filtern: "done" und "published" gibt es
        // bei einer Website gar nicht (draft, building, ready, live,
        // paused, failed). Mit dem alten Filter wurde nie eine
        // Kundenseite gesichert. Gesichert wird, was auch etwas zu
        // sichern hat - also alles, was einmal gebaut wurde.
        $project = Db::first(
            "SELECT p.* FROM projects p
             WHERE EXISTS (SELECT 1 FROM builds b2 WHERE b2.project_id = p.id)
               AND NOT EXISTS (
                   SELECT 1 FROM backups b
                   WHERE b.project_id = p.id AND b.created_at > :since
               )
             ORDER BY p.id ASC LIMIT 1",
            ['since' => $since]
        );

        if ($project === null) {
            return '';
        }

        return self::project($project) ? (string) $project['slug'] : '';
    }

    /**
     * Eine Kundenseite sichern.
     *
     * Gesichert wird die gebaute Website und – wenn ein Zugang
     * hinterlegt ist – zusätzlich der Ordner "data" vom Server des
     * Kunden. Der ist der eigentliche Grund für die Übung: Dort liegen
     * die Anfragen aus dem Kontaktformular und alles, was der Kunde in
     * seinem Bearbeitungsbereich geändert hat. Nur das existiert bloss
     * einmal; der Rest lässt sich neu bauen.
     */
    public static function project(array $project): bool
    {
        $slug = (string) $project['slug'];
        $dist = STORAGE_DIR . '/projects/' . $slug . '/dist';

        if (!is_dir($dist)) {
            return false;
        }

        $dir = STORAGE_DIR . '/backups/projects/' . $slug;
        ensure_dir($dir);

        $path = $dir . '/' . $slug . '-' . date('Y-m-d') . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $files = self::addTree($zip, $dist, 'website');
        $note = 'Gebaute Website';

        $live = self::fetchRemoteData($project);

        foreach ($live as $relative => $content) {
            $zip->addFromString('live-data/' . $relative, $content);
            $files++;
        }

        if ($live !== []) {
            $note .= ' und ' . count($live) . ' Dateien vom Server des Kunden';
        }

        $zip->close();

        self::record('project', (int) $project['id'], $path, $files, $note);

        return true;
    }

    /**
     * Den Ordner "data" vom Server des Kunden holen.
     *
     * Schlägt es fehl, ist das kein Fehler der Sicherung: Vielleicht
     * ist die Seite noch nicht hochgeladen, vielleicht liegen keine
     * Zugangsdaten vor. Dann bleibt eben die gebaute Website übrig.
     *
     * @return array<string, string>
     */
    private static function fetchRemoteData(array $project): array
    {
        // Frueher wurde der Ordner ueber FTP beim Kunden geholt. Das ist
        // gestrichen, und zwar nicht aus Bequemlichkeit: Von einem
        // Hosting zum anderen kommt die Datenverbindung nicht durch -
        // gemessen, dreimal, an drei Wegen. Die Sicherung hat den
        // Datenordner damit seit Wochen still nicht bekommen und trotzdem
        // "fertig" gemeldet.
        //
        // Jetzt kommt er aus dem zuletzt uebernommenen Stand. Der ist
        // aelter als "jetzt" - und genau das steht auch drin, statt dass
        // ein leerer Ordner wie ein voller aussieht.
        $ordner = Uebernahme::ordner($project) . '/data';

        if (!is_dir($ordner)) {
            return [];
        }

        $raus = [];

        try {
            $eintraege = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($ordner, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($eintraege as $eintrag) {
                if (!$eintrag->isFile() || is_link($eintrag->getPathname())) {
                    continue;
                }

                $relativ = substr($eintrag->getPathname(), strlen($ordner) + 1);
                $raus[$relativ] = (string) @file_get_contents($eintrag->getPathname());
            }
        } catch (Throwable $e) {
            Logger::info('Sicherung: der uebernommene Datenordner liess sich nicht lesen.', [
                'projekt' => (string) $project['slug'],
            ]);

            return [];
        }

        return $raus;
    }

    // -------------------------------------------------------------- Aufräumen

    /**
     * Alte Sicherungen löschen.
     *
     * Behalten wird nach der Regel oben: die letzten 14 Tage, dazu der
     * Monatserste der letzten sechs Monate. Gelöscht wird sowohl die
     * Datei als auch der Eintrag – eine Liste, die auf nicht mehr
     * vorhandene Dateien zeigt, ist schlimmer als keine Liste.
     */
    public static function prune(): int
    {
        $removed = 0;

        $groups = [];

        foreach (Db::all('SELECT * FROM backups ORDER BY created_at DESC') as $row) {
            $key = (string) $row['scope'] . ':' . (string) ($row['project_id'] ?? '0');
            $groups[$key][] = $row;
        }

        // Wie viele Staende einer Kundenwebsite bleiben. Einstellbar,
        // weil eine Website je nach Umfang schnell hundert Megabyte hat
        // und der Speicherplatz auf gemietetem Hosting endlich ist.
        $proSeite = max(1, min(30, (int) Settings::get('backup_keep_per_site', '2')));

        foreach ($groups as $key => $rows) {
            $eigene = str_starts_with($key, 'self:');
            $daily = 0;
            $monthly = 0;

            foreach ($rows as $row) {
                $keep = false;

                if (!$eigene) {
                    // Kundenwebsites: nur die neuesten Staende.
                    if ($daily < $proSeite) {
                        $daily++;
                        $keep = true;
                    }
                } else {
                    // Die eigene Datenbank bleibt laenger liegen. Sie ist
                    // winzig im Vergleich, und genau sie braucht man,
                    // wenn etwas seit Wochen unbemerkt schieflaeuft.
                    $day = substr((string) $row['created_at'], 0, 10);
                    $isFirst = str_ends_with($day, '-01');

                    if ($daily < self::KEEP_DAILY) {
                        $daily++;
                        $keep = true;
                    } elseif ($isFirst && $monthly < self::KEEP_MONTHLY) {
                        $monthly++;
                        $keep = true;
                    }
                }

                if ($keep) {
                    continue;
                }

                $path = (string) $row['path'];

                if ($path !== '' && is_file($path) && self::insideBackups($path)) {
                    @unlink($path);
                }

                Db::delete('backups', 'id = :id', ['id' => (int) $row['id']]);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Eine Sicherung, die schon läuft, darf nichts ausserhalb ihres
     * eigenen Ordners löschen. Der Pfad kommt zwar aus unserer eigenen
     * Datenbank – aber "kommt von uns" ist keine Prüfung.
     */
    private static function insideBackups(string $path): bool
    {
        $real = realpath($path);
        $root = realpath(STORAGE_DIR . '/backups');

        return $real !== false && $root !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR);
    }

    // ------------------------------------------------------------- Übersicht

    /** @return array<int, array<string, mixed>> */
    public static function listAll(int $limit = 100): array
    {
        return Db::all(
            'SELECT b.*, p.name AS project_name, p.slug AS project_slug
             FROM backups b LEFT JOIN projects p ON p.id = b.project_id
             ORDER BY b.created_at DESC, b.id DESC LIMIT ' . max(1, min(500, $limit))
        );
    }

    /**
     * Eine Sicherung entfernen - Datei und Eintrag.
     *
     * Auch wenn die Datei schon weg ist, verschwindet der Eintrag: Eine
     * Zeile, die auf nichts zeigt, ist schlimmer als keine Zeile.
     */
    public static function remove(int $id): bool
    {
        $zeile = Db::first('SELECT * FROM backups WHERE id = :id', ['id' => $id]);

        if ($zeile === null) {
            return false;
        }

        $pfad = (string) $zeile['path'];

        // "Kommt aus unserer Datenbank" ist keine Pruefung - eine
        // Loeschanweisung bleibt innerhalb des Sicherungsordners.
        if (is_file($pfad) && self::insideBackups($pfad)) {
            @unlink($pfad);
        }

        Db::delete('backups', 'id = :id', ['id' => $id]);

        return true;
    }

    /** Der Pfad einer Sicherung – oder null, wenn sie nicht (mehr) da ist. */
    public static function pathFor(int $id): ?string
    {
        $row = Db::first('SELECT path FROM backups WHERE id = :id', ['id' => $id]);

        if ($row === null) {
            return null;
        }

        $path = (string) $row['path'];

        return is_file($path) && self::insideBackups($path) ? $path : null;
    }

    // -------------------------------------------------------------- Kleinkram

    private static function record(string $scope, ?int $projectId, string $path, int $files, string $note): void
    {
        Db::insert('backups', [
            'scope' => $scope,
            'project_id' => $projectId,
            'path' => $path,
            'bytes' => is_file($path) ? (int) filesize($path) : 0,
            'files' => $files,
            'note' => mb_substr($note, 0, 250),
            'created_at' => Db::now(),
        ]);
    }

    /**
     * Einen Ordner ins Archiv legen.
     *
     * @param array<int, string> $auslassen Ordner, die nicht mit hinein
     *        sollen - allen voran der Sicherungsordner selbst. Ohne
     *        diese Liste packte sich das Archiv in sich hinein, bis der
     *        Speicher voll ist.
     */
    private static function addTree(
        ZipArchive $zip,
        string $directory,
        string $prefix,
        array $auslassen = []
    ): int {
        $files = 0;
        $bytes = 0;

        $tabu = [];

        foreach ($auslassen as $weg) {
            $echt = realpath($weg);

            if ($echt !== false) {
                $tabu[] = $echt . DIRECTORY_SEPARATOR;
            }
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if ($tabu !== []) {
                $pfad = $file->getPathname();
                $drin = false;

                foreach ($tabu as $weg) {
                    if (str_starts_with($pfad, $weg)) {
                        $drin = true;
                        break;
                    }
                }

                if ($drin) {
                    continue;
                }
            }

            $bytes += (int) $file->getSize();

            if ($bytes > self::MAX_BYTES) {
                $zip->addFromString(
                    $prefix . '/ABGESCHNITTEN.txt',
                    "Diese Sicherung wurde bei " . round(self::MAX_BYTES / 1048576) . " MB abgebrochen.\n"
                    . "Es sind mehr Dateien da, als in ein Archiv passen.\n"
                );
                break;
            }

            $relative = ltrim(str_replace($directory, '', $file->getPathname()), '/\\');
            $zip->addFile($file->getPathname(), $prefix . '/' . str_replace('\\', '/', $relative));
            $files++;
        }

        return $files;
    }

    private static function readmeFiles(string $day): string
    {
        return "Dateisicherung von WebAtze vom " . $day . "\n"
            . str_repeat('=', 44) . "\n\n"
            . "In website/ liegt die gesamte Installation, so wie sie auf dem\n"
            . "Server steht: die Anwendung, die Ansichten, das gebaute Frontend,\n"
            . "die Vorlagen und alles unter storage/.\n\n"
            . "Nicht dabei sind:\n"
            . "  storage/backups/   die Sicherungen selbst\n"
            . "  storage/previews/  Vorschauen, die nach 24 Stunden ohnehin gehen\n"
            . "  storage/logs/      Protokolle\n"
            . "  storage/cache/     Zwischenspeicher\n\n"
            . "So spielst du sie zurück:\n\n"
            . "  1. Den Inhalt von website/ nach public_html hochladen.\n"
            . "  2. Die Datenbanksicherung getrennt einspielen - sie liegt in\n"
            . "     einem eigenen Archiv (webatze-JJJJ-MM-TT.zip).\n"
            . "  3. Fertig. app/config.php ist dabei, es ist nichts neu\n"
            . "     einzurichten.\n\n"
            . "WICHTIG\n"
            . "-------\n"
            . "Dieses Archiv enthält app/config.php - und damit den Schlüssel,\n"
            . "mit dem die Passwörter im Tresor verschlüsselt sind.\n\n"
            . "Das ist Absicht: Ohne diesen Schlüssel wäre nach einem Ausfall\n"
            . "jedes Passwort im Tresor unwiederbringlich verloren. Der Schutz\n"
            . "liegt darin, dass Schlüssel und verschlüsselte Daten in ZWEI\n"
            . "getrennten Archiven stehen - wer nur eines davon hat, hat nichts.\n\n"
            . "Bewahre dieses Archiv deshalb an einem anderen Ort auf als die\n"
            . "Datenbanksicherung, und gib es nicht weiter.\n";
    }

    private static function readme(string $day): string
    {
        return "Sicherung von WebAtze vom " . $day . "\n"
            . str_repeat('=', 40) . "\n\n"
            . "datenbank.sql   Alle Tabellen als INSERT-Zeilen.\n"
            . "uploads/        Was hochgeladen wurde: Logos, Bilder.\n"
            . "assets/         Erzeugte Bilder der Projekte.\n\n"
            . "So spielst du sie zurück:\n\n"
            . "  1. In cPanel eine leere Datenbank anlegen.\n"
            . "  2. install.php aufrufen – das legt die Tabellen an.\n"
            . "  3. datenbank.sql in phpMyAdmin einspielen.\n"
            . "  4. Die Ordner nach storage/ zurückkopieren.\n\n"
            . "Nicht dabei ist app/config.php mit den Zugangsdaten und dem\n"
            . "Schlüssel. Das ist Absicht: Diese Datei gehört nicht in eine\n"
            . "Sicherung, die kopiert und weitergegeben wird. Verwahre sie\n"
            . "getrennt – ohne sie lassen sich die FTP-Zugänge in der\n"
            . "Datenbank nicht entschlüsseln.\n";
    }
}
