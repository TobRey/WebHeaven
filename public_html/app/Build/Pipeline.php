<?php

declare(strict_types=1);

namespace WebAtze\Build;

use RuntimeException;
use WebAtze\Ai\{ClaudeClient, ContentWriter, JsonSchema, Prompts, SitePlanner, Translator};
use WebAtze\Core\{Audit, Config, ConfigurationError, Db, Jobs, Logger, Worker};
use WebAtze\Domain\Brief;

/**
 * Der Ablauf vom Formular bis zur fertigen Website.
 *
 * Die Arbeit ist in Schritte zerlegt. Jeder Aufruf von step() erledigt
 * so viel, wie in das übergebene Zeitfenster passt, merkt sich den Stand
 * und kehrt zurück. Der nächste Durchlauf macht weiter.
 *
 * Das ist keine Zier, sondern Notwendigkeit: Auf gemietetem Hosting
 * bricht PHP eine Anfrage nach 30 bis 120 Sekunden ab. Eine Website zu
 * erzeugen dauert länger.
 *
 *   analyse -> plan -> theme -> inhalte -> bilder -> bauen
 *           -> vorschau -> zip -> [hochladen] -> [referenz] -> fertig
 */
final class Pipeline
{
    /** Reihenfolge der Schritte und ihr Anteil am Fortschritt. */
    private const STEPS = [
        'analyse' => ['label' => 'Bestehende Website wird gelesen', 'progress' => 10],
        'plan' => ['label' => 'Aufbau wird geplant', 'progress' => 22],
        'theme' => ['label' => 'Farben und Schriften werden festgelegt', 'progress' => 28],
        'inhalte' => ['label' => 'Texte werden geschrieben', 'progress' => 62],
        'bilder' => ['label' => 'Bilder werden eingesetzt', 'progress' => 70],
        'sprachen' => ['label' => 'Weitere Sprachen werden übersetzt', 'progress' => 76],
        'bauen' => ['label' => 'Website wird zusammengesetzt', 'progress' => 82],
        'pruefen' => ['label' => 'Die fertige Website wird geprüft', 'progress' => 88],
        'vorschau' => ['label' => 'Vorschau wird bereitgestellt', 'progress' => 91],
        'zip' => ['label' => 'Paket wird geschnürt', 'progress' => 96],
        'referenz' => ['label' => 'Referenz wird angelegt', 'progress' => 98],
        'fertig' => ['label' => 'Fertig', 'progress' => 100],
    ];

    /**
     * Einen Schritt des Auftrags ausführen.
     *
     * @param float $budget wie viele Sekunden noch zur Verfügung stehen
     */
    public static function step(array $job, float $budget): void
    {
        $type = (string) $job['type'];

        match ($type) {
            'generate', 'rebuild' => self::generate($job, $budget),
            'zip' => self::zipOnly($job),
            'deploy' => self::deploy($job, $budget),
            'live' => self::pullLive($job, $budget),
            'zip-hochladen' => self::deployZip($job, $budget),
            'zip-per-bruecke' => self::deployBruecke($job, $budget),
            default => throw new RuntimeException('Unbekannte Auftragsart: ' . $type),
        };
    }

    // ==================================================================
    // Website erzeugen
    // ==================================================================

    private static function generate(array $job, float $budget): void
    {
        $started = microtime(true);

        $projectId = (int) ($job['project_id'] ?? 0);
        $project = Db::first('SELECT * FROM projects WHERE id = :id', ['id' => $projectId]);

        if ($project === null) {
            Jobs::fail($job['id'], 'Das Projekt wurde inzwischen gelöscht.', false);
            return;
        }

        $state = $job['state'];
        $step = $state['step'] ?? 'analyse';
        $brief = json_decode((string) $project['brief'], true) ?: [];

        // Der Waechter.
        //
        // Vier Mal habe ich eine Ursache repariert, und vier Mal blieb der
        // Bau an einer anderen Stelle stehen. Deshalb hier etwas, das
        // nicht wissen muss, woran es liegt: Kommt der Bau seit acht
        // Minuten nicht von derselben Stelle weg, wird diese Stelle
        // uebersprungen - mit dem Ersatz, der ohne KI auskommt.
        //
        // Acht Minuten sind bewusst grosszuegig: Ein einzelner Aufruf
        // dauert bis zu einer halben Minute, und zwischen zwei
        // Cron-Laeufen liegt eine. Wer hier zu knapp misst, wirft
        // gesunde Arbeit weg.
        $marke = self::stellenMarke($step, $state);
        $jetzt = time();

        if ((string) ($state['stelle'] ?? '') !== $marke) {
            $state['stelle'] = $marke;
            $state['stelle_seit'] = $jetzt;
        } elseif ($jetzt - (int) ($state['stelle_seit'] ?? $jetzt) > self::WAECHTER_SEKUNDEN) {
            Logger::warning('Der Bau kommt nicht von der Stelle - Ersatz wird erzwungen.', [
                'auftrag' => (int) $job['id'],
                'stelle' => $marke,
                'seit' => $jetzt - (int) ($state['stelle_seit'] ?? $jetzt),
            ]);

            $state['notausgang'] = true;
            $state['stelle_seit'] = $jetzt;
        }

        // Sofort sichern. Wirft der Schritt gleich eine Ausnahme, wird
        // der Zustand sonst nicht geschrieben - und der Waechter faengt
        // bei jedem Anlauf wieder von vorn an zu zaehlen. Genau deshalb
        // greift eine solche Bremse sonst nie.
        Jobs::saveState((int) $job['id'], $state);

        while ($step !== 'fertig') {
            // Vor jedem Schritt prüfen, ob noch genug Zeit bleibt.
            $used = microtime(true) - $started;
            if ($used > $budget - 3.0) {
                $state['step'] = $step;
                Jobs::yield($job['id'], $state);
                return;
            }

            $info = self::STEPS[$step] ?? null;
            if ($info === null) {
                throw new RuntimeException('Unbekannter Schritt: ' . $step);
            }

            // Mitschreiben, was gelaufen ist. Steht danach beim Auftrag –
            // sowohl für die Anzeige als auch für den Probelauf, der
            // sonst nicht sehen kann, welche Schritte wirklich dran waren.
            if (!in_array($step, (array) ($state['schritte'] ?? []), true)) {
                $state['schritte'][] = $step;
            }

            Jobs::progress($job['id'], $step, $info['progress'], $info['label'], $state);

            $remaining = $budget - (microtime(true) - $started);

            $state = match ($step) {
                'analyse' => self::stepAnalyse($project, $brief, $state, $remaining, $job),
                'plan' => self::stepPlan($project, $brief, $state, $job, $remaining),
                'theme' => self::stepTheme($project, $brief, $state),
                'inhalte' => self::stepContent($project, $brief, $state, $remaining, $job),
                'bilder' => self::stepImages($project, $state),
                'sprachen' => self::stepTranslate($project, $state, $remaining, $job),
                'bauen' => self::stepBuild($project, $state),
                'pruefen' => self::stepCheck($project, $state, $remaining),
                'vorschau' => self::stepPreview($project, $state),
                'zip' => self::stepZip($project, $state),
                'referenz' => self::stepShowcase($project, $state),
                default => $state,
            };

            // Ein Schritt darf sich wiederholen – die Texte etwa entstehen
            // Seite für Seite. Er sagt das über 'repeat'. Ohne dieses
            // ausdrückliche Signal geht es immer weiter zum nächsten
            // Schritt; sonst bliebe der Ablauf beim ersten hängen, der
            // seinen Namen im Zustand stehen lässt.
            if (($state['repeat'] ?? false) === true) {
                unset($state['repeat']);
                $repeats = (int) ($state['repeat_count'] ?? 0) + 1;

                // Sicherung gegen einen Schritt, der nie fertig wird.
                if ($repeats > 60) {
                    throw new RuntimeException(
                        'Der Schritt "' . $step . '" kommt nicht zum Ende. Bitte den Auftrag neu starten.'
                    );
                }
                $state['repeat_count'] = $repeats;
            } else {
                $state['repeat_count'] = 0;
                $step = self::nextStep($step);
            }

            $state['step'] = $step;

            // Projekt neu laden – die Schritte ändern es.
            $project = Db::first('SELECT * FROM projects WHERE id = :id', ['id' => $projectId]) ?? $project;
        }

        Db::update('projects', [
            'status' => 'ready',
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => $projectId]);

        $previewUrl = self::previewUrl($project);

        // Wurde unterwegs etwas ohne die KI gebaut, gehoert das gesagt -
        // sonst wundert sich jemand ueber vorlaeufige Texte und sucht den
        // Fehler an der falschen Stelle.
        $hinweise = array_values(array_unique((array) ($state['hinweise'] ?? [])));

        $meldung = 'Die Website ist fertig.';

        if ($hinweise !== []) {
            $meldung .= ' Hinweis: ' . implode(' ', array_slice($hinweise, 0, 3));

            if (count($hinweise) > 3) {
                $meldung .= ' (und ' . (count($hinweise) - 3) . ' weitere)';
            }
        }

        Jobs::progress($job['id'], 'fertig', 100, 'Fertig.', $state);
        Jobs::finish($job['id'], $meldung);

        // Wohin es nach dem Bauen geht: in den Editor.
        //
        // Bisher landete man in der Vorschau - einer Seite, auf der man
        // die Website ansehen konnte und sonst nichts. Das ist die
        // falsche erste Begegnung mit etwas, das gerade fuer einen
        // gebaut wurde: Was man dann tun will, ist etwas aendern, und
        // dafuer musste man erst wieder zurueck.
        //
        // Die Vorschau bleibt, sie steht einen Knopf weiter. Aber der
        // Weg, den niemand waehlt, ist jetzt der, den man nicht braucht.
        $editorUrl = '/' . trim((string) Config::get('create_path', 'create'), '/')
            . '/editor/' . $projectId;

        $ziel = \WebAtze\Core\Assets::exists('editor.js') ? $editorUrl : $previewUrl;

        Db::update('jobs', ['state' => array_merge($state, ['redirect' => $ziel])],
            'id = :id', ['id' => (int) $job['id']]);

        Audit::log('project.built', (string) $project['name'], ['id' => $projectId]);
    }

    private static function nextStep(string $current): string
    {
        $keys = array_keys(self::STEPS);
        $index = array_search($current, $keys, true);

        return $index === false || $index + 1 >= count($keys)
            ? 'fertig'
            : $keys[$index + 1];
    }

    // ------------------------------------------------------------------
    // Schritt 1: bestehende Website lesen
    // ------------------------------------------------------------------

    private static function stepAnalyse(array $project, array $brief, array $state, float $budget, array $job): array
    {
        $url = trim((string) ($brief['old_url'] ?? ''));

        if ($url === '') {
            $state['old_site'] = null;
            return $state;
        }

        $importer = new OldSiteImporter($url);
        $crawl = $importer->crawl(max(8.0, min($budget - 5.0, 40.0)));

        $state['old_site'] = [
            'summary' => OldSiteImporter::summarise($crawl),
            'colors' => $crawl['colors'],
            'pages' => count($crawl['pages']),
            'errors' => array_slice($crawl['errors'], 0, 5),
        ];

        // Bilder herunterladen, wenn gewünscht
        if (!empty($brief['take_images']) && $crawl['images'] !== []) {
            $saved = OldSiteImporter::fetchImages(
                $crawl['images'],
                (int) $project['id'],
                (string) $project['slug'],
                24
            );
            $state['images_imported'] = $saved;
            Logger::info('Bilder übernommen', ['projekt' => (int) $project['id'], 'anzahl' => $saved]);
        }

        if ($crawl['pages'] === []) {
            // Kein Grund zum Abbruch – die Website entsteht dann eben
            // allein aus den Angaben im Formular.
            Logger::warning('Alte Website nicht lesbar', ['url' => $url, 'fehler' => $crawl['errors']]);
            $state['old_site']['summary'] = '';
        }

        return $state;
    }

    // ------------------------------------------------------------------
    // Schritt 2: Aufbau planen
    // ------------------------------------------------------------------

    private static function stepPlan(array $project, array $brief, array $state, array $job, float $budget): array
    {
        $planner = new SitePlanner((int) $project['id'], (int) $job['id']);
        $summary = (string) ($state['old_site']['summary'] ?? '');

        // Zuerst der Versuch, alles in einer Anfrage zu planen. Bei elf
        // Seiten spart das elf Aufrufe - und elf Stellen, an denen etwas
        // schiefgehen kann. Klappt es nicht, geht es Seite fuer Seite
        // weiter wie bisher.
        if (!isset($state['plan_pages']) && empty($state['plan_einzeln'])) {
            $alles = $planner->planAllAtOnce($brief, $summary);

            if ($alles !== null) {
                $plan = SitePlanner::sanitise($alles, $brief);

                Db::update('projects', [
                    'plan' => $plan,
                    'updated_at' => Db::now(),
                ], 'id = :id', ['id' => (int) $project['id']]);

                $state['plan'] = $plan;
                $state['page_index'] = 0;
                $state['group_index'] = 0;

                return $state;
            }

            // Nicht noch einmal probieren - der Weg Seite fuer Seite
            // uebernimmt.
            $state['plan_einzeln'] = true;
        }

        // Erst die Seitenliste - ein kleiner Aufruf.
        if (!isset($state['plan_pages'])) {
            $state['plan_pages'] = self::withFallback(
                $job,
                $state,
                'seitenliste',
                static fn (): array => $planner->planPages($brief, $summary),
                static fn (): array => SitePlanner::fallbackPlan($brief),
                'Der Aufbau wird ohne Vorschlag der KI erstellt.'
            );

            $state['plan_index'] = 0;
            $state['repeat'] = true;

            return $state;
        }

        $liste = (array) $state['plan_pages'];
        $seiten = array_values((array) ($liste['pages'] ?? []));
        $index = (int) ($state['plan_index'] ?? 0);
        $anzahl = max(1, count($seiten));

        $started = microtime(true);
        $getanNow = 0;

        // Dann je Seite die Abschnitte, einzeln und fortsetzbar.
        while ($index < count($seiten)) {
            if ($getanNow > 0 && microtime(true) - $started > $budget - 30.0) {
                break;
            }

            // Der Planungsschritt hat jetzt eine eigene Spanne. Vorher
            // stand er fest auf 22 Prozent, egal wie viele Seiten schon
            // geplant waren - er sah stehengeblieben aus, obwohl er
            // arbeitete. Eine Anzeige, die sich nicht ruehrt, ist von
            // einem Stillstand nicht zu unterscheiden.
            // Die Position zuerst in den Zustand, dann erst arbeiten.
            // Andersherum sichert ein Fehlschlag unterwegs die alte
            // Position mit - der Bau faellt zurueck und faengt dieselbe
            // Seite wieder an. Genau das liess ihn im Kreis laufen.
            $state['plan_index'] = $index;

            Jobs::progress(
                (int) $job['id'],
                'plan',
                12 + (int) round(14 * ($index / $anzahl)),
                sprintf('Aufbau von "%s" (%d von %d)',
                    (string) ($seiten[$index]['title'] ?? ''), $index + 1, $anzahl),
                $state
            );

            Jobs::keepAlive((int) $job['id']);
            Worker::beat();

            $seite = $seiten[$index];

            $seiten[$index]['sections'] = self::withFallback(
                $job,
                $state,
                'abschnitte-' . $index,
                static fn (): array => $planner->planSections($brief, $seite),
                static fn (): array => SitePlanner::fallbackSections($seite),
                sprintf('"%s" wird nach dem Standardaufbau erstellt.',
                    (string) ($seite['title'] ?? ''))
            );

            $liste['pages'] = $seiten;
            $state['plan_pages'] = $liste;

            $index++;
            $getanNow++;
        }

        $state['plan_index'] = $index;

        if ($index < count($seiten)) {
            $state['repeat'] = true;
            return $state;
        }

        $plan = SitePlanner::sanitise($liste, $brief);

        Db::update('projects', [
            'plan' => $plan,
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => (int) $project['id']]);

        $state['plan'] = $plan;
        $state['page_index'] = 0;
        $state['group_index'] = 0;

        unset($state['plan_pages'], $state['plan_index'], $state['plan_einzeln'], $state['fehlversuche']);

        return $state;
    }

    /**
     * Wo genau steht der Bau?
     *
     * Nicht der Schritt allein - der heisst zehn Minuten lang "plan".
     * Sondern Schritt und Position darin. Aendert sich diese Zeichenkette
     * nicht mehr, kommt der Bau nicht vom Fleck, ganz gleich woran es
     * liegt.
     */
    /**
     * So lange darf der Bau an derselben Stelle stehen.
     *
     * Grosszuegig gewaehlt: Ein einzelner Aufruf dauert bis zu einer
     * halben Minute, zwischen zwei Cron-Laeufen liegt eine weitere. Wer
     * hier knapp misst, wirft gesunde Arbeit weg.
     */
    private const WAECHTER_SEKUNDEN = 480;

    private static function stellenMarke(string $step, array $state): string
    {
        return implode(':', [
            $step,
            (string) ($state['plan_index'] ?? '-'),
            (string) ($state['page_index'] ?? '-'),
            (string) ($state['group_index'] ?? '-'),
            (string) count((array) ($state['translated'] ?? [])),
        ]);
    }

    /**
     * Etwas versuchen - und nach zwei Fehlschlaegen ohne KI weitermachen.
     *
     * Das ist die Antwort auf die eigentliche Frage: Was passiert, wenn
     * ein Aufruf einfach nicht durchkommt? Bisher: derselbe Versuch,
     * wieder und wieder, bis jemand eingreift. Das ist der Stillstand,
     * den niemand erklaeren konnte - kein Fehler, nur ewiges
     * Wiederholen, und jede Runde kostet.
     *
     * Ab jetzt wird zweimal versucht, und dann nimmt der Bau den Weg,
     * der ohne KI funktioniert. Eine Website, die nach dem
     * Standardaufbau entsteht, ist unendlich viel besser als eine, die
     * nie entsteht. Was ersetzt wurde, steht in der Meldung - und laesst
     * sich in der Vorschau jederzeit von Hand nachbessern.
     *
     * Ein Fehler, der sich durch Warten nicht behebt - fehlendes
     * Guthaben, falscher Schluessel - wird durchgereicht. Dort ist ein
     * Ersatzaufbau die falsche Antwort; da muss jemand etwas tun.
     *
     * @param callable():array $versuch
     * @param callable():array $ersatz
     */
    private static function withFallback(
        array $job,
        array &$state,
        string $marke,
        callable $versuch,
        callable $ersatz,
        string $hinweis
    ): array {
        $offen = (int) ($state['fehlversuche'][$marke] ?? 0);

        // Der Waechter hat entschieden, dass es hier nicht weitergeht.
        if (!empty($state['notausgang'])) {
            unset($state['notausgang']);
            $offen = 99;
        }

        if ($offen >= 2) {
            Logger::warning('Ersatzaufbau verwendet, die KI kam nicht durch.', [
                'auftrag' => (int) $job['id'],
                'stelle' => $marke,
            ]);

            $state['hinweise'][] = $hinweis;
            unset($state['fehlversuche'][$marke]);

            Jobs::saveState((int) $job['id'], $state);

            return $ersatz();
        }

        try {
            $ergebnis = $versuch();

            // Eine leere Antwort ist kein Erfolg. Sie kaeme spaeter als
            // Seite ohne Abschnitte heraus - also gar nicht.
            if ($ergebnis === [] && $marke !== '' && !str_starts_with($marke, 'texte-')) {
                throw new RuntimeException('Die Antwort war leer.');
            }
        } catch (ConfigurationError $e) {
            // Guthaben leer, Schluessel falsch: Warten hilft nicht, und
            // ein Ersatzaufbau waere hier nur Vertuschen.
            throw $e;
        } catch (\Throwable $e) {
            $state['fehlversuche'][$marke] = $offen + 1;

            // Vor dem Weiterreichen sichern - sonst weiss der naechste
            // Anlauf nicht, dass es hier schon zweimal schieflief, und
            // wiederholt denselben Fehler ewig.
            Jobs::saveState((int) $job['id'], $state);

            Logger::warning('Aufruf fehlgeschlagen, wird wiederholt.', [
                'auftrag' => (int) $job['id'],
                'stelle' => $marke,
                'versuch' => $offen + 1,
                'grund' => $e->getMessage(),
            ]);

            throw $e;
        }

        unset($state['fehlversuche'][$marke]);

        return $ergebnis;
    }

    // ------------------------------------------------------------------
    // Schritt 3: Farben und Schriften
    // ------------------------------------------------------------------

    private static function stepTheme(array $project, array $brief, array $state): array
    {
        $mode = (string) ($brief['color_mode'] ?? 'auto');
        $wishes = [];

        if ($mode === 'manual') {
            $wishes = [
                'primary' => (string) ($brief['color_primary'] ?? ''),
                'secondary' => (string) ($brief['color_secondary'] ?? ''),
                'accent' => (string) ($brief['color_accent'] ?? ''),
            ];
        } elseif ($mode === 'from_old') {
            $colors = $state['old_site']['colors'] ?? [];
            $wishes = [
                'primary' => (string) ($colors[0]['hex'] ?? ''),
                'secondary' => (string) ($colors[1]['hex'] ?? ''),
            ];
        }

        $theme = Theme::build($wishes, (string) ($brief['style'] ?? 'clean'));

        Db::update('projects', [
            'theme' => $theme,
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => (int) $project['id']]);

        $state['theme_report'] = $theme['report'];

        return $state;
    }

    // ------------------------------------------------------------------
    // Schritt 4: Texte schreiben, Seite für Seite
    // ------------------------------------------------------------------

    private static function stepContent(array $project, array $brief, array $state, float $budget, array $job): array
    {
        $plan = $state['plan'] ?? json_decode((string) $project['plan'], true) ?: [];
        $pages = $plan['pages'] ?? [];
        $index = (int) ($state['page_index'] ?? 0);

        if ($pages === []) {
            throw new RuntimeException('Der Plan enthält keine Seiten.');
        }

        // Beim ersten Durchlauf die alten Seiten wegraeumen - aber nur
        // dann. Wird mitten in der ersten Seite fortgesetzt (Seite 0,
        // Gruppe 1), steht der Zaehler ebenfalls auf null; wegraeumen
        // wuerde genau das loeschen, was gerade bezahlt wurde.
        if ($index === 0 && (int) ($state['group_index'] ?? 0) === 0) {
            self::clearPages((int) $project['id']);
        }

        $writer = new ContentWriter((int) $project['id'], (int) $job['id']);
        $started = microtime(true);
        $gruppe = (int) ($state['group_index'] ?? 0);
        $getanNow = 0;

        while ($index < count($pages)) {
            $page = $pages[$index];
            $gruppen = $writer->groupsFor($page);
            $anzahl = max(1, count($gruppen));

            // Eine Gruppe dauert 15 bis 35 Sekunden. Wer weniger Zeit
            // hat, hoert auf und macht im naechsten Durchlauf weiter -
            // bei genau dieser Gruppe, nicht am Anfang der Seite.
            //
            // Entscheidend ist "in DIESEM Durchlauf schon etwas
            // geschafft". Bei einem knappen Zeitfenster waere die
            // Bedingung sonst sofort erfuellt, es kaeme nie etwas dazu,
            // und der Schritt liefe endlos im Kreis.
            if ($getanNow > 0 && microtime(true) - $started > $budget - 35.0) {
                break;
            }

            // Anteil der Seiten plus Anteil der Gruppen innerhalb der
            // Seite - sonst steht die Anzeige minutenlang still.
            $anteil = ($index + min($gruppe, $anzahl) / $anzahl) / max(1, count($pages));
            $percent = 28 + (int) round(34 * $anteil);

            $wo = sprintf('Texte fuer "%s" (%d von %d)', (string) ($page['title'] ?? ''), $index + 1, count($pages));

            if ($anzahl > 1) {
                $wo .= sprintf(', Teil %d von %d', min($gruppe + 1, $anzahl), $anzahl);
            }

            // Wie oben: erst die Position festhalten, dann arbeiten.
            $state['page_index'] = $index;
            $state['group_index'] = $gruppe;

            Jobs::progress((int) $job['id'], 'inhalte', $percent, $wo, $state);

            // Waechter: Vor jeder Gruppe festhalten, dass sie begonnen
            // wurde. Kommt dieselbe Gruppe zum vierten Mal dran, ohne je
            // fertig geworden zu sein, bricht der Server den Vorgang
            // immer an derselben Stelle ab. Weiterprobieren kostet dann
            // nur noch Geld - besser einmal deutlich sagen, woran es
            // liegt.
            $marke = $index . ':' . $gruppe;
            $versuche = (int) ($state['gruppe_versuche'][$marke] ?? 0) + 1;
            $state['gruppe_versuche'] = [$marke => $versuche];

            if ($versuche > 3) {
                throw new ConfigurationError(
                    'Der Server bricht das Schreiben immer an derselben Stelle ab '
                    . '(Seite ' . ($index + 1) . ', Teil ' . ($gruppe + 1) . '). '
                    . 'Das passiert, wenn der Cronjob die Adresse per curl oder wget '
                    . 'aufruft: Dann gilt die Zeitgrenze des Webservers, und die ist '
                    . 'kuerzer als eine Anfrage an die KI dauert.',
                    'Stelle den Cronjob in cPanel auf die Kommandozeile um. Die Zeile '
                    . 'lautet: /usr/local/bin/php -q ' . BASE_DIR . '/worker.php '
                    . '- also ohne curl und ohne Adresse. Danach den Auftrag '
                    . 'fortsetzen; das bereits Geschriebene bleibt erhalten.',
                    ''
                );
            }

            // Die Sperre auffrischen. Ein Aufruf dauert laenger als die
            // Sperre; ohne das koennte ein zweiter Arbeiter mitten hinein
            // denselben Auftrag greifen und alles doppelt bezahlen.
            Jobs::keepAlive((int) $job['id']);
            Worker::beat();

            // Die Seitenzeile zuerst - dann haengt jede Gruppe an einer
            // Stelle, die es schon gibt, auch nach einem Abbruch.
            $pageId = $writer->preparePage((int) $project['id'], $page);

            $summary = (string) ($state['old_site']['summary'] ?? '');
            $nummer = $gruppe;

            self::withFallback(
                $job,
                $state,
                'texte-' . $index . '-' . $gruppe,
                static function () use ($writer, $project, $pageId, $page, $brief, $summary, $nummer): array {
                    $writer->writeGroup((int) $project['id'], $pageId, $page, $brief, $summary, $nummer);
                    return [];
                },
                // Der Rueckfall: Platzhaltertexte aus dem Formular statt
                // gar nichts. Sie sind erkennbar vorlaeufig und lassen
                // sich in der Vorschau mit einem Klick neu schreiben -
                // aber die Seite steht, und der Bau laeuft weiter.
                static function () use ($writer, $project, $pageId, $page, $brief, $nummer): array {
                    $writer->writeGroupPlain((int) $project['id'], $pageId, $page, $brief, $nummer);
                    return [];
                },
                sprintf('"%s" hat vorlaeufige Texte - in der Vorschau neu schreiben lassen.',
                    (string) ($page['title'] ?? ''))
            );

            // Geschafft - der Zaehler des Waechters darf zurueck.
            $state['gruppe_versuche'] = [];

            $getanNow++;
            $gruppe++;

            if ($gruppe >= $anzahl) {
                $index++;
                $gruppe = 0;
            }
        }

        $state['group_index'] = $gruppe;
        $state['page_index'] = $index;

        // Noch nicht alle Seiten geschrieben: denselben Schritt wiederholen.
        if ($index < count($pages)) {
            $state['repeat'] = true;
        }

        return $state;
    }

    // ------------------------------------------------------------------
    // Schritt 4b: Die weiteren Sprachen
    // ------------------------------------------------------------------

    /**
     * Seite für Seite und Sprache für Sprache übersetzen.
     *
     * Mit demselben Zeitbudget wie beim Schreiben: Ein Auftrag darf
     * nicht ins Zeitlimit des Hostings laufen, nur weil eine Website
     * drei Sprachen hat.
     */
    private static function stepTranslate(array $project, array $state, float $budget, array $job): array
    {
        $extra = Translator::extraLocales($project);

        if ($extra === []) {
            return $state;
        }

        $pages = Db::all(
            'SELECT id, title FROM project_pages WHERE project_id = :p ORDER BY sort_order ASC, id ASC',
            ['p' => (int) $project['id']]
        );

        $done = (array) ($state['translated'] ?? []);
        $started = microtime(true);
        $translator = new Translator((int) $project['id'], (int) $job['id']);
        $didSomething = false;

        foreach ($pages as $page) {
            foreach ($extra as $locale) {
                $key = $page['id'] . ':' . $locale;

                if (in_array($key, $done, true)) {
                    continue;
                }

                // Mindestens eine Übersetzung je Durchgang, danach nur
                // weiter, solange Zeit bleibt.
                if ($didSomething && microtime(true) - $started > $budget - 25.0) {
                    $state['translated'] = $done;
                    $state['repeat'] = true;
                    return $state;
                }

                // Eine eigene Spanne statt einer festen Zahl. Vorher
                // stand hier minutenlang 76 Prozent, egal wie viele
                // Seiten schon uebersetzt waren - und eine Anzeige, die
                // sich nicht ruehrt, ist von einem Stillstand nicht zu
                // unterscheiden.
                $gesamt = max(1, count($pages) * count($extra));
                $anteil = count($done) / $gesamt;

                Jobs::progress(
                    (int) $job['id'],
                    'sprachen',
                    70 + (int) round(12 * $anteil),
                    sprintf('%s auf %s (%d von %d)',
                        (string) $page['title'],
                        Translator::NAMES[$locale] ?? $locale,
                        count($done) + 1,
                        $gesamt)
                );

                Jobs::keepAlive((int) $job['id']);
                Worker::beat();

                $translator->translatePage((int) $page['id'], $locale);

                $done[] = $key;
                $didSomething = true;
            }
        }

        $state['translated'] = $done;

        return $state;
    }

    // ------------------------------------------------------------------
    // Schritt 5: Bilder auf die Abschnitte verteilen
    // ------------------------------------------------------------------

    private static function stepImages(array $project, array $state): array
    {
        $assigned = ImagePlacer::place((int) $project['id']);
        $state['images_placed'] = $assigned;

        return $state;
    }

    // ------------------------------------------------------------------
    // Schritt 6: Website bauen
    // ------------------------------------------------------------------

    private static function stepBuild(array $project, array $state): array
    {
        $dist = STORAGE_DIR . '/projects/' . (string) $project['slug'] . '/dist';
        delete_tree($dist);

        $builder = new SiteBuilder($project, $dist);
        $result = $builder->build();

        $state['build'] = $result;

        return $state;
    }

    // ------------------------------------------------------------------
    // Schritt 7: die fertige Website prüfen
    // ------------------------------------------------------------------

    /**
     * Geprüft wird das Ergebnis, nicht der Plan.
     *
     * Eine Prüfung darf den Auftrag nie scheitern lassen: Der Kunde
     * bekäme sonst wegen eines Tippfehlers gar keine Website. Was
     * auffällt, steht danach beim Projekt – dort, wo man es liest,
     * bevor man die Seite freigibt.
     */
    private static function stepCheck(array $project, array $state, float $budget): array
    {
        try {
            $state['checks'] = SiteChecker::runAll($project, max(5.0, $budget - 5.0));
        } catch (\Throwable $e) {
            Logger::info('Prüfung übersprungen: ' . $e->getMessage());
            $state['checks'] = [];
        }

        return $state;
    }

    // ------------------------------------------------------------------
    // Schritt 8: Vorschau bereitstellen
    // ------------------------------------------------------------------

    private static function stepPreview(array $project, array $state): array
    {
        $token = (string) ($project['preview_token'] ?? '');
        if ($token === '') {
            $token = random_token(18);
        }

        $target = STORAGE_DIR . '/previews/' . $token;
        delete_tree($target);
        ensure_dir($target);

        self::copyTree(STORAGE_DIR . '/projects/' . (string) $project['slug'] . '/dist', $target);

        $hours = (int) Config::get('preview_ttl_hours', 24);

        Db::update('projects', [
            'preview_token' => $token,
            'preview_expires_at' => date('Y-m-d H:i:s', time() + $hours * 3600),
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => (int) $project['id']]);

        $state['preview_token'] = $token;

        return $state;
    }

    // ------------------------------------------------------------------
    // Schritt 9: ZIP
    // ------------------------------------------------------------------

    private static function stepZip(array $project, array $state): array
    {
        $result = ZipExporter::create($project);
        $state['zip'] = $result;

        return $state;
    }

    /**
     * Die fertige Website in die Referenzen aufnehmen.
     *
     * Der Text entsteht dabei automatisch – und verrät nicht, wie die
     * Website entstanden ist. Der Eintrag bleibt zunächst unsichtbar:
     * Kein Kunde soll auf der Website auftauchen, bevor er die Seite
     * überhaupt gesehen hat. Sichtbar schalten geht mit einem Klick.
     *
     * Scheitert das, ist die Website trotzdem fertig. Eine fehlende
     * Referenz darf keinen Auftrag zum Scheitern bringen.
     */
    private static function stepShowcase(array $project, array $state): array
    {
        try {
            $entry = ShowcaseBuilder::create($project, false);
            $state['referenz'] = ['id' => (int) ($entry['id'] ?? 0)];
        } catch (\Throwable $e) {
            Logger::warning('Die Referenz konnte nicht angelegt werden: ' . $e->getMessage(), [
                'projekt' => (int) $project['id'],
            ]);
            $state['referenz'] = ['fehler' => $e->getMessage()];
        }

        return $state;
    }

    // ==================================================================
    // Andere Auftragsarten
    // ==================================================================

    private static function zipOnly(array $job): void
    {
        $project = Db::first('SELECT * FROM projects WHERE id = :id', ['id' => (int) $job['project_id']]);
        if ($project === null) {
            Jobs::fail($job['id'], 'Projekt nicht gefunden.', false);
            return;
        }

        Jobs::progress($job['id'], 'zip', 50, 'Paket wird geschnürt …');
        $result = ZipExporter::create($project);

        Jobs::progress($job['id'], 'fertig', 100, 'Paket ist fertig.', ['zip' => $result]);
        Jobs::finish($job['id'], sprintf('Paket erstellt (%s).', format_bytes((int) $result['bytes'])));
    }

    /**
     * Den aktuellen Stand vom Kundenserver holen.
     *
     * Als Auftrag und nicht im Web-Request: Eine Website mit
     * dreihundert Dateien ueber FTP dauert laenger als jedes
     * max_execution_time auf geteiltem Hosting - und der Browser haette
     * laengst abgebrochen, waehrend der Download weiterlaeuft.
     */
    private static function pullLive(array $job, float $budget): void
    {
        $project = Db::first('SELECT * FROM projects WHERE id = :id', ['id' => (int) $job['project_id']]);

        if ($project === null) {
            Jobs::fail($job['id'], 'Projekt nicht gefunden.', false);

            return;
        }

        Jobs::progress($job['id'], 'holen', 10, 'Verbindung wird aufgebaut …');

        try {
            $ergebnis = ZipExporter::pullLive(
                $project,
                static function (int $dateien, string $pfad) use ($job): void {
                    // Ohne bekannte Gesamtzahl gibt es keinen Prozentsatz -
                    // hier wird gezaehlt, nicht geschaetzt. Eine erfundene
                    // Prozentzahl, die bei 90 stehen bleibt, ist schlimmer
                    // als eine ehrliche Anzahl.
                    Jobs::progress(
                        $job['id'],
                        'holen',
                        min(90, 10 + (int) ($dateien / 5)),
                        sprintf('%d Dateien geholt … (%s)', $dateien, $pfad)
                    );
                },
                $budget - 10.0
            );
        } catch (\Throwable $e) {
            Jobs::fail($job['id'], $e->getMessage(), false);

            return;
        }

        Jobs::progress($job['id'], 'fertig', 100, 'Stand geholt.', ['live' => $ergebnis]);

        Jobs::finish($job['id'], sprintf(
            '%d Dateien geholt (%s).%s',
            $ergebnis['files'],
            format_bytes((int) $ergebnis['bytes']),
            $ergebnis['abgeschnitten']
                ? ' Achtung: unvollständig, eine Grenze war erreicht.'
                : ''
        ));
    }

    /**
     * Ein hochgeladenes Archiv auf den Kundenserver schieben.
     *
     * Der Weg ohne den eingebauten Generator: Auftragstext kopieren,
     * die Website anderswo bauen lassen, das Ergebnis hier hochladen.
     */
    /**
     * Dasselbe Archiv, nur über HTTPS statt FTP.
     *
     * Für den Fall, dass die FTP-Datenverbindung nicht durchkommt -
     * gemessen, nicht vermutet: Der Ausgangstest sagt, ob es daran
     * liegt. Vorbedingung ist, dass die Empfangsdatei einmal von Hand
     * auf der Kundenwebsite liegt.
     */
    private static function deployBruecke(array $job, float $budget): void
    {
        $project = Db::first('SELECT * FROM projects WHERE id = :id', ['id' => (int) $job['project_id']]);

        if ($project === null) {
            Jobs::fail($job['id'], 'Projekt nicht gefunden.', false);

            return;
        }

        $zip = (string) ($job['payload']['zip'] ?? '');
        $erlaubt = STORAGE_DIR . '/uploads/';

        if ($zip === '' || !str_starts_with($zip, $erlaubt) || !is_file($zip)) {
            Jobs::fail($job['id'], 'Das hochgeladene Archiv ist nicht mehr da.', false);

            return;
        }

        Jobs::progress($job['id'], 'hochladen', 10, 'Die Website wird angerufen …');

        $ergebnis = \WebAtze\Build\Empfang::senden(
            $project,
            $zip,
            static function (int $fertig, int $gesamt, string $datei) use ($job): void {
                Jobs::progress(
                    $job['id'],
                    'hochladen',
                    10 + (int) round(85 * ($gesamt > 0 ? $fertig / $gesamt : 0)),
                    sprintf('%d von %d Dateien (%s)', $fertig, $gesamt, $datei)
                );
            },
            $budget - 5.0
        );

        @unlink($zip);

        if (!$ergebnis['ok']) {
            Jobs::fail($job['id'], $ergebnis['error'], $ergebnis['retryable'] ?? true);

            return;
        }

        Jobs::progress($job['id'], 'fertig', 100, 'Hochgeladen.');
        Jobs::finish($job['id'], sprintf(
            '%d Dateien über HTTPS hochgeladen%s.',
            $ergebnis['files'],
            ($ergebnis['aufgeraeumt'] ?? false) ? ' - die Empfangsdatei ist wieder weg' : ''
        ));

        Db::update('projects', [
            'status' => 'live',
            'published_at' => Db::now(),
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => (int) $project['id']]);

        Audit::log('project.deployed.bruecke', (string) $project['name'], [
            'dateien' => $ergebnis['files'],
        ]);
    }

    private static function deployZip(array $job, float $budget): void
    {
        $project = Db::first('SELECT * FROM projects WHERE id = :id', ['id' => (int) $job['project_id']]);

        if ($project === null) {
            Jobs::fail($job['id'], 'Projekt nicht gefunden.', false);

            return;
        }

        $zip = (string) ($job['payload']['zip'] ?? '');

        // Nur aus dem eigenen Ablageordner - der Pfad kommt aus dem
        // Auftrag, und ein Auftrag ist eine Datenbankzeile.
        $erlaubt = STORAGE_DIR . '/uploads/';

        if ($zip === '' || !str_starts_with($zip, $erlaubt) || !is_file($zip)) {
            Jobs::fail($job['id'], 'Das hochgeladene Archiv ist nicht mehr da.', false);

            return;
        }

        Jobs::progress($job['id'], 'hochladen', 10, 'Verbindung wird aufgebaut …');

        $ergebnis = FtpDeployer::deployZip(
            $project,
            $zip,
            static function (int $fertig, int $gesamt, string $datei) use ($job): void {
                Jobs::progress(
                    $job['id'],
                    'hochladen',
                    10 + (int) round(85 * ($gesamt > 0 ? $fertig / $gesamt : 0)),
                    sprintf('%d von %d Dateien (%s)', $fertig, $gesamt, $datei)
                );
            },
            $budget - 5.0
        );

        // Das Archiv hat seinen Zweck erfüllt. Es liegen zu lassen
        // hiesse, fremde Dateien ohne Grund aufzubewahren.
        @unlink($zip);

        if (!$ergebnis['ok']) {
            Jobs::fail($job['id'], $ergebnis['error'], $ergebnis['retryable'] ?? true);

            return;
        }

        Jobs::progress($job['id'], 'fertig', 100, 'Hochgeladen.', ['deploy' => $ergebnis]);
        Jobs::finish($job['id'], sprintf('%d Dateien aus dem Archiv hochgeladen.', $ergebnis['files']));

        Db::update('projects', [
            'status' => 'live',
            'published_at' => Db::now(),
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => (int) $project['id']]);

        Audit::log('project.deployed.zip', (string) $project['name'], [
            'dateien' => $ergebnis['files'],
        ]);
    }

    private static function deploy(array $job, float $budget): void
    {
        $project = Db::first('SELECT * FROM projects WHERE id = :id', ['id' => (int) $job['project_id']]);
        if ($project === null) {
            Jobs::fail($job['id'], 'Projekt nicht gefunden.', false);
            return;
        }

        Jobs::progress($job['id'], 'hochladen', 20, 'Verbindung wird aufgebaut …');

        $result = FtpDeployer::deploy($project, static function (int $done, int $total, string $file) use ($job): void {
            $percent = 20 + (int) round(70 * ($total > 0 ? $done / $total : 0));
            Jobs::progress($job['id'], 'hochladen', $percent, sprintf('%d von %d Dateien', $done, $total));
        }, $budget - 5.0);

        if (!$result['ok']) {
            Jobs::fail($job['id'], $result['error'], $result['retryable'] ?? true);
            return;
        }

        Jobs::progress($job['id'], 'fertig', 100, 'Hochgeladen.', ['deploy' => $result]);
        Jobs::finish($job['id'], sprintf('%d Dateien hochgeladen.', $result['files']));

        Db::update('projects', [
            'status' => 'live',
            'published_at' => Db::now(),
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => (int) $project['id']]);

        Audit::log('project.deployed', (string) $project['name'], ['dateien' => $result['files']]);

        // Wer eine Website veröffentlicht, will sie überwacht haben,
        // ohne daran denken zu müssen. Steht sie schon in der Aufsicht,
        // passiert hier nichts.
        $adresse = trim((string) ($project['domain'] ?? ''));

        if ($adresse !== '') {
            try {
                \WebAtze\Domain\Monitor::adopt(
                    $adresse,
                    (string) $project['name'],
                    null,
                    (int) $project['id']
                );
            } catch (\Throwable $e) {
                // Die Aufsicht ist Beiwerk. Ein Hochladen, das
                // deswegen als gescheitert gilt, wäre schlimmer als
                // eine Seite ohne Aufsicht.
                Logger::exception($e);
            }
        }
    }

    // ==================================================================
    // Helfer
    // ==================================================================

    /** Alte Seiten und Abschnitte entfernen, bevor neu geschrieben wird. */
    private static function clearPages(int $projectId): void
    {
        Db::transaction(static function () use ($projectId): void {
            Db::delete('project_sections', 'project_id = :p', ['p' => $projectId]);
            Db::delete('project_pages', 'project_id = :p', ['p' => $projectId]);
        });
    }

    public static function previewUrl(array $project): string
    {
        $token = (string) ($project['preview_token'] ?? '');
        return $token === '' ? '' : '/vorschau/' . $token . '/';
    }

    /** Verzeichnis samt Inhalt kopieren. */
    public static function copyTree(string $from, string $to): int
    {
        if (!is_dir($from)) {
            return 0;
        }

        ensure_dir($to);
        $count = 0;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($from) + 1);
            $target = $to . '/' . $relative;

            if ($item->isDir()) {
                ensure_dir($target);
            } elseif ($item->isFile()) {
                ensure_dir(dirname($target));
                if (@copy($item->getPathname(), $target)) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
