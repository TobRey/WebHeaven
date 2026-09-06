<?php

declare(strict_types=1);

namespace WebAtze\Build;

use WebAtze\Core\{Audit, Db, Logger};

/**
 * Die Stände einer Website.
 *
 * Ein Stand ist ein Archiv und der Zeitpunkt, an dem zuletzt darin
 * gespeichert wurde. Einer davon ist aktiv: der, an dem gerade
 * gearbeitet wird. Die älteren liegen daneben und lassen sich wieder
 * aktiv setzen.
 *
 * Diese Klasse ersetzt die "Pakete". Der Unterschied ist kein Name,
 * sondern ein Fehler, der genau daran hing: Herunterladen zeigte auf das
 * zuletzt *gebaute* Paket - und nach einer Übernahme war das
 * ausgerechnet das Archiv, das gerade hochgeladen worden war. Wer eine
 * Seite änderte und dann herunterlud, bekam seine eigene Änderung nicht
 * zurück, sondern den Stand davor. Es sah aus, als hätte das Speichern
 * nicht funktioniert.
 *
 * Deshalb hier die Regel, die das unmöglich macht: **Herunterladen packt
 * immer den Ordner, in dem gearbeitet wird - frisch, bei jedem Klick.**
 * Ein Archiv, das schon dalag, wird dafür nie benutzt.
 */
final class Staende
{
    /** So viele Stände bleiben je Website liegen. */
    public const BEHALTEN = 10;

    /**
     * Alle Stände einer Website, neuester zuerst.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function liste(int $projectId): array
    {
        return Db::all(
            'SELECT * FROM site_versions WHERE project_id = :p ORDER BY id DESC',
            ['p' => $projectId]
        );
    }

    /** Der Stand, an dem gerade gearbeitet wird. */
    public static function aktiver(int $projectId): ?array
    {
        return Db::first(
            'SELECT * FROM site_versions WHERE project_id = :p AND is_active = 1 ORDER BY id DESC LIMIT 1',
            ['p' => $projectId]
        );
    }

    public static function finden(int $projectId, int $id): ?array
    {
        return Db::first(
            'SELECT * FROM site_versions WHERE id = :id AND project_id = :p',
            ['id' => $id, 'p' => $projectId]
        );
    }

    /**
     * Ein hochgeladenes Archiv als neuen Stand aufnehmen.
     *
     * Das Archiv wandert in den Ablageordner und wird ausgepackt; der
     * neue Stand ist danach der aktive.
     *
     * @return array{ok:bool, error:string, id:int, files:int}
     */
    public static function aufnehmen(array $projekt, string $zipPfad, string $note = 'Hochgeladen'): array
    {
        $aus = Uebernahme::auspacken($projekt, $zipPfad);

        if (!$aus['ok']) {
            return ['ok' => false, 'error' => $aus['error'], 'id' => 0, 'files' => 0];
        }

        $id = self::ablegen($projekt, $zipPfad, $note, $aus['files']);

        if ($id === 0) {
            return ['ok' => false, 'error' => 'Der Stand liess sich nicht ablegen.', 'id' => 0, 'files' => 0];
        }

        Audit::log('stand.aufgenommen', (string) $projekt['name'], [
            'dateien' => $aus['files'],
            'notiz' => $note,
        ]);

        return ['ok' => true, 'error' => '', 'id' => $id, 'files' => $aus['files']];
    }

    /**
     * Ein fertig gebautes Paket als Stand vermerken.
     *
     * Ohne Auspacken: Bei einer hier gebauten Website ist der
     * Arbeitsordner `dist`, und den erzeugt der Bau selbst. Das Archiv
     * kommt trotzdem in die Liste - sonst gäbe es zwei Orte, an denen
     * Fassungen einer Website stehen, und man müsste raten, welcher der
     * gemeinte ist. Genau daran hing der Fehler, der diese Klasse
     * überhaupt nötig gemacht hat.
     */
    public static function vermerken(array $projekt, string $zipPfad, string $note, int $dateien): int
    {
        return is_file($zipPfad) ? self::ablegen($projekt, $zipPfad, $note, $dateien) : 0;
    }

    /**
     * Einen älteren Stand wieder aktiv setzen.
     *
     * Vorher wird gesichert, woran gerade gearbeitet wird. Ohne diesen
     * Schritt wäre "Wiederherstellen" eine Falle: Ein Klick, und die
     * Arbeit der letzten Stunde ist weg, ohne dass irgendwo stand, dass
     * das passieren würde.
     *
     * Gesichert wird immer, wenn ein Arbeitsordner daliegt - auch dann,
     * wenn der gewählte Stand schon der aktive ist. Gerade dann sogar:
     * Das Archiv eines Standes ist der Zustand beim Ablegen, der Ordner
     * ist der von jetzt, und dazwischen liegt genau die Arbeit, um die
     * es geht. Eine Prüfung "nur wenn ein anderer Stand gemeint ist"
     * hätte den einen Fall übersprungen, für den diese Sicherung da ist.
     *
     * @return array{ok:bool, error:string}
     */
    public static function wiederherstellen(array $projekt, int $standId): array
    {
        $stand = self::finden((int) $projekt['id'], $standId);

        if ($stand === null) {
            return ['ok' => false, 'error' => 'Diesen Stand gibt es nicht.'];
        }

        $datei = self::pfad($stand);

        if ($datei === null) {
            return ['ok' => false, 'error' => 'Das Archiv dieses Standes liegt nicht mehr da.'];
        }

        if (Uebernahme::vorhanden($projekt)) {
            $jetzt = self::jetztPacken($projekt, 'Vor dem Wiederherstellen gesichert');

            if ($jetzt !== null) {
                self::ablegen($projekt, $jetzt, 'Vor dem Wiederherstellen gesichert', 0);
                @unlink($jetzt);
            }
        }

        $aus = Uebernahme::auspacken($projekt, $datei);

        if (!$aus['ok']) {
            return ['ok' => false, 'error' => $aus['error']];
        }

        Db::update('site_versions', ['is_active' => 0], 'project_id = :p', ['p' => (int) $projekt['id']]);
        Db::update('site_versions', ['is_active' => 1], 'id = :id', ['id' => $standId]);

        Audit::log('stand.wiederhergestellt', (string) $projekt['name'], ['stand' => $standId]);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Vermerken, dass gerade gespeichert wurde.
     *
     * Der Zeitpunkt steht am Stand und nicht an der Website: Was man
     * wissen will, ist "wann wurde in *diesem* Stand zuletzt etwas
     * geändert" - und das bleibt richtig, auch wenn danach ein anderer
     * aktiv gesetzt wird.
     */
    public static function gespeichert(int $projectId): void
    {
        $aktiv = self::aktiver($projectId);

        if ($aktiv === null) {
            return;
        }

        Db::update('site_versions', ['saved_at' => Db::now()], 'id = :id', ['id' => (int) $aktiv['id']]);
    }

    /**
     * Den Stand, an dem gearbeitet wird, frisch packen.
     *
     * **Immer frisch.** Genau hier sass der Fehler: Ein Archiv, das
     * schon dalag, ist nicht das, was auf dem Bildschirm steht.
     *
     * @return string|null Pfad zur fertigen Datei, oder null
     */
    public static function jetztPacken(array $projekt, string $anlass = ''): ?string
    {
        $quelle = Uebernahme::ordner($projekt);

        if (!is_dir($quelle)) {
            // Ohne übernommenen Stand: das Gebaute. Eine hier erzeugte
            // Website hat kein hochgeladenes Archiv, und trotzdem soll
            // der Knopf etwas herausgeben.
            $quelle = STORAGE_DIR . '/projects/' . (string) $projekt['slug'] . '/dist';
        }

        if (!is_dir($quelle) || !class_exists(\ZipArchive::class)) {
            return null;
        }

        $tmp = ensure_dir(STORAGE_DIR . '/tmp');
        self::tmpAufraeumen($tmp);

        $ziel = $tmp . '/'
            . (string) $projekt['slug'] . '-' . date('Y-m-d-Hi') . '-' . bin2hex(random_bytes(3)) . '.zip';

        $zip = new \ZipArchive();

        if ($zip->open($ziel, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        $eintraege = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($quelle, \FilesystemIterator::SKIP_DOTS)
        );

        $anzahl = 0;

        foreach ($eintraege as $eintrag) {
            /** @var \SplFileInfo $eintrag */
            if (!$eintrag->isFile() || $eintrag->isLink()) {
                continue;
            }

            $relativ = str_replace('\\', '/', substr($eintrag->getPathname(), strlen($quelle) + 1));

            if ($relativ === '' || str_contains($relativ, '..')) {
                continue;
            }

            $zip->addFile($eintrag->getPathname(), $relativ);
            $anzahl++;
        }

        $zip->close();

        if ($anzahl === 0 || !is_file($ziel)) {
            @unlink($ziel);

            return null;
        }

        if ($anlass !== '') {
            Logger::info('Stand gepackt', ['projekt' => (string) $projekt['slug'], 'anlass' => $anlass]);
        }

        return $ziel;
    }

    /** Der vollständige Pfad zum Archiv eines Standes – oder null. */
    public static function pfad(array $stand): ?string
    {
        $relativ = (string) ($stand['zip_path'] ?? '');

        if ($relativ === '' || str_contains($relativ, '..')) {
            return null;
        }

        $datei = STORAGE_DIR . '/zips/' . $relativ;

        return is_file($datei) ? $datei : null;
    }

    // ------------------------------------------------------------------
    // Innereien
    // ------------------------------------------------------------------

    /** Ein Archiv in die Ablage legen und als Stand eintragen. */
    private static function ablegen(array $projekt, string $zipPfad, string $note, int $dateien): int
    {
        $slug = (string) $projekt['slug'];
        $ordner = ensure_dir(STORAGE_DIR . '/zips/' . $slug);
        $name = $slug . '-' . date('Y-m-d-His') . '-' . bin2hex(random_bytes(3)) . '.zip';

        if (!@copy($zipPfad, $ordner . '/' . $name)) {
            return 0;
        }

        Db::update('site_versions', ['is_active' => 0], 'project_id = :p', ['p' => (int) $projekt['id']]);

        $id = (int) Db::insert('site_versions', [
            'project_id' => (int) $projekt['id'],
            'note' => mb_substr($note, 0, 120),
            'zip_path' => $slug . '/' . $name,
            'zip_bytes' => (int) filesize($ordner . '/' . $name),
            'files_count' => $dateien,
            'is_active' => 1,
            'created_at' => Db::now(),
            'saved_at' => null,
        ]);

        self::aufraeumen((int) $projekt['id'], $slug);

        return $id;
    }

    /**
     * Die Zwischenablage leeren.
     *
     * Jedes Herunterladen packt neu, und die fertige Datei wird
     * ausgeliefert, nicht gelöscht - während PHP sie noch sendet, wäre
     * das ein Rennen. Also räumt der nächste Klick auf, was vom
     * vorletzten übrig ist. Eine Stunde ist grosszügig genug, dass keine
     * laufende Übertragung darunter wegbricht.
     */
    private static function tmpAufraeumen(string $ordner): void
    {
        $grenze = time() - 3600;

        foreach ((array) glob($ordner . '/*.zip') as $datei) {
            if (is_file($datei) && (int) filemtime($datei) < $grenze) {
                @unlink($datei);
            }
        }
    }

    /**
     * Alte Stände wegräumen.
     *
     * Ohne das füllt ein Nachmittag Arbeit das Hosting-Konto: Jeder
     * Stand ist so gross wie die ganze Website. Zehn bleiben - genug,
     * um zurückzugehen, wenig genug, um nicht wehzutun. Der aktive
     * bleibt in jedem Fall.
     */
    private static function aufraeumen(int $projectId, string $slug): void
    {
        $alte = Db::all(
            'SELECT id, zip_path FROM site_versions
              WHERE project_id = :p AND is_active = 0
              ORDER BY id DESC',
            ['p' => $projectId]
        );

        foreach (array_slice($alte, self::BEHALTEN) as $eintrag) {
            $relativ = (string) $eintrag['zip_path'];

            if ($relativ !== '' && !str_contains($relativ, '..') && str_starts_with($relativ, $slug . '/')) {
                @unlink(STORAGE_DIR . '/zips/' . $relativ);
            }

            Db::delete('site_versions', 'id = :id', ['id' => (int) $eintrag['id']]);
        }
    }
}
