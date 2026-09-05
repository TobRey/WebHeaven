<?php

/**
 * Der Aufbau der eigenen Website, als Zahlenwerk.
 *
 * Die eigene Website wird Schritt für Schritt von handgeschriebenen
 * Ansichten auf Abschnittsdaten umgestellt. Bei so einem Umbau geht
 * selten etwas laut kaputt. Was passiert, ist leiser und teurer: Eine
 * Überschrift rutscht von h1 auf h2, eine Sprungmarke zeigt ins Leere,
 * ein Abschnitt verschwindet auf der englischen Fassung. Im Browser
 * sieht das alles richtig aus.
 *
 * Deshalb wird hier nicht die ganze Seite verglichen, sondern ihr
 * Gerüst: welche Überschriften in welcher Ebene und Reihenfolge, welche
 * Abschnitte, welche Sprungmarken und Verweise. Ein umformulierter Satz
 * löst damit keinen Fehlalarm aus – ein verschwundener Abschnitt schon.
 *
 * Neu festhalten, wenn sich die Seite absichtlich geändert hat:
 *
 *     php tests/run.php --seiten-festhalten
 */

declare(strict_types=1);

/**
 * Welche Seiten es gibt und wie man sie aufruft.
 *
 * Der Schlüssel ist derselbe wie in I18n::routeMap(), damit später
 * project_pages.route_key daran anknüpfen kann.
 *
 * @return array<string, string> Schlüssel => Methode im SiteController
 */
function seiten_liste(): array
{
    return [
        '' => 'home',
        'services' => 'services',
        'process' => 'process',
        'work' => 'work',
        'contact' => 'contact',
        'imprint' => 'imprint',
        'privacy' => 'privacy',
    ];
}

/** Die Startseite hat als Adressschlüssel das Nichts – hier braucht sie einen Namen. */
function seiten_name(string $schluessel): string
{
    return $schluessel === '' ? 'start' : $schluessel;
}

/**
 * Alle Seiten in allen Sprachen rendern und ihr Gerüst zurückgeben.
 *
 * @return array<string, array<string, mixed>> Schlüssel: "seite@sprache"
 */
function seiten_erfassen(): array
{
    $controller = new \WebAtze\Http\SiteController();
    $ergebnis = [];

    foreach (seiten_liste() as $schluessel => $methode) {
        foreach (\WebAtze\Core\I18n::LOCALES as $sprache) {
            $pfad = \WebAtze\Core\I18n::url($schluessel, $sprache);

            $html = seiten_rendern($controller, $methode, $pfad, $sprache);

            $ergebnis[seiten_name($schluessel) . '@' . $sprache] = seiten_geruest($html, $pfad);
        }
    }

    return $ergebnis;
}

/**
 * Eine einzelne Seite rendern, so wie der Server es täte.
 *
 * Der Zustand kommt aus Kernel::shareViewData() – nicht aus einer
 * Nachbildung hier, die beim nächsten neuen Wert stillschweigend
 * unvollständig wäre.
 */
function seiten_rendern(
    \WebAtze\Http\SiteController $controller,
    string $methode,
    string $pfad,
    string $sprache
): string {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $pfad;
    $_SERVER['HTTP_HOST'] = 'webatze.test';
    $_SERVER['HTTPS'] = 'on';

    $anfrage = \WebAtze\Core\Request::capture();

    \WebAtze\Core\I18n::setLocale($sprache);
    \WebAtze\Core\Kernel::shareViewData($anfrage);

    return $controller->{$methode}($anfrage)->getBody();
}

/**
 * Aus fertigem HTML das herausziehen, was sich nicht ändern darf.
 *
 * @return array<string, mixed>
 */
function seiten_geruest(string $html, string $pfad): array
{
    return [
        'pfad' => $pfad,
        'titel' => seiten_treffer('/<title>(.*?)<\/title>/s', $html),
        'beschreibung' => seiten_treffer(
            '/<meta name="description" content="([^"]*)"/',
            $html
        ),
        'sprache' => seiten_treffer('/<html lang="([^"]*)"/', $html),
        'ueberschriften' => seiten_ueberschriften($html),
        'abschnitte' => seiten_abschnitte($html),
        'sprungmarken' => seiten_sprungmarken($html),
        'verweise' => seiten_verweise($html),
        'kennungen' => seiten_kennungen($html),
    ];
}

/** Überschriften in Reihenfolge, als "h2: Text". */
function seiten_ueberschriften(string $html): array
{
    preg_match_all('/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $html, $treffer, PREG_SET_ORDER);

    $liste = [];

    foreach ($treffer as $t) {
        $text = seiten_text($t[2]);

        if ($text !== '') {
            $liste[] = 'h' . $t[1] . ': ' . $text;
        }
    }

    return $liste;
}

/** Die Abschnitte der Seite, an ihrer Kennung erkannt. */
function seiten_abschnitte(string $html): array
{
    preg_match_all('/<section\b([^>]*)>/i', $html, $treffer);

    $liste = [];

    foreach ($treffer[1] as $attribute) {
        $kennung = seiten_treffer('/\bid="([^"]*)"/', $attribute);
        $klasse = seiten_treffer('/\bclass="([^"]*)"/', $attribute);

        $liste[] = $kennung !== '' ? '#' . $kennung : '.' . $klasse;
    }

    return $liste;
}

/** Alle Sprungmarken, auf die die Seite selbst zeigt. */
function seiten_sprungmarken(string $html): array
{
    preg_match_all('/href="#([^"]+)"/', $html, $treffer);

    $liste = array_values(array_unique($treffer[1]));
    sort($liste);

    return $liste;
}

/** Alle Kennungen, die es auf der Seite gibt – das Gegenstück dazu. */
function seiten_kennungen(string $html): array
{
    preg_match_all('/\bid="([^"]+)"/', $html, $treffer);

    return array_values(array_unique($treffer[1]));
}

/** Verweise auf eigene Seiten, ohne Anker und ohne fremde Ziele. */
function seiten_verweise(string $html): array
{
    preg_match_all('/href="(\/[^"#?]*)"/', $html, $treffer);

    $liste = array_values(array_unique(array_map(
        'seiten_ohne_pruefwert',
        $treffer[1]
    )));
    sort($liste);

    return $liste;
}

/**
 * Den Prüfwert aus einem Dateinamen nehmen.
 *
 * Der Build schreibt ihn hinein, damit Browser eine geänderte Datei
 * neu laden – aus /assets/main.css wird /assets/main-DxirPQad.css.
 * Er gehört zum Inhalt der Datei, nicht zum Aufbau der Seite, und
 * hier stand er trotzdem in der Prüfsumme: Eine einzige geänderte
 * CSS-Regel liess damit den Aufbau *aller* Seiten als verändert
 * gelten. Ein Alarm, der bei jeder Kleinigkeit losgeht, ist nach
 * dem dritten Mal keiner mehr.
 *
 * Der Name bleibt, nur der Prüfwert fällt weg: Verschwindet das
 * Stilpaket ganz oder heisst es plötzlich anders, schlägt die
 * Prüfung weiterhin an.
 */
function seiten_ohne_pruefwert(string $pfad): string
{
    if (!str_starts_with($pfad, '/assets/')) {
        return $pfad;
    }

    return preg_replace('/-[A-Za-z0-9_-]{8,}\.(css|js)$/', '.$1', $pfad) ?? $pfad;
}

/** Aus einem Stück HTML den reinen Text machen. */
function seiten_text(string $html): string
{
    $text = preg_replace('/<[^>]*>/', ' ', $html) ?? '';
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';

    return trim($text);
}

/** Erster Klammerausdruck eines Musters, oder leer. */
function seiten_treffer(string $muster, string $text): string
{
    return preg_match($muster, $text, $t) === 1 ? trim($t[1]) : '';
}

/**
 * Die Prüfsumme des Gerüsts.
 *
 * Bewusst ohne Titel und Beschreibung: Die ändert man beim Feilen an der
 * Suchmaschinenanzeige öfter, und dafür braucht es keinen Alarm. Der
 * Aufbau dagegen soll sich nur ändern, wenn jemand ihn ändern wollte.
 */
function seiten_pruefsumme(array $geruest): string
{
    return substr(hash('sha256', json_encode([
        $geruest['ueberschriften'],
        $geruest['abschnitte'],
        $geruest['sprungmarken'],
        $geruest['verweise'],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 0, 16);
}

/** Wo die festgehaltenen Prüfsummen liegen. */
function seiten_datei(): string
{
    return __DIR__ . '/fixtures/eigene-seiten.json';
}

/** Den aktuellen Stand festhalten. */
function seiten_festhalten(array $erfasst): int
{
    $inhalt = [];

    foreach ($erfasst as $schluessel => $geruest) {
        $inhalt[$schluessel] = [
            'pfad' => $geruest['pfad'],
            'pruefsumme' => seiten_pruefsumme($geruest),
            'ueberschriften' => count($geruest['ueberschriften']),
            'abschnitte' => count($geruest['abschnitte']),
        ];
    }

    @mkdir(dirname(seiten_datei()), 0755, true);

    file_put_contents(
        seiten_datei(),
        json_encode($inhalt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );

    return count($inhalt);
}

/** Den festgehaltenen Stand lesen. */
function seiten_erwartet(): array
{
    if (!is_file(seiten_datei())) {
        return [];
    }

    $daten = json_decode((string) file_get_contents(seiten_datei()), true);

    return is_array($daten) ? $daten : [];
}
