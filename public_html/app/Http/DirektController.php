<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Build\{Karte, Maske, Staende, Uebernahme};
use WebAtze\Core\{Audit, Config, Db, Request, Response, Session, View};

/**
 * Eine fremde Website bearbeiten.
 *
 * Der Editor daneben arbeitet auf Abschnitten in der Datenbank. Eine
 * Website, die aus dem Auftragstext zurueckkommt oder vom Hosting des
 * Kunden heruntergeladen wurde, hat davon nichts - sie ist HTML. Bisher
 * stand dort "Diese Website hat noch keine Seiten", und das war
 * wortwoertlich richtig und in der Sache unbrauchbar: Die Seiten liegen
 * ja da, sie stehen nur nicht in der Datenbank.
 *
 * Hier wird deshalb die Datei selbst bearbeitet. Die Seite laeuft in
 * einem Rahmen, gleiche Herkunft wie der Adminbereich - deshalb kann das
 * Skript daneben hineingreifen, ohne dass etwas in die Kundendatei
 * eingeschleust werden muesste. Was gespeichert wird, ist die Seite
 * selbst, ohne eine Spur der Bearbeitung.
 *
 * Was geht: Texte aendern, Bilder tauschen, Seite fuer Seite. Was nicht
 * geht: Abschnitte verschieben oder neue einsetzen - dafuer braucht es
 * das Datenmodell, und das hat eine fremde Website nicht.
 */
final class DirektController
{
    /** So gross darf eine einzelne Seite beim Speichern sein. */
    private const MAX_SEITE_BYTES = 4 * 1024 * 1024;

    /** Und ein hochgeladenes Bild. */
    private const MAX_BILD_BYTES = 12 * 1024 * 1024;

    /**
     * Was sich bearbeiten laesst.
     *
     * Nicht nur HTML. Eine Kundenwebsite besteht oft aus `index.php`
     * und `seite.php` - und weil hier nur `html` und `htm` standen,
     * fand der Editor bei ihr *keine einzige Seite* und zeigte
     * "Hier liegt noch keine Seite". Das war wortwoertlich richtig und
     * in der Sache unbrauchbar: Die Seiten lagen da, sie hiessen nur
     * anders.
     *
     * Ausgefuehrt wird dabei nie etwas. Der PHP-Code wird vor dem
     * Ausliefern weggeblendet und beim Speichern zeichengetreu
     * zurueckgesetzt - siehe `Build\Maske`.
     */
    private const SEITEN = ['html', 'htm', 'php', 'phtml', 'shtml'];

    /** Und was sich als Bild einsetzen laesst. */
    private const BILDER = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
        'avif' => 'image/avif',
    ];

    /**
     * Wie Dateien ausgeliefert werden.
     *
     * Dieselbe Liste wie in der Vorschau, und aus demselben Grund: Es
     * werden Bytes geschoben, keine Programme ausgefuehrt. Eine .php aus
     * dem Kundenarchiv kommt hier als Datenstrom heraus und nicht als
     * laufender Code.
     */
    private const TYPEN = [
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'ttf' => 'font/ttf',
        'txt' => 'text/plain; charset=utf-8',
        'xml' => 'application/xml; charset=utf-8',
        'webmanifest' => 'application/manifest+json',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'pdf' => 'application/pdf',
    ];

    // ------------------------------------------------------------------
    // Die Oberfläche
    // ------------------------------------------------------------------

    public function index(Request $request): Response
    {
        $projekt = ProjectController::find($request->paramInt('id'));

        if ($projekt === null) {
            return Response::notFound();
        }

        $seiten = self::seitenListe($projekt);
        $gewaehlt = trim((string) $request->query('seite'));

        if ($gewaehlt === '' || !in_array($gewaehlt, $seiten, true)) {
            $gewaehlt = $seiten[0] ?? '';
        }

        return Response::html(View::partial('layouts/admin', [
            'title' => 'Bearbeiten: ' . (string) $projekt['name'],
            'bodyClass' => 'wa-admin--editor',
            'content' => View::partial('admin/direkt', [
                'projekt' => $projekt,
                'seiten' => $seiten,
                'seite' => $gewaehlt,
                'offen' => \WebAtze\Domain\Websites::offeneAenderung($projekt),
                'stand' => Staende::aktiver((int) $projekt['id']),
            ]),
        ]))->noCache()->noIndex();
    }

    /**
     * Eine Datei des übernommenen Stands ausliefern.
     *
     * Für den Rahmen: gleiche Herkunft wie der Adminbereich, damit das
     * Skript daneben hineingreifen kann. Und hinter der Anmeldung - es
     * ist die Website eines Kunden, nicht die eigene.
     */
    public function datei(Request $request): Response
    {
        $projekt = ProjectController::find($request->paramInt('id'));

        if ($projekt === null) {
            return Response::notFound();
        }

        $datei = self::aufloesen($projekt, (string) $request->param('pfad'));

        if ($datei === null) {
            return Response::notFound('Diese Datei gibt es in diesem Stand nicht.');
        }

        $endung = strtolower(pathinfo($datei, PATHINFO_EXTENSION));

        $inhalt = (string) file_get_contents($datei);

        // Jedem Element seine Nummer mitgeben.
        //
        // Sie ist die Verbindung zwischen dem, was im Rahmen steht, und
        // dem, was in der Datei steht. Ohne sie muesste beim Speichern
        // das ganze Dokument zurueckgeschickt werden - und genau daran
        // ist der alte Editor gescheitert: Was der Browser
        // zurueckgibt, ist nie die Datei, die hineinging.
        $inhalt = Karte::nummerieren($inhalt, Karte::lesen($inhalt));

        // Und bei einer PHP-Datei geht der Code nicht mit hinaus.
        // Ausfuehren kaeme nicht in Frage - das waere fremder Code auf
        // dem eigenen Server.
        if (Maske::istPhp($datei)) {
            $inhalt = Maske::maskieren($inhalt)['html'];
            $endung = 'html';
        }

        return Response::make($inhalt)
            ->header('Content-Type', self::TYPEN[$endung] ?? 'application/octet-stream')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            // Nur wir selbst duerfen das einbetten. Ohne diese Zeile
            // koennte eine fremde Seite die Kundenwebsite in einen
            // Rahmen holen und den Anschein erwecken, sie gehoere ihr.
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->noCache();
    }

    // ------------------------------------------------------------------
    // Ändern
    // ------------------------------------------------------------------

    /** Eine bearbeitete Seite zurückschreiben. */
    /**
     * Speichern - aber nur die Stellen, die sich geändert haben.
     *
     * Der alte Weg schickte das **ganze Dokument** aus dem Browser
     * zurück und überschrieb die Datei damit. Das ging so lange gut,
     * bis es das nicht mehr tat - und dann war nicht zu sehen, woran es
     * lag: Der Browser gibt nie zurück, was er bekommen hat. Er
     * normalisiert beim Einlesen, und was zurückkam, war gleichwertig,
     * aber nie identisch.
     *
     * Jetzt kommt eine Liste von Änderungen, jede mit der Nummer ihres
     * Elements. Angefasst wird nur, was darin steht; der Rest der Datei
     * bleibt Byte für Byte stehen.
     *
     * Und danach wird **zurückgelesen und geprüft**. "Gespeichert"
     * heisst hier: Es steht nachweislich in der Datei - nicht bloss:
     * abgeschickt, kein Fehler.
     */
    public function speichern(Request $request): Response
    {
        $projekt = ProjectController::find($request->paramInt('id'));

        if ($projekt === null) {
            return Response::json(['ok' => false, 'error' => 'Website nicht gefunden.'], 404)->noCache();
        }

        $pfad = (string) $request->input('seite');
        $datei = self::aufloesen($projekt, $pfad);

        if ($datei === null || !in_array(strtolower(pathinfo($datei, PATHINFO_EXTENSION)), self::SEITEN, true)) {
            return Response::json(['ok' => false, 'error' => 'Diese Seite lässt sich nicht bearbeiten.'], 400)->noCache();
        }

        $liste = json_decode($request->roh('aenderungen', 2 * 1024 * 1024), true);

        if (!is_array($liste) || $liste === []) {
            return Response::json([
                'ok' => false,
                'error' => 'Es kam keine Änderung an.',
            ], 400)->noCache();
        }

        $quelle = (string) @file_get_contents($datei);

        // Ist die Datei noch die, an der gearbeitet wurde?
        //
        // Zwischen Öffnen und Speichern kann ein neues Archiv
        // hochgeladen oder ein Stand wiederhergestellt worden sein.
        // Dann zeigen die Nummern woandershin, als der Bearbeiter
        // meint - und geschrieben würde an einer Stelle, die er nie
        // gesehen hat.
        $finger = (string) $request->input('finger');

        if ($finger !== '' && $finger !== Karte::finger($quelle)) {
            return Response::json([
                'ok' => false,
                'neuLaden' => true,
                'error' => 'Diese Seite hat sich inzwischen geändert. Lade sie neu, '
                    . 'sonst würde an der falschen Stelle geschrieben.',
            ], 409)->noCache();
        }

        $ergebnis = self::anwenden($quelle, $liste);

        if ($ergebnis['error'] !== '') {
            return Response::json(['ok' => false, 'error' => $ergebnis['error']], 400)->noCache();
        }

        if ($ergebnis['inhalt'] === $quelle) {
            return Response::json([
                'ok' => true, 'geaendert' => 0,
                'finger' => Karte::finger($quelle),
            ])->noCache();
        }

        // Auf die Byte-Zahl prüfen, nicht auf `false`.
        //
        // Bei vollem Kontingent schreibt `file_put_contents` so viel es
        // kann und gibt die Anzahl zurück - nicht `false`. Eine Prüfung
        // auf `=== false` hielte das für Erfolg, und die Seite des
        // Kunden wäre mitten im HTML abgeschnitten.
        $geschrieben = @file_put_contents($datei, $ergebnis['inhalt'], LOCK_EX);

        if ($geschrieben !== strlen($ergebnis['inhalt'])) {
            return Response::json([
                'ok' => false,
                'error' => $geschrieben === false
                    ? 'Die Seite liess sich nicht schreiben. Fehlt dem Ordner das Schreibrecht?'
                    : sprintf(
                        'Nur %d von %d Bytes geschrieben - das Konto ist vermutlich voll. '
                        . 'Die Seite ist jetzt unvollständig; stelle sie über einen älteren '
                        . 'Stand wieder her.',
                        (int) $geschrieben,
                        strlen($ergebnis['inhalt'])
                    ),
            ], 500)->noCache();
        }

        // Zurücklesen. Das ist der Unterschied zu vorher.
        clearstatcache(true, $datei);
        $danach = (string) @file_get_contents($datei);

        if ($danach !== $ergebnis['inhalt']) {
            return Response::json([
                'ok' => false,
                'error' => 'Geschrieben, aber die Datei enthält danach etwas anderes. '
                    . 'Das deutet auf ein volles Konto oder einen zweiten Zugriff hin.',
            ], 500)->noCache();
        }

        Db::update('projects', ['updated_at' => Db::now()], 'id = :id', ['id' => (int) $projekt['id']]);
        Staende::gespeichert((int) $projekt['id']);

        Audit::log('direkt.gespeichert', (string) $projekt['name'], [
            'seite' => $pfad,
            'aenderungen' => $ergebnis['anzahl'],
        ], $request);

        return Response::json([
            'ok' => true,
            'geaendert' => $ergebnis['anzahl'],
            'bytes' => strlen($danach),
            'finger' => Karte::finger($danach),
        ])->noCache();
    }

    /**
     * Die Änderungen auf den Text anwenden.
     *
     * Jede Änderung wird erst in eine **Byte-Ersetzung** übersetzt -
     * "von hier bis dort steht künftig das" -, und alle zusammen werden
     * dann von hinten nach vorn eingesetzt.
     *
     * Warum nicht eine nach der anderen: Jede Ersetzung verschiebt
     * alles dahinter. Wer die zweite Änderung mit den Grenzen der
     * unveränderten Datei einsetzt, trifft daneben - und zwar nicht
     * knapp, sondern um genau so viele Zeichen, wie die erste länger
     * oder kürzer war. Dabei entsteht eine Datei, die aussieht, als
     * hätte jemand mit der Schere hineingeschnitten. Rückwärts bleibt
     * jede Grenze gültig, bis sie an der Reihe ist.
     *
     * Und wenn zwei Ersetzungen einander überlappen, wird gar nichts
     * geschrieben. Zwei Änderungen an derselben Stelle sind kein Fall,
     * den man erraten sollte.
     *
     * @return array{inhalt:string, anzahl:int, error:string}
     */
    private static function anwenden(string $quelle, array $liste): array
    {
        $karte = Karte::lesen($quelle);
        $schnitte = [];

        foreach ($liste as $eintrag) {
            if (!is_array($eintrag)) {
                continue;
            }

            $id = (int) ($eintrag['id'] ?? -1);
            $was = (string) ($eintrag['was'] ?? '');

            // Neue Bausteine tragen negative Nummern - sie stehen noch
            // nicht in der Datei und werden über ihren Anker gesetzt.
            if ($was === 'einfuegen') {
                $anker = (int) ($eintrag['id2'] ?? $eintrag['anker'] ?? -1);

                if (!isset($karte[$anker])) {
                    return self::fehler(sprintf('Die Stelle für den neuen Baustein (%d) gibt es nicht.', $anker), $quelle);
                }

                $stelle = ($eintrag['davor'] ?? false)
                    ? $karte[$anker]['von']
                    : $karte[$anker]['bis'];

                $schnitte[] = ['von' => $stelle, 'bis' => $stelle, 'text' => (string) ($eintrag['wert'] ?? '')];

                continue;
            }

            if (!isset($karte[$id])) {
                return self::fehler(sprintf('Element %d gibt es in dieser Seite nicht (mehr).', $id), $quelle);
            }

            $el = $karte[$id];

            // Ein Bereich, in dem PHP steckt, wird nicht als Text
            // überschrieben - dabei ginge der Code des Kunden verloren.
            if (in_array($was, ['text', 'entfernen'], true)
                && str_contains(self::inhaltVon($quelle, $el), '<?')
            ) {
                return self::fehler(
                    'In diesem Bereich steckt PHP. Er lässt sich nicht als Text ändern, '
                    . 'weil dabei der Code verlorenginge.',
                    $quelle
                );
            }

            switch ($was) {
                case 'text':
                    $schnitte[] = ['von' => $el['inhaltVon'], 'bis' => $el['inhaltBis'],
                        'text' => (string) ($eintrag['wert'] ?? '')];
                    break;

                case 'stil':
                case 'attribut':
                    $name = $was === 'stil'
                        ? 'style'
                        : (preg_replace('~[^a-z0-9-]~i', '', (string) ($eintrag['name'] ?? '')) ?: 'data-x');

                    // Das Tag für sich neu bauen und als Ganzes ersetzen.
                    $tagNeu = Karte::attributSetzen(
                        substr($quelle, $el['von'], $el['inhaltVon'] - $el['von']),
                        ['von' => 0, 'inhaltVon' => $el['inhaltVon'] - $el['von']],
                        $name,
                        (string) ($eintrag['wert'] ?? '')
                    );

                    $schnitte[] = ['von' => $el['von'], 'bis' => $el['inhaltVon'], 'text' => $tagNeu];
                    break;

                case 'entfernen':
                    $schnitte[] = ['von' => $el['von'], 'bis' => $el['bis'], 'text' => ''];
                    break;

                case 'tausch':
                    $mit = (int) ($eintrag['mit'] ?? -1);

                    if (!isset($karte[$mit])) {
                        return self::fehler(sprintf('Der Tauschpartner (%d) gibt es nicht.', $mit), $quelle);
                    }

                    $b = $karte[$mit];

                    // Ineinander geschachtelte lassen sich nicht
                    // tauschen: Das eine ist Teil des anderen, und
                    // danach gäbe es beide zweimal oder gar nicht.
                    if (($el['von'] >= $b['von'] && $el['bis'] <= $b['bis'])
                        || ($b['von'] >= $el['von'] && $b['bis'] <= $el['bis'])
                    ) {
                        return self::fehler(
                            'Diese beiden Blöcke liegen ineinander - sie lassen sich nicht tauschen.',
                            $quelle
                        );
                    }

                    // Zwei Ersetzungen, jede mit dem Text der anderen.
                    $schnitte[] = ['von' => $el['von'], 'bis' => $el['bis'],
                        'text' => substr($quelle, $b['von'], $b['bis'] - $b['von'])];
                    $schnitte[] = ['von' => $b['von'], 'bis' => $b['bis'],
                        'text' => substr($quelle, $el['von'], $el['bis'] - $el['von'])];
                    break;

                default:
                    break;
            }
        }

        if ($schnitte === []) {
            return ['inhalt' => $quelle, 'anzahl' => 0, 'error' => ''];
        }

        // Von hinten nach vorn - und vorher nachsehen, ob sich zwei in
        // die Quere kommen.
        usort($schnitte, static fn (array $a, array $b): int => $b['von'] <=> $a['von']);

        $letzterAnfang = strlen($quelle) + 1;

        foreach ($schnitte as $schnitt) {
            if ($schnitt['bis'] > $letzterAnfang) {
                return self::fehler(
                    'Zwei Änderungen betreffen dieselbe Stelle. Speichere sie einzeln - '
                    . 'zusammen wäre nicht zu entscheiden, welche gilt.',
                    $quelle
                );
            }

            $letzterAnfang = $schnitt['von'];
        }

        $inhalt = $quelle;

        foreach ($schnitte as $schnitt) {
            $inhalt = substr($inhalt, 0, $schnitt['von'])
                . $schnitt['text']
                . substr($inhalt, $schnitt['bis']);
        }

        return ['inhalt' => $inhalt, 'anzahl' => count($schnitte), 'error' => ''];
    }

    /** @return array{inhalt:string, anzahl:int, error:string} */
    private static function fehler(string $text, string $quelle): array
    {
        return ['inhalt' => $quelle, 'anzahl' => 0, 'error' => $text];
    }

    private static function inhaltVon(string $quelle, array $element): string
    {
        return substr($quelle, $element['inhaltVon'], $element['inhaltBis'] - $element['inhaltVon']);
    }

    /**
     * Ein Bild einsetzen.
     *
     * Es landet neben den anderen, unter `webatze-bilder/`, und behält
     * einen Namen, aus dem man es wiedererkennt. Der Aufrufer bekommt
     * den Pfad zurück und setzt ihn selbst in das `src`-Attribut.
     */
    public function bild(Request $request): Response
    {
        $projekt = ProjectController::find($request->paramInt('id'));

        if ($projekt === null) {
            return Response::json(['ok' => false, 'error' => 'Website nicht gefunden.'], 404)->noCache();
        }

        if (!Uebernahme::vorhanden($projekt)) {
            return Response::json(['ok' => false, 'error' => 'Es liegt kein Stand bereit.'], 400)->noCache();
        }

        $hoch = $request->file('bild');

        if ($hoch === null || (int) ($hoch['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return Response::json(['ok' => false, 'error' => 'Es ist kein Bild angekommen.'], 400)->noCache();
        }

        $tmp = (string) ($hoch['tmp_name'] ?? '');

        // is_uploaded_file und nicht bloss is_file: Ohne diese Pruefung
        // liesse sich ueber den Namen jede Datei des Servers als Bild
        // ausgeben.
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return Response::json(['ok' => false, 'error' => 'Diese Datei kam nicht über das Formular.'], 400)->noCache();
        }

        if ((int) filesize($tmp) > self::MAX_BILD_BYTES) {
            return Response::json(['ok' => false, 'error' => 'Das Bild ist zu gross.'], 413)->noCache();
        }

        $endung = strtolower(pathinfo((string) ($hoch['name'] ?? ''), PATHINFO_EXTENSION));

        if (!isset(self::BILDER[$endung])) {
            return Response::json([
                'ok' => false,
                'error' => 'Das ist kein Bildformat, das hier hingehört.',
            ], 415)->noCache();
        }

        // Nicht dem Namen glauben, sondern dem Inhalt. Eine .png, die in
        // Wahrheit etwas anderes ist, hat auf einer Kundenwebsite nichts
        // zu suchen. SVG ist Text und laesst sich so nicht pruefen -
        // deshalb steht es weiter unten noch einmal.
        if ($endung !== 'svg' && @getimagesize($tmp) === false) {
            return Response::json(['ok' => false, 'error' => 'Diese Datei ist kein Bild.'], 415)->noCache();
        }

        if ($endung === 'svg') {
            $roh = (string) @file_get_contents($tmp);

            // Ein SVG darf Skripte enthalten - auf einer fremden Website
            // ist das eine Hintertuer, die man nicht sieht.
            if (preg_match('/<\s*script|javascript:|on[a-z]+\s*=/i', $roh) === 1) {
                return Response::json([
                    'ok' => false,
                    'error' => 'Dieses SVG enthält Skripte. So etwas wird nicht eingesetzt.',
                ], 415)->noCache();
            }
        }

        $name = self::bildName((string) ($hoch['name'] ?? 'bild'), $endung);
        $ordner = ensure_dir(Uebernahme::ordner($projekt) . '/webatze-bilder');

        if (!@move_uploaded_file($tmp, $ordner . '/' . $name)) {
            return Response::json(['ok' => false, 'error' => 'Das Bild liess sich nicht ablegen.'], 500)->noCache();
        }

        Audit::log('direkt.bild', (string) $projekt['name'], ['datei' => $name], $request);

        return Response::json(['ok' => true, 'pfad' => 'webatze-bilder/' . $name])->noCache();
    }

    // ------------------------------------------------------------------
    // Innereien
    // ------------------------------------------------------------------

    /**
     * Alle bearbeitbaren Seiten des übernommenen Stands.
     *
     * Die Startseite zuerst - danach alphabetisch. Wer eine Website
     * bearbeitet, fängt fast immer dort an.
     *
     * @return list<string>
     */
    public static function seitenListe(array $projekt): array
    {
        if (!Uebernahme::vorhanden($projekt)) {
            return [];
        }

        $wurzel = Uebernahme::ordner($projekt);
        $treffer = [];

        $eintraege = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($wurzel, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($eintraege as $eintrag) {
            /** @var \SplFileInfo $eintrag */
            if (!$eintrag->isFile() || $eintrag->isLink()) {
                continue;
            }

            if (!in_array(strtolower($eintrag->getExtension()), self::SEITEN, true)) {
                continue;
            }

            $relativ = str_replace('\\', '/', substr($eintrag->getPathname(), strlen($wurzel) + 1));

            if ($relativ !== '' && !str_contains($relativ, '..')) {
                $treffer[] = $relativ;
            }
        }

        sort($treffer);

        usort($treffer, static fn (string $a, string $b): int
            => (int) ($b === 'index.html') - (int) ($a === 'index.html'));

        return $treffer;
    }

    /**
     * Einen Pfad im übernommenen Stand auflösen – ohne Ausbruch.
     *
     * realpath() löst alle Verweise und ".." auf; danach wird geprüft,
     * ob das Ergebnis wirklich noch im Ordner liegt. Damit sind
     * Pfad-Ausbrüche und Symlink-Tricks ausgeschlossen.
     */
    public static function aufloesen(array $projekt, string $pfad): ?string
    {
        if (!Uebernahme::vorhanden($projekt)) {
            return null;
        }

        $wurzel = realpath(Uebernahme::ordner($projekt));

        if ($wurzel === false) {
            return null;
        }

        $pfad = str_replace('\\', '/', trim($pfad, '/'));

        if ($pfad === '') {
            $pfad = 'index.html';
        }

        if (str_contains($pfad, "\0")) {
            return null;
        }

        $kandidat = realpath($wurzel . '/' . $pfad);

        if ($kandidat !== false && is_dir($kandidat)) {
            $kandidat = realpath($kandidat . '/index.html');
        }

        if ($kandidat === false || !is_file($kandidat) || is_link($wurzel . '/' . $pfad)) {
            return null;
        }

        return str_starts_with($kandidat, $wurzel . DIRECTORY_SEPARATOR) ? $kandidat : null;
    }

    /**
     * Die Zeilenenden der bisherigen Datei übernehmen.
     *
     * Gemessen und nicht geraten: Es zählt, was in der Datei überwiegt.
     * Gibt es sie nicht mehr, gilt LF - das ist auf einem Webserver das
     * Übliche.
     */
    private static function zeilenendenAngleichen(string $inhalt, string $datei): string
    {
        $inhalt = str_replace(["\r\n", "\r"], "\n", $inhalt);

        $vorher = (string) @file_get_contents($datei);
        $crlf = substr_count($vorher, "\r\n");
        $lf = substr_count($vorher, "\n") - $crlf;

        return $crlf > $lf ? str_replace("\n", "\r\n", $inhalt) : $inhalt;
    }

    /** Ein Dateiname, den man wiedererkennt und der nichts anrichtet. */
    private static function bildName(string $roh, string $endung): string
    {
        $stamm = strtolower(pathinfo($roh, PATHINFO_FILENAME));
        $stamm = (string) preg_replace('/[^a-z0-9]+/', '-', $stamm);
        $stamm = trim($stamm, '-');

        if ($stamm === '') {
            $stamm = 'bild';
        }

        return mb_substr($stamm, 0, 40) . '-' . bin2hex(random_bytes(4)) . '.' . $endung;
    }
}
