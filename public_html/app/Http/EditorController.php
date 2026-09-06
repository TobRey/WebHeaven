<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Build\{Pipeline, SiteBuilder, Theme};
use WebAtze\Core\{Assets, Audit, Config, Db, I18n, Logger, Request, Response, Session, View};
use WebAtze\Domain\{Publications, Sections};
use WebAtze\Templates\{Blocks, Catalog, Effects, Page, Renderer, Schema};

/**
 * Die Schnittstelle des Editors.
 *
 * Der Editor ist ein einziges JavaScript-Bündel, das an drei Orten läuft:
 * hier bei WebAtze für jede Kundenwebsite, im Bearbeitungsbereich beim
 * Kunden, und auf der eigenen Website. Damit das kein Wunsch bleibt,
 * redet es überall mit derselben Schnittstelle - hier gegen die
 * Datenbank, beim Kunden gegen data/site.php. Gleiche Adressen, gleiche
 * Antworten.
 *
 * Der Unterschied zum bisherigen Vorschau-Editor steckt in einer
 * einzigen Zeile, die es hier nicht mehr gibt: window.location.reload().
 * Bisher endete jede Änderung damit - Bildlauf weg, Auswahl weg, und
 * dazwischen ein weisses Aufblitzen. Stattdessen liefert jede ändernde
 * Antwort das fertige HTML des betroffenen Abschnitts zurück, und der
 * Editor tauscht genau dieses eine Element aus.
 *
 * Deshalb baut hier auch nichts die ganze Website neu. Gebaut wird beim
 * Veröffentlichen; bis dahin steht die Änderung in der Datenbank und
 * wird bei Bedarf gerendert. Ein vollständiger Neubau bei jedem Zug
 * hätte das Ziehen unbenutzbar gemacht.
 */
final class EditorController
{
    // ------------------------------------------------------------------
    // Die Oberfläche
    // ------------------------------------------------------------------

    /** Der Editor für eine Website. */
    public function index(Request $request): Response
    {
        $projekt = $this->projektAus($request);

        if ($projekt === null) {
            return Response::redirect(self::base() . '/websites')->noCache();
        }

        $seiten = Db::all(
            'SELECT * FROM project_pages WHERE project_id = :p ORDER BY sort_order ASC, id ASC',
            ['p' => (int) $projekt['id']]
        );

        // Keine Abschnitte? Dann ist es keine hier gebaute Website -
        // sondern eine, die als Archiv hereinkam.
        //
        // "Diese Website hat noch keine Seiten" war wortwoertlich richtig
        // und in der Sache unbrauchbar: Die Seiten liegen ja da, sie
        // stehen nur nicht in der Datenbank. Dafuer gibt es die
        // Direktbearbeitung - dort wird die Datei selbst geaendert.
        if ($seiten === []) {
            return Response::redirect(self::base() . '/direkt/' . (int) $projekt['id'])->noCache();
        }

        $seiteId = $request->int('seite') ?: (int) $seiten[0]['id'];
        $seite = null;

        foreach ($seiten as $kandidat) {
            if ((int) $kandidat['id'] === $seiteId) {
                $seite = $kandidat;
            }
        }

        $seite ??= $seiten[0];

        return Response::html(View::partial('layouts/admin', [
            'title' => 'Editor · ' . (string) $projekt['name'],
            'bodyClass' => 'wa-admin--editor',
            'content' => View::partial('admin/editor', [
                'projekt' => $projekt,
                'seiten' => $seiten,
                'seite' => $seite,
                'entwurf' => Publications::hasDraft((int) $seite['id']),
                'daten' => $this->konfiguration($projekt, $seite, $seiten),
                // Die zwei Enden der Schleife: Was hereinkam, und was
                // noch hinaus muss. Uebertragen wird von Hand - also
                // gehoert beides dorthin, wo gearbeitet wird.
                'pakete' => \WebAtze\Build\ZipExporter::listFor((int) $projekt['id']),
                'offen' => \WebAtze\Domain\Websites::offeneAenderung($projekt),
            ]),
        ]))->noCache()->noIndex();
    }

    /**
     * Was der Editor beim Start wissen muss.
     *
     * Alles in einem Zug statt in fünf Anfragen: Der Editor soll stehen,
     * bevor jemand das erste Mal etwas anfasst.
     */
    private function konfiguration(array $projekt, array $seite, array $seiten): array
    {
        return [
            'projekt' => ['id' => (int) $projekt['id'], 'name' => (string) $projekt['name']],
            'seite' => ['id' => (int) $seite['id'], 'titel' => (string) $seite['title']],
            'seiten' => array_map(static fn (array $s): array => [
                'id' => (int) $s['id'],
                'titel' => (string) $s['title'],
                'pfad' => (string) $s['path'],
            ], $seiten),
            'rahmen' => self::base() . '/editor/' . (int) $projekt['id']
                . '/seite/' . (int) $seite['id'] . '/ansicht',
            'api' => self::base() . '/editor/' . (int) $projekt['id'],
            'token' => Session::csrfToken(),
            'typen' => $this->typen(),
            'bausteine' => Blocks::arten(),
            'effekte' => Effects::schema(),
            'sprachen' => $this->sprachen($projekt),
        ];
    }

    /** Welche Abschnitte sich einsetzen lassen. */
    private function typen(): array
    {
        $liste = [];

        foreach (Schema::all() as $typ => $beschreibung) {
            // Kopf und Fuss gibt es je Seite genau einmal; sie stehen
            // deshalb nicht im Vorrat zum Hineinziehen.
            if (in_array($typ, ['header', 'footer'], true)) {
                continue;
            }

            $liste[] = [
                'typ' => $typ,
                'label' => (string) $beschreibung['label'],
                'beschreibung' => (string) $beschreibung['description'],
            ];
        }

        return $liste;
    }

    /** @return list<array{code:string, label:string}> */
    private function sprachen(array $projekt): array
    {
        $codes = array_filter(array_map(
            'trim',
            explode(',', (string) ($projekt['locales'] ?? $projekt['locale'] ?? 'de'))
        ));

        return array_values(array_map(static fn (string $c): array => [
            'code' => $c,
            'label' => strtoupper($c),
        ], $codes ?: ['de']));
    }

    // ------------------------------------------------------------------
    // Die Ansicht im Rahmen
    // ------------------------------------------------------------------

    /**
     * Die Seite selbst, wie sie der Besucher sähe.
     *
     * Sie liegt in einem Rahmen gleicher Herkunft. Das ist der Grund,
     * warum der Editor überhaupt ziehen kann: Über eine fremde Domain
     * hinweg darf JavaScript den Inhalt eines Rahmens weder lesen noch
     * verändern - und daran ändert keine Einstellung etwas.
     *
     * Der Rahmen wird beim Umschalten auf Tablet oder Handy wirklich
     * schmaler. Die alte Vorschau hat stattdessen nur den Body
     * zusammengeschoben, weshalb die Umbruchregeln der Seite gar nicht
     * ausgelöst haben: Die Handyansicht zeigte eine schmale Desktopseite.
     */
    public function view(Request $request): Response
    {
        $projekt = $this->projektAus($request);
        $seite = $this->seiteAus($request, $projekt);

        if ($projekt === null || $seite === null) {
            return Response::html('<!doctype html><title>Nicht gefunden</title>', 404)->noCache();
        }

        $sprache = $this->sprache($request, $projekt);

        return Response::html($this->seiteRendern($projekt, $seite, $sprache))
            ->noCache()
            ->noIndex();
    }

    /**
     * Die Dateien der Website: Stylesheet, Skript, Bilder, Schriften.
     *
     * Die Seite im Rahmen verweist relativ auf "assets/css/site.css".
     * Sie liegt unter .../seite/{seite}/ansicht, also sucht der Browser
     * unter .../seite/{seite}/assets/css/site.css - und genau das
     * beantwortet diese Adresse.
     *
     * Der Umweg über relative Pfade ist kein Versehen: Dieselbe Seite
     * muss später als Datei auf einem fremden Server liegen, wo es
     * keinen Router gibt. Eine absolute Adresse hier hiesse, dass die
     * Vorschau etwas anderes zeigt als die Auslieferung.
     */
    public function file(Request $request): Response
    {
        $projekt = $this->projektAus($request);

        if ($projekt === null) {
            return Response::make('', 404)->noCache();
        }

        $pfad = ltrim((string) $request->param('path'), '/');

        // Kein Ausbruch aus dem Ordner der Website. Der Pfad kommt aus
        // der Adresszeile, und dort steht, was jemand hineinschreibt.
        if ($pfad === '' || str_contains($pfad, '..') || str_contains($pfad, "\0")) {
            return Response::make('', 404)->noCache();
        }

        $wurzel = STORAGE_DIR . '/projects/' . (string) $projekt['slug'] . '/dist';
        $datei = $wurzel . '/' . $pfad;

        // Noch nie gebaut? Dann jetzt - sonst zeigt der Editor beim
        // ersten Öffnen einer frischen Website eine nackte Seite.
        if (!is_file($datei)) {
            $this->bauen($projekt);
        }

        $echt = realpath($datei);
        $echteWurzel = realpath($wurzel);

        if ($echt === false || $echteWurzel === false || !str_starts_with($echt, $echteWurzel)) {
            return Response::make('', 404)->noCache();
        }

        return Response::make((string) file_get_contents($echt))
            ->header('Content-Type', self::typVon($echt))
            ->header('Cache-Control', 'no-store')
            ->noIndex();
    }

    /** Welcher Inhaltstyp zu welcher Endung gehört. */
    private static function typVon(string $datei): string
    {
        return match (strtolower(pathinfo($datei, PATHINFO_EXTENSION))) {
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'html' => 'text/html; charset=utf-8',
            default => 'application/octet-stream',
        };
    }

    /** Eine ganze Seite bauen, mit dem Editor darin. */
    private function seiteRendern(array $projekt, array $seite, string $sprache): string
    {
        $seite['sections'] = Sections::forPage((int) $seite['id']);

        $daten = $this->websiteDaten($projekt);
        $html = Page::render($daten, $seite, (string) ($daten['critical_css'] ?? ''), $sprache);

        return $this->editorEinsetzen($html);
    }

    /**
     * Das Stylesheet des Editors in die Seite einsetzen – mehr nicht.
     *
     * Kein Skript im Rahmen. Der Editor läuft in der Hülle und greift
     * über die gleiche Herkunft in den Rahmen hinein; ein zweites Skript
     * dort hätte einen zweiten Zustand bedeutet, und zwei Zustände
     * laufen auseinander.
     *
     * Das Stylesheet muss aber hinein: Auswahlrahmen und Ablegelinien
     * werden im Rahmen gezeichnet, weil nur dort die Abschnitte liegen.
     */
    private function editorEinsetzen(string $html): string
    {
        $overlay = "\n<link rel=\"stylesheet\" href=\"" . e(Assets::url('editor.css')) . "\">\n";

        $stelle = strripos($html, '</body>');

        return $stelle === false
            ? $html . $overlay
            : substr($html, 0, $stelle) . $overlay . substr($html, $stelle);
    }

    // ------------------------------------------------------------------
    // Ändern
    // ------------------------------------------------------------------

    /** Einen Abschnitt auf einen bestimmten Platz setzen. */
    public function move(Request $request): Response
    {
        return $this->handeln($request, function (array $projekt) use ($request): array {
            $ergebnis = Sections::move($request->int('abschnitt'), $request->int('position'));

            if ($ergebnis['ok']) {
                Audit::log('editor.move', 'section', [
                    'abschnitt' => $request->int('abschnitt'),
                    'position' => $request->int('position'),
                ]);
            }

            return $ergebnis;
        });
    }

    /** Einen Abschnitt einsetzen. */
    public function add(Request $request): Response
    {
        return $this->handeln($request, function (array $projekt) use ($request): array {
            $seite = $this->seiteAus($request, $projekt);

            if ($seite === null) {
                return ['ok' => false, 'error' => 'Diese Seite gibt es nicht.'];
            }

            $position = $request->input('position') === '' ? null : $request->int('position');

            $ergebnis = Sections::add(
                (int) $seite['id'],
                (string) $request->input('typ'),
                $position,
                (string) $request->input('variante')
            );

            if ($ergebnis['ok']) {
                Audit::log('editor.add', 'section', [
                    'abschnitt' => $ergebnis['id'],
                    'typ' => $request->input('typ'),
                ]);
                $ergebnis['html'] = $this->abschnittHtml($ergebnis['id'], $projekt);
            }

            return $ergebnis;
        });
    }

    /** Einen Abschnitt entfernen. */
    public function remove(Request $request): Response
    {
        return $this->handeln($request, function (array $projekt) use ($request): array {
            $id = $request->int('abschnitt');
            $ergebnis = Sections::remove($id);

            if ($ergebnis['ok']) {
                Audit::log('editor.remove', 'section', ['abschnitt' => $id]);
            }

            return $ergebnis;
        });
    }

    /** Einen Abschnitt verdoppeln. */
    public function duplicate(Request $request): Response
    {
        return $this->handeln($request, function (array $projekt) use ($request): array {
            $ergebnis = Sections::duplicate($request->int('abschnitt'));

            if ($ergebnis['ok']) {
                $ergebnis['html'] = $this->abschnittHtml($ergebnis['id'], $projekt);
            }

            return $ergebnis;
        });
    }

    /** Einen Listeneintrag ziehen, anlegen oder löschen. */
    public function item(Request $request): Response
    {
        return $this->handeln($request, function (array $projekt) use ($request): array {
            $id = $request->int('abschnitt');
            $liste = (string) ($request->input('liste') ?: 'items');

            $ergebnis = match ((string) $request->input('was')) {
                'ziehen' => Sections::moveItem($id, $request->int('von'), $request->int('nach'), $liste),
                'anlegen' => Sections::addItem($id, $request->int('nach'), $liste),
                'loeschen' => Sections::removeItem($id, $request->int('platz'), $liste),
                default => ['ok' => false, 'error' => 'Unbekannte Aktion.'],
            };

            if ($ergebnis['ok']) {
                $ergebnis['html'] = $this->abschnittHtml($id, $projekt);
            }

            return $ergebnis;
        });
    }

    /** Ein Feld eines Abschnitts ändern. */
    public function field(Request $request): Response
    {
        return $this->handeln($request, function (array $projekt) use ($request): array {
            $id = $request->int('abschnitt');
            $abschnitt = Sections::find($id);

            if ($abschnitt === null) {
                return ['ok' => false, 'error' => 'Diesen Abschnitt gibt es nicht.'];
            }

            $pfad = (string) $request->input('feld');
            $wert = $request->input('wert');

            $inhalt = $this->setzen($abschnitt['content'], $pfad, $wert);

            // Geprüft wird gegen dasselbe Schema, das auch die
            // Schnittstelle bindet. Ein Feld, das es nicht gibt, kommt
            // damit weder von Hand noch aus einer Antwort durch.
            $geprueft = \WebAtze\Ai\ContentWriter::validate((string) $abschnitt['type'], $inhalt);

            if ($geprueft === null) {
                return ['ok' => false, 'error' => 'Dieser Wert passt nicht zu diesem Feld.'];
            }

            Db::update(
                'project_sections',
                ['content' => $geprueft, 'updated_at' => Db::now()],
                'id = :id',
                ['id' => $id]
            );

            return ['ok' => true, 'error' => '', 'html' => $this->abschnittHtml($id, $projekt)];
        });
    }

    /** Vorlage wechseln. */
    public function template(Request $request): Response
    {
        return $this->handeln($request, function (array $projekt) use ($request): array {
            $id = $request->int('abschnitt');
            $abschnitt = Sections::find($id);

            if ($abschnitt === null) {
                return ['ok' => false, 'error' => 'Diesen Abschnitt gibt es nicht.'];
            }

            $variante = (string) $request->input('variante');

            if (!Catalog::exists((string) $abschnitt['type'], $variante)) {
                return ['ok' => false, 'error' => 'Diese Vorlage gibt es nicht.'];
            }

            Db::update(
                'project_sections',
                ['template_key' => $variante, 'updated_at' => Db::now()],
                'id = :id',
                ['id' => $id]
            );

            return ['ok' => true, 'error' => '', 'html' => $this->abschnittHtml($id, $projekt)];
        });
    }

    /** Hintergrund, Bewegung und Parallaxe setzen. */
    public function effects(Request $request): Response
    {
        return $this->handeln($request, function (array $projekt) use ($request): array {
            $id = $request->int('abschnitt');
            $ergebnis = Sections::setEffects($id, $request->jsonBody()['effekte'] ?? $request->input('effekte'));

            if ($ergebnis['ok']) {
                $ergebnis['html'] = $this->abschnittHtml($id, $projekt);
            }

            return $ergebnis;
        });
    }

    /** Die Bausteine eines freien Abschnitts setzen. */
    public function blocks(Request $request): Response
    {
        return $this->handeln($request, function (array $projekt) use ($request): array {
            $id = $request->int('abschnitt');
            $abschnitt = Sections::find($id);

            if ($abschnitt === null || (string) $abschnitt['type'] !== 'frei') {
                return ['ok' => false, 'error' => 'Das ist kein freier Abschnitt.'];
            }

            $blocks = Blocks::clean($request->jsonBody()['blocks'] ?? []);

            Db::update(
                'project_sections',
                ['content' => ['blocks' => $blocks], 'updated_at' => Db::now()],
                'id = :id',
                ['id' => $id]
            );

            return [
                'ok' => true,
                'error' => '',
                'blocks' => count($blocks),
                'html' => $this->abschnittHtml($id, $projekt),
            ];
        });
    }

    /** Einen Abschnitt aus- oder wieder einblenden. */
    public function toggle(Request $request): Response
    {
        return $this->handeln($request, function (array $projekt) use ($request): array {
            $id = $request->int('abschnitt');
            $abschnitt = Sections::find($id);

            if ($abschnitt === null) {
                return ['ok' => false, 'error' => 'Diesen Abschnitt gibt es nicht.'];
            }

            $versteckt = empty($abschnitt['hidden']) ? 1 : 0;

            Db::update(
                'project_sections',
                ['hidden' => $versteckt, 'updated_at' => Db::now()],
                'id = :id',
                ['id' => $id]
            );

            // Ein ausgeblendeter Abschnitt verschwindet aus der fertigen
            // Seite - dann kann ihn niemand mehr anklicken, um ihn
            // zurückzuholen. Im Editor bleibt er deshalb sichtbar, nur
            // gekennzeichnet. Das entscheidet die Ansicht, nicht hier.
            return ['ok' => true, 'error' => '', 'hidden' => $versteckt === 1];
        });
    }

    // ------------------------------------------------------------------
    // Das Promptfeld
    // ------------------------------------------------------------------

    /**
     * Eine Anweisung für einen Abschnitt.
     *
     * Der Editor speichert vorher, was von Hand geändert wurde – das ist
     * seine Aufgabe, nicht unsere. Hier wird nur mit dem gearbeitet, was
     * in der Datenbank steht, und das ist nach dem Speichern der aktuelle
     * Stand.
     *
     * Geändert werden darf ausschliesslich dieser eine Abschnitt. Das ist
     * keine Absichtserklärung: Die Antwort wird gegen das Schema dieses
     * Typs geprüft, und geschrieben wird mit "WHERE id = :id".
     */
    public function instruct(Request $request): Response
    {
        return $this->handeln($request, function (array $projekt) use ($request): array {
            $id = $request->int('abschnitt');
            $abschnitt = Sections::find($id);

            if ($abschnitt === null || (int) $abschnitt['project_id'] !== (int) $projekt['id']) {
                return ['ok' => false, 'error' => 'Diesen Abschnitt gibt es nicht.'];
            }

            $anweisung = trim((string) $request->input('anweisung'));

            if ($anweisung === '') {
                return ['ok' => false, 'error' => 'Bitte beschreiben, was anders werden soll.'];
            }

            // Ein Aufruf kostet Geld und dauert. Wer aus Versehen zehnmal
            // auf denselben Knopf kommt, soll das nicht zehnmal bezahlen.
            if (!\WebAtze\Core\RateLimit::hit('editor:' . (int) $projekt['id'], 60, 3600)) {
                return [
                    'ok' => false,
                    'error' => 'Viele Anweisungen in kurzer Zeit. Bitte in einer Stunde weitermachen.',
                ];
            }

            $editor = new \WebAtze\Ai\SectionEditor((int) $projekt['id']);

            $ergebnis = $editor->apply(
                $abschnitt,
                $anweisung,
                (string) (Session::user()['username'] ?? 'webatze'),
                'manual'
            );

            if (!empty($ergebnis['ok'])) {
                $ergebnis['html'] = $this->abschnittHtml($id, $projekt);
            }

            return $ergebnis;
        });
    }

    /**
     * Eine Anweisung für die ganze Seite.
     *
     * Sie darf mehr als eine Abschnittsanweisung: Abschnitte anlegen,
     * löschen, umordnen. Deshalb läuft sie in zwei Zügen – erst ein Plan,
     * was geschehen soll, dann die Ausführung Abschnitt für Abschnitt.
     *
     * Ein einzelner Aufruf, der die ganze Seite auf einmal zurückgibt,
     * wäre einfacher zu schreiben und schwerer zu ertragen: Er würde bei
     * jedem Versuch auch die Abschnitte neu schreiben, die niemand
     * angefasst haben wollte.
     */
    public function pagePrompt(Request $request): Response
    {
        return $this->handeln($request, function (array $projekt) use ($request): array {
            $seite = $this->seiteAus($request, $projekt);

            if ($seite === null) {
                return ['ok' => false, 'error' => 'Diese Seite gibt es nicht.'];
            }

            $anweisung = trim((string) $request->input('anweisung'));

            if ($anweisung === '') {
                return ['ok' => false, 'error' => 'Bitte beschreiben, was anders werden soll.'];
            }

            if (!\WebAtze\Core\RateLimit::hit('editor:' . (int) $projekt['id'], 60, 3600)) {
                return ['ok' => false, 'error' => 'Viele Anweisungen in kurzer Zeit.'];
            }

            $plan = \WebAtze\Ai\PagePlanner::plan(
                $projekt,
                $seite,
                Sections::forPage((int) $seite['id']),
                $anweisung
            );

            if (!$plan['ok']) {
                return $plan;
            }

            $getan = $this->planAusfuehren($plan['schritte'], $seite, $projekt, $anweisung);

            return [
                'ok' => true,
                'error' => '',
                'summary' => $plan['summary'],
                'schritte' => $getan,
                'neuladen' => true,
            ];
        });
    }

    /**
     * Den Plan abarbeiten.
     *
     * Jeder Schritt für sich, jeder mit derselben Prüfung wie eine
     * Handbewegung im Editor. Ein Schritt, der scheitert, hält die
     * anderen nicht auf – sonst bliebe eine halb umgebaute Seite stehen,
     * und niemand wüsste, wo sie stehengeblieben ist.
     *
     * @return list<string>
     */
    private function planAusfuehren(array $schritte, array $seite, array $projekt, string $anweisung): array
    {
        $getan = [];

        foreach ($schritte as $schritt) {
            $was = (string) ($schritt['was'] ?? '');
            $id = (int) ($schritt['abschnitt'] ?? 0);

            try {
                $ergebnis = match ($was) {
                    'einsetzen' => Sections::add(
                        (int) $seite['id'],
                        (string) ($schritt['typ'] ?? ''),
                        isset($schritt['position']) ? (int) $schritt['position'] : null
                    ),
                    'entfernen' => Sections::remove($id),
                    'ziehen' => Sections::move($id, (int) ($schritt['position'] ?? 0)),
                    'anpassen' => (new \WebAtze\Ai\SectionEditor((int) $projekt['id']))->apply(
                        Sections::find($id) ?? [],
                        (string) ($schritt['anweisung'] ?? $anweisung),
                        (string) (Session::user()['username'] ?? 'webatze'),
                        'manual'
                    ),
                    default => ['ok' => false, 'error' => 'Unbekannter Schritt.'],
                };
            } catch (\Throwable $e) {
                Logger::exception($e);
                $ergebnis = ['ok' => false, 'error' => 'Dieser Schritt ist gescheitert.'];
            }

            $getan[] = ($ergebnis['ok'] ?? false)
                ? $was . ': erledigt'
                : $was . ': ' . (string) ($ergebnis['error'] ?? 'gescheitert');
        }

        return $getan;
    }

    // ------------------------------------------------------------------
    // Thema
    // ------------------------------------------------------------------

    /**
     * Farben und Schriften der ganzen Website.
     *
     * Eine Änderung hier zieht durch jeden Abschnitt, ohne dass einer
     * davon angefasst wird - das ist der Sinn der Variablen. Deshalb
     * antwortet diese Adresse auch nicht mit dem HTML eines Abschnitts,
     * sondern sagt dem Editor, dass er die Ansicht neu laden soll.
     */
    public function theme(Request $request): Response
    {
        return $this->handeln($request, function (array $projekt) use ($request): array {
            $thema = is_array($projekt['theme'] ?? null) ? $projekt['theme'] : [];

            $wuensche = [
                'primary' => (string) ($request->input('primary') ?: ($thema['colors']['primary_raw'] ?? '')),
                'secondary' => (string) ($request->input('secondary') ?: ($thema['colors']['secondary'] ?? '')),
                'accent' => (string) ($request->input('accent') ?: ($thema['colors']['ink'] ?? '')),
                'mode' => (string) ($request->input('mode') ?: ($thema['mode'] ?? 'hell')),
            ];

            $neu = Theme::build($wuensche, (string) $request->input('stil') ?: 'clean');

            Db::update(
                'projects',
                ['theme' => $neu, 'updated_at' => Db::now()],
                'id = :id',
                ['id' => (int) $projekt['id']]
            );

            Audit::log('editor.theme', 'project', $wuensche + ['projekt' => (int) $projekt['id']]);

            return ['ok' => true, 'error' => '', 'neuladen' => true, 'bericht' => $neu['report'] ?? []];
        });
    }

    // ------------------------------------------------------------------
    // Entwurf und Veröffentlichen
    // ------------------------------------------------------------------

    /** Den Entwurf live schieben. */
    public function publish(Request $request): Response
    {
        return $this->handeln($request, function (array $projekt) use ($request): array {
            $seite = $this->seiteAus($request, $projekt);

            if ($seite === null) {
                return ['ok' => false, 'error' => 'Diese Seite gibt es nicht.'];
            }

            Publications::record((int) $seite['id'], (string) $request->input('notiz'));

            // Erst jetzt wird die Website gebaut. Bei jedem Zug zu bauen
            // hätte das Ziehen unbenutzbar gemacht - und niemand will
            // einen Zwischenstand veröffentlichen, den er gerade erst
            // angefangen hat.
            $this->bauen($projekt);

            Audit::log('editor.publish', 'page', ['seite' => (int) $seite['id']]);

            $hinaus = $this->hinausschieben($projekt, $request->input('ueberschreiben') === '1');

            return [
                'ok' => true,
                'error' => '',
                'meldung' => 'Veröffentlicht.' . ($hinaus === '' ? '' : ' ' . $hinaus),
                'entwurf' => false,
                'live' => $hinaus,
            ];
        });
    }

    /** Den Entwurf verwerfen. */
    public function discard(Request $request): Response
    {
        return $this->handeln($request, function (array $projekt) use ($request): array {
            $seite = $this->seiteAus($request, $projekt);

            if ($seite === null) {
                return ['ok' => false, 'error' => 'Diese Seite gibt es nicht.'];
            }

            $ergebnis = Publications::discard((int) $seite['id']);
            $ergebnis['neuladen'] = true;

            return $ergebnis;
        });
    }

    /** Die Fassungen einer Seite. */
    public function versions(Request $request): Response
    {
        $projekt = $this->projektAus($request);
        $seite = $this->seiteAus($request, $projekt);

        if ($projekt === null || $seite === null) {
            return Response::json(['ok' => false, 'error' => 'Nicht gefunden.'], 404)->noCache();
        }

        return Response::json([
            'ok' => true,
            'entwurf' => Publications::hasDraft((int) $seite['id']),
            'fassungen' => Publications::forPage((int) $seite['id']),
        ])->noCache();
    }

    /** Eine Fassung zurückholen. */
    public function restore(Request $request): Response
    {
        return $this->handeln($request, function (array $projekt) use ($request): array {
            $ergebnis = Publications::restore($request->int('fassung'));
            $ergebnis['neuladen'] = true;

            return $ergebnis;
        });
    }

    /**
     * Den fertigen Stand auf die Kundendomain bringen.
     *
     * Zuerst die Brücke: Sie schreibt in etwa einer Sekunde. Gibt es sie
     * nicht - eine Website ohne Bearbeitungsbereich, eine fremde Seite,
     * ein Kunde, der sie abgeschaltet hat -, geht es über FTP. Das
     * dauert, funktioniert aber überall, wo Zugangsdaten hinterlegt sind.
     *
     * Gibt es beides nicht, ist das kein Fehler: Dann liegt die fertige
     * Website als Paket bereit und wird von Hand hochgeladen, wie
     * bisher. Nur sagen muss man es.
     */
    private function hinausschieben(array $projekt, bool $ueberschreiben): string
    {
        if (trim((string) ($projekt['domain'] ?? '')) === '') {
            return 'Es ist keine Domain hinterlegt, die Seite bleibt hier.';
        }

        if (trim((string) ($projekt['bridge_secret'] ?? '')) !== '') {
            $dist = STORAGE_DIR . '/projects/' . (string) $projekt['slug'] . '/dist';

            $antwort = \WebAtze\Domain\Bridge::publish(
                $projekt,
                (new SiteBuilder($projekt, $dist))->site(),
                $ueberschreiben
            );

            if ($antwort['ok']) {
                return 'Auf ' . (string) $projekt['domain'] . ' ist es live.';
            }

            // Ein Fehlschlag der Brücke ist eine Nachricht und kein
            // stiller Rückfall auf einen anderen Weg.
            return 'Die Brücke meldet: ' . $antwort['error'];
        }

        // Ohne Brücke bleibt der Weg von Hand - und das ist seit dem
        // Umbau der Normalfall, nicht der Notfall. Von hier aus kommt
        // nichts mehr auf den Kundenserver: Drei Wege dorthin waren
        // durchgemessen und alle drei tot. Also sagt die Meldung, was
        // als Nächstes zu tun ist, statt einen Upload zu versprechen,
        // den es nicht gibt.
        return 'Gespeichert. Zum Veröffentlichen die Website herunterladen '
            . 'und mit deinem FTP-Programm hinaufladen.';
    }

    // ------------------------------------------------------------------
    // Werkzeug
    // ------------------------------------------------------------------

    /**
     * Der gemeinsame Rahmen jeder ändernden Adresse.
     *
     * Projekt suchen, Aktion ausführen, Ergebnis als JSON. Ohne diesen
     * Rahmen stünde in vierzehn Methoden dieselbe Prüfung, und in der
     * fünfzehnten stünde sie irgendwann nicht mehr.
     */
    private function handeln(Request $request, callable $was): Response
    {
        $projekt = $this->projektAus($request);

        if ($projekt === null) {
            return Response::json(['ok' => false, 'error' => 'Diese Website gibt es nicht.'], 404)
                ->noCache();
        }

        try {
            $ergebnis = $was($projekt);
        } catch (\Throwable $e) {
            $id = Logger::exception($e);

            return Response::json([
                'ok' => false,
                'error' => 'Da ist etwas schiefgelaufen. Kennnummer: ' . $id,
            ], 500)->noCache();
        }

        // Jede ändernde Antwort sagt, ob es jetzt einen Entwurf gibt.
        // Sonst müsste der Editor danach fragen, und die Frage würde
        // irgendwann vergessen.
        if (($ergebnis['ok'] ?? false) && !array_key_exists('entwurf', $ergebnis)) {
            $seiteId = $request->int('seite');

            if ($seiteId > 0) {
                $ergebnis['entwurf'] = \WebAtze\Domain\Publications::hasDraft($seiteId);
            }
        }

        return Response::json($ergebnis, ($ergebnis['ok'] ?? false) ? 200 : 422)->noCache();
    }

    /**
     * Einen einzelnen Abschnitt als fertiges HTML.
     *
     * Das ist der Kern des "kein Neuladen": Der Editor bekommt genau das
     * eine Element zurück und tauscht es aus. Bildlauf, Auswahl und
     * Verlauf bleiben, wo sie sind.
     */
    private function abschnittHtml(int $id, array $projekt): string
    {
        $abschnitt = Sections::find($id);

        if ($abschnitt === null) {
            return '';
        }

        $daten = $this->websiteDaten($projekt);
        $seite = Db::first('SELECT * FROM project_pages WHERE id = :id', ['id' => (int) $abschnitt['page_id']]);

        $kontext = Page::context($daten, $seite ?? [], (string) ($daten['locale'] ?? 'de'));

        return Renderer::section($abschnitt, $kontext);
    }

    /** Die Angaben zur Website, so wie der Seitenbau sie erwartet. */
    private function websiteDaten(array $projekt): array
    {
        $dist = STORAGE_DIR . '/projects/' . (string) $projekt['slug'] . '/dist';

        return (new SiteBuilder($projekt, $dist))->site();
    }

    /** Website und Vorschau neu schreiben. */
    private function bauen(array $projekt): void
    {
        try {
            $dist = STORAGE_DIR . '/projects/' . (string) $projekt['slug'] . '/dist';
            (new SiteBuilder($projekt, $dist))->build();

            $token = (string) ($projekt['preview_token'] ?? '');

            if ($token !== '') {
                $vorschau = STORAGE_DIR . '/previews/'
                    . preg_replace('/[^A-Za-z0-9_-]/', '', $token);
                delete_tree($vorschau);
                Pipeline::copyTree($dist, $vorschau);
            }

            Db::update(
                'projects',
                ['updated_at' => Db::now()],
                'id = :id',
                ['id' => (int) $projekt['id']]
            );
        } catch (\Throwable $e) {
            Logger::exception($e);
        }
    }

    /** Einen Wert an einer Stelle wie "items.2.title" setzen. */
    private function setzen(array $inhalt, string $pfad, mixed $wert): array
    {
        $teile = array_filter(explode('.', $pfad), static fn (string $t): bool => $t !== '');

        if ($teile === []) {
            return $inhalt;
        }

        $zeiger = &$inhalt;

        foreach ($teile as $platz => $teil) {
            $letzter = $platz === count($teile) - 1;

            if ($letzter) {
                $zeiger[$teil] = $wert;
                break;
            }

            if (!isset($zeiger[$teil]) || !is_array($zeiger[$teil])) {
                $zeiger[$teil] = [];
            }

            $zeiger = &$zeiger[$teil];
        }

        return $inhalt;
    }

    /**
     * Die Website, um die es geht.
     *
     * Ihre Kennung steht in der Adresse. request->int() liest nur Rumpf
     * und Abfrage – ein Adressteil landet dort nie, und genau das hat
     * jede Anfrage in ein 404 laufen lassen.
     */
    private function projektAus(Request $request): ?array
    {
        return $this->projekt($request->paramInt('id'));
    }

    /** Die Seite: aus der Adresse, sonst aus dem Rumpf. */
    private function seiteAus(Request $request, ?array $projekt): ?array
    {
        $id = $request->paramInt('seite') ?: $request->int('seite');

        return $this->seite($id, $projekt);
    }

    private function projekt(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $zeile = Db::first('SELECT * FROM projects WHERE id = :id', ['id' => $id]);

        if ($zeile === null) {
            return null;
        }

        foreach (['brief', 'theme', 'chrome'] as $feld) {
            $wert = $zeile[$feld] ?? null;

            if (is_string($wert) && $wert !== '') {
                $zeile[$feld] = json_decode($wert, true) ?: [];
            }
        }

        return $zeile;
    }

    private function seite(int $id, ?array $projekt): ?array
    {
        if ($id <= 0 || $projekt === null) {
            return null;
        }

        return Db::first(
            'SELECT * FROM project_pages WHERE id = :id AND project_id = :p',
            ['id' => $id, 'p' => (int) $projekt['id']]
        );
    }

    private function sprache(Request $request, array $projekt): string
    {
        $gewuenscht = (string) $request->query('sprache');
        $erlaubt = array_column($this->sprachen($projekt), 'code');

        return in_array($gewuenscht, $erlaubt, true) ? $gewuenscht : (string) $erlaubt[0];
    }

    private static function base(): string
    {
        return '/' . trim((string) Config::get('create_path', 'create'), '/');
    }
}
