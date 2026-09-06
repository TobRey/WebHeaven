<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use WebAtze\Core\{Db, Logger};

/**
 * Alle Websites – die selbst gebauten und die hinzugefügten.
 *
 * Warum dieselbe Tabelle wie für die eigenen Projekte und nicht eine
 * zweite: Wer alle seine Websites sehen will, will eine Liste und nicht
 * zwei, die er im Kopf zusammenfügt. Eine Website, die WebAtze nicht
 * gebaut hat, wird trotzdem betreut, überwacht und in Rechnung
 * gestellt – sie unterscheidet sich nur in ihrer Herkunft.
 *
 * Die steht in 'source':
 *
 *   ki    von WebAtze aufgebaut, mit Auftrag, Abschnitten und Paketen
 *   hand  von Hand eingetragen, weil sie schon da war
 *
 * Alles, was den Aufbau betrifft, gibt es nur bei 'ki'. Alles andere –
 * Kunde, Domain, Überwachung, Notizen – gilt für beide.
 */
final class Websites
{
    /** Woher eine Website kommt. */
    public const SOURCES = [
        'ki' => 'Von WebAtze gebaut',
        'hand' => 'Hinzugefügt',
    ];

    /**
     * In welchem Zustand sie ist.
     *
     * Kurz, weil es als Marke in einer Tabellenzelle steht: „Fertig,
     * noch nicht online" war als eine Zeile zweihundert Pixel breit
     * und damit der Grund, aus dem die Liste über den Rand lief. Der
     * volle Satz steht in STATUS_LANG und erscheint als Titel, wenn
     * man darauf zeigt.
     */
    public const STATUS = [
        'draft' => 'Entwurf',
        'building' => 'Wird gebaut',
        'ready' => 'Fertig',
        'live' => 'Online',
        'paused' => 'Ruht',
        'failed' => 'Fehlgeschlagen',
    ];

    /** Derselbe Zustand, ausgeschrieben. */
    public const STATUS_LANG = [
        'draft' => 'Entwurf – noch nicht gebaut',
        'building' => 'Wird gerade gebaut',
        'ready' => 'Fertig gebaut, aber noch nicht veröffentlicht',
        'live' => 'Veröffentlicht und erreichbar',
        'paused' => 'Ruht – vorübergehend nicht betreut',
        'failed' => 'Der Bau ist fehlgeschlagen',
    ];

    /** Womit sie gebaut ist – nur ein Vorschlag, eintippen geht auch. */
    public const PLATFORMS = [
        'WebAtze',
        'WordPress',
        'Wix',
        'Jimdo',
        'Squarespace',
        'Shopify',
        'TYPO3',
        'Joomla',
        'Webflow',
        'Von Hand gebaut',
    ];

    // ------------------------------------------------------------------
    // Lesen
    // ------------------------------------------------------------------

    /**
     * Die Liste, wie sie im Adminbereich steht.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(string $suche = '', string $herkunft = '', int $kunde = 0): array
    {
        $sql = 'SELECT p.*, c.name AS kunde,
                       (SELECT COUNT(*) FROM project_pages WHERE project_id = p.id) AS seiten,
                       (SELECT MAX(version) FROM builds WHERE project_id = p.id) AS fassung
                FROM projects p
                LEFT JOIN customers c ON c.id = p.customer_id';

        $wo = [];
        $werte = [];

        $suche = trim($suche);

        if ($suche !== '') {
            $wo[] = '(LOWER(p.name) LIKE :suche OR LOWER(p.domain) LIKE :suche OR LOWER(c.name) LIKE :suche)';
            $werte['suche'] = '%' . mb_strtolower($suche) . '%';
        }

        if (isset(self::SOURCES[$herkunft])) {
            $wo[] = 'p.source = :quelle';
            $werte['quelle'] = $herkunft;
        }

        if ($kunde > 0) {
            $wo[] = 'p.customer_id = :kunde';
            $werte['kunde'] = $kunde;
        } elseif ($kunde < 0) {
            // Minus eins heisst "ohne Zuordnung". Von der Kundenliste
            // aus ist das der einzige Weg zu einer Website, die keinem
            // Kunden gehört - und seit die Website-Liste nicht mehr im
            // Menü steht, der einzige überhaupt.
            $wo[] = '(p.customer_id IS NULL OR p.customer_id = 0)';
        }

        if ($wo !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $wo);
        }

        $sql .= ' ORDER BY p.updated_at DESC, p.id DESC';

        $zeilen = Db::all($sql, $werte);

        // Der Zustand der Überwachung, sofern eine läuft. Er gehört in
        // diese Liste: «läuft die Seite» ist die erste Frage bei einer
        // Website, die man betreut.
        foreach ($zeilen as $nummer => $zeile) {
            $zeilen[$nummer]['waechter'] = self::monitorFor($zeile);
        }

        return $zeilen;
    }

    /** Die Websites eines Kunden. */
    public static function forCustomer(int $kunde): array
    {
        return self::all('', '', $kunde);
    }

    /**
     * Kurze Liste fuer Auswahlfelder: id, Name, Kunde.
     *
     * Bewusst ohne Filter auf den Zustand. Frueher stand hier
     * "status IN ('done','published')" - beides Zustaende, die es bei
     * einer Website gar nicht gibt (sie ist draft, building, ready,
     * live, paused oder failed). Das Feld blieb deshalb immer leer.
     * Ein Vertrag oder eine Rechnung kann zu jeder Website gehoeren,
     * auch zu einer, die gerade erst entsteht.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function pickList(int $limit = 300): array
    {
        return Db::all(
            'SELECT p.id, p.name, p.domain, p.status, p.source, c.name AS kunde
             FROM projects p
             LEFT JOIN customers c ON c.id = p.customer_id
             ORDER BY p.name ASC
             LIMIT ' . max(1, min(1000, $limit))
        );
    }

    /**
     * Wie pickList, aber als Beschriftung: "Name - Kunde (Domain)".
     *
     * @return array<int, string> id => Beschriftung
     */
    public static function pickLabels(int $limit = 300): array
    {
        $liste = [];

        foreach (self::pickList($limit) as $zeile) {
            $text = (string) $zeile['name'];
            $zusatz = [];

            if (trim((string) ($zeile['kunde'] ?? '')) !== '') {
                $zusatz[] = (string) $zeile['kunde'];
            }

            if (trim((string) ($zeile['domain'] ?? '')) !== '') {
                $zusatz[] = (string) $zeile['domain'];
            }

            if ($zusatz !== []) {
                $text .= ' · ' . implode(' · ', $zusatz);
            }

            $liste[(int) $zeile['id']] = $text;
        }

        return $liste;
    }

    public static function find(int $id): ?array
    {
        return Db::first(
            'SELECT p.*, c.name AS kunde FROM projects p
             LEFT JOIN customers c ON c.id = p.customer_id
             WHERE p.id = :id',
            ['id' => $id]
        );
    }

    /** @return array<string, int> */
    public static function counts(): array
    {
        return [
            'gesamt' => (int) Db::value('SELECT COUNT(*) FROM projects', [], 0),
            'live' => (int) Db::value("SELECT COUNT(*) FROM projects WHERE status = 'live'", [], 0),
            'gebaut' => (int) Db::value("SELECT COUNT(*) FROM projects WHERE source = 'ki'", [], 0),
            'hinzugefuegt' => (int) Db::value("SELECT COUNT(*) FROM projects WHERE source = 'hand'", [], 0),
            'ohne_kunde' => (int) Db::value('SELECT COUNT(*) FROM projects WHERE customer_id IS NULL', [], 0),
        ];
    }

    /**
     * Die Überwachung zu einer Website, falls es eine gibt.
     *
     * @param array<string, mixed> $website
     * @return array<string, mixed>|null
     */
    public static function monitorFor(array $website): ?array
    {
        $zeile = Db::first(
            'SELECT * FROM monitors WHERE project_id = :p ORDER BY id ASC LIMIT 1',
            ['p' => (int) $website['id']]
        );

        if ($zeile !== null) {
            return $zeile;
        }

        // Auch ohne Verknüpfung: Wer dieselbe Domain überwacht, meint
        // dieselbe Website.
        $host = self::host((string) ($website['domain'] ?? ''));

        if ($host === '') {
            return null;
        }

        return Db::first(
            'SELECT * FROM monitors WHERE url LIKE :muster ORDER BY id ASC LIMIT 1',
            ['muster' => '%' . $host . '%']
        );
    }

    // ------------------------------------------------------------------
    // Schreiben
    // ------------------------------------------------------------------

    /**
     * Eine Website von Hand eintragen oder ändern.
     *
     * @param array<string, mixed> $daten
     * @return array{ok:bool, meldung:string, id:int}
     */
    public static function save(array $daten, ?int $id = null): array
    {
        $name = mb_substr(trim((string) ($daten['name'] ?? '')), 0, 191);

        if ($name === '') {
            return ['ok' => false, 'meldung' => 'Ohne Namen lässt sich die Website später nicht finden.', 'id' => 0];
        }

        $satz = [
            'name' => $name,
            'domain' => self::domain($daten['domain'] ?? ''),
            'customer_id' => ((int) ($daten['customer_id'] ?? 0)) ?: null,
            'status' => isset(self::STATUS[(string) ($daten['status'] ?? '')])
                ? (string) $daten['status']
                : 'live',
            'platform' => mb_substr(trim((string) ($daten['platform'] ?? '')), 0, 60),
            'hosting' => mb_substr(trim((string) ($daten['hosting'] ?? '')), 0, 120),
            'notes' => mb_substr(trim((string) ($daten['notes'] ?? '')), 0, 4000),
            'live_since' => self::day($daten['live_since'] ?? ''),
            'updated_at' => Db::now(),
        ];

        if ($id !== null) {
            Db::update('projects', $satz, 'id = :id', ['id' => $id]);

            return ['ok' => true, 'meldung' => 'Gespeichert.', 'id' => $id];
        }

        // Der Slug ist eindeutig und wird gebraucht, auch wenn diese
        // Website nie gebaut wird - andere Teile verlassen sich darauf.
        $satz['slug'] = self::uniqueSlug($name);
        $satz['source'] = 'hand';
        $satz['locale'] = 'de';
        $satz['locales'] = 'de';
        $satz['created_at'] = Db::now();

        $neu = Db::insert('projects', $satz);

        Logger::info('Website hinzugefügt.', ['id' => $neu, 'name' => $name]);

        return ['ok' => true, 'meldung' => 'Die Website steht in der Liste.', 'id' => $neu];
    }

    /**
     * Der Zugangscode fuer die Hilfeseite dieser Website.
     *
     * Er wird beim ersten Nachfragen erzeugt und bleibt dann stehen.
     * Bewusst kurz und aussprechbar: Ich sage ihn dem Kunden am Telefon
     * oder schreibe ihn auf die Rechnung. Ein 32-stelliger Schluessel
     * waere sicherer und wuerde nie benutzt.
     *
     * Verwechselbare Zeichen fehlen (0/O, 1/I/l), damit niemand raten
     * muss, ob das eine Null oder ein O ist.
     */
    public static function supportCode(int $id): string
    {
        $zeile = Db::first('SELECT support_code FROM projects WHERE id = :id', ['id' => $id]);

        if ($zeile === null) {
            return '';
        }

        $code = trim((string) ($zeile['support_code'] ?? ''));

        if ($code !== '') {
            return $code;
        }

        $code = self::freshCode();

        Db::update('projects', ['support_code' => $code, 'updated_at' => Db::now()],
            'id = :id', ['id' => $id]);

        return $code;
    }

    /**
     * Der Schluessel, mit dem sich die Hilfeseite einer Kundenwebsite
     * bei WebAtze ausweist.
     *
     * Getrennt vom assistant_token, und das mit Absicht: Dieser hier
     * steht im Auftragstext, damit auf der Kundenwebsite nichts von Hand
     * eingerichtet werden muss - und ein Auftragstext wird kopiert und
     * weitergereicht. Mit dem assistant_token liesse sich auch der
     * Abschnitts-Editor ansteuern, und der kostet bei jedem Aufruf Geld.
     * Dieser kann genau zweierlei: eine Nachricht senden und den eigenen
     * Faden lesen.
     */
    public static function supportToken(int $id): string
    {
        $zeile = Db::first('SELECT support_token FROM projects WHERE id = :id', ['id' => $id]);

        if ($zeile === null) {
            return '';
        }

        $schluessel = trim((string) ($zeile['support_token'] ?? ''));

        if ($schluessel !== '') {
            return $schluessel;
        }

        $schluessel = random_token(24);

        Db::update('projects', ['support_token' => $schluessel], 'id = :id', ['id' => $id]);

        return $schluessel;
    }

    /** Einen neuen Schluessel setzen - falls der alte unterwegs war. */
    public static function newSupportToken(int $id): string
    {
        $schluessel = random_token(24);

        Db::update('projects', ['support_token' => $schluessel, 'updated_at' => Db::now()],
            'id = :id', ['id' => $id]);

        return $schluessel;
    }

    /** Einen neuen Code setzen - falls er einmal in falsche Haende geriet. */
    public static function newSupportCode(int $id): string
    {
        $code = self::freshCode();

        Db::update('projects', ['support_code' => $code, 'updated_at' => Db::now()],
            'id = :id', ['id' => $id]);

        return $code;
    }

    private static function freshCode(): string
    {
        $zeichen = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < 10; $i++) {
            if ($i === 5) {
                $code .= '-';
            }
            $code .= $zeichen[random_int(0, strlen($zeichen) - 1)];
        }

        return $code;
    }

    /** Eine Website einem Kunden zuordnen – oder die Zuordnung lösen. */
    public static function assign(int $id, int $kunde): bool
    {
        $ok = Db::update('projects', [
            'customer_id' => $kunde > 0 ? $kunde : null,
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => $id]) > 0;

        if ($ok) {
            self::gleichziehen($id, $kunde);
        }

        return $ok;
    }

    /**
     * Gibt es einen gebauten Stand, den man zeigen kann?
     *
     * Das Vorschaubild in den Listen ist die echte Startseite, in einem
     * Rahmen verkleinert. Dafür braucht es die gebauten Dateien. Eine
     * von Hand hinzugefügte Website hat keine – die fremde Seite
     * einzubetten geht nicht, weil Websites `X-Frame-Options` senden
     * (unsere eigenen inbegriffen, das setzen wir selbst so). Dort
     * steht deshalb eine Kachel statt eines Bildes.
     */
    public static function hatVorschau(array $website): bool
    {
        $slug = trim((string) ($website['slug'] ?? ''));

        if ($slug === '' || str_contains($slug, '..') || str_contains($slug, '/')) {
            return false;
        }

        // Der gebaute Stand zuerst, dann der uebernommene: Eine fremde
        // Website wird hier nie gebaut, liegt aber nach dem Auspacken
        // trotzdem da - und ein echtes Bild ist besser als ein
        // Anfangsbuchstabe.
        return is_file(STORAGE_DIR . '/projects/' . $slug . '/dist/index.html')
            || is_file(STORAGE_DIR . '/projects/' . $slug . '/'
                . \WebAtze\Build\Uebernahme::ORDNER . '/index.html');
    }

    /**
     * Gibt es etwas, das beim Kunden noch nicht liegt?
     *
     * Seit die Übertragung von Hand läuft, ist das die wichtigste Frage
     * der Liste – und sie wird nicht durch ein zweites Merkmal
     * beantwortet, das man pflegen muss. Ein solches Merkmal weicht
     * irgendwann von der Wirklichkeit ab, und dann sagt es das Falsche
     * an der Stelle, an der man sich darauf verlässt.
     *
     * Stattdessen aus den Daten selbst: Die jüngste Änderung an einer
     * Seite oder einem Abschnitt gegen den Zeitpunkt des letzten
     * Herunterladens. Ist sie jünger, ist sie noch nicht draussen.
     *
     * Nie heruntergeladen und schon gebaut heisst ebenfalls offen –
     * sonst sähe eine fertige, nie ausgelieferte Website aus wie eine
     * erledigte.
     */
    public static function offeneAenderung(array $website): bool
    {
        $id = (int) ($website['id'] ?? 0);

        if ($id <= 0) {
            return false;
        }

        $geaendert = (string) Db::value(
            'SELECT MAX(letzte) FROM (
                 SELECT MAX(updated_at) AS letzte FROM project_pages WHERE project_id = :p1
                 UNION ALL
                 SELECT MAX(updated_at) AS letzte FROM project_sections WHERE project_id = :p2
             ) AS beides',
            ['p1' => $id, 'p2' => $id]
        );

        if ($geaendert === '') {
            // Nichts zu bearbeiten - dann ist auch nichts offen. Eine
            // von Hand hinzugefuegte Website soll nicht ewig mahnen.
            return false;
        }

        $geholt = trim((string) ($website['downloaded_at'] ?? ''));

        if ($geholt === '') {
            return self::hatVorschau($website);
        }

        return strtotime($geaendert) > strtotime($geholt);
    }

    /**
     * Zu welchem Kunden gehört diese Website? 0, wenn zu keinem.
     *
     * Gebraucht überall dort, wo etwas Neues entsteht, das zu einer
     * Website gehört: ein Wartungsvertrag, ein Supportfaden, ein
     * Fragebogen. Sie alle kennen das Projekt und müssen den Kunden
     * daraus lesen, damit sie auf dessen Seite auftauchen.
     *
     * Ein gemeinsamer Helfer statt drei eigener Abfragen – gerade
     * weil es drei sind und irgendwann eine vierte dazukommt. Die
     * Spalte gab es schon, gefüllt hat sie keine der drei
     * Einfügestellen: Bestehendes trug die Migration nach, alles Neue
     * landete ohne Kunden und verschwand still von seiner Seite.
     *
     * Zieht eine Website später zu einem anderen Kunden um, wandert
     * ein bereits geschriebener Vertrag *nicht* mit. Das ist Absicht
     * und kein Versehen: Ein Vertrag gehört dem, der ihn
     * unterschrieben hat, nicht dem, dem die Website heute gehört.
     */
    public static function kundeVon(int $websiteId): int
    {
        if ($websiteId <= 0) {
            return 0;
        }

        return (int) Db::value(
            'SELECT customer_id FROM projects WHERE id = :id',
            ['id' => $websiteId],
            0
        );
    }

    /**
     * Die zweite Verknüpfung nachziehen.
     *
     * Es gibt zwei Wege zwischen Kunde und Website, und sie werden von
     * verschiedenen Stellen geschrieben: `projects.customer_id` und
     * `customers.project_id`. Bisher konnten sie sich widersprechen –
     * ein Kunde zeigte auf eine Website, die längst einem anderen
     * gehörte, und je nachdem, welche Abfrage gerade lief, kam eine
     * andere Antwort.
     *
     * `projects.customer_id` gewinnt: Es ist die Richtung, die mehrere
     * Websites je Kunde halten kann. `customers.project_id` behält nur
     * noch die Bedeutung "das ist seine Hauptwebsite" und wird hier
     * nachgeführt, damit die beiden nie auseinanderlaufen.
     */
    public static function gleichziehen(int $websiteId, int $kunde): void
    {
        // Kein anderer Kunde darf diese Website noch als seine führen.
        Db::update(
            'customers',
            ['project_id' => null, 'updated_at' => Db::now()],
            'project_id = :w' . ($kunde > 0 ? ' AND id <> :k' : ''),
            $kunde > 0 ? ['w' => $websiteId, 'k' => $kunde] : ['w' => $websiteId]
        );

        if ($kunde <= 0) {
            return;
        }

        // Und der neue bekommt sie, falls er noch keine Hauptwebsite hat.
        $zeile = Db::first('SELECT project_id FROM customers WHERE id = :k', ['k' => $kunde]);

        if ($zeile !== null && (int) ($zeile['project_id'] ?? 0) === 0) {
            Db::update(
                'customers',
                ['project_id' => $websiteId, 'updated_at' => Db::now()],
                'id = :k',
                ['k' => $kunde]
            );
        }
    }

    /**
     * Eine hinzugefügte Website entfernen.
     *
     * Selbst gebaute werden hier nicht angefasst: An ihnen hängen
     * Abschnitte, Pakete und Vorschauen, und die räumt der eigene Weg
     * über die Projektseite auf.
     */
    public static function remove(int $id): bool
    {
        $website = self::find($id);

        if ($website === null || (string) $website['source'] !== 'hand') {
            return false;
        }

        Db::update('monitors', ['project_id' => null], 'project_id = :p', ['p' => $id]);

        return Db::delete('projects', 'id = :id', ['id' => $id]) > 0;
    }

    // ------------------------------------------------------------------
    // Kleinkram
    // ------------------------------------------------------------------

    /** Die volle Adresse zum Anklicken. */
    public static function url(array $website): string
    {
        $domain = trim((string) ($website['domain'] ?? ''));

        if ($domain === '') {
            return '';
        }

        return preg_match('#^https?://#i', $domain) === 1 ? $domain : 'https://' . $domain;
    }

    private static function domain(mixed $wert): string
    {
        $text = trim((string) $wert);

        // Mit oder ohne Vorsilbe eingegeben - gespeichert wird der
        // nackte Name, damit die Liste ruhig aussieht.
        $text = preg_replace('#^https?://#i', '', $text) ?? $text;

        return mb_substr(rtrim($text, '/'), 0, 191);
    }

    private static function host(string $domain): string
    {
        $host = preg_replace('#^https?://#i', '', trim($domain)) ?? $domain;
        $host = explode('/', $host)[0];

        return preg_replace('/^www\./i', '', $host) ?? $host;
    }

    private static function day(mixed $wert): string
    {
        $text = trim((string) $wert);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? $text : '';
    }

    /** Ein Kurzname, den es noch nicht gibt. */
    private static function uniqueSlug(string $name): string
    {
        $basis = str_slug($name, 60);

        if ($basis === '') {
            $basis = 'website';
        }

        $slug = $basis;
        $zahl = 2;

        while (Db::value('SELECT id FROM projects WHERE slug = :s', ['s' => $slug]) !== null) {
            $slug = $basis . '-' . $zahl;
            $zahl++;
        }

        return $slug;
    }
}
