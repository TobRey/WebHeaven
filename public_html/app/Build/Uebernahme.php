<?php

declare(strict_types=1);

namespace WebAtze\Build;

use WebAtze\Core\{Db, Logger};
use WebAtze\Domain\Publications;

/**
 * Der Stand vom Kunden, als ZIP.
 *
 * Drei Wege zum Kundenserver waren durchgemessen und alle drei tot: FTP
 * mit verworfener Datenverbindung, eine Empfangsdatei über HTTPS, eine
 * dauerhafte Leseschnittstelle. Von einem Hosting zum anderen kommt
 * nichts durch.
 *
 * Also übernimmt der Mensch die Übertragung, mit seinem eigenen
 * FTP-Programm, und hier beginnt die Werkstatt: Das Archiv wird
 * ausgepackt und - wenn es eine von WebAtze gebaute Website ist - ihr
 * Inhalt in die Datenbank zurückgeholt. Danach steht im Editor
 * tatsächlich das, was beim Kunden lag: seine hochgeladenen Bilder,
 * seine im Backend geänderten Texte, seine eingegangenen Anfragen.
 *
 * Ausgepackt wird nach `storage/projects/<slug>/live`. Das war der
 * Einwand, der das Auspacken bisher verhindert hat: Fremde PHP-Dateien
 * auf dem eigenen Webserver sind eine Hintertür.
 *
 * Was diesen Ordner schützt - genau und ohne Beschönigung:
 *
 *   1. `public_html/storage/.htaccess` verbietet jeden direkten Zugriff
 *      (`Require all denied`). Auf Apache, und damit auf cPanel, ist das
 *      wirksam. **Es ist die einzige Sperre**: Der Ordner liegt
 *      unterhalb von `public_html`, weil WebAtze als ein Ordner
 *      ausgeliefert wird. Wer diese Datei löscht oder auf einen Server
 *      ohne `.htaccess` umzieht, macht die Kundendateien erreichbar -
 *      und PHP darin ausführbar.
 *   2. Ausgeliefert wird sonst nur über `PreviewController` und
 *      `DirektController`, und beide schieben Bytes mit einer festen
 *      Typenliste. Eine `.php` kommt dort als Datenstrom heraus, nicht
 *      als laufendes Programm.
 *
 * Der Testlauf prüft Punkt 1 mit, damit die Sperre nicht eines Tages
 * still verschwindet.
 */
final class Uebernahme
{
    /** Wohin ausgepackt wird - unterhalb des Projektordners. */
    public const ORDNER = 'live';

    /** So viele Dateien nimmt ein Archiv höchstens mit. */
    private const MAX_DATEIEN = 5000;

    /** Und so viel zusammen. */
    private const MAX_BYTES = 300 * 1024 * 1024;

    // ------------------------------------------------------------------
    // Auspacken
    // ------------------------------------------------------------------

    /**
     * Ein Archiv auspacken.
     *
     * @return array{ok:bool, files:int, bytes:int, error:string, ordner:string}
     */
    public static function auspacken(array $projekt, string $zipPfad): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return self::fehler('Dieser Server kann keine ZIP-Dateien lesen.');
        }

        $zip = new \ZipArchive();

        if ($zip->open($zipPfad) !== true) {
            return self::fehler('Das Archiv liess sich nicht öffnen.');
        }

        $plan = self::archivPlan($zip);

        if ($plan['error'] !== '') {
            $zip->close();

            return self::fehler($plan['error']);
        }

        if ($plan['dateien'] === []) {
            $zip->close();

            return self::fehler('Das Archiv ist leer.');
        }

        if (count($plan['dateien']) > self::MAX_DATEIEN) {
            $zip->close();

            return self::fehler(sprintf(
                'Das Archiv hat %d Dateien. Mehr als %d nimmt WebAtze nicht an.',
                count($plan['dateien']),
                self::MAX_DATEIEN
            ));
        }

        // Die Sperre steht, bevor etwas darunter liegt.
        //
        // Sie kommt aus dem Vollpaket, nicht aus dem Update - eine
        // Installation, die nur je aktualisiert wurde, koennte sie
        // verloren haben. Das hier faellt nicht auf, wenn es fehlt: Der
        // Ordner ist dann einfach offen. Also wird sie nachgelegt.
        self::sperreSichern();

        $ordner = self::ordner($projekt);

        // Der alte Stand kommt weg, bevor der neue kommt. Sonst bleiben
        // Dateien liegen, die es beim Kunden nicht mehr gibt - und
        // wandern beim nächsten Herunterladen wieder zu ihm zurück.
        self::leeren($ordner);
        ensure_dir($ordner);

        $anzahl = 0;
        $bytes = 0;

        try {
            foreach ($plan['dateien'] as $datei) {
                if ($bytes > self::MAX_BYTES) {
                    return self::fehler(sprintf(
                        'Das Archiv ist grösser als %s.',
                        format_bytes(self::MAX_BYTES)
                    ));
                }

                $inhalt = $zip->getFromIndex($datei['index']);

                if ($inhalt === false) {
                    return self::fehler('Die Datei ' . $datei['ziel'] . ' liess sich nicht lesen.');
                }

                $ziel = $ordner . '/' . $datei['ziel'];

                ensure_dir(dirname($ziel));

                if (@file_put_contents($ziel, $inhalt) === false) {
                    return self::fehler('Die Datei ' . $datei['ziel'] . ' liess sich nicht schreiben.');
                }

                $anzahl++;
                $bytes += strlen($inhalt);
            }
        } finally {
            $zip->close();
        }

        return ['ok' => true, 'files' => $anzahl, 'bytes' => $bytes, 'error' => '', 'ordner' => $ordner];
    }

    /**
     * Dafür sorgen, dass `storage` gesperrt ist.
     *
     * Der Inhalt ist derselbe wie im Vollpaket. Auf nginx greift eine
     * `.htaccess` nicht - das steht seit jeher in `install.php`, und es
     * bleibt wahr. Auf Apache und damit auf cPanel, wo WebAtze laeuft,
     * greift sie.
     */
    public static function sperreSichern(): void
    {
        $datei = STORAGE_DIR . '/.htaccess';

        if (is_file($datei)) {
            return;
        }

        @file_put_contents($datei, <<<'CONF'
            # Kein direkter Zugriff auf den Programmcode.
            <IfModule mod_authz_core.c>
                Require all denied
            </IfModule>
            <IfModule !mod_authz_core.c>
                Order allow,deny
                Deny from all
            </IfModule>
            CONF);

        Logger::warning('storage/.htaccess fehlte und wurde neu angelegt.');
    }

    /** Wo der ausgepackte Stand einer Website liegt. */
    public static function ordner(array $projekt): string
    {
        return STORAGE_DIR . '/projects/' . (string) $projekt['slug'] . '/' . self::ORDNER;
    }

    /** Liegt ein ausgepackter Stand bereit? */
    public static function vorhanden(array $projekt): bool
    {
        $slug = trim((string) ($projekt['slug'] ?? ''));

        if ($slug === '' || str_contains($slug, '..') || str_contains($slug, '/')) {
            return false;
        }

        return is_dir(self::ordner($projekt));
    }

    // ------------------------------------------------------------------
    // Inhalte zurückholen
    // ------------------------------------------------------------------

    /**
     * Was der Kunde geändert hat, zurück in die Datenbank.
     *
     * `data/site.php` ist die Website als Daten - dieselbe Form, die
     * `SiteBuilder::site()` erzeugt und `AdminKit` beim Bauen dorthin
     * schreibt. Hier geht sie den Weg zurück.
     *
     * Geschrieben wird über dieselben Tabellen, die der Editor liest.
     * Vorher legt `Publications::record()` je Seite eine Fassung an -
     * wer sich vertut, holt den letzten Stand über "Entwurf verwerfen"
     * zurück.
     *
     * @return array{ok:bool, seiten:int, abschnitte:int, uebrig:int, error:string}
     */
    public static function inhalteUebernehmen(array $projekt): array
    {
        $datei = self::ordner($projekt) . '/data/site.php';

        if (!is_file($datei)) {
            // Kein Fehler, sondern eine Auskunft: Die Website ist nicht
            // hier gebaut worden, also gibt es keine Abschnitte. Texte
            // und Bilder lassen sich trotzdem aendern - direkt in der
            // Datei.
            return self::inhaltFehler(
                'Keine Abschnittsdaten im Archiv - Texte und Bilder lassen sich '
                . 'trotzdem direkt in der Seite ändern.'
            );
        }

        $daten = self::siteLesen($datei);

        if ($daten === null) {
            return self::inhaltFehler('data/site.php liess sich nicht lesen.');
        }

        $seiten = $daten['pages'] ?? null;

        if (!is_array($seiten) || $seiten === []) {
            return self::inhaltFehler('In data/site.php stehen keine Seiten.');
        }

        $projektId = (int) $projekt['id'];
        $vorhanden = [];

        foreach (Db::all(
            'SELECT id, path FROM project_pages WHERE project_id = :p',
            ['p' => $projektId]
        ) as $zeile) {
            $vorhanden[(string) $zeile['path']] = (int) $zeile['id'];
        }

        $angefasst = [];
        $abschnitte = 0;

        foreach ($seiten as $ordnung => $seite) {
            if (!is_array($seite)) {
                continue;
            }

            $pfad = (string) ($seite['path'] ?? '');

            if ($pfad === '') {
                continue;
            }

            $seiteId = $vorhanden[$pfad] ?? 0;

            if ($seiteId > 0) {
                // Erst die Fassung, dann das Überschreiben. Das ist die
                // einzige Stelle, an der fremde Daten eigene ersetzen -
                // sie muss umkehrbar sein.
                try {
                    Publications::record($seiteId, 'Stand vom Kunden übernommen');
                } catch (\Throwable $e) {
                    Logger::warning('Fassung vor der Übernahme fehlgeschlagen: ' . $e->getMessage(), [
                        'seite' => $seiteId,
                    ]);
                }
            }

            $seiteId = self::seiteSchreiben($projektId, $seiteId, $seite, (int) $ordnung);
            $angefasst[] = $seiteId;
            $abschnitte += self::abschnitteSchreiben($projektId, $seiteId, (array) ($seite['sections'] ?? []));
        }

        // Seiten, die hier stehen und im Archiv fehlen, bleiben stehen.
        //
        // Löschen wäre die andere Möglichkeit und die gefährlichere: Ein
        // unvollständiges Archiv - eine halb abgebrochene Übertragung -
        // nähme sonst die halbe Website mit. Gemeldet wird es trotzdem,
        // sonst wundert sich später jemand.
        $uebrig = count($vorhanden) - count(array_intersect($vorhanden, $angefasst));

        Db::update('projects', ['updated_at' => Db::now()], 'id = :id', ['id' => $projektId]);

        return [
            'ok' => true,
            'seiten' => count($angefasst),
            'abschnitte' => $abschnitte,
            'uebrig' => max(0, $uebrig),
            'error' => '',
        ];
    }

    /**
     * data/site.php lesen.
     *
     * Die Datei beginnt mit `<?php exit; ?>` - damit sie im Browser
     * nichts preisgibt, wenn sie doch einmal im Web-Ordner landet.
     * Dahinter steht JSON. Gelesen wird als Text, nicht eingebunden:
     * Eine fremde PHP-Datei wird hier nicht ausgeführt.
     */
    public static function siteLesen(string $datei): ?array
    {
        $roh = (string) @file_get_contents($datei);

        if ($roh === '') {
            return null;
        }

        $klammer = strpos($roh, '{');

        if ($klammer === false) {
            return null;
        }

        $daten = json_decode(substr($roh, $klammer), true);

        return is_array($daten) ? $daten : null;
    }

    // ------------------------------------------------------------------
    // Archivprüfung
    // ------------------------------------------------------------------

    /**
     * Was aus einem Archiv wohin gehört – und ob es überhaupt geht.
     *
     * Zwei Aufgaben. Erstens: Kein Eintrag darf aus dem Zielordner
     * ausbrechen. Ein ZIP kommt von aussen, und seine Namen sind
     * Behauptungen - "../../" und absolute Pfade und Nullbytes.
     *
     * Zweitens: Liegt alles in einem einzigen Ordner – und genau so
     * packen die meisten –, wird der weggeschnitten. Sonst landete die
     * Website eine Ebene zu tief, und niemand fände sie.
     *
     * @return array{dateien:array<int, array{index:int, ziel:string, bytes:int}>, error:string, stamm:string}
     */
    public static function archivPlan(\ZipArchive $zip): array
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

            // Was Mac und Windows beilegen und was nirgends etwas zu
            // suchen hat. Geprueft wird je Pfadabschnitt und nicht am
            // Anfang des ganzen Namens: Packt jemand seinen
            // Website-Ordner ein, heisst der Eintrag
            // "meine-website/__MACOSX/._x" - und eine Pruefung auf den
            // Anfang laesst ihn durch. Genau das ist passiert.
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
     * Beipack, der nirgends etwas zu suchen hat.
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

    // ------------------------------------------------------------------
    // Innereien
    // ------------------------------------------------------------------

    /** Eine Seite anlegen oder auffrischen; gibt ihre Kennung zurück. */
    private static function seiteSchreiben(int $projektId, int $seiteId, array $seite, int $ordnung): int
    {
        $werte = [
            'path' => mb_substr((string) ($seite['path'] ?? '/'), 0, 191),
            'title' => mb_substr((string) ($seite['title'] ?? ''), 0, 191),
            'meta_description' => mb_substr((string) ($seite['meta_description'] ?? ''), 0, 255),
            'sort_order' => (int) ($seite['sort_order'] ?? $ordnung),
            'in_navigation' => !empty($seite['in_navigation']) ? 1 : 0,
            'translations' => json_encode((array) ($seite['translations'] ?? []),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => Db::now(),
        ];

        if ($seiteId > 0) {
            Db::update('project_pages', $werte, 'id = :id', ['id' => $seiteId]);

            return $seiteId;
        }

        return (int) Db::insert('project_pages', $werte + [
            'project_id' => $projektId,
            'created_at' => Db::now(),
        ]);
    }

    /**
     * Die Abschnitte einer Seite ersetzen.
     *
     * Ganz ersetzen und nicht abgleichen: Ein Abschnitt hat innerhalb
     * seiner Seite keine Identität ausser seinem Platz. Ein Abgleich
     * müsste sie erfinden und läge dann irgendwann daneben.
     */
    private static function abschnitteSchreiben(int $projektId, int $seiteId, array $abschnitte): int
    {
        Db::delete('project_sections', 'page_id = :p', ['p' => $seiteId]);

        $anzahl = 0;

        foreach (array_values($abschnitte) as $platz => $abschnitt) {
            if (!is_array($abschnitt)) {
                continue;
            }

            $typ = (string) ($abschnitt['type'] ?? '');

            if ($typ === '') {
                continue;
            }

            Db::insert('project_sections', [
                'project_id' => $projektId,
                'page_id' => $seiteId,
                'type' => mb_substr($typ, 0, 40),
                'template_key' => mb_substr((string) ($abschnitt['template_key'] ?? ''), 0, 60),
                'content' => self::alsJson($abschnitt['content'] ?? []),
                'overrides' => self::alsJson($abschnitt['overrides'] ?? []),
                'effects' => self::alsJson($abschnitt['effects'] ?? []),
                'translations' => self::alsJson($abschnitt['translations'] ?? []),
                'hidden' => !empty($abschnitt['hidden']) ? 1 : 0,
                'sort_order' => (int) ($abschnitt['sort_order'] ?? $platz),
                'created_at' => Db::now(),
                'updated_at' => Db::now(),
            ]);

            $anzahl++;
        }

        return $anzahl;
    }

    private static function alsJson(mixed $wert): string
    {
        return (string) json_encode(
            is_array($wert) ? $wert : [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /** Einen Ordner samt Inhalt wegräumen. */
    private static function leeren(string $ordner): void
    {
        if (!is_dir($ordner)) {
            return;
        }

        $eintraege = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($ordner, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($eintraege as $eintrag) {
            // Symlinks zuerst: Einer auf einen Ordner ist keiner, und
            // rmdir liefe daran vorbei - oder schlimmer, hindurch.
            if (is_link($eintrag->getPathname()) || $eintrag->isFile()) {
                @unlink($eintrag->getPathname());
            } else {
                @rmdir($eintrag->getPathname());
            }
        }

        @rmdir($ordner);
    }

    /** @return array{ok:bool, files:int, bytes:int, error:string, ordner:string} */
    private static function fehler(string $text): array
    {
        return ['ok' => false, 'files' => 0, 'bytes' => 0, 'error' => $text, 'ordner' => ''];
    }

    /** @return array{ok:bool, seiten:int, abschnitte:int, uebrig:int, error:string} */
    private static function inhaltFehler(string $text): array
    {
        return ['ok' => false, 'seiten' => 0, 'abschnitte' => 0, 'uebrig' => 0, 'error' => $text];
    }
}
