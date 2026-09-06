<?php

declare(strict_types=1);

namespace WebAtze\Build;

/**
 * Wo in der Datei welches Element steht.
 *
 * Der bisherige Editor schickte beim Speichern das **ganze Dokument**
 * aus dem Browser zurück und überschrieb die Datei damit. Das ist der
 * Grund für den Umbau, und es sind drei Fehler in einem:
 *
 *   1. Der Browser gibt nie zurück, was er bekommen hat. Er normalisiert
 *      beim Einlesen - Attributreihenfolge, Anführungszeichen, Entities,
 *      eingefügte `<tbody>`, geschlossene `<br>`. Gleichwertig ja,
 *      identisch nein.
 *   2. Ein Fehler irgendwo betraf die ganze Datei statt einer Stelle.
 *   3. Und niemand sah nach, was danach wirklich dort stand.
 *
 * Diese Klasse dreht das um. Sie läuft einmal über die Datei und
 * vermerkt für jedes Element, an welchem Byte es beginnt, wo sein
 * Inhalt anfängt und aufhört und wo es endet. Damit lässt sich später
 * **eine einzelne Stelle** ersetzen und alles andere Byte für Byte
 * stehenlassen.
 *
 * ## Warum ein eigener Scanner und kein DOMDocument
 *
 * `DOMDocument` würde die Datei parsen und beim Zurückschreiben genau
 * das tun, was der Browser tut: normalisieren. Es kennt ausserdem kein
 * PHP - `<?php ... ?>` würde als Processing Instruction verstümmelt.
 * Hier wird nichts geparst und nichts erzeugt: Es werden Positionen
 * gezählt, und die Datei bleibt ein Text.
 *
 * ## Was der Scanner absichtlich nicht kann
 *
 * Er versteht keine Fehlertoleranz. Ein nicht geschlossenes `<div>`
 * bringt seine Verschachtelung durcheinander - dann stimmen die Grenzen
 * der äusseren Elemente nicht mehr. Deshalb wird beim Speichern
 * **zurückgelesen und geprüft**, statt sich darauf zu verlassen.
 */
final class Karte
{
    /**
     * Elemente ohne schliessendes Tag.
     *
     * Sie haben keinen Inhalt, also auch keine Inhaltsgrenzen - nur
     * Anfang und Ende. Wer hier eines vergisst, bekommt eine
     * Verschachtelung, die nie wieder zumacht.
     */
    private const LEER = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * Elemente, deren Inhalt kein HTML ist.
     *
     * In ihnen wird bis zum passenden schliessenden Tag gesprungen. Ein
     * `<` in einem Skript ist ein Kleiner-als und kein Tag - wer das
     * verwechselt, zählt ab dort alles falsch.
     */
    private const ROHTEXT = ['script', 'style', 'textarea', 'title'];

    /**
     * Die Datei durchgehen.
     *
     * @return array<int, array{tag:string, von:int, inhaltVon:int, inhaltBis:int, bis:int, tiefe:int}>
     *         Nummer => Grenzen. Die Nummer ist die Reihenfolge des
     *         Auftretens und damit dieselbe, die der Browser vergibt,
     *         wenn er das Dokument in derselben Reihenfolge durchgeht.
     */
    public static function lesen(string $quelle): array
    {
        $elemente = [];
        $stapel = [];
        $i = 0;
        $laenge = strlen($quelle);
        $nummer = 0;

        while ($i < $laenge) {
            $auf = strpos($quelle, '<', $i);

            if ($auf === false) {
                break;
            }

            // Kommentare, Doctype und PHP-Blöcke überspringen - in
            // ihnen steht kein Element, und was darin nach einem Tag
            // aussieht, ist keines.
            $sprung = self::ueberspringen($quelle, $auf);

            if ($sprung !== null) {
                $i = $sprung;
                continue;
            }

            if (!preg_match('~\G</?([a-zA-Z][a-zA-Z0-9-]*)~', $quelle, $t, 0, $auf)) {
                $i = $auf + 1;
                continue;
            }

            $tag = strtolower($t[1]);
            $schliessend = $quelle[$auf + 1] === '/';
            $zu = self::tagEnde($quelle, $auf);

            if ($zu === null) {
                break;
            }

            if ($schliessend) {
                // Den passenden Anfang suchen. Steht er nicht obenauf,
                // war dazwischen etwas nicht geschlossen; die
                // dazwischenliegenden werden verworfen, statt die
                // ganze Karte zu verlieren.
                for ($s = count($stapel) - 1; $s >= 0; $s--) {
                    if ($stapel[$s]['tag'] !== $tag) {
                        continue;
                    }

                    $offen = $stapel[$s];
                    $stapel = array_slice($stapel, 0, $s);

                    $elemente[$offen['nummer']]['inhaltBis'] = $auf;
                    $elemente[$offen['nummer']]['bis'] = $zu;

                    break;
                }

                $i = $zu;
                continue;
            }

            $selbstschliessend = in_array($tag, self::LEER, true)
                || substr($quelle, $zu - 2, 1) === '/';

            $elemente[$nummer] = [
                'tag' => $tag,
                'von' => $auf,
                'inhaltVon' => $zu,
                'inhaltBis' => $zu,
                'bis' => $zu,
                'tiefe' => count($stapel),
            ];

            if (!$selbstschliessend) {
                if (in_array($tag, self::ROHTEXT, true)) {
                    // Bis zum schliessenden Tag springen, ohne den
                    // Inhalt anzusehen.
                    $ende = stripos($quelle, '</' . $tag, $zu);

                    if ($ende !== false) {
                        $endeZu = self::tagEnde($quelle, $ende) ?? $laenge;
                        $elemente[$nummer]['inhaltBis'] = $ende;
                        $elemente[$nummer]['bis'] = $endeZu;
                        $nummer++;
                        $i = $endeZu;
                        continue;
                    }
                }

                $stapel[] = ['tag' => $tag, 'nummer' => $nummer];
            }

            $nummer++;
            $i = $zu;
        }

        return $elemente;
    }

    /**
     * Jedem Element seine Nummer ins Tag schreiben.
     *
     * Nur für die Anzeige im Rahmen - in die Datei kommt das nie. Der
     * Browser liest die Nummer dann einfach ab, statt selbst zu zählen,
     * und genau darauf kommt es an:
     *
     * Ein Browser zählt anders als eine Datei. Er fügt ein `<tbody>`
     * ein, das nirgends steht, verschiebt Elemente aus dem `<head>` in
     * den Rumpf, wenn sie dort nichts zu suchen haben, und schliesst,
     * was offen blieb. Zählten beide Seiten für sich, liefen die Nummern
     * auseinander - und dann würde beim Speichern die falsche Stelle
     * ersetzt. Das wäre schlimmer als der Fehler, der diesen Umbau
     * ausgelöst hat.
     *
     * Von hinten nach vorn eingesetzt: Jede Einfügung verschiebt alles
     * dahinter, und von vorn gearbeitet stimmte ab dem zweiten Element
     * keine Grenze mehr.
     */
    public static function nummerieren(string $quelle, array $elemente, string $attribut = 'data-wa-id'): string
    {
        foreach (array_reverse($elemente, true) as $nummer => $element) {
            // Nur echte Tags. Wo kein Name steht, steht auch kein Platz
            // für ein Attribut.
            if (!preg_match('~^<[a-zA-Z]~', substr($quelle, $element['von'], 2))) {
                continue;
            }

            // Das Tag herausschneiden und für sich behandeln. Ein
            // Muster mit `^` gegen die ganze Datei zu halten, träfe
            // deren Anfang und nicht dieses Tag.
            $tag = substr($quelle, $element['von'], $element['inhaltVon'] - $element['von']);

            $quelle = substr($quelle, 0, $element['von'])
                . preg_replace(
                    '~^<([a-zA-Z][a-zA-Z0-9-]*)~',
                    '<$1 ' . $attribut . '="' . $nummer . '"',
                    $tag,
                    1
                )
                . substr($quelle, $element['inhaltVon']);
        }

        return $quelle;
    }

    /**
     * Der Kurzfinger einer Datei.
     *
     * Er wandert mit ins Fenster und beim Speichern zurück. Passt er
     * nicht mehr, hat sich die Datei zwischendurch geändert - und dann
     * zeigen die Nummern woandershin, als der Bearbeiter meint. In dem
     * Fall wird nicht geschrieben, sondern gesagt, dass neu geladen
     * werden muss.
     */
    public static function finger(string $quelle): string
    {
        return substr(hash('sha256', $quelle), 0, 16);
    }

    /**
     * Den Inhalt eines Elements austauschen.
     *
     * Ersetzt werden die Bytes zwischen `>` des öffnenden und `<` des
     * schliessenden Tags. Das Element selbst, seine Attribute und alles
     * ausserhalb bleiben unberührt.
     */
    public static function inhaltSetzen(string $quelle, array $element, string $neu): string
    {
        return substr($quelle, 0, $element['inhaltVon'])
            . $neu
            . substr($quelle, $element['inhaltBis']);
    }

    /**
     * Ein Attribut setzen oder entfernen.
     *
     * Angefasst wird nur das öffnende Tag. Ist das Attribut schon da,
     * wird sein Wert ersetzt; sonst kommt es hinter den Tagnamen. Ein
     * leerer Wert entfernt es - `style=""` gehört nicht in die Datei
     * eines Kunden.
     */
    public static function attributSetzen(string $quelle, array $element, string $name, string $wert): string
    {
        $tag = substr($quelle, $element['von'], $element['inhaltVon'] - $element['von']);
        $muster = '~\s' . preg_quote($name, '~') . '\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)~i';

        if ($wert === '') {
            $neu = (string) preg_replace($muster, '', $tag);
        } elseif (preg_match($muster, $tag)) {
            $neu = (string) preg_replace(
                $muster,
                ' ' . $name . '="' . self::schuetzen($wert) . '"',
                $tag,
                1
            );
        } else {
            // Hinter den Tagnamen, nicht ans Ende: Ein Tag kann auf
            // `/>` enden, und dann stünde das Attribut hinter dem
            // Schrägstrich.
            $neu = (string) preg_replace(
                '~^<([a-zA-Z][a-zA-Z0-9-]*)~',
                '<$1 ' . $name . '="' . self::schuetzen($wert) . '"',
                $tag,
                1
            );
        }

        return substr($quelle, 0, $element['von'])
            . $neu
            . substr($quelle, $element['inhaltVon']);
    }

    /** Das ganze Element samt Tags herausschneiden. */
    public static function entfernen(string $quelle, array $element): string
    {
        return substr($quelle, 0, $element['von']) . substr($quelle, $element['bis']);
    }

    /** Text vor oder hinter einem Element einfügen. */
    public static function einfuegen(string $quelle, array $element, string $html, bool $davor): string
    {
        $stelle = $davor ? $element['von'] : $element['bis'];

        return substr($quelle, 0, $stelle) . $html . substr($quelle, $stelle);
    }

    /**
     * Zwei Elemente die Plätze tauschen lassen.
     *
     * Der hintere zuerst: Wer vorn anfängt, verschiebt damit die
     * Grenzen des hinteren, und die zweite Ersetzung landet daneben.
     */
    public static function tauschen(string $quelle, array $a, array $b): ?string
    {
        // Ineinander geschachtelte lassen sich nicht tauschen - das
        // eine ist Teil des anderen, und danach gäbe es beide zweimal
        // oder gar nicht.
        if (self::steckenIneinander($a, $b)) {
            return null;
        }

        [$vorn, $hinten] = $a['von'] < $b['von'] ? [$a, $b] : [$b, $a];

        $textVorn = substr($quelle, $vorn['von'], $vorn['bis'] - $vorn['von']);
        $textHinten = substr($quelle, $hinten['von'], $hinten['bis'] - $hinten['von']);

        return substr($quelle, 0, $vorn['von'])
            . $textHinten
            . substr($quelle, $vorn['bis'], $hinten['von'] - $vorn['bis'])
            . $textVorn
            . substr($quelle, $hinten['bis']);
    }

    /** Der reine Text eines Elements, ohne Tags. */
    public static function textVon(string $quelle, array $element): string
    {
        $inhalt = substr($quelle, $element['inhaltVon'], $element['inhaltBis'] - $element['inhaltVon']);

        return trim((string) preg_replace('~\s+~', ' ', strip_tags($inhalt)));
    }

    // ------------------------------------------------------------------

    private static function steckenIneinander(array $a, array $b): bool
    {
        return ($a['von'] >= $b['von'] && $a['bis'] <= $b['bis'])
            || ($b['von'] >= $a['von'] && $b['bis'] <= $a['bis']);
    }

    /**
     * Steht hier etwas, das kein Element ist?
     *
     * @return int|null Die Stelle dahinter, oder null.
     */
    private static function ueberspringen(string $quelle, int $auf): ?int
    {
        foreach ([
            '<!--' => '-->',
            '<?' => '?>',
            '<!' => '>',
        ] as $anfang => $ende) {
            if (substr($quelle, $auf, strlen($anfang)) !== $anfang) {
                continue;
            }

            $stelle = strpos($quelle, $ende, $auf + strlen($anfang));

            return $stelle === false ? strlen($quelle) : $stelle + strlen($ende);
        }

        return null;
    }

    /**
     * Das Ende eines Tags finden.
     *
     * Nicht einfach das nächste `>`: In `<a title="a > b">` steht eines
     * im Attributwert. Deshalb wird durch die Anführungszeichen
     * hindurchgezählt.
     */
    private static function tagEnde(string $quelle, int $auf): ?int
    {
        $i = $auf + 1;
        $laenge = strlen($quelle);
        $quote = '';

        while ($i < $laenge) {
            $z = $quelle[$i];

            if ($quote !== '') {
                if ($z === $quote) {
                    $quote = '';
                }
            } elseif ($z === '"' || $z === "'") {
                $quote = $z;
            } elseif ($z === '>') {
                return $i + 1;
            }

            $i++;
        }

        return null;
    }

    private static function schuetzen(string $wert): string
    {
        return str_replace(['&', '"'], ['&amp;', '&quot;'], $wert);
    }
}
