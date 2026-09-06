<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Build\{Staende, Uebernahme};
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

    /** Was sich bearbeiten laesst. */
    private const SEITEN = ['html', 'htm'];

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

        return Response::make((string) file_get_contents($datei))
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

        // Ungefiltert, und das ist hier die einzige richtige Wahl:
        // `input()` schneidet bei 2000 Zeichen ab und entfernt jeden
        // Zeilenumbruch. Eine Kundenseite kaeme damit gekuerzt und
        // einzeilig an - und die Laengenpruefung darunter saehe eine
        // Zahl, die schon nicht mehr stimmt.
        $inhalt = $request->roh('inhalt', self::MAX_SEITE_BYTES);

        if ($inhalt === '') {
            return Response::json([
                'ok' => false,
                'error' => 'Es kam nichts an - oder die Seite ist grösser als '
                    . format_bytes(self::MAX_SEITE_BYTES) . '.',
            ], 400)->noCache();
        }

        // Zeilenenden so lassen, wie die Datei sie hatte.
        //
        // Ein Formularfeld kommt laut Norm mit CRLF an - der Browser
        // stellt das beim Absenden um. Ohne diesen Schritt haette jede
        // gespeicherte Seite andere Zeilenenden als vorher: gerendert
        // dasselbe, im Vergleich mit dem Original aber jede Zeile
        // geaendert. Wer danach zwei Staende vergleicht, sieht nur noch
        // Rauschen.
        $inhalt = self::zeilenendenAngleichen($inhalt, $datei);

        if (@file_put_contents($datei, $inhalt, LOCK_EX) === false) {
            return Response::json(['ok' => false, 'error' => 'Die Seite liess sich nicht schreiben.'], 500)->noCache();
        }

        // Damit die Liste weiss, dass etwas draussen noch fehlt.
        Db::update('projects', ['updated_at' => Db::now()], 'id = :id', ['id' => (int) $projekt['id']]);

        // Und damit im Fenster steht, wann zuletzt gespeichert wurde.
        // Das ist die Angabe, an der man erkennt, ob der Klick etwas
        // bewirkt hat - ohne sie bleibt nur, es zu glauben.
        Staende::gespeichert((int) $projekt['id']);

        Audit::log('direkt.gespeichert', (string) $projekt['name'], ['seite' => $pfad], $request);

        return Response::json(['ok' => true, 'bytes' => strlen($inhalt)])->noCache();
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
