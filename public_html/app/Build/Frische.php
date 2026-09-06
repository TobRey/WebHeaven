<?php

declare(strict_types=1);

namespace WebAtze\Build;

/**
 * Damit beim Besucher ankommt, was auf dem Server liegt.
 *
 * Der Anlass: Eine Textänderung wurde hochgeladen, war live zu sehen,
 * wurde zurückgenommen, wieder hochgeladen - und die Seite zeigte
 * weiter die alte Fassung. Das Werkzeug war in Ordnung, das ZIP war
 * richtig gepackt. Der Browser des Besuchers hatte die Seite im
 * Zwischenspeicher und fragte gar nicht erst nach.
 *
 * **Fremde Zwischenspeicher lassen sich nicht leeren.** Nicht von
 * WebAtze, nicht vom Hoster, von niemandem - sie liegen auf den Geräten
 * der Besucher. Was geht, ist verhindern, dass sie überhaupt entstehen.
 * Zwei Handgriffe reichen dafür:
 *
 * 1. **HTML immer nachfragen lassen.** Ohne Angabe rät der Browser eine
 *    Haltbarkeit - üblich sind zehn Prozent des Dateialters - und
 *    liefert die Seite ohne Rückfrage aus. Mit `no-cache` fragt er jedes
 *    Mal nach und bekommt bei unverändertem Inhalt ein knappes "unverändert"
 *    zurück. Das kostet fast nichts und ist nie falsch.
 * 2. **Stilblatt und Skript am Namen erkennbar machen.** Sie dürfen lange
 *    liegen bleiben, solange sich ihre Adresse ändert, wenn sich die
 *    Datei ändert. Dafür bekommt jeder Verweis ein `?v=` mit dem
 *    Änderungszeitpunkt der Zieldatei.
 *
 * Bilder brauchen nichts davon: Ein getauschtes Bild bekommt hier immer
 * einen neuen Dateinamen (siehe `Http\DirektController::bildName()`),
 * und eine neue Adresse wird nie aus dem Zwischenspeicher bedient.
 *
 * Beides greift nur auf Apache. Auf nginx wird `.htaccess` nicht
 * gelesen - der Stempel wirkt trotzdem.
 */
final class Frische
{
    /**
     * Was eine Seite ist.
     *
     * Nicht nur `.html`. Eine Kundenwebsite läuft oft über `index.php`,
     * und dort stehen dieselben Verweise auf Stilblatt und Skript. Als
     * hier nur `html` und `htm` standen, lief das Stempeln bei solchen
     * Websites über null Dateien - ohne dass irgendwo etwas fehlschlug.
     */
    private const SEITEN = ['html', 'htm', 'php', 'phtml', 'shtml'];

    /** Anfang und Ende des Blocks, den WebAtze verwaltet. */
    public const MARKE_AUF = '# ---- WebAtze: Zwischenspeicher (automatisch gesetzt) ----';
    public const MARKE_ZU = '# ---- WebAtze: Ende ----';

    /**
     * Beides auf einen Ordner anwenden.
     *
     * @return array{htaccess:bool, seiten:int, verweise:int}
     */
    public static function sichern(string $ordner): array
    {
        if (!is_dir($ordner)) {
            return ['htaccess' => false, 'seiten' => 0, 'verweise' => 0];
        }

        $gestempelt = self::stempeln($ordner);

        return [
            'htaccess' => self::regelnSetzen($ordner, $gestempelt['verweise'] > 0),
            'seiten' => $gestempelt['seiten'],
            'verweise' => $gestempelt['verweise'],
        ];
    }

    /**
     * Die Regeln in die `.htaccess` legen.
     *
     * Eine vorhandene Datei wird **nicht** ersetzt. Eine fremde Website
     * hat dort ihre Umschreibungen stehen - bei WordPress hängen die
     * Adressen aller Unterseiten daran, und sie zu überschreiben hiesse,
     * die Website kaputtzumachen, um sie zu beschleunigen.
     *
     * Stattdessen wird nur der markierte Block ersetzt oder angehängt.
     * Beim nächsten Mal wird derselbe Block wiedergefunden - die Datei
     * wächst also nicht mit jedem Herunterladen.
     */
    public static function regelnSetzen(string $ordner, bool $gestempelt = true): bool
    {
        $datei = $ordner . '/.htaccess';
        $alt = is_file($datei) ? (string) @file_get_contents($datei) : '';
        $block = self::block($gestempelt);

        $auf = strpos($alt, self::MARKE_AUF);
        $zu = strpos($alt, self::MARKE_ZU);

        if ($auf !== false && $zu !== false && $zu > $auf) {
            $neu = substr($alt, 0, $auf) . $block . substr($alt, $zu + strlen(self::MARKE_ZU));
        } elseif (trim($alt) === '') {
            $neu = $block . "\n";
        } else {
            $neu = rtrim($alt) . "\n\n" . $block . "\n";
        }

        if ($neu === $alt) {
            return true;
        }

        return @file_put_contents($datei, $neu, LOCK_EX) !== false;
    }

    /**
     * Stilblatt und Skript mit ihrem Änderungszeitpunkt versehen.
     *
     * Der Stempel ist die Änderungszeit der Zieldatei, nicht die
     * aktuelle Uhrzeit. Das ist der Unterschied zwischen "ändert sich,
     * wenn sich etwas ändert" und "ändert sich bei jedem Klick": Mit der
     * Uhrzeit müsste jeder Besucher nach jedem Herunterladen alles neu
     * laden, auch wenn sich nichts geändert hat.
     *
     * @return array{seiten:int, verweise:int}
     */
    public static function stempeln(string $ordner): array
    {
        $seiten = 0;
        $verweise = 0;

        foreach (self::seiten($ordner) as $datei) {
            $inhalt = (string) @file_get_contents($datei);

            if ($inhalt === '') {
                continue;
            }

            $anzahl = 0;
            $neu = self::stempelnIn($inhalt, dirname($datei), $ordner, $anzahl);

            if ($anzahl > 0 && $neu !== $inhalt && @file_put_contents($datei, $neu, LOCK_EX) !== false) {
                $seiten++;
                $verweise += $anzahl;
            }
        }

        return ['seiten' => $seiten, 'verweise' => $verweise];
    }

    // ------------------------------------------------------------------
    // Innereien
    // ------------------------------------------------------------------

    /**
     * Die Verweise einer einzelnen Seite stempeln.
     *
     * Bewusst eng gefasst: Gesucht wird nur das `href` eines `<link>`
     * und das `src` eines `<script>`, und nur, wenn der Pfad auf `.css`
     * oder `.js` endet und im Ordner tatsächlich eine Datei liegt. Alles
     * andere bleibt Zeichen für Zeichen, wie es war.
     */
    private static function stempelnIn(string $html, string $basis, string $wurzel, int &$anzahl): string
    {
        $muster = '~(<(?:link|script)\b[^>]*?\b(?:href|src)=")([^"]+\.(?:css|js))((?:\?[^"]*)?)(")~i';

        return (string) preg_replace_callback($muster, static function (array $t) use ($basis, $wurzel, &$anzahl): string {
            $pfad = $t[2];

            // Fremde Server und protokollrelative Adressen bleiben, wie
            // sie sind - deren Haltbarkeit bestimmt jemand anderes.
            if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $pfad) === 1) {
                return $t[0];
            }

            $datei = str_starts_with($pfad, '/')
                ? $wurzel . '/' . ltrim($pfad, '/')
                : $basis . '/' . $pfad;

            $echt = realpath($datei);
            $heim = realpath($wurzel);

            // Nur Dateien aus diesem Ordner. Ein Verweis, der mit "../"
            // herausführt, wird nicht angefasst.
            if ($echt === false || $heim === false || !str_starts_with($echt, $heim . DIRECTORY_SEPARATOR)) {
                return $t[0];
            }

            $stempel = (int) @filemtime($echt);

            if ($stempel <= 0) {
                return $t[0];
            }

            // Ein vorhandenes ?v= wird ersetzt und nicht ergaenzt -
            // sonst stuende nach dem dritten Mal ?v=1&v=2&v=3 da.
            $rest = self::ohneStempel($t[3]);
            $anzahl++;

            return $t[1] . $pfad . '?v=' . $stempel . ($rest === '' ? '' : '&' . $rest) . $t[4];
        }, $html);
    }

    /** Die Abfrage ohne unser eigenes `v` – der Rest bleibt erhalten. */
    private static function ohneStempel(string $abfrage): string
    {
        $abfrage = ltrim($abfrage, '?');

        if ($abfrage === '') {
            return '';
        }

        $teile = array_filter(
            explode('&', $abfrage),
            static fn (string $t): bool => $t !== '' && !str_starts_with($t, 'v=')
        );

        return implode('&', $teile);
    }

    /** @return list<string> Alle HTML-Dateien unterhalb des Ordners. */
    private static function seiten(string $ordner): array
    {
        $treffer = [];

        $eintraege = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($ordner, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($eintraege as $eintrag) {
            /** @var \SplFileInfo $eintrag */
            if (!$eintrag->isFile() || $eintrag->isLink()) {
                continue;
            }

            if (in_array(strtolower($eintrag->getExtension()), self::SEITEN, true)) {
                $treffer[] = $eintrag->getPathname();
            }
        }

        return $treffer;
    }

    /**
     * Der Block, der in die `.htaccess` kommt.
     *
     * `$gestempelt` sagt, ob die Verweise auf Stilblatt und Skript ein
     * `?v=` bekommen haben. Davon hängt ab, wie lange die beiden liegen
     * bleiben dürfen - und diese Kopplung ist kein Feinschliff, sondern
     * der Unterschied zwischen schnell und kaputt:
     *
     * Ein Jahr Haltbarkeit ist nur zulässig, wenn sich die **Adresse**
     * ändert, sobald sich die Datei ändert. Ohne Stempel wäre es eine
     * Falle - ein geändertes Stilblatt bliebe beim Besucher ein Jahr
     * lang eingefroren, und niemand käme mehr daran. Genau das ist
     * vorher passiert: Der Block setzte das Jahr, das Stempeln lief
     * aber über null Dateien, weil es nur `.html` kannte.
     */
    private static function block(bool $gestempelt = true): string
    {
        // Ohne Stempel: eine Stunde und danach nachfragen. Kurz genug,
        // dass ein Fehler in Stunden vergeht statt in Monaten.
        $beiwerk = $gestempelt
            ? 'public, max-age=31536000'
            : 'public, max-age=3600, must-revalidate';

        return self::MARKE_AUF . "\n"
            . "# Von WebAtze gesetzt. Dieser Block wird beim nächsten Herunterladen\n"
            . "# ersetzt - eigene Regeln bitte ausserhalb der beiden Markierungen.\n"
            . "#\n"
            . "# Zeigt die Website nach dem Hochladen plötzlich einen Fehler 500,\n"
            . "# versteht dieser Server eine der Regeln nicht: Block löschen, fertig.\n"
            . "\n"
            . "# SEITEN: IMMER NACHFRAGEN.\n"
            . "#\n"
            . "# Geprüft wird, WAS ausgeliefert wird - nicht, wie die Datei heisst.\n"
            . "# Vorher stand hier nur <FilesMatch \"\\.(html|htm)\$\">, und eine\n"
            . "# Website, die über index.php läuft, heisst nicht index.html. Die\n"
            . "# Regel griff bei ihr nie, und es sah aus, als käme keine Änderung an.\n"
            . "#\n"
            . "# Drei Wege übereinander, weil jeder einzelne eine Lücke hat.\n"
            . "\n"
            . "# Weg 1: über den Inhaltstyp. Der einzige, den auch LiteSpeed\n"
            . "# auswertet - dort wird die Bedingung in Weg 2 stillschweigend\n"
            . "# übergangen, ohne Fehlermeldung und ohne Wirkung.\n"
            . "<IfModule mod_expires.c>\n"
            . "    ExpiresActive On\n"
            . "    ExpiresByType text/html \"access plus 0 seconds\"\n"
            . "    ExpiresByType application/xhtml+xml \"access plus 0 seconds\"\n"
            . "</IfModule>\n"
            . "\n"
            . "<IfModule mod_headers.c>\n"
            . "    # Weg 2: Apache, ebenfalls am Inhaltstyp. Fasst auch die nackte\n"
            . "    # Adresse \"/\" und jede Seite ohne Dateiendung.\n"
            . "    Header always set Cache-Control \"no-cache, must-revalidate\" \"expr=%{CONTENT_TYPE} =~ m#^text/html#i\"\n"
            . "\n"
            . "    # Weg 3: am Dateinamen, für Server, die den Ausdruck oben nicht\n"
            . "    # auswerten. Doppelt gesetzt schadet nicht: \"always set\" ersetzt\n"
            . "    # den Wert, es reiht sich nichts aneinander.\n"
            . "    <FilesMatch \"\\.(html|htm|php|phtml|shtml)\$\">\n"
            . "        Header always set Cache-Control \"no-cache, must-revalidate\"\n"
            . "    </FilesMatch>\n"
            . "\n"
            . ($gestempelt
                ? "    # Stilblatt und Skript dürfen liegen bleiben: Ihre Adresse trägt\n"
                    . "    # ein ?v= mit dem Änderungszeitpunkt und ist nach einer Änderung\n"
                    . "    # eine andere.\n"
                : "    # Nur eine Stunde. In dieser Website wurde kein einziger Verweis\n"
                    . "    # auf Stilblatt oder Skript gefunden, der sich stempeln liesse -\n"
                    . "    # ohne wechselnde Adresse wäre ein Jahr Haltbarkeit eine Falle.\n")
            . "    <FilesMatch \"\\.(css|js)\$\">\n"
            . "        Header always set Cache-Control \"" . $beiwerk . "\"\n"
            . "    </FilesMatch>\n"
            . "\n"
            . "    # Bilder und Schriften: ein Monat. Ein hier getauschtes Bild\n"
            . "    # bekommt ohnehin einen neuen Dateinamen.\n"
            . "    <FilesMatch \"\\.(png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf)\$\">\n"
            . "        Header always set Cache-Control \"public, max-age=2592000\"\n"
            . "    </FilesMatch>\n"
            . "</IfModule>\n"
            . self::MARKE_ZU;
    }
}
