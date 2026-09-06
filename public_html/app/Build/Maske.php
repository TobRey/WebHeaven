<?php

declare(strict_types=1);

namespace WebAtze\Build;

/**
 * PHP aus dem Blickfeld nehmen und zurückbringen.
 *
 * Eine Kundenwebsite besteht oft aus `index.php` und `seite.php`, nicht
 * aus HTML-Dateien. Der Editor konnte sie deshalb gar nicht anzeigen -
 * er kannte nur `.html`. Ausführen kommt nicht in Frage: Das wäre
 * fremder Code auf dem eigenen Server, und zwar der Code von jemandem,
 * dessen Website man gerade repariert.
 *
 * Also wird der Code **stehengelassen und weggeblendet**. Was in den
 * Rahmen geht, ist das HTML-Gerüst mit seinen Texten; die PHP-Blöcke
 * sind Platzhalter, die beim Speichern zeichengetreu zurückkommen.
 *
 * ## Warum PHPs eigener Lexer
 *
 * Getrennt wird mit `token_get_all()` und nicht mit einem selbst
 * geschriebenen Sucher. Der Unterschied wird an einer Zeile deutlich:
 *
 *     <?php echo "?>"; ?>
 *
 * Ein Suchen nach `?>` fände das erste im Text und schnitte mitten in
 * eine Zeichenkette. Der Lexer weiss, dass es dort keines ist. Dasselbe
 * gilt für `?>` in Kommentaren und in Heredocs.
 *
 * ## Warum zwei Arten von Platzhaltern
 *
 * | Wo der Block steht | Platzhalter        |
 * | ------------------ | ------------------ |
 * | im Textfluss       | `<!--wa-php-N-->`  |
 * | in einem Tag       | `__WAPHPN__`       |
 *
 * Ein HTML-Kommentar ist **überall** gültig - auch zwischen zwei `<li>`
 * und innerhalb einer `<table>`. Ein `<span>` wäre es nicht: Der
 * Browser schöbe ihn aus der Tabelle heraus und verschöbe damit den
 * Code des Kunden. Und ein Kommentar ist unsichtbar, stört also nicht
 * beim Bearbeiten.
 *
 * Innerhalb eines Tags (`class="<?= $x ?>"`) geht kein Kommentar. Dort
 * steht ein Wort, das als Attributwert überlebt.
 */
final class Maske
{
    /** Woran ein weggeblendeter Block zu erkennen ist. */
    public const KOMMENTAR = 'wa-php-';

    /** Und derselbe innerhalb eines Tags. */
    public const WORT = '__WAPHP';

    /**
     * Elemente, in denen ein Kommentar keiner ist.
     *
     * Der Browser liest den Inhalt von `<title>` und `<textarea>` als
     * reinen Text: Aus `<!--wa-php-1-->` wird dort die Zeichenfolge
     * `&lt;!--wa-php-1--&gt;`, und beim Zurückschreiben stünde
     * maskierter Text in der Datei statt des Codes. In `<script>` und
     * `<style>` liest er ihn als Rohtext - ein Kommentar darin würde
     * beim Serialisieren zwar überleben, aber das ist Glück und keine
     * Zusage.
     *
     * In allen vieren steht deshalb ein Wort. Sichtbar ist es nur in
     * `<title>` (im Reiter des Rahmens) - und das ist der ehrlichere
     * Zustand als ein verschluckter Titel.
     */
    private const ROHTEXT = ['title', 'textarea', 'script', 'style'];

    /**
     * Den Code wegblenden.
     *
     * @return array{html:string, bloecke:array<int,string>}
     */
    public static function maskieren(string $quelle): array
    {
        if (!str_contains($quelle, '<?')) {
            return ['html' => $quelle, 'bloecke' => []];
        }

        $stuecke = @token_get_all($quelle);

        if ($stuecke === false || $stuecke === []) {
            return ['html' => $quelle, 'bloecke' => []];
        }

        $html = '';
        $bloecke = [];
        $offen = '';
        $imTag = false;
        $rohtext = '';

        foreach ($stuecke as $stueck) {
            $roh = is_array($stueck) ? $stueck[1] : $stueck;

            if (is_array($stueck) && $stueck[0] === T_INLINE_HTML) {
                // Erst den offenen Block ablegen, dann das HTML anhängen.
                if ($offen !== '') {
                    $html .= self::platzhalter(count($bloecke), $imTag || $rohtext !== '');
                    $bloecke[] = $offen;
                    $offen = '';
                }

                $html .= $roh;
                $imTag = self::imTag($roh, $imTag);
                $rohtext = self::rohtext($roh, $rohtext);

                continue;
            }

            $offen .= $roh;
        }

        // Eine Datei, die mit PHP endet, hat keinen abschliessenden
        // HTML-Teil - der letzte Block muss trotzdem hinaus.
        if ($offen !== '') {
            $html .= self::platzhalter(count($bloecke), $imTag || $rohtext !== '');
            $bloecke[] = $offen;
        }

        return ['html' => $html, 'bloecke' => $bloecke];
    }

    /**
     * Den Code zurückbringen.
     *
     * Beide Schreibweisen werden ersetzt: Der Browser kann einen
     * Kommentar beim Einlesen und Ausgeben nicht in ein Wort verwandeln,
     * aber er kann ihn verschieben - und dann steht ein Block, der im
     * Tag begann, plötzlich im Textfluss.
     */
    public static function demaskieren(string $html, array $bloecke): string
    {
        foreach ($bloecke as $nummer => $code) {
            $html = str_replace(
                ['<!--' . self::KOMMENTAR . $nummer . '-->', self::WORT . $nummer . '__'],
                (string) $code,
                $html
            );
        }

        return $html;
    }

    /**
     * Sind alle Blöcke noch da?
     *
     * Die Frage vor dem Schreiben. Ein Bearbeiter, der einen Absatz
     * löscht, löscht mit ihm jeden Platzhalter darin - und das wäre der
     * PHP-Code des Kunden. Fehlt einer, wird nicht geschrieben.
     *
     * @return list<int> Die Nummern der fehlenden Blöcke.
     */
    public static function fehlende(string $html, array $bloecke): array
    {
        $fehlt = [];

        foreach (array_keys($bloecke) as $nummer) {
            if (!str_contains($html, '<!--' . self::KOMMENTAR . $nummer . '-->')
                && !str_contains($html, self::WORT . $nummer . '__')
            ) {
                $fehlt[] = (int) $nummer;
            }
        }

        return $fehlt;
    }

    /** Trägt diese Datei überhaupt PHP? */
    public static function istPhp(string $datei): bool
    {
        return in_array(strtolower(pathinfo($datei, PATHINFO_EXTENSION)), ['php', 'phtml', 'shtml'], true);
    }

    // ------------------------------------------------------------------

    private static function platzhalter(int $nummer, bool $imTag): string
    {
        return $imTag
            ? self::WORT . $nummer . '__'
            : '<!--' . self::KOMMENTAR . $nummer . '-->';
    }

    /**
     * Steht die Stelle danach in einem Element mit Rohtext?
     *
     * Gesucht wird das letzte öffnende oder schliessende der vier Tags.
     * Kommt keines vor, gilt der Zustand von vorher weiter - der Inhalt
     * eines `<title>` kann über mehrere Stücke laufen.
     */
    private static function rohtext(string $stueck, string $vorher): string
    {
        $muster = '~<(/?)(' . implode('|', self::ROHTEXT) . ')\b~i';

        if (preg_match_all($muster, $stueck, $treffer, PREG_SET_ORDER) === 0) {
            return $vorher;
        }

        $letzter = end($treffer);

        return $letzter[1] === '/' ? '' : strtolower($letzter[2]);
    }

    /**
     * Endet dieses HTML-Stück innerhalb eines Tags?
     *
     * Gezählt wird nicht, sondern nachgesehen, was zuletzt kam: Steht
     * das letzte `<` hinter dem letzten `>`, ist ein Tag offen. Kam
     * keines von beiden vor, gilt der Zustand von vorher weiter - ein
     * Attributwert kann über mehrere Stücke laufen.
     */
    private static function imTag(string $stueck, bool $vorher): bool
    {
        $auf = strrpos($stueck, '<');
        $zu = strrpos($stueck, '>');

        if ($auf === false && $zu === false) {
            return $vorher;
        }

        if ($auf === false) {
            return false;
        }

        return $zu === false || $auf > $zu;
    }
}
