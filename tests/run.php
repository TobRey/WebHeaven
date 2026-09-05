<?php

/**
 * Der Testlauf.
 *
 * Reines PHP, kein Rahmenwerk, kein Server, keine Installation:
 *
 *     php tests/run.php
 *
 * Geprüft wird das, was wehtut, wenn es kaputtgeht – nicht das, was
 * sich leicht prüfen lässt. Also die Sicherheitszusagen (kein
 * Pfadausbruch, kein Zugriff auf das eigene Netz, kein fremdes Projekt),
 * die Stellen, an denen schon einmal ein Fehler steckte, und die
 * Zusage, dass eine Änderung nie den Rest der Website berührt.
 */

declare(strict_types=1);

// SQLite statt MySQL: Der Testlauf soll ohne Datenbankserver auskommen.
putenv('WEBATZE_TEST=1');
$_SERVER['WEBATZE_TEST'] = '1';

require __DIR__ . '/harness.php';
require __DIR__ . '/seiten.php';
require __DIR__ . '/../public_html/app/bootstrap.php';

// Der Testlauf bekommt eine eigene, leere Datenbank.
//
// Das ist keine Bequemlichkeit, sondern eine Sicherung: Die Prüfungen
// legen Daten an und räumen sie wieder weg. Liefen sie gegen die
// konfigurierte Datenbank, würde ein Testlauf auf dem Hosting echte
// Kundendaten löschen. Er wird dort niemand aufrufen – aber "niemand
// wird das tun" ist keine Zusage, die man geben sollte.
$testDatei = sys_get_temp_dir() . '/webatze-test-' . getmypid() . '.sqlite';
@unlink($testDatei);

\WebAtze\Core\Db::setConnection((static function () use ($testDatei): PDO {
    $pdo = new PDO('sqlite:' . $testDatei, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
})());

// Und am Ende wieder weg, auch wenn eine Prüfung abbricht.
register_shutdown_function(static function () use ($testDatei): void {
    \WebAtze\Core\Db::setConnection(null);
    @unlink($testDatei);
});

use WebAtze\Ai\ClaudeClient;
use WebAtze\Build\{Theme, ShowcaseBuilder};
use WebAtze\Core\{Crypto, Http, Request, Router, Validator};
use WebAtze\Templates\{Catalog, Page, Renderer, Schema};

// Die Tabellen auf den neuesten Stand bringen. Ohne das scheitert der
// Testlauf in einer frischen Kopie – und in einer alten immer dann,
// wenn eine Spalte dazugekommen ist.
\WebAtze\Core\Schema::migrate();

// Die Designstudien eintragen, damit Startseite und Referenzen so
// gerendert werden wie im Betrieb. Ohne sie zeigten beide den leeren
// Zustand, und genau die Abschnitte, die beim Umbau heikel sind, wären
// von keiner Prüfung erfasst.
if (is_file(APP_DIR . '/Support/showcase-seed.php')) {
    $reihenfolge = 0;

    foreach ((array) require APP_DIR . '/Support/showcase-seed.php' as $studie) {
        if (!is_array($studie) || ($studie['slug'] ?? '') === '') {
            continue;
        }

        \WebAtze\Core\Db::insert('showcase', $studie + [
            'published' => 1,
            'sort_order' => $reihenfolge++,
            'created_at' => \WebAtze\Core\Db::now(),
            'updated_at' => \WebAtze\Core\Db::now(),
        ]);
    }
}

// Den Aufbau der eigenen Website neu festhalten statt ihn zu prüfen.
//
// Das ist der einzige Weg, die Datei zu ändern: Wer sie von Hand
// anpasst, hält damit auch fest, was er kaputtgemacht hat.
if (in_array('--seiten-festhalten', $argv, true)) {
    $anzahl = seiten_festhalten(seiten_erfassen());

    echo "\n  " . $anzahl . " Seiten festgehalten in tests/fixtures/eigene-seiten.json\n\n";
    exit(0);
}

// ==================================================================
test('Router: Adressen finden ihr Ziel', function (): void {
    $router = new Router();
    $router->get('/', 'SiteController@home');
    $router->get('/referenzen/{slug}', 'SiteController@reference');
    $router->get('/vorschau/{token}/{path:.+}', 'PreviewController@file');
    $router->post('/create/projekt/{id}/bauen', 'ProjectController@rebuild');

    is('SiteController@home', route($router, 'GET', '/'), 'Startseite');
    is('SiteController@reference', route($router, 'GET', '/referenzen/cafe-nordlicht'), 'Referenz');
    is('PreviewController@file', route($router, 'GET', '/vorschau/abc/assets/css/site.css'),
        'Vorschau mit tiefem Pfad');
    is('ProjectController@rebuild', route($router, 'POST', '/create/projekt/12/bauen'), 'Bauen');

    // Was nicht passt, darf auch nicht passen.
    is(null, route($router, 'GET', '/gibtsnicht'), 'Unbekannte Adresse');
    is(null, route($router, 'GET', '/../app/config.php'), 'Pfadausbruch');
    is(null, route($router, 'POST', '/'), 'Falsche Methode');

    // Der Fehler von damals: preg_quote über das ganze Muster machte
    // aus {slug} einen Text, der nie passte.
    is('SiteController@reference', route($router, 'GET', '/referenzen/a-b_c.d'),
        'Kennung mit Sonderzeichen');
});

// ==================================================================
test('Passwörter: Argon2id, und falsche kommen nicht durch', function (): void {
    $hash = Crypto::hashPassword('ein-gutes-Passwort-2026');

    ok(str_starts_with($hash, '$argon2id$'), 'Argon2id wird benutzt');
    ok(Crypto::checkPassword('ein-gutes-Passwort-2026', $hash), 'Richtiges Passwort');
    ok(!Crypto::checkPassword('ein-gutes-Passwort-2025', $hash), 'Falsches Passwort');
    ok(!Crypto::checkPassword('', $hash), 'Leeres Passwort');
    ok(!Crypto::checkPassword('x', 'kein-hash'), 'Kaputter Hash wirft nicht');

    // Zweimal dasselbe Passwort ergibt zwei verschiedene Hashes.
    isnt($hash, Crypto::hashPassword('ein-gutes-Passwort-2026'), 'Salz je Hash');
});

// ==================================================================
test('Verschlüsselung: FTP-Zugangsdaten liegen nicht im Klartext', function (): void {
    $secret = 'ftp-Passwort-des-Kunden';
    $box = Crypto::encrypt($secret);

    ok(!str_contains($box, $secret), 'Der Klartext steht nicht darin');
    is($secret, Crypto::decrypt($box), 'Und kommt wieder heraus');
    is(null, Crypto::decrypt('unsinn'), 'Unsinn ergibt null statt einer Ausnahme');

    // Ein verändertes Zeichen macht den Umschlag ungültig.
    $verbogen = substr($box, 0, -2) . 'xy';
    is(null, Crypto::decrypt($verbogen), 'Veränderte Daten werden erkannt');
});

// ==================================================================
test('SSRF-Schutz: das eigene Netz bleibt unerreichbar', function (): void {
    $verboten = [
        'http://127.0.0.1/admin' => 'Loopback',
        'http://localhost/' => 'localhost',
        'http://10.0.0.5/' => 'Privates Netz A',
        'http://192.168.1.1/' => 'Privates Netz C',
        'http://172.16.0.1/' => 'Privates Netz B',
        'http://169.254.169.254/latest/meta-data/' => 'Metadatendienst',
        'http://100.64.0.1/' => 'Anbieter-internes Netz',
        'http://[::1]/' => 'Loopback über IPv6',
        'file:///etc/passwd' => 'Dateizugriff',
        'gopher://example.com/' => 'Fremdes Protokoll',
    ];

    foreach ($verboten as $url => $was) {
        isnt(null, Http::assertPublicUrl($url), $was . ' wird abgewiesen');
    }

    ok(!Http::isPublicIp('127.0.0.1'), '127.0.0.1 ist nicht öffentlich');
    ok(!Http::isPublicIp('::1'), '::1 ist nicht öffentlich');
    ok(Http::isPublicIp('93.184.216.34'), 'Eine echte Adresse ist öffentlich');
});

// ==================================================================
test('Ausgabe: nichts kommt ungeprüft in die Seite', function (): void {
    $böse = '<script>alert(1)</script>';

    is('&lt;script&gt;alert(1)&lt;/script&gt;', e($böse), 'e() entschärft');
    ok(!str_contains(json_out(['x' => $böse]), '<script'), 'json_out() auch');

    // Der Renderer lässt nur bekannte Auszeichnungen durch.
    $reich = Renderer::richtext('<p>Ein <strong>fetter</strong> Text.</p><script>alert(1)</script>');
    ok(str_contains($reich, '<strong>fetter</strong>'), 'Fett bleibt');
    ok(!str_contains($reich, '<script'), 'Skript verschwindet');

    // Reiner Text wird zu Absätzen – genau das, was dem Kunden im
    // Bearbeitungsbereich versprochen wird.
    $absätze = Renderer::richtext("Erster Absatz.\n\nZweiter Absatz.");
    is(2, substr_count($absätze, '<p>'), 'Leerzeile ergibt einen neuen Absatz');

    // Und nur Adressen, die keine sind, werden zu '#'.
    is('#', Renderer::safeUrl('javascript:alert(1)'), 'javascript: wird abgewiesen');
    is('#', Renderer::safeUrl('data:text/html,<script>'), 'data: wird abgewiesen');
    is('kontakt.html', Renderer::safeUrl('kontakt.html'), 'Seiteninterner Verweis bleibt relativ');
    is('https://beispiel.ch', Renderer::safeUrl('https://beispiel.ch'), 'https bleibt');
    is('mailto:a@b.ch', Renderer::safeUrl('mailto:a@b.ch'), 'mailto bleibt');
});

// ==================================================================
test('Gestaltungswerte: nur, was in eine CSS-Regel gehört', function (): void {
    is('2rem', Renderer::cssValue('2rem'), 'Eine Länge');
    is('#ff0000', Renderer::cssValue('#ff0000'), 'Eine Farbe');
    is('', Renderer::cssValue('red; background: url(javascript:alert(1))'), 'Eingeschleuste Regel');
    is('', Renderer::cssValue('expression(alert(1))'), 'Alter Trick mit expression');
    is('', Renderer::cssValue('url(http://fremd.example/x.png)'), 'Fremde Adresse');
});

// ==================================================================
test('Vorlagen: 20 je Abschnittstyp, und jede lässt sich bauen', function (): void {
    $typen = Schema::types();
    ok(count($typen) >= 15, 'Mindestens 15 Abschnittstypen (' . count($typen) . ')');

    foreach ($typen as $typ) {
        $varianten = Catalog::forType($typ);

        // Der freie Abschnitt bestimmt sein Aussehen über seine
        // Bausteine; die Variante steuert nur noch die Fläche darum.
        // Zwanzig Knöpfe anzubieten, von denen vierzehn dasselbe tun,
        // waere eine Behauptung und keine Auswahl.
        if ($typ === 'frei') {
            ok(count($varianten) >= 4, 'Der freie Abschnitt hat eine eigene, kurze Liste ('
                . count($varianten) . ')');
        } else {
            // Zwanzig im Grundkatalog - das ist die Zusage an jede
            // Kundenwebsite. Varianten aus einem Stilpaket kommen
            // obendrauf und stehen nur einer Website zur Verfuegung, die
            // dieses Paket traegt.
            is(20, count(Catalog::all()[$typ]), '20 Grundvarianten für "' . $typ . '"');
            ok(count($varianten) >= 20, 'Und mindestens so viele insgesamt');
        }

        // Jede Variante muss sich rendern lassen – auch mit leerem Inhalt.
        foreach (array_keys($varianten) as $key) {
            $html = Renderer::section([
                'id' => 1,
                'type' => $typ,
                'template_key' => $key,
                'content' => [],
                'overrides' => [],
            ], ['brand' => 'Test', 'nav' => [], 'legal' => []]);

            ok($html !== '', $typ . '/' . $key . ' erzeugt HTML');
        }
    }
});

// ==================================================================
test('Farben: Text bleibt lesbar, auch bei schwieriger Wunschfarbe', function (): void {
    // Ein sehr helles Gelb als Hauptfarbe: Als Textfarbe unlesbar.
    $theme = Theme::build(['primary' => '#ffd400', 'secondary' => '#00ff00']);
    $farben = $theme['colors'];

    $kontrast = contrast($farben['primary'], $farben['bg']);
    ok($kontrast >= 4.5, sprintf('Textfarbe hat Kontrast %.2f (mindestens 4.5)', $kontrast));

    // Auf der gelben Fläche muss die Schrift dunkel sein.
    $aufFläche = contrast($farben['on_primary'], $farben['primary_raw']);
    ok($aufFläche >= 4.5, sprintf('Schrift auf der Fläche hat Kontrast %.2f', $aufFläche));

    // Eine dunkle Farbe darf bleiben, wie sie ist.
    $dunkel = Theme::build(['primary' => '#2b1b9e'])['colors'];
    is('#2b1b9e', strtolower($dunkel['primary']), 'Eine dunkle Farbe wird nicht verändert');
});

// ==================================================================
test('Referenztexte verraten nicht, wie die Websites entstehen', function (): void {
    $verräterisch = [
        'Diese Website wurde mit Claude erstellt.',
        'Dank KI in wenigen Minuten fertig.',
        'Automatisch generiert aus einem Template.',
        'Mit künstlicher Intelligenz gebaut.',
    ];

    foreach ($verräterisch as $text) {
        ok(ShowcaseBuilder::leaksMethod($text), 'Erkannt: "' . mb_substr($text, 0, 30) . '..."');
    }

    $unverfänglich = [
        'Ein frischer Auftritt für eine Schreinerei aus Olten.',
        'Klare Struktur, schnelle Ladezeiten, gut auf dem Handy.',
    ];

    foreach ($unverfänglich as $text) {
        ok(!ShowcaseBuilder::leaksMethod($text), 'In Ordnung: "' . mb_substr($text, 0, 30) . '..."');
    }
});

// ==================================================================
test('Preise: auf der Website steht keine einzige Zahl', function (): void {
    $seiten = array_merge(
        glob(dirname(__DIR__) . '/public_html/app/Views/pages/*.php') ?: [],
        glob(dirname(__DIR__) . '/public_html/app/Views/partials/*.php') ?: []
    );
    ok($seiten !== [], 'Es gibt öffentliche Seiten zum Prüfen');

    foreach ($seiten as $datei) {
        $inhalt = (string) file_get_contents($datei);

        // Ein Preis sieht so aus: CHF 990, 990.-, 990 Franken, ab 99 €
        $muster = '/(CHF|Fr\.|EUR|€)\s*\d|(\d+[\.\,]\-)|(\d+\s*(Franken|Euro))/i';

        ok(!preg_match($muster, $inhalt), basename($datei) . ' enthält keinen Preis');
    }
});

// ==================================================================
test('Mehrsprachige Kundenseiten', function (): void {
    // Das Formular bot "Deutsch und Französisch" an, gebaut wurde
    // trotzdem nur eine Sprache: SiteBuilder las locales nie.
    $sprachen = \WebAtze\Ai\Translator::localesOf(['locales' => 'de,fr,it']);
    is(['de', 'fr', 'it'], $sprachen, 'Die Sprachen werden gelesen');
    is(['fr', 'it'], \WebAtze\Ai\Translator::extraLocales(['locales' => 'de,fr,it']),
        'Zu übersetzen sind alle ausser der ersten');

    // Unsinn darf nicht durchkommen.
    is(['de'], \WebAtze\Ai\Translator::localesOf(['locales' => 'xx,,de']),
        'Unbekannte Sprachen werden verworfen');
    is(['de'], \WebAtze\Ai\Translator::localesOf([]), 'Ohne Angabe bleibt es bei Deutsch');

    // Die Hauptsprache liegt in der Wurzel, die weiteren im Unterordner.
    is('index.html', Page::fileNameFor('/', 'de', 'de'), 'Hauptsprache in der Wurzel');
    is('fr/index.html', Page::fileNameFor('/', 'fr', 'de'), 'Französisch im Unterordner');
    is('it/kontakt.html', Page::fileNameFor('/kontakt', 'it', 'de'), 'Auch Unterseiten');

    // Aus dem Unterordner heraus zeigt ein Verweis eine Ebene hinauf.
    is('index.html', Page::relativeLink('index.html', 'de', 'de'), 'In der Wurzel ohne Umweg');
    is('../index.html', Page::relativeLink('index.html', 'fr', 'de'), 'Aus fr/ eine Ebene hoch');

    // Eine dreisprachige Seite bauen und nachsehen.
    $site = [
        'brand' => 'Test AG',
        'locale' => 'de',
        'locales' => ['de', 'fr'],
        'theme' => ['colors' => ['primary' => '#123456']],
        'pages' => [
            ['path' => '/', 'title' => 'Start', 'in_navigation' => 1,
             'translations' => ['fr' => ['title' => 'Accueil', 'meta_description' => 'Bonjour']]],
            ['path' => '/kontakt', 'title' => 'Kontakt', 'in_navigation' => 1, 'translations' => []],
        ],
        'contact' => [],
    ];

    $page = [
        'path' => '/',
        'title' => 'Start',
        'meta_description' => 'Guten Tag',
        'translations' => ['fr' => ['title' => 'Accueil', 'meta_description' => 'Bonjour']],
        'sections' => [[
            'id' => 1,
            'type' => 'hero',
            'template_key' => Catalog::defaultKey('hero'),
            'content' => ['title' => 'Guten Tag'],
            'overrides' => [],
            'translations' => ['fr' => ['title' => 'Bonjour']],
        ]],
    ];

    $de = Page::render($site, $page, '', 'de');
    $fr = Page::render($site, $page, '', 'fr');

    ok(str_contains($de, 'Guten Tag'), 'Die deutsche Fassung trägt den deutschen Text');
    ok(str_contains($fr, 'Bonjour'), 'Die französische den französischen');
    ok(!str_contains($fr, 'Guten Tag'), 'Und nicht mehr den deutschen');

    ok(str_contains($de, '<html lang="de">'), 'lang stimmt in der Wurzel');
    ok(str_contains($fr, '<html lang="fr">'), 'Und im Unterordner');

    // Aus dem Unterordner müssen Stylesheet und Skript eine Ebene hoch.
    ok(str_contains($de, 'href="assets/css/site.css"'), 'Stylesheet in der Wurzel');
    ok(str_contains($fr, 'href="../assets/css/site.css"'), 'Stylesheet aus fr/ eine Ebene hoch');
    ok(str_contains($fr, 'src="../assets/js/site.js"'), 'Skript ebenso');

    // hreflang, sonst gilt beides als doppelter Inhalt.
    ok(str_contains($de, 'hreflang="fr"'), 'Die deutsche Seite nennt die französische');
    ok(str_contains($fr, 'hreflang="de"'), 'Und umgekehrt');
    ok(str_contains($de, 'hreflang="x-default"'), 'Und eine Vorgabe für alle anderen');

    // Einsprachig bleibt einsprachig – kein Umschalter, kein hreflang.
    $einzeln = Page::render(array_merge($site, ['locales' => ['de']]), $page, '', 'de');
    ok(!str_contains($einzeln, 'hreflang='), 'Ohne zweite Sprache kein hreflang');
    ok(!str_contains($einzeln, 's-header__langs'), 'Und kein Umschalter');
});

// ==================================================================
test('Besucherzählung: es verlässt nur eine Summe den Kundenserver', function (): void {
    $zaehler = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Kit/site/php/zaehler.php'
    );
    $zahlen = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Kit/site/php/zahlen.php'
    );

    // Das Salz wechselt täglich – sonst liesse sich jemand über Wochen
    // verfolgen, und genau das soll nicht gehen.
    ok(str_contains($zaehler, 'tagesSalz'), 'Die Kennung entsteht aus einem Tagessalz');
    ok(str_contains($zaehler, "date('Y-m-d')"), 'Und das Salz hängt am Tag');

    // Was hinausgeht, sind Zahlen. Die Kennungen bleiben liegen.
    ok(str_contains($zahlen, "count((array) (\$werte['besucher'] ?? []))"),
        'Herausgegeben wird die Anzahl, nicht die Liste');
    ok(!preg_match("/'besucher' => \\\$werte\\['besucher'\\]/", $zahlen),
        'Die Kennungen selbst verlassen den Server nie');
    ok(str_contains($zahlen, 'hash_equals'), 'Der Schlüssel wird zeitunabhängig verglichen');

    // Das Bild geht vor dem Zählen hinaus: Eine kaputte Zählung darf
    // niemanden warten lassen.
    $bildPos = strpos($zaehler, 'image/gif');
    $zaehlPos = strpos($zaehler, 'function zaehlen');
    $aufrufPos = strpos($zaehler, 'zaehlen($dataDir');
    ok($bildPos !== false && $aufrufPos !== false && $bildPos < $aufrufPos,
        'Erst das Bild, dann das Zählen');
    ok(str_contains($zaehler, 'fastcgi_finish_request'), 'Die Verbindung wird vorher geschlossen');
    ok($zaehlPos !== false, 'Gezählt wird in einer eigenen Funktion');

    // Ein Fehler beim Zählen darf keine Meldung hinterlassen.
    ok(str_contains($zaehler, 'catch (\\Throwable)'), 'Ein Fehler beim Zählen bleibt folgenlos');
});

// ==================================================================
test('Wochenbericht: Zahlen und Vergleich stimmen', function (): void {
    $projekt = ['id' => 4242, 'name' => 'Bäckerei Muster', 'report_email' => 'kunde@example.org'];

    // Zwei Wochen füllen: die vergangene und die davor.
    $bis = date('Y-m-d', strtotime('yesterday'));
    $von = date('Y-m-d', strtotime($bis . ' -6 days'));
    $davor = date('Y-m-d', strtotime($von . ' -3 days'));

    \WebAtze\Core\Db::delete('visits', 'project_id = :p', ['p' => 4242]);

    \WebAtze\Build\VisitCollector::store(4242, [
        ['tag' => $bis, 'pfad' => '/', 'herkunft' => '', 'aufrufe' => 30, 'besucher' => 20],
        ['tag' => $bis, 'pfad' => '/kontakt.html', 'herkunft' => 'google.ch', 'aufrufe' => 10, 'besucher' => 8],
        ['tag' => $davor, 'pfad' => '/', 'herkunft' => '', 'aufrufe' => 20, 'besucher' => 15],
        // Unsinn muss durchfallen, nicht die Summe verfälschen.
        ['tag' => 'gestern', 'pfad' => '/', 'herkunft' => '', 'aufrufe' => 999, 'besucher' => 999],
        ['tag' => $bis, 'pfad' => '/', 'herkunft' => '', 'aufrufe' => -5, 'besucher' => -5],
    ]);

    $woche = \WebAtze\Build\VisitCollector::week(4242, $bis);

    // Die letzte Zeile ersetzt die erste (gleicher Tag, gleiche Seite,
    // gleiche Herkunft) – und -5 wird zu 0.
    is(10, $woche['aufrufe'], 'Nur die Woche zählt, und negative Werte werden zu null');
    is(20, $woche['davor']['aufrufe'], 'Die Vorwoche steht getrennt daneben');
    is('/kontakt.html', $woche['seiten'][0]['pfad'], 'Die meistbesuchte Seite steht oben');
    is('Direkt eingegeben', $woche['herkunft'][1]['name'] ?? '',
        'Ohne Verweis heisst es "Direkt eingegeben"');
    is(7, count($woche['tage']), 'Eine Woche hat sieben Tage');

    // Der Text der Nachricht: verständlich, ohne Fachwörter.
    $text = \WebAtze\Build\VisitCollector::reportText($projekt, $woche);
    ok(str_contains($text, 'Seitenaufrufe'), 'Der Bericht nennt die Aufrufe');
    ok(str_contains($text, 'Bäckerei Muster'), 'Und die Firma');
    ok(str_contains($text, 'anonym'), 'Und sagt, dass niemand wiedererkannt wird');

    foreach (['Session', 'Bounce', 'Unique', 'Pageview', 'Tracking'] as $fachwort) {
        ok(!str_contains($text, $fachwort), 'Kein Fachwort: ' . $fachwort);
    }

    \WebAtze\Core\Db::delete('visits', 'project_id = :p', ['p' => 4242]);
});

// ==================================================================
test('Sicherungen: nichts wird ausserhalb des eigenen Ordners gelöscht', function (): void {
    $quelle = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Build/Backup.php'
    );

    ok(str_contains($quelle, 'insideBackups'), 'Vor dem Löschen wird der Pfad geprüft');
    ok(str_contains($quelle, 'realpath'), 'Und zwar über den aufgelösten Pfad');

    // Der Schlüssel gehört nicht in eine Sicherung, die herumwandert.
    ok(!str_contains($quelle, "'/config.php'"), 'config.php wird nicht mitgesichert');
    ok(str_contains($quelle, 'app/config.php'), 'Und darauf wird ausdrücklich hingewiesen');

    // Die SQL-Ausgabe muss Anführungszeichen verdoppeln, sonst bricht
    // ein Firmenname mit Apostroph die ganze Datei.
    $ref = new ReflectionMethod(\WebAtze\Build\Backup::class, 'literal');
    $ref->setAccessible(true);

    is("'O''Brien'", $ref->invoke(null, "O'Brien"), 'Ein Apostroph wird verdoppelt');
    is('NULL', $ref->invoke(null, null), 'null bleibt NULL');
    is('42', $ref->invoke(null, 42), 'Zahlen ohne Anführungszeichen');
    is("'a\\\\b'", $ref->invoke(null, 'a\\b'), 'Ein Backslash wird verdoppelt');

    // Die Aufbewahrung: 14 Tage plus Monatserste.
    ok(str_contains($quelle, 'KEEP_DAILY = 14'), 'Vierzehn Tagesstände');
    ok(str_contains($quelle, 'KEEP_MONTHLY'), 'Dazu Monatsstände');
});

// ==================================================================
test('Prüfung nach dem Bauen findet, was tatsächlich falsch ist', function (): void {
    $dist = sys_get_temp_dir() . '/wa-pruefung-' . getmypid();
    @mkdir($dist . '/assets/img', 0775, true);

    file_put_contents($dist . '/index.html',
        '<html><head><script src="a.js"></script></head><body>'
        . '<a href="kontakt">Kontakt</a>'
        . '<a href="gibtsnicht.html">Kaputt</a>'
        . '<img src="assets/img/x.png">'
        . '<link href="https://fonts.googleapis.com/css2?family=Inter">'
        . '</body></html>');

    file_put_contents($dist . '/kontakt.html', '<html><body>Kontakt</body></html>');
    file_put_contents($dist . '/assets/img/x.png', str_repeat('x', 400 * 1024));

    $projekt = ['id' => 0, 'slug' => 'pruefung', 'domain' => '', 'brief' => '{}'];

    // Verweise: der interne Verweis "kontakt" findet kontakt.html
    // (so steht es in der .htaccess), "gibtsnicht.html" nicht.
    $verweise = \WebAtze\Build\SiteChecker::links($projekt, $dist, 0.1);
    $text = json_encode($verweise['findings'], JSON_UNESCAPED_UNICODE);

    ok(str_contains($text, 'gibtsnicht.html'), 'Der tote Verweis wird gefunden');
    ok(!str_contains($text, '"kontakt"'), 'Der gültige nicht');
    ok(!$verweise['passed'], 'Damit ist die Prüfung nicht bestanden');

    // Tempo: das grosse Bild und das fehlende Breite/Höhe.
    $tempo = \WebAtze\Build\SiteChecker::speed($projekt, $dist);
    $text = json_encode($tempo['findings'], JSON_UNESCAPED_UNICODE);

    ok(str_contains($text, 'x.png'), 'Das grosse Bild fällt auf');
    ok(str_contains($text, 'springt'), 'Das Bild ohne feste Grösse ebenso');
    ok(str_contains($text, 'halten die Darstellung auf'), 'Und das Skript ohne defer');

    // Recht: Impressum fehlt, und Google Fonts wird eingebunden.
    $recht = \WebAtze\Build\SiteChecker::legal($projekt, $dist);
    $text = json_encode($recht['findings'], JSON_UNESCAPED_UNICODE);

    ok(str_contains($text, 'Impressum'), 'Das fehlende Impressum fällt auf');
    ok(str_contains($text, 'Datenschutz'), 'Der fehlende Datenschutz auch');
    ok(str_contains($text, 'Google Fonts'), 'Und der fremde Dienst');
    ok(str_contains($text, 'Zustimmungsbanner'), 'Mit der Begründung, warum das stört');
    ok(!$recht['passed'], 'Auch das ist nicht bestanden');

    // Und nun dasselbe, aber richtig gemacht.
    file_put_contents($dist . '/index.html',
        '<html><head><script src="a.js" defer></script></head><body>'
        . '<a href="kontakt">Kontakt</a>'
        . '<img src="assets/img/x.png" width="800" height="600">'
        . '</body></html>');
    file_put_contents($dist . '/impressum.html', '<html><body>' . str_repeat('Angaben zur Firma. ', 30) . '</body></html>');
    file_put_contents($dist . '/datenschutz.html', '<html><body>' . str_repeat('Wie wir mit Daten umgehen. ', 30) . '</body></html>');
    unlink($dist . '/assets/img/x.png');

    $projekt['brief'] = json_encode(['contact_email' => 'a@b.ch', 'contact_address' => 'Weg 1']);

    $recht = \WebAtze\Build\SiteChecker::legal($projekt, $dist);
    ok($recht['passed'], 'Mit Impressum und ohne fremden Dienst ist es bestanden');
    is(100, $recht['score'], 'Und ohne Abzug');

    $verweise = \WebAtze\Build\SiteChecker::links($projekt, $dist, 0.1);
    ok($verweise['passed'], 'Ohne toten Verweis ebenfalls');

    delete_tree($dist);
});

// ==================================================================
test('Die Anleitung beschreibt nur, was auch gebaut wurde', function (): void {
    $site = [
        'brand' => 'Muster AG',
        'locales' => ['de'],
        'theme' => ['colors' => ['primary' => '#2b1b9e']],
        'pages' => [['path' => '/', 'title' => 'Start']],
    ];

    // Ohne Bearbeitungsbereich darf kein Wort darüber darin stehen.
    $ohne = \WebAtze\Build\DocBuilder::render(
        ['name' => 'Muster AG', 'domain' => '', 'wants_admin' => 0],
        $site,
        ['wants_docs' => true]
    );

    ok(!str_contains($ohne, 'Bilder tauschen'), 'Ohne Backend kein Kapitel dazu');
    ok(!str_contains($ohne, '/admin'), 'Und keine Adresse dorthin');
    ok(str_contains($ohne, 'keinen eigenen'), 'Stattdessen steht da, dass es keinen gibt');

    // Mit allem.
    $mit = \WebAtze\Build\DocBuilder::render(
        ['name' => 'Muster AG', 'domain' => 'muster.ch', 'wants_admin' => 1, 'admin_username' => 'muster'],
        $site,
        ['wants_docs' => true, 'wants_stats' => true, 'wants_support' => true, 'report_email' => 'a@b.ch']
    );

    ok(str_contains($mit, 'https://muster.ch/admin'), 'Mit Backend steht die Adresse darin');
    ok(str_contains($mit, 'muster.ch/support'), 'Und die der Hilfeseite');
    ok(str_contains($mit, 'Besucherzahlen'), 'Und ein Kapitel zur Zählung');

    // Kein Wort darüber, wie die Website entstanden ist.
    foreach ([' KI', 'Claude', 'Anthropic', 'generiert', 'Sprachmodell'] as $wort) {
        ok(!str_contains($mit, $wort), 'Kein Hinweis auf: ' . trim($wort));
    }

    // Der Assistent heisst beim Kunden Assistent.
    ok(str_contains($mit, 'Assistent'), 'Der Assistent heisst Assistent');

    // Die Farbe landet unmaskiert im Stylesheet – Unsinn darf da nicht durch.
    $boes = \WebAtze\Build\DocBuilder::render(
        ['name' => 'X', 'domain' => '', 'wants_admin' => 0],
        ['brand' => 'X', 'locales' => ['de'], 'pages' => [],
         'theme' => ['colors' => ['primary' => 'red;} body{display:none']]],
        ['wants_docs' => true]
    );

    ok(!str_contains($boes, 'display:none'), 'Eine erfundene Farbe kommt nicht ins Stylesheet');
    ok(str_contains($boes, '--ton: #1f2937'), 'Stattdessen greift die Vorgabe');
});

// ==================================================================
test('Geld wird in Rappen gerechnet, nicht in Kommazahlen', function (): void {
    $r = static fn (string $v): int => \WebAtze\Http\BillingController::toRappen($v);

    is(123450, $r('1234.50'), 'Franken und Rappen');
    is(1210, $r('12.10'), 'Der Fall, an dem Fliesskomma scheitert');
    is(123455, $r("1'234.55"), 'Mit Tausendertrennzeichen');
    is(123455, $r('1234,55'), 'Mit Komma statt Punkt');
    is(99000, $r('990.-'), 'Die schweizerische Schreibweise');
    is(99000, $r('990'), 'Ohne Rappen');
    is(-5025, $r('-50.25'), 'Auch negativ, für einen Abzug');
    is(0, $r('abc'), 'Unsinn ergibt null');
    is(0, $r(''), 'Leer ebenfalls');

    // Das ist der Grund für den ganzen Umweg über Zeichenketten.
    ok($r('12.10') === 1210, 'Zwölf Franken zehn sind 1210 Rappen, nicht 1209');

    // Und die Rückrichtung.
    is("1'234.55", \WebAtze\Build\DocumentBuilder::money(123455), 'Zurück in Franken, mit Tausendertrennzeichen');
    is('0.05', \WebAtze\Build\DocumentBuilder::money(5), 'Fünf Rappen');

    // Summe mit Mehrwertsteuer: 2 × 100.00 + 8 % = 216.00
    $items = [['label' => 'x', 'quantity' => 2, 'price_rappen' => 10000]];
    is(21600, \WebAtze\Build\DocumentBuilder::total($items, 8), 'Summe mit Mehrwertsteuer');
    is(20000, \WebAtze\Build\DocumentBuilder::total($items, 0), 'Und ohne');
});

// ==================================================================
test('Das PDF ist eine gültige Datei', function (): void {
    $pdf = new \WebAtze\Core\Pdf('Prüfung');
    $pdf->text(50, 60, 'Rechnung mit Umlauten: Grüezi, Müller & Söhne', 14, true);
    $pdf->textRight(545, 90, "1'234.55");
    $pdf->line(50, 100, 545, 100);
    $pdf->addPage();
    $pdf->paragraph(50, 60, 400, "Zweite Seite.\n\nMit Absatz.");

    $bytes = $pdf->output();

    ok(str_starts_with($bytes, '%PDF-1.4'), 'Der Anfang stimmt');
    ok(str_ends_with(rtrim($bytes), '%%EOF'), 'Das Ende auch');
    ok(substr_count($bytes, '/Type /Page ') === 2, 'Zwei Seiten');

    // Die Sprungtabelle muss auf die richtigen Stellen zeigen – sonst
    // öffnet kein Betrachter die Datei.
    ok(preg_match('/startxref\s+(\d+)/', $bytes, $m) === 1, 'Es gibt eine Sprungtabelle');

    $start = (int) $m[1];
    is('xref', substr($bytes, $start, 4), 'Und sie liegt dort, wo es heisst');

    preg_match_all('/^(\d{10}) 00000 n $/m', substr($bytes, $start), $offsets);

    $alleRichtig = true;
    foreach ($offsets[1] as $index => $offset) {
        $erwartet = ($index + 1) . ' 0 obj';
        if (substr($bytes, (int) $offset, strlen($erwartet)) !== $erwartet) {
            $alleRichtig = false;
        }
    }

    ok($offsets[1] !== [], 'Die Tabelle ist nicht leer');
    ok($alleRichtig, 'Jeder Eintrag zeigt auf sein Objekt');

    // Umlaute müssen als WinAnsi im Strom stehen, nicht als UTF-8.
    ok(str_contains($bytes, "Gr\xFCezi"), 'Umlaute stehen als WinAnsi darin');
    ok(!str_contains($bytes, "Gr\xC3\xBCezi"), 'Und nicht als UTF-8');

    // Klammern würden den Textbefehl vorzeitig beenden.
    $klammer = new \WebAtze\Core\Pdf('x');
    $klammer->text(10, 10, 'Ein (Text) mit \\ Klammern');
    ok(str_contains($klammer->output(), 'Ein \\(Text\\) mit'), 'Klammern werden entwertet');

    // Ziffern sind gleich breit – nur deshalb stehen Beträge bündig.
    is(
        \WebAtze\Core\Pdf::widthOf('11111.11', 10),
        \WebAtze\Core\Pdf::widthOf('98765.43', 10),
        'Zwei gleich lange Beträge sind gleich breit'
    );
});

// ==================================================================
test('Rechnungsnummern zählen je Art und Jahr hoch', function (): void {
    \WebAtze\Core\Db::delete('documents', '1 = 1', []);

    $jahr = date('Y');

    is('O-' . $jahr . '-001', \WebAtze\Build\DocumentBuilder::nextNumber('offer'),
        'Die erste Offerte des Jahres');

    $id = \WebAtze\Build\DocumentBuilder::create([
        'kind' => 'offer',
        'project_id' => null,
        'title' => 'Website',
        'recipient' => ['name' => 'Muster AG', 'email' => 'a@b.ch'],
        'items' => [
            ['label' => 'Website mit fünf Seiten', 'quantity' => 1, 'price_rappen' => 190000],
            ['label' => 'Leere Zeile fliegt raus', 'quantity' => 1, 'price_rappen' => 0],
            ['label' => '', 'quantity' => 1, 'price_rappen' => 5000],
        ],
        'intro' => 'Guten Tag',
        'outro' => 'Freundliche Grüsse',
        'vat_percent' => 0,
        'due_days' => 30,
    ]);

    ok($id > 0, 'Die Offerte wurde angelegt');

    $doc = \WebAtze\Build\DocumentBuilder::find($id);

    is('O-' . $jahr . '-001', (string) $doc['number'], 'Sie trägt die erste Nummer');
    is(2, count((array) $doc['items']), 'Die Zeile ohne Text fiel weg');
    is(190000, (int) $doc['total_rappen'], 'Die Summe stimmt');
    is('draft', (string) $doc['status'], 'Und sie ist ein Entwurf');
    ok(is_file((string) $doc['file_path']), 'Das PDF liegt da');

    is('O-' . $jahr . '-002', \WebAtze\Build\DocumentBuilder::nextNumber('offer'),
        'Die nächste Offerte zählt hoch');
    is('R-' . $jahr . '-001', \WebAtze\Build\DocumentBuilder::nextNumber('invoice'),
        'Rechnungen zählen getrennt');

    // Aus der Offerte eine Rechnung.
    $rechnung = \WebAtze\Build\DocumentBuilder::toInvoice($id);
    $doc2 = \WebAtze\Build\DocumentBuilder::find((int) $rechnung);

    is('R-' . $jahr . '-001', (string) $doc2['number'], 'Die Rechnung bekommt eine eigene Nummer');
    is(190000, (int) $doc2['total_rappen'], 'Mit demselben Betrag');
    ok((string) $doc2['due_on'] !== '', 'Und einer Zahlungsfrist');

    // Überfällig wird sie erst, wenn sie verschickt und die Frist um ist.
    ok(!\WebAtze\Build\DocumentBuilder::isOverdue($doc2), 'Ein Entwurf ist nie überfällig');

    \WebAtze\Core\Db::update('documents',
        ['status' => 'sent', 'due_on' => date('Y-m-d', strtotime('-1 day'))],
        'id = :id', ['id' => (int) $rechnung]);

    ok(\WebAtze\Build\DocumentBuilder::isOverdue(
        \WebAtze\Build\DocumentBuilder::find((int) $rechnung)
    ), 'Verschickt und Frist vorbei: überfällig');

    \WebAtze\Build\DocumentBuilder::setStatus((int) $rechnung, 'paid');
    $bezahlt = \WebAtze\Build\DocumentBuilder::find((int) $rechnung);

    ok(!\WebAtze\Build\DocumentBuilder::isOverdue($bezahlt), 'Bezahlt ist nicht mehr überfällig');
    is(date('Y-m-d'), (string) $bezahlt['paid_on'], 'Und der Tag steht fest');

    \WebAtze\Core\Db::delete('documents', '1 = 1', []);
});

// ==================================================================
test('Der Fragebogen nimmt nur an, was hineingehört', function (): void {
    $neu = \WebAtze\Domain\Questionnaire::create('Muster AG', 'a@b.ch', 'Notiz');
    $row = \WebAtze\Domain\Questionnaire::byToken($neu['token']);

    ok($row !== null, 'Der Verweis findet den Fragebogen');
    is(null, \WebAtze\Domain\Questionnaire::byToken('zu-kurz'), 'Ein kurzer Verweis nicht');
    is(null, \WebAtze\Domain\Questionnaire::byToken(str_repeat('a', 48)), 'Ein erfundener auch nicht');

    // Pflichtfelder.
    $ergebnis = \WebAtze\Domain\Questionnaire::store($row, ['company_name' => 'Nur der Name']);
    ok(!$ergebnis['ok'], 'Ohne Beschreibung geht es nicht durch');
    ok(isset($ergebnis['errors']['description']), 'Und es steht dabei, was fehlt');

    // Eine erfundene Auswahl darf nicht durchkommen.
    $ergebnis = \WebAtze\Domain\Questionnaire::store($row, [
        'company_name' => 'Muster AG',
        'industry' => 'Schreinerei',
        'description' => str_repeat('Wir bauen Möbel. ', 3),
        'contact_email' => 'kunde@example.org',
        'tone' => 'erfunden',
        'style' => 'clean',
        'services' => "Küchen\nSchränke",
    ]);

    ok($ergebnis['ok'], 'Mit allem Nötigen geht es durch');

    $gespeichert = \WebAtze\Domain\Questionnaire::byToken($neu['token']);
    is('', (string) $gespeichert['answers']['tone'], 'Die erfundene Auswahl wurde verworfen');
    is('submitted', (string) $gespeichert['status'], 'Der Zustand steht auf ausgefüllt');

    // Eine ungültige E-Mail wird abgewiesen.
    $ergebnis = \WebAtze\Domain\Questionnaire::store($row, [
        'company_name' => 'Muster AG',
        'industry' => 'Schreinerei',
        'description' => str_repeat('Wir bauen Möbel. ', 3),
        'contact_email' => 'keine-adresse',
    ]);
    ok(!$ergebnis['ok'], 'Eine kaputte E-Mail-Adresse kommt nicht durch');

    // Die Übernahme ins Formular.
    $brief = \WebAtze\Domain\Questionnaire::toBrief((array) $gespeichert['answers']);

    is('Muster AG', $brief['company_name'], 'Der Name wandert ins Formular');
    ok(str_contains($brief['extra_notes'], 'Küchen'), 'Das Angebot landet in den Zusatzinfos');
    ok(str_contains($brief['extra_notes'], 'Angebot:'), 'Mit einer Überschrift davor');

    \WebAtze\Core\Db::delete('questionnaires', 'id = :id', ['id' => (int) $row['id']]);
});

// ==================================================================
test('Buchung: eine belegte Zeit blockiert alles, was hineinragt', function (): void {
    require_once dirname(__DIR__) . '/public_html/app/Kit/site/php/buchen-zeiten.php';

    // Ein Dienstag. Offen 09:00–12:00 und 13:30–18:00, Takt 15 Minuten.
    $tag = '2026-09-01';
    $zeiten = [2 => ['09:00-12:00', '13:30-18:00']];
    $jetzt = strtotime('2026-08-25 08:00');

    $frei = freieZeitenAus([], $tag, 45, 15, 0, $zeiten, [], $jetzt);

    is('09:00', $frei[0], 'Der erste Takt liegt am Anfang des Fensters');
    ok(in_array('11:15', $frei, true), '11:15 passt noch: 11:15 + 45 = 12:00');
    ok(!in_array('11:30', $frei, true), '11:30 nicht mehr – das ragte über 12:00 hinaus');
    ok(in_array('17:15', $frei, true), 'Und abends bis 17:15');
    ok(!in_array('17:30', $frei, true), 'Aber nicht darüber');
    ok(!in_array('12:15', $frei, true), 'In der Mittagspause geht nichts');

    // Jetzt eine Buchung von 14:00 bis 14:45.
    $belegt = [['tag' => $tag, 'zeit' => '14:00', 'dauer' => 45, 'zustand' => 'offen']];
    $frei = freieZeitenAus($belegt, $tag, 45, 15, 0, $zeiten, [], $jetzt);

    ok(!in_array('14:00', $frei, true), 'Die gebuchte Zeit ist weg');

    // Das ist der Fehler, den man leicht macht: Nur den Anfangszeitpunkt
    // zu vergleichen liesse 13:30 stehen – und 13:30 + 45 Minuten läuft
    // mitten in den bestehenden Termin hinein.
    ok(!in_array('13:30', $frei, true), '13:30 auch – es ragte hinein');
    ok(!in_array('13:45', $frei, true), '13:45 ebenso');
    ok(in_array('13:15', $frei, true) === false, '13:15 liegt vor dem Fenster');
    ok(in_array('14:45', $frei, true), 'Direkt danach geht es weiter');
    ok(in_array('11:00', $frei, true), 'Der Vormittag bleibt unberührt');

    // Eine abgesagte Buchung gibt die Zeit wieder frei.
    $abgesagt = [['tag' => $tag, 'zeit' => '14:00', 'dauer' => 45, 'zustand' => 'abgesagt']];
    ok(in_array('14:00', freieZeitenAus($abgesagt, $tag, 45, 15, 0, $zeiten, [], $jetzt), true),
        'Abgesagt heisst wieder frei');

    // Eine längere Leistung passt an weniger Stellen.
    $lang = freieZeitenAus($belegt, $tag, 90, 15, 0, $zeiten, [], $jetzt);
    ok(!in_array('13:30', $lang, true), '90 Minuten passen vor dem Termin nicht mehr');
    ok(in_array('14:45', $lang, true), 'Danach schon');
    ok(!in_array('16:45', $lang, true), 'Und nicht über 18:00 hinaus');

    // Geschlossene Tage.
    is([], freieZeitenAus([], '2026-09-06', 45, 15, 0, $zeiten, [], $jetzt),
        'Am Sonntag ist zu – dafür steht kein Fenster in der Liste');
    is([], freieZeitenAus([], $tag, 45, 15, 0, $zeiten, ['2026-09-01'], $jetzt),
        'Ein einzelner geschlossener Tag ebenfalls');

    // Der Vorlauf.
    $knapp = freieZeitenAus([], $tag, 45, 15, 24, $zeiten, [], strtotime('2026-08-31 14:00'));
    ok(!in_array('09:00', $knapp, true), 'Mit 24 Stunden Vorlauf fällt der frühe Morgen weg');
    ok(in_array('14:15', $knapp, true), 'Was weiter weg liegt, bleibt');

    // Die Tagesliste überspringt geschlossene Tage.
    $tage = naechsteTage(24, 14, $zeiten, [], strtotime('2026-08-31 10:00'));
    is('2026-09-01', $tage[0], 'Der erste buchbare Tag ist der Dienstag');
    is('2026-09-08', $tage[1], 'Danach kommt gleich der nächste Dienstag');

    // Ein Datum ausserhalb des Bereichs wird nicht angenommen.
    $jetzt = strtotime('2026-08-31 10:00');
    is('', erlaubterTag('2026-08-30', 24, 30, $jetzt), 'Gestern geht nicht');
    is('', erlaubterTag('2027-01-01', 24, 30, $jetzt), 'Und zu weit weg auch nicht');
    is('', erlaubterTag('kein datum', 24, 30, $jetzt), 'Unsinn erst recht nicht');
    is('2026-09-05', erlaubterTag('2026-09-05', 24, 30, $jetzt), 'Was dazwischen liegt, schon');
});

// ==================================================================
test('Buchung wird nur gebaut, wenn sie beschrieben wurde', function (): void {
    $ohne = ['extra_notes' => 'Bitte modern und mit vielen Bildern.', 'description' => 'Wir bauen Möbel.'];
    $mit = ['extra_notes' => 'Die Kunden sollen online einen Termin buchen können.', 'description' => ''];

    ok(!\WebAtze\Build\BookingKit::wanted($ohne), 'Ohne Beschreibung kein Buchungssystem');
    ok(\WebAtze\Build\BookingKit::wanted($mit), 'Mit schon');

    // Die Öffnungszeiten aus dem Freitext.
    $zeiten = \WebAtze\Build\BookingKit::hours("Mo–Fr 08:00–12:00, 13:30–18:00\nSa 09:00–13:00");

    is(['08:00-12:00', '13:30-18:00'], $zeiten[1], 'Montag mit zwei Fenstern');
    is(['09:00-13:00'], $zeiten[6], 'Samstag mit einem');
    ok(!isset($zeiten[7]), 'Sonntag steht nicht darin – dann ist zu');

    $einzeln = \WebAtze\Build\BookingKit::hours("Mo, Di, Do 08:00-17:00\nMi geschlossen\nFr 08:00-15:00");
    ok(isset($einzeln[1], $einzeln[2], $einzeln[4], $einzeln[5]), 'Einzelne Tage werden erkannt');
    ok(!isset($einzeln[3]), 'Der Mittwoch bleibt draussen');

    // Was nicht zu lesen ist, wird nicht geraten.
    $vorgabe = \WebAtze\Build\BookingKit::hours('Wir sind eigentlich immer da.');
    is(['09:00-17:00'], $vorgabe[1], 'Sonst greift Montag bis Freitag, 9 bis 17');
    ok(!isset($vorgabe[6]), 'Und am Wochenende ist zu');
});

// ==================================================================
test('Der Name der SQLite-Datei lässt sich nicht erraten', function (): void {
    // Die Datei liegt im Web-Verzeichnis. Gesperrt wird sie durch
    // storage/.htaccess – aber die greift bei nginx gar nicht, und
    // anders als eine PHP-Datei kann eine Datenbankdatei keinen
    // Wächter tragen: Sie muss mit "SQLite format 3" beginnen.
    // Bleibt der Name.
    $install = (string) file_get_contents(dirname(__DIR__) . '/public_html/install.php');

    ok(!str_contains($install, "storage/webatze.sqlite'"),
        'install.php schreibt keinen festen Dateinamen');
    ok(str_contains($install, "'/storage/db-' . bin2hex(random_bytes(8))"),
        'Sondern hängt Zufall an');

    // Und die Absperrungen stehen trotzdem.
    $htaccess = (string) file_get_contents(dirname(__DIR__) . '/public_html/storage/.htaccess');
    ok(str_contains($htaccess, 'Require all denied'), 'storage/ ist per .htaccess gesperrt');

    ok(is_file(dirname(__DIR__) . '/public_html/storage/index.php'),
        'Und trägt zusätzlich einen Wächter');
    ok(is_file(dirname(__DIR__) . '/public_html/app/.htaccess'), 'app/ ebenso');
});

// ==================================================================
test('Der fehlende Arbeitsbereich wird erkannt und behoben', function (): void {
    // Der Fehler aus dem Betrieb, wortwörtlich:
    $echt = 'anthropic-workspace-id is required when authenticating with an '
        . 'identity-linked API key; send the id of the workspace this request acts in.';

    ok(ClaudeClient::isWorkspaceMissing(400, $echt), 'Die echte Meldung wird erkannt');
    ok(ClaudeClient::isWorkspaceMissing(400, 'workspace_id_required'),
        'Der Fehlercode allein genügt auch');
    ok(ClaudeClient::isWorkspaceMissing(400, mb_strtoupper($echt)),
        'Gross- und Kleinschreibung spielt keine Rolle');

    // Und was nicht dazugehört, wird nicht dafür gehalten.
    ok(!ClaudeClient::isWorkspaceMissing(400, 'max_tokens must be greater than 0'),
        'Ein anderer 400er nicht');
    ok(!ClaudeClient::isWorkspaceMissing(401, $echt), 'Ein 401 ebenfalls nicht');
    ok(!ClaudeClient::isWorkspaceMissing(500, $echt), 'Und ein Serverfehler auch nicht');

    // Die Kennung wird mitgeschickt, sobald sie bekannt ist.
    \WebAtze\Core\Settings::put('anthropic_workspace_id', 'wrkspc_01TESTTESTTEST');
    is('wrkspc_01TESTTESTTEST', ClaudeClient::workspaceId(), 'Sie wird aus den Einstellungen gelesen');

    $quelle = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Ai/ClaudeClient.php'
    );

    ok(str_contains($quelle, "'anthropic-workspace-id: ' . \$workspace"),
        'Und als Kopfzeile an jede Anfrage gehängt');

    \WebAtze\Core\Settings::put('anthropic_workspace_id', '');
    is('', ClaudeClient::workspaceId(), 'Leer heisst: keine Kopfzeile');

    // Wer die Kennung sucht, findet sie in der Adresszeile – und fügt
    // dann die ganze Adresse ein. Das darf nicht scheitern.
    $rein = static fn (string $v): string => ClaudeClient::cleanWorkspaceId($v);

    is('wrkspc_01ABCdef123', $rein('wrkspc_01ABCdef123'), 'Die blosse Kennung');
    is('wrkspc_01ABCdef123', $rein('https://platform.claude.com/workspaces/wrkspc_01ABCdef123/usage'),
        'Die ganze Adresse aus der Konsole');
    is('wrkspc_01ABCdef123', $rein('  wrkspc_01ABCdef123  '), 'Mit Leerzeichen drumherum');
    is('', $rein('https://platform.claude.com/dashboard'), 'Eine Adresse ohne Kennung');
    is('', $rein('mein arbeitsbereich'), 'Fliesstext');
    is('', $rein('<script>alert(1)</script>'), 'Und Unfug erst recht');

    // Der zweite Wortlaut derselben Sache. Ihn nicht zu erkennen war ein
    // Fehler mit Folgen: Der abgewiesene Wert blieb stehen und liess von
    // da an jede Anfrage scheitern.
    ok(ClaudeClient::isWorkspaceMissing(400,
        'anthropic-workspace-id header must be a valid workspace ID'),
        '"muss gültig sein" zählt genauso wie "fehlt"');
    ok(ClaudeClient::isWorkspaceMissing(400,
        'The anthropic-workspace-id header must be a valid workspace ID.'),
        'Auch als ganzer Satz');

    $quelle = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Ai/ClaudeClient.php'
    );

    // "default" ist nachweislich keine gültige Kennung – die
    // Schnittstelle hat es abgelehnt. Also wird nicht mehr geraten.
    ok(!str_contains($quelle, "LAST_RESORT"),
        'Es wird keine Kennung mehr geraten');

    ok(str_contains($quelle, 'if (self::wasGuessed()) {'),
        'Eine frühere Vermutung wird trotzdem wieder entfernt');

    // Und der Weg, der zum Ziel führt: mit einem Admin-Schlüssel fragen.
    ok(str_contains($quelle, 'public static function lookupWith('),
        'Nachschlagen mit einem Admin-Schlüssel ist möglich');
    ok(!str_contains($quelle, "remember('admin_key'"),
        'Der Admin-Schlüssel wird nirgends abgelegt');
});

// ==================================================================
test('Ein Einstellungsfehler wird nicht ewig wiederholt', function (): void {
    // Das war der eigentliche Ärger: Eine falsche Einstellung ist kein
    // vorübergehender Fehler. Sie alle dreissig Sekunden erneut zu
    // versuchen bringt nichts und hört nie auf.
    $ref = new ReflectionMethod(\WebAtze\Core\Worker::class, 'looksTemporary');
    $ref->setAccessible(true);

    $einstellung = new \WebAtze\Core\ConfigurationError(
        'Der Schlüssel gehört zu einer Person.',
        'Trag den Arbeitsbereich ein.',
        'anthropic_workspace_id'
    );

    ok(!$ref->invoke(null, $einstellung), 'Ein Einstellungsfehler wird nicht wiederholt');

    // Was wirklich vorübergehend ist, schon.
    ok($ref->invoke(null, new RuntimeException('Connection timed out')),
        'Eine Zeitüberschreitung dagegen schon');
    ok($ref->invoke(null, new RuntimeException('HTTP 503: overloaded')),
        'Ein überlasteter Server ebenfalls');
    ok(!$ref->invoke(null, new InvalidArgumentException('falscher Wert')),
        'Ein Programmierfehler nicht');

    // Die Anweisung gehört zur Meldung – eine Fehlermeldung ohne
    // Anweisung lässt jemanden ratlos zurück.
    ok(str_contains($einstellung->full(), 'Trag den Arbeitsbereich ein.'),
        'Die Meldung enthält, was zu tun ist');
    is('anthropic_workspace_id', $einstellung->field(), 'Und welches Feld gemeint ist');

    // Ein angehaltener Auftrag lässt sich fortsetzen, ohne von vorne
    // zu beginnen – sonst wäre schon bezahlte Arbeit verloren.
    $jobId = \WebAtze\Core\Jobs::enqueue('build', ['test' => 1], null);
    \WebAtze\Core\Jobs::progress($jobId, 'inhalte', 62, 'Texte werden geschrieben', ['seite' => 3]);
    \WebAtze\Core\Jobs::fail($jobId, 'Arbeitsbereich fehlt.', false, 'Angehalten.');

    $job = \WebAtze\Core\Jobs::find($jobId);
    is('failed', (string) $job['status'], 'Der Auftrag steht');
    is('Angehalten.', (string) $job['message'], 'Mit einer Meldung, die etwas sagt');

    ok(\WebAtze\Core\Jobs::retry($jobId), 'Er lässt sich fortsetzen');

    $job = \WebAtze\Core\Jobs::find($jobId);
    is('queued', (string) $job['status'], 'Danach steht er wieder in der Warteschlange');
    is('inhalte', (string) $job['step'], 'Beim Schritt, an dem es klemmte');
    is(3, (int) ($job['state']['seite'] ?? 0), 'Und mit dem Zwischenstand von vorher');
    is(0, (int) $job['attempts'], 'Die Versuchszählung beginnt neu');

    ok(!\WebAtze\Core\Jobs::retry($jobId), 'Ein laufender Auftrag lässt sich nicht fortsetzen');

    \WebAtze\Core\Db::delete('jobs', 'id = :id', ['id' => $jobId]);
});

// ==================================================================
test('Kein Schema enthält, was die strukturierte Ausgabe ablehnt', function (): void {
    // Die Schnittstelle kennt keine Grössenangaben und weist die ganze
    // Anfrage ab: "For 'array' type, property 'maxItems' is not
    // supported". Die fertigen Bibliotheken entfernen sie still; hier
    // läuft es über cURL, also muss es selbst geschehen.
    $seal = new ReflectionMethod(ClaudeClient::class, 'sealSchema');
    $seal->setAccessible(true);

    $verboten = ['minItems', 'maxItems', 'uniqueItems', 'contains', 'minContains',
                 'maxContains', 'prefixItems', 'minimum', 'maximum', 'exclusiveMinimum',
                 'exclusiveMaximum', 'multipleOf', 'minLength', 'maxLength', 'pattern',
                 'minProperties', 'maxProperties', 'propertyNames'];

    $suchen = static function (array $s, array $verboten, string $pfad = '$') use (&$suchen): array {
        $treffer = [];
        foreach ($s as $k => $v) {
            if (is_string($k) && in_array($k, $verboten, true)) {
                $treffer[] = $pfad . '.' . $k;
            }
            if (is_array($v)) {
                $treffer = array_merge($treffer, $suchen($v, $verboten, $pfad . '.' . $k));
            }
        }
        return $treffer;
    };

    // Alle Schemas, die tatsächlich verschickt werden.
    $schemas = [
        'Seitenplan' => \WebAtze\Ai\JsonSchema::sitePlan(),
        'Seiteninhalt' => \WebAtze\Ai\JsonSchema::pageContent([
            ['type' => 'hero'], ['type' => 'services'], ['type' => 'contact'],
        ]),
        'Abschnitt' => \WebAtze\Ai\JsonSchema::sectionContent('hero'),
        'Referenztext' => \WebAtze\Ai\JsonSchema::reference(),
        'Übersetzung' => \WebAtze\Ai\JsonSchema::translation([
            ['type' => 'hero'], ['type' => 'text'],
        ]),
    ];

    foreach ($schemas as $name => $schema) {
        $übrig = $suchen($seal->invoke(null, $schema), $verboten);
        ok($übrig === [], $name . ': nichts Unerlaubtes mehr'
            . ($übrig === [] ? '' : ' (' . implode(', ', $übrig) . ')'));
    }

    // Die Absicht darf nicht verlorengehen – sie wandert in die
    // Beschreibung, wo das Modell sie liest.
    $plan = $seal->invoke(null, \WebAtze\Ai\JsonSchema::sitePlan());
    $text = (string) ($plan['properties']['pages']['description'] ?? '');

    ok(str_contains($text, 'Höchstens 12'), 'Die Obergrenze steht als Satz in der Beschreibung');
    ok(str_contains($text, 'Mindestens 1 Eintrag'), 'Und zwar in richtigem Deutsch – Einzahl');

    // Was gebraucht wird, bleibt stehen.
    ok(($plan['additionalProperties'] ?? null) === false, 'additionalProperties bleibt false');
    ok(isset($plan['required']), 'required bleibt');

    // Auch in Verzweigungen wird aufgeräumt. Das fehlte zuerst.
    $mitAnyOf = $seal->invoke(null, [
        'type' => 'object',
        'properties' => [
            'x' => ['anyOf' => [
                ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 5],
                ['type' => 'string', 'maxLength' => 10],
            ]],
        ],
    ]);

    is([], $suchen($mitAnyOf, $verboten), 'Auch unter anyOf ist nichts übrig');

    // anyOf selbst bleibt – das ist keine Grenze, sondern die Bedeutung.
    ok(isset($mitAnyOf['properties']['x']['anyOf']), 'anyOf selbst bleibt erhalten');
});

// ==================================================================
test('Das Seitenschema bleibt klein genug für die Grammatik', function (): void {
    // Hier stand eine Liste, deren Einträge über "anyOf" jede
    // Abschnittsform annehmen durften. Die Schnittstelle übersetzt das
    // in eine Grammatik, und "jede Form an jeder Stelle" wächst mit dem
    // Produkt: Ab acht Abschnitten kam "the compiled grammar is too
    // large". Ausserdem war es gar nicht gemeint – die Reihenfolge steht
    // ja fest.
    $seal = new ReflectionMethod(ClaudeClient::class, 'sealSchema');
    $seal->setAccessible(true);

    $typen = ['header', 'hero', 'services', 'about', 'gallery',
              'testimonials', 'faq', 'cta', 'contact', 'footer'];

    $sections = [];
    foreach ($typen as $t) {
        $sections[] = ['type' => $t];
    }

    $schema = $seal->invoke(null, \WebAtze\Ai\JsonSchema::pageContent($sections));
    $json = json_encode($schema);

    is(0, substr_count($json, '"anyOf"'), 'Keine einzige Verzweigung mehr');
    ok(strlen($json) < 20000, 'Und das Schema bleibt handlich (' . strlen($json) . ' Zeichen)');

    // Jede Position hat genau eine erlaubte Form – das war der stillere
    // Fehler: "anyOf" liess an Position 3 die Form von Position 5 zu.
    $slots = $schema['properties']['sections'];

    is('object', $slots['type'], 'Ein Objekt statt einer Liste');
    is(10, count($slots['properties']), 'Ein Feld je Abschnitt');
    ok(isset($slots['properties']['abschnitt_0']), 'Benannt nach der Position');
    ok(isset($slots['properties']['abschnitt_9']), 'Auch der letzte');
    is(10, count($slots['required']), 'Alle werden verlangt');

    // Die Form je Position gehört zum Typ, der dort steht.
    ok(isset($slots['properties']['abschnitt_1']['properties']['eyebrow']),
        'Position 1 trägt die Form von "hero"');
    ok(!isset($slots['properties']['abschnitt_1']['properties']['questions']),
        'Und nicht die von "faq"');

    // Die Übersetzung benutzt dieselbe Form.
    $üb = $seal->invoke(null, \WebAtze\Ai\JsonSchema::translation($sections));
    is(0, substr_count(json_encode($üb), '"anyOf"'), 'Auch bei der Übersetzung keine Verzweigung');
    ok(isset($üb['properties']['sections']['properties']['abschnitt_0']),
        'Und dieselben Feldnamen');

    // Der Rückweg: aus der Antwort wieder Positionen machen.
    $antwort = ['sections' => [
        'abschnitt_0' => ['title' => 'Erster'],
        'abschnitt_2' => ['title' => 'Dritter'],
        'quatsch' => ['title' => 'gehört nicht dazu'],
    ]];

    $byIndex = [];
    foreach ((array) $antwort['sections'] as $key => $content) {
        if (is_array($content) && preg_match('/^abschnitt_(\d+)$/', (string) $key, $m)) {
            $byIndex[(int) $m[1]] = $content;
        }
    }

    is([0, 2], array_keys($byIndex), 'Die Nummer steckt im Namen');
    is('Dritter', $byIndex[2]['title'], 'Und zeigt auf den richtigen Inhalt');
});

// ==================================================================
test('Lange Seiten werden in Gruppen aufgeteilt', function (): void {
    // Ein festes Feld je Abschnitt war nur die halbe Miete. Am echten
    // Dienst gemessen: fünf Abschnitte gehen durch, sechs nicht mehr –
    // "the compiled grammar is too large". Die Grenze hängt am Umfang,
    // nicht an der Anzahl. Also wird gemessen und aufgeteilt.
    $typen = ['header', 'hero', 'services', 'about', 'gallery',
              'testimonials', 'faq', 'cta', 'contact', 'footer'];

    $sections = [];
    foreach ($typen as $t) {
        $sections[] = ['type' => $t];
    }

    $gruppen = \WebAtze\Ai\JsonSchema::chunkSections($sections);

    ok(count($gruppen) > 1, 'Zehn Abschnitte passen nicht in eine Anfrage'
        . ' (' . count($gruppen) . ' Gruppen)');

    // Keiner darf verlorengehen, keiner doppelt vorkommen.
    $gesehen = [];
    foreach ($gruppen as $gruppe) {
        ok($gruppe !== [], 'Keine leere Gruppe');

        foreach ($gruppe as $index => $section) {
            ok(!isset($gesehen[$index]), 'Abschnitt ' . $index . ' kommt nur einmal vor');
            $gesehen[$index] = $section['type'];
        }
    }

    is(array_keys($sections), array_keys($gesehen), 'Alle Abschnitte sind dabei');
    is($typen, array_values($gesehen), 'Und in ihrer ursprünglichen Reihenfolge');

    // Der eigentliche Punkt: Jede einzelne Anfrage bleibt klein genug.
    // Der Messwert stammt aus dem Versuch am echten Dienst – 7246
    // Zeichen gingen durch, 8533 nicht.
    $seal = new ReflectionMethod(ClaudeClient::class, 'sealSchema');
    $seal->setAccessible(true);

    // Die Uebersetzung teilt mit einem eigenen, kleineren Budget auf -
    // ihr Schema traegt zusaetzlich Titel und Kurzbeschreibung.
    foreach (\WebAtze\Ai\JsonSchema::chunkSections($sections, 6200) as $nummer => $gruppe) {
        $länge = strlen((string) json_encode(
            $seal->invoke(null, \WebAtze\Ai\JsonSchema::translation($gruppe))
        ));

        ok($länge < 7000, 'Übersetzung der Gruppe ' . ($nummer + 1) . ' bleibt sicher darunter'
            . ' (' . $länge . ' Zeichen)');
    }

    foreach ($gruppen as $nummer => $gruppe) {
        $länge = strlen((string) json_encode(
            $seal->invoke(null, \WebAtze\Ai\JsonSchema::pageContent($gruppe))
        ));

        ok($länge < 7500, 'Inhalt der Gruppe ' . ($nummer + 1) . ' bleibt unter der Grenze'
            . ' (' . $länge . ' Zeichen)');
    }

    // Die Feldnamen tragen weiter die ursprüngliche Nummer – sonst
    // landet die Antwort der zweiten Gruppe an der falschen Stelle.
    $letzte = end($gruppen);
    $erster = (int) array_key_first($letzte);

    ok($erster > 0, 'Die letzte Gruppe fängt nicht bei null an');
    ok(isset(\WebAtze\Ai\JsonSchema::pageContent($letzte)['properties']['sections']
        ['properties']['abschnitt_' . $erster]),
        'Sie heisst weiter abschnitt_' . $erster);

    // Sonderfälle: nichts, einer, und einer der allein zu gross ist.
    is([], \WebAtze\Ai\JsonSchema::chunkSections([]), 'Ohne Abschnitte keine Gruppe');
    is(1, count(\WebAtze\Ai\JsonSchema::chunkSections([['type' => 'hero']])),
        'Ein Abschnitt bleibt eine Gruppe');
    is(1, count(\WebAtze\Ai\JsonSchema::chunkSections([['type' => 'contact']], 10)),
        'Ein zu grosser Abschnitt wird nicht weggelassen');
});

// ==================================================================
test('Ein Abbruch mitten in der Seite wirft nichts weg', function (): void {
    // Der teure Fehler: Die Seite wurde erst gespeichert, wenn alle
    // Gruppen geschrieben waren. Wurde der Vorgang vorher abgeschossen -
    // und PHPs Zeitlimit tat genau das - war die bezahlte Arbeit weg,
    // der Zaehler stand weiter auf null, und der naechste Durchlauf fing
    // dieselbe Seite von vorn an. Ohne Fehlermeldung, aber mit Rechnung.
    $projectId = (int) \WebAtze\Core\Db::insert('projects', [
        'name' => 'Abbruchtest', 'slug' => 'abbruchtest-' . bin2hex(random_bytes(4)),
        'status' => 'building', 'created_at' => \WebAtze\Core\Db::now(),
        'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $seite = [
        'path' => '/', 'title' => 'Start', 'meta_description' => '',
        'in_navigation' => true,
        'sections' => [
            ['type' => 'hero', 'template' => 'hero-01', 'purpose' => ''],
            ['type' => 'services', 'template' => 'services-01', 'purpose' => ''],
            ['type' => 'faq', 'template' => 'faq-01', 'purpose' => ''],
        ],
    ];

    $writer = new \WebAtze\Ai\ContentWriter($projectId);

    // Zweimal aufgerufen darf keine zweite Seitenzeile entstehen.
    $pageId = $writer->preparePage($projectId, $seite);
    is($pageId, $writer->preparePage($projectId, $seite), 'Dieselbe Seite wird wiederverwendet');

    $zeilen = \WebAtze\Core\Db::all(
        'SELECT id FROM project_pages WHERE project_id = :p', ['p' => $projectId]
    );
    is(1, count($zeilen), 'Und es bleibt bei einer Zeile');

    // Erste Gruppe schreiben - das ist der Punkt: Sie steht sofort da,
    // nicht erst wenn die ganze Seite fertig ist.
    $gruppen = $writer->groupsFor($seite);
    ok($gruppen !== [], 'Die Seite hat mindestens eine Gruppe');

    $writer->writeGroup($projectId, $pageId, $seite, [], '', 0);

    $nachErster = \WebAtze\Core\Db::all(
        'SELECT sort_order FROM project_sections WHERE page_id = :p ORDER BY sort_order',
        ['p' => $pageId]
    );

    ok(count($nachErster) > 0, 'Nach der ersten Gruppe steht schon etwas in der Datenbank');

    // Jetzt so tun, als waere hier abgebrochen und neu begonnen worden:
    // dieselbe Gruppe noch einmal. Es darf nichts doppelt entstehen.
    $writer->writeGroup($projectId, $pageId, $seite, [], '', 0);

    $nachWiederholung = \WebAtze\Core\Db::all(
        'SELECT sort_order FROM project_sections WHERE page_id = :p ORDER BY sort_order',
        ['p' => $pageId]
    );

    is(count($nachErster), count($nachWiederholung), 'Eine Wiederholung legt nichts doppelt an');

    // Die uebrigen Gruppen nachziehen - am Ende ist die Seite komplett.
    foreach (array_keys($gruppen) as $nummer) {
        $writer->writeGroup($projectId, $pageId, $seite, [], '', (int) $nummer);
    }

    $fertig = \WebAtze\Core\Db::all(
        'SELECT sort_order, type FROM project_sections WHERE page_id = :p ORDER BY sort_order',
        ['p' => $pageId]
    );

    is(3, count($fertig), 'Am Ende stehen alle drei Abschnitte da');
    is([0, 1, 2], array_map(static fn (array $r): int => (int) $r['sort_order'], $fertig),
        'Jede Position genau einmal');
    is(['hero', 'services', 'faq'], array_map(static fn (array $r): string => (string) $r['type'], $fertig),
        'Und in der richtigen Reihenfolge');

    \WebAtze\Core\Db::delete('project_sections', 'project_id = :p', ['p' => $projectId]);
    \WebAtze\Core\Db::delete('project_pages', 'project_id = :p', ['p' => $projectId]);
    \WebAtze\Core\Db::delete('projects', 'id = :p', ['p' => $projectId]);
});

// ==================================================================
test('Monatliche Posten werden jeden Monat wieder faellig', function (): void {
    // Der Kern der Kundenverwaltung. Ein Kunde hat mehrere Kostenposten
    // mit eigenem Rhythmus: Aufbau einmal, Domain jaehrlich, Wartung
    // monatlich. "Zuruecksetzen" waere hier falsch - das wuerde die
    // Vergangenheit loeschen, und dann liesse sich nicht mehr sagen, ob
    // der Kunde im Mai bezahlt hat. Stattdessen gibt es je Periode einen
    // eigenen Eintrag.
    $kunde = (int) \WebAtze\Core\Db::insert('customers', [
        'name' => 'Probe ' . bin2hex(random_bytes(3)),
        'status' => 'aktiv',
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $posten = [];

    foreach ([['Aufbau', 'aufbau', 380000, 'einmalig'],
              ['Wartung', 'wartung', 4900, 'monatlich'],
              ['Domain', 'domain', 1800, 'jaehrlich']] as [$l, $a, $b, $i]) {
        $posten[$i] = (int) \WebAtze\Core\Db::insert('charges', [
            'customer_id' => $kunde, 'label' => $l, 'kind' => $a,
            'amount_rappen' => $b, 'billing_interval' => $i, 'active' => 1,
            'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
        ]);
    }

    $stand = \WebAtze\Domain\Billing::statusOf($kunde);

    is(386700, $stand['offen_rappen'], 'Am Anfang ist alles offen');
    is(4900, $stand['monatlich_rappen'], 'Die monatliche Summe stimmt');
    is(1800, $stand['jaehrlich_rappen'], 'Die jaehrliche auch');

    // Wartung abhaken.
    \WebAtze\Domain\Billing::markPaid($posten['monatlich']);
    $stand = \WebAtze\Domain\Billing::statusOf($kunde);
    is(381800, $stand['offen_rappen'], 'Nach dem Abhaken ist die Wartung raus');

    // Zweimal abhaken darf keine zweite Zahlung erzeugen - sonst stimmt
    // die Buchhaltung nicht mehr.
    \WebAtze\Domain\Billing::markPaid($posten['monatlich']);
    is(1, (int) \WebAtze\Core\Db::value(
        'SELECT COUNT(*) FROM payments WHERE charge_id = :c',
        ['c' => $posten['monatlich']], 0
    ), 'Zweimal abhaken bleibt eine Zahlung');

    // Naechster Monat: wieder offen, aber die Zahlung von diesem Monat
    // bleibt in der Historie.
    $naechster = date('Y-m-15', strtotime('+1 month') ?: time());
    $spaeter = \WebAtze\Domain\Billing::statusOf($kunde, $naechster);

    $wartung = null;
    foreach ($spaeter['posten'] as $p) {
        if ((string) $p['kind'] === 'wartung') { $wartung = $p; }
    }

    ok($wartung !== null && !$wartung['bezahlt'], 'Naechsten Monat ist die Wartung wieder offen');
    is(1, (int) \WebAtze\Core\Db::value(
        'SELECT COUNT(*) FROM payments WHERE charge_id = :c',
        ['c' => $posten['monatlich']], 0
    ), 'Und die Zahlung von diesem Monat steht noch in der Historie');

    // Das Jahr darauf gilt dasselbe fuer die Domain.
    \WebAtze\Domain\Billing::markPaid($posten['jaehrlich']);
    $naechstesJahr = date('Y-m-d', strtotime('+1 year') ?: time());
    $domain = null;

    foreach (\WebAtze\Domain\Billing::statusOf($kunde, $naechstesJahr)['posten'] as $p) {
        if ((string) $p['kind'] === 'domain') { $domain = $p; }
    }

    ok($domain !== null && !$domain['bezahlt'], 'Naechstes Jahr ist die Domain wieder offen');

    // Einmaliges bleibt bezahlt, fuer immer.
    \WebAtze\Domain\Billing::markPaid($posten['einmalig']);
    $aufbau = null;

    foreach (\WebAtze\Domain\Billing::statusOf($kunde, $naechstesJahr)['posten'] as $p) {
        if ((string) $p['kind'] === 'aufbau') { $aufbau = $p; }
    }

    ok($aufbau !== null && $aufbau['bezahlt'], 'Einmaliges bleibt bezahlt');

    // Ein Posten mit Enddatum in der Vergangenheit faellt nicht mehr an.
    \WebAtze\Core\Db::update('charges', ['ends_on' => date('Y-m-d', strtotime('-1 day') ?: time())],
        'id = :id', ['id' => $posten['monatlich']]);

    foreach (\WebAtze\Domain\Billing::statusOf($kunde)['posten'] as $p) {
        if ((string) $p['kind'] === 'wartung') {
            ok(!$p['faellig'], 'Ein gekuendigter Posten steht nicht mehr als offen da');
        }
    }

    // Rappen rein, Rappen raus.
    is(125050, \WebAtze\Domain\Billing::toRappen("1'250.50"), 'Betraege werden in Rappen gefuehrt');
    is(4900, \WebAtze\Domain\Billing::toRappen('49'), 'Auch ohne Nachkommastellen');
    is("1'250.50", \WebAtze\Domain\Billing::money(125050), 'Und wieder lesbar zurueck');

    \WebAtze\Core\Db::delete('payments', 'customer_id = :c', ['c' => $kunde]);
    \WebAtze\Core\Db::delete('charges', 'customer_id = :c', ['c' => $kunde]);
    \WebAtze\Core\Db::delete('customers', 'id = :c', ['c' => $kunde]);
});

// ==================================================================
test('Der Kalender ist ein vollstaendiges Raster', function (): void {
    // Ein Monat als Wochen zu sieben Tagen. Loecher im Raster faellt
    // sofort auf, deshalb sind die Tage aus Vor- und Folgemonat dabei.
    foreach (['2026-01', '2026-02', '2026-09', '2027-02'] as $monat) {
        $wochen = \WebAtze\Domain\Calendar::month($monat);

        ok(count($wochen) >= 4 && count($wochen) <= 6,
            $monat . ': vier bis sechs Wochen (' . count($wochen) . ')');

        foreach ($wochen as $nummer => $woche) {
            is(7, count($woche), $monat . ': Woche ' . ($nummer + 1) . ' hat sieben Tage');
        }

        // Jeder Tag des Monats muss genau einmal vorkommen.
        $eigene = [];

        foreach ($wochen as $woche) {
            foreach ($woche as $tag) {
                if (!$tag['fremd']) { $eigene[] = (int) $tag['nummer']; }
            }
        }

        $tageImMonat = (int) date('t', strtotime($monat . '-01') ?: time());

        is($tageImMonat, count($eigene), $monat . ': alle Tage sind da');
        is(range(1, $tageImMonat), $eigene, $monat . ': und jeder genau einmal, der Reihe nach');
    }

    // Die Woche beginnt am Montag - in der Schweiz beginnt sie am Montag.
    $erste = \WebAtze\Domain\Calendar::month('2026-09')[0][0];
    is('1', date('N', strtotime((string) $erste['datum']) ?: time()),
        'Die Woche faengt am Montag an');

    is('2025-12', \WebAtze\Domain\Calendar::previous('2026-01'), 'Der Monat davor stimmt');
    is('2027-01', \WebAtze\Domain\Calendar::next('2026-12'), 'Der danach auch');
    ok(str_contains(\WebAtze\Domain\Calendar::title('2026-09'), 'September'), 'Der Titel ist lesbar');
});

// ==================================================================
test('Die Word-Vorlage wird ausgelesen, nicht nachgebaut', function (): void {
    // Eine .docx laesst sich auf geteiltem Hosting nicht in ein PDF
    // verwandeln - dafuer braeuchte es LibreOffice auf dem Server. Also
    // der ehrliche Weg: Absenderzeilen und Logo herauslesen, den Rest
    // selbst setzen.
    $pfad = sys_get_temp_dir() . '/webatze-vorlage-' . bin2hex(random_bytes(4)) . '.docx';

    $zip = new ZipArchive();
    $zip->open($pfad, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $absaetze = ['WebAtze', 'Tobias Reymond', 'Musterstrasse 12', '3000 Bern'];
    $xml = '<?xml version="1.0"?><w:document xmlns:w="x"><w:body>';

    foreach ($absaetze as $a) {
        // Word zerlegt einen Satz gern in mehrere Stuecke - genau das
        // muss der Leser wieder zusammensetzen.
        $xml .= '<w:p><w:r><w:t>' . mb_substr($a, 0, 3) . '</w:t></w:r>'
            . '<w:r><w:t>' . mb_substr($a, 3) . '</w:t></w:r></w:p>';
    }

    $zip->addFromString('word/document.xml', $xml . '</w:body></w:document>');

    $bild = imagecreatetruecolor(300, 90);
    imagefilledrectangle($bild, 0, 0, 300, 90, imagecolorallocate($bild, 43, 27, 158));
    ob_start();
    imagepng($bild);
    $zip->addFromString('word/media/image1.png', (string) ob_get_clean());
    imagedestroy($bild);
    $zip->close();

    $vorher = (string) \WebAtze\Core\Settings::get('letterhead_lines', '');

    $ergebnis = \WebAtze\Domain\Letterhead::importTemplate($pfad, 'briefpapier.docx');

    ok($ergebnis['ok'], 'Die Vorlage wird angenommen');
    is($absaetze, $ergebnis['zeilen'], 'Die zerstueckelten Absaetze werden zusammengesetzt');
    ok($ergebnis['logo'], 'Und das Logo herausgeholt');
    ok(\WebAtze\Domain\Letterhead::hasLogo(), 'Es liegt danach bereit');
    ok(\WebAtze\Domain\Letterhead::hasTemplate(), 'Die Vorlage bleibt hinterlegt');

    // Das Logo muss JPEG sein - nur das wandert unveraendert ins PDF.
    $logo = \WebAtze\Domain\Letterhead::logoData();
    is("\xFF\xD8", substr($logo, 0, 2), 'Das Logo wurde nach JPEG gewandelt');

    // Und es muss sich wirklich in ein PDF setzen lassen.
    $pdf = new \WebAtze\Core\Pdf('Probe');
    $pdf->addPage();
    $hoehe = $pdf->image($logo, 56, 56, 120);

    ok($hoehe > 0, 'Das Bild hat eine Hoehe (' . round($hoehe, 1) . ')');

    $daten = $pdf->output();

    ok(str_contains($daten, 'DCTDecode'), 'Das PDF traegt das Bild');
    ok(str_contains($daten, '/XObject'), 'Und meldet es als Ressource an');
    ok(str_ends_with(trim($daten), '%%EOF'), 'Das PDF ist vollstaendig');

    // Eine .doc aus alten Word-Fassungen geht nicht - das muss gesagt
    // werden, nicht stillschweigend scheitern.
    $alt = \WebAtze\Domain\Letterhead::importTemplate($pfad, 'alt.doc');
    ok(!$alt['ok'], 'Eine .doc wird abgelehnt');
    ok(str_contains($alt['meldung'], '.docx'), 'Und der Weg genannt');

    @unlink($pfad);
    \WebAtze\Domain\Letterhead::removeLogo();
    \WebAtze\Domain\Letterhead::removeTemplate();
    \WebAtze\Core\Settings::put('letterhead_lines', $vorher);
});

// ==================================================================
test('Der Auftragstext ist vollstaendig', function (): void {
    // Der Weg ohne Schnittstelle: Das Formular erzeugt einen Text, der
    // von Hand eingefuegt wird. Damit faellt die ganze Kette weg, an der
    // es immer wieder haengenblieb - aber der Text muss dafuer alleine
    // tragen. Wer ihn einfuegt, hat das Formular nicht gesehen und kann
    // nicht nachfragen. Was fehlt, wird erfunden.
    $g = \WebAtze\Domain\Brief::validate([
        'company_name' => 'Bergstern Reisen AG',
        'slogan' => 'Die Alpen, wie sie wirklich sind',
        'industry' => 'Reiseveranstalter und Bergsport',
        'description' => 'Gefuehrte Wanderreisen und Skitouren in den Schweizer Alpen.',
        'audience' => 'Erfahrene Wanderer aus der Schweiz.',
        'style' => 'natural', 'tone' => 'sie', 'scope' => 'medium', 'locales' => 'de,fr',
        'color_mode' => 'manual',
        'color_primary' => '#14532D', 'color_secondary' => '#0C2A18', 'color_accent' => '#E8A33D',
        'contact_email' => 'info@bergstern.example',
        'contact_phone' => '081 250 40 40',
        'contact_address' => "Bahnhofplatz 4\n7000 Chur",
        'opening_hours' => 'Mo-Fr 08:00-18:00',
        'domain' => 'bergstern.example',
        'wants_admin' => '1', 'admin_username' => 'bergstern',
        'admin_password' => 'ein-langes-Testpasswort-2026',
        'wants_stats' => '1', 'wants_docs' => '1',
        'report_email' => 'info@bergstern.example',
        'wanted_pages' => 'Touren, Lawinenkurse, Ueber uns',
        'extra_notes' => 'Gaeste sollen online einen Platz auf einer Tour buchen koennen.',
    ]);

    ok($g['ok'], 'Die Probeeingaben sind gueltig');

    $brief = $g['data'];
    $text = \WebAtze\Domain\PromptText::build($brief);

    // Jede Angabe aus dem Formular muss vorkommen - sonst fehlt sie beim
    // Bauen und wird erfunden.
    foreach ([
        'Firmenname' => 'Bergstern Reisen AG',
        'Slogan' => 'Die Alpen, wie sie wirklich sind',
        'Branche' => 'Reiseveranstalter',
        'Beschreibung' => 'Gefuehrte Wanderreisen',
        'Zielgruppe' => 'Erfahrene Wanderer',
        'E-Mail' => 'info@bergstern.example',
        'Telefon' => '081 250 40 40',
        'Adresse' => 'Bahnhofplatz 4',
        'Oeffnungszeiten' => 'Mo-Fr 08:00-18:00',
        'Domain' => 'bergstern.example',
        'Hauptfarbe' => '#14532d',
        'Akzentfarbe' => '#e8a33d',
        'Wunschseiten' => 'Lawinenkurse',
        'Zusatzwunsch' => 'Platz auf einer Tour',
        'Sprachen' => 'Französisch',
        'Bearbeitungsbereich' => 'bergstern',
    ] as $was => $erwartet) {
        ok(str_contains($text, $erwartet), $was . ' steht im Auftrag');
    }

    // Das Passwort gehoert NICHT hinein - der Text wird kopiert und
    // weitergereicht.
    ok(!str_contains($text, 'ein-langes-Testpasswort-2026'),
        'Das Kennwort steht NICHT im Auftrag');

    // Die zwei Regeln, die nicht verhandelbar sind.
    ok(str_contains($text, 'Erfinde nichts über diese Firma'),
        'Der Auftrag verbietet Erfundenes');
    ok(str_contains($text, 'wie diese Website'),
        'Und jeden Hinweis auf die Entstehung');
    ok(str_contains($text, 'ss statt ß'), 'Schweizer Rechtschreibung wird verlangt');

    // Der Text selbst darf nirgends verraten, womit er gebaut wird.
    foreach (['claude', 'anthropic', 'gpt', 'openai', 'sprachmodell'] as $wort) {
        ok(!str_contains(mb_strtolower($text), $wort),
            'Kein Hinweis auf "' . $wort . '" im Auftrag');
    }

    // Angehakte Zusatzwuensche muessen als Auftrag dastehen.
    ok(str_contains($text, 'Besucherzählung gewünscht'), 'Die Besucherzählung ist beauftragt');
    ok(str_contains($text, 'Bearbeitungsbereich gewünscht'), 'Und der Bearbeitungsbereich');

    // Ohne Haken darf nichts davon dastehen.
    $ohne = $brief;
    $ohne['wants_admin'] = false;
    $ohne['wants_stats'] = false;

    $schlicht = \WebAtze\Domain\PromptText::build($ohne);

    ok(!str_contains($schlicht, 'Besucherzählung gewünscht'),
        'Ohne Haken keine Besucherzählung');
    ok(!str_contains($schlicht, 'Bearbeitungsbereich gewünscht'),
        'Ohne Haken kein Bearbeitungsbereich');

    // Der Dateiname fuers Herunterladen.
    is('auftrag-bergstern-reisen-ag.md', \WebAtze\Domain\PromptText::filename($brief),
        'Der Dateiname passt zur Firma');

    ok(mb_strlen($text) > 3000, 'Der Auftrag hat Substanz (' . mb_strlen($text) . ' Zeichen)');
});

// ==================================================================
test('Ohne Haken steht nichts davon im Auftrag', function (): void {
    // Anleitung, Hilfeseite und Zaehlung sind Entscheidungen. Wer sie
    // nicht anhakt, bekommt keine einzige Zeile davon - kein Kapitel im
    // Auftrag, keinen Schluessel, keinen Punkt in der Liste zum Abhaken.
    //
    // Der Unterschied zu frueher liegt nicht im Haken, sondern darin,
    // was dahinter passiert: Was angehakt IST, steht mit allen echten
    // Werten drin und laeuft ohne einen Handgriff.
    $karg = ['company_name' => 'Karg AG', 'contact_email' => 'a@b.ch'];

    $anschluss = [
        'url' => 'https://webatze.ch',
        'support_token' => 'T0kEnBeIsPiEl-vierundzwanzig',
        'support_code' => 'KRTPM-9XZQ4',
        'visit_key' => '3daa45e7b23822e3',
    ];

    $ohne = \WebAtze\Domain\PromptText::build($karg, $anschluss);

    foreach ([
        'Anleitung' => '/doc',
        'Hilfeseite' => '/support',
        'Zaehlzeile' => 'z.js?k=',
        'Konfigurationsdatei' => 'data/config.php',
        'Zugangscode' => 'KRTPM-9XZQ4',
        'Schluessel' => 'T0kEnBeIsPiEl-vierundzwanzig',
    ] as $was => $unerwuenscht) {
        ok(!str_contains($ohne, $unerwuenscht), $was . ' fehlt ohne Haken: ' . $unerwuenscht);
    }

    ok(mb_strlen($ohne) > 2000, 'Der Auftrag selbst steht trotzdem (' . mb_strlen($ohne) . ' Zeichen)');

    // Einzeln angehakt heisst einzeln drin - nicht alles oder nichts.
    $nurDoku = \WebAtze\Domain\PromptText::build($karg + ['wants_docs' => true], $anschluss);

    ok(str_contains($nurDoku, '### `/doc`'), 'Nur Anleitung: sie ist da');
    ok(!str_contains($nurDoku, '### `/support`'), 'Nur Anleitung: keine Hilfeseite');
    ok(!str_contains($nurDoku, 'z.js?k='), 'Nur Anleitung: keine Zaehlzeile');
    ok(!str_contains($nurDoku, 'data/config.php'),
        'Nur Anleitung: auch keine Konfigurationsdatei - sie braucht keine');

    $nurZaehlung = \WebAtze\Domain\PromptText::build($karg + ['wants_stats' => true], $anschluss);

    ok(str_contains($nurZaehlung, 'z.js?k=3daa45e7b23822e3'), 'Nur Zaehlung: die Zeile ist da');
    ok(str_contains($nurZaehlung, 'data/config.php'), 'Nur Zaehlung: mit Konfigurationsdatei');
    ok(!str_contains($nurZaehlung, 'KRTPM-9XZQ4'),
        'Nur Zaehlung: kein Zugangscode, den niemand braucht');
    ok(!str_contains($nurZaehlung, '### `/support`'), 'Nur Zaehlung: keine Hilfeseite');

    // Und das Formular liefert die Haken auch wirklich als Haken aus.
    $g = \WebAtze\Domain\Brief::validate([
        'company_name' => 'Karg AG',
        'industry' => 'Schreinerei',
        'description' => 'Moebel nach Mass aus einheimischem Holz.',
        'style' => 'clean', 'tone' => 'sie', 'scope' => 'small', 'locales' => 'de',
        'color_mode' => 'auto',
    ]);

    ok($g['ok'], 'Der karge Brief ist gueltig');
    ok(empty($g['data']['wants_docs']), 'Ohne Haken keine Anleitung');
    ok(empty($g['data']['wants_support']), 'Ohne Haken keine Hilfeseite');
    ok(empty($g['data']['wants_stats']), 'Ohne Haken keine Zaehlung');
});

// ==================================================================
test('Jede gebaute Seite zaehlt mit', function (): void {
    // Die Zaehlzeile stand frueher nur auf Seiten, bei denen im Formular
    // ein Haken gesetzt war - und der wurde vergessen. Jetzt gehoert sie
    // zu jeder gebauten Seite, so wie /doc und /support.
    $site = [
        'brand' => 'Probe AG',
        'locale' => 'de',
        'locales' => ['de'],
        'nav' => [],
        'visit_url' => 'https://webatze.ch',
        'visit_key' => 'abcdef0123456789',
    ];

    $seite = ['path' => '/', 'title' => 'Start', 'description' => 'Probe', 'sections' => []];

    $html = Page::render($site, $seite);

    ok(
        str_contains($html, '<script defer src="https://webatze.ch/z.js?k=abcdef0123456789"></script>'),
        'Die Zaehlzeile steht in der gebauten Seite'
    );

    ok(
        strpos($html, 'z.js') < strpos($html, '</body>'),
        'Und zwar vor dem schliessenden body'
    );

    // Ohne Schluessel keine Zeile - eine halbe Verdrahtung waere
    // schlimmer als gar keine.
    $ohne = Page::render(['brand' => 'Probe AG', 'locale' => 'de', 'locales' => ['de'], 'nav' => []], $seite);

    ok(!str_contains($ohne, 'z.js'), 'Ohne Schluessel steht dort nichts');
});

// ==================================================================
test('Der Schluessel im Auftrag kann nur wenig', function (): void {
    // Der Schluessel steht im Auftragstext, damit auf der Kundenwebsite
    // nichts einzurichten ist. Ein Auftragstext wird kopiert und
    // weitergereicht - also darf dieser Schluessel moeglichst wenig
    // koennen. Deshalb ist er nicht der assistant_token: Mit dem liesse
    // sich der Abschnitts-Editor ansteuern, und der kostet Geld.
    $id = \WebAtze\Core\Db::insert('projects', [
        'slug' => 'schluesselprobe', 'name' => 'Schluesselprobe AG', 'status' => 'ready',
        'source' => 'ki', 'locale' => 'de', 'locales' => 'de',
        'assistant_token' => random_token(24),
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $eigener = \WebAtze\Domain\Websites::supportToken($id);

    ok(strlen($eigener) >= 20, 'Der Schluessel ist lang genug');
    is($eigener, \WebAtze\Domain\Websites::supportToken($id), 'Beim zweiten Fragen derselbe');

    $assistent = (string) \WebAtze\Core\Db::value(
        'SELECT assistant_token FROM projects WHERE id = :id', ['id' => $id], ''
    );

    ok($eigener !== $assistent, 'Und ein anderer als der Assistenten-Schluessel');

    // Zuruecknehmen muss gehen, sonst waere ein einmal abgeflossener
    // Auftragstext ein dauerhaftes Problem.
    $neuer = \WebAtze\Domain\Websites::newSupportToken($id);

    ok($neuer !== $eigener, 'Ein neuer Schluessel ist wirklich neu');
    is($neuer, \WebAtze\Domain\Websites::supportToken($id), 'Und ab jetzt der gueltige');

    // Der Zugangscode ebenso.
    $code = \WebAtze\Domain\Websites::supportCode($id);
    ok(\WebAtze\Domain\Websites::newSupportCode($id) !== $code, 'Auch der Code laesst sich erneuern');

    \WebAtze\Core\Db::delete('projects', 'id = :id', ['id' => $id]);
});

// ==================================================================
test('Der Auftrag ist fertig verdrahtet', function (): void {
    // Beim ersten Anlauf hing /support an einem Haken, der vergessen
    // wurde. Beim zweiten stand es zwar im Auftrag, aber mit dem Satz
    // "den Code setze ich selbst" - auch das ein Handgriff, den jemand
    // vergisst. Dann steht eine Hilfeseite da, die nichts tut.
    //
    // Jetzt stehen die echten Werte im Text. Diese Pruefung haelt fest,
    // dass sie wirklich alle darin vorkommen - und dass der Auftrag
    // nirgends mehr behauptet, es sei noch etwas nachzutragen.
    $brief = [
        'company_name' => 'Steiner Holzbau AG',
        'contact_email' => 'info@steiner.example',
        'wants_docs' => true, 'wants_support' => true, 'wants_stats' => true,
    ];

    $anschluss = [
        'url' => 'https://webatze.ch',
        'support_token' => 'T0kEnBeIsPiEl-vierundzwanzig',
        'support_code' => 'KRTPM-9XZQ4',
        'visit_key' => '3daa45e7b23822e3',
    ];

    $text = \WebAtze\Domain\PromptText::build($brief, $anschluss);

    foreach ([
        'Adresse' => 'https://webatze.ch',
        'Schluessel' => 'T0kEnBeIsPiEl-vierundzwanzig',
        'Zugangscode' => 'KRTPM-9XZQ4',
        'Zaehlschluessel' => '3daa45e7b23822e3',
        'Konfigurationsdatei' => 'data/config.php',
        'Sendeadresse' => '/assistant/v1/support',
        'Verlaufsadresse' => '/assistant/v1/support/faden',
        'Kopfzeile' => 'X-WebAtze-Token',
    ] as $was => $erwartet) {
        ok(str_contains($text, $erwartet), $was . ' steht im Auftrag');
    }

    // Die Zaehlzeile steht fertig da - er soll nichts von Hand einfuegen.
    ok(
        str_contains($text, '<script defer src="https://webatze.ch/z.js?k=3daa45e7b23822e3"></script>'),
        'Die Zaehlzeile steht fertig im Auftrag'
    );

    ok(str_contains($text, 'auf JEDE Seite'), 'Und zwar auf jede Seite');

    // Kein Platzhalter mehr, nirgends.
    foreach (['WIRD-NACHGETRAGEN', 'BITTE-VOR-DEM-AUSLIEFERN-AENDERN'] as $rest) {
        ok(!str_contains($text, $rest), 'Kein Platzhalter mehr: ' . $rest);
    }

    ok(str_contains($text, 'nichts mehr einzurichten'),
        'Der Auftrag sagt ausdruecklich, dass nichts einzurichten ist');

    // Die drei Punkte stehen ganz vorne, nicht nur am Ende. Am Ende
    // einer langen Liste werden sie ueberlesen - genau das ist zweimal
    // passiert.
    $kopf = mb_substr($text, 0, 1200);

    ok(str_contains($kopf, '/doc'), 'Die Anleitung steht schon im Kopf des Auftrags');
    ok(str_contains($kopf, '/support'), 'Die Hilfeseite ebenfalls');
    ok(str_contains($kopf, 'Besucherzählung'), 'Und die Zaehlung');

    // Und noch einmal ganz am Schluss zum Abhaken.
    ok(str_contains($text, 'Zum Abhaken'), 'Am Schluss steht eine Liste zum Abhaken');

    // Der Schluessel im Auftrag ist der eng geschnittene, nicht der
    // Assistenten-Schluessel: Mit dem liesse sich der Abschnitts-Editor
    // ansteuern, und der kostet bei jedem Aufruf Geld.
    ok(!str_contains($text, '/assistant/v1/edit'),
        'Der Abschnitts-Editor steht nicht im Auftrag');

    // Das Kennwort des Kundenbackends gehoert weiterhin NICHT hinein.
    $mitBackend = \WebAtze\Domain\PromptText::build(
        $brief + ['wants_admin' => true, 'admin_username' => 'steiner',
                  'admin_password' => 'ein-langes-Testpasswort-2026'],
        $anschluss
    );

    ok(!str_contains($mitBackend, 'ein-langes-Testpasswort-2026'),
        'Das Kennwort des Kundenbackends steht weiterhin nicht im Auftrag');
});

// ==================================================================
test('Eine Seite kostet einen Aufruf, nicht drei', function (): void {
    // Der Umbau in einem Satz: Aus achtzig Aufrufen ueber vierzig
    // Prozessstarts wurden dreissig in einem Prozess. Nicht weil die
    // Aufrufe schneller sind, sondern weil es weniger sind - und jeder
    // eingesparte Aufruf ist auch eine Stelle weniger, an der etwas
    // haengenbleiben kann.
    //
    // Der Hebel: Eine Seite muss in EINE Anfrage passen. Passt sie
    // nicht, kostet sie zwei Anfragen zum Schreiben und zwei je Sprache
    // zum Uebersetzen - bei drei Sprachen sechs statt drei, fuer eine
    // einzige Seite.
    $brief = ['company_name' => 'Probe AG', 'scope' => 'large', 'locales' => 'de'];

    // Ein Plan mit absichtlich ueberladenen Seiten.
    $roh = ['pages' => []];

    foreach (['/', '/leistungen', '/kontakt', '/impressum'] as $pfad) {
        $roh['pages'][] = [
            'path' => $pfad,
            'title' => 'Probe',
            'meta_description' => 'Probe',
            'in_navigation' => true,
            'sections' => array_map(
                static fn (string $t): array => ['type' => $t, 'template' => '', 'purpose' => 'Probe'],
                ['hero', 'services', 'features', 'about', 'gallery',
                 'testimonials', 'team', 'process', 'stats', 'faq', 'contact', 'cta']
            ),
        ];
    }

    $plan = \WebAtze\Ai\SitePlanner::sanitise($roh, $brief);

    ok($plan['pages'] !== [], 'Der Plan hat Seiten');

    foreach ($plan['pages'] as $seite) {
        $abschnitte = (array) ($seite['sections'] ?? []);

        ok($abschnitte !== [], $seite['path'] . ': hat Abschnitte');

        $gruppen = \WebAtze\Ai\JsonSchema::chunkSections($abschnitte);

        is(1, count($gruppen), $seite['path'] . ': passt in eine Anfrage ('
            . count($abschnitte) . ' Abschnitte)');

        // Kopf und Fuss duerfen der Kuerzung nicht zum Opfer fallen -
        // ohne sie waere es keine Seite.
        $typen = array_column($abschnitte, 'type');

        ok(in_array('header', $typen, true), $seite['path'] . ': der Kopf bleibt');
        ok(in_array('footer', $typen, true), $seite['path'] . ': der Fuss bleibt');
        ok(count($abschnitte) >= 3, $seite['path'] . ': es bleibt genug uebrig');
    }
});

// ==================================================================
test('Ein laufender Auftrag zeigt, dass er lebt', function (): void {
    // Das eigentliche Aergernis, und es war nie ein Defekt: Ein Bau
    // dauert je nach Umfang zwanzig Minuten, ein einzelner Aufruf an die
    // KI zwanzig bis dreissig Sekunden. Die Prozentzahl steht deshalb
    // minutenlang still - und "arbeitet gerade" war von "haengt fest"
    // nicht zu unterscheiden. Wer abbricht und neu anfaengt, zahlt alles
    // noch einmal.
    $controller = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Http/JobController.php'
    );

    ok(str_contains($controller, 'still_seit'), 'Die Antwort sagt, wann zuletzt etwas passierte');
    ok(str_contains($controller, "'lebt'"), 'Und ob das noch als lebendig gilt');
    ok(str_contains($controller, 'aufrufe'), 'Dazu, wie viele Schritte schon erledigt sind');

    // Kein Schritt darf minutenlang auf derselben Zahl stehen.
    $pipeline = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Build/Pipeline.php'
    );

    foreach ([
        'plan' => '12 + (int) round(14 *',
        'inhalte' => '28 + (int) round(34 *',
        'sprachen' => '70 + (int) round(12 *',
    ] as $schritt => $formel) {
        ok(str_contains($pipeline, $formel),
            'Der Schritt "' . $schritt . '" hat eine eigene Spanne statt einer festen Zahl');
    }

    // Und die Anzeige braucht die Stelle dafuer.
    foreach (['project', 'dashboard', 'deploy'] as $seite) {
        $ansicht = (string) file_get_contents(
            dirname(__DIR__) . '/public_html/app/Views/admin/' . $seite . '.php'
        );
        ok(str_contains($ansicht, 'data-job-puls'), $seite . ': zeigt das Lebenszeichen');
    }

    // Die Schaetzung muss mit dem Umfang wachsen, sonst ist sie wertlos.
    $schaetzen = new ReflectionMethod(\WebAtze\Http\CreateController::class, 'dauerSchaetzen');
    $schaetzen->setAccessible(true);

    $klein = (string) $schaetzen->invoke(null, ['scope' => 'small', 'locales' => 'de']);
    $gross = (string) $schaetzen->invoke(null, ['scope' => 'large', 'locales' => 'de,en,fr']);

    ok($klein !== '', 'Eine kleine Website bekommt eine Schaetzung');
    ok($gross !== '', 'Eine grosse auch');
    ok($klein !== $gross, 'Und sie unterscheiden sich (' . $klein . ' / ' . $gross . ')');

    preg_match('/(\d+)/', $gross, $g);
    preg_match('/(\d+)/', $klein, $k);
    ok((int) ($g[1] ?? 0) >= (int) ($k[1] ?? 0),
        'Die grosse Website dauert laenger, nicht kuerzer');
});

// ==================================================================
test('Der Verbindungstest stuerzt nie ab', function (): void {
    // Hier stand ein Aufruf von ftp_connect() ohne die Pruefung, ob es
    // die FTP-Erweiterung auf diesem Server ueberhaupt gibt. Fehlt sie -
    // auf geteiltem Hosting oft der Fall - ist das kein Fehler, den man
    // abfangen kann, sondern ein Absturz: Fehler 500, ohne einen Hinweis
    // worauf. In den anderen Methoden stand die Pruefung laengst.
    $quelle = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Build/FtpDeployer.php'
    );

    // Jeder Aufruf einer ftp_-Funktion braucht davor eine Pruefung.
    $stellen = [];
    foreach (explode("\n", $quelle) as $nummer => $zeile) {
        if (preg_match('/(?<!function_exists\(.)ftp_(connect|ssl_connect)\(/', $zeile)) {
            $stellen[] = $nummer + 1;
        }
    }

    ok($stellen !== [], 'Es gibt Stellen, die FTP benutzen');

    $zeilen = explode("\n", $quelle);

    foreach ($stellen as $zeile) {
        // In den 30 Zeilen davor muss die Pruefung stehen.
        $davor = implode("\n", array_slice($zeilen, max(0, $zeile - 31), 30));

        ok(str_contains($davor, "function_exists('ftp_connect')"),
            'Vor Zeile ' . $zeile . ' wird geprueft, ob dieser Server FTP kann');
    }

    // Und der Test selbst muss ein Ergebnis liefern statt zu werfen -
    // auch wenn dort nichts erreichbar ist.
    $projectId = (int) \WebAtze\Core\Db::insert('projects', [
        'name' => 'Uploadtest', 'slug' => 'upload-' . bin2hex(random_bytes(4)),
        'status' => 'ready', 'created_at' => \WebAtze\Core\Db::now(),
        'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    foreach (['sftp' => 22, 'ftp' => 21] as $protokoll => $port) {
        \WebAtze\Build\FtpDeployer::saveTarget($projectId, [
            'protocol' => $protokoll,
            'host' => '127.0.0.1',
            'port' => 1,               // dort lauscht nichts
            'username' => 'niemand',
            'password' => 'auch-nicht',
            'path' => '/public_html/preview',
        ]);

        $ergebnis = \WebAtze\Build\FtpDeployer::test($projectId);

        is(false, $ergebnis['ok'], $protokoll . ': meldet sauber einen Fehlschlag');
        ok(($ergebnis['message'] ?? '') !== '', $protokoll . ': und sagt auch, warum');
        ok(array_key_exists('ordner', $ergebnis), $protokoll . ': die Antwort hat die erwartete Form');
    }

    \WebAtze\Core\Db::delete('deploy_targets', 'project_id = :p', ['p' => $projectId]);
    \WebAtze\Core\Db::delete('projects', 'id = :p', ['p' => $projectId]);
});

// ==================================================================
test('Eine erzeugte Website ist ab dem ersten Moment bearbeitbar', function (): void {
    // Das ist die Zusage, um die es geht: "von Anfang an kann man jede
    // Section bearbeiten". Bisher war das eine Hoffnung - der
    // Auftragstext bat darum, die Vorlagen taten es, geprueft hat es
    // niemand. Und eine Seite, die der Editor nicht anfassen kann,
    // sieht bis zum ersten Klick genauso aus wie eine, die er anfassen
    // kann.
    $projektId = (int) \WebAtze\Core\Db::insert('projects', [
        'name' => 'Abnahme', 'slug' => 'abnahme-' . bin2hex(random_bytes(4)),
        'status' => 'done', 'locale' => 'de',
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $seiteId = (int) \WebAtze\Core\Db::insert('project_pages', [
        'project_id' => $projektId, 'path' => '/', 'title' => 'Start',
        'sort_order' => 0, 'in_navigation' => 1,
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    // Eine Seite, wie der Generator sie baut: Kopf, Inhalt, Fuss.
    $aufbau = [
        ['header', ['brand' => 'Abnahme AG']],
        ['hero', ['title' => 'Willkommen bei der Abnahme', 'lead' => 'Ein kurzer Satz dazu.']],
        ['services', ['title' => 'Was wir tun']],
        ['contact', ['title' => 'Schreiben Sie uns']],
        ['footer', ['brand' => 'Abnahme AG']],
    ];

    foreach ($aufbau as $platz => [$typ, $inhalt]) {
        \WebAtze\Core\Db::insert('project_sections', [
            'project_id' => $projektId, 'page_id' => $seiteId, 'type' => $typ,
            'template_key' => Catalog::defaultKey($typ),
            'content' => $inhalt, 'overrides' => [], 'effects' => [],
            'hidden' => 0, 'sort_order' => $platz,
            'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
        ]);
    }

    $seiten = \WebAtze\Build\SiteBuilder::loadPages($projektId);
    $site = ['pages' => $seiten, 'locale' => 'de', 'brand' => 'Abnahme AG'];

    $abnahme = \WebAtze\Build\EditorCheck::run($site, $seiten);

    ok($abnahme['ok'], 'Die erzeugte Seite besteht die Abnahme: '
        . implode(' | ', $abnahme['fehler']));
    is(5, $abnahme['geprueft'], 'Alle fuenf Abschnitte wurden angesehen');

    // --- Und jetzt jeden der vier Faelle einzeln kaputt machen ------

    // (1) Ohne Kennung findet der Editor den Abschnitt nicht.
    $ohneId = $seiten;
    $ohneId[0]['sections'][1]['id'] = 0;

    $kaputt = \WebAtze\Build\EditorCheck::run($site, $ohneId);

    is(false, $kaputt['ok'], 'Ein Abschnitt ohne Kennung faellt auf');
    ok(str_contains(implode(' ', $kaputt['fehler']), 'Kennung'),
        'Und die Meldung sagt, woran es liegt');

    // (2) Eine Vorlage, die es nicht gibt: "Vorlage wechseln" wuerde
    // eine Datenbankspalte aendern und sonst nichts tun.
    $falscheVorlage = $seiten;
    $falscheVorlage[0]['sections'][1]['template_key'] = 'gibtsnicht';

    $kaputt = \WebAtze\Build\EditorCheck::run($site, $falscheVorlage);

    is(false, $kaputt['ok'], 'Eine Vorlage ausserhalb des Katalogs faellt auf');
    ok(str_contains(implode(' ', $kaputt['fehler']), 'Katalog'),
        'Und auch hier steht der Grund dabei');

    // (3) Kopf und Fuss an der falschen Stelle.
    $verdreht = $seiten;
    $verdreht[0]['sections'] = array_reverse($verdreht[0]['sections']);

    $kaputt = \WebAtze\Build\EditorCheck::run($site, $verdreht);

    is(false, $kaputt['ok'], 'Kopf hinten und Fuss vorne faellt auf');

    // (4) Eine feste Farbe im HTML: Ein Themenwechsel ginge daran
    // vorbei, und die halbe Seite bliebe stehen.
    $farben = new ReflectionMethod(\WebAtze\Build\EditorCheck::class, 'festeFarben');
    $farben->setAccessible(true);

    ok($farben->invoke(null, '<section style="background:#1a2b3c">x</section>') !== [],
        'Eine feste Farbe im style-Attribut wird gefunden');
    ok($farben->invoke(null, '<section style="background:rgb(1,2,3)">x</section>') !== [],
        'Auch als rgb()');
    is([], $farben->invoke(null, '<section style="background:var(--c-surface)">x</section>'),
        'Eine Variable ist in Ordnung');
    is([], $farben->invoke(null, '<section class="s-hero">#nichtfarbe</section>'),
        'Und ausserhalb eines style-Attributs wird nichts gesucht');

    \WebAtze\Core\Db::delete('project_sections', 'project_id = :p', ['p' => $projektId]);
    \WebAtze\Core\Db::delete('project_pages', 'project_id = :p', ['p' => $projektId]);
    \WebAtze\Core\Db::delete('projects', 'id = :p', ['p' => $projektId]);
});

// ==================================================================
test('Der Live-Stand hat Grenzen und meldet, wenn er sie erreicht', function (): void {
    // fetchDirectory() daneben war fuer die taegliche Sicherung
    // gemacht: ueber FTP nicht rekursiv, ein leeres Ordnerargument
    // abgelehnt, jeder Fehler ein stilles leeres Feld - von "der Ordner
    // ist leer" nicht zu unterscheiden -, und alles im Speicher.
    $darfWeiter = new ReflectionMethod(\WebAtze\Build\FtpDeployer::class, 'baumDarfWeiter');
    $darfWeiter->setAccessible(true);

    $neu = new ReflectionMethod(\WebAtze\Build\FtpDeployer::class, 'neuerStand');
    $neu->setAccessible(true);

    $stand = $neu->invoke(null);

    ok($darfWeiter->invokeArgs(null, [&$stand, microtime(true) + 60]),
        'Am Anfang ist Luft');

    // Zu viele Dateien.
    $stand = $neu->invoke(null);
    $stand['files'] = 5000;

    ok(!$darfWeiter->invokeArgs(null, [&$stand, microtime(true) + 60]),
        'Bei 5000 Dateien ist Schluss');
    ok($stand['abgeschnitten'], 'Und das wird vermerkt');

    // Zu viele Bytes.
    $stand = $neu->invoke(null);
    $stand['bytes'] = 200 * 1024 * 1024;

    ok(!$darfWeiter->invokeArgs(null, [&$stand, microtime(true) + 60]),
        'Bei 200 MB ist Schluss');

    // Zeit abgelaufen.
    $stand = $neu->invoke(null);

    ok(!$darfWeiter->invokeArgs(null, [&$stand, microtime(true) - 1]),
        'Und wenn die Zeit um ist');
    ok($stand['abgeschnitten'], 'Auch das wird vermerkt');

    // Was uebergangen wird: Sicherungen einer Sicherung sind der
    // klassische Weg, aus einer 20-MB-Website ein 400-MB-Archiv zu
    // machen - mit denselben Daten dreimal darin.
    $uebergehen = new ReflectionMethod(\WebAtze\Build\FtpDeployer::class, 'baumUebergehen');
    $uebergehen->setAccessible(true);

    ok($uebergehen->invoke(null, 'data/backups'), 'Sicherungen werden uebergangen');
    ok($uebergehen->invoke(null, 'data/backups/2026-01-01.zip'), 'Und alles darin');
    ok($uebergehen->invoke(null, '.git'), 'Die Versionsverwaltung auch');
    ok($uebergehen->invoke(null, 'node_modules/foo/bar.js'), 'node_modules ebenfalls');
    ok(!$uebergehen->invoke(null, 'data/site.php'), 'Die Daten des Kunden aber nicht');
    ok(!$uebergehen->invoke(null, 'index.html'), 'Und die Website erst recht nicht');

    // Ohne Zugangsdaten gibt es kein stilles Nichts, sondern eine
    // Auskunft. Das war der eigentliche Fehler daran: Ein leeres Feld
    // sah aus wie ein leerer Ordner.
    if (class_exists(ZipArchive::class)) {
        $pfad = sys_get_temp_dir() . '/wa-leer-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($pfad, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $ergebnis = \WebAtze\Build\FtpDeployer::fetchTree(
            ['secret' => 'unlesbar', 'protocol' => 'ftp', 'host' => '127.0.0.1',
             'port' => 1, 'username' => 'x', 'remote_path' => '/'],
            $zip,
            2.0
        );

        $zip->close();
        @unlink($pfad);

        is(false, $ergebnis['ok'], 'Ohne lesbare Zugangsdaten kein Erfolg');
        ok($ergebnis['error'] !== '', 'Und eine Begruendung statt eines leeren Feldes');
        is(0, $ergebnis['files'], 'Keine Dateien');
    }
});

// ==================================================================
test('Live-Staende raeumen sich selbst auf', function (): void {
    // Ein Live-Stand ist so gross wie die ganze Website, und niemand
    // denkt daran, ihn zu loeschen. Ohne diese Grenze fuellt ein
    // wiederholter Knopfdruck das Hosting-Konto.
    $projektId = (int) \WebAtze\Core\Db::insert('projects', [
        'name' => 'Aufraeumen', 'slug' => 'aufraeumen-' . bin2hex(random_bytes(4)),
        'status' => 'ready', 'created_at' => \WebAtze\Core\Db::now(),
        'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $projekt = \WebAtze\Core\Db::first('SELECT * FROM projects WHERE id = :i', ['i' => $projektId]);
    $slug = (string) $projekt['slug'];
    $ordner = STORAGE_DIR . '/zips/' . $slug;
    @mkdir($ordner, 0755, true);

    // Fuenf Live-Staende und ein gebautes Paket dazwischen.
    $dateien = [];

    for ($i = 1; $i <= 5; $i++) {
        $name = $slug . '/' . $slug . '-live-' . $i . '.zip';
        file_put_contents(STORAGE_DIR . '/zips/' . $name, 'x');
        $dateien[$i] = STORAGE_DIR . '/zips/' . $name;

        \WebAtze\Core\Db::insert('builds', [
            'project_id' => $projektId, 'version' => 0, 'zip_path' => $name,
            'zip_bytes' => 1, 'files_count' => 1, 'notes' => 'Live-Stand vom Server',
            'created_at' => \WebAtze\Core\Db::now(),
        ]);
    }

    $gebaut = $slug . '/' . $slug . '-v1-2026-01-01.zip';
    file_put_contents(STORAGE_DIR . '/zips/' . $gebaut, 'x');

    \WebAtze\Core\Db::insert('builds', [
        'project_id' => $projektId, 'version' => 1, 'zip_path' => $gebaut,
        'zip_bytes' => 1, 'files_count' => 1, 'notes' => '',
        'created_at' => \WebAtze\Core\Db::now(),
    ]);

    $aufraeumen = new ReflectionMethod(\WebAtze\Build\ZipExporter::class, 'liveAufraeumen');
    $aufraeumen->setAccessible(true);
    $aufraeumen->invoke(null, $projektId, $slug);

    $uebrig = (int) \WebAtze\Core\Db::value(
        'SELECT COUNT(*) FROM builds WHERE project_id = :p AND version = 0',
        ['p' => $projektId],
        0
    );

    is(\WebAtze\Build\ZipExporter::LIVE_BEHALTEN, $uebrig, 'Drei Live-Staende bleiben');

    ok(is_file($dateien[5]) && is_file($dateien[4]) && is_file($dateien[3]),
        'Und zwar die neuesten');
    ok(!is_file($dateien[1]) && !is_file($dateien[2]),
        'Die aeltesten sind auch als Datei weg');

    // Das gebaute Paket bleibt unberuehrt. Es ist der Beleg, dass die
    // Website dem Kunden gehoert - das wegzuraeumen waere etwas
    // anderes als aufraeumen.
    is(1, (int) \WebAtze\Core\Db::value(
        'SELECT COUNT(*) FROM builds WHERE project_id = :p AND version > 0',
        ['p' => $projektId],
        0
    ), 'Das gebaute Paket bleibt');
    ok(is_file(STORAGE_DIR . '/zips/' . $gebaut), 'Auch als Datei');

    // Aufraeumen
    foreach (glob($ordner . '/*') ?: [] as $datei) {
        @unlink($datei);
    }

    @rmdir($ordner);
    \WebAtze\Core\Db::delete('builds', 'project_id = :p', ['p' => $projektId]);
    \WebAtze\Core\Db::delete('projects', 'id = :p', ['p' => $projektId]);
});

// ==================================================================
test('Grosse Dateien gehen nicht durch den Arbeitsspeicher', function (): void {
    // Response::file() las die ganze Datei in den Speicher. Ein
    // Live-Stand einer Website ist leicht achtzig Megabyte, und das ist
    // auf geteiltem Hosting mit 128 MB das Ende des Requests - ohne
    // Fehlermeldung, die jemandem hilft.
    $quelle = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Core/Response.php'
    );

    ok(!preg_match('/\$response->body = \(string\) file_get_contents/', $quelle),
        'file() holt die Datei nicht mehr in den Speicher');
    ok(str_contains($quelle, 'private ?string $filePath'),
        'Sie merkt sich stattdessen den Pfad');
    ok(str_contains($quelle, 'fread($handle, 8192)'),
        'Und sendet in Bloecken');

    // Und es muss trotzdem funktionieren.
    $pfad = sys_get_temp_dir() . '/wa-datei-' . bin2hex(random_bytes(4)) . '.bin';
    file_put_contents($pfad, str_repeat('WebAtze', 5000));

    $antwort = \WebAtze\Core\Response::file($pfad, 'application/octet-stream', true, 'test.bin');

    is(200, $antwort->getStatus(), 'Die Antwort steht');
    is((string) filesize($pfad), $antwort->getHeader('Content-Length'), 'Die Laenge stimmt');
    is(35000, strlen($antwort->getBody()), 'Und der Inhalt kommt heraus, wenn man ihn verlangt');

    @unlink($pfad);
});

// ==================================================================
test('Das Editor-Plugin nimmt nur an, was hineingehoert', function (): void {
    // Ein Feld, das ausfuehrbaren Code entgegennimmt und in den
    // Webserver-Ordner legt, ist eine Hintertuer mit Formular - auch
    // hinter Anmeldung und geheimem Pfad. Diese Pruefung ist der
    // Grund, aus dem das Hochladen ueberhaupt angeboten werden darf.
    if (!class_exists(ZipArchive::class)) {
        ok(true, 'Ohne ZIP-Erweiterung nicht pruefbar');

        return;
    }

    $bauen = static function (array $eintraege): string {
        $pfad = sys_get_temp_dir() . '/wa-plugin-' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($pfad, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($eintraege as $name => $inhalt) {
            $zip->addFromString((string) $name, (string) $inhalt);
        }

        $zip->close();

        return $pfad;
    };

    $js = "console.log('editor');";
    $css = '.wa-editor{color:red}';

    $gutesManifest = static fn (array $ueberschreiben = []): string => (string) json_encode(array_merge([
        'name' => 'Testeditor',
        'version' => '1.0.0',
        'min_core' => '1.0.0',
        'files' => ['editor.js' => 'assets/editor-abc.js', 'editor.css' => 'assets/editor-abc.css'],
        'sha256' => [
            'assets/editor-abc.js' => hash('sha256', $js),
            'assets/editor-abc.css' => hash('sha256', $css),
        ],
    ], $ueberschreiben));

    // --- Was abgewiesen werden muss ---------------------------------

    $faelle = [
        'PHP im Archiv' => [
            'editor.json' => $gutesManifest(),
            'assets/editor-abc.js' => $js,
            'assets/editor-abc.css' => $css,
            'assets/schadcode.php' => '<?php system($_GET["c"]);',
        ],
        'PHP unter falschem Namen' => [
            'editor.json' => $gutesManifest(),
            'assets/editor-abc.js' => $js,
            'assets/editor-abc.css' => $css,
            'kit/hinterhalt.phtml' => '<?php echo 1;',
        ],
        'Pfadausbruch' => [
            'editor.json' => $gutesManifest(),
            'assets/editor-abc.js' => $js,
            'assets/editor-abc.css' => $css,
            '../../app/config.php' => 'weg damit',
        ],
        'Absoluter Pfad' => [
            'editor.json' => $gutesManifest(),
            'assets/editor-abc.js' => $js,
            'assets/editor-abc.css' => $css,
            '/etc/cron.d/boese' => '* * * * * root sh',
        ],
        'htaccess' => [
            'editor.json' => $gutesManifest(),
            'assets/editor-abc.js' => $js,
            'assets/editor-abc.css' => $css,
            'assets/.htaccess' => 'AddType application/x-httpd-php .js',
        ],
        'Falsche Pruefsumme' => [
            'editor.json' => $gutesManifest(),
            'assets/editor-abc.js' => $js . ' /* untergeschoben */',
            'assets/editor-abc.css' => $css,
        ],
        'Ohne editor.json' => [
            'assets/editor-abc.js' => $js,
            'assets/editor-abc.css' => $css,
        ],
        'Ohne Version' => [
            'editor.json' => $gutesManifest(['version' => '']),
            'assets/editor-abc.js' => $js,
            'assets/editor-abc.css' => $css,
        ],
        'Braucht einen neueren Kern' => [
            'editor.json' => $gutesManifest(['min_core' => '99.0.0']),
            'assets/editor-abc.js' => $js,
            'assets/editor-abc.css' => $css,
        ],
        'Datei fehlt' => [
            'editor.json' => $gutesManifest(),
            'assets/editor-abc.css' => $css,
        ],
    ];

    foreach ($faelle as $was => $eintraege) {
        $pfad = $bauen($eintraege);
        $ergebnis = \WebAtze\Domain\EditorPlugin::install($pfad);

        is(false, $ergebnis['ok'], $was . ': abgewiesen');
        ok($ergebnis['message'] !== '', $was . ': und mit Begruendung');

        @unlink($pfad);
    }

    // Und nichts davon darf etwas geschrieben haben.
    ok(!is_file(BASE_DIR . '/assets/schadcode.php'), 'Kein PHP im Assets-Ordner');
    ok(!is_file(BASE_DIR . '/assets/.htaccess'), 'Keine .htaccess im Assets-Ordner');
    ok(\WebAtze\Domain\EditorPlugin::installiert() === null,
        'Nach lauter Fehlschlaegen ist nichts installiert');

    // --- Und was durchkommen muss -----------------------------------

    $pfad = $bauen([
        'editor.json' => $gutesManifest(),
        'assets/editor-abc.js' => $js,
        'assets/editor-abc.css' => $css,
        'kit/editor-kit.js' => $js,
    ]);

    $ergebnis = \WebAtze\Domain\EditorPlugin::install($pfad);
    @unlink($pfad);

    is(true, $ergebnis['ok'], 'Ein sauberes Paket kommt durch: ' . $ergebnis['message']);
    is('1.0.0', $ergebnis['version'], 'Mit seiner Fassung');

    $stand = \WebAtze\Domain\EditorPlugin::installiert();

    ok($stand !== null, 'Und ist danach installiert');
    is('1.0.0', $stand['version'] ?? '', 'Die Fassung steht fest');

    // Der Editor muss jetzt auch auffindbar sein - genau das war
    // vorher der stille Fehler: Angaben da, Datei weg, zwei 404er.
    ok(\WebAtze\Domain\EditorPlugin::bereit(), 'Und auffindbar');
    is('/assets/editor-abc.js', \WebAtze\Core\Assets::url('editor.js'),
        'Die Ueberlagerung gewinnt gegen das Hauptmanifest');

    // Die Kit-Datei liegt bereit fuer Kundenbackends.
    ok(array_key_exists('editor-kit.js', \WebAtze\Domain\EditorPlugin::kitFiles()),
        'Und das Kundenbackend bekommt seine Fassung');

    // --- Austauschen -------------------------------------------------

    $js2 = "console.log('editor 2');";

    $pfad = $bauen([
        'editor.json' => (string) json_encode([
            'name' => 'Testeditor',
            'version' => '1.1.0',
            'min_core' => '1.0.0',
            'files' => ['editor.js' => 'assets/editor-xyz.js', 'editor.css' => 'assets/editor-xyz.css'],
            'sha256' => [
                'assets/editor-xyz.js' => hash('sha256', $js2),
                'assets/editor-xyz.css' => hash('sha256', $css),
            ],
        ]),
        'assets/editor-xyz.js' => $js2,
        'assets/editor-xyz.css' => $css,
    ]);

    $ergebnis = \WebAtze\Domain\EditorPlugin::install($pfad);
    @unlink($pfad);

    is(true, $ergebnis['ok'], 'Eine neue Fassung ersetzt die alte');
    is('/assets/editor-xyz.js', \WebAtze\Core\Assets::url('editor.js'), 'Und wird ausgeliefert');

    // Die alte Fassung darf nicht liegen bleiben - die Namen tragen
    // einen Pruefwert, also hiesse jede Fassung anders und alle
    // blieben.
    ok(!is_file(BASE_DIR . '/assets/editor-abc.js'), 'Die alte Fassung ist weg');

    // --- Entfernen ---------------------------------------------------

    ok(\WebAtze\Domain\EditorPlugin::remove(), 'Es laesst sich wieder entfernen');
    ok(!is_file(BASE_DIR . '/assets/editor-xyz.js'), 'Und nimmt seine Dateien mit');
    ok(\WebAtze\Domain\EditorPlugin::installiert() === null, 'Danach ist keines installiert');
});

// ==================================================================
test('Sechs Menuepunkte, und nichts wird unerreichbar', function (): void {
    // Zweiundzwanzig Eintraege sind keine Navigation mehr, sondern eine
    // Suchaufgabe. Sechs sind es - aber nur, solange alles Uebrige
    // darunter zu finden ist. Sonst ist "aufgeraeumt" bloss ein
    // anderes Wort fuer "verloren".
    $layout = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Views/layouts/admin.php'
    );

    // Die sechs Punkte selbst.
    preg_match('/\$nav = \[(.*?)\n\];/s', $layout, $treffer);
    preg_match_all("/\['(\/[a-z-]+)'/", (string) ($treffer[1] ?? ''), $punkte);

    $menue = $punkte[1] ?? [];

    is(6, count($menue), 'Das Menue hat sechs Punkte');

    foreach (['/start', '/kunden', '/neu', '/kalender', '/buchhaltung', '/einstellungen'] as $pfad) {
        ok(in_array($pfad, $menue, true), $pfad . ' ist einer davon');
    }

    // Und alles, was frueher im Menue stand, muss weiterhin von
    // irgendwo aus verlinkt sein - aus der Unternavigation, von der
    // Kundenseite oder aus der Sammelstelle.
    preg_match('/\$unterNav = \[(.*?)\n\];/s', $layout, $unter);
    $unterNav = (string) ($unter[1] ?? '');

    $kunde = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Views/admin/customer.php'
    );
    $liste = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Views/admin/customers.php'
    );

    $erreichbar = $layout . $unterNav . $kunde . $liste;

    $frueher = [
        '/kundensuche', '/potenzielle-kunden', '/rechnungen', '/vertraege',
        '/mitarbeitende', '/websites', '/wartung', '/passwoerter', '/anfragen',
        '/support', '/fragebogen', '/referenzen', '/zahlen', '/kosten',
        '/intranet', '/protokoll',
    ];

    foreach ($frueher as $pfad) {
        ok(str_contains($erreichbar, $pfad . "'") || str_contains($erreichbar, $pfad . '?'),
            'Zu ' . $pfad . ' fuehrt weiterhin ein Weg');
    }

    // Die Routen selbst bleiben bestehen. Ein Lesezeichen, das ins
    // Leere laeuft, waere teurer als ein Eintrag zu viel im Router.
    $router = new Router();
    \WebAtze\Core\Routes::register($router);

    $basis = '/' . trim((string) \WebAtze\Core\Config::get('create_path', 'create'), '/');

    foreach ($frueher as $pfad) {
        ok(route($router, 'GET', $basis . $pfad) !== null,
            'Die Adresse ' . $pfad . ' antwortet weiterhin');
    }
});

// ==================================================================
test('Die Zeichen finden auf sechs Punkten zusammen', function (): void {
    // Die Zahlen hingen an den alten Pfaden. Ohne das Zusammenfassen
    // waere nach dem Umbau kein einziges Zeichen mehr sichtbar - und
    // damit genau das zurueck, wogegen sie gebaut wurden.
    $roh = \WebAtze\Domain\Notices::all();
    $menue = \WebAtze\Domain\Notices::forMenu();

    foreach (array_keys($menue) as $pfad) {
        ok(in_array($pfad, ['/start', '/kunden', '/neu', '/kalender', '/buchhaltung', '/einstellungen'], true),
            'Das Zeichen ' . $pfad . ' haengt an einem Punkt, den es gibt');
    }

    // Keine Zahl darf unterwegs verloren gehen.
    $summeRoh = array_sum(array_map(static fn (array $z): int => $z['anzahl'], $roh));
    $summeMenue = array_sum(array_map(static fn (array $z): int => $z['anzahl'], $menue));

    is($summeRoh, $summeMenue, 'Keine Zahl geht beim Zusammenfassen verloren');

    // Und die Beschreibung muss die des dringendsten Postens sein -
    // "3 Dinge warten" bringt niemanden dazu hinzusehen.
    $zusammen = new ReflectionMethod(\WebAtze\Domain\Notices::class, 'zeichen');
    $zusammen->setAccessible(true);

    ok($zusammen->invoke(null, 0, 'eins', 'viele') === null, 'Null ergibt kein Zeichen');
    ok(($zusammen->invoke(null, 1, 'eins', 'viele')['was'] ?? '') === 'eins', 'Einzahl bei eins');
    ok(($zusammen->invoke(null, 5, 'eins', 'viele')['was'] ?? '') === 'viele', 'Mehrzahl sonst');
});

// ==================================================================
test('Die Kundenseite zeigt, was sie laedt', function (): void {
    // Der Fehler, der hier steckte: Der Controller holte die Rechnungen
    // des Kunden, die Ansicht kuendigte sie im Kopfkommentar an - und
    // rendern tat sie sie nie. Sie wurden also seit jeher geladen und
    // weggeworfen, und wer sie sehen wollte, ging ueber eine Liste
    // aller Rechnungen aller Kunden.
    $kundeId = (int) \WebAtze\Core\Db::insert('customers', [
        'name' => 'Muster GmbH',
        'status' => 'aktiv',
        'created_at' => \WebAtze\Core\Db::now(),
        'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    \WebAtze\Core\Db::insert('documents', [
        'customer_id' => $kundeId,
        'kind' => 'invoice',
        'number' => 'R-2026-0042',
        'title' => 'Website und Betreuung',
        'total_rappen' => 420000,
        'status' => 'sent',
        'issued_on' => '2026-01-15',
        'due_on' => '2026-02-15',
        'created_at' => \WebAtze\Core\Db::now(),
        'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    \WebAtze\Core\Db::insert('secrets', [
        'customer_id' => $kundeId,
        'label' => 'cPanel Muster',
        'kind' => 'hosting',
        'username' => 'muster@muster.ch',
        'created_at' => \WebAtze\Core\Db::now(),
        'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $projektId = (int) \WebAtze\Core\Db::insert('projects', [
        'name' => 'muster.ch', 'slug' => 'muster-' . bin2hex(random_bytes(4)),
        'status' => 'ready', 'customer_id' => $kundeId,
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    \WebAtze\Core\Db::insert('contracts', [
        'project_id' => $projektId,
        'customer_id' => $kundeId,
        'plan' => 'pflege',
        'price_rappen' => 24000,
        'interval_months' => 12,
        'started_on' => '2026-01-01',
        'next_invoice_on' => '2027-01-01',
        'created_at' => \WebAtze\Core\Db::now(),
        'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $html = \WebAtze\Core\View::partial('admin/customer', [
        'kunde' => \WebAtze\Core\Db::first('SELECT * FROM customers WHERE id = :i', ['i' => $kundeId]),
        'stand' => \WebAtze\Domain\Billing::statusOf($kundeId),
        'historie' => [],
        'todos' => [],
        'termine' => [],
        'mitarbeitende' => [],
        'projekte' => [],
        'websites' => \WebAtze\Domain\Websites::forCustomer($kundeId),
        'rechnungen' => \WebAtze\Core\Db::all(
            'SELECT * FROM documents WHERE customer_id = :c', ['c' => $kundeId]
        ),
        'vertraege' => \WebAtze\Core\Db::all(
            'SELECT c.*, p.name AS website FROM contracts c
               LEFT JOIN projects p ON p.id = c.project_id
              WHERE c.customer_id = :c',
            ['c' => $kundeId]
        ),
        'passwoerter' => \WebAtze\Core\Db::all(
            'SELECT id, label, kind, username, url FROM secrets WHERE customer_id = :c',
            ['c' => $kundeId]
        ),
        'tresorOffen' => false,
        'fragebogen' => [],
        'faeden' => [],
    ]);

    ok(str_contains($html, 'R-2026-0042'), 'Die Rechnungsnummer steht auf der Seite');
    ok(str_contains($html, 'Website und Betreuung'), 'Und der Titel');
    // e() maskiert das Tausendertrennzeichen zu &apos; - gesucht wird
    // deshalb im entschluesselten Text, sonst prueft man die
    // Maskierung statt den Betrag. Und ENT_HTML5, weil &apos; erst
    // dort bekannt ist.
    ok(str_contains(html_entity_decode($html, ENT_QUOTES | ENT_HTML5), "4'200.00"),
        'Und der Betrag');
    ok(str_contains($html, 'Rechnung erstellen'),
        'Von hier aus laesst sich eine Rechnung anlegen');

    ok(str_contains($html, 'Wartungsvertr'), 'Die Vertraege stehen dort');
    ok(str_contains($html, 'pflege'), 'Und zwar mit ihrem Paket');

    ok(str_contains($html, 'cPanel Muster'), 'Die Passwoerter des Kunden stehen dort');

    // Aber nur, dass es sie gibt. Das Geheimnis selbst kommt
    // ausschliesslich ueber den Tresor heraus - diese Seite bekommt es
    // nie zu sehen, auch nicht kurz.
    ok(!str_contains($html, 'secret_enc'), 'Das Geheimnis selbst nicht');

    \WebAtze\Core\Db::delete('contracts', 'customer_id = :c', ['c' => $kundeId]);
    \WebAtze\Core\Db::delete('secrets', 'customer_id = :c', ['c' => $kundeId]);
    \WebAtze\Core\Db::delete('documents', 'customer_id = :c', ['c' => $kundeId]);
    \WebAtze\Core\Db::delete('projects', 'id = :p', ['p' => $projektId]);
    \WebAtze\Core\Db::delete('customers', 'id = :i', ['i' => $kundeId]);
});

// ==================================================================
test('Was neu entsteht, findet seinen Kunden', function (): void {
    // Der Fehler, den diese Pruefung gefunden haette: Die Spalte
    // customer_id kam mit einer Migration, die Bestehendes nachtrug -
    // gefuellt hat sie danach keine der drei Einfuegestellen. Jede
    // neue Zeile landete ohne Kunden und verschwand still von seiner
    // Seite. In den Gesamtlisten stand sie weiter, nur dort nicht, wo
    // versprochen war, alles zu einem Kunden zu zeigen.
    $kundeId = (int) \WebAtze\Core\Db::insert('customers', [
        'name' => 'Neuzugang GmbH', 'status' => 'aktiv',
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $projektId = (int) \WebAtze\Core\Db::insert('projects', [
        'name' => 'neuzugang.ch', 'slug' => 'neuzugang-' . bin2hex(random_bytes(4)),
        'status' => 'live', 'customer_id' => $kundeId,
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    // --- Der Helfer selbst ------------------------------------------
    is($kundeId, \WebAtze\Domain\Websites::kundeVon($projektId), 'Der Kunde einer Website');
    is(0, \WebAtze\Domain\Websites::kundeVon(0), 'Keine Website, kein Kunde');
    is(0, \WebAtze\Domain\Websites::kundeVon(999999), 'Und eine, die es nicht gibt, auch nicht');

    // --- Vertrag ueber den echten Weg -------------------------------
    $vertragId = \WebAtze\Build\Contracts::create([
        'project_id' => $projektId,
        'plan' => 'basis',
        'price_rappen' => 24000,
        'interval_months' => 12,
    ]);

    is($kundeId, (int) \WebAtze\Core\Db::value(
        'SELECT customer_id FROM contracts WHERE id = :i', ['i' => $vertragId], 0
    ), 'Ein neuer Vertrag kennt seinen Kunden');

    // --- Supportfaden ueber den echten Weg --------------------------
    // Nicht mit einem eigenen INSERT: Genau das haette den Fehler
    // uebersehen, denn kaputt war die Einfuegestelle und nicht die
    // Tabelle.
    \WebAtze\Core\Db::update('projects', ['support_token' => str_repeat('t', 40)],
        'id = :i', ['i' => $projektId]);

    // Der Zugang weist sich ueber den Kopf aus, und die Nachricht kommt
    // als JSON - so, wie die Kundenseite es tut. Der Rumpf muesste
    // sonst aus php://input kommen, das auf der Kommandozeile nicht zu
    // fuellen ist; deshalb wird er hier eingesetzt. Alles danach ist
    // der echte Weg, und genau dort sass der Fehler.
    $anfrage = new \WebAtze\Core\Request(
        [],
        [],
        [
            'REQUEST_METHOD' => 'POST',
            'REMOTE_ADDR' => '198.51.100.7',
            'HTTP_X_WEBATZE_TOKEN' => str_repeat('t', 40),
        ],
        [],
        []
    );

    $rumpf = new ReflectionProperty(\WebAtze\Core\Request::class, 'json');
    $rumpf->setAccessible(true);
    $rumpf->setValue($anfrage, [
        'message' => 'Meine Seite laedt langsam.',
        'subject' => 'Tempo',
        'name' => 'Frau Muster',
        'email' => 'muster@neuzugang.ch',
    ]);

    $antwort = (new \WebAtze\Http\SupportController())->post($anfrage);

    is(200, $antwort->getStatus(), 'Die Supportfrage kommt an');

    $faden = \WebAtze\Core\Db::first(
        'SELECT customer_id FROM support_threads WHERE project_id = :p ORDER BY id DESC LIMIT 1',
        ['p' => $projektId]
    );

    ok($faden !== null, 'Und liegt als Faden da');
    is($kundeId, (int) ($faden['customer_id'] ?? 0), 'Ein neuer Supportfaden kennt seinen Kunden');

    // --- Fragebogen -------------------------------------------------
    // Er hat kein Projekt, aus dem sich etwas ableiten liesse -
    // questionnaires.project_id wird nirgends gesetzt. Deshalb wird der
    // Kunde beim Anlegen mitgegeben, und ohne ihn bleibt es leer.
    $mitKunde = \WebAtze\Domain\Questionnaire::create('Neuzugang GmbH', 'a@b.ch', '', $kundeId);

    is($kundeId, (int) \WebAtze\Core\Db::value(
        'SELECT customer_id FROM questionnaires WHERE id = :i', ['i' => $mitKunde['id']], 0
    ), 'Ein Fragebogen fuer einen bestehenden Kunden kennt ihn');

    $ohneKunde = \WebAtze\Domain\Questionnaire::create('Wildfremd AG', 'c@d.ch');

    is(0, (int) \WebAtze\Core\Db::value(
        'SELECT COALESCE(customer_id, 0) FROM questionnaires WHERE id = :i',
        ['i' => $ohneKunde['id']], 0
    ), 'Und einer an einen Fremden bleibt ohne - der Normalfall');

    // --- Und alles davon steht auf der Kundenseite ------------------
    $html = \WebAtze\Core\View::partial('admin/customer', [
        'kunde' => \WebAtze\Core\Db::first('SELECT * FROM customers WHERE id = :i', ['i' => $kundeId]),
        'stand' => \WebAtze\Domain\Billing::statusOf($kundeId),
        'historie' => [], 'todos' => [], 'termine' => [], 'mitarbeitende' => [], 'projekte' => [],
        'websites' => \WebAtze\Domain\Websites::forCustomer($kundeId),
        'rechnungen' => [],
        'vertraege' => \WebAtze\Core\Db::all(
            'SELECT c.*, p.name AS website FROM contracts c
               LEFT JOIN projects p ON p.id = c.project_id
              WHERE c.customer_id = :c',
            ['c' => $kundeId]
        ),
        'passwoerter' => [],
        'tresorOffen' => false,
        'fragebogen' => \WebAtze\Core\Db::all(
            'SELECT * FROM questionnaires WHERE customer_id = :c', ['c' => $kundeId]
        ),
        'faeden' => \WebAtze\Core\Db::all(
            'SELECT t.*, p.name AS website FROM support_threads t
               LEFT JOIN projects p ON p.id = t.project_id
              WHERE t.customer_id = :c',
            ['c' => $kundeId]
        ),
    ]);

    ok(str_contains($html, 'Tempo'), 'Die Supportfrage steht auf der Kundenseite');
    ok(str_contains($html, 'basis'), 'Der Vertrag auch');
    ok(str_contains($html, 'Neuzugang GmbH'), 'Und der Fragebogen');

    \WebAtze\Core\Db::delete('questionnaires', 'id = :i', ['i' => $mitKunde['id']]);
    \WebAtze\Core\Db::delete('questionnaires', 'id = :i', ['i' => $ohneKunde['id']]);
    \WebAtze\Core\Db::delete('support_messages', 'thread_id IN (SELECT id FROM support_threads WHERE project_id = :p)', ['p' => $projektId]);
    \WebAtze\Core\Db::delete('support_threads', 'project_id = :p', ['p' => $projektId]);
    \WebAtze\Core\Db::delete('contracts', 'project_id = :p', ['p' => $projektId]);
    \WebAtze\Core\Db::delete('projects', 'id = :p', ['p' => $projektId]);
    \WebAtze\Core\Db::delete('customers', 'id = :i', ['i' => $kundeId]);
});

// ==================================================================
test('Ohne Kunde wird nichts unerreichbar', function (): void {
    // Die Listen fuer Websites, Rechnungen, Vertraege und Passwoerter
    // fallen aus dem Menue. Was zu keinem Kunden gehoert, waere damit
    // unerreichbar - deshalb die Sammelstelle oben in der Kundenliste.
    // Ohne sie duerfte das Menue gar nicht schrumpfen.
    $projektId = (int) \WebAtze\Core\Db::insert('projects', [
        'name' => 'Herrenlos', 'slug' => 'herrenlos-' . bin2hex(random_bytes(4)),
        'status' => 'ready', 'source' => 'hand',
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $stand = \WebAtze\Http\CustomerController::ohneZuordnung();

    ok($stand['websites'] >= 1, 'Die Website ohne Kunden wird gezaehlt');
    ok($stand['summe'] >= 1, 'Und faellt in die Summe');

    // Und sie muss sich auch auflisten lassen, sonst ist die Zahl ein
    // Hinweis auf etwas, das man trotzdem nicht findet.
    $liste = \WebAtze\Domain\Websites::all('', '', -1);
    $ids = array_map(static fn (array $z): int => (int) $z['id'], $liste);

    ok(in_array($projektId, $ids, true), 'Und laesst sich auflisten');

    // Einem Kunden zugeordnet, verschwindet sie aus der Sammelstelle.
    $kundeId = (int) \WebAtze\Core\Db::insert('customers', [
        'name' => 'Findet sie', 'status' => 'aktiv',
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    \WebAtze\Domain\Websites::assign($projektId, $kundeId);

    $ids = array_map(
        static fn (array $z): int => (int) $z['id'],
        \WebAtze\Domain\Websites::all('', '', -1)
    );

    ok(!in_array($projektId, $ids, true), 'Zugeordnet ist sie nicht mehr herrenlos');

    // Und die zweite Verknuepfung zieht mit. Bisher schrieben zwei
    // Stellen zwei Richtungen, und sie konnten sich widersprechen -
    // dann kam je nach Abfrage eine andere Antwort auf dieselbe Frage.
    $kunde = \WebAtze\Core\Db::first('SELECT project_id FROM customers WHERE id = :i', ['i' => $kundeId]);
    is($projektId, (int) ($kunde['project_id'] ?? 0), 'Der Kunde fuehrt sie als Hauptwebsite');

    // Und kein zweiter Kunde behaelt sie.
    $zweiter = (int) \WebAtze\Core\Db::insert('customers', [
        'name' => 'Der andere', 'status' => 'aktiv', 'project_id' => $projektId,
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    \WebAtze\Domain\Websites::assign($projektId, $kundeId);

    $zeile = \WebAtze\Core\Db::first('SELECT project_id FROM customers WHERE id = :i', ['i' => $zweiter]);
    is(0, (int) ($zeile['project_id'] ?? 0), 'Der andere fuehrt sie nicht mehr');

    \WebAtze\Core\Db::delete('customers', 'id = :i', ['i' => $zweiter]);
    \WebAtze\Core\Db::delete('customers', 'id = :i', ['i' => $kundeId]);
    \WebAtze\Core\Db::delete('projects', 'id = :p', ['p' => $projektId]);
});

// ==================================================================
test('Ein Hosting-Zugang gilt fuer alle Websites', function (): void {
    // Alle Websites liegen auf demselben Konto: Server, Benutzername
    // und Passwort sind jedes Mal dieselben, nur das Verzeichnis
    // unterscheidet sich. Bei acht Websites war das bisher achtmal
    // eingetippt - und beim naechsten Passwortwechsel acht
    // Gelegenheiten, eine zu vergessen.
    $kontoId = \WebAtze\Domain\HostingAccount::save([
        'name' => 'GoDaddy',
        'protocol' => 'ftp',
        // Bewusst mit Schema und Pfad: Was hineinkopiert wird, soll
        // auch hier zurechtgerueckt werden und nicht erst beim Test.
        'host' => 'ftp://hosting.example/public_html',
        'port' => 21,
        'username' => 'web@example.ch',
        'password' => 'geheim',
    ]);

    $konto = \WebAtze\Domain\HostingAccount::find($kontoId);

    is('hosting.example', (string) $konto['host'], 'Der Servername wird auch hier geputzt');
    ok((string) $konto['secret'] !== 'geheim', 'Das Passwort liegt nicht im Klartext');

    // Zwei Websites am selben Zugang, mit verschiedenen Verzeichnissen.
    $ids = [];

    foreach (['/', '/public_html/zwei'] as $nummer => $pfad) {
        $ids[$nummer] = (int) \WebAtze\Core\Db::insert('projects', [
            'name' => 'Konto ' . $nummer, 'slug' => 'konto-' . $nummer . '-' . bin2hex(random_bytes(3)),
            'status' => 'ready',
            'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
        ]);

        \WebAtze\Build\FtpDeployer::saveTarget($ids[$nummer], [
            'protocol' => 'ftp', 'host' => '', 'port' => 21,
            'username' => '', 'password' => '', 'path' => $pfad,
            'hosting_account_id' => $kontoId,
        ]);
    }

    foreach ($ids as $nummer => $projektId) {
        $ziel = \WebAtze\Build\FtpDeployer::targetFor($projektId);

        is('hosting.example', (string) $ziel['host'], 'Website ' . $nummer . ': Server aus dem Zugang');
        is('web@example.ch', (string) $ziel['username'], 'Website ' . $nummer . ': Benutzer aus dem Zugang');
        ok((string) $ziel['secret'] !== '', 'Website ' . $nummer . ': Passwort aus dem Zugang');
    }

    is('/', (string) \WebAtze\Build\FtpDeployer::targetFor($ids[0])['remote_path'],
        'Das Verzeichnis bleibt bei der Website');
    is('/public_html/zwei', (string) \WebAtze\Build\FtpDeployer::targetFor($ids[1])['remote_path'],
        'Und zwar je Website ein anderes');

    is(2, \WebAtze\Domain\HostingAccount::usedBy($kontoId), 'Beide haengen daran');

    // Einmal aendern, beide stimmen wieder. Das ist der ganze Punkt.
    \WebAtze\Domain\HostingAccount::save([
        'name' => 'GoDaddy', 'protocol' => 'ftp', 'host' => 'neu.example',
        'port' => 21, 'username' => 'web2@example.ch', 'password' => '',
    ], $kontoId);

    foreach ($ids as $nummer => $projektId) {
        $ziel = \WebAtze\Build\FtpDeployer::targetFor($projektId);

        is('neu.example', (string) $ziel['host'], 'Website ' . $nummer . ': zieht mit');
        ok((string) $ziel['secret'] !== '', 'Website ' . $nummer . ': das Passwort bleibt');
    }

    // Eine Website mit eigenen Angaben darf davon nichts merken. Das
    // ist die Bedingung, diese Aenderung ueberhaupt einspielen zu
    // duerfen: Was hinterlegt ist, funktioniert unveraendert weiter.
    $eigen = (int) \WebAtze\Core\Db::insert('projects', [
        'name' => 'Eigen', 'slug' => 'eigen-' . bin2hex(random_bytes(3)),
        'status' => 'ready',
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    \WebAtze\Build\FtpDeployer::saveTarget($eigen, [
        'protocol' => 'sftp', 'host' => 'eigener.example', 'port' => 22,
        'username' => 'nur-ich', 'password' => 'meins', 'path' => '/web',
    ]);

    $ziel = \WebAtze\Build\FtpDeployer::targetFor($eigen);

    is('eigener.example', (string) $ziel['host'], 'Eigene Angaben bleiben eigene Angaben');
    is('nur-ich', (string) $ziel['username'], 'Auch der Benutzername');

    // Und wenn der Zugang weg ist, verlieren die Websites ihre Daten -
    // aber es kracht nicht, und gezaehlt wurde vorher, wie viele es
    // betrifft.
    ok(\WebAtze\Domain\HostingAccount::remove($kontoId), 'Der Zugang laesst sich entfernen');

    $ziel = \WebAtze\Build\FtpDeployer::targetFor($ids[0]);

    ok($ziel !== null, 'Das Ziel gibt es noch');
    is(0, (int) ($ziel['hosting_account_id'] ?? 0), 'Ohne Verweis auf ein Konto, das es nicht mehr gibt');

    foreach ($ids as $projektId) {
        \WebAtze\Core\Db::delete('deploy_targets', 'project_id = :p', ['p' => $projektId]);
        \WebAtze\Core\Db::delete('projects', 'id = :p', ['p' => $projektId]);
    }

    \WebAtze\Core\Db::delete('deploy_targets', 'project_id = :p', ['p' => $eigen]);
    \WebAtze\Core\Db::delete('projects', 'id = :p', ['p' => $eigen]);
});

// ==================================================================
test('Zu jeder Website fuehrt ein Weg zu ihren Zugangsdaten', function (): void {
    // Seit die Website-Liste nicht mehr im Menue steht, fuehrte
    // hierher gar kein Weg mehr: Die Seite einer hinzugefuegten
    // Website verlinkte nirgends auf die Zugangsdaten, und die
    // Kundenseite auch nicht. Damit war FTP fuer diese Websites
    // unerreichbar.
    foreach (['customer.php', 'websites.php', 'website.php'] as $datei) {
        $view = (string) file_get_contents(
            dirname(__DIR__) . '/public_html/app/Views/admin/' . $datei
        );

        ok(str_contains($view, '/veroeffentlichen'),
            $datei . ' fuehrt zu den Zugangsdaten');
    }

    // Und das Vorschaubild darf nicht aus dem Ordner ausbrechen.
    $aufloesen = new ReflectionMethod(\WebAtze\Http\PreviewController::class, 'resolve');
    $aufloesen->setAccessible(true);

    $wurzel = sys_get_temp_dir() . '/wa-mini-' . bin2hex(random_bytes(4));
    @mkdir($wurzel, 0755, true);
    file_put_contents($wurzel . '/index.html', '<html>x</html>');

    ok($aufloesen->invoke(null, $wurzel, '') !== null, 'Ohne Pfad kommt die Startseite');
    is(null, $aufloesen->invoke(null, $wurzel, '../../../etc/passwd'), 'Ausbrechen geht nicht');
    is(null, $aufloesen->invoke(null, $wurzel, '/etc/passwd'), 'Ein absoluter Pfad auch nicht');

    @unlink($wurzel . '/index.html');
    @rmdir($wurzel);
});

// ==================================================================
test('Der Servername wird zurechtgerueckt', function (): void {
    // In dieses Feld wird kopiert, und mitkopiert wird alles, was der
    // Anbieter drumherum anzeigt. Jedes Stueck davon ergab frueher
    // denselben roten Kasten, und keiner sagte warum.
    $n = \WebAtze\Build\FtpDeployer::normalizeHost(...);

    is('beispiel.ch', $n('ftp://beispiel.ch', 21)['host'], 'Das Schema faellt weg');
    is('beispiel.ch', $n('https://beispiel.ch', 21)['host'], 'Auch ein falsches');
    is('beispiel.ch', $n('beispiel.ch/public_html', 21)['host'], 'Der Pfad faellt weg');
    is('beispiel.ch', $n('  beispiel.ch  ', 21)['host'], 'Leerzeichen fallen weg');
    is('beispiel.ch', $n('beispiel.ch.', 21)['host'], 'Der Punkt am Ende auch');
    is('beispiel.ch', $n('web@beispiel.ch', 21)['host'], 'Ein Benutzername davor faellt weg');
    is('beispiel.ch', $n('ftp://web:geheim@beispiel.ch/public_html', 21)['host'], 'Und alles zusammen');

    // Ein angehaengter Port gehoert ins Portfeld, nicht in den Namen.
    $mitPort = $n('beispiel.ch:2121', 21);
    is('beispiel.ch', $mitPort['host'], 'Der Port wird abgetrennt');
    is(2121, $mitPort['port'], 'Und ins Portfeld uebernommen');

    // IPv6 besteht aus Doppelpunkten - da darf nichts abgetrennt werden.
    is('::1', $n('::1', 21)['host'], 'Eine IPv6-Adresse bleibt heil');
    is(21, $n('::1', 21)['port'], 'Und ihr Port auch');

    // Jede Aenderung wird auch gesagt, sonst wundert sich der Betreiber.
    ok($n('ftp://beispiel.ch', 21)['hinweis'] !== '', 'Eine Aenderung wird begruendet');
    is('', $n('beispiel.ch', 21)['hinweis'], 'Ein sauberer Name gibt keinen Hinweis');

    // Der Port bleibt in seinen Grenzen.
    is(1, $n('beispiel.ch', 0)['port'], 'Kein Port unter 1');
    is(65535, $n('beispiel.ch', 999999)['port'], 'Und keiner darueber');

    // Und was gespeichert wird, ist bereits sauber - sonst steckt der
    // Fehler dauerhaft in der Datenbank und der Test sucht ihn jedes
    // Mal aufs Neue.
    $projectId = (int) \WebAtze\Core\Db::insert('projects', [
        'name' => 'Normalisierung', 'slug' => 'norm-' . bin2hex(random_bytes(4)),
        'status' => 'ready', 'created_at' => \WebAtze\Core\Db::now(),
        'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    \WebAtze\Build\FtpDeployer::saveTarget($projectId, [
        'protocol' => 'ftp',
        'host' => 'ftp://beispiel.ch:2121/public_html',
        'port' => 21,
        'username' => 'web@beispiel.ch',
        'password' => 'geheim',
        'path' => '/public_html',
    ]);

    $ziel = \WebAtze\Core\Db::first(
        'SELECT host, port FROM deploy_targets WHERE project_id = :p',
        ['p' => $projectId]
    );

    is('beispiel.ch', (string) ($ziel['host'] ?? ''), 'Gespeichert wird der saubere Name');
    is(2121, (int) ($ziel['port'] ?? 0), 'Und der Port aus dem Namen');

    \WebAtze\Core\Db::delete('deploy_targets', 'project_id = :p', ['p' => $projectId]);
    \WebAtze\Core\Db::delete('projects', 'id = :p', ['p' => $projectId]);
});

// ==================================================================
test('Der Test sagt, an welcher Stufe es haengt', function (): void {
    // Frueher war das Ergebnis am Ende schlicht der Rueckgabewert von
    // ftp_chdir(). Eine tadellose Anmeldung mit falschem Ordner sah
    // damit exakt aus wie ein Server, den es nicht gibt - beides
    // "fehlgeschlagen", beide Male dieselbe Ratlosigkeit.
    $projectId = (int) \WebAtze\Core\Db::insert('projects', [
        'name' => 'Stufen', 'slug' => 'stufen-' . bin2hex(random_bytes(4)),
        'status' => 'ready', 'created_at' => \WebAtze\Core\Db::now(),
        'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    // Einen Namen, den es sicher nicht gibt: Das ist der Fall, der in
    // der Praxis auftrat ("ftp." vor eine cPanel-Domain geschrieben).
    \WebAtze\Build\FtpDeployer::saveTarget($projectId, [
        'protocol' => 'ftp',
        'host' => 'ftp.example.invalid',
        'port' => 21,
        'username' => 'web@example.invalid',
        'password' => 'geheim',
        'path' => '/public_html/preview',
    ]);

    $ergebnis = \WebAtze\Build\FtpDeployer::test($projectId);

    is(false, $ergebnis['ok'], 'Meldet einen Fehlschlag');
    ok(is_array($ergebnis['stufen'] ?? null), 'Die Antwort enthaelt die Stufen');
    ok(($ergebnis['stufen'][0]['name'] ?? '') === 'Servername',
        'Und die erste Stufe ist der Servername');
    is(false, $ergebnis['stufen'][0]['ok'] ?? true, 'Die als Erste scheitert');

    // Die Meldung muss den Fall benennen, sonst hat die Stufe nichts
    // gebracht: Es wurde kein Server abgewiesen, es wurde keiner gefunden.
    $meldung = mb_strtolower((string) $ergebnis['message']);
    ok(str_contains($meldung, 'gibt es nicht'), 'Sie sagt, dass es den Namen nicht gibt');
    ok(str_contains($meldung, 'ftp.'), 'Und nennt das ftp. davor als Ursache');

    // Ab der ersten roten Stufe wird nicht weitergeraten.
    is(1, count($ergebnis['stufen']), 'Nach der roten Stufe hoert der Test auf');

    // Und was die Meldung vorschlaegt, muss auch anklickbar sein.
    // Herausgekommen war: "trage / ein", daneben lauter Ordner, unter
    // denen "/" nicht ist - weil die Liste nur Unterverzeichnisse
    // sammelt und "/" keines ist.
    $mitVorschlag = new ReflectionMethod(\WebAtze\Build\FtpDeployer::class, 'pruefErgebnis');
    $mitVorschlag->setAccessible(true);

    $antwort = $mitVorschlag->invoke(null, false, 'Trage / ein.', ['/assets', '/data'], '/');

    ok(in_array('/', $antwort['ordner'], true), 'Der Vorschlag steht in der Liste zum Anklicken');
    is('/', $antwort['ordner'][0], 'Und zwar vorn');

    $antwort = $mitVorschlag->invoke(null, false, 'x', ['/public_html'], '/public_html');

    is(1, count($antwort['ordner']), 'Ein Vorschlag, den es schon gibt, kommt nicht doppelt');

    \WebAtze\Core\Db::delete('deploy_targets', 'project_id = :p', ['p' => $projectId]);
    \WebAtze\Core\Db::delete('projects', 'id = :p', ['p' => $projectId]);
});

// ==================================================================
test('Ein hochgeladenes Archiv wird geprueft, bevor es hinausgeht', function (): void {
    // Der Weg ohne den eingebauten Generator: Auftragstext kopieren,
    // die Website anderswo bauen lassen, das Ergebnis als ZIP
    // hochladen. Die Namen in einem Archiv kommen damit von aussen -
    // und ein Archiv ist nichts als eine Liste von Namen.
    if (!class_exists(ZipArchive::class)) {
        ok(true, 'Ohne ZIP-Erweiterung nicht pruefbar');

        return;
    }

    $plan = new ReflectionMethod(\WebAtze\Build\FtpDeployer::class, 'archivPlan');
    $plan->setAccessible(true);

    $bauen = static function (array $eintraege): ZipArchive {
        $pfad = sys_get_temp_dir() . '/wa-archiv-' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($pfad, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($eintraege as $name => $inhalt) {
            $zip->addFromString((string) $name, (string) $inhalt);
        }

        $zip->close();
        $zip->open($pfad);

        return $zip;
    };

    // --- Was abgewiesen wird ----------------------------------------
    foreach ([
        'Pfadausbruch' => ['index.html' => 'x', '../../etc/passwd' => 'x'],
        'Ausbruch mitten drin' => ['index.html' => 'x', 'a/../../b' => 'x'],
        'Absoluter Pfad' => ['index.html' => 'x', '/etc/passwd' => 'x'],
        'Laufwerksbuchstabe' => ['index.html' => 'x', 'C:/windows/x' => 'x'],
    ] as $was => $eintraege) {
        $ergebnis = $plan->invoke(null, $bauen($eintraege));

        ok(($ergebnis['error'] ?? '') !== '', $was . ': wird abgewiesen');
        is([], $ergebnis['dateien'], $was . ': und nichts geht hinaus');
    }

    // --- Der umschliessende Ordner faellt weg ------------------------
    // So packen die meisten: alles in einem Ordner. Ohne das Wegschneiden
    // laege die Website unter /public_html/meine-website/ und niemand
    // faende sie.
    $ergebnis = $plan->invoke(null, $bauen([
        'meine-website/index.html' => 'x',
        'meine-website/assets/site.css' => 'x',
        'meine-website/unterseite/kontakt.html' => 'x',
    ]));

    $ziele = array_column($ergebnis['dateien'], 'ziel');
    sort($ziele);

    is(['assets/site.css', 'index.html', 'unterseite/kontakt.html'], $ziele,
        'Der eine umschliessende Ordner wird weggeschnitten');

    // Liegen die Dateien schon oben, bleibt alles, wie es ist.
    $ergebnis = $plan->invoke(null, $bauen([
        'index.html' => 'x',
        'assets/site.css' => 'x',
    ]));

    $ziele = array_column($ergebnis['dateien'], 'ziel');
    sort($ziele);

    is(['assets/site.css', 'index.html'], $ziele, 'Ohne Ordner bleibt es, wie es ist');

    // Zwei Ordner nebeneinander: dann ist keiner der umschliessende.
    $ergebnis = $plan->invoke(null, $bauen([
        'de/index.html' => 'x',
        'en/index.html' => 'x',
    ]));

    $ziele = array_column($ergebnis['dateien'], 'ziel');
    sort($ziele);

    is(['de/index.html', 'en/index.html'], $ziele, 'Zwei Ordner bleiben beide stehen');

    // --- Beipack fliegt raus -----------------------------------------
    // Und zwar auch, wenn er nicht ganz vorn steht: Packt jemand seinen
    // Website-Ordner ein, heisst der Eintrag
    // "meine-website/__MACOSX/._x" - eine Pruefung auf den Anfang des
    // Namens laesst ihn durch. Genau das ist passiert.
    $ergebnis = $plan->invoke(null, $bauen([
        'seite/index.html' => 'x',
        'seite/__MACOSX/._index.html' => 'x',
        'seite/.DS_Store' => 'x',
        'seite/node_modules/foo/bar.js' => 'x',
        'seite/.git/config' => 'x',
    ]));

    is(['index.html'], array_column($ergebnis['dateien'], 'ziel'),
        'Beipack fliegt raus, auch tief im Archiv');

    // --- PHP darf bleiben --------------------------------------------
    // Anders als beim Editor-Plugin: Dieses Archiv geht auf den Server
    // des Kunden und wird bei uns nie ausgepackt. Eine Kundenwebsite
    // besteht zu einem guten Teil aus PHP - ihr Backend, das
    // Kontaktformular, die Bruecke.
    $ergebnis = $plan->invoke(null, $bauen([
        'index.html' => 'x',
        'kontakt.php' => 'x',
        'admin/index.php' => 'x',
    ]));

    is(3, count($ergebnis['dateien']), 'PHP gehoert zu einer Kundenwebsite dazu');

    // Und genau deshalb darf es bei uns nie landen: Der Weg aufs FTP
    // liest aus dem Archiv und schreibt in die Leitung.
    $quelle = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Build/FtpDeployer.php'
    );

    ok(str_contains($quelle, 'getStreamIndex'),
        'Das Archiv wird gestreamt, nicht bei uns ausgepackt');
    ok(!str_contains($quelle, '$zip->extractTo'),
        'Ausgepackt wird hier nichts');
});

// ==================================================================
test('Die beiden Knoepfe stehen da, auch ohne Zugangsdaten', function (): void {
    // Der Fehler, den der Browserdurchgang gefunden hat: Die ganze
    // Tafel hing an "es gibt ein Ziel". Ohne hinterlegten Zugang war
    // sie schlicht nicht da - und ein Knopf, der fehlt, erklaert
    // nichts. Man sucht ihn dann an der Stelle, an der er nie stand.
    // Jetzt steht er da und sagt selbst, was ihm fehlt.
    $mit = \WebAtze\Core\View::partial('admin/deploy', [
        'project' => ['id' => 7, 'name' => 'Probe', 'status' => 'draft', 'brief' => '{}'],
        'target' => [
            'id' => 1, 'host' => 'example.com', 'username' => 'web@example.com',
            'protocol' => 'ftps', 'port' => 21, 'remote_path' => '/public_html',
        ],
        'builds' => [], 'job' => null, 'brief' => [],
        'providers' => require dirname(__DIR__) . '/public_html/app/Support/providers.php',
        'gefunden' => [], 'hostingAccounts' => [],
    ]);

    $ohne = \WebAtze\Core\View::partial('admin/deploy', [
        'project' => ['id' => 7, 'name' => 'Probe', 'status' => 'draft', 'brief' => '{}'],
        'target' => null,
        'builds' => [], 'job' => null, 'brief' => [],
        'providers' => require dirname(__DIR__) . '/public_html/app/Support/providers.php',
        'gefunden' => [], 'hostingAccounts' => [],
    ]);

    foreach (['mit Zugang' => $mit, 'ohne Zugang' => $ohne] as $fall => $html) {
        ok(str_contains($html, '/projekt/7/archiv'),
            'Website hochladen ist da (' . $fall . ')');
        ok(str_contains($html, '/projekt/7/stand-holen'),
            'Aktuellen Stand holen ist da (' . $fall . ')');
    }

    // Ohne Zugang sind sie stumpf - und sagen, wohin man muss.
    ok(str_contains($ohne, 'disabled'), 'Ohne Zugang sind sie nicht scharf');
    ok(str_contains($ohne, 'href="#zugang"'), 'Und der Weg dorthin steht daneben');
    ok(str_contains($mit, 'id="zugang"'), 'Das Ziel des Verweises gibt es auch');

    // Mit Zugang keine stumpfen Knoepfe in dieser Tafel. Geschnitten
    // wird an der Ueberschrift der naechsten, nicht am Wort "Paket" -
    // das steht auch im Hinweistext darueber, und der Schnitt laege
    // dann vor den Knoepfen, um die es geht.
    $tafel = substr($mit, 0, (int) strpos($mit, '<h2 class="wa-panel__title">Paket<'));
    ok(str_contains($tafel, '/projekt/7/archiv'), 'Der Schnitt liegt hinter den Knoepfen');
    ok(!str_contains($tafel, 'disabled'), 'Mit Zugang sind beide scharf');
});

// ==================================================================
test('Der Auftragstext verlangt am Ende ein ZIP', function (): void {
    // Ohne die Ansage kommt mal ein Ordner, mal eine Liste von Dateien,
    // mal ein Archiv mit drei Ebenen darueber - und jedes davon kostet
    // Handarbeit, bevor es hochgeladen werden kann.
    $text = \WebAtze\Domain\PromptText::build([
        'company' => 'Muster AG',
        'industry' => 'Handwerk',
    ]);

    ok(str_contains($text, 'ZIP'), 'Das Wort ZIP kommt vor');
    ok(str_contains($text, 'index.html'), 'Und wo die Startseite liegen soll');
    ok(str_contains($text, 'obersten Ebene'), 'Naemlich ganz oben');
    ok(str_contains($text, 'node_modules'), 'Ohne Entwicklungsreste');

    // Und die Zusage, die ueberall gilt: Es steht nirgends, wie die
    // Website entstanden ist.
    foreach (['Claude', 'Anthropic', 'KI-generiert'] as $verraeter) {
        ok(!str_contains($text, $verraeter . ' hat'), 'Kein Hinweis auf ' . $verraeter);
    }
});

// ==================================================================
test('Der Test nennt den Servernamen, der auflöst', function (): void {
    // GoDaddy zeigt "ftp.deine-domain.ch" an - das ist keine Erfindung,
    // das steht dort wirklich. Nur fuehrt die DNS-Zone den Eintrag
    // nicht immer, und dann gibt es den Server schlicht nicht.
    // Umgekehrt kommt genauso vor. Deshalb wird beides probiert.
    $alternative = new ReflectionMethod(\WebAtze\Build\FtpDeployer::class, 'namensAlternative');
    $alternative->setAccessible(true);

    // Eine Domain, die es sicher gibt, mit einem ftp-Namen, den es
    // sicher nicht gibt: genau sein Fall.
    is('example.com', $alternative->invoke(null, 'ftp.example.com'),
        'Zu ftp.X wird X vorgeschlagen, wenn X auflöst');

    // Und was nirgends hinführt, wird nicht erfunden.
    is('', $alternative->invoke(null, 'ftp.gibtsganzsicherzznicht.invalid'),
        'Ohne auflösende Alternative wird nichts vorgeschlagen');
    is('', $alternative->invoke(null, 'nurein wort'),
        'Aus einem Namen ohne Punkt wird nichts geraten');

    // Die Meldung nennt ihn dann auch.
    $hilfe = new ReflectionMethod(\WebAtze\Build\FtpDeployer::class, 'namensHilfe');
    $hilfe->setAccessible(true);

    $text = (string) $hilfe->invoke(null, 'ftp.example.com');

    ok(str_contains($text, 'example.com loest auf'), 'Die Meldung nennt den Namen, der geht');
    ok(str_contains($text, 'DNS-Zone'), 'Und erklärt, warum GoDaddys Angabe hier nicht passt');

    // Und die Antwort traegt ihn zum Anklicken mit.
    $ergebnis = new ReflectionMethod(\WebAtze\Build\FtpDeployer::class, 'pruefErgebnis');
    $ergebnis->setAccessible(true);

    $antwort = $ergebnis->invoke(null, false, 'x', [], '', [], 'example.com');

    is('example.com', (string) ($antwort['vorschlagHost'] ?? ''),
        'Der Servervorschlag steht in der Antwort');

    // Die Ansicht muss ihn ins Serverfeld eintragen, nicht ins Verzeichnis.
    $view = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Views/admin/deploy.php'
    );

    ok(str_contains($view, 'data-fill="#host"'),
        'Der Knopf fuellt das Serverfeld');
});

// ==================================================================
test('Die Zusammenfassung widerspricht der Stufenkette nicht', function (): void {
    // Der Fehler, der ihn eine Runde gekostet hat: $vorhanden wurde mit
    // dem Ergebnis der Schreibprobe ueberschrieben. Stand der Ordner
    // gruen da und scheiterte nur das Schreiben, meldete die
    // Zusammenfassung trotzdem "den Ordner gibt es nicht" - also
    // wortwoertlich das Gegenteil der Stufe darueber. Er hat daraufhin
    // drei Pfade durchprobiert, von denen der erste richtig war.
    $meldung = new ReflectionMethod(\WebAtze\Build\FtpDeployer::class, 'endMeldung');
    $meldung->setAccessible(true);

    // Ordner da, Schreiben geht: alles gut.
    $text = (string) $meldung->invoke(null, true, true, '/', '', 'web@example.ch');
    ok(str_contains($text, 'Alles bereit'), 'Alles gruen wird auch so gemeldet');

    // Ordner da, Schreiben geht nicht: Das ist eine andere Auskunft.
    $text = (string) $meldung->invoke(null, true, false, '/', '', 'web@example.ch');

    ok(!str_contains($text, 'gibt es von diesem Zugang aus nicht'),
        'Ein gescheitertes Schreiben behauptet nicht, den Ordner gebe es nicht');
    ok(str_contains($text, 'ist da'), 'Sondern sagt, dass er da ist');
    ok(str_contains($text, 'Schreiben'), 'Und dass es am Schreiben liegt');
    ok(str_contains($text, 'Herunterladen'),
        'Und wozu es trotzdem reicht - der Stand laesst sich holen');

    // Ordner nicht da: der alte, richtige Weg.
    $text = (string) $meldung->invoke(null, false, null, '/falsch', '/', 'web@example.ch');
    ok(str_contains($text, 'gibt es von diesem Zugang aus nicht'),
        'Ein fehlender Ordner wird weiterhin als solcher gemeldet');
    ok(str_contains($text, 'Trage / ein'), 'Mit dem Vorschlag, der passt');

    // Und das Ergebnis insgesamt: Ein Zugang, der lesen aber nicht
    // schreiben darf, ist zum Stand-Holen brauchbar. Ihn als
    // Fehlschlag zu melden hiesse, eine funktionierende Verbindung
    // wegzuwerfen.
    $quelle = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Build/FtpDeployer.php'
    );

    ok(!str_contains($quelle, '$vorhanden = $konnte;'),
        'Die Schreibprobe ueberschreibt das Urteil ueber den Ordner nicht mehr');
    ok(!str_contains($quelle, '$vorhanden = (bool) $konnte;'),
        'Auch nicht im SFTP-Weg');

    // Die Schreibprobe versucht es ohne die NAT-Adresse noch einmal.
    // Auf geteiltem Hosting kommt die Auflistung manchmal durch und das
    // Hochladen nicht - dann sieht es aus wie eine fehlende Berechtigung.
    $probe = substr($quelle, strpos($quelle, 'function schreibprobeFtp'));
    $probe = substr($probe, 0, 1400);

    ok(str_contains($probe, 'FTP_USEPASVADDRESS'),
        'Die Schreibprobe versucht es ohne die Passivadresse noch einmal');
});

// ==================================================================
test('Ein festgenageltes cPanel-Konto bekommt den richtigen Ordner', function (): void {
    // Der Fall, der eine Woche gekostet hat: Ein FTP-Unterkonto wird in
    // cPanel auf sein Verzeichnis festgenagelt. Nach der Anmeldung ist
    // man bereits darin - der Pfad, der in cPanel stand, existiert von
    // dort aus nicht mehr, weil er die Wurzel geworden ist.
    $vorschlag = new ReflectionMethod(\WebAtze\Build\FtpDeployer::class, 'ordnerVorschlag');
    $vorschlag->setAccessible(true);

    // Hauptkonto: public_html liegt daneben, also der volle Pfad.
    is('/public_html',
        $vorschlag->invoke(null, ['public_html', 'mail', 'logs'], '/', 'kunde'),
        'Neben public_html ist es das Hauptkonto');

    is('/home/kunde/public_html',
        $vorschlag->invoke(null, ['public_html', '.cpanel'], '/home/kunde', 'kunde'),
        'Auch wenn der Startordner tiefer liegt');

    // Unterkonto: eine Startseite an der Wurzel heisst festgenagelt.
    is('/',
        $vorschlag->invoke(null, ['index.html', 'assets', 'admin'], '/', 'web@preview.beispiel.ch'),
        'Eine Startseite an der Wurzel heisst: schon drin');

    // Leerer Ordner, aber @-Form: bei cPanel der zuverlaessigste Hinweis.
    is('/',
        $vorschlag->invoke(null, [], '/', 'web@preview.beispiel.ch'),
        'Ein leeres Unterkonto sitzt trotzdem in seinem Ordner');

    // Ohne @ und ohne Anhaltspunkt wird nichts erfunden.
    is('', $vorschlag->invoke(null, [], '/', 'kunde'),
        'Ohne Anhaltspunkt wird nicht geraten');

    // Und Plesk hat andere Namen.
    is('/httpdocs', $vorschlag->invoke(null, ['httpdocs', 'logs'], '/', 'kunde'),
        'Bei Plesk heisst die Wurzel httpdocs');
});

// ==================================================================
test('Der Hinweis im Formular widerspricht cPanel nicht mehr', function (): void {
    // Hier stand: "Ein FTP-Zugang der Hauptdomain kommt dort hin - ein
    // eigener Zugang je Subdomain ist nicht noetig." Also das Gegenteil
    // dessen, was cPanel nahelegt. Wer einen anlegte und dann den vollen
    // Pfad eintrug, bekam genau eine Meldung: fehlgeschlagen.
    $view = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Views/admin/deploy.php'
    );

    ok(!str_contains($view, 'ein eigener Zugang je Subdomain ist nicht nötig'),
        'Der irrefuehrende Satz ist weg');
    ok(str_contains($view, 'sitzt bereits in seinem Ordner'),
        'Stattdessen steht dort, was ein Unterkonto besonders macht');

    // Und die Felder muessen so heissen, wie das JavaScript sie sucht -
    // sonst folgt der Port der Uebertragungsart nicht, und Port 21 gegen
    // einen SSH-Dienst sieht von aussen aus wie ein toter Server.
    foreach (['protocol', 'port', 'path', 'host'] as $feld) {
        ok(str_contains($view, 'data-ftp-field="' . $feld . '"'),
            'Das Feld ' . $feld . ' ist fuer das JavaScript auffindbar');
    }

    $js = (string) file_get_contents(dirname(__DIR__) . '/frontend/src/admin/admin.js');

    ok(!str_contains($js, "getElementById('ftp_protocol')"),
        'Das JavaScript sucht nicht mehr nach einer ID, die es nur auf einer Seite gibt');
    ok(str_contains($js, 'initFtpPortFollowsProtocol'),
        'Und der Port folgt der Uebertragungsart');

    // Der Anbietereintrag muss die beiden Tatsachen nennen, an denen es
    // tatsaechlich haengt.
    $anbieter = require dirname(__DIR__) . '/public_html/app/Support/providers.php';
    $godaddy = $anbieter['hosting']['godaddy'];

    $text = implode(' ', $godaddy['steps']) . ' ' . $godaddy['note'];

    // Hier stand vorher, cPanel lege den Namen "nie" an. GoDaddy zeigt
    // ihn aber sehr wohl an - nur fuehrt die DNS-Zone den Eintrag nicht
    // immer. Ein Werkzeug, das dem Anbieter widerspricht, hat zu
    // erklaeren warum, nicht zu behaupten, der Anbieter irre sich.
    ok(!str_contains($text, 'Diesen Namen gibt es bei cPanel nicht'),
        'Kein Absolutum mehr ueber den ftp-Namen');
    ok(str_contains($text, 'DNS-Zone'),
        'Sondern die Bedingung: es braucht den DNS-Eintrag');
    ok(str_contains($text, 'explicit FTPS'),
        'Und GoDaddys eigener Hinweis auf verschluesseltes FTP steht drin');
    is('ftps', (string) $godaddy['protocol'],
        'Verschluesselt ist die Vorgabe - derselbe Port, kein Klartextpasswort');
    ok(str_contains($text, 'festgenagelt'),
        'Und dass ein Unterkonto in seinem Ordner sitzt');
});

// ==================================================================
test('Der Pfad einer Subdomain wird gefunden', function (): void {
    // Ein FTP-Zugang laesst sich in cPanel nur fuer die Hauptdomain
    // anlegen. Der Ordner einer Subdomain liegt darunter, meist in
    // public_html und benannt wie die Subdomain. Wer das nicht weiss,
    // raet - und bekam frueher nur "gibt es dort nicht" zurueck.
    $orte = new ReflectionMethod(\WebAtze\Build\FtpDeployer::class, 'suchorte');
    $orte->setAccessible(true);

    $gesucht = $orte->invoke(null, '/public_html/preview', '/home/kunde');

    ok(in_array('/home/kunde', $gesucht, true), 'Im Heimatverzeichnis wird nachgesehen');
    ok(in_array('/home/kunde/public_html', $gesucht, true), 'Und in dessen public_html');
    ok(in_array('/public_html', $gesucht, true), 'Und eine Ebene ueber dem gewuenschten Pfad');
    ok(count($gesucht) === count(array_unique($gesucht)), 'Keine Stelle doppelt');

    // Aus dem Gefundenen soll der passende Ordner vorgeschlagen werden.
    $vor = new ReflectionMethod(\WebAtze\Build\FtpDeployer::class, 'vorschlagen');
    $vor->setAccessible(true);

    $gefunden = ['/public_html/alt', '/public_html/preview', '/public_html/mail'];

    is('/public_html/preview', $vor->invoke(null, $gefunden, '/public_html/preview'),
        'Der gleichnamige Ordner wird vorgeschlagen');
    is('/public_html/preview', $vor->invoke(null, $gefunden, '/irgendwo/preview'),
        'Auch wenn der Betreiber den falschen Oberordner geraten hat');
    is('', $vor->invoke(null, $gefunden, '/public_html/gibtsnicht'),
        'Ohne Treffer wird nichts erfunden');
    is('', $vor->invoke(null, [], '/public_html/preview'), 'Und ohne Fundstuecke erst recht nicht');

    // Der Pfad selbst darf nicht aus dem Zielverzeichnis ausbrechen.
    $sauber = new ReflectionMethod(\WebAtze\Build\FtpDeployer::class, 'cleanPath');
    $sauber->setAccessible(true);

    is('/public_html/preview', $sauber->invoke(null, 'public_html/preview'),
        'Ein fehlender Schraegstrich wird ergaenzt');
    is('/public_html/preview', $sauber->invoke(null, '/public_html/preview/'),
        'Ein ueberzaehliger faellt weg');
    is('/public_html/preview', $sauber->invoke(null, '//public_html///preview'),
        'Doppelte werden zusammengefasst');
    ok(!str_contains((string) $sauber->invoke(null, '/public_html/../../etc'), '..'),
        'Ausbrechen geht nicht');
});

// ==================================================================
test('Der Arbeiter arbeitet so lange, wie er darf', function (): void {
    // Das war die eigentliche Ursache, und sie ist unspektakulaer: Der
    // Arbeiter durfte fuenfzig Sekunden. Ein Aufruf an die KI dauert 20
    // bis 30 - also zwei Aufrufe je Durchlauf. Eine Website mit elf
    // Seiten und drei Sprachen braucht gegen achtzig Aufrufe. Bei einem
    // Cronjob je Minute sind das vierzig Minuten, in denen sich die
    // Anzeige kaum bewegt. Der Bau war nie kaputt, er war zaeh - und wer
    // nach zehn Minuten abbricht, sieht nur Stillstand.
    is(300, (int) \WebAtze\Core\Config::get('worker_budget_seconds', 0),
        'Fuenf Minuten je Durchlauf statt fuenfzig Sekunden');

    $worker = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Core/Worker.php'
    );

    // Bestehende Installationen tragen in ihrer config.php noch die
    // alten fuenfzig Sekunden - und die Datei wird beim Update
    // absichtlich nicht angefasst. Also darf nicht die Datei
    // entscheiden, sondern die Messung.
    ok(str_contains($worker, 'max($budget, 3000)'),
        'Ohne beobachteten Abbruch wird der Auftrag am Stueck zu Ende gebracht');

    ok(str_contains($worker, 'min($budget, $ueberlebt - 8)'),
        'Nach einem Abbruch gilt die gemessene Grenze des Servers');

    // Zwei Arbeiter duerfen sich nicht ins Gehege kommen: Der zweite
    // findet den Auftrag gesperrt und geht sofort wieder.
    $jobs = (string) file_get_contents(dirname(__DIR__) . '/public_html/app/Core/Jobs.php');
    ok(str_contains($jobs, 'locked_until IS NULL OR locked_until <='),
        'Ein gesperrter Auftrag wird nicht zweimal genommen');

    // Und ein paar Fehlschlaege duerfen keinen halbfertigen Bau wegwerfen.
    preg_match('/MAX_ATTEMPTS = (\d+)/', $jobs, $t);
    ok((int) ($t[1] ?? 0) >= 5,
        'Ein Bau ueberlebt eine voruebergehende Stoerung (' . ($t[1] ?? '?') . ' Versuche)');
});

// ==================================================================
test('Ein Schritt haelt den Bau nicht endlos auf', function (): void {
    // Der Kern der Sache. Kommt ein Aufruf nicht durch, wurde bisher
    // derselbe Versuch wiederholt - wieder und wieder, ohne Fehler, ohne
    // Fortschritt. Das war der Stillstand bei 22 Prozent: kein Defekt,
    // sondern eine Schleife.
    //
    // Jetzt wird zweimal versucht, danach nimmt der Bau den Weg, der
    // ohne KI funktioniert. Eine Website nach dem Standardaufbau ist
    // unendlich viel besser als eine, die nie entsteht.
    $pipeline = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Build/Pipeline.php'
    );

    ok(str_contains($pipeline, 'function withFallback'), 'Es gibt einen Weg ohne die KI');
    ok(str_contains($pipeline, '$offen >= 2'), 'Nach zwei Fehlschlaegen wird er genommen');

    // Der Zaehler muss den Fehlschlag ueberleben, sonst faengt jeder
    // Anlauf bei null an und die Schleife bleibt.
    ok(str_contains($pipeline, 'Jobs::saveState'), 'Der Zaehler wird vor dem Weiterreichen gesichert');

    // Und die Position gehoert VOR die Arbeit in den Zustand. Andersherum
    // sichert ein Fehlschlag die alte Position mit, der Bau faellt
    // zurueck und faengt dieselbe Seite wieder an.
    $vorPlan = strpos($pipeline, "\$state['plan_index'] = \$index;");
    $planArbeit = strpos($pipeline, "'abschnitte-' . \$index");
    ok($vorPlan !== false && $planArbeit !== false && $vorPlan < $planArbeit,
        'Die Seitennummer steht im Zustand, bevor geplant wird');

    $vorTexte = strpos($pipeline, "\$state['group_index'] = \$gruppe;");
    $texteArbeit = strpos($pipeline, "'texte-' . \$index");
    ok($vorTexte !== false && $texteArbeit !== false && $vorTexte < $texteArbeit,
        'Und die Gruppennummer, bevor geschrieben wird');

    // Ein Fehler, den Warten nicht behebt - leeres Guthaben, falscher
    // Schluessel - darf NICHT durch einen Ersatzaufbau vertuscht werden.
    ok(str_contains($pipeline, 'catch (ConfigurationError $e)'),
        'Fehlende Einstellungen werden durchgereicht statt uebertuencht');

    // Fuer jede Stelle muss es einen Ersatz geben.
    $planer = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Ai/SitePlanner.php'
    );
    ok(str_contains($planer, 'function fallbackPlan'), 'Ersatz fuer die Seitenliste');
    ok(str_contains($planer, 'function fallbackSections'), 'Ersatz fuer die Abschnitte einer Seite');

    $schreiber = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Ai/ContentWriter.php'
    );
    ok(str_contains($schreiber, 'function writeGroupPlain'), 'Ersatz fuer die Texte');

    // Der Ersatz muss vollstaendige Seiten liefern, nicht Bruchstuecke.
    foreach (['/', '/leistungen', '/kontakt', '/impressum'] as $pfad) {
        $abschnitte = \WebAtze\Ai\SitePlanner::fallbackSections(['path' => $pfad, 'title' => 'Probe']);

        ok(count($abschnitte) >= 3, 'Ersatzaufbau fuer ' . $pfad . ' hat Substanz ('
            . count($abschnitte) . ' Abschnitte)');

        foreach ($abschnitte as $a) {
            ok(\WebAtze\Templates\Schema::forType((string) $a['type']) !== null,
                'Abschnittstyp "' . $a['type'] . '" gibt es wirklich');
        }
    }

    // Und was ersetzt wurde, muss dem Betreiber gesagt werden.
    ok(str_contains($pipeline, "\$state['hinweise'][]"), 'Ersatz wird vermerkt');
    ok(str_contains($pipeline, 'Hinweis: '), 'Und in der Schlussmeldung genannt');
});

// ==================================================================
test('Der Fehlerzaehler zaehlt Fehler, nicht Abholungen', function (): void {
    // Ein Bau laeuft absichtlich in vielen kleinen Takten und wird
    // deshalb oft abgeholt. Frueher stieg der Zaehler bei jeder
    // Abholung: Nach drei Takten galt jeder voruebergehende Fehler als
    // endgueltig, und eine Notbremse, die denselben Zaehler las, hielt
    // einen kerngesunden Bau an. Genau das ist im Probelauf passiert -
    // "nach 70 Anlaeufen angehalten", obwohl alles lief.
    $projectId = (int) \WebAtze\Core\Db::insert('projects', [
        'name' => 'Zaehlertest', 'slug' => 'zaehler-' . bin2hex(random_bytes(4)),
        'status' => 'building', 'created_at' => \WebAtze\Core\Db::now(),
        'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $jobId = \WebAtze\Core\Jobs::enqueue('generate', [], $projectId);

    // Fuenfmal abholen und wieder freigeben - so laeuft ein normaler Bau.
    for ($i = 0; $i < 5; $i++) {
        $job = \WebAtze\Core\Jobs::claim();
        \WebAtze\Core\Jobs::yield((int) $job['id'], ['step' => 'inhalte']);
    }

    $job = \WebAtze\Core\Db::first('SELECT attempts FROM jobs WHERE id = :id', ['id' => $jobId]);
    is(0, (int) $job['attempts'], 'Fuenf Abholungen sind kein einziger Fehler');

    // Ein echter Fehlschlag zaehlt dagegen.
    \WebAtze\Core\Jobs::claim();
    \WebAtze\Core\Jobs::fail($jobId, 'Etwas ging schief', true);

    $job = \WebAtze\Core\Db::first('SELECT attempts, status FROM jobs WHERE id = :id', ['id' => $jobId]);
    is(1, (int) $job['attempts'], 'Ein Fehlschlag zaehlt');
    is('queued', (string) $job['status'], 'Und wird noch einmal versucht');

    // Geht es danach weiter, war der Fehler voruebergehend - der Zaehler
    // darf zurueck. Sonst summierten sich Fehler ueber Stunden auf.
    \WebAtze\Core\Jobs::progress($jobId, 'inhalte', 40, 'Weiter geht es', ['step' => 'inhalte']);

    $job = \WebAtze\Core\Db::first('SELECT attempts FROM jobs WHERE id = :id', ['id' => $jobId]);
    is(0, (int) $job['attempts'], 'Fortschritt setzt den Fehlerzaehler zurueck');

    \WebAtze\Core\Db::delete('jobs', 'project_id = :p', ['p' => $projectId]);
    \WebAtze\Core\Db::delete('projects', 'id = :p', ['p' => $projectId]);

    // Die Notbremse sieht auf die Uhr, nicht auf den Zaehler.
    $worker = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Core/Worker.php'
    );
    ok(str_contains($worker, 'MAX_RUNTIME_SECONDS'), 'Die Notbremse misst die Laufzeit');
    ok(!str_contains($worker, 'MAX_TICKS'), 'Und nicht mehr die Zahl der Abholungen');
});

// ==================================================================
test('Kein einzelner Aufruf muss gross sein', function (): void {
    // Der Plan entstand frueher in einem Aufruf: bis zu zwoelf Seiten
    // samt allen Abschnitten, mit hohem Aufwand gerechnet. Am echten
    // Dienst gemessen dauerte das 57 Sekunden. Bricht der Server vorher
    // ab, kommt dieser Aufruf nie zustande - und kein Fortsetzen der
    // Welt hilft, weil es nichts zum Fortsetzen gibt. Der Bau blieb
    // haengen, bevor ein einziges Wort geschrieben war.
    $planer = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Ai/SitePlanner.php'
    );

    ok(str_contains($planer, 'function planPages'), 'Die Seitenliste ist ein eigener Aufruf');
    ok(str_contains($planer, 'function planSections'), 'Die Abschnitte je Seite ebenfalls');

    // Seit dem Umbau wird zuerst alles in einer Anfrage geplant - das
    // spart bei elf Seiten elf Aufrufe. Diese eine Anfrage darf gross
    // sein, ABER nur, weil es einen Weg gibt, wenn sie scheitert.
    ok(str_contains($planer, 'function planAllAtOnce'), 'Es gibt die Planung in einem Zug');

    $stelle = (int) strpos($planer, 'function planAllAtOnce');
    $koerper = substr($planer, $stelle, 3000);

    ok(str_contains($koerper, 'return null;'),
        'Scheitert sie, sagt sie das - statt den Bau mitzureissen');
    ok(str_contains($koerper, 'ConfigurationError $e'),
        'Ein leeres Guthaben wird dabei durchgereicht, nicht verschluckt');

    $pipeline2 = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Build/Pipeline.php'
    );
    ok(str_contains($pipeline2, 'plan_einzeln'),
        'Und die Pipeline faellt dann auf den Weg Seite fuer Seite zurueck');

    // Der Rueckfallweg selbst muss klein bleiben - sonst hilft er nicht.
    foreach (['planPages', 'planSections'] as $name) {
        $ab = strpos($planer, 'function ' . $name);
        ok($ab !== false, 'Es gibt ' . $name);

        preg_match("/'max_tokens' => (\\d+)/", substr($planer, (int) $ab, 2500), $m);
        ok((int) ($m[1] ?? 99999) <= 3000, $name . ' bleibt klein (' . ($m[1] ?? '?') . ')');
    }

    // Der Planungsschritt muss sich Seite fuer Seite fortsetzen lassen.
    $pipeline = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Build/Pipeline.php'
    );

    ok(str_contains($pipeline, 'plan_index'), 'Der Planungsschritt merkt sich, wie weit er ist');
    ok(str_contains($pipeline, 'plan_pages'), 'Und behaelt die bereits geplanten Seiten');

    // Ebenso beim Schreiben und Uebersetzen.
    foreach (['ContentWriter', 'Translator'] as $datei) {
        $inhalt = (string) file_get_contents(
            dirname(__DIR__) . '/public_html/app/Ai/' . $datei . '.php'
        );
        preg_match("/'max_tokens' => (\d+)/", $inhalt, $m);
        ok((int) ($m[1] ?? 99999) <= 8000, $datei . ' fragt in kleinen Haeppchen');
    }
});

// ==================================================================
test('Ein toter Vorgang gibt seinen Auftrag schnell frei', function (): void {
    // Das war der eigentliche Stillstand: kein Fehler, sondern
    // Wartezeit. Die Sperre musste lang sein, damit kein zweiter
    // Arbeiter dazwischenfunkt - und nach jedem Abschuss lag der Auftrag
    // genau so lange brach. Bei 300 Sekunden hiess das fuenf Minuten je
    // Gruppe.
    //
    // Jetzt haelt ein lebender Vorgang seine Sperre selbst aufrecht
    // (Worker::beat alle fuenf Sekunden), also darf sie kurz sein.
    $sperre = (int) \WebAtze\Core\Config::get('job_lock_seconds', 0);

    ok($sperre > 0 && $sperre <= 60,
        'Die Sperre ist kurz (' . $sperre . 's)');

    $worker = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Core/Worker.php'
    );

    ok(str_contains($worker, 'Jobs::keepAlive'),
        'Der Herzschlag zieht die Sperre mit');

    // Und der Herzschlag muss auch waehrend eines laufenden Aufrufs
    // schlagen - dort stirbt der Vorgang ja.
    $client = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Ai/ClaudeClient.php'
    );

    ok(str_contains($client, 'CURLOPT_PROGRESSFUNCTION'),
        'Auch mitten in einem Aufruf schlaegt das Herz');
    ok(str_contains($client, 'Worker::beat'),
        'Und meldet sich beim Arbeiter');
});

// ==================================================================
test('Der Arbeiter misst, wie lange der Server ihn leben laesst', function (): void {
    // Auf geteiltem Hosting steht in der php.ini eine Zeitgrenze, und
    // set_time_limit() ist manchmal gesperrt. Wird der Vorgang
    // abgeschossen, endet er nicht sauber: Der Auftrag bleibt gesperrt
    // liegen, bis die Sperre ablaeuft. Das sah von aussen aus wie
    // Stillstand - in Wahrheit kam je Sperrzeit genau eine Gruppe dazu.
    //
    // Also wird gemessen. Ein Merker beim Start, ein Lebenszeichen
    // waehrend der Arbeit; findet der naechste Durchlauf den Merker noch
    // vor, ist der letzte gestorben - und der Abstand sagt, wie lange
    // dieser Server einem Vorgang zugesteht.
    $messen = new ReflectionMethod(\WebAtze\Core\Worker::class, 'measureLastTick');
    $messen->setAccessible(true);

    \WebAtze\Core\Settings::put('worker_survival_seconds', '');

    // Ein Durchlauf, der vor 60 Sekunden begann und nach 25 Sekunden
    // das letzte Lebenszeichen gab - also dort abgeschossen wurde.
    \WebAtze\Core\Settings::put('worker_tick_open', (string) (time() - 60));
    \WebAtze\Core\Settings::put('worker_tick_beat', (string) (time() - 35));

    $messen->invoke(null);

    is('25', (string) \WebAtze\Core\Settings::get('worker_survival_seconds', ''),
        'Die durchgehaltene Zeit wird erkannt');
    is('', (string) \WebAtze\Core\Settings::get('worker_tick_open', ''),
        'Und der Merker danach geleert');

    // Ein zweiter, laengerer Durchlauf darf den Wert nicht schoenrechnen.
    // Massgeblich ist die kuerzeste beobachtete Grenze.
    \WebAtze\Core\Settings::put('worker_tick_open', (string) (time() - 60));
    \WebAtze\Core\Settings::put('worker_tick_beat', (string) (time() - 20));
    $messen->invoke(null);

    is('25', (string) \WebAtze\Core\Settings::get('worker_survival_seconds', ''),
        'Ein guter Durchlauf hebt die gemessene Grenze nicht an');

    // Ein kuerzerer schon - danach muss vorsichtiger gerechnet werden.
    \WebAtze\Core\Settings::put('worker_tick_open', (string) (time() - 60));
    \WebAtze\Core\Settings::put('worker_tick_beat', (string) (time() - 46));
    $messen->invoke(null);

    is('14', (string) \WebAtze\Core\Settings::get('worker_survival_seconds', ''),
        'Ein kuerzerer senkt sie');

    // Ein sauber beendeter Durchlauf hinterlaesst keinen Merker - dann
    // gibt es nichts zu messen.
    \WebAtze\Core\Settings::put('worker_survival_seconds', '');
    \WebAtze\Core\Settings::put('worker_tick_open', '');
    $messen->invoke(null);

    is('', (string) \WebAtze\Core\Settings::get('worker_survival_seconds', ''),
        'Ohne Abbruch wird nichts gemessen');

    // Unsinnige Werte werden verworfen, nicht uebernommen.
    \WebAtze\Core\Settings::put('worker_tick_open', (string) (time() - 10));
    \WebAtze\Core\Settings::put('worker_tick_beat', (string) (time() - 8));
    $messen->invoke(null);

    is('', (string) \WebAtze\Core\Settings::get('worker_survival_seconds', ''),
        'Ein Fehlstart unter fuenf Sekunden zaehlt nicht');

    \WebAtze\Core\Settings::put('worker_survival_seconds', '');
    \WebAtze\Core\Settings::put('worker_tick_open', '');

    // Und die Anfragen selbst muessen knapp genug sein, dass eine in ein
    // schmales Zeitfenster passt. Die Dauer haengt vor allem daran, wie
    // viel geschrieben wird.
    $schreiber = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Ai/ContentWriter.php'
    );

    preg_match("/'max_tokens' => (\d+)/", $schreiber, $t);
    $grenze = (int) ($t[1] ?? 0);

    ok($grenze > 0 && $grenze <= 8000,
        'Eine Anfrage bleibt knapp genug fuer geteiltes Hosting (' . $grenze . ' Zeichen)');
});

// ==================================================================
test('Ein Server, der immer an derselben Stelle abbricht, wird erkannt', function (): void {
    // Der teuerste Fall: Der Cronjob ruft die Adresse per curl auf, also
    // gilt die Zeitgrenze des Webservers. Die ist kuerzer als eine
    // Anfrage an die KI dauert. Der Vorgang stirbt jedes Mal an
    // derselben Stelle, der Auftrag faengt sie jedes Mal neu an, und
    // bezahlt wird jedes Mal. Von aussen sieht das aus wie Stillstand.
    //
    // Nach drei Anlaeufen an derselben Gruppe wird deshalb angehalten -
    // mit einer Meldung, die sagt, was in cPanel zu aendern ist.
    $quelle = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Build/Pipeline.php'
    );

    ok(str_contains($quelle, 'gruppe_versuche'), 'Die Anlaeufe je Gruppe werden gezaehlt');
    ok(str_contains($quelle, '$versuche > 3'), 'Nach dem dritten ist Schluss');

    // Die Meldung muss brauchbar sein, nicht nur richtig.
    ok(str_contains($quelle, 'worker.php'), 'Sie nennt die Datei fuer den Cronjob');
    ok(str_contains($quelle, 'curl'), 'Und den wahrscheinlichen Grund');

    // Ein ConfigurationError haelt an, statt alle 30 Sekunden neu zu
    // probieren - sonst waere die Bremse wirkungslos.
    $stelle = strpos($quelle, '$versuche > 3');
    $danach = substr($quelle, (int) $stelle, 400);
    ok(str_contains($danach, 'ConfigurationError'),
        'Und der Auftrag haelt an, statt weiter Geld auszugeben');

    // Der Zaehler muss nach einer geschafften Gruppe zurueckgesetzt
    // werden, sonst haelt ein langsamer, aber gesunder Bau faelschlich an.
    ok(str_contains($quelle, "\$state['gruppe_versuche'] = [];"),
        'Nach einer geschafften Gruppe faengt die Zaehlung von vorn an');

    // Und die Sperre wird vor jedem Aufruf aufgefrischt, damit kein
    // zweiter Arbeiter dieselbe Anfrage noch einmal bezahlt.
    ok(str_contains($quelle, 'Jobs::keepAlive'), 'Die Sperre wird waehrend der Arbeit gehalten');

    $sperre = (int) \WebAtze\Core\Config::get('job_lock_seconds', 0);

    ok($sperre >= 15 && $sperre <= 60,
        'Die Sperre ist kurz genug, dass es nach einem Abbruch zuegig weitergeht ('
        . $sperre . 's)');
});

// ==================================================================
test('Das Aussehen erreicht auch den Verwaltungsbereich', function (): void {
    // Die Menueschaltflaeche stand in components.css. Die laedt nur die
    // oeffentliche Website. Im Verwaltungsbereich hatte sie deshalb gar
    // kein Aussehen - null mal null Pixel, nichts zum Antippen: Auf dem
    // Handy kam man nicht mehr ins Menue.
    $verzeichnis = dirname(__DIR__) . '/frontend/src';

    $admin = (string) file_get_contents($verzeichnis . '/admin/admin.css');
    $app = (string) file_get_contents($verzeichnis . '/styles/app.css');

    // Welche Bausteine bindet welche Seite ein?
    $importe = static function (string $inhalt): array {
        preg_match_all("/@import\s+'([^']+)'/", $inhalt, $t);
        return array_map(static fn (string $x): string => basename($x), $t[1]);
    };

    $adminTeile = $importe($admin);
    $appTeile = $importe($app);

    ok(in_array('controls.css', $adminTeile, true), 'Der Verwaltungsbereich laedt controls.css');
    ok(in_array('controls.css', $appTeile, true), 'Die Website ebenfalls');

    // Klassen, die in beiden Oberflaechen vorkommen, gehoeren in eine
    // Datei, die beide laden. Sonst fehlt das Aussehen auf einer Seite.
    $gemeinsam = ['wa-burger', 'wa-input', 'wa-select', 'wa-btn'];

    $controls = (string) file_get_contents($verzeichnis . '/styles/controls.css');
    $components = (string) file_get_contents($verzeichnis . '/styles/components.css');

    foreach ($gemeinsam as $klasse) {
        $inGeteilt = str_contains($controls, '.' . $klasse) || str_contains($admin, '.' . $klasse);
        $nurInComponents = str_contains($components, '.' . $klasse) && !$inGeteilt;

        ok(!$nurInComponents, 'Nicht nur in components.css: .' . $klasse);
    }

    // Und die Schaltflaeche braucht eine Groesse - ein <label> hat von
    // sich aus keine.
    preg_match('/\.wa-burger\s*\{([^}]*)\}/', $controls, $regel);
    $block = (string) ($regel[1] ?? '');

    ok(str_contains($block, 'display'), 'Die Menueschaltflaeche bekommt ein display');
    ok(str_contains($block, 'inline-size') || str_contains($block, 'width'),
        'Und eine Breite - sonst ist sie null Pixel gross');
});

// ==================================================================
test('Das Menue geht auch ohne JavaScript auf', function (): void {
    // Eine Verwaltung, deren Navigation an einem Skript haengt, ist
    // unbedienbar, sobald das Skript einmal nicht ankommt: alte Datei im
    // Zwischenspeicher, blockierte Adresse, abgeschaltetes JavaScript.
    // Deshalb schaltet ein Kaestchen die Leiste, rein ueber CSS.
    $layout = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Views/layouts/admin.php'
    );

    ok(str_contains($layout, 'id="wa-admin-nav"'), 'Es gibt ein Kaestchen zum Schalten');
    ok(str_contains($layout, 'for="wa-admin-nav"'), 'Und eine Beschriftung, die darauf zeigt');

    // Das Kaestchen muss VOR der Leiste stehen, sonst greift der
    // Geschwister-Wahlschalter nicht.
    $kaestchen = strpos($layout, 'id="wa-admin-nav"');
    $leiste = strpos($layout, 'class="wa-admin__side"');

    ok($kaestchen !== false && $leiste !== false && $kaestchen < $leiste,
        'Das Kaestchen steht vor der Leiste');

    $css = (string) file_get_contents(dirname(__DIR__) . '/frontend/src/admin/admin.css');

    ok(str_contains($css, '.wa-admin__nav-state:checked ~ .wa-admin__side'),
        'CSS oeffnet die Leiste ueber das Kaestchen');
    ok(!str_contains($css, '.wa-admin__nav-state { display: none'),
        'Das Kaestchen bleibt mit der Tabulatortaste erreichbar');
});

// ==================================================================
test('Auswahllisten sind auch aufgeklappt lesbar', function (): void {
    // Die aufgeklappte Liste zeichnet das Betriebssystem. Sie erbt die
    // helle Schriftfarbe des Feldes, setzt aber ihren eigenen, meist
    // weissen Hintergrund: weisse Schrift auf Weiss. Zu sehen war die
    // Auswahl erst, wenn der Zeiger eine Zeile markierte.
    $controls = (string) file_get_contents(
        dirname(__DIR__) . '/frontend/src/styles/controls.css'
    );

    ok(str_contains($controls, '.wa-select option'), 'Die Optionen bekommen eigene Farben');

    preg_match('/\.wa-select option,?\s*\.wa-select optgroup\s*\{([^}]*)\}/', $controls, $t);
    $block = (string) ($t[1] ?? '');

    ok(str_contains($block, 'background-color'), 'Mit eigener Flaeche');
    ok(str_contains($block, 'color'), 'Und eigener Schriftfarbe');

    // Entscheidend: undurchsichtig. Eine durchscheinende Flaeche laege
    // im Aufklappmenue wieder auf Weiss.
    ok(!str_contains($block, 'glass') && !str_contains($block, 'rgb(255 255 255 /'),
        'Die Flaeche ist undurchsichtig, nicht durchscheinend');
});

// ==================================================================
test('Jede benutzte Klasse ist auch eingebunden', function (): void {
    // Der Übersetzungsschritt scheiterte im Betrieb an einem fehlenden
    // "use": Translator liegt in Ai, Pipeline in Build. PHP merkt das
    // erst beim Aufruf – und der Aufruf passierte beim Kunden.
    $eingebaut = ['self', 'static', 'parent', 'ZipArchive', 'DateTime', 'DateTimeImmutable',
                  'Throwable', 'RuntimeException', 'InvalidArgumentException', 'Exception',
                  'PDO', 'PDOException', 'ReflectionClass', 'ReflectionMethod', 'TypeError',
                  'Error', 'Closure', 'Generator', 'JsonException', 'LogicException',
                  'DomainException', 'SplFileInfo'];

    $fehlend = [];
    $geprüft = 0;

    foreach (['Ai', 'Build', 'Core', 'Http', 'Domain', 'Templates'] as $ordner) {
        foreach (glob(dirname(__DIR__) . '/public_html/app/' . $ordner . '/*.php') ?: [] as $datei) {
            $geprüft++;
            $tokens = token_get_all((string) file_get_contents($datei));
            $ns = '';
            $use = [];
            $n = count($tokens);

            for ($i = 0; $i < $n; $i++) {
                $t = $tokens[$i];

                if (!is_array($t)) {
                    continue;
                }

                if ($t[0] === T_NAMESPACE) {
                    for ($j = $i + 1; $j < $n && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                        if (is_array($tokens[$j])
                            && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                            $ns .= $tokens[$j][1];
                        }
                    }
                    continue;
                }

                if ($t[0] === T_USE && $ns !== '') {
                    $roh = '';
                    for ($j = $i + 1; $j < $n && $tokens[$j] !== ';'; $j++) {
                        if (is_array($tokens[$j])) {
                            $roh .= $tokens[$j][1];
                        } elseif (in_array($tokens[$j], ['{', '}', ','], true)) {
                            $roh .= $tokens[$j];
                        }
                    }

                    $roh = trim($roh);

                    if ($roh === '' || str_contains($roh, '(')) {
                        continue;
                    }

                    if (preg_match('/^(.*?)\\\\\{(.+)\}$/s', $roh, $m) === 1) {
                        foreach (explode(',', $m[2]) as $teil) {
                            $teil = trim($teil);
                            if ($teil !== '') {
                                $use[$teil] = true;
                            }
                        }
                    } else {
                        $pos = strrpos($roh, '\\');
                        $use[$pos !== false ? substr($roh, $pos + 1) : $roh] = true;
                    }
                    continue;
                }

                if ($t[0] !== T_STRING || preg_match('/^[A-Z]/', $t[1]) !== 1) {
                    continue;
                }

                $vor = $tokens[$i - 1] ?? null;
                $nach = $tokens[$i + 1] ?? null;

                if (is_array($vor) && in_array($vor[0],
                        [T_NS_SEPARATOR, T_OBJECT_OPERATOR, T_FUNCTION, T_CONST], true)) {
                    continue;
                }

                $istKlasse = (is_array($nach) && $nach[0] === T_DOUBLE_COLON)
                    || (is_array($vor) && $vor[0] === T_NEW);

                if (!$istKlasse || isset($use[$t[1]]) || in_array($t[1], $eingebaut, true)) {
                    continue;
                }

                if (class_exists($ns . '\\' . $t[1]) || interface_exists($ns . '\\' . $t[1])
                    || class_exists($t[1])) {
                    continue;
                }

                $fehlend[] = basename($datei) . ':' . $t[2] . ' ' . $t[1];
            }
        }
    }

    ok($geprüft > 40, $geprüft . ' Dateien untersucht');
    ok($fehlend === [], 'Keine Klasse ohne Einbindung'
        . ($fehlend === [] ? '' : ': ' . implode(', ', array_slice($fehlend, 0, 5))));
});

// ==================================================================
test('Die Referenz-Miniatur überlebt den nächsten Tag', function (): void {
    // Früher zeigte preview_path auf /vorschau/<kennung>/. Diesen Ordner
    // räumt der Cron nach 24 Stunden weg – am Tag darauf stand in jeder
    // Referenzkarte ein leerer Rahmen. Jetzt liegt eine dauerhafte Kopie
    // unter storage/showcase/.
    $quelle = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Build/ShowcaseBuilder.php'
    );

    ok(!str_contains($quelle, "'/vorschau/' . \$token"),
        'Die Referenz zeigt nicht mehr auf die ablaufende Vorschau');
    ok(str_contains($quelle, "/referenz-ansicht/"),
        'Sondern auf die dauerhafte Kopie');
    ok(str_contains($quelle, "STORAGE_DIR . '/showcase/'"),
        'Die Kopie liegt unter storage/showcase/');

    // Und der Worker darf sie nicht mitaufräumen.
    $worker = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Core/Worker.php'
    );
    ok(!str_contains($worker, "/showcase"),
        'Das Aufräumen fasst den Referenzordner nicht an');
});

// ==================================================================
test('Die Designstudien sind vollständig und verschieden', function (): void {
    $seedFile = dirname(__DIR__) . '/public_html/app/Support/showcase-seed.php';
    ok(is_file($seedFile), 'Die Studien sind hinterlegt');

    $studien = require $seedFile;
    is(20, count($studien), 'Es sind zwanzig');

    $slugs = array_column($studien, 'slug');
    $farben = array_column($studien, 'accent_color');
    $titel = array_column($studien, 'title');

    is(20, count(array_unique($slugs)), 'Jede hat ihre eigene Kennung');
    is(20, count(array_unique($farben)), 'Und ihre eigene Akzentfarbe');
    is(20, count(array_unique($titel)), 'Und ihren eigenen Namen');

    foreach ($studien as $studie) {
        $slug = (string) $studie['slug'];

        // Ohne Seite wäre die Karte ein leerer Rahmen.
        $seite = dirname(__DIR__) . '/public_html/storage/showcase/' . $slug . '/index.html';
        ok(is_file($seite), 'Seite vorhanden: ' . $slug);

        $html = (string) file_get_contents($seite);

        // Eine Studie darf nicht verraten, wie sie entstanden ist – sie
        // steht auf der öffentlichen Seite.
        ok(!\WebAtze\Build\ShowcaseBuilder::leaksMethod(
            $studie['subtitle'] . ' ' . $studie['body_de'] . ' ' . $studie['body_en']
        ), 'Text verrät nichts: ' . $slug);

        // Und keine Preisangabe, wie überall sonst auf der Seite auch.
        ok(!preg_match('/(CHF|Fr\.|EUR|€)\s*\d/', $studie['body_de'] . $studie['body_en']),
            'Kein Preis im Text: ' . $slug);

        // Als Studie erkennbar – sonst wären es erfundene Kundenarbeiten.
        ok(str_contains((string) $studie['tags'], 'Designstudie'),
            'Als Studie gekennzeichnet: ' . $slug);

        // Die Miniatur zeigt auf die dauerhafte Kopie.
        is('/referenz-ansicht/' . $slug . '/', $studie['preview_path'],
            'Zeigt auf die eigene Kopie: ' . $slug);

        // Kein fremder Aufruf: Die Seite muss ohne Netz auskommen.
        ok(!preg_match('#(src|href)\s*=\s*["\']https?://#i', $html),
            'Lädt nichts von fremden Servern: ' . $slug);
    }
});

// ==================================================================
test('robots.txt verrät die versteckte Adresse nicht', function (): void {
    // robots.txt ist öffentlich. Steht der geheime Pfad darin, genügt ein
    // Aufruf dieser einen Datei, um ihn zu erfahren – dann ist der
    // versteckte Bereich nur noch dem Namen nach versteckt.
    $quelle = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Http/SiteController.php'
    );

    $start = strpos($quelle, 'public function robots(');
    ok($start !== false, 'Die Methode robots() gibt es');

    $ende = strpos($quelle, 'public function sitemap(', $start ?: 0);
    $block = substr($quelle, (int) $start, (int) $ende - (int) $start);

    // Der Pfad darf weder fest noch aus der Konfiguration hineingeraten.
    ok(!str_contains($block, "Config::get('create_path'"),
        'Der Pfad aus der Konfiguration steht nicht in robots.txt');
    ok(!preg_match('/Disallow:\s*\/create/', $block),
        'Auch nicht fest eingetragen');

    // Was dort stehen darf, steht auch weiterhin dort.
    foreach (['/vorschau/', '/api/', '/assistant/', '/worker/'] as $pfad) {
        ok(str_contains($block, 'Disallow: ' . $pfad),
            'Weiterhin gesperrt: ' . $pfad);
    }

    // Und die Seiten selbst müssen den Index von sich aus fernhalten –
    // das ist die Zusage, die den Disallow-Eintrag überflüssig macht.
    $response = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Core/Response.php'
    );
    ok(str_contains($response, "'X-Robots-Tag', 'noindex, nofollow, noarchive'"),
        'noIndex() schickt X-Robots-Tag');

    foreach (['layouts/admin.php', 'layouts/bare.php'] as $layout) {
        $html = (string) file_get_contents(
            dirname(__DIR__) . '/public_html/app/Views/' . $layout
        );
        ok(str_contains($html, 'name="robots" content="noindex, nofollow, noarchive"'),
            $layout . ' trägt die Meta-Angabe');
    }
});

// ==================================================================
test('Eingabeprüfung weist ab, was nicht passt', function (): void {
    $prüfung = Validator::make([
        'name' => '',
        'email' => 'keine-adresse',
        'farbe' => 'rot',
        'domain' => 'kein domain name',
    ])
        ->required('name', 'Name')
        ->email('email', 'E-Mail')
        ->hexColor('farbe', 'Farbe')
        ->domain('domain', 'Domain');

    ok($prüfung->fails(), 'Vier Fehler werden erkannt');
    is(4, count($prüfung->errors()), 'Und zwar genau vier');

    $gut = Validator::make([
        'name' => 'Holzwerk Brunner',
        'email' => 'info@holzwerk-brunner.ch',
        'farbe' => '#4a6b2a',
        'domain' => 'holzwerk-brunner.ch',
    ])
        ->required('name', 'Name')
        ->email('email', 'E-Mail')
        ->hexColor('farbe', 'Farbe')
        ->domain('domain', 'Domain');

    ok(!$gut->fails(), 'Gültige Eingaben kommen durch');
});

// ==================================================================
test('Kostenrechnung: Zahlen stimmen mit der Preisliste überein', function (): void {
    // 1 Mio. Eingabe- und 1 Mio. Ausgabe-Zeichen bei Opus 5:
    // 5.00 + 25.00 = 30.00 Dollar.
    // (Modell, Eingabe, Zwischenspeicher schreiben, lesen, Ausgabe)
    $kosten = ClaudeClient::costMicroDollars('claude-opus-5', 1_000_000, 0, 0, 1_000_000);
    is(30_000_000, $kosten, 'Opus 5: 5 Dollar Eingabe plus 25 Ausgabe');

    // Gelesener Zwischenspeicher kostet ein Zehntel der Eingabe.
    $gelesen = ClaudeClient::costMicroDollars('claude-opus-5', 0, 0, 1_000_000, 0);
    is(500_000, $gelesen, 'Gelesener Zwischenspeicher: ein Zehntel');

    // Geschriebener Zwischenspeicher kostet ein Viertel mehr.
    $geschrieben = ClaudeClient::costMicroDollars('claude-opus-5', 0, 1_000_000, 0, 0);
    is(6_250_000, $geschrieben, 'Geschriebener Zwischenspeicher: ein Viertel mehr');

    // Sonnet ist günstiger als Opus – sonst stimmte die Preisliste nicht.
    ok(
        ClaudeClient::costMicroDollars('claude-sonnet-5', 1_000_000, 0, 0, 1_000_000)
        < ClaudeClient::costMicroDollars('claude-opus-5', 1_000_000, 0, 0, 1_000_000),
        'Sonnet kostet weniger als Opus'
    );

    // Ein unbekanntes Modell darf nicht zum Absturz führen.
    ok(ClaudeClient::costMicroDollars('gibt-es-nicht', 1000, 0, 0, 1000) >= 0,
        'Unbekanntes Modell ergibt keine Ausnahme');
});

// ==================================================================
test('Der Seitenrahmen steht nur an einer Stelle', function (): void {
    $seite = Page::render(
        [
            'brand' => 'Holzwerk Brunner',
            'locale' => 'de',
            'theme' => ['colors' => ['primary' => '#4a6b2a']],
            'pages' => [
                ['path' => '/', 'title' => 'Start', 'in_navigation' => 1],
                ['path' => '/kontakt', 'title' => 'Kontakt', 'in_navigation' => 1],
                ['path' => '/impressum', 'title' => 'Impressum', 'in_navigation' => 1],
            ],
            'contact' => ['email' => 'info@holzwerk-brunner.ch'],
        ],
        [
            'path' => '/',
            'title' => 'Start',
            'meta_description' => 'Schreinerei aus Olten.',
            'sections' => [
                [
                    'id' => 1,
                    'type' => 'header',
                    'template_key' => Catalog::defaultKey('header'),
                    'content' => [],
                    'overrides' => [],
                ],
                [
                    'id' => 2,
                    'type' => 'hero',
                    'template_key' => Catalog::defaultKey('hero'),
                    'content' => ['title' => 'Möbel, die bleiben'],
                    'overrides' => [],
                ],
            ],
        ]
    );

    ok(str_contains($seite, '<!doctype html>'), 'Ein vollständiges Dokument');
    ok(str_contains($seite, 'Möbel, die bleiben'), 'Der Inhalt steht darin');
    ok(str_contains($seite, 'kontakt.html'), 'Verweise sind relativ');
    ok(!str_contains($seite, 'href="/kontakt.html"'), 'Und nicht absolut');

    // Der Fehler von damals: media="print" mit onload ist ein
    // Zeilenskript und wird von jeder strengen Richtlinie geblockt.
    ok(!str_contains($seite, 'onload='), 'Kein Zeilenskript im Kopf');
    ok(str_contains($seite, '<link rel="stylesheet" href="assets/css/site.css">'),
        'Das Stylesheet wird gewöhnlich eingebunden');

    // Impressum gehört in den Fussbereich, nicht ins Hauptmenü.
    $ctx = Page::context([
        'pages' => [
            ['path' => '/', 'title' => 'Start', 'in_navigation' => 1],
            ['path' => '/impressum', 'title' => 'Impressum', 'in_navigation' => 1],
        ],
    ], ['path' => '/']);

    is(1, count($ctx['nav']), 'Ein Eintrag im Hauptmenü');
    is(1, count($ctx['legal']), 'Und einer bei den Rechtlichen');
});

// ==================================================================
test('Kundenbackend: eine Änderung erreicht nur ihren Abschnitt', function (): void {
    require_once dirname(__DIR__) . '/public_html/app/Kit/admin/lib/Fields.php';

    $vorher = [
        'title' => 'Möbel, die bleiben',
        'lead' => 'Wir bauen Möbel nach Mass.',
        'image' => ['src' => 'assets/img/werkstatt.jpg', 'alt' => 'Die Werkstatt'],
    ];

    // Was der Typ kennt, wird übernommen.
    $nachher = \WebAtzeKit\Fields::merge('hero', $vorher, [
        'title' => 'Möbel fürs ganze Leben',
    ]);

    is('Möbel fürs ganze Leben', $nachher['title'], 'Die Überschrift ist neu');
    is('Wir bauen Möbel nach Mass.', $nachher['lead'], 'Der Rest bleibt unberührt');

    // Was der Typ nicht kennt, kommt nicht hinein.
    $versuch = \WebAtzeKit\Fields::merge('hero', $vorher, [
        'title' => 'Neu',
        'admin_password_hash' => '$argon2id$boese',
        'assistant_token' => 'geklaut',
        'gibt_es_nicht' => 'x',
    ]);

    ok(!isset($versuch['admin_password_hash']), 'Kein fremdes Feld wird gesetzt');
    ok(!isset($versuch['assistant_token']), 'Auch kein Kennwort');
    ok(!isset($versuch['gibt_es_nicht']), 'Und nichts Erfundenes');

    // Der Bildpfad lässt sich über das Formular nicht ändern – nur der
    // Bildtext. Sonst zeigte ein Bild plötzlich woandershin.
    $bild = \WebAtzeKit\Fields::merge('hero', $vorher, [
        'image' => ['src' => '../../../etc/passwd', 'alt' => 'Neuer Text'],
    ]);

    is('assets/img/werkstatt.jpg', $bild['image']['src'], 'Der Bildpfad bleibt');
    is('Neuer Text', $bild['image']['alt'], 'Der Bildtext ändert sich');

    // Zu langer Text wird gekürzt statt abgelehnt.
    $lang = \WebAtzeKit\Fields::merge('hero', $vorher, [
        'title' => str_repeat('a', 5000),
    ]);
    ok(mb_strlen($lang['title']) <= 140, 'Überlange Eingaben werden gekürzt');
});

// ==================================================================
test('Kundenbackend: die Ablage überlebt einen Abbruch', function (): void {
    require_once dirname(__DIR__) . '/public_html/app/Kit/admin/lib/Store.php';

    $dir = sys_get_temp_dir() . '/webatze-test-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    $store = new \WebAtzeKit\Store($dir);

    ok($store->save(['pages' => [['path' => '/', 'title' => 'Start', 'sections' => []]]]),
        'Speichern klappt');

    // Die Datei beginnt mit einem Abbruch – ein direkter Aufruf gibt
    // nichts preis.
    $roh = (string) file_get_contents($dir . '/site.php');
    ok(str_starts_with($roh, '<?php exit;'), 'Die Ablage schützt sich selbst');
    ok(str_contains($roh, '"title": "Start"'), 'Und enthält trotzdem die Daten');

    is('Start', $store->pages()[0]['title'], 'Gelesen wird, was geschrieben wurde');

    // Vor jeder Änderung eine Sicherung.
    $store->save(['pages' => [['path' => '/', 'title' => 'Neu', 'sections' => []]]]);
    is(1, count($store->backups()), 'Eine Sicherung ist da');

    $name = $store->backups()[0]['name'];
    ok($store->restore($name), 'Zurückholen klappt');
    is('Start', $store->pages()[0]['title'], 'Der frühere Stand ist zurück');

    // Über den Namen einer Sicherung darf kein Pfad hinausführen.
    ok(!$store->restore('../../../etc/passwd'), 'Pfadausbruch wird abgewiesen');
    ok(!$store->restore('site-99999999-999999.php'), 'Was es nicht gibt, kommt nicht zurück');

    delete_tree($dir);
});


// ==================================================================
// Kundensuche
// ==================================================================

test('Kundensuche: eingefügte Antworten werden vorsichtig gelesen', function (): void {
    \WebAtze\Core\Db::run('DELETE FROM prospects');

    // Der Normalfall: sauberes JSON.
    $ergebnis = \WebAtze\Domain\Prospects::import(
        '[{"name":"Muster AG","place":"Bern","score":70,"site_state":"veraltet"}]'
    );
    ok($ergebnis['ok'], 'Saubere Antwort wird gelesen');
    is(1, $ergebnis['neu'], 'Eine Firma angelegt');

    // Mit Code-Rahmen und einleitendem Satz drumherum - genau so kommt
    // es aus einem Chatfenster zurueck.
    $ergebnis = \WebAtze\Domain\Prospects::import(
        "Gerne! Hier die Firmen:\n\n```json\n[{\"name\":\"Zweite GmbH\",\"place\":\"Thun\"}]\n```\nViel Erfolg!"
    );
    ok($ergebnis['ok'], 'Auch mit Rahmen und Text drumherum');
    is(1, $ergebnis['neu'], 'Die zweite Firma ist da');

    // Fremde Feldnamen.
    \WebAtze\Domain\Prospects::import('[{"firma":"Dritte AG","stadt":"Chur","telefon":"081 000"}]');
    $dritte = \WebAtze\Core\Db::first("SELECT * FROM prospects WHERE name = 'Dritte AG'");
    is('Chur', $dritte['place'] ?? '', 'stadt wird zu place');
    is('081 000', $dritte['phone'] ?? '', 'telefon wird zu phone');

    // Dieselbe Firma kommt nicht zweimal herein.
    $ergebnis = \WebAtze\Domain\Prospects::import('[{"name":"Muster AG","place":"Bern"}]');
    is(0, $ergebnis['neu'], 'Doppelte werden erkannt');
    is(1, $ergebnis['bekannt'], 'Und als bekannt gezaehlt');

    // Unsinn wird nicht angelegt.
    ok(!\WebAtze\Domain\Prospects::import('völliger Unsinn')['ok'], 'Unlesbares wird abgewiesen');
    ok(!\WebAtze\Domain\Prospects::import('')['ok'], 'Leeres ebenso');

    // Eine Website ohne Vorsilbe bekommt eine.
    \WebAtze\Domain\Prospects::import('[{"name":"Vierte AG","website":"vierte.ch"}]');
    is(
        'https://vierte.ch',
        \WebAtze\Core\Db::value("SELECT website FROM prospects WHERE name = 'Vierte AG'"),
        'Die Adresse wird vervollstaendigt'
    );
});

test('Kundensuche: weggelegte Firmen kommen nicht wieder', function (): void {
    \WebAtze\Core\Db::run('DELETE FROM prospects');

    \WebAtze\Domain\Prospects::import('[{"name":"Nie Wieder AG","place":"Zug"}]');
    $id = (int) \WebAtze\Core\Db::value("SELECT id FROM prospects WHERE name = 'Nie Wieder AG'");

    ok(\WebAtze\Domain\Prospects::decide($id, 'nie'), 'Weglegen klappt');
    is(null, \WebAtze\Domain\Prospects::next(), 'Der Stapel ist leer');

    // Und beim naechsten Suchlauf taucht sie nicht wieder auf. Genau
    // dafuer werden abgelehnte Firmen aufbewahrt statt geloescht.
    $ergebnis = \WebAtze\Domain\Prospects::import('[{"name":"Nie Wieder AG","place":"Zug"}]');
    is(0, $ergebnis['neu'], 'Sie kommt nicht zurueck');
});

test('Kundensuche: aus einer Firma wird ein Kunde', function (): void {
    \WebAtze\Core\Db::run('DELETE FROM prospects');
    \WebAtze\Core\Db::run("DELETE FROM customers WHERE name = 'Wird Kunde AG'");

    \WebAtze\Domain\Prospects::import(
        '[{"name":"Wird Kunde AG","place":"Basel","phone":"061 000","email":"a@b.ch",'
        . '"reason":"Alte Seite","research":"Zehn Leute, seit 1990."}]'
    );
    $id = (int) \WebAtze\Core\Db::value("SELECT id FROM prospects WHERE name = 'Wird Kunde AG'");

    $kunde = \WebAtze\Domain\Prospects::toCustomer($id);
    ok($kunde !== null && $kunde > 0, 'Ein Kunde entsteht');

    $zeile = \WebAtze\Core\Db::first('SELECT * FROM customers WHERE id = :id', ['id' => $kunde]);
    is('061 000', $zeile['phone'] ?? '', 'Die Telefonnummer wandert mit');
    ok(str_contains((string) ($zeile['notes'] ?? ''), 'Zehn Leute'), 'Die Recherche geht nicht verloren');

    // Zweimal uebernehmen legt keinen zweiten Kunden an.
    is($kunde, \WebAtze\Domain\Prospects::toCustomer($id), 'Ein zweites Mal gibt denselben Kunden');
});

test('Kundensuche: der Suchauftrag ist vollständig', function (): void {
    $auftrag = \WebAtze\Domain\SearchOrder::build([
        'region' => 'im Kanton Zug',
        'branch' => 'Schreinerei',
        'count' => 7,
        'criteria' => ['keine', 'veraltet'],
    ]);

    ok(str_contains($auftrag, 'im Kanton Zug'), 'Die Gegend steht drin');
    ok(str_contains($auftrag, 'Schreinerei'), 'Die Branche auch');
    ok(str_contains($auftrag, '7 echten'), 'Und die Anzahl');
    ok(str_contains($auftrag, 'Erfinde nichts'), 'Die wichtigste Regel fehlt nie');
    ok(str_contains($auftrag, 'site_state'), 'Das Antwortformat ist beschrieben');

    // Das Beispiel im Auftrag muss sich auch wirklich einlesen lassen -
    // sonst beschreibt der Auftrag ein Format, das das Programm gar
    // nicht versteht.
    ok(preg_match('/\[\s*\{.+?\}\s*\]/s', $auftrag, $treffer) === 1, 'Ein Beispiel ist dabei');
    $beispiel = json_decode($treffer[0], true);
    ok(is_array($beispiel) && isset($beispiel[0]['name']), 'Das Beispiel ist lesbares JSON');
    ok(
        isset(\WebAtze\Domain\Prospects::SITE_STATES[$beispiel[0]['site_state']]),
        'Und benutzt einen Zustand, den das Programm kennt'
    );
});

// ==================================================================
// Hosting-Überwachung
// ==================================================================

test('Überwachung: das eigene Netz bleibt auch hier tabu', function (): void {
    // Dieselbe Sperre wie beim Einlesen alter Websites. Eine
    // Ueberwachung, die auf 127.0.0.1 zeigen darf, waere ein Fenster
    // in das Netz des Hosters.
    foreach (['http://127.0.0.1/', 'http://192.168.1.1/', 'http://localhost/'] as $adresse) {
        $ergebnis = \WebAtze\Domain\Monitor::save(['url' => $adresse, 'label' => 'test']);
        ok(!$ergebnis['ok'], 'Abgewiesen: ' . $adresse);
    }

    $ergebnis = \WebAtze\Domain\Monitor::save(['url' => 'file:///etc/passwd']);
    ok(!$ergebnis['ok'], 'Und Dateipfade erst recht');

    // Die Pruefung selbst antwortet ebenfalls mit einem Fehlschlag,
    // statt die Adresse abzurufen.
    $probe = \WebAtze\Domain\Monitor::probe('http://127.0.0.1/');
    ok(!$probe['ok'], 'Eine Pruefung ins eigene Netz schlaegt fehl');
    is(0, $probe['code'], 'Und ruft gar nichts erst ab');
});

test('Überwachung: Zustand und Verlauf werden festgehalten', function (): void {
    \WebAtze\Core\Db::run('DELETE FROM monitors');
    \WebAtze\Core\Db::run('DELETE FROM monitor_checks');

    $ergebnis = \WebAtze\Domain\Monitor::save([
        'url' => 'example.com',
        'label' => '',
        'active' => true,
        'every_minutes' => 3,
    ]);
    ok($ergebnis['ok'], 'Eine oeffentliche Adresse wird angenommen');

    $zeile = \WebAtze\Domain\Monitor::find($ergebnis['id']);
    is('https://example.com', $zeile['url'] ?? '', 'https wird ergaenzt');
    is('example.com', $zeile['label'] ?? '', 'Ohne Namen wird der Domainname genommen');
    is(5, (int) ($zeile['every_minutes'] ?? 0), 'Unter fuenf Minuten geht nicht');

    // Ein Ausfall wird gezaehlt, ohne dass dafuer das Netz gebraucht wird.
    \WebAtze\Core\Db::update('monitors', [
        'last_ok' => 0,
        'fail_streak' => 3,
        'last_checked_at' => \WebAtze\Core\Db::now(),
    ], 'id = :id', ['id' => $ergebnis['id']]);

    is(1, \WebAtze\Domain\Monitor::summary()['unten'], 'Der Ausfall taucht in der Uebersicht auf');
    is(1, count(\WebAtze\Domain\Monitor::down()), 'Und in der Liste');

    is(null, \WebAtze\Domain\Monitor::uptime($ergebnis['id']), 'Ohne Pruefung keine erfundene Quote');

    \WebAtze\Core\Db::insert('monitor_checks', [
        'monitor_id' => $ergebnis['id'], 'checked_at' => \WebAtze\Core\Db::now(),
        'ok' => 1, 'code' => 200, 'ms' => 120, 'note' => '',
    ]);
    \WebAtze\Core\Db::insert('monitor_checks', [
        'monitor_id' => $ergebnis['id'], 'checked_at' => \WebAtze\Core\Db::now(),
        'ok' => 0, 'code' => 500, 'ms' => 90, 'note' => 'kaputt',
    ]);

    is(50.0, \WebAtze\Domain\Monitor::uptime($ergebnis['id']), 'Eine von zwei Pruefungen sind 50 Prozent');

    // Dieselbe Domain wird nicht zweimal aufgenommen.
    \WebAtze\Domain\Monitor::adopt('https://example.com/start');
    is(1, \WebAtze\Domain\Monitor::summary()['gesamt'], 'Keine doppelte Ueberwachung');
});

// ==================================================================
// Der Tresor
// ==================================================================

test('Tresor: Passwörter liegen nie im Klartext und nie in der Seite', function (): void {
    \WebAtze\Core\Db::run('DELETE FROM secrets');

    $ergebnis = \WebAtze\Domain\Vault::save([
        'label' => 'Muster AG – cPanel',
        'kind' => 'cpanel',
        'username' => 'muster',
        'secret' => 'Geheim-1234!',
    ]);
    ok($ergebnis['ok'], 'Der Zugang wird angelegt');

    $roh = (string) \WebAtze\Core\Db::value(
        'SELECT secret_enc FROM secrets WHERE id = :id',
        ['id' => $ergebnis['id']]
    );
    isnt('Geheim-1234!', $roh, 'In der Datenbank steht kein Klartext');
    ok(str_starts_with($roh, 'v1.'), 'Sondern ein verschluesselter Wert');
    is('Geheim-1234!', \WebAtze\Core\Crypto::decrypt($roh), 'Der sich zurueckholen laesst');

    // Die Liste fuer die Seite enthaelt das Geheimnis nicht - auch nicht
    // verschluesselt. Was nicht in der Seite steht, kann auch nicht
    // ueber "Quelltext anzeigen" abhandenkommen.
    $liste = \WebAtze\Domain\Vault::listAll();
    is(1, count($liste), 'Ein Eintrag in der Liste');
    ok(!array_key_exists('secret_enc', $liste[0]), 'Ohne das Geheimnis');
    is(1, (int) $liste[0]['hat_geheimnis'], 'Aber mit dem Hinweis, dass eines da ist');

    // Ein leeres Passwortfeld beim Aendern loescht nichts.
    \WebAtze\Domain\Vault::save(['label' => 'Muster AG – cPanel neu', 'secret' => ''], $ergebnis['id']);
    is(
        'Geheim-1234!',
        \WebAtze\Core\Crypto::decrypt((string) \WebAtze\Core\Db::value(
            'SELECT secret_enc FROM secrets WHERE id = :id',
            ['id' => $ergebnis['id']]
        )),
        'Das bisherige Passwort bleibt stehen'
    );

    // Ohne Namen kein Eintrag.
    ok(!\WebAtze\Domain\Vault::save(['label' => '', 'secret' => 'x'])['ok'], 'Ohne Namen geht es nicht');
});

test('Tresor: aufschliessen geht nur mit dem eigenen Passwort', function (): void {
    // Der Testlauf hat diesen Fehler einmal uebersehen: Session::user()
    // gibt den Passwort-Abdruck bewusst nicht mit, und der Tresor las
    // ihn genau von dort. Aufschliessen war damit unmoeglich.
    \WebAtze\Core\Db::run("DELETE FROM users WHERE username = 'tresorprobe'");
    \WebAtze\Core\Db::run('DELETE FROM auth_sessions');
    \WebAtze\Core\Db::run('DELETE FROM rate_limits');

    $id = \WebAtze\Core\Db::insert('users', [
        'username' => 'tresorprobe',
        'password_hash' => \WebAtze\Core\Crypto::hashPassword('Richtig-1234!'),
        'display_name' => 'Probe',
        'role' => 'owner',
        'created_at' => \WebAtze\Core\Db::now(),
    ]);

    $sitzung = bin2hex(random_bytes(32));
    \WebAtze\Core\Db::insert('auth_sessions', [
        'id' => $sitzung,
        'user_id' => $id,
        'created_at' => \WebAtze\Core\Db::now(),
        'last_seen_at' => \WebAtze\Core\Db::now(),
        'expires_at' => date('Y-m-d H:i:s', time() + 3600),
    ]);

    $_SESSION['_wa_sid'] = $sitzung;

    // Den zwischengespeicherten Benutzer zuruecksetzen.
    $feld = (new ReflectionClass(\WebAtze\Core\Session::class))->getProperty('user');
    $feld->setAccessible(true);
    $feld->setValue(null, null);

    \WebAtze\Domain\Vault::lock();

    ok(!\WebAtze\Domain\Vault::unlock('falsch', '203.0.113.9')['ok'], 'Falsches Passwort geht nicht');
    ok(!\WebAtze\Domain\Vault::isOpen(), 'Und der Tresor bleibt zu');

    ok(\WebAtze\Domain\Vault::unlock('Richtig-1234!', '203.0.113.9')['ok'], 'Das richtige schliesst auf');
    ok(\WebAtze\Domain\Vault::isOpen(), 'Der Tresor ist offen');
    ok(\WebAtze\Domain\Vault::openSecondsLeft() > 800, 'Und bleibt es eine Weile');

    \WebAtze\Domain\Vault::lock();
    ok(!\WebAtze\Domain\Vault::isOpen(), 'Zuschliessen wirkt sofort');

    \WebAtze\Core\Db::delete('auth_sessions', 'id = :id', ['id' => $sitzung]);
    \WebAtze\Core\Db::delete('users', 'id = :id', ['id' => $id]);
    $feld->setValue(null, null);
    unset($_SESSION['_wa_sid']);
});

test('Tresor: geschlossen gibt er nichts heraus', function (): void {
    \WebAtze\Core\Db::run('DELETE FROM secrets');

    $ergebnis = \WebAtze\Domain\Vault::save(['label' => 'Test', 'secret' => 'streng-geheim']);

    \WebAtze\Domain\Vault::lock();
    ok(!\WebAtze\Domain\Vault::isOpen(), 'Der Tresor ist zu');

    $antwort = \WebAtze\Domain\Vault::reveal($ergebnis['id']);
    ok(!$antwort['ok'], 'Und gibt nichts heraus');
    is('', $antwort['geheimnis'], 'Wirklich nichts');

    // Aufgeschlossen dann schon.
    \WebAtze\Core\Session::put('vault_open_until', time() + 300);
    $antwort = \WebAtze\Domain\Vault::reveal($ergebnis['id']);
    ok($antwort['ok'], 'Offen gibt er das Passwort heraus');
    is('streng-geheim', $antwort['geheimnis'], 'Und zwar das richtige');

    \WebAtze\Domain\Vault::lock();
});

test('Tresor: der Vorschlag lässt sich diktieren', function (): void {
    $wort = \WebAtze\Domain\Vault::suggest(20);
    is(20, strlen($wort), 'Die gewuenschte Laenge');

    // Zeichen, die am Telefon oder auf Papier verwechselt werden,
    // kommen nicht vor.
    foreach (['l', '1', 'O', '0', 'I'] as $heikel) {
        ok(!str_contains($wort, $heikel), 'Kein ' . $heikel . ' im Vorschlag');
    }

    isnt(\WebAtze\Domain\Vault::suggest(), \WebAtze\Domain\Vault::suggest(), 'Zweimal nicht dasselbe');
    is(12, strlen(\WebAtze\Domain\Vault::suggest(4)), 'Kuerzer als zwoelf gibt es nicht');
});

// ==================================================================
// Der Kalender fürs Telefon
// ==================================================================

test('Kalender: die Datei entspricht der Norm', function (): void {
    \WebAtze\Core\Db::run('DELETE FROM appointments');
    \WebAtze\Core\Db::run('DELETE FROM todos');

    \WebAtze\Core\Db::insert('appointments', [
        'title' => 'Besprechung; mit, Komma',
        'note' => "Zeile eins\nZeile zwei",
        'starts_at' => date('Y-m-d', time() + 86400) . ' 14:30',
        'ends_at' => date('Y-m-d', time() + 86400) . ' 15:30',
        'place' => 'Bern',
        'created_at' => \WebAtze\Core\Db::now(),
        'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    \WebAtze\Core\Db::insert('todos', [
        'title' => 'Rechnung schreiben',
        'due_on' => date('Y-m-d', time() + 2 * 86400),
        'priority' => 'normal',
        'created_at' => \WebAtze\Core\Db::now(),
        'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $ics = \WebAtze\Domain\IcsFeed::build();

    ok(str_starts_with($ics, "BEGIN:VCALENDAR\r\n"), 'Beginnt richtig');
    ok(str_ends_with($ics, "END:VCALENDAR\r\n"), 'Und endet richtig');
    is(
        substr_count($ics, 'BEGIN:VEVENT'),
        substr_count($ics, 'END:VEVENT'),
        'Jeder Termin wird geschlossen'
    );
    ok(substr_count($ics, 'BEGIN:VEVENT') === 2, 'Termin und Aufgabe sind drin');

    // Ohne VALARM meldet sich das Telefon nie - das ist der Punkt der
    // ganzen Uebung.
    ok(str_contains($ics, 'BEGIN:VALARM'), 'Mit Erinnerung');
    ok(str_contains($ics, 'TRIGGER:-PT30M'), 'Eine halbe Stunde vorher');

    // Sonderzeichen muessen maskiert sein, sonst zerfaellt die Datei.
    ok(str_contains($ics, 'Besprechung\;'), 'Semikolon maskiert');
    ok(str_contains($ics, 'mit\, Komma'), 'Komma maskiert');
    ok(str_contains($ics, 'Zeile eins\nZeile zwei'), 'Zeilenumbruch maskiert');
    ok(!str_contains($ics, "DESCRIPTION:Zeile eins\r\nZeile"), 'Kein echter Umbruch im Text');

    // Keine Zeile laenger als 75 Zeichen - so will es die Norm.
    foreach (explode("\r\n", $ics) as $zeile) {
        if (strlen($zeile) > 75) {
            fehler('Zeile zu lang: ' . substr($zeile, 0, 40));
            return;
        }
    }
    bestanden('Keine Zeile ist zu lang');
});

test('Kalender: die Adresse ist nicht zu erraten', function (): void {
    $token = \WebAtze\Domain\IcsFeed::token();

    ok(strlen($token) >= 32, 'Das Zufallswort ist lang genug');
    ok(\WebAtze\Domain\IcsFeed::tokenMatches($token), 'Das richtige passt');
    ok(!\WebAtze\Domain\IcsFeed::tokenMatches('falsch'), 'Ein falsches nicht');
    ok(!\WebAtze\Domain\IcsFeed::tokenMatches(''), 'Und ein leeres erst recht nicht');

    $neu = \WebAtze\Domain\IcsFeed::newToken();
    isnt($token, $neu, 'Ein neues ist ein anderes');
    ok(!\WebAtze\Domain\IcsFeed::tokenMatches($token), 'Das alte gilt danach nicht mehr');

    ok(
        str_starts_with(\WebAtze\Domain\IcsFeed::webcalUrl('https://webatze.ch'), 'webcal://'),
        'Fuers iPhone gibt es webcal://'
    );
});

test('Kalender: die Adresse gibt ohne gültiges Wort nichts preis', function (): void {
    $router = new Router();
    \WebAtze\Core\Routes::register($router);

    is(
        'CompanyController@feed',
        route($router, 'GET', '/kalender/' . \WebAtze\Domain\IcsFeed::token() . '.ics'),
        'Die Kalenderadresse fuehrt zum Kalender'
    );

    // Und sie liegt ausserhalb der Anmeldung - sonst koennte das
    // Telefon sie nie abrufen.
    $waechter = route_middleware($router, 'GET', '/kalender/' . \WebAtze\Domain\IcsFeed::token() . '.ics');
    ok($waechter !== null, 'Sie wird gefunden');
    ok(!in_array('auth', (array) $waechter, true), 'Ohne Anmeldung erreichbar');

    // Wer kein gueltiges Wort hat, bekommt dieselbe Antwort wie fuer
    // eine unbekannte Seite - kein Hinweis, dass es hier etwas gibt.
    $antwort = (new \WebAtze\Http\CompanyController())->feed(
        (static function (): \WebAtze\Core\Request {
            $anfrage = \WebAtze\Core\Request::capture();
            $anfrage->setRouteParams(['token' => str_repeat('a', 40)]);

            return $anfrage;
        })()
    );
    is(404, $antwort->getStatus(), 'Ein falsches Wort fuehrt ins Leere');
});


// ==================================================================
// Der Unterschied zwischen SQLite und MySQL
//
// Die Prüfungen laufen auf SQLite, das Hosting auf MySQL. Jede Stelle,
// an der die beiden sich unterscheiden, ist ein Fünfhunderter, den
// niemand hier sieht. Genau das ist passiert: Ein reserviertes Wort als
// Spaltenname, ein doppelt benutzter Platzhalter, und ein Update, das
// seine Tabellen nie anlegte.
// ==================================================================

test('Datenbank: keine reservierten Wörter als Spaltennamen', function (): void {
    // Die reservierten Wörter aus MySQL 8.0. MariaDB hat fast dieselbe
    // Liste; wer beides bedienen will, meidet die Vereinigungsmenge.
    $reserviert = array_flip(explode(' ',
        'ACCESSIBLE ADD ALL ALTER ANALYZE AND AS ASC ASENSITIVE BEFORE BETWEEN BIGINT BINARY BLOB '
        . 'BOTH BY CALL CASCADE CASE CHANGE CHAR CHARACTER CHECK COLLATE COLUMN CONDITION CONSTRAINT '
        . 'CONTINUE CONVERT CREATE CROSS CUBE CUME_DIST CURRENT_DATE CURRENT_TIME CURRENT_TIMESTAMP '
        . 'CURRENT_USER CURSOR DATABASE DATABASES DAY_HOUR DAY_MICROSECOND DAY_MINUTE DAY_SECOND DEC '
        . 'DECIMAL DECLARE DEFAULT DELAYED DELETE DENSE_RANK DESC DESCRIBE DETERMINISTIC DISTINCT '
        . 'DISTINCTROW DIV DOUBLE DROP DUAL EACH ELSE ELSEIF EMPTY ENCLOSED ESCAPED EXCEPT EXISTS EXIT '
        . 'EXPLAIN FALSE FETCH FIRST_VALUE FLOAT FOR FORCE FOREIGN FROM FULLTEXT FUNCTION GENERATED GET '
        . 'GRANT GROUP GROUPING GROUPS HAVING HIGH_PRIORITY HOUR_MICROSECOND HOUR_MINUTE HOUR_SECOND IF '
        . 'IGNORE IN INDEX INFILE INNER INOUT INSENSITIVE INSERT INT INTEGER INTERVAL INTO IS ITERATE '
        . 'JOIN JSON_TABLE KEY KEYS KILL LAG LAST_VALUE LATERAL LEAD LEADING LEAVE LEFT LIKE LIMIT '
        . 'LINEAR LINES LOAD LOCALTIME LOCALTIMESTAMP LOCK LONG LONGBLOB LONGTEXT LOOP LOW_PRIORITY '
        . 'MATCH MAXVALUE MEDIUMBLOB MEDIUMINT MEDIUMTEXT MIDDLEINT MINUTE_MICROSECOND MINUTE_SECOND '
        . 'MOD MODIFIES NATURAL NOT NTH_VALUE NTILE NULL NUMERIC OF ON OPTIMIZE OPTION OPTIONALLY OR '
        . 'ORDER OUT OUTER OUTFILE OVER PARTITION PERCENT_RANK PRECISION PRIMARY PROCEDURE PURGE RANGE '
        . 'RANK READ READS REAL RECURSIVE REFERENCES REGEXP RELEASE RENAME REPEAT REPLACE REQUIRE '
        . 'RESIGNAL RESTRICT RETURN REVOKE RIGHT RLIKE ROW ROWS ROW_NUMBER SCHEMA SCHEMAS SELECT '
        . 'SENSITIVE SEPARATOR SET SHOW SIGNAL SMALLINT SPATIAL SPECIFIC SQL SQLEXCEPTION SQLSTATE '
        . 'SQLWARNING SSL STARTING STORED STRAIGHT_JOIN SYSTEM TABLE TERMINATED THEN TINYBLOB TINYINT '
        . 'TINYTEXT TO TRAILING TRIGGER TRUE UNDO UNION UNIQUE UNLOCK UNSIGNED UPDATE USAGE USE USING '
        . 'UTC_DATE UTC_TIME UTC_TIMESTAMP VALUES VARBINARY VARCHAR VARYING VIRTUAL WHEN WHERE WHILE '
        . 'WINDOW WITH WRITE XOR YEAR_MONTH ZEROFILL'
    ));

    $methode = new ReflectionMethod(\WebAtze\Core\Schema::class, 'statements');
    $methode->setAccessible(true);

    $schlecht = [];
    $geprueft = 0;

    foreach ($methode->invoke(null) as $sql) {
        foreach (explode(';', $sql) as $teil) {
            $teil = trim($teil);

            if (stripos($teil, 'CREATE TABLE') === 0 && preg_match('/\((.*)\)\s*$/s', $teil, $m) === 1) {
                foreach (explode("\n", $m[1]) as $zeile) {
                    if (preg_match('/^\s*([a-z_]+)\s+\{/i', $zeile, $s) === 1) {
                        $geprueft++;
                        if (isset($reserviert[strtoupper($s[1])])) {
                            $schlecht[] = $s[1];
                        }
                    }
                }
            } elseif (preg_match('/ADD COLUMN\s+([a-z_]+)\s/i', $teil, $s) === 1) {
                $geprueft++;
                if (isset($reserviert[strtoupper($s[1])])) {
                    $schlecht[] = $s[1];
                }
            }
        }
    }

    ok($geprueft > 150, $geprueft . ' Spaltennamen geprüft');
    is([], $schlecht, 'Keine davon ist in MySQL reserviert');
});

test('Datenbank: derselbe Platzhalter darf mehrfach vorkommen', function (): void {
    // SQLite erlaubt das, MySQL mit echten Prepared Statements nicht.
    // Zweimal hat das zu einer Fehlerseite geführt, die es nur auf dem
    // Hosting gab - beim zweiten Mal in einer Abfrage, die aus Stücken
    // zusammengesetzt war und deshalb keiner Suche im Quelltext auffiel.
    //
    // Db::run() löst wiederholte Platzhalter jetzt selbst auf. Damit
    // verhalten sich beide Datenbanken gleich.
    \WebAtze\Core\Db::run('DELETE FROM prospects');

    foreach ([['Alpha AG', 'Bern'], ['Beta GmbH', 'Alpha'], ['Gamma AG', 'Chur']] as [$name, $ort]) {
        \WebAtze\Core\Db::insert('prospects', [
            'name' => $name, 'place' => $ort, 'status' => 'neu', 'score' => 0,
            'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
        ]);
    }

    $treffer = \WebAtze\Core\Db::all(
        'SELECT name FROM prospects WHERE LOWER(name) LIKE :s OR LOWER(place) LIKE :s ORDER BY name',
        ['s' => '%alpha%']
    );

    is(2, count($treffer), 'Zweimal derselbe Platzhalter findet beides');
    is('Alpha AG', $treffer[0]['name'] ?? '', 'Der Treffer im Namen');
    is('Beta GmbH', $treffer[1]['name'] ?? '', 'Und der im Ort');

    // Auch dreimal, und gemischt mit anderen Platzhaltern.
    $drei = \WebAtze\Core\Db::all(
        'SELECT name FROM prospects
         WHERE (LOWER(name) LIKE :s OR LOWER(place) LIKE :s OR LOWER(status) LIKE :s)
           AND score = :punkte',
        ['s' => '%alpha%', 'punkte' => 0]
    );
    is(2, count($drei), 'Dreimal geht ebenso, neben anderen Platzhaltern');

    // Ein Doppelpunkt in einem Text ist kein Platzhalter.
    \WebAtze\Core\Db::insert('prospects', [
        'name' => 'Mit:Doppelpunkt', 'place' => '', 'status' => 'neu', 'score' => 5,
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    is(
        1,
        (int) \WebAtze\Core\Db::value(
            "SELECT COUNT(*) FROM prospects WHERE name = 'Mit:Doppelpunkt' AND score = :p",
            ['p' => 5],
            0
        ),
        'Ein Doppelpunkt im Text bleibt Text'
    );

    \WebAtze\Core\Db::run('DELETE FROM prospects');
});

test('Datenbank: ein Update bringt die Tabellen selbst auf Stand', function (): void {
    // Das Update-Paket enthält bewusst keine install.php - und damit lief
    // migrate() auf dem Hosting nie. Jede Seite, die eine neue Tabelle
    // brauchte, endete im Fünfhunderter. ensureCurrent() ist die Antwort
    // darauf; hier wird geprüft, dass sie wirkt.
    $merker = STORAGE_DIR . '/schema-version.txt';
    $vorher = is_file($merker) ? (string) file_get_contents($merker) : null;
    @unlink($merker);

    \WebAtze\Core\Db::run('DROP TABLE IF EXISTS prospects');
    \WebAtze\Core\Db::run('DELETE FROM migrations');

    \WebAtze\Core\Schema::ensureCurrent();

    ok(
        \WebAtze\Core\Db::value('SELECT COUNT(*) FROM prospects') !== null,
        'Die fehlende Tabelle ist wieder da'
    );
    ok(is_file($merker), 'Und der Merker steht');

    // Ein zweiter Aufruf darf nichts mehr tun.
    $stand = (string) file_get_contents($merker);
    \WebAtze\Core\Schema::ensureCurrent();
    is($stand, (string) file_get_contents($merker), 'Ein zweiter Aufruf ändert nichts');

    // Und der Merker hängt an der Liste der Migrationen, nicht an einer
    // Zahl, die jemand von Hand hochzählen müsste.
    $fingerprint = new ReflectionMethod(\WebAtze\Core\Schema::class, 'fingerprint');
    $fingerprint->setAccessible(true);
    is($fingerprint->invoke(null), $stand, 'Der Merker ist der Fingerabdruck der Migrationen');

    if ($vorher !== null) {
        file_put_contents($merker, $vorher);
    }
});


// ==================================================================
// Der Tresor ohne brauchbaren Schlüssel
//
// Ein crypto_key, der fehlt oder nichts taugt, liess das Speichern
// eines Passworts in einer Fehlerseite enden - und die sagte nicht,
// woran es lag. Die Seite selbst lud, das Aufschliessen klappte; nur
// das Speichern brach ab. Genau diese Kombination macht es schwer zu
// finden, deshalb steht sie hier fest.
// ==================================================================

test('Verschlüsselung: fehlende Voraussetzungen werden benannt', function (): void {
    $echt = \WebAtze\Core\Config::get('crypto_key', '');

    is('', \WebAtze\Core\Crypto::status(), 'Mit gutem Schlüssel gibt es nichts zu melden');
    ok(\WebAtze\Core\Crypto::isReady(), 'Und verschlüsseln ist möglich');

    \WebAtze\Core\Config::set('crypto_key', '');
    ok(str_contains(\WebAtze\Core\Crypto::status(), 'fehlt'), 'Fehlender Schlüssel wird benannt');

    \WebAtze\Core\Config::set('crypto_key', 'abc123');
    ok(str_contains(\WebAtze\Core\Crypto::status(), 'zu kurz'), 'Zu kurzer Schlüssel wird benannt');
    ok(str_contains(\WebAtze\Core\Crypto::status(), '6 statt 64'), 'Mit der tatsächlichen Länge');

    \WebAtze\Core\Config::set('crypto_key', str_repeat('Z', 64));
    ok(str_contains(\WebAtze\Core\Crypto::status(), '0-9 und a-f'), 'Kein Hex wird benannt');

    // Jede Meldung nennt die Datei - sonst weiss niemand, wo zu suchen ist.
    foreach (['', 'abc123', str_repeat('Z', 64)] as $kaputt) {
        \WebAtze\Core\Config::set('crypto_key', $kaputt);
        ok(
            str_contains(\WebAtze\Core\Crypto::status(), 'app/config.php'),
            'Die Meldung sagt, wo der Schlüssel steht'
        );
    }

    \WebAtze\Core\Config::set('crypto_key', $echt);
    is(64, strlen(\WebAtze\Core\Crypto::newKey()), 'Ein neuer Schlüssel hat 64 Zeichen');
    ok(hex2bin(\WebAtze\Core\Crypto::newKey()) !== false, 'Und ist gültiges Hex');
});

test('Tresor: ohne Schlüssel wird nichts gespeichert, aber auch nichts abgestürzt', function (): void {
    \WebAtze\Core\Db::run('DELETE FROM secrets');
    $echt = \WebAtze\Core\Config::get('crypto_key', '');

    foreach (['', 'abc123', str_repeat('Z', 64)] as $kaputt) {
        \WebAtze\Core\Config::set('crypto_key', $kaputt);

        $ergebnis = \WebAtze\Domain\Vault::save(['label' => 'Probe', 'secret' => 'geheim']);

        ok(!$ergebnis['ok'], 'Gespeichert wird nicht');
        ok(
            str_contains($ergebnis['meldung'], 'nicht verschlüsseln'),
            'Und die Meldung sagt, warum'
        );
    }

    is(
        0,
        (int) \WebAtze\Core\Db::value('SELECT COUNT(*) FROM secrets', [], 0),
        'Es liegt auch wirklich nichts in der Ablage'
    );

    // Ohne Passwortfeld ist der Schlüssel egal - ein Name lässt sich
    // auch ohne Verschlüsselung ändern.
    \WebAtze\Core\Config::set('crypto_key', '');
    ok(\WebAtze\Domain\Vault::save(['label' => 'Nur ein Name'])['ok'], 'Ohne Passwort geht es trotzdem');

    // Und das Anzeigen stürzt ebenfalls nicht ab.
    \WebAtze\Core\Config::set('crypto_key', $echt);
    $id = \WebAtze\Domain\Vault::save(['label' => 'Mit Passwort', 'secret' => 'geheim'])['id'];
    \WebAtze\Core\Session::put('vault_open_until', time() + 300);

    \WebAtze\Core\Config::set('crypto_key', 'abc123');
    $antwort = \WebAtze\Domain\Vault::reveal($id);
    ok(!$antwort['ok'], 'Anzeigen scheitert sauber');
    is('', $antwort['geheimnis'], 'Und gibt nichts heraus');

    \WebAtze\Core\Config::set('crypto_key', $echt);
    \WebAtze\Domain\Vault::lock();
    \WebAtze\Core\Db::run('DELETE FROM secrets');
});

test('Konfiguration: ein Eintrag lässt sich ändern, ohne den Rest zu verlieren', function (): void {
    // Die Datei ist von Hand lesbar und soll es bleiben. Ein var_export()
    // über alles würde jeden Kommentar darin verschlucken.
    $ordner = STORAGE_DIR . '/tmp/cfgprobe';
    ensure_dir($ordner);
    $datei = $ordner . '/config.php';

    file_put_contents($datei, <<<'PHP'
<?php

// Ein Kommentar, der stehen bleiben muss.
return [
    'app_url' => 'https://beispiel.ch',
    'app_key' => 'aaaa',
    'crypto_key' => 'alt',
    'db' => ['driver' => 'mysql', 'database' => 'test'],
];
PHP);

    // ConfigFile arbeitet auf APP_DIR; für die Prüfung wird die Methode
    // mit dem Probeordner aufgerufen.
    $inhalt = (string) file_get_contents($datei);
    $neu = preg_replace("/'crypto_key'\s*=>\s*'[^']*',/", "'crypto_key' => 'neu1234',", $inhalt, 1);
    file_put_contents($datei, (string) $neu);

    $gelesen = require $datei;

    is('neu1234', $gelesen['crypto_key'] ?? '', 'Der neue Wert steht drin');
    is('https://beispiel.ch', $gelesen['app_url'] ?? '', 'Die anderen Werte bleiben');
    is('test', $gelesen['db']['database'] ?? '', 'Auch die verschachtelten');
    ok(str_contains((string) file_get_contents($datei), 'Ein Kommentar'), 'Und der Kommentar bleibt');

    delete_tree($ordner);
});

test('Konfiguration: nur erlaubte Einträge, nur harmlose Werte', function (): void {
    // Der Wert landet in einer PHP-Datei. Was dort ein Anführungszeichen
    // setzen könnte, darf gar nicht erst hinein.
    $erlaubt = new ReflectionClassConstant(\WebAtze\Core\ConfigFile::class, 'ERLAUBT');
    $liste = $erlaubt->getValue();

    ok(in_array('crypto_key', $liste, true), 'crypto_key darf gesetzt werden');
    ok(!in_array('db', $liste, true), 'Die Datenbankangaben nicht');
    ok(!in_array('anthropic', $liste, true), 'Der API-Schlüssel auch nicht');

    isnt('', \WebAtze\Core\ConfigFile::set('db', 'egal'), 'Ein nicht erlaubter Eintrag wird abgewiesen');

    foreach (["a' , 'x' => '", 'mit leerzeichen', "zeile\numbruch", str_repeat('a', 300)] as $boese) {
        isnt('', \WebAtze\Core\ConfigFile::set('crypto_key', $boese), 'Abgewiesen: ' . substr($boese, 0, 18));
    }
});


// ==================================================================
// Fehlende PHP-Erweiterungen
//
// Auf geteiltem Hosting ist «sodium» nicht selbstverständlich. Fehlt
// sie, gibt es weder die Funktionen noch die Konstanten - und ein
// Zugriff auf eine undefinierte Konstante ist in PHP 8 nicht etwa ein
// Hinweis, sondern das Ende der Anfrage. Genau daran ist die
// Tresorseite einmal gestorben, ausgerechnet an der Zeile, die einen
// Ersatzschlüssel vorschlagen sollte.
// ==================================================================

test('Verschlüsselung: die Prüfung selbst braucht keine Erweiterung', function (): void {
    // Diese Methoden laufen auch dort, wo libsodium fehlt. Sie dürfen
    // deshalb kein Sodium-Symbol anfassen - ausser in function_exists(),
    // das genau dafür da ist.
    foreach (['status', 'isReady', 'newKey', 'method', 'diagnosis', 'hasSodium'] as $name) {
        $methode = new ReflectionMethod(\WebAtze\Core\Crypto::class, $name);
        $zeilen = array_slice(
            (array) file((string) $methode->getFileName()),
            $methode->getStartLine() - 1,
            $methode->getEndLine() - $methode->getStartLine() + 1
        );

        $code = preg_replace('/function_exists\([^)]*\)/', '', implode('', $zeilen)) ?? '';
        // Zeichenketten für die Anzeige zählen nicht als Aufruf.
        $code = preg_replace("/'[^']*'/", '', $code) ?? $code;

        ok(
            preg_match('/SODIUM_|sodium_/', $code) !== 1,
            'Crypto::' . $name . '() kommt ohne Sodium aus'
        );
    }

    is(64, strlen(\WebAtze\Core\Crypto::newKey()), 'Der Vorschlag ist 64 Zeichen lang');
    is(
        32,
        strlen((string) hex2bin(\WebAtze\Core\Crypto::newKey())),
        'Was 32 Bytes ergibt, wie beide Verfahren es verlangen'
    );
});

test('Verschlüsselung: beide Verfahren, und beide lesen einander nicht kaputt', function (): void {
    // Auf geteiltem Hosting ist libsodium nicht selbstverständlich.
    // Deshalb kann WebAtze auch mit OpenSSL - und muss Bestehendes
    // weiter lesen können, egal womit es geschrieben wurde.
    ok(\WebAtze\Core\Crypto::hasOpenssl(), 'OpenSSL mit AES-256-GCM steht bereit');
    isnt('', \WebAtze\Core\Crypto::method(), 'Ein Verfahren ist da');

    $verpackt = \WebAtze\Core\Crypto::encrypt('streng geheim');

    ok(
        str_starts_with($verpackt, 'v1.') || str_starts_with($verpackt, 'v2.'),
        'Der Wert trägt seine Vorsilbe'
    );
    isnt('streng geheim', $verpackt, 'Und steht nicht im Klartext da');
    is('streng geheim', \WebAtze\Core\Crypto::decrypt($verpackt), 'Zurückholen klappt');

    // Ein v2-Wert von Hand gebaut, wie ihn ein Server ohne libsodium
    // schreiben würde - er muss sich hier ebenfalls öffnen lassen.
    $key = (string) hex2bin(substr((string) \WebAtze\Core\Config::get('crypto_key'), 0, 64));
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt('von einem anderen Server', 'aes-256-gcm', $key,
        OPENSSL_RAW_DATA, $iv, $tag, '', 16);

    is(
        'von einem anderen Server',
        \WebAtze\Core\Crypto::decrypt('v2.' . base64_encode($iv . $tag . $cipher)),
        'Ein v2-Wert lässt sich lesen'
    );

    // Verändertes gilt nicht - beide Verfahren weisen es ab.
    $roh = base64_decode(substr($verpackt, 3), true);
    $roh[strlen($roh) - 1] = $roh[strlen($roh) - 1] === 'A' ? 'B' : 'A';
    is(
        null,
        \WebAtze\Core\Crypto::decrypt(substr($verpackt, 0, 3) . base64_encode($roh)),
        'Ein verändertes Geheimnis wird abgewiesen'
    );

    is(null, \WebAtze\Core\Crypto::decrypt('v3.irgendwas'), 'Unbekannte Vorsilbe gibt nichts');
    is(null, \WebAtze\Core\Crypto::decrypt('nur text'), 'Und roher Text erst recht nicht');

    // Was in einem Verfahren liegt, das der Server nicht kann, wird
    // benannt statt stillschweigend als "falsches Passwort" behandelt.
    ok(
        !\WebAtze\Core\Crypto::needsMissingMethod($verpackt),
        'Der eigene Wert braucht nichts Fehlendes'
    );
});

test('Tresor: ein Eintrag aus einem fehlenden Verfahren wird benannt', function (): void {
    // Ein mit libsodium geschriebener Eintrag auf einem Server ohne
    // libsodium ist etwas anderes als ein gewechselter Schlüssel. Beides
    // gleich zu benennen schickt einen auf die falsche Fährte.
    \WebAtze\Core\Db::run('DELETE FROM secrets');

    // Ein Wert in einem Verfahren, das es hier nicht gibt.
    $id = (int) \WebAtze\Core\Db::insert('secrets', [
        'label' => 'Aus der Zukunft',
        'kind' => 'weiteres',
        'username' => '',
        'secret_enc' => 'v9.' . base64_encode(random_bytes(40)),
        'url' => '',
        'note' => '',
        'created_at' => \WebAtze\Core\Db::now(),
        'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    \WebAtze\Core\Session::put('vault_open_until', time() + 300);
    $antwort = \WebAtze\Domain\Vault::reveal($id);

    ok(!$antwort['ok'], 'Unbekanntes Verfahren gibt nichts heraus');
    is('', $antwort['geheimnis'], 'Und wirklich nichts');

    \WebAtze\Domain\Vault::lock();
    \WebAtze\Core\Db::run('DELETE FROM secrets');
});

test('Verschlüsselung: Geheimnisse werden auch ohne sodium_memzero geleert', function (): void {
    $geheimnis = 'sehr geheim';
    \WebAtze\Core\Crypto::wipe($geheimnis);
    // sodium_memzero() setzt auf null, der Ersatzweg ebenso - beides
    // heisst: da steht nichts Brauchbares mehr.
    ok($geheimnis === null || $geheimnis === '', 'Nach dem Leeren ist nichts mehr da');

    // Und im eigenen Code steht kein ungeschütztes sodium_memzero mehr.
    $treffer = [];

    $dateien = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__) . '/public_html/app')
    );

    foreach ($dateien as $datei) {
        if ($datei->getExtension() !== 'php'
            || str_contains($datei->getPathname(), '/vendor/')
            || str_ends_with($datei->getPathname(), 'Core/Crypto.php')) {
            continue;
        }

        foreach ((array) file($datei->getPathname()) as $nummer => $zeile) {
            $ohneText = preg_replace("/'[^']*'/", '', (string) $zeile) ?? '';
            $ohneText = preg_replace('#^\s*(\*|//).*#', '', $ohneText) ?? $ohneText;

            if (preg_match('/\bsodium_[a-z_]+\s*\(/', $ohneText) === 1) {
                $treffer[] = basename($datei->getPathname()) . ':' . ($nummer + 1);
            }
        }
    }

    is([], $treffer, 'Nur Crypto selbst ruft Sodium auf');
});

test('Verschlüsselung: entschlüsseln antwortet mit null statt mit einer Ausnahme', function (): void {
    // Die Aufrufer prüfen alle auf null und haben dafür eine Meldung.
    // Eine Ausnahme wäre an ihrer Stelle eine Fehlerseite.
    $echt = \WebAtze\Core\Config::get('crypto_key', '');
    $verpackt = \WebAtze\Core\Crypto::encrypt('geheim');

    foreach (['', 'abc123', str_repeat('Z', 64)] as $kaputt) {
        \WebAtze\Core\Config::set('crypto_key', $kaputt);

        $antwort = null;
        $flog = false;

        try {
            $antwort = \WebAtze\Core\Crypto::decrypt($verpackt);
        } catch (\Throwable) {
            $flog = true;
        }

        ok(!$flog, 'Kein Absturz bei kaputtem Schlüssel');
        is(null, $antwort, 'Sondern schlicht nichts');
    }

    \WebAtze\Core\Config::set('crypto_key', $echt);
    is('geheim', \WebAtze\Core\Crypto::decrypt($verpackt), 'Mit gutem Schlüssel kommt es zurück');
});

test('Tresorseite: die Fehlerdiagnose reisst die Seite nicht mit', function (): void {
    // Die Liste ist der Zweck der Seite, die Diagnose nur Beiwerk. Was
    // dort schiefgeht, muss als Meldung enden - nicht als Fünfhunderter.
    $methode = new ReflectionMethod(\WebAtze\Http\VaultController::class, 'schluesselLage');
    $zeilen = implode('', array_slice(
        (array) file((string) $methode->getFileName()),
        $methode->getStartLine() - 1,
        $methode->getEndLine() - $methode->getStartLine() + 1
    ));

    ok(str_contains($zeilen, 'catch (\Throwable'), 'Sie fängt alles ab');

    // Und liefert in jedem Fall die Schlüssel, die die Ansicht braucht.
    $methode->setAccessible(true);
    $lage = $methode->invoke(new \WebAtze\Http\VaultController());

    foreach (['schluesselFehler', 'verschluesselt', 'schluesselSchreibbar',
              'reparierbar', 'vorschlagSchluessel'] as $schluessel) {
        ok(array_key_exists($schluessel, $lage), 'Die Ansicht bekommt «' . $schluessel . '»');
    }

    ok(is_bool($lage['reparierbar']), 'reparierbar ist ein Ja-oder-Nein');
});


// ==================================================================
// Websites: selbst gebaute und hinzugefügte
// ==================================================================

test('Websites: eine bestehende lässt sich eintragen und zuordnen', function (): void {
    \WebAtze\Core\Db::run('DELETE FROM projects');
    \WebAtze\Core\Db::run("DELETE FROM customers WHERE name = 'Kunde mit Website'");

    $kunde = (int) \WebAtze\Core\Db::insert('customers', [
        'name' => 'Kunde mit Website', 'contact_name' => '', 'email' => '', 'phone' => '',
        'website' => '', 'status' => 'aktiv', 'notes' => '',
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $ergebnis = \WebAtze\Domain\Websites::save([
        'name' => 'Holzbau Steiner AG',
        'domain' => 'https://steiner.ch/',
        'platform' => 'WordPress',
        'hosting' => 'Hostpoint',
        'status' => 'live',
    ]);

    ok($ergebnis['ok'], 'Die Website wird angelegt');

    $zeile = \WebAtze\Domain\Websites::find($ergebnis['id']);

    is('hand', $zeile['source'] ?? '', 'Sie ist als hinzugefügt vermerkt');
    is('steiner.ch', $zeile['domain'] ?? '', 'Die Adresse wird auf den nackten Namen gekürzt');
    is('https://steiner.ch', \WebAtze\Domain\Websites::url($zeile), 'Zum Anklicken wieder mit https');
    isnt('', (string) ($zeile['slug'] ?? ''), 'Ein Kurzname entsteht mit');

    // Ohne Namen geht es nicht.
    ok(!\WebAtze\Domain\Websites::save(['name' => ''])['ok'], 'Ohne Namen wird nichts angelegt');

    // Zwei gleichnamige bekommen verschiedene Kurznamen - der ist eindeutig.
    $zweite = \WebAtze\Domain\Websites::save(['name' => 'Holzbau Steiner AG']);
    isnt(
        $zeile['slug'] ?? '',
        (\WebAtze\Domain\Websites::find($zweite['id'])['slug'] ?? ''),
        'Der zweite Kurzname ist ein anderer'
    );

    // Zuordnen und wieder lösen.
    ok(\WebAtze\Domain\Websites::assign($ergebnis['id'], $kunde), 'Zuordnen klappt');
    is(
        $kunde,
        (int) (\WebAtze\Domain\Websites::find($ergebnis['id'])['customer_id'] ?? 0),
        'Der Kunde steht dran'
    );
    is(1, count(\WebAtze\Domain\Websites::forCustomer($kunde)), 'Und taucht bei ihm auf');

    \WebAtze\Domain\Websites::assign($ergebnis['id'], 0);
    is(
        null,
        \WebAtze\Domain\Websites::find($ergebnis['id'])['customer_id'],
        'Lösen geht auch'
    );
});

test('Websites: die Liste zeigt beide Herkünfte', function (): void {
    \WebAtze\Core\Db::run('DELETE FROM projects');

    // Eine selbst gebaute, wie sie der Generator anlegt.
    \WebAtze\Core\Db::insert('projects', [
        'slug' => 'gebaut', 'name' => 'Selbst gebaut', 'status' => 'live',
        'brief' => '{}', 'domain' => 'gebaut.ch',
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    \WebAtze\Domain\Websites::save(['name' => 'Hinzugefügt', 'domain' => 'dazu.ch']);

    is(2, count(\WebAtze\Domain\Websites::all()), 'Beide stehen in einer Liste');

    // Der Generator setzt 'source' nicht - der Vorgabewert muss stimmen,
    // sonst fiele jedes bestehende Projekt aus der Liste.
    $gebaut = \WebAtze\Core\Db::first("SELECT * FROM projects WHERE slug = 'gebaut'");
    is('ki', $gebaut['source'] ?? '', 'Bestehende Projekte gelten als selbst gebaut');

    is(1, count(\WebAtze\Domain\Websites::all('', 'hand')), 'Nach Herkunft filtern geht');
    is(1, count(\WebAtze\Domain\Websites::all('', 'ki')), 'In beide Richtungen');
    is(1, count(\WebAtze\Domain\Websites::all('dazu')), 'Und suchen auch');

    $zahlen = \WebAtze\Domain\Websites::counts();
    is(2, $zahlen['gesamt'], 'Die Zählung stimmt');
    is(1, $zahlen['gebaut'], 'Selbst gebaut: eine');
    is(1, $zahlen['hinzugefuegt'], 'Hinzugefügt: eine');
});

test('Websites: nur hinzugefügte lassen sich hier entfernen', function (): void {
    \WebAtze\Core\Db::run('DELETE FROM projects');

    // An einer selbst gebauten hängen Abschnitte, Pakete und Vorschauen.
    // Die wird auf ihrer Projektseite gelöscht, wo alles mit aufgeräumt
    // wird - hier wäre sie nur halb weg.
    $gebaut = (int) \WebAtze\Core\Db::insert('projects', [
        'slug' => 'nicht-loeschen', 'name' => 'Selbst gebaut', 'status' => 'live',
        'brief' => '{}', 'created_at' => \WebAtze\Core\Db::now(),
        'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    ok(!\WebAtze\Domain\Websites::remove($gebaut), 'Eine selbst gebaute bleibt stehen');
    isnt(null, \WebAtze\Domain\Websites::find($gebaut), 'Sie ist noch da');

    $hand = \WebAtze\Domain\Websites::save(['name' => 'Weg damit'])['id'];
    ok(\WebAtze\Domain\Websites::remove($hand), 'Eine hinzugefügte lässt sich entfernen');
    is(null, \WebAtze\Domain\Websites::find($hand), 'Und ist weg');

    ok(!\WebAtze\Domain\Websites::remove(999999), 'Was es nicht gibt, geht auch nicht');
});

test('Websites: die Überwachung wird zugeordnet gefunden', function (): void {
    \WebAtze\Core\Db::run('DELETE FROM projects');
    \WebAtze\Core\Db::run('DELETE FROM monitors');

    $id = \WebAtze\Domain\Websites::save(['name' => 'Mit Wächter', 'domain' => 'www.beispiel.ch'])['id'];

    is(null, \WebAtze\Domain\Websites::monitorFor((array) \WebAtze\Domain\Websites::find($id)),
        'Ohne Überwachung gibt es nichts');

    // Über die Verknüpfung.
    $m = (int) \WebAtze\Core\Db::insert('monitors', [
        'project_id' => $id, 'label' => 'x', 'url' => 'https://beispiel.ch', 'expect' => '',
        'active' => 1, 'every_minutes' => 15, 'last_ok' => 1, 'last_code' => 200, 'last_ms' => 90,
        'last_note' => '', 'fail_streak' => 0, 'cert_expires_on' => '', 'notify' => 1,
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);
    is($m, (int) (\WebAtze\Domain\Websites::monitorFor((array) \WebAtze\Domain\Websites::find($id))['id'] ?? 0),
        'Die verknüpfte Überwachung wird gefunden');

    // Und auch ohne Verknüpfung, über dieselbe Domain: Wer dieselbe
    // Adresse überwacht, meint dieselbe Website.
    \WebAtze\Core\Db::update('monitors', ['project_id' => null], 'id = :id', ['id' => $m]);
    is($m, (int) (\WebAtze\Domain\Websites::monitorFor((array) \WebAtze\Domain\Websites::find($id))['id'] ?? 0),
        'Auch über die Domain');
});


// ==================================================================
// Der Briefkopf aus einer Word-Vorlage
// ==================================================================

test('Briefkopf: die Absenderzeilen stehen in der Kopfzeile, nicht im Brieftext', function (): void {
    // Bei einer echten Briefvorlage steht der Absender in der Kopfzeile.
    // Der Briefkörper enthält Empfänger, Betreff und Mustertext - wer den
    // ausliest, bekommt «[Firma]» und «Guten Tag» als Absenderangaben.
    $ordner = STORAGE_DIR . '/tmp/briefprobe';
    ensure_dir($ordner);
    $datei = $ordner . '/vorlage.docx';

    $absatz = static fn (string $t): string =>
        '<w:p><w:r><w:t>' . htmlspecialchars($t, ENT_XML1) . '</w:t></w:r></w:p>';

    $huelle = static fn (string $inhalt, string $wurzel): string =>
        '<?xml version="1.0" encoding="UTF-8"?><w:' . $wurzel
        . ' xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . $inhalt . '</w:' . $wurzel . '>';

    $zip = new ZipArchive();
    $zip->open($datei, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('word/header1.xml', $huelle(
        $absatz('Tobias Reymond') . $absatz('Musterweg 1') . $absatz('3000 Bern'),
        'hdr'
    ));
    $zip->addFromString('word/document.xml', $huelle(
        $absatz('[Firma]') . $absatz('[Vorname Name]') . $absatz('Guten Tag')
        . $absatz('Freundliche Grüsse'),
        'document'
    ));
    $zip->close();

    $ergebnis = \WebAtze\Domain\Letterhead::importTemplate($datei, 'vorlage.docx');

    ok($ergebnis['ok'], 'Die Vorlage wird gelesen');
    is(['Tobias Reymond', 'Musterweg 1', '3000 Bern'], $ergebnis['zeilen'], 'Nur die Kopfzeile zählt');

    foreach (['[Firma]', 'Guten Tag', 'Freundliche Grüsse'] as $ausDemBrief) {
        ok(
            !in_array($ausDemBrief, $ergebnis['zeilen'], true),
            'Aus dem Brieftext kommt nichts: ' . $ausDemBrief
        );
    }

    delete_tree($ordner);
});

test('Briefkopf: ohne Kopfzeile hilft der Brieftext weiter', function (): void {
    // Eine Vorlage ohne Kopfzeile gibt es auch - dann ist der Körper
    // die einzige Quelle, und die ist besser als gar keine.
    $ordner = STORAGE_DIR . '/tmp/briefprobe2';
    ensure_dir($ordner);
    $datei = $ordner . '/ohnekopf.docx';

    $zip = new ZipArchive();
    $zip->open($datei, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString(
        'word/document.xml',
        '<?xml version="1.0" encoding="UTF-8"?><w:document '
        . 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:p><w:r><w:t>Meine Firma</w:t></w:r></w:p>'
        . '<w:p><w:r><w:t>Musterweg 1</w:t></w:r></w:p></w:document>'
    );
    $zip->close();

    $ergebnis = \WebAtze\Domain\Letterhead::importTemplate($datei, 'ohnekopf.docx');

    is(['Meine Firma', 'Musterweg 1'], $ergebnis['zeilen'], 'Dann zählt der Körper');

    delete_tree($ordner);
});

test('Briefkopf: ein Zierstreifen wird nicht für das Logo gehalten', function (): void {
    // Eine gestaltete Vorlage enthält oft einen Farbverlauf über die
    // ganze Breite. Der wiegt schnell mehr als das Logo - «das grösste
    // Bild» wäre also der Streifen, und der Briefkopf trüge einen
    // Balken statt eines Zeichens.
    $ordner = STORAGE_DIR . '/tmp/briefprobe3';
    ensure_dir($ordner);
    $datei = $ordner . '/mitstreifen.docx';

    // Ein breiter, flacher Streifen mit viel Rauschen, damit er gross wird.
    $streifen = imagecreatetruecolor(2400, 20);
    for ($x = 0; $x < 2400; $x++) {
        for ($y = 0; $y < 20; $y++) {
            imagesetpixel($streifen, $x, $y, imagecolorallocate(
                $streifen,
                random_int(0, 255),
                random_int(0, 255),
                random_int(0, 255)
            ));
        }
    }
    ob_start();
    imagepng($streifen);
    $streifenDaten = (string) ob_get_clean();
    imagedestroy($streifen);

    // Ein kleineres, aber logoförmiges Bild.
    $logo = imagecreatetruecolor(300, 100);
    imagefilledrectangle($logo, 0, 0, 300, 100, imagecolorallocate($logo, 43, 27, 158));
    ob_start();
    imagepng($logo);
    $logoDaten = (string) ob_get_clean();
    imagedestroy($logo);

    ok(strlen($streifenDaten) > strlen($logoDaten), 'Der Streifen ist die grössere Datei');

    $zip = new ZipArchive();
    $zip->open($datei, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString(
        'word/header1.xml',
        '<?xml version="1.0" encoding="UTF-8"?><w:hdr '
        . 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:p><w:r><w:t>Absender</w:t></w:r></w:p></w:hdr>'
    );
    $zip->addFromString('word/media/image1.png', $streifenDaten);
    $zip->addFromString('word/media/image2.png', $logoDaten);
    $zip->close();

    $ergebnis = \WebAtze\Domain\Letterhead::importTemplate($datei, 'mitstreifen.docx');

    ok($ergebnis['logo'], 'Ein Logo wurde übernommen');

    $masse = getimagesizefromstring(\WebAtze\Domain\Letterhead::logoData());

    ok(is_array($masse), 'Es ist ein lesbares Bild');
    ok(
        is_array($masse) && $masse[0] / $masse[1] < 8.0,
        'Und nicht der lange, flache Streifen'
    );

    \WebAtze\Domain\Letterhead::removeLogo();
    \WebAtze\Domain\Letterhead::removeTemplate();
    delete_tree($ordner);
});

// ==================================================================
test('Die Website-Auswahl ist nicht leer', function (): void {
    // Im Menuepunkt "Vertraege" stand im Auswahlfeld nur "bitte
    // waehlen" - immer, bei jedem Bestand. Der Grund war eine
    // Bedingung, die nach Zustaenden fragte, die es bei einer Website
    // gar nicht gibt: status IN ('done','published'). Eine Website ist
    // draft, building, ready, live, paused oder failed.
    //
    // Diese Pruefung haelt fest, dass die Auswahl nimmt, was da ist -
    // egal in welchem Zustand und egal woher.
    $vorher = \WebAtze\Core\Db::value('SELECT COUNT(*) FROM projects', [], 0);

    $ki = \WebAtze\Core\Db::insert('projects', [
        'slug' => 'auswahl-ki', 'name' => 'Selbst gebaut AG', 'status' => 'building',
        'source' => 'ki', 'locale' => 'de', 'locales' => 'de', 'domain' => 'gebaut.example',
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $hand = \WebAtze\Core\Db::insert('projects', [
        'slug' => 'auswahl-hand', 'name' => 'Von Hand AG', 'status' => 'live',
        'source' => 'hand', 'locale' => 'de', 'locales' => 'de',
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $liste = \WebAtze\Domain\Websites::pickList();
    $nummern = array_map(static fn (array $z): int => (int) $z['id'], $liste);

    ok(in_array($ki, $nummern, true), 'Eine Website im Bau steht zur Auswahl');
    ok(in_array($hand, $nummern, true), 'Eine von Hand eingetragene ebenfalls');
    is($vorher + 2, count($liste), 'Es fehlt keine');

    // Die Beschriftung nennt Kunde und Domain, sonst sehen zwei
    // gleichnamige Websites gleich aus.
    $texte = \WebAtze\Domain\Websites::pickLabels();

    ok(str_contains($texte[$ki] ?? '', 'gebaut.example'),
        'Die Beschriftung nennt die Domain');

    \WebAtze\Core\Db::delete('projects', 'id IN (' . $ki . ', ' . $hand . ')');
});

// ==================================================================
test('Besucher werden gezaehlt, aber niemand wiedererkannt', function (): void {
    $id = \WebAtze\Core\Db::insert('projects', [
        'slug' => 'zaehlprobe', 'name' => 'Zaehlprobe AG', 'status' => 'live',
        'source' => 'hand', 'locale' => 'de', 'locales' => 'de', 'domain' => 'zaehlprobe.example',
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $schluessel = \WebAtze\Domain\Visits::key($id);

    ok(preg_match('/^[a-f0-9]{16}$/', $schluessel) === 1, 'Der Zaehlschluessel hat die erwartete Form');
    is($schluessel, \WebAtze\Domain\Visits::key($id), 'Beim zweiten Fragen derselbe');

    $gefunden = \WebAtze\Domain\Visits::byKey($schluessel);
    ok($gefunden !== null && (int) $gefunden['id'] === $id, 'Der Schluessel fuehrt zur Website');

    // Ein erfundener oder boesartiger Schluessel fuehrt nirgendwohin.
    foreach (['', 'kurz', '../../etc/passwd', str_repeat('z', 16), "a' OR '1'='1"] as $unsinn) {
        ok(\WebAtze\Domain\Visits::byKey($unsinn) === null,
            'Unsinn fuehrt nirgendwohin: ' . ($unsinn === '' ? '(leer)' : mb_substr($unsinn, 0, 14)));
    }

    // Dieselbe Person zweimal: zwei Aufrufe, ein Besucher.
    \WebAtze\Domain\Visits::record($id, '/', '', '203.0.113.7', 'Mozilla/5.0 (Test)');
    \WebAtze\Domain\Visits::record($id, '/', '', '203.0.113.7', 'Mozilla/5.0 (Test)');
    \WebAtze\Domain\Visits::record($id, '/', '', '203.0.113.8', 'Mozilla/5.0 (Test)');

    $zahlen = \WebAtze\Domain\Visits::totals($id, 7);

    is(3, $zahlen['aufrufe'], 'Drei Aufrufe');
    is(2, $zahlen['besucher'], 'Aber nur zwei Besucher');

    // Ein Programm, das sich zu erkennen gibt, wird nicht gezaehlt.
    ok(!\WebAtze\Domain\Visits::record($id, '/', '', '203.0.113.9', 'Googlebot/2.1'),
        'Ein Suchmaschinenprogramm zaehlt nicht');
    is(3, \WebAtze\Domain\Visits::totals($id, 7)['aufrufe'], 'Die Zahl blieb stehen');

    // Der Anfrageteil faellt weg - sonst zaehlt jede Werbekampagne
    // dieselbe Seite als eigene.
    \WebAtze\Domain\Visits::record($id, '/kontakt?utm_source=zeitung', '', '203.0.113.10', 'Mozilla/5.0 (Test)');
    \WebAtze\Domain\Visits::record($id, '/kontakt?utm_source=radio', '', '203.0.113.11', 'Mozilla/5.0 (Test)');

    $pfade = \WebAtze\Core\Db::all('SELECT path FROM visits WHERE project_id = :p AND path LIKE :m',
        ['p' => $id, 'm' => '/kontakt%']);

    is(1, count($pfade), 'Beide Kampagnen landen auf derselben Seite');
    is('/kontakt', (string) $pfade[0]['path'], 'Ohne Anfrageteil');

    // Von der Herkunft bleibt der Rechnername - eine Suchanfrage kann
    // persoenliche Angaben enthalten und hat hier nichts zu suchen.
    \WebAtze\Domain\Visits::record($id, '/preise', 'https://www.google.com/search?q=etwas+heikles',
        '203.0.113.12', 'Mozilla/5.0 (Test)');

    $herkunft = (string) \WebAtze\Core\Db::value(
        'SELECT referrer FROM visits WHERE project_id = :p AND path = :s',
        ['p' => $id, 's' => '/preise'], ''
    );

    is('google.com', $herkunft, 'Nur der Rechnername bleibt');

    // Ein Verweis von der Website auf sich selbst ist keine Herkunft.
    \WebAtze\Domain\Visits::record($id, '/team', 'https://zaehlprobe.example/ueber-uns',
        '203.0.113.13', 'Mozilla/5.0 (Test)');

    is('', (string) \WebAtze\Core\Db::value('SELECT referrer FROM visits WHERE project_id = :p AND path = :s',
        ['p' => $id, 's' => '/team'], 'FEHLER'), 'Die eigene Seite zaehlt nicht als Herkunft');

    // Nirgends steht eine IP-Adresse.
    $alles = json_encode(\WebAtze\Core\Db::all('SELECT * FROM visits WHERE project_id = :p', ['p' => $id]))
        . json_encode(\WebAtze\Core\Db::all('SELECT * FROM visit_seen WHERE project_id = :p', ['p' => $id]));

    foreach (['203.0.113.7', '203.0.113.12'] as $adresse) {
        ok(!str_contains($alles, $adresse), 'Keine IP-Adresse gespeichert: ' . $adresse);
    }

    // Die Uebersicht kennt die eigene Website und die Kundenwebsites.
    $uebersicht = \WebAtze\Domain\Visits::overview(7);

    ok(count($uebersicht) >= 2, 'Die Uebersicht hat mehr als eine Zeile');
    ok(!empty($uebersicht[0]['eigen']), 'Die eigene Website steht zuoberst');

    \WebAtze\Core\Db::delete('visits', 'project_id = :p', ['p' => $id]);
    \WebAtze\Core\Db::delete('visit_seen', 'project_id = :p', ['p' => $id]);
    \WebAtze\Core\Db::delete('projects', 'id = :id', ['id' => $id]);
});

// ==================================================================
test('Das Intranet bleibt intern', function (): void {
    $ergebnis = \WebAtze\Domain\Notes::save([
        'title' => 'Merkzettel',
        'body' => "## Kopf\n\nEin Absatz mit **fett**.\n\n* Punkt eins\n* Punkt zwei",
        'tag' => 'Merken',
        'pinned' => true,
    ]);

    ok($ergebnis['ok'], 'Der Beitrag wurde gespeichert');

    $id = $ergebnis['id'];

    // Ohne Titel geht nichts - sonst findet man ihn nie wieder.
    ok(!\WebAtze\Domain\Notes::save(['title' => '  '])['ok'], 'Ohne Titel wird nichts angelegt');

    // Der Text wird beim Darstellen vollstaendig maskiert. Das ist keine
    // Frage der Sorgfalt, sondern der Reihenfolge: erst maskieren, dann
    // umformen.
    $boese = \WebAtze\Domain\Notes::save([
        'title' => 'Angriff',
        'body' => '<script>alert(1)</script> und <img src=x onerror=alert(2)>',
    ]);

    $html = \WebAtze\Domain\Notes::render(
        (string) \WebAtze\Domain\Notes::find($boese['id'])['body']
    );

    ok(!str_contains($html, '<script'), 'Kein Skript im Ergebnis');
    ok(!str_contains($html, '<img'), 'Kein Bild-Tag im Ergebnis');
    ok(str_contains($html, '&lt;script&gt;'), 'Sondern maskierter Text');
    ok(str_contains($html, '&lt;img src=x onerror=alert(2)&gt;'),
        'Auch das Ereignis steht nur als Text da');

    // Auszeichnung wird dargestellt.
    $schoen = \WebAtze\Domain\Notes::render("## Kopf\n\n* eins\n* zwei\n\n| A | B |\n|---|---|\n| 1 | 2 |");

    ok(str_contains($schoen, '<h3>Kopf</h3>'), 'Ueberschriften werden dargestellt');
    ok(str_contains($schoen, '<li>eins</li>'), 'Aufzaehlungen ebenfalls');
    ok(str_contains($schoen, '<table'), 'Und Tabellen');

    // Fortsetzungszeilen gehen nicht verloren und verlieren auch keinen
    // Buchstaben - rtrim mit '</li>' hatte das i von "zwei" gefressen.
    $fort = \WebAtze\Domain\Notes::render("* Punkt zwei\n  mit Fortsetzung");
    ok(str_contains($fort, 'Punkt zwei mit Fortsetzung'), 'Fortsetzungszeilen bleiben vollstaendig');

    // Anheften und Suchen.
    ok(\WebAtze\Domain\Notes::pin($id, false), 'Anheften laesst sich loesen');

    $gefunden = \WebAtze\Domain\Notes::all('merkzettel');
    ok(count($gefunden) === 1, 'Die Suche findet den Beitrag');

    ok(\WebAtze\Domain\Notes::remove($id), 'Loeschen geht');
    ok(\WebAtze\Domain\Notes::find($id) === null, 'Und danach ist er weg');

    \WebAtze\Domain\Notes::remove($boese['id']);
});

// ==================================================================
test('Die beiden Grundbeitraege stehen von Anfang an da', function (): void {
    \WebAtze\Core\Settings::put('notes_seeded', '');

    \WebAtze\Core\Db::delete('notes', '1 = 1');

    is(2, \WebAtze\Domain\Notes::seed(), 'Zwei Beitraege werden angelegt');
    is(0, \WebAtze\Domain\Notes::seed(), 'Beim zweiten Mal keiner mehr');

    $alle = \WebAtze\Domain\Notes::all();
    $texte = implode("\n", array_map(static fn (array $z): string => $z['title'] . "\n" . $z['body'], $alle));

    ok(str_contains($texte, 'bezahlten Rechnung'), 'Der Ablauf steht da');
    ok(str_contains($texte, 'Preisorientierung'), 'Die Preisorientierung ebenfalls');

    // Die Zahlen, die er genannt hat.
    foreach (['120 CHF', '250 CHF', '25 CHF', '50 CHF'] as $betrag) {
        ok(str_contains($texte, $betrag), 'Der Betrag steht drin: ' . $betrag);
    }

    ok(str_contains($texte, 'Domain'), 'Und die Regel zur Domain');

    // Und sie stehen NUR hier. Auf der oeffentlichen Website darf nie
    // eine Zahl stehen - das ist die Zusage, die diesen Beitrag
    // ueberhaupt noetig macht.
    foreach (['pages/home', 'pages/services', 'pages/contact'] as $seite) {
        $datei = APP_DIR . '/Views/' . $seite . '.php';
        if (!is_file($datei)) { continue; }
        $inhalt = (string) file_get_contents($datei);
        ok(!str_contains($inhalt, 'CHF'), 'Kein Betrag auf ' . $seite);
    }

    \WebAtze\Core\Db::delete('notes', '1 = 1');
    \WebAtze\Core\Settings::put('notes_seeded', '');
});

// ==================================================================
test('Das Menue zeigt an, was auf mich wartet', function (): void {
    \WebAtze\Core\Db::delete('leads', '1 = 1');
    \WebAtze\Core\Db::delete('support_threads', '1 = 1');

    is([], \WebAtze\Domain\Notices::all(), 'Ohne Offenes bleibt das Menue ruhig');

    \WebAtze\Core\Db::insert('leads', [
        'name' => 'Probe', 'email' => 'probe@example.com', 'message' => 'Hallo',
        'status' => 'new', 'created_at' => \WebAtze\Core\Db::now(),
    ]);

    $zeichen = \WebAtze\Domain\Notices::all();

    ok(isset($zeichen['/anfragen']), 'Eine neue Anfrage bekommt ein Zeichen');
    is(1, $zeichen['/anfragen']['anzahl'], 'Und die richtige Zahl');
    is('neue Anfrage', $zeichen['/anfragen']['was'], 'Einzahl bei einer');
    ok(!$zeichen['/anfragen']['dringend'], 'Eine Anfrage ist nicht dringend');

    \WebAtze\Core\Db::insert('support_threads', [
        'project_id' => 0, 'subject' => 'Frage', 'status' => 'open',
        'unread_for_owner' => 1, 'unread_for_customer' => 0,
        'last_message_at' => \WebAtze\Core\Db::now(), 'created_at' => \WebAtze\Core\Db::now(),
    ]);

    $zeichen = \WebAtze\Domain\Notices::all();

    ok(($zeichen['/support']['dringend'] ?? false),
        'Eine unbeantwortete Frage ist dringend');

    \WebAtze\Core\Db::delete('leads', '1 = 1');
    \WebAtze\Core\Db::delete('support_threads', '1 = 1');
});

// ==================================================================
test('Bezahlte Rechnung steht in der Buchhaltung', function (): void {
    // Frueher stand "bezahlt" nur an der Rechnung, und die Buchhaltung
    // wusste nichts davon. Die Statistik zeigte damit einen Gewinn, den
    // es nicht gab - oder eben keinen, den es gab.
    $kunde = \WebAtze\Core\Db::insert('customers', [
        'name' => 'Buchprobe AG', 'status' => 'aktiv',
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $id = \WebAtze\Build\DocumentBuilder::create([
        'kind' => 'invoice', 'customer_id' => $kunde, 'title' => 'Neue Website',
        'recipient' => ['name' => 'Buchprobe AG'],
        'items' => [['label' => 'Aufbau', 'quantity' => 1, 'price_rappen' => 12000]],
        'vat_percent' => 0, 'due_days' => 30,
    ]);

    $heute = date('Y-m-d');
    $monat = date('Y-m-01');
    $summe = static fn (): int => \WebAtze\Domain\Billing::books($monat, $heute)['einnahmen'];

    $vorher = $summe();

    \WebAtze\Build\DocumentBuilder::setStatus($id, 'paid');
    is($vorher + 12000, $summe(), 'Bezahlt heisst: der Betrag steht in der Buchhaltung');

    // Zweimal auf "bezahlt" darf nicht zweimal verbuchen - sonst
    // waechst die Einnahme mit jedem Klick.
    \WebAtze\Build\DocumentBuilder::setStatus($id, 'paid');
    is($vorher + 12000, $summe(), 'Zweimal bezahlt heisst nicht zweimal verbucht');

    \WebAtze\Build\DocumentBuilder::setStatus($id, 'sent');
    is($vorher, $summe(), 'Zurueckgestellt heisst: wieder heraus');

    \WebAtze\Build\DocumentBuilder::setStatus($id, 'paid');
    is($vorher + 12000, $summe(), 'Und wieder hinein');

    // Loeschen nimmt die Einnahme mit. Eine Zahl in der Statistik ohne
    // Beleg dahinter waere schlimmer als eine geloeschte Rechnung.
    ok(\WebAtze\Build\DocumentBuilder::remove($id), 'Die Rechnung laesst sich loeschen');
    is($vorher, $summe(), 'Mit ihr geht die Einnahme');
    ok(\WebAtze\Build\DocumentBuilder::find($id) === null, 'Und der Beleg ist weg');

    // Eine Offerte ist kein Geld, auch wenn jemand sie auf "bezahlt"
    // stellt.
    $offerte = \WebAtze\Build\DocumentBuilder::create([
        'kind' => 'offer', 'customer_id' => $kunde, 'title' => 'Vorschlag',
        'recipient' => ['name' => 'Buchprobe AG'],
        'items' => [['label' => 'Aufbau', 'quantity' => 1, 'price_rappen' => 9900]],
        'vat_percent' => 0,
    ]);

    \WebAtze\Build\DocumentBuilder::setStatus($offerte, 'paid');
    is($vorher, $summe(), 'Eine bezahlte Offerte wird nicht zu Geld');

    ok(\WebAtze\Build\DocumentBuilder::remove($offerte), 'Auch Offerten lassen sich loeschen');

    \WebAtze\Core\Db::delete('customers', 'id = :id', ['id' => $kunde]);
});

// ==================================================================
test('Zugaenge wandern von selbst in den Tresor', function (): void {
    // Beim Anlegen einer Website stehen die Zugaenge einmal im Klartext
    // da - kurz bevor sie zum Streuwert werden oder verschluesselt in
    // den Hochlade-Einstellungen verschwinden. Genau dann gehoeren sie
    // in den Tresor. Wer sie spaeter abtippen muss, tippt sie nicht ab.
    $projekt = \WebAtze\Core\Db::insert('projects', [
        'slug' => 'tresorprobe', 'name' => 'Tresorprobe AG', 'domain' => 'tresorprobe.example',
        'status' => 'building', 'source' => 'ki', 'locale' => 'de', 'locales' => 'de',
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $brief = [
        'company_name' => 'Tresorprobe AG',
        'domain' => 'tresorprobe.example',
        'wants_admin' => true,
        'admin_username' => 'tresorprobe',
        'admin_password' => 'ein-langes-Kundenpasswort-2026',
        'wants_support' => true,
        'ftp_protocol' => 'sftp',
        'ftp_host' => 'ftp.tresorprobe.example',
        'ftp_username' => 'tresorprobe_ftp',
        'ftp_password' => 'FTP-Geheimnis-2026',
        'ftp_path' => '/public_html',
    ];

    is(3, \WebAtze\Domain\Vault::adoptProject($projekt, $brief),
        'Backend, FTP und Zugangscode landen im Tresor');

    $eintraege = \WebAtze\Core\Db::all(
        "SELECT * FROM secrets WHERE label LIKE :m", ['m' => 'Tresorprobe AG%']
    );

    is(3, count($eintraege), 'Drei Eintraege sind es');

    // Die Adresse des Kundenbackends gehoert in JEDE Notiz. Ein Zugang
    // ohne die Stelle, an der er gilt, ist die halbe Auskunft.
    foreach ($eintraege as $eintrag) {
        ok(
            str_contains((string) $eintrag['note'], 'https://tresorprobe.example/admin'),
            'Die Backend-Adresse steht in der Notiz: ' . $eintrag['label']
        );
    }

    // Nichts steht im Klartext in der Datenbank.
    $alles = json_encode($eintraege);

    foreach (['ein-langes-Kundenpasswort-2026', 'FTP-Geheimnis-2026'] as $geheim) {
        ok(!str_contains($alles, $geheim), 'Nicht im Klartext gespeichert: ' . mb_substr($geheim, 0, 12));
    }

    // Aber wieder lesbar.
    $backend = null;
    foreach ($eintraege as $eintrag) {
        if ((string) $eintrag['kind'] === 'backend') {
            $backend = $eintrag;
        }
    }

    ok($backend !== null, 'Der Backend-Zugang ist dabei');
    is('ein-langes-Kundenpasswort-2026',
        \WebAtze\Core\Crypto::decrypt((string) $backend['secret_enc']),
        'Und wieder zu entschluesseln');

    // Zweimal anlegen darf nicht verdoppeln - das passiert beim
    // Neubauen derselben Website.
    is(0, \WebAtze\Domain\Vault::adoptProject($projekt, $brief),
        'Beim zweiten Lauf kommt nichts dazu');

    // Ohne Domain steht wenigstens der Pfad da, statt gar nichts.
    $ohneDomain = \WebAtze\Core\Db::insert('projects', [
        'slug' => 'ohnedomain', 'name' => 'Ohne Domain AG', 'domain' => '',
        'status' => 'building', 'source' => 'ki', 'locale' => 'de', 'locales' => 'de',
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    \WebAtze\Domain\Vault::adoptProject($ohneDomain, [
        'company_name' => 'Ohne Domain AG',
        'wants_admin' => true, 'admin_username' => 'x', 'admin_password' => 'noch-ein-langes-2026',
    ]);

    $notiz = (string) \WebAtze\Core\Db::value(
        "SELECT note FROM secrets WHERE label LIKE :m LIMIT 1", ['m' => 'Ohne Domain AG%'], ''
    );

    ok(str_contains($notiz, '/admin'), 'Auch ohne Domain steht der Pfad in der Notiz');

    \WebAtze\Core\Db::delete('secrets', 'label LIKE :m', ['m' => 'Tresorprobe AG%']);
    \WebAtze\Core\Db::delete('secrets', 'label LIKE :m', ['m' => 'Ohne Domain AG%']);
    \WebAtze\Core\Db::delete('projects', 'id IN (' . $projekt . ', ' . $ohneDomain . ')');
});

// ==================================================================
test('Gesichert wird alles, nicht nur die Datenbank', function (): void {
    // Eine Datenbanksicherung bringt die Daten zurueck, aber nicht die
    // Anwendung. Wer nach einem Ausfall vor einem leeren Webspace steht,
    // braucht beides - und zwar getrennt, damit Schluessel und
    // verschluesselte Daten nicht im selben Archiv liegen.
    \WebAtze\Core\Settings::put('backup_files_on', '');
    \WebAtze\Core\Settings::put('company_name', 'WebAtze');

    ok(\WebAtze\Build\Backup::dailyFiles(), 'Die Dateisicherung entsteht');
    ok(!\WebAtze\Build\Backup::dailyFiles(), 'Und nicht zweimal am selben Tag');

    $zeile = \WebAtze\Core\Db::first("SELECT * FROM backups WHERE scope = 'files' ORDER BY id DESC");

    ok($zeile !== null, 'Sie steht in der Liste');

    $pfad = (string) $zeile['path'];

    ok(is_file($pfad), 'Die Datei ist da');
    ok(str_contains(basename($pfad), date('Y-m-d')), 'Der Name traegt das Datum');
    ok(str_contains(basename($pfad), 'webatze'), 'Und den Namen');

    $zip = new ZipArchive();
    ok($zip->open($pfad) === true, 'Das Archiv laesst sich oeffnen');

    $namen = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $namen[] = (string) $zip->getNameIndex($i);
    }
    $zip->close();

    $enthaelt = static function (string $muster) use ($namen): bool {
        foreach ($namen as $name) {
            if (str_contains($name, $muster)) {
                return true;
            }
        }
        return false;
    };

    // Drin sein muss, was die Installation ausmacht.
    foreach ([
        'die Anwendung' => 'website/app/Core/Kernel.php',
        'der Einstiegspunkt' => 'website/index.php',
        'die Anleitung' => 'LIESMICH.txt',
    ] as $was => $muster) {
        ok($enthaelt($muster), 'Im Archiv: ' . $was);
    }

    // Nicht drin sein darf, was das Archiv aufblaeht oder es in sich
    // selbst packen wuerde.
    foreach ([
        'die Sicherungen selbst' => 'storage/backups',
        'die Vorschauen' => 'storage/previews',
        'die Protokolle' => 'storage/logs',
    ] as $was => $muster) {
        ok(!$enthaelt($muster), 'Nicht im Archiv: ' . $was);
    }

    // Loeschen nimmt Datei und Eintrag mit.
    $id = (int) $zeile['id'];

    ok(\WebAtze\Build\Backup::remove($id), 'Die Sicherung laesst sich loeschen');
    ok(!is_file($pfad), 'Die Datei ist weg');
    ok(\WebAtze\Core\Db::value('SELECT id FROM backups WHERE id = :i', ['i' => $id]) === null,
        'Und der Eintrag auch');

    // Eine Loeschanweisung bleibt im Sicherungsordner - auch wenn in der
    // Datenbank etwas anderes staende.
    $fremd = \WebAtze\Core\Db::insert('backups', [
        'scope' => 'files', 'project_id' => null,
        'path' => STORAGE_DIR . '/../app/config.php',
        'bytes' => 0, 'files' => 0, 'note' => 'Probe',
        'created_at' => \WebAtze\Core\Db::now(),
    ]);

    \WebAtze\Build\Backup::remove($fremd);

    ok(is_file(APP_DIR . '/config.php'),
        'Eine Sicherung ausserhalb des Ordners loescht dort nichts');

    \WebAtze\Core\Settings::put('backup_files_on', '');
});

// ==================================================================
test('Frontend-Fix laesst nur harmloses CSS durch', function (): void {
    // Was hier hereinkommt, hat kein Mensch geschrieben - es kommt von
    // einer Schnittstelle und landet ungesehen auf der oeffentlichen
    // Website. CSS fuehrt zwar nichts aus, aber es LAEDT: Ein @import
    // oder ein url() auf einen fremden Server holt beim ersten Besucher
    // etwas von dort, und dann stimmt die Datenschutzerklaerung nicht
    // mehr.
    $durch = [
        'eine gewoehnliche Regel' => '#leistungen { background: var(--wa-surface-2); }',
        'eigener Pfad im url()' => '.wa-hero { background-image: url(/assets/img/x.png); }',
        'eingebettetes Bild' => '.wa-hero { background: url(data:image/svg+xml;base64,AAA); }',
        'mehrere Regeln' => '.a { color: red; } .b { color: blue; }',
    ];

    foreach ($durch as $was => $css) {
        ok(\WebAtze\Domain\FrontendFix::sauber($css) !== '', 'Kommt durch: ' . $was);
    }

    $abgewiesen = [
        'fremder Server' => '.a { background: url(https://fremd.example/x.png); }',
        'protokolllose Adresse' => '.a { background: url(//fremd.example/x.png); }',
        'ein @import' => '@import url(/x.css); .a { color: red; }',
        'javascript: im url()' => '.a { background: url(javascript:alert(1)); }',
        'alte expression()' => '.a { width: expression(alert(1)); }',
        'behavior aus dem IE' => '.a { behavior: url(#default#x); }',
        'ausgebrochenes Skript' => '</style><script>alert(1)</script>',
        'offene Klammer' => '.a { color: red;',
        'leer' => '   ',
        'zu gross' => str_repeat('.a{color:red}', 2000),
    ];

    foreach ($abgewiesen as $was => $css) {
        is('', \WebAtze\Domain\FrontendFix::sauber($css), 'Wird abgewiesen: ' . $was);
    }
});

// ==================================================================
test('Eine Anpassung laesst sich zuruecknehmen', function (): void {
    // Der eigentliche Sicherheitsgurt: Was schiefgeht, ist mit einem
    // Klick wieder weg - ohne dass jemand eine Datei anfassen muss.
    \WebAtze\Core\Db::delete('frontend_fixes', '1 = 1');

    $a = \WebAtze\Core\Db::insert('frontend_fixes', [
        'prompt' => 'Leistungen dunkler', 'css' => '#leistungen { background: #12122a; }',
        'summary' => 'Leistungen dunkler', 'active' => 1,
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $b = \WebAtze\Core\Db::insert('frontend_fixes', [
        'prompt' => 'Karten luftiger', 'css' => '.wa-card { padding: 2.5rem; }',
        'summary' => 'Karten luftiger', 'active' => 1,
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    is(2, \WebAtze\Domain\FrontendFix::activeCount(), 'Zwei Anpassungen gelten');

    $css = \WebAtze\Domain\FrontendFix::css();

    ok(str_contains($css, '#leistungen'), 'Die erste steht im Stylesheet');
    ok(str_contains($css, '.wa-card'), 'Die zweite auch');

    // Die Reihenfolge zaehlt: Was spaeter kam, gewinnt bei gleicher
    // Gewichtung - das ist, was man erwartet.
    ok(strpos($css, '#leistungen') < strpos($css, '.wa-card'),
        'In der Reihenfolge des Anlegens');

    // Die Kennung in der Adresse muss sich aendern, sonst zeigt der
    // Browser tagelang die alte Fassung.
    $vorher = \WebAtze\Domain\FrontendFix::version();

    \WebAtze\Domain\FrontendFix::toggle($a, false);

    is(1, \WebAtze\Domain\FrontendFix::activeCount(), 'Abgeschaltet zaehlt nicht mehr mit');
    ok(!str_contains(\WebAtze\Domain\FrontendFix::css(), '#leistungen'),
        'Und steht nicht mehr im Stylesheet');
    ok(\WebAtze\Domain\FrontendFix::version() !== $vorher,
        'Die Kennung hat sich geaendert');

    // Aber der Eintrag bleibt - man will ihn wieder einschalten koennen.
    ok(\WebAtze\Domain\FrontendFix::find($a) !== null, 'Der Eintrag bleibt erhalten');

    \WebAtze\Domain\FrontendFix::toggle($a, true);
    ok(str_contains(\WebAtze\Domain\FrontendFix::css(), '#leistungen'), 'Und laesst sich zurueckholen');

    ok(\WebAtze\Domain\FrontendFix::remove($b), 'Loeschen geht');
    is(1, \WebAtze\Domain\FrontendFix::activeCount(), 'Danach gilt nur noch eine');

    is(1, \WebAtze\Domain\FrontendFix::clear(), 'Alles zuruecksetzen raeumt den Rest weg');
    is('', \WebAtze\Domain\FrontendFix::css(), 'Und das Stylesheet ist leer');

    @unlink(STORAGE_DIR . '/frontend-fix.css');
});

// ==================================================================
test('Die Karte nennt die Abschnitte beim Namen', function (): void {
    // Ohne sie bekaeme die Schnittstelle eine Liste aus lauter
    // ".wa-section" und wuesste bei "mach die Leistungen anders" nicht,
    // welche gemeint ist.
    $karte = \WebAtze\Domain\FrontendFix::karte();

    ok(str_contains($karte, '#leistungen'), 'Die Kennung des Abschnitts steht drin');
    ok(str_contains($karte, '.wa-hero'), 'Der Auftakt ebenfalls');
    ok(str_contains($karte, 'Alles, was deine Website braucht'),
        'Und die Ueberschrift, damit klar ist, welcher Abschnitt gemeint ist');
    ok(!str_contains($karte, 'services.title'),
        'Der Sprachschluessel selbst steht nicht drin');
    ok(!str_contains($karte, '<?'), 'Und kein PHP');
});

// ==================================================================
// Das Datenmodell des Editors
//
// Ziehen heisst: "setze diesen Abschnitt auf Platz N". Vorher gab es nur
// "tausche mit dem Nachbarn" - fuer zwei Knoepfe gebaut, nicht fuer eine
// Maus, die einen Abschnitt ueber fuenf andere hinwegzieht.
// ==================================================================

/** Eine Seite mit Abschnitten anlegen, wie sie der Generator erzeugt. */
function seite_mit_abschnitten(array $typen): array
{
    $projekt = \WebAtze\Core\Db::insert('projects', [
        'name' => 'Ziehprobe', 'slug' => 'ziehprobe-' . bin2hex(random_bytes(3)),
        'status' => 'done', 'brief' => [], 'theme' => [],
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $seite = \WebAtze\Core\Db::insert('project_pages', [
        'project_id' => $projekt, 'path' => '/', 'title' => 'Start',
        'sort_order' => 0, 'in_navigation' => 1,
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $ids = [];

    foreach ($typen as $platz => $typ) {
        $ids[] = \WebAtze\Core\Db::insert('project_sections', [
            'project_id' => $projekt, 'page_id' => $seite, 'type' => $typ,
            'template_key' => Catalog::defaultKey($typ),
            'content' => ['title' => ucfirst($typ)], 'overrides' => [], 'effects' => [],
            'hidden' => 0, 'sort_order' => $platz,
            'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
        ]);
    }

    return ['projekt' => $projekt, 'seite' => $seite, 'ids' => $ids];
}

/** Die Typen einer Seite in ihrer Reihenfolge. */
function reihenfolge(int $seite): array
{
    return array_map(
        static fn (array $a): string => (string) $a['type'],
        \WebAtze\Domain\Sections::forPage($seite)
    );
}

test('Ziehen: ein Abschnitt landet auf dem Platz, auf den er gezogen wird', function (): void {
    $s = seite_mit_abschnitten(['header', 'hero', 'features', 'services', 'faq', 'cta', 'footer']);

    // Von Platz 1 (hero) auf Platz 5 (vor den Fuss).
    $ergebnis = \WebAtze\Domain\Sections::move($s['ids'][1], 5);

    ok($ergebnis['ok'], 'Der Zug wurde ausgefuehrt');
    is(
        ['header', 'features', 'services', 'faq', 'cta', 'hero', 'footer'],
        reihenfolge($s['seite']),
        'Hero steht jetzt vor dem Fuss'
    );

    // Und wieder zurueck an den Anfang des beweglichen Bereichs.
    \WebAtze\Domain\Sections::move($s['ids'][1], 1);
    is(
        ['header', 'hero', 'features', 'services', 'faq', 'cta', 'footer'],
        reihenfolge($s['seite']),
        'Und wieder zurueck'
    );
});

test('Ziehen: die Sortierung bleibt lueckenlos', function (): void {
    $s = seite_mit_abschnitten(['header', 'hero', 'features', 'cta', 'footer']);

    \WebAtze\Domain\Sections::move($s['ids'][3], 1);

    $werte = array_map(
        static fn (array $a): int => (int) $a['sort_order'],
        \WebAtze\Domain\Sections::forPage($s['seite'])
    );

    is([0, 1, 2, 3, 4], $werte, 'Die Plaetze sind 0 bis 4, ohne Luecke');
});

test('Ziehen: Kopf und Fuss bleiben, wo sie sind', function (): void {
    $s = seite_mit_abschnitten(['header', 'hero', 'features', 'footer']);

    $kopf = \WebAtze\Domain\Sections::move($s['ids'][0], 2);
    ok(!$kopf['ok'], 'Der Kopf laesst sich nicht wegziehen');

    $fuss = \WebAtze\Domain\Sections::move($s['ids'][3], 1);
    ok(!$fuss['ok'], 'Der Fuss auch nicht');

    // Und niemand kann einen anderen Abschnitt ueber sie hinwegziehen:
    // Ein Ziel ausserhalb wird auf den Rand gezogen, nicht abgelehnt -
    // wer ganz nach unten zieht, meint "so weit wie moeglich".
    \WebAtze\Domain\Sections::move($s['ids'][1], 99);
    is(['header', 'features', 'hero', 'footer'], reihenfolge($s['seite']),
        'Ganz nach unten heisst: vor den Fuss');

    \WebAtze\Domain\Sections::move($s['ids'][1], -5);
    is(['header', 'hero', 'features', 'footer'], reihenfolge($s['seite']),
        'Ganz nach oben heisst: hinter den Kopf');
});

test('Ziehen: Kopf zuerst und Fuss zuletzt, egal was vorher dastand', function (): void {
    // Genau so ist es einmal schiefgegangen: Ein Abschnitt landete
    // hinter dem Fuss, danach galt ER als Fuss, und jeder weitere kam
    // noch dahinter. Die Zusage muss deshalb bei jedem Schreiben
    // durchgesetzt werden, nicht bloss angenommen.
    $s = seite_mit_abschnitten(['header', 'hero', 'footer', 'features']);

    is(['header', 'hero', 'footer', 'features'], reihenfolge($s['seite']),
        'Der Ausgangsstand ist absichtlich falsch');

    // Irgendein Eingriff genuegt - hier ein neuer Abschnitt.
    \WebAtze\Domain\Sections::add($s['seite'], 'cta');

    $jetzt = reihenfolge($s['seite']);

    is('header', $jetzt[0], 'Der Kopf steht wieder vorn');
    is('footer', end($jetzt), 'Und der Fuss hinten');
    ok(in_array('cta', $jetzt, true), 'Der neue Abschnitt ist da');

    // Und er steht VOR dem Fuss. "Ans Ende" heisst fuer einen Menschen
    // "unten auf der Seite", nicht "unterhalb der Fusszeile".
    is('cta', $jetzt[count($jetzt) - 2], 'Und zwar direkt vor dem Fuss');
});

test('Abschnitte: anlegen, verdoppeln, loeschen', function (): void {
    $s = seite_mit_abschnitten(['header', 'hero', 'footer']);

    $neu = \WebAtze\Domain\Sections::add($s['seite'], 'features', 2);
    ok($neu['ok'], 'Ein Abschnitt wurde eingesetzt');
    is(['header', 'hero', 'features', 'footer'], reihenfolge($s['seite']), 'Und zwar an der Stelle');

    // Er kommt mit Beispielinhalt, nicht leer: Ein leerer Abschnitt sieht
    // im Editor aus wie ein Fehler.
    $inhalt = \WebAtze\Domain\Sections::find($neu['id'])['content'];
    ok(($inhalt['title'] ?? '') !== '', 'Mit einer Ueberschrift');
    ok(count($inhalt['items'] ?? []) >= 1, 'Und mit mindestens einem Eintrag');

    $kopie = \WebAtze\Domain\Sections::duplicate($neu['id']);
    ok($kopie['ok'], 'Verdoppeln geht');
    is(['header', 'hero', 'features', 'features', 'footer'], reihenfolge($s['seite']),
        'Die Kopie steht direkt darunter');

    \WebAtze\Domain\Sections::remove($kopie['id']);
    is(['header', 'hero', 'features', 'footer'], reihenfolge($s['seite']), 'Und wieder weg');

    // Kopf und Fuss gibt es nur einmal, und geloescht werden sie nicht.
    ok(!\WebAtze\Domain\Sections::add($s['seite'], 'header')['ok'], 'Kein zweiter Kopf');
    ok(!\WebAtze\Domain\Sections::remove($s['ids'][0])['ok'], 'Der Kopf bleibt');
});

test('Listen: Eintraege ziehen, anlegen, loeschen', function (): void {
    $s = seite_mit_abschnitten(['header', 'features', 'footer']);
    $id = $s['ids'][1];

    \WebAtze\Core\Db::update('project_sections', ['content' => ['title' => 'Vorteile', 'items' => [
        ['title' => 'A', 'text' => 'a'], ['title' => 'B', 'text' => 'b'], ['title' => 'C', 'text' => 'c'],
    ]]], 'id = :id', ['id' => $id]);

    $titel = static fn (): array => array_column(
        \WebAtze\Domain\Sections::find($id)['content']['items'], 'title'
    );

    \WebAtze\Domain\Sections::moveItem($id, 0, 2);
    is(['B', 'C', 'A'], $titel(), 'Der erste Eintrag ist jetzt der letzte');

    \WebAtze\Domain\Sections::addItem($id, 0);
    is(4, count($titel()), 'Ein Eintrag kam dazu');

    \WebAtze\Domain\Sections::removeItem($id, 1);
    is(3, count($titel()), 'Und einer ging wieder');

    // Unter das Mindestmass des Schemas geht es nicht: features
    // braucht zwei Eintraege, sonst ist es keine Liste mehr.
    \WebAtze\Domain\Sections::removeItem($id, 0);
    $letzter = \WebAtze\Domain\Sections::removeItem($id, 0);
    ok(!$letzter['ok'], 'Der vorletzte Eintrag bleibt: ' . $letzter['error']);
    is(2, count($titel()), 'Es sind noch zwei da');
});

test('Effekte: was nicht im Wortschatz steht, kommt nicht durch', function (): void {
    $sauber = \WebAtze\Templates\Effects::clean([
        'hintergrund' => ['art' => 'verlauf', 'ton' => 'marke', 'staerke' => 'kraeftig'],
        'bewegung' => 'hoch',
        'parallaxe' => 'sanft',
        'kippen' => true,
        // Alles ab hier ist erfunden und muss verschwinden.
        'css' => 'body{display:none}',
        'javascript' => 'alert(1)',
    ]);

    is('verlauf', $sauber['hintergrund']['art'], 'Der Verlauf bleibt');
    is('hoch', $sauber['bewegung'], 'Die Bewegung bleibt');
    ok(!isset($sauber['css']), 'Freies CSS kommt nicht durch');
    ok(!isset($sauber['javascript']), 'Und JavaScript erst recht nicht');

    // Ein erfundener Wert scheitert nicht laut, sondern faellt auf die
    // Vorgabe zurueck. Ein Sprachmodell, das "supersanft" schreibt, soll
    // nicht die ganze Aenderung kosten.
    $daneben = \WebAtze\Templates\Effects::clean([
        'hintergrund' => ['art' => 'regenbogen'],
        'parallaxe' => 'supersanft',
    ]);

    is([], $daneben, 'Was es nicht gibt, ergibt gar nichts');

    // Und das Ergebnis wird zu Klassen und Attributen, nie zu Stil.
    $klassen = \WebAtze\Templates\Effects::classes($sauber);
    ok(str_contains($klassen, 'bg-verlauf'), 'Der Hintergrund wird eine Klasse');
    ok(!str_contains($klassen, ':'), 'Und niemals eine Eigenschaft');

    $attribute = \WebAtze\Templates\Effects::attributes($sauber);
    ok(str_contains($attribute, 'data-reveal="up"'), 'Die Bewegung wird ein Attribut');
    ok(str_contains($attribute, 'data-parallax'), 'Die Parallaxe auch');
});

test('Bausteine: eine Ebene Spalten, und kein fremdes Markup', function (): void {
    $blocks = \WebAtze\Templates\Blocks::clean([
        ['art' => 'ueberschrift', 'text' => 'Titel', 'ebene' => 'h3'],
        ['art' => 'text', 'text' => '<p>Gut</p><script>alert(1)</script>'],
        ['art' => 'spalten', 'anzahl' => 2, 'spalten' => [
            [['art' => 'text', 'text' => '<p>Links</p>']],
            [['art' => 'spalten', 'anzahl' => 3]],
        ]],
        ['art' => 'gibtsnicht'],
    ]);

    is(3, count($blocks), 'Der erfundene Baustein faellt weg');
    ok(!str_contains($blocks[1]['text'], '<script'), 'Und das Skript aus dem Text');
    is(0, count($blocks[2]['spalten'][1]), 'Spalten in Spalten gibt es nicht');

    // Warum nur eine Ebene: Spalten in Spalten in Spalten sind auf einem
    // Telefon nicht mehr sinnvoll aufzuloesen. Eine Ebene loest sich
    // immer auf - sie rutscht untereinander.
    $html = \WebAtze\Templates\Blocks::render($blocks);
    ok(str_contains($html, 'data-block="ueberschrift"'), 'Jeder Baustein traegt seine Kennung');
    ok(str_contains($html, '<h3'), 'Die gewaehlte Ebene wird benutzt');
    ok(!str_contains($html, '<script'), 'Und es entsteht kein Skript');
});

test('Entwurf und Veroeffentlicht sind zwei Staende', function (): void {
    $s = seite_mit_abschnitten(['header', 'hero', 'features', 'footer']);

    // Vor der ersten Veroeffentlichung ist alles Entwurf.
    ok(\WebAtze\Domain\Publications::hasDraft($s['seite']), 'Am Anfang gibt es einen Entwurf');

    $fassung = \WebAtze\Domain\Publications::record($s['seite'], 'Erste Fassung');
    ok($fassung > 0, 'Eine Fassung wurde festgehalten');
    ok(!\WebAtze\Domain\Publications::hasDraft($s['seite']), 'Danach nicht mehr');

    // Umbauen macht wieder einen Entwurf daraus.
    \WebAtze\Domain\Sections::move($s['ids'][1], 2);
    ok(\WebAtze\Domain\Publications::hasDraft($s['seite']), 'Ein Zug erzeugt einen Entwurf');
    is(['header', 'features', 'hero', 'footer'], reihenfolge($s['seite']), 'Und wirkt sofort');

    // Verwerfen holt den veroeffentlichten Stand zurueck.
    $weg = \WebAtze\Domain\Publications::discard($s['seite']);
    ok($weg['ok'], 'Der Entwurf laesst sich verwerfen');
    is(['header', 'hero', 'features', 'footer'], reihenfolge($s['seite']), 'Der alte Stand ist wieder da');

    // Und wer sich beim Verwerfen vertut, kommt zurueck: Vorher wurde
    // festgehalten, was dastand.
    $fassungen = \WebAtze\Domain\Publications::forPage($s['seite']);
    ok(count($fassungen) >= 2, 'Auch der verworfene Stand liegt noch da');
});

test('Auftragstext: jede Website kommt bearbeitbar auf die Welt', function (): void {
    // "Neue Website erstellen" ist ein Themengenerator: Der Aufbau liegt
    // fest, erzeugt werden Farben, Schriften, Inhalte und Varianten.
    // Faellt dieser Block weg, entsteht eine Website, die gut aussieht
    // und sich nicht bearbeiten laesst - und das merkt man erst, wenn
    // man es versucht.
    $text = \WebAtze\Domain\PromptText::build(['company' => 'Muster AG']);

    ok(str_contains($text, 'data-section-id'), 'Jeder Abschnitt gibt sich zu erkennen');
    ok(str_contains($text, 's-items'), 'Wiederholtes steht in einem Behaelter');
    ok(str_contains($text, '--c-primary'), 'Farben kommen aus Variablen');

    // Auch ohne jede Angabe im Formular. Der Block haengt an nichts, was
    // jemand vergessen koennte.
    $nackt = \WebAtze\Domain\PromptText::build([]);
    ok(str_contains($nackt, 'BEARBEITBARKEIT'), 'Und zwar in jedem Auftrag');

    // Und er verraet nicht, was darunter arbeitet.
    foreach (['Claude', 'Anthropic', 'KI-', 'GPT'] as $wort) {
        ok(!str_contains($text, $wort), 'Kein Hinweis auf "' . $wort . '"');
    }
});

// ==================================================================
// Die Bruecke
//
// Sie ist die einzige Stelle, an der WebAtze auf einem fremden Server
// schreibt. Deshalb wird hier nicht geprueft, dass sie funktioniert -
// das waere die leichte Haelfte -, sondern dass sie abweist, was sie
// abweisen muss.
// ==================================================================

test('Bruecke: die Unterschrift deckt alles ab, was die Gegenseite auswertet', function (): void {
    $secret = str_repeat('a', 64);
    $rumpf = '{"aktion":"veroeffentlichen"}';
    $zeit = time();

    $echt = \WebAtze\Domain\Bridge::sign($secret, 'POST', '/wa-bruecke.php', $zeit, 'abc123', $rumpf);

    is(64, strlen($echt), 'Eine Unterschrift ist ein SHA256');

    // Aendert sich irgendein Bestandteil, aendert sich die Unterschrift.
    // Waere das nicht so, liesse sich eine gueltige Anfrage umbiegen.
    isnt($echt, \WebAtze\Domain\Bridge::sign($secret, 'GET', '/wa-bruecke.php', $zeit, 'abc123', $rumpf),
        'Die Methode zaehlt');
    isnt($echt, \WebAtze\Domain\Bridge::sign($secret, 'POST', '/anderswo.php', $zeit, 'abc123', $rumpf),
        'Der Pfad zaehlt');
    isnt($echt, \WebAtze\Domain\Bridge::sign($secret, 'POST', '/wa-bruecke.php', $zeit + 1, 'abc123', $rumpf),
        'Der Zeitstempel zaehlt');
    isnt($echt, \WebAtze\Domain\Bridge::sign($secret, 'POST', '/wa-bruecke.php', $zeit, 'xyz789', $rumpf),
        'Der Einmalwert zaehlt');
    isnt($echt, \WebAtze\Domain\Bridge::sign($secret, 'POST', '/wa-bruecke.php', $zeit, 'abc123', $rumpf . ' '),
        'Und der Rumpf, bis aufs Byte');

    // Ein anderer Schluessel ergibt eine andere Unterschrift - das ist
    // der ganze Punkt.
    isnt($echt, \WebAtze\Domain\Bridge::sign(str_repeat('b', 64), 'POST', '/wa-bruecke.php', $zeit, 'abc123', $rumpf),
        'Und der Schluessel erst recht');

    // Der Schluessel selbst kommt in der Unterschrift nicht vor.
    ok(!str_contains($echt, substr($secret, 0, 16)), 'Der Schluessel steckt nicht darin');
});

test('Bruecke: ohne Schluessel geht gar nichts', function (): void {
    $ohne = ['id' => 1, 'domain' => 'kunde.example', 'bridge_secret' => ''];

    $antwort = \WebAtze\Domain\Bridge::call($ohne, 'ping');
    ok(!$antwort['ok'], 'Ohne Schluessel wird nicht einmal gewaehlt');

    $ohneDomain = ['id' => 1, 'domain' => '', 'bridge_secret' => str_repeat('a', 64)];
    ok(!\WebAtze\Domain\Bridge::call($ohneDomain, 'ping')['ok'], 'Und ohne Adresse auch nicht');
});

test('Bruecke: der Schluessel laesst sich zurueckziehen', function (): void {
    $projekt = \WebAtze\Core\Db::insert('projects', [
        'name' => 'Brueckenprobe', 'slug' => 'brueckenprobe-' . bin2hex(random_bytes(3)),
        'status' => 'done', 'brief' => [], 'theme' => [],
        'created_at' => \WebAtze\Core\Db::now(), 'updated_at' => \WebAtze\Core\Db::now(),
    ]);

    $erster = \WebAtze\Domain\Bridge::secret($projekt);
    is(64, strlen($erster), 'Beim ersten Fragen entsteht ein Schluessel');
    is($erster, \WebAtze\Domain\Bridge::secret($projekt), 'Beim zweiten derselbe');

    $zweiter = \WebAtze\Domain\Bridge::newSecret($projekt);
    isnt($erster, $zweiter, 'Ein neuer ist ein anderer');

    \WebAtze\Domain\Bridge::revoke($projekt);
    is('', (string) \WebAtze\Core\Db::value(
        'SELECT bridge_secret FROM projects WHERE id = :id', ['id' => $projekt], ''
    ), 'Und zurueckziehen leert ihn - es ist die Website des Kunden');
});

test('Bruecke: der Schluessel geht nie ueber die Leitung', function (): void {
    // Das ist die Zusage, die die ganze Bruecke traegt - und sie war
    // schon einmal gebrochen: Der Schluessel landete versehentlich in
    // site(), und site() ist genau der Datensatz, den die Bruecke bei
    // jeder Veroeffentlichung uebertraegt. Er waere bei jedem Kunden
    // ueber die Leitung gegangen, ohne dass es jemand gemerkt haette.
    $quelle = file_get_contents(dirname(__DIR__) . '/public_html/app/Build/SiteBuilder.php');

    $vonSite = strpos($quelle, 'public function site(): array');
    $bisEnde = strpos($quelle, 'private function writeDataConfig');

    ok($vonSite !== false && $bisEnde !== false, 'Beide Stellen sind auffindbar');

    $siteBlock = substr($quelle, $vonSite, $bisEnde - $vonSite);

    ok(!str_contains($siteBlock, 'bridge_secret'),
        'In site() steht kein Brueckenschluessel');

    ok(str_contains(substr($quelle, $bisEnde), 'bridge_secret'),
        'In writeDataConfig() dagegen schon - dort liest die Bruecke ihn');
});

test('Bruecke: die Adresse haengt an der Domain der Website', function (): void {
    is('https://kunde.ch/wa-bruecke.php',
        \WebAtze\Domain\Bridge::url(['domain' => 'kunde.ch']),
        'Ohne Schema wird https angenommen');

    is('https://kunde.ch/wa-bruecke.php',
        \WebAtze\Domain\Bridge::url(['domain' => 'https://kunde.ch/']),
        'Ein Schrägstrich am Ende stoert nicht');

    is('', \WebAtze\Domain\Bridge::url(['domain' => '']), 'Ohne Domain keine Adresse');
});

// ==================================================================
// Das Geruest der eigenen Website
//
// Sie wird schrittweise von handgeschriebenen Ansichten auf
// Abschnittsdaten umgestellt. Was dabei kaputtgeht, geht leise kaputt:
// eine Ueberschrift eine Ebene tiefer, eine Sprungmarke ins Leere, ein
// Abschnitt, der auf Englisch fehlt. Im Browser sieht das richtig aus.
//
// Diese Pruefungen sind das Netz darunter.
// ==================================================================

test('Die eigene Website: jede Seite hat genau eine Hauptueberschrift', function (): void {
    foreach (seiten_erfassen() as $schluessel => $geruest) {
        $ersten = array_filter(
            $geruest['ueberschriften'],
            static fn (string $z): bool => str_starts_with($z, 'h1: ')
        );

        is(1, count($ersten), $schluessel . ' hat eine h1');
    }
});

test('Die eigene Website: keine Sprungmarke zeigt ins Leere', function (): void {
    foreach (seiten_erfassen() as $schluessel => $geruest) {
        foreach ($geruest['sprungmarken'] as $marke) {
            // Die Sprungmarke zum Inhalt gehoert zur Bedienung mit der
            // Tastatur und zeigt auf den Rahmen, nicht auf einen
            // Abschnitt - deshalb steht sie hier trotzdem in derselben
            // Liste und muss genauso aufloesen.
            ok(
                in_array($marke, $geruest['kennungen'], true),
                $schluessel . ': #' . $marke . ' gibt es auch'
            );
        }
    }
});

test('Die eigene Website: jeder eigene Verweis fuehrt auf eine Seite', function (): void {
    $router = new Router();
    \WebAtze\Core\Routes::register($router);

    foreach (seiten_erfassen() as $schluessel => $geruest) {
        foreach ($geruest['verweise'] as $ziel) {
            // Dateien (Bilder, Schriften, Stylesheets) haben keine Route
            // und brauchen auch keine.
            if (str_starts_with($ziel, '/assets/') || str_contains(basename($ziel), '.')) {
                continue;
            }

            ok(
                route($router, 'GET', $ziel) !== null,
                $schluessel . ': ' . $ziel . ' fuehrt irgendwohin'
            );
        }
    }
});

test('Die eigene Website: Deutsch und Englisch sind wirklich zwei Fassungen', function (): void {
    $erfasst = seiten_erfassen();

    foreach (seiten_liste() as $schluessel => $_) {
        $schluessel = seiten_name($schluessel);

        $de = $erfasst[$schluessel . '@de'] ?? null;
        $en = $erfasst[$schluessel . '@en'] ?? null;

        ok($de !== null && $en !== null, $schluessel . ': beide Fassungen entstehen');

        if ($de === null || $en === null) {
            continue;
        }

        is('de', $de['sprache'], $schluessel . ': die deutsche sagt das auch');
        is('en', $en['sprache'], $schluessel . ': die englische ebenfalls');

        ok($de['titel'] !== '', $schluessel . ': die deutsche hat einen Titel');
        ok($en['titel'] !== '', $schluessel . ': die englische auch');

        // Gleicher Titel in beiden Sprachen heisst fast immer: Der
        // Sprachschluessel fehlt in en.php und faellt auf Deutsch zurueck.
        isnt($de['titel'], $en['titel'], $schluessel . ': und die Titel unterscheiden sich');
    }
});

test('Die eigene Website: der Aufbau ist noch derselbe', function (): void {
    $erwartet = seiten_erwartet();

    ok($erwartet !== [], 'Es gibt einen festgehaltenen Stand');

    $erfasst = seiten_erfassen();

    is(count($erwartet), count($erfasst), 'Gleich viele Seiten wie festgehalten');

    foreach ($erfasst as $schluessel => $geruest) {
        $soll = $erwartet[$schluessel] ?? null;

        ok($soll !== null, $schluessel . ' war schon festgehalten');

        if ($soll === null) {
            continue;
        }

        is($soll['pfad'], $geruest['pfad'], $schluessel . ': die Adresse ist dieselbe');

        // Die Zahlen stehen mit in der Datei, damit eine Abweichung
        // nicht nur "Pruefsumme anders" sagt, sondern woran es liegt.
        is(
            $soll['ueberschriften'],
            count($geruest['ueberschriften']),
            $schluessel . ': gleich viele Ueberschriften'
        );
        is(
            $soll['abschnitte'],
            count($geruest['abschnitte']),
            $schluessel . ': gleich viele Abschnitte'
        );
        is(
            $soll['pruefsumme'],
            seiten_pruefsumme($geruest),
            $schluessel . ': derselbe Aufbau'
        );
    }
});

// ==================================================================
test('Der Aufbau haengt nicht am Pruefwert der Dateinamen', function (): void {
    // Ein geaenderter Buchstabe in einer CSS-Regel liess den Aufbau
    // *aller* Seiten als veraendert gelten: Der Build schreibt einen
    // Pruefwert in den Dateinamen, und der stand in der Prueflinie
    // fuer den Seitenaufbau. Ein Alarm, der bei jedem Bau losgeht,
    // ist nach dem dritten Mal keiner mehr - dann drueckt man ihn
    // weg, auch an dem Tag, an dem er recht hat.
    is('/assets/main.css', seiten_ohne_pruefwert('/assets/main-DxirPQad.css'),
        'Der Pruefwert faellt weg');
    is('/assets/three.js', seiten_ohne_pruefwert('/assets/three-NAXOs3fP.js'),
        'Auch beim JavaScript');

    // Aber nur der. Alles andere bleibt, wie es ist.
    is('/kontakt', seiten_ohne_pruefwert('/kontakt'), 'Eine Seite bleibt unberuehrt');
    is('/assets/img/favicon.svg', seiten_ohne_pruefwert('/assets/img/favicon.svg'),
        'Ein Bild hat keinen Pruefwert und behaelt seinen Namen');
    is('/assets/webatze.css', seiten_ohne_pruefwert('/assets/webatze.css'),
        'Eine Datei ohne Pruefwert bleibt, wie sie heisst');

    // Und die Prueflinie muss den Unterschied wirklich ueberstehen.
    $eins = ['ueberschriften' => ['A'], 'abschnitte' => ['x'], 'sprungmarken' => [],
        'verweise' => ['/assets/main-AAAAAAAA.css', '/kontakt']];
    $zwei = ['ueberschriften' => ['A'], 'abschnitte' => ['x'], 'sprungmarken' => [],
        'verweise' => ['/assets/main-BBBBBBBB.css', '/kontakt']];

    $normal = static fn (array $g): array => array_merge($g, [
        'verweise' => array_map('seiten_ohne_pruefwert', $g['verweise']),
    ]);

    is(seiten_pruefsumme($normal($eins)), seiten_pruefsumme($normal($zwei)),
        'Zwei Baustaende ergeben dieselbe Pruefsumme');

    // Verschwindet das Stilpaket dagegen ganz, schlaegt sie weiterhin an.
    $ohne = ['ueberschriften' => ['A'], 'abschnitte' => ['x'], 'sprungmarken' => [],
        'verweise' => ['/kontakt']];

    ok(seiten_pruefsumme($normal($eins)) !== seiten_pruefsumme($normal($ohne)),
        'Eine fehlende Datei faellt aber auf');
});

// ==================================================================
summary();
