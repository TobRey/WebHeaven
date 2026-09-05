<?php

declare(strict_types=1);

namespace WebAtze\Build;

/**
 * FTP, klein und ehrlich.
 *
 * Eine Verbindung, ein Objekt. Wer eine Instanz in der Hand hat, hat
 * eine Leitung, die steht - und bekommt nie eine, die nur so aussieht.
 *
 * Diese Klasse ist der Nachbau des FTP-Teils, nachdem eine Woche
 * Fehlersuche vier Fehler zutage gefördert hat, die alle dieselbe Form
 * hatten: Das Werkzeug behauptete etwas, das es nicht gemessen hatte.
 * Vier Regeln machen genau das hier baulich unmöglich:
 *
 * 1. **null ist nicht [].** Gescheitert und leer sind verschiedene
 *    Rückgabewerte. Ein frisch angelegter, leerer Ordner wurde vorher
 *    als "blockierte Datenverbindung" gemeldet - das war der Fehler,
 *    der die meiste Zeit gekostet hat.
 *
 * 2. **Eine gescheiterte Übertragung verbrennt die Leitung.** Über TLS
 *    liegt der Steuerkanal danach um eine Antwort versetzt: ftp_chdir
 *    bekommt das "226 Fertig" des vorigen Befehls, ftp_pwd das "250 Ok"
 *    von chdir. Alles danach ist erfunden. Deshalb wird nach jedem
 *    Fehlschlag neu verbunden, bevor irgendetwas weitergefragt wird -
 *    und weil das hier drin steckt, kann kein Aufrufer es vergessen.
 *
 * 3. **Jeder Fehler trägt den Wortlaut von PHP.** "SSL read failed" ist
 *    ein anderes Gespräch mit dem Hoster als "php_connect_nonb()
 *    failed". Ohne das Einfangen landet der Satz im Fehlerprotokoll des
 *    Servers, also dort, wo ihn niemand sucht.
 *
 * 4. **Diese Klasse rät nicht.** Sie probiert keine Pfade durch und
 *    schlägt nichts vor. Erkunden ist Sache der Schicht darüber - und
 *    zwar erst, wenn feststeht, dass es gebraucht wird. Vorher lief es
 *    davor und hat das Urteil zerstört, das es stützen sollte.
 */
final class Ftp
{
    /** Wie lange auf den Handschlag gewartet wird. */
    private const TIMEOUT = 15;

    /**
     * Und wie lange bei der Ausgangsprobe.
     *
     * Kurz, absichtlich: Sie soll eine Frage beantworten, nicht die
     * Seite eine halbe Minute anhalten. Wer in sechs Sekunden nicht
     * antwortet, antwortet auch in fuenfzehn nicht.
     */
    private const TIMEOUT_PROBE = 6;

    /** Öffentliche FTP-Server für die Ausgangsprobe. */
    private const FREMDE = ['ftp.gnu.org', 'ftp.funet.fi'];

    /** @var resource|\FTP\Connection|null */
    private $c = null;

    /** @var array{host:string, port:int, username:string, password:string, tls:bool, timeout:int} */
    private array $zugang;

    /**
     * Ob die im Passivmodus genannte Adresse übergangen wird.
     *
     * Auf geteiltem Hosting nennt der Server dort gern eine interne
     * NAT-Adresse, die von aussen niemand erreicht. Bei einem Fehlschlag
     * wird das umgeschaltet und noch einmal versucht.
     */
    private bool $ohnePasvAdresse = false;

    private string $fehler = '';

    private function __construct(array $zugang)
    {
        $this->zugang = $zugang;
    }

    // ------------------------------------------------------------------
    // Aufmachen und zumachen
    // ------------------------------------------------------------------

    /**
     * Eine Verbindung öffnen.
     *
     * Gibt es ein Objekt zurück, steht die Leitung und die Anmeldung
     * sass. Sonst kommt ein Satz zurück, der sagt, woran es lag - kein
     * false, über das man stolpert.
     *
     * @param array{host:string, port?:int, username:string, password:string, protocol?:string} $zugang
     * @return self|string
     */
    public static function oeffnen(array $zugang)
    {
        if (!function_exists('ftp_connect')) {
            return 'Dieser Server kann kein FTP - die PHP-Erweiterung fehlt.';
        }

        $protokoll = (string) ($zugang['protocol'] ?? 'ftp');
        $tls = $protokoll === 'ftps';

        if ($tls && !function_exists('ftp_ssl_connect')) {
            return 'Dieses PHP kann kein verschluesseltes FTP - es fehlt OpenSSL.';
        }

        $ftp = new self([
            'host' => (string) $zugang['host'],
            'port' => (int) ($zugang['port'] ?? 21) ?: 21,
            'username' => (string) $zugang['username'],
            'password' => (string) $zugang['password'],
            'tls' => $tls,
            'timeout' => (int) ($zugang['timeout'] ?? self::TIMEOUT),
        ]);

        $fehler = $ftp->verbinden();

        return $fehler === '' ? $ftp : $fehler;
    }

    /** Aufbauen und anmelden. Leerer Rückgabewert heisst: hat geklappt. */
    private function verbinden(): string
    {
        // Dieselbe Wache wie beim ersten Mal. verbinden() wird auch aus
        // mitNeustart() gerufen und soll sich nicht darauf verlassen,
        // dass jemand vorher nachgesehen hat - ohne die Erweiterung ist
        // der Aufruf kein Fehler, den man melden kann, sondern ein
        // Absturz.
        if (!function_exists('ftp_connect')) {
            return 'Dieser Server kann kein FTP - die PHP-Erweiterung fehlt.';
        }

        $this->schliessen();

        $c = $this->messen(fn () => $this->zugang['tls']
            ? ftp_ssl_connect($this->zugang['host'], $this->zugang['port'], $this->zugang['timeout'])
            : ftp_connect($this->zugang['host'], $this->zugang['port'], $this->zugang['timeout']));

        if ($c === false) {
            return 'Auf Port ' . $this->zugang['port'] . ' antwortet nichts'
                . ($this->fehler !== '' ? ' (' . $this->fehler . ')' : '') . '.';
        }

        $angemeldet = $this->messen(
            fn () => ftp_login($c, $this->zugang['username'], $this->zugang['password'])
        );

        if ($angemeldet === false) {
            @ftp_close($c);

            return 'Die Anmeldung als ' . $this->zugang['username'] . ' wurde abgelehnt.';
        }

        $this->c = $c;

        // Passiv, immer. Aktiv verlangt, dass die Gegenseite sich zu uns
        // zurückverbindet - auf geteiltem Hosting eingehend gesperrt.
        @ftp_pasv($c, true);

        if ($this->ohnePasvAdresse && defined('FTP_USEPASVADDRESS')) {
            @ftp_set_option($c, FTP_USEPASVADDRESS, false);
        }

        return '';
    }

    public function schliessen(): void
    {
        if ($this->c !== null) {
            @ftp_close($this->c);
            $this->c = null;
        }
    }

    public function fehler(): string
    {
        return $this->fehler;
    }

    // ------------------------------------------------------------------
    // Die eigentliche Arbeit
    // ------------------------------------------------------------------

    /** Wo stehen wir nach der Anmeldung? */
    public function hier(): string
    {
        $pwd = $this->messen(fn () => ftp_pwd($this->c));

        return is_string($pwd) && $pwd !== '' ? $pwd : '/';
    }

    /**
     * Was in einem Ordner liegt.
     *
     * null heisst: konnte nicht nachsehen. [] heisst: nachgesehen, und
     * es liegt nichts darin. Der Unterschied ist der Grund für diese
     * Klasse.
     *
     * @return array<int, string>|null
     */
    public function liste(string $pfad = ''): ?array
    {
        $ziel = $pfad !== '' ? $pfad : $this->hier();

        $roh = $this->mitNeustart(function () use ($ziel) {
            $namen = ftp_nlist($this->c, $ziel);

            // Manche Server antworten auf NLST in einem leeren Ordner
            // mit einem Fehler statt mit einer leeren Liste. LIST fragt
            // dasselbe noch einmal anders.
            if ($namen === false) {
                $namen = self::ausRohzeilen(ftp_rawlist($this->c, $ziel));
            }

            return $namen;
        });

        return $roh === false ? null : self::nurNamen((array) $roh);
    }

    /** In einen Ordner wechseln. */
    public function wechseln(string $pfad): bool
    {
        return (bool) $this->messen(fn () => ftp_chdir($this->c, $pfad));
    }

    /**
     * Den Ordner anlegen, falls er fehlt - samt Elternordnern.
     *
     * Ein Fehlschlag beim Anlegen ist kein Grund aufzugeben: Oft gibt
     * es den Ordner schon, und nur das Anlegen war verboten.
     */
    public function ordnerSicherstellen(string $pfad): bool
    {
        $pfad = trim($pfad, '/');

        if ($pfad === '') {
            return true;
        }

        $gebaut = '';

        foreach (explode('/', $pfad) as $stueck) {
            if ($stueck === '') {
                continue;
            }

            $gebaut .= '/' . $stueck;

            if (!$this->wechseln($gebaut)) {
                $this->messen(fn () => ftp_mkdir($this->c, $gebaut));
            }
        }

        return $this->wechseln('/' . $pfad);
    }

    /**
     * Einen Datenstrom hinaufschreiben.
     *
     * Der Strom wird vor jedem Versuch zurückgespult - sonst schreibt
     * der zweite Versuch die Datei halb.
     *
     * @param resource $strom
     */
    public function schreiben(string $fern, $strom): bool
    {
        return (bool) $this->mitNeustart(function () use ($fern, $strom) {
            @rewind($strom);

            return ftp_fput($this->c, $fern, $strom, FTP_BINARY);
        });
    }

    /**
     * Eine Datei herunterholen, in einen offenen Strom.
     *
     * @param resource $strom
     */
    public function lesen(string $fern, $strom): bool
    {
        return (bool) $this->mitNeustart(function () use ($fern, $strom) {
            // ftp_fget nimmt den Strom zuerst, ftp_fput den Namen -
            // die beiden sind spiegelverkehrt.
            @rewind($strom);

            return ftp_fget($this->c, $strom, $fern, FTP_BINARY);
        });
    }

    /** Wie gross ist die Datei? -1, wenn der Server es nicht sagt. */
    public function groesse(string $fern): int
    {
        $bytes = $this->messen(fn () => ftp_size($this->c, $fern));

        return is_int($bytes) ? $bytes : -1;
    }

    public function loeschen(string $fern): bool
    {
        return (bool) $this->messen(fn () => ftp_delete($this->c, $fern));
    }

    // ------------------------------------------------------------------
    // Die zwei Regeln, die alles zusammenhalten
    // ------------------------------------------------------------------

    /**
     * Eine Übertragung - und bei Fehlschlag auf frischer Leitung noch
     * einmal.
     *
     * Nach einem abgebrochenen Datentransfer ist der Steuerkanal nicht
     * mehr verlässlich (gemessen, siehe Klassenkopf). Deshalb wird die
     * Verbindung weggeworfen statt weiterbenutzt. Der zweite Versuch
     * schaltet zusätzlich um, ob die vom Server genannte Passivadresse
     * verwendet wird - das ist der zweithäufigste Grund auf geteiltem
     * Hosting.
     *
     * @return mixed false, wenn es endgültig nicht ging
     */
    private function mitNeustart(callable $tat)
    {
        $ergebnis = $this->messen($tat);

        if ($ergebnis !== false) {
            return $ergebnis;
        }

        $ersterFehler = $this->fehler;

        $this->ohnePasvAdresse = !$this->ohnePasvAdresse;

        if ($this->verbinden() !== '') {
            $this->fehler = $ersterFehler;

            return false;
        }

        $ergebnis = $this->messen($tat);

        if ($ergebnis === false && $this->fehler === '') {
            $this->fehler = $ersterFehler;
        }

        return $ergebnis;
    }

    /**
     * Eine Aktion ausführen und dabei mitschreiben, was PHP bemängelt.
     *
     * @return mixed
     */
    private function messen(callable $tat)
    {
        $this->fehler = '';

        set_error_handler(function (int $nummer, string $text): bool {
            if ($this->fehler === '') {
                $this->fehler = trim((string) preg_replace('/^ftp_\w+\(\):\s*/', '', $text));
            }

            return true;
        });

        try {
            return $tat();
        } finally {
            restore_error_handler();
        }
    }

    // ------------------------------------------------------------------
    // Der Test: ein Satz, und Einzelheiten für den, der sie braucht
    // ------------------------------------------------------------------

    /**
     * Stimmt der Zugang?
     *
     * @param array{host:string, port?:int, username:string, password:string, protocol?:string, path?:string} $zugang
     * @return array{ok:bool, satz:string, details:array<int,string>}
     */
    public static function pruefen(array $zugang): array
    {
        $host = (string) ($zugang['host'] ?? '');
        $pfad = (string) ($zugang['path'] ?? '/');
        $details = [];

        if ($host === '' || !self::loestAuf($host)) {
            return self::urteil(false, 'Den Servernamen ' . ($host !== '' ? $host : '(leer)')
                . ' gibt es nicht.', $details);
        }

        $details[] = $host . ' löst auf.';

        $ftp = self::oeffnen($zugang);

        if (is_string($ftp)) {
            return self::urteil(false, $ftp, $details);
        }

        try {
            $daheim = $ftp->hier();
            $details[] = 'Angemeldet. Nach der Anmeldung stehst du in ' . $daheim . '.';

            $inhalt = $ftp->liste($daheim);

            if ($inhalt === null) {
                $details[] = 'Der Startordner liess sich nicht auflisten'
                    . ($ftp->fehler() !== '' ? ' (' . $ftp->fehler() . ')' : '') . '.';

                foreach ($ftp->datenDetails() as $zeile) {
                    $details[] = $zeile;
                }

                // Kommt der Abbruch aus der Verschluesselung, ist der
                // naechste Schritt ein anderer als bei einem Netzfehler:
                // Viele cPanel-Server verlangen, dass die Datenverbindung
                // die TLS-Sitzung der Steuerverbindung wiederverwendet -
                // das kann PHP nicht.
                $grund = $ftp->fehler();

                if (stripos($grund, 'ssl') !== false || stripos($grund, 'tls') !== false) {
                    return self::urteil(false, 'Die Anmeldung sitzt, aber die Datenverbindung '
                        . 'bricht in der Verschluesselung ab. Stell die Verbindungsart einmal '
                        . 'auf „FTP“ ohne Verschluesselung um - laeuft es dann durch, verlangt '
                        . 'dieser Server eine wiederverwendete TLS-Sitzung.', $details);
                }

                return self::urteil(false, 'Die Anmeldung sitzt, aber die Datenverbindung '
                    . 'kommt nicht zustande.', $details);
            }

            $details[] = $inhalt === []
                ? $daheim . ' ist leer - lesen liess er sich aber.'
                : count($inhalt) . ' Einträge im Startordner: ' . implode(', ', array_slice($inhalt, 0, 8));

            if (!$ftp->wechseln($pfad)) {
                $details[] = $pfad . ' gibt es von diesem Zugang aus nicht.';

                return self::urteil(false, 'Das Verzeichnis ' . $pfad . ' gibt es nicht.', $details);
            }

            $details[] = $pfad . ' ist vorhanden.';

            if (!$ftp->schreibprobe($pfad)) {
                $details[] = 'Die Schreibprobe scheiterte'
                    . ($ftp->fehler() !== '' ? ' (' . $ftp->fehler() . ')' : '') . '.';

                return self::urteil(false, 'Lesen geht, schreiben nicht - zum Herunterladen '
                    . 'reicht das, zum Hochladen nicht.', $details);
            }

            $details[] = 'Datei angelegt und wieder entfernt - der Zugang darf schreiben.';

            return self::urteil(true, 'Alles bereit: angemeldet, ' . $pfad
                . ' vorhanden und beschreibbar.', $details);
        } finally {
            $ftp->schliessen();
        }
    }

    /** @param array<int,string> $details */
    private static function urteil(bool $ok, string $satz, array $details): array
    {
        return ['ok' => $ok, 'satz' => $satz, 'details' => $details];
    }

    /**
     * Darf dieser Zugang dort wirklich schreiben?
     *
     * Ohne diese Probe heisst "grün" nur, dass der Ordner existiert. Ein
     * nur lesender Zugang fällt sonst erst beim Hochladen auf - also
     * genau dann, wenn es eilig ist.
     */
    public function schreibprobe(string $pfad): bool
    {
        $ziel = rtrim($pfad, '/') . '/.webatze-probe-' . bin2hex(random_bytes(4));
        $strom = fopen('php://temp', 'r+');

        if ($strom === false) {
            return false;
        }

        fwrite($strom, 'webatze');

        $ok = $this->schreiben($ziel, $strom);
        fclose($strom);

        if ($ok) {
            $this->loeschen($ziel);
        }

        return $ok;
    }

    // ------------------------------------------------------------------
    // Wenn die Datenverbindung nicht kommt: nachmessen statt vermuten
    // ------------------------------------------------------------------

    /**
     * Die Datenverbindung von Hand aufbauen und berichten.
     *
     * "Meist eine blockierte Datenverbindung" ist eine Vermutung, und
     * mit einer Vermutung geht man nicht zum Hoster. Also PASV selbst
     * schicken, die genannte Adresse auslesen und dort anklopfen -
     * dreimal, denn ein einzelner Port kann zufällig belegt sein.
     *
     * @return array<int, string>
     */
    public function datenDetails(): array
    {
        $versuche = [];

        for ($i = 0; $i < 3; $i++) {
            $versuch = $this->passivProbe();

            if ($versuch === null) {
                break;
            }

            $versuche[] = $versuch;

            if ($versuch['offen']) {
                break;
            }
        }

        if ($versuche === []) {
            return ['Der Server hat auf PASV nicht wie erwartet geantwortet.'];
        }

        $adresse = $versuche[0]['adresse'];
        $ports = implode(', ', array_column($versuche, 'port'));
        $letzter = $versuche[count($versuche) - 1];

        $zeilen = ['Der Server nennt ' . $adresse . ', Port ' . $ports . '.'];

        if ($versuche[0]['intern']) {
            $zeilen[] = 'Das ist eine interne Adresse - von aussen erreicht sie niemand. '
                . 'Geklopft habe ich deshalb an ' . $this->zugang['host'] . '.';
        }

        if ($letzter['offen']) {
            $zeilen[] = 'Dort steht die Verbindung. Der Weg ist frei - dann liegt es nicht am Netz.';

            return $zeilen;
        }

        $zeilen[] = $letzter['abgewiesen']
            ? 'Die Verbindung wird abgewiesen (Connection refused) - der Port ist nicht offen.'
            : 'Dort kommt keine Antwort (' . $letzter['text'] . ') - die Pakete werden verworfen, '
                . 'typisch für eine Firewall.';

        $zeilen[] = 'Port ' . $this->zugang['port'] . ' steht, ' . count($versuche)
            . ' Ports aus dem Passivbereich stehen nicht.';

        return $zeilen;
    }

    /**
     * Einmal PASV schicken und an der genannten Stelle anklopfen.
     *
     * @return array{adresse:string, port:int, intern:bool, offen:bool, abgewiesen:bool, text:string}|null
     */
    private function passivProbe(): ?array
    {
        $antwort = $this->messen(fn () => ftp_raw($this->c, 'PASV'));
        $zeile = trim((string) (is_array($antwort) ? ($antwort[0] ?? '') : ''));

        if (!preg_match('/\((\d+),(\d+),(\d+),(\d+),(\d+),(\d+)\)/', $zeile, $t)) {
            return null;
        }

        $adresse = $t[1] . '.' . $t[2] . '.' . $t[3] . '.' . $t[4];
        $port = ((int) $t[5] << 8) + (int) $t[6];
        $intern = self::internesNetz($adresse);

        $nummer = 0;
        $text = '';
        $sock = @fsockopen($intern ? $this->zugang['host'] : $adresse, $port, $nummer, $text, 8.0);

        if (is_resource($sock)) {
            fclose($sock);

            return ['adresse' => $adresse, 'port' => $port, 'intern' => $intern,
                'offen' => true, 'abgewiesen' => false, 'text' => 'offen'];
        }

        // "Connection refused" heisst: da ist ein Rechner, aber kein
        // offener Port. Eine Zeitüberschreitung heisst: die Pakete
        // verschwinden unterwegs. Zwei verschiedene Gespräche mit dem
        // Hoster.
        return [
            'adresse' => $adresse,
            'port' => $port,
            'intern' => $intern,
            'offen' => false,
            'abgewiesen' => $nummer === 111 || stripos($text, 'refused') !== false,
            'text' => $text !== '' ? $text : 'Zeitüberschreitung',
        ];
    }

    // ------------------------------------------------------------------
    // Die Ausgangsprobe
    // ------------------------------------------------------------------

    /**
     * Darf dieser Server überhaupt eine FTP-Datenverbindung öffnen?
     *
     * Die Frage, die sich vom Kundenserver aus nie beantworten liess:
     * Wenn dort das Auflisten scheitert, kann die Ursache eingehend bei
     * ihm liegen - oder ausgehend bei uns. Von einem Endpunkt aus sieht
     * beides gleich aus.
     *
     * Diese Probe fragt dasselbe noch einmal, nur an einem fremden
     * Ziel: anonym bei einem öffentlichen FTP-Server auflisten. Klappt
     * das, ist der Ausgang frei und die Sperre sitzt beim Hoster des
     * Kunden. Klappt es nirgends, darf dieser Server keine
     * Datenverbindungen öffnen - dann ist FTP von hier aus grundsätzlich
     * tot, und kein Neubau ändert daran etwas.
     *
     * @return array{ok:bool, satz:string, details:array<int,string>}
     */
    public static function ausgangsprobe(): array
    {
        if (!function_exists('ftp_connect')) {
            return self::urteil(false, 'Dieser Server kann kein FTP - die PHP-Erweiterung fehlt.', []);
        }

        $details = [];

        // Zwei verschiedene Fehlschläge, zwei verschiedene Aussagen:
        // gar nicht auf Port 21 hinaus ist etwas anderes als angemeldet,
        // aber ohne Datenkanal.
        $bisPort21 = false;

        foreach (self::FREMDE as $fremd) {
            if (!self::loestAuf($fremd)) {
                $details[] = $fremd . ': löst nicht auf.';

                continue;
            }

            $ftp = self::oeffnen([
                'host' => $fremd,
                'port' => 21,
                'username' => 'anonymous',
                'password' => 'webatze@example.com',
                'protocol' => 'ftp',
                'timeout' => self::TIMEOUT_PROBE,
            ]);

            if (is_string($ftp)) {
                $details[] = $fremd . ': ' . $ftp;

                continue;
            }

            $bisPort21 = true;
            $inhalt = $ftp->liste('/');
            $grund = $ftp->fehler();
            $ftp->schliessen();

            if ($inhalt !== null) {
                $details[] = $fremd . ': Auflistung geklappt (' . count($inhalt) . ' Einträge).';

                return self::urteil(
                    true,
                    'Dieser Server darf FTP-Datenverbindungen öffnen - der Ausgang ist frei. '
                    . 'Scheitert es beim Kunden, sitzt die Sperre auf dessen Seite.',
                    $details
                );
            }

            $details[] = $fremd . ': angemeldet, aber keine Datenverbindung'
                . ($grund !== '' ? ' (' . $grund . ')' : '') . '.';
        }

        if (!$bisPort21) {
            return self::urteil(
                false,
                'Dieser Server kommt nicht einmal auf Port 21 nach draussen - ausgehendes '
                . 'FTP ist hier komplett gesperrt. Das sagt allerdings nichts über die '
                . 'Verbindung zum Kunden, wenn die sich anmelden lässt.',
                $details
            );
        }

        return self::urteil(
            false,
            'Anmelden ja, Daten nein: Dieser Server bekommt zu keinem fremden FTP-Server '
            . 'eine Datenverbindung. Dann liegt es nicht am Kundenserver, sondern am '
            . 'Ausgang dieses Hostings - und mit FTP ist von hier aus nichts zu machen.',
            $details
        );
    }

    // ------------------------------------------------------------------
    // Kleinkram
    // ------------------------------------------------------------------

    /** Gibt es diesen Namen im DNS? */
    public static function loestAuf(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return $host !== '' && gethostbyname($host) !== $host;
    }

    /** Adressen, die nur im eigenen Netz gelten. */
    public static function internesNetz(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * Aus den rohen Zeilen von LIST die Namen holen.
     *
     * Eine Zeile sieht aus wie "drwxr-xr-x 2 web web 4096 Sep 5 12:00
     * assets" - gebraucht wird nur das letzte Feld.
     *
     * @param array<int, string>|false $zeilen
     * @return array<int, string>|false
     */
    public static function ausRohzeilen($zeilen)
    {
        if (!is_array($zeilen)) {
            return false;
        }

        $namen = [];

        foreach ($zeilen as $zeile) {
            $zeile = trim((string) $zeile);

            if ($zeile === '' || str_starts_with($zeile, 'total ')) {
                continue;
            }

            $felder = preg_split('/\s+/', $zeile, 9);

            $namen[] = (is_array($felder) && count($felder) === 9) ? $felder[8] : $zeile;
        }

        return $namen;
    }

    /**
     * Aus Pfaden Namen machen, ohne "." und "..".
     *
     * @param array<int, mixed> $eintraege
     * @return array<int, string>
     */
    public static function nurNamen(array $eintraege): array
    {
        $namen = [];

        foreach ($eintraege as $eintrag) {
            $name = basename((string) $eintrag);

            if ($name !== '' && $name !== '.' && $name !== '..') {
                $namen[] = $name;
            }
        }

        return array_values(array_unique($namen));
    }
}
