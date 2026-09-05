<?php

declare(strict_types=1);

namespace WebAtze\Domain;

/**
 * Baut aus dem Formular einen fertigen Auftrag zum Kopieren.
 *
 * Der Grund für diese Datei ist eine Erfahrung, die Geld gekostet hat.
 * Vorher rief WebAtze die Schnittstelle selbst auf: rund achtzig
 * Anfragen, aneinandergekettet über eine Warteschlange, eine Datenbank
 * und einen Cronjob, der jede Minute einen neuen Vorgang startete. Jeder
 * dieser Übergänge ist eine Stelle, an der etwas hängenbleiben kann –
 * und es blieb hängen, immer wieder, jedes Mal woanders.
 *
 * Hier passiert davon nichts mehr. Das Formular erzeugt einen Text. Der
 * Text wird kopiert und von Hand eingefügt. Eine Anfrage, eine Antwort,
 * fertig – so wie ein Mensch es auch täte.
 *
 * Was der Text leisten muss: Er muss vollständig sein. Wer ihn einfügt,
 * hat das Formular nicht gesehen und kann nicht nachfragen. Alles, was
 * fehlt, wird erfunden – und Erfundenes auf einer Firmenwebsite ist
 * schlimmer als eine Lücke.
 */
final class PromptText
{
    /** Wie viele Seiten bei welchem Umfang. */
    private const PAGES = [
        'small' => '4 bis 5',
        'medium' => '6 bis 8',
        'large' => '9 bis 12',
    ];

    /**
     * Der ganze Auftrag als Text.
     *
     * @param array<string, mixed> $brief    geprüfte Formulardaten
     * @param array<string, string> $anschluss echte Werte für Support und
     *        Zählung: 'url', 'support_token', 'support_code', 'visit_key'.
     *        Sind sie da, steht im Auftrag alles fertig verdrahtet -
     *        nichts muss danach eingerichtet werden. Fehlen sie, schreibt
     *        der Auftrag Platzhalter und sagt, wo die Werte herkommen.
     */
    public static function build(array $brief, array $anschluss = []): string
    {
        $anschluss = self::anschluss($anschluss);

        $teile = [
            self::auftrag($brief, $anschluss),
            self::firma($brief),
            self::gestaltung($brief),
            self::umfang($brief),
            self::technik($brief),
            self::bearbeitbar(),
            self::betreuung($brief, $anschluss),
            self::zusatz($brief),
            self::regeln($brief),
            self::abschluss($brief, $anschluss),
        ];

        return implode("\n\n", array_filter($teile, static fn (string $t): bool => trim($t) !== ''));
    }

    /**
     * Was die Website bearbeitbar macht.
     *
     * Der Editor kennt keine Website. Er kennt Abschnitte, die sich zu
     * erkennen geben, und Werte, die aus einer Variablen kommen. Alles
     * andere kann er nicht anfassen - nicht aus Bosheit, sondern weil er
     * es nicht findet.
     *
     * Deshalb steht dieser Block in jedem Auftrag, auch in einem, der
     * von Hand irgendwo eingefügt wird. Ohne ihn entsteht eine Website,
     * die gut aussieht und sich nicht bearbeiten lässt - und das merkt
     * man erst, wenn man es versucht.
     *
     * "Neue Website erstellen" ist damit kein Bauauftrag mehr, sondern
     * ein Themengenerator: Der Aufbau liegt fest, erzeugt werden Farben,
     * Schriften, Inhalte und die Wahl der Varianten.
     */
    private static function bearbeitbar(): string
    {
        return <<<'TEXT'
BEARBEITBARKEIT (nicht verhandelbar)
====================================
Diese Website wird später in einem Editor bearbeitet. Damit das geht,
gelten drei Regeln. Sie kosten nichts und sind später nicht nachtragbar.

1. JEDER ABSCHNITT GIBT SICH ZU ERKENNEN

   Jeder eigenständige Bereich der Seite ist ein <section> (Kopf ein
   <header>, Fuss ein <footer>) mit zwei Angaben:

       <section class="s-leistungen ..." id="leistungen"
                data-section="services" data-section-id="3">

   data-section    = die Art des Abschnitts: hero, features, services,
                     about, gallery, testimonials, team, process, stats,
                     logos, faq, cta, contact, text, header, footer
   data-section-id = eine Zahl, auf der ganzen Website eindeutig,
                     fortlaufend ab 1

   Ohne diese beiden Angaben ist ein Abschnitt für den Editor nicht
   vorhanden.

2. WAS SICH WIEDERHOLT, STEHT IN EINEM BEHÄLTER MIT DER KLASSE s-items

   Karten, Leistungen, Teammitglieder, Fragen, Logos: Der umgebende
   Behälter bekommt die Klasse s-items, jedes Kind ist ein direktes Kind
   davon. Nur so lassen sich Einträge einzeln umordnen statt nur der
   ganze Abschnitt.

3. JEDE FARBE, SCHRIFT UND GRÖSSE KOMMT AUS EINER VARIABLEN

   Ganz oben im Stylesheet steht ein :root-Block mit allen Werten.
   Danach kommt im ganzen Dokument kein einziger fester Farbwert mehr
   vor - kein #hex, kein rgb(), kein Farbname.

       :root {
         --c-primary: #2b1b9e;
         --c-secondary: #17c8c8;
         --c-bg: #ffffff;
         --c-text: #16172a;
         --c-text-muted: #55566e;
         --c-surface: #ffffff;
         --c-border: rgb(0 0 0 / 0.1);
         --c-radius: 0.75rem;
         --c-font-display: "...", system-ui, sans-serif;
         --c-font-body: "...", system-ui, sans-serif;
       }

   Wer die Farben der Website ändern will, ändert diesen einen Block -
   und der Rest zieht nach. Steht irgendwo sonst noch ein Farbwert,
   stimmt danach genau diese eine Stelle nicht mehr, und niemand findet
   sie.

Prüfe am Ende selbst: Suche im fertigen Stylesheet nach "#" und "rgb(".
Was ausserhalb des :root-Blocks steht, gehört hineingezogen.
TEXT;
    }

    /**
     * Die Anschlusswerte aufräumen und fehlende erkennbar machen.
     *
     * @param array<string, string> $roh
     * @return array{url:string, support_token:string, support_code:string,
     *               visit_key:string, fertig:bool}
     */
    private static function anschluss(array $roh): array
    {
        $wert = static fn (string $name): string => trim((string) ($roh[$name] ?? ''));

        $werte = [
            'url' => rtrim($wert('url'), '/'),
            'support_token' => $wert('support_token'),
            'support_code' => $wert('support_code'),
            'visit_key' => $wert('visit_key'),
        ];

        // Nur wenn wirklich alles da ist, verspricht der Auftrag, dass
        // nichts mehr einzurichten sei. Ein halb ausgefüllter Anschluss
        // wäre schlimmer als gar keiner: Dann baut jemand etwas, das
        // beim ersten Versuch scheitert.
        $werte['fertig'] = $werte['url'] !== ''
            && $werte['support_token'] !== ''
            && $werte['support_code'] !== ''
            && $werte['visit_key'] !== '';

        return $werte;
    }

    // ------------------------------------------------------------------

    private static function auftrag(array $brief, array $anschluss = []): string
    {
        $name = (string) ($brief['company_name'] ?? 'die Firma');

        $text = "# Auftrag: Website für {$name}\n\n"
            . "Bau eine vollständige, sofort lauffähige Website. Kein Gerüst, kein\n"
            . "Entwurf, keine Platzhalter, wo Inhalt hingehört – eine Website, die\n"
            . "man so hochladen und ausliefern kann.\n\n"
            . "Leg sie in einem neuen Ordner an und schreib am Ende hin, welche\n"
            . "Dateien entstanden sind.";

        // Was bestellt wurde, steht ganz vorne. Am Ende einer langen
        // Liste wird es ueberlesen - genau das ist zweimal passiert.
        $stuecke = self::bestellt($brief);

        if ($stuecke !== []) {
            $text .= "\n\n## Was diese Website neben den Seiten hat\n\n"
                . "Ausführlich steht es weiter unten. Hier zuerst, damit es nicht\n"
                . "untergeht:\n\n";

            foreach ($stuecke as $nummer => $zeile) {
                $text .= ($nummer + 1) . '. ' . $zeile . "\n";
            }

            $text .= "\nDas ist Teil des Auftrags, nicht Beiwerk. Wenn am Ende eines\n"
                . "davon fehlt, ist die Website nicht fertig.";

            if (!empty($anschluss['fertig'])) {
                $text .= "\n\n**Alles dafür Nötige steht in diesem Auftrag: Adresse,\n"
                    . "Schlüssel, Zugangscode, Zählzeile.** Es ist danach nichts mehr\n"
                    . "einzurichten, nichts nachzutragen, nichts von Hand einzufügen.";
            }
        }

        return $text;
    }

    /**
     * Was ausser den Seiten bestellt wurde - als kurze Liste.
     *
     * Es sind wieder Entscheidungen im Formular. Der Unterschied zu
     * frueher liegt nicht im Haken, sondern darin, was dahinter
     * passiert: Was angehakt ist, steht mit allen echten Werten im
     * Auftrag und laeuft ohne einen Handgriff.
     *
     * @return array<int, string>
     */
    private static function bestellt(array $brief): array
    {
        $stuecke = [];

        if (!empty($brief['wants_docs'])) {
            $stuecke[] = '**`/doc`** – die Anleitung für den Kunden.';
        }

        if (!empty($brief['wants_support'])) {
            $stuecke[] = '**`/support`** – die Hilfeseite, über die er mir schreibt.';
        }

        if (!empty($brief['wants_stats'])) {
            $stuecke[] = '**Die Besucherzählung** – eine Zeile im Kopfbereich jeder Seite.';
        }

        if (!empty($brief['wants_admin'])) {
            $stuecke[] = '**`/admin`** – der Bearbeitungsbereich für den Kunden.';
        }

        return $stuecke;
    }

    private static function firma(array $brief): string
    {
        $text = "## Die Firma\n\n" . Brief::toPromptText($brief);

        return $text;
    }

    private static function gestaltung(array $brief): string
    {
        $zeilen = ["## Gestaltung"];

        if (($brief['color_mode'] ?? '') === 'manual') {
            $zeilen[] = sprintf(
                "Farben sind vorgegeben: Hauptfarbe %s, Zweitfarbe %s, Akzent %s.\n"
                . "Leg sie als CSS-Variablen an genau einer Stelle ab und benutze\n"
                . "ausschliesslich diese – keine weiteren Farbwerte im Stylesheet\n"
                . "verstreut. Prüf den Kontrast: Text auf Fläche muss 4.5:1\n"
                . "erreichen, grosse Überschriften 3:1. Reicht eine Farbe für Text\n"
                . "nicht, benutz sie als Fläche und nimm dafür dunkle Schrift.",
                (string) ($brief['color_primary'] ?? ''),
                (string) ($brief['color_secondary'] ?? ''),
                (string) ($brief['color_accent'] ?? '')
            );
        } else {
            $zeilen[] = "Wähl selbst eine Farbwelt, die zur Branche passt, und leg sie als\n"
                . "CSS-Variablen an genau einer Stelle ab. Prüf den Kontrast: Text auf\n"
                . "Fläche muss 4.5:1 erreichen.";
        }

        $zeilen[] = "Schriften: zwei, höchstens drei Schnitte, als Webfont mitgeliefert\n"
            . "oder Systemschrift – nichts, was beim Aufruf von aussen nachgeladen\n"
            . "wird.";

        $zeilen[] = "Modern heisst hier: grosszügige Abstände, klare Hierarchie,\n"
            . "zurückhaltende Bewegung beim Scrollen. Kein Effekt um seiner selbst\n"
            . "willen. Wer die Seite mit reduzierter Bewegung öffnet\n"
            . "(prefers-reduced-motion), bekommt eine ruhige Fassung.";

        return implode("\n\n", $zeilen);
    }

    private static function umfang(array $brief): string
    {
        $umfang = (string) ($brief['scope'] ?? 'small');
        $seiten = self::PAGES[$umfang] ?? '4 bis 5';

        $zeilen = ["## Seiten und Aufbau"];

        $zeilen[] = "{$seiten} Seiten, plus Impressum und Datenschutz.";

        $gewuenscht = trim((string) ($brief['wanted_pages'] ?? ''));

        if ($gewuenscht !== '') {
            $zeilen[] = "Diese Seiten sind ausdrücklich gewünscht:\n" . $gewuenscht;
        }

        $zeilen[] = "Jede Seite hat vier bis sechs Abschnitte. Denk dir den Aufbau je\n"
            . "Seite selbst aus – Aufmacher, Leistungen, Über uns, Ablauf, Zahlen,\n"
            . "Stimmen, Fragen und Antworten, Aufruf zum Handeln, Kontakt sind die\n"
            . "üblichen Bausteine. Keine Seite soll aussehen wie die vorige.";

        $sprachen = (string) ($brief['locales'] ?? 'de');

        if ($sprachen !== 'de') {
            $zeilen[] = "Sprachen: " . (Brief::LOCALES[$sprachen] ?? $sprachen) . ".\n"
                . "Die Hauptsprache liegt im Wurzelverzeichnis, jede weitere in einem\n"
                . "eigenen Unterordner (/en/, /fr/). Umschalter im Kopf, hreflang im\n"
                . "Kopfbereich. Adressen, Telefonnummern und Eigennamen bleiben\n"
                . "unübersetzt – eine übersetzte Strasse ist falsch.";
        }

        return implode("\n\n", $zeilen);
    }

    private static function technik(array $brief): string
    {
        $zeilen = ["## Technik"];

        $zeilen[] = "Statisches HTML, CSS und ein wenig JavaScript. Kein Framework,\n"
            . "kein Build-Schritt, keine Abhängigkeiten aus dem Netz. Die Seite\n"
            . "muss laufen, wenn man den Ordner auf ein gewöhnliches Webhosting\n"
            . "kopiert.";

        $zeilen[] = "Kein fremder Dienst wird eingebunden – keine Schriften von\n"
            . "aussen, keine Analysewerkzeuge, keine Karten, keine Videoeinbettung\n"
            . "von Drittanbietern. Damit kommt die Seite ohne Zustimmungsbanner\n"
            . "aus, und das soll sie auch.";

        $zeilen[] = "Bilder: Es gibt keine. Setz saubere Platzhalter mit fester\n"
            . "Grösse (damit nichts springt) und einem sprechenden alt-Text, der\n"
            . "sagt, was dort hingehört. Erfinde keine Fotos von Menschen,\n"
            . "Räumen oder Arbeiten – das wären Falschaussagen über eine echte\n"
            . "Firma.";

        $zeilen[] = "Barrierefreiheit ist keine Kür: eine h1 je Seite, Überschriften\n"
            . "ohne Sprünge, jedes Formularfeld beschriftet, alles mit der\n"
            . "Tastatur bedienbar, sichtbarer Fokusrahmen, Sprungmarke zum Inhalt.";

        if (!empty($brief['wants_admin'])) {
            $zeilen[] = "**Bearbeitungsbereich gewünscht.** Bau unter /admin einen\n"
                . "einfachen Bereich in PHP, mit dem der Kunde Texte und Bilder\n"
                . "ändern kann. Anmeldung mit Argon2id, Inhalte als JSON-Dateien\n"
                . "mit atomarem Schreiben und einer Sicherungskopie je Änderung.\n"
                . "Benutzername: " . (string) ($brief['admin_username'] ?? 'admin') . ".\n"
                . "Das Passwort setze ich selbst – schreib hin, wo es hingehört.";
        }

        if (!empty($brief['wants_stats'])) {
            $bericht = trim((string) ($brief['report_email'] ?? ''));

            $zeilen[] = "**Besucherzählung gewünscht.** Eine eigene, ohne\n"
                . "Drittanbieter: ein winziges Zählskript, das Aufrufe je Seite und\n"
                . "Tag in eine Datei schreibt, ohne IP-Adressen zu speichern. Dazu\n"
                . "eine geschützte Seite, die die Zahlen zeigt."
                . ($bericht !== ''
                    ? "\nJeden Montag geht eine kurze Zusammenfassung an {$bericht}."
                    : '');
        }

        if (Brief::needsDatabase($brief)) {
            $zeilen[] = "**Achtung:** Aus den Zusatzwünschen unten geht hervor, dass\n"
                . "etwas gespeichert werden muss (Buchung, Bestellung, Anmeldung).\n"
                . "Bau das mit – Datenbank oder Dateien, je nachdem was reicht –\n"
                . "und eine Verwaltungsoberfläche dazu.";
        }

        return implode("\n\n", $zeilen);
    }

    /**
     * Anleitung, Supportbereich und Zaehlung - fertig verdrahtet.
     *
     * Zwei Anlaeufe hat es gebraucht. Beim ersten hing alles an einem
     * Haken im Formular, der vergessen wurde. Beim zweiten stand es zwar
     * im Auftrag, aber mit Platzhaltern: "den Code setze ich selbst".
     * Auch das ist ein Handgriff, den jemand vergisst - und dann steht
     * eine Hilfeseite da, die nichts tut.
     *
     * Jetzt stehen die echten Werte im Text: Adresse, Schluessel,
     * Zugangscode, Zaehlzeile. Wer die Dateien hochlaedt, hat eine
     * Website, die zaehlt und ueber die man Support bekommt. Ohne einen
     * einzigen Handgriff, auch wenn der Text von Hand eingefuegt wurde.
     *
     * Der Schluessel darin ist bewusst der support_token und nicht der
     * assistant_token: Er kann eine Nachricht senden und den eigenen
     * Faden lesen. Mehr nicht, und zuruecknehmen laesst er sich.
     */
    private static function betreuung(array $brief, array $anschluss = []): string
    {
        $fertig = !empty($anschluss['fertig']);
        $url = (string) ($anschluss['url'] ?? '');
        $token = (string) ($anschluss['support_token'] ?? '');
        $code = (string) ($anschluss['support_code'] ?? '');
        $key = (string) ($anschluss['visit_key'] ?? '');

        $an = trim((string) ($brief['contact_email'] ?? ''));

        $doku    = !empty($brief['wants_docs']);
        $support = !empty($brief['wants_support']);
        $zaehlen = !empty($brief['wants_stats']);

        // Nichts bestellt, nichts zu schreiben. Eine leere Rubrik im
        // Auftrag ist schlimmer als keine: Sie liest sich, als sei
        // etwas vergessen worden.
        if (!$doku && !$support && !$zaehlen) {
            return '';
        }

        $zeilen = ["## Betreuung"];

        $namen = [];
        if ($doku)    { $namen[] = 'die Anleitung'; }
        if ($support) { $namen[] = 'die Hilfeseite'; }
        if ($zaehlen) { $namen[] = 'die Besucherzählung'; }

        $liste = count($namen) > 1
            ? implode(', ', array_slice($namen, 0, -1)) . ' und ' . end($namen)
            : $namen[0];

        $zeilen[] = "Zu dieser Website gehören " . $liste . ". Das ist bestellt und\n"
            . "bezahlt – bau es, auch wenn es Arbeit macht. Was hier steht, ist\n"
            . "vollständig: Adresse, Schlüssel und Zugangscode stehen im Klartext\n"
            . "darin, es ist danach nichts mehr einzurichten.";

        // Die Konfigurationsdatei braucht nur, wer auch etwas damit tut.
        $braucht_datei = $support || $zaehlen;

        // ------------------------------------------------ Die Konfiguration
        if ($braucht_datei && $fertig) {
            $zeilen[] = "### Zuerst: `data/config.php`\n\n"
                . "Leg diese Datei als Erstes an, genau mit diesen Werten. Sie sind\n"
                . "echt und einsatzbereit – **nichts davon ist ein Platzhalter, und\n"
                . "es ist danach nichts mehr einzurichten**:\n\n"
                . "```php\n"
                . "<?php\n\n"
                . "// Die Anbindung an WebAtze. Von WebAtze erzeugt, gültig ab sofort.\n"
                . "return [\n"
                . "    'assistant_url' => '" . $url . "',\n"
                // Nur die Schluessel, die auch gebraucht werden. Ein
                // Wert, den nichts liest, wirft nur die Frage auf, wozu
                // er da ist - und wandert irgendwann in eine Datei, in
                // die er nicht gehoert.
                . ($support ? "    'support_token' => '" . $token . "',\n" : '')
                . ($support ? "    'support_code'  => '" . $code . "',\n" : '')
                . ($zaehlen ? "    'visit_key'     => '" . $key . "',\n" : '')
                . ($support && $an !== '' ? "    'support_email' => '" . $an . "',\n" : '')
                . "];\n"
                . "```\n\n"
                . "Der Ordner `data/` bekommt eine `.htaccess` mit `Require all denied`\n"
                . "und eine leere `index.php`, die mit 404 abbricht. Der Schlüssel\n"
                . "darf nie über den Browser lesbar sein.";
        } elseif ($braucht_datei) {
            $zeilen[] = "### Zuerst: `data/config.php`\n\n"
                . "```php\n"
                . "<?php\n\n"
                . "return [\n"
                . "    'assistant_url' => 'https://webatze.ch',\n"
                . "    'support_token' => 'WIRD-NACHGETRAGEN',\n"
                . "    'support_code'  => 'WIRD-NACHGETRAGEN',\n"
                . "    'visit_key'     => 'WIRD-NACHGETRAGEN',\n"
                . "    'support_email' => '" . ($an !== '' ? $an : 'meine Adresse') . "',\n"
                . "];\n"
                . "```\n\n"
                . "Die echten Werte stehen im Adminbereich von WebAtze auf der Seite\n"
                . "dieser Website. Solange sie fehlen, zeigt die Hilfeseite einen\n"
                . "ruhigen Hinweis statt eines Formulars – und keinen Fehler.\n\n"
                . "Der Ordner `data/` bekommt eine `.htaccess` mit `Require all denied`\n"
                . "und eine leere `index.php`, die mit 404 abbricht.";
        }

        // ------------------------------------------------------------- /doc
        if ($doku) {
            $zeilen[] = "### `/doc` – die Anleitung\n\n"
                . "Eine eigene Seite `doc.html` (oder `doc/index.html`), verlinkt in\n"
                . "der Fusszeile. Sie erklärt dem Kunden in einfachen Worten, wie\n"
                . "seine Website funktioniert – ohne Fachbegriffe, mit Beispielen,\n"
                . "in der Anrede, die auch der Rest der Seite benutzt.\n\n"
                . "Sie enthält mindestens:\n"
                . "- **Was wo steht:** eine kurze Übersicht aller Seiten und was\n"
                . "  auf welche gehört.\n"
                . "- **Wie Anfragen ankommen:** wohin das Kontaktformular schickt,\n"
                . "  was der Kunde tun muss, wenn nichts ankommt (Spam-Ordner).\n"
                . "- **Was der Kunde selbst ändern kann und was nicht.**\n"
                . "- **Die Dateien:** welche Datei wofür da ist, in einer Tabelle.\n"
                . "- **Wenn etwas nicht stimmt:** der Weg über `/support`, samt\n"
                . "  Hinweis, dass er den Zugangscode von mir bekommen hat.\n"
                . "- **Was der Kunde noch liefern muss:** Bilder, Texte, Angaben\n"
                . "  fürs Impressum – alles, wo jetzt noch ein Platzhalter steht.\n\n"
                . "Sie trägt `<meta name=\"robots\" content=\"noindex, nofollow\">` und\n"
                . "steht nicht in der sitemap.xml. Sie ist für den Kunden, nicht\n"
                . "für Suchmaschinen.";

        }

        // --------------------------------------------------------- /support
        if ($support) {
            $zeilen[] = "### `/support` – der Draht zu mir\n\n"
                . "Eine Seite `support.php`, ebenfalls in der Fusszeile verlinkt. Der\n"
                . "Kunde schreibt dort eine Nachricht, sie geht an WebAtze, und der\n"
                . "bisherige Verlauf bleibt auf der Seite sichtbar. Kein Sofort-Chat,\n"
                . "keine Zusage einer sofortigen Antwort – schreib das auch so hin.";

            $zeilen[] = "**So wird gesendet** (fertig, nichts einzurichten):\n\n"
                . "```php\n"
                . "// Nachricht senden\n"
                . "POST {assistant_url}/assistant/v1/support\n"
                . "Header:  Content-Type: application/json\n"
                . "         Accept: application/json\n"
                . "         X-WebAtze-Token: {support_token}\n"
                . "Body:    {\"thread\": <Faden-Nr oder 0>, \"message\": \"…\",\n"
                . "          \"subject\": \"…\", \"name\": \"…\", \"email\": \"…\"}\n"
                . "Antwort: {\"ok\": true, \"thread\": 12, \"message\": \"…\"}\n\n"
                . "// Verlauf holen\n"
                . "POST {assistant_url}/assistant/v1/support/faden\n"
                . "Header:  dieselben\n"
                . "Body:    {\"thread\": <Faden-Nr>}\n"
                . "Antwort: {\"ok\": true, \"subject\": \"…\",\n"
                . "          \"messages\": [{\"from\": \"…\", \"body\": \"…\",\n"
                . "                        \"at\": \"…\", \"own\": true|false}]}\n"
                . "```\n\n"
                . "Gesendet wird mit cURL, `CURLOPT_SSL_VERIFYPEER => true`,\n"
                . "`CURLOPT_SSL_VERIFYHOST => 2`, Zeitgrenze 20 Sekunden. Die\n"
                . "Faden-Nummer merkt sich ein Cookie (180 Tage, `HttpOnly`,\n"
                . "`SameSite=Lax`, `Secure` sobald HTTPS anliegt), damit der Kunde\n"
                . "seine Antwort wiederfindet, ohne sich anzumelden.\n\n"
                . "Fällt die Verbindung aus, sagt die Seite das ruhig und schickt die\n"
                . "Nachricht zusätzlich per E-Mail an `support_email`. Eine Frage darf\n"
                . "nicht verloren gehen, nur weil ein Server gerade nicht antwortet.";

            $zeilen[] = "**Diese Seite ist nur für den Kunden.** Nicht für Besucher,\n"
                . "nicht für Automaten. Sie ist der einzige Weg, auf dem mich jemand\n"
                . "unaufgefordert erreicht, und genau deshalb das lohnendste Ziel auf\n"
                . "der ganzen Website. Bau die Absicherung von Anfang an ein:";

            $zeilen[] = "1. **Zugangscode.** Vor dem Formular steht ein einzelnes Feld\n"
                . "   „Zugangscode“. Verglichen wird gegen `support_code` aus\n"
                . "   `data/config.php`, mit `hash_equals`, nie mit `==`.\n"
                . "2. **Merken statt jedesmal fragen.** Nach richtigem Code ein\n"
                . "   Cookie mit einem Ticket: Ablaufzeitpunkt plus\n"
                . "   `hash_hmac('sha256', …, \$support_token)`, geprüft mit\n"
                . "   `hash_equals`. 180 Tage, `HttpOnly`, `SameSite=Lax`, `Secure`\n"
                . "   sobald HTTPS anliegt. Im Cookie steht kein Code im Klartext.\n"
                . "3. **Versuche begrenzen.** Höchstens 5 Code-Eingaben je Stunde und\n"
                . "   IP, gezählt in einer Datei unter `data/support/`. Danach eine\n"
                . "   freundliche Meldung, kein Hinweis darauf, was falsch war.\n"
                . "4. **Honigtopf und Zeitfalle.** Ein für Menschen unsichtbares\n"
                . "   Feld, das leer bleiben muss, und ein signierter Zeitstempel im\n"
                . "   Formular: Wer in unter 3 Sekunden abschickt, ist kein Mensch.\n"
                . "   In beiden Fällen freundlich bestätigen und nichts versenden –\n"
                . "   ein Automat soll nicht lernen, woran er gescheitert ist.\n"
                . "5. **Menge begrenzen.** Höchstens 5 Nachrichten je Stunde, auch\n"
                . "   mit gültigem Ticket. Nachricht 10 bis 4000 Zeichen.\n"
                . "6. **Sauber verschicken.** Steuerzeichen raus, `\\r` und `\\n` aus\n"
                . "   allen Kopfzeilen entfernen (sonst schleust jemand eigene\n"
                . "   Empfänger ein). `Reply-To` nur bei einer Adresse, die\n"
                . "   `filter_var(…, FILTER_VALIDATE_EMAIL)` besteht.\n"
                . "7. **Verlauf schützen.** Was lokal zwischengespeichert wird, liegt\n"
                . "   unter `data/support/` – gesperrt wie oben. Beim Ausgeben wird\n"
                . "   escaped.\n"
                . "8. **Nicht auffindbar.** `X-Robots-Tag: noindex, nofollow` als\n"
                . "   Kopfzeile und dasselbe als meta-Angabe. Nicht in die\n"
                . "   sitemap.xml.";

        }

        // ---------------------------------------------------------- Zählung
        if ($zaehlen) {
            if ($fertig) {
                $zeilen[] = "### Besucherzählung – eine Zeile, sonst nichts\n\n"
                    . "**Diese Zeile kommt unmittelbar vor `</body>` auf JEDE Seite**,\n"
                    . "auch auf `/doc`, `/support`, die 404-Seite und jede\n"
                    . "Sprachfassung:\n\n"
                    . "```html\n"
                    . '<script defer src="' . $url . '/z.js?k=' . $key . '"></script>' . "\n"
                    . "```\n\n"
                    . "Sie ist echt und einsatzbereit. Sie lädt nichts nach, setzt kein\n"
                    . "Cookie und verlangsamt nichts.\n\n"
                    . "Gezählt wird ohne IP-Adresse, ohne Cookie und ohne dauerhafte\n"
                    . "Kennung. Deshalb braucht die Website dafür **kein**\n"
                    . "Zustimmungsbanner – und deshalb steht in der\n"
                    . "Datenschutzerklärung dazu: es werden Seitenaufrufe gezählt, ohne\n"
                    . "personenbezogene Daten zu speichern, ohne Cookies, ohne\n"
                    . "Weitergabe an Dritte. Kein Werkzeug von Google, Meta oder sonst\n"
                    . "jemandem – schreib auch keines hin.\n\n"
                    . "Wenn du eine gemeinsame Kopf- oder Fussdatei baust (PHP-Include\n"
                    . "oder ein Baustein), gehört die Zeile genau einmal dorthin. Wenn\n"
                    . "jede Seite für sich steht, gehört sie in jede einzelne. Prüf am\n"
                    . "Ende nach, dass keine Seite sie vergisst.";
            } else {
                $zeilen[] = "### Besucherzählung\n\n"
                    . "Unmittelbar vor `</body>` auf jeder Seite:\n\n"
                    . "```html\n"
                    . '<script defer src="{assistant_url}/z.js?k={visit_key}"></script>' . "\n"
                    . "```\n\n"
                    . "Die Werte stehen in `data/config.php`. Sie lädt nichts nach,\n"
                    . "setzt kein Cookie und speichert keine IP-Adresse.";
            }

        }

        if ($support) {
            $zeilen[] = "Falls das Hosting kein PHP kann, sag es mir, statt still auf\n"
                . "einen fremden Formulardienst auszuweichen. Ein Dienst von aussen\n"
                . "wäre wieder ein Zustimmungsbanner, und das ist es nicht wert.";
        }

        return implode("\n\n", $zeilen);
    }

    private static function zusatz(array $brief): string
    {
        $extra = trim((string) ($brief['extra_notes'] ?? ''));
        $design = trim((string) ($brief['design_notes'] ?? ''));
        $alt = trim((string) ($brief['old_url'] ?? ''));

        if ($extra === '' && $design === '' && $alt === '') {
            return '';
        }

        $zeilen = ["## Besondere Wünsche"];

        if ($design !== '') {
            $zeilen[] = "Die Designwünsche oben haben Vorrang vor allem, was hier als\n"
                . "Empfehlung steht.";
        }

        if ($alt !== '') {
            $texte = !empty($brief['take_texts']);
            $bilder = !empty($brief['take_images']);

            $was = match (true) {
                $texte && $bilder => "Übernimm die Texte, die brauchbar sind, und die Bilder.",
                $texte => "Übernimm die Texte, die brauchbar sind. Die Bilder ausdrücklich nicht.",
                $bilder => "Übernimm die Bilder. Die Texte werden neu geschrieben.",
                default => "Übernimm weder Texte noch Bilder – sie dient nur als "
                    . "Anhaltspunkt, was die Firma tut.",
            };

            $zeilen[] = "Bestehende Website: {$alt}\n"
                . "Schau sie an. {$was}\n"
                . "Den Aufbau übernimmst du ausdrücklich NICHT – die Seite wird neu\n"
                . "gedacht, nicht abgemalt.";
        }

        if ($extra !== '') {
            $zeilen[] = "Die \"Zusätzlichen Wünsche\" oben sind kein Beiwerk. Lies sie\n"
                . "genau und bau, was dort steht – auch wenn es Arbeit macht. Was\n"
                . "du davon nicht umsetzen kannst, schreib mir am Ende hin, statt\n"
                . "es stillschweigend wegzulassen.";
        }

        return implode("\n\n", $zeilen);
    }

    private static function regeln(array $brief): string
    {
        $zeilen = ["## Zwei Dinge, die nicht verhandelbar sind"];

        $zeilen[] = "**Erstens: Nirgends ein Hinweis darauf, wie diese Website\n"
            . "entstanden ist.** Kein \"mit KI erstellt\", kein Modellname, kein\n"
            . "Hinweis in Kommentaren, Metadaten, Dateinamen oder der\n"
            . "Fusszeile. Weder im sichtbaren Text noch im Quelltext.";

        $zeilen[] = "**Zweitens: Erfinde nichts über diese Firma.** Keine Zahlen,\n"
            . "keine Jahreszahlen, keine Mitarbeiterzahlen, keine Auszeichnungen,\n"
            . "keine Kundenstimmen, keine Referenzen – nichts, was oben nicht\n"
            . "dasteht. Wo ein Abschnitt so etwas bräuchte und es fehlt, lass ihn\n"
            . "weg oder setz einen erkennbaren Platzhalter mit dem Hinweis, was\n"
            . "der Kunde liefern muss. Eine erfundene Kundenstimme auf einer\n"
            . "echten Firmenwebsite ist eine Lüge gegenüber deren Kunden.";

        $zeilen[] = "Schweizer Rechtschreibung: ss statt ß, durchgehend.";

        $anrede = (string) ($brief['tone'] ?? 'sie');
        $zeilen[] = $anrede === 'du'
            ? "Angesprochen wird geduzt, durchgehend und natürlich – nicht\nkumpelhaft."
            : "Angesprochen wird gesiezt, durchgehend.";

        return implode("\n\n", $zeilen);
    }

    private static function abschluss(array $brief, array $anschluss = []): string
    {
        $zeilen = ["## Zum Schluss"];

        $zeilen[] = "Rechtliches: Impressum und Datenschutzerklärung nach\n"
            . "Schweizer Recht, mit den Angaben von oben. Wo Angaben fehlen,\n"
            . "setz einen deutlich erkennbaren Platzhalter statt einer\n"
            . "Erfindung.";

        $zeilen[] = "Dazu gehören: robots.txt, sitemap.xml, eine 404-Seite,\n"
            . "Open-Graph-Angaben, ein Favicon.";

        $zeilen[] = "Das Kontaktformular schickt per PHP an "
            . (string) ($brief['contact_email'] ?? 'die Adresse oben') . ".\n"
            . "Schutz gegen Spam ohne Captcha: ein verstecktes Feld, eine\n"
            . "Zeitfalle und eine Begrenzung je Adresse. Jede Eingabe wird\n"
            . "geprüft und beim Ausgeben escaped.";

        $zeilen[] = "Wenn du fertig bist, prüf selbst nach: Führt jeder Verweis\n"
            . "irgendwohin? Hat jede Seite genau eine h1? Steht irgendwo noch ein\n"
            . "Platzhalter, der keiner sein sollte? Schreib mir hin, was du\n"
            . "gefunden und behoben hast – und was der Kunde noch liefern muss.";

        // Die Liste ganz am Schluss. Sie wiederholt bewusst, was oben
        // schon steht: Genau diese drei Punkte sind bisher jedes Mal
        // durchgerutscht, und ein Auftrag, der am Ende zum Nachzaehlen
        // auffordert, wird eher vollstaendig abgearbeitet.
        // Die Liste am Schluss nennt nur, was auch bestellt wurde. Ein
        // Punkt zu einer Seite, die es gar nicht geben soll, macht die
        // ganze Liste unglaubwuerdig - und dann wird keiner mehr
        // abgehakt.
        $punkte = [];

        if (!empty($brief['wants_support']) || !empty($brief['wants_stats'])) {
            $punkte[] = "- [ ] `data/config.php` liegt da, mit den Werten aus dem Auftrag,\n"
                . "      und `data/` ist per `.htaccess` gesperrt.";
        }

        if (!empty($brief['wants_docs'])) {
            $punkte[] = "- [ ] `/doc` gibt es, ist in der Fusszeile verlinkt und trägt\n"
                . "      `noindex`.";
        }

        if (!empty($brief['wants_support'])) {
            $punkte[] = "- [ ] `/support` gibt es, fragt den Zugangscode ab, sendet an\n"
                . "      WebAtze und zeigt den Verlauf.";
        }

        if (!empty($brief['wants_stats'])) {
            $punkte[] = "- [ ] Die Zählzeile steht vor `</body>` auf **jeder** Seite –\n"
                . "      zähl sie nach, 404-Seite und jede Sprachfassung\n"
                . "      eingeschlossen.";
        }

        $punkte[] = "- [ ] Impressum und Datenschutz stehen"
            . (!empty($brief['wants_stats'])
                ? ", die Datenschutzerklärung\n      erwähnt die Zählung ohne personenbezogene Daten."
                : '.');

        $punkte[] = "- [ ] Jeder Verweis führt irgendwohin, jede Seite hat genau eine h1.";
        $punkte[] = "- [ ] Nirgends steht, wie diese Website entstanden ist.";

        $zeilen[] = "**Zum Abhaken, bevor du sagst, du seist fertig:**\n\n"
            . implode("\n", $punkte) . "\n\n"
            . "Fehlt einer dieser Punkte, ist die Website nicht fertig – sag mir\n"
            . "in dem Fall, welcher und warum, statt es stillschweigend\n"
            . "wegzulassen.";

        // Zum Schluss die Abgabe.
        //
        // Sie steht hier, weil das Ergebnis danach hochgeladen wird und
        // nicht abgetippt: Im Backend gibt es unter „Veröffentlichen"
        // ein Feld, das genau dieses Archiv entgegennimmt und aufs FTP
        // schiebt. Ohne die Ansage kommt mal ein Ordner, mal eine Liste
        // von Dateien, mal ein Archiv mit drei Ebenen darüber - und
        // jedes davon kostet Handarbeit.
        $zeilen[] = "**Die Abgabe: ein ZIP.**\n\n"
            . "Pack am Ende alles in eine einzige ZIP-Datei und gib sie mir zum\n"
            . "Herunterladen. Das ist die Form, in der die Website weiterverarbeitet\n"
            . "wird – sie wird als Archiv hochgeladen und von dort direkt auf das\n"
            . "Webhosting geschrieben.\n\n"
            . "Dafür gilt:\n\n"
            . "- `index.html` liegt **auf der obersten Ebene** des Archivs, nicht in\n"
            . "  einem Unterordner. (Ein einzelner umschliessender Ordner wird beim\n"
            . "  Hochladen weggeschnitten – aber verlass dich nicht darauf.)\n"
            . "- Alle Pfade im Archiv sind **relativ**. Kein `/` am Anfang, kein `..`,\n"
            . "  keine Laufwerksbuchstaben.\n"
            . "- Keine Entwicklungsreste: kein `node_modules`, kein `.git`, keine\n"
            . "  `.DS_Store`, keine Quelldateien, aus denen erst noch etwas gebaut\n"
            . "  werden müsste. Was im Archiv liegt, läuft so, wie es liegt.\n"
            . "- Verweise untereinander sind relativ, damit die Website in jedem\n"
            . "  Verzeichnis funktioniert – auch in einem Unterordner.\n\n"
            . "Sag mir am Ende in einem Satz, wie viele Dateien darin sind und wie\n"
            . "gross das Archiv ist.";

        return implode("\n\n", $zeilen);
    }

    /**
     * Ein Dateiname für den Download.
     */
    public static function filename(array $brief): string
    {
        $name = mb_strtolower(trim((string) ($brief['company_name'] ?? 'auftrag')));
        $name = preg_replace('/[^a-z0-9]+/u', '-', $name) ?? 'auftrag';

        return 'auftrag-' . trim($name, '-') . '.md';
    }
}
