<?php

declare(strict_types=1);

namespace WebAtze\Build;

use WebAtze\Core\{Db, Http, Logger};
use WebAtze\Domain\Bridge;

/**
 * Der Weg ohne FTP.
 *
 * FTP braucht zwei Verbindungen: eine für die Befehle auf Port 21 und
 * für jede Datei eine zweite auf einem hohen Port. Genau die zweite
 * wird auf geteiltem Hosting gern verworfen - und dann hilft kein
 * Neubau, weil das Protokoll selbst nicht durchkommt.
 *
 * HTTPS braucht nur eine Verbindung, auf Port 443, und die ist von
 * jedem Webserver aus offen. Also: eine kleine Empfangsdatei einmal von
 * Hand auf die Kundenwebsite legen, und danach geht alles über 443 -
 * hinauf und herunter.
 *
 * Nebenbei fällt damit auch die Pfadfrage weg: Der Empfänger arbeitet
 * immer in dem Ordner, in dem er selbst liegt. Ein falsches Verzeichnis
 * kann es nicht geben.
 *
 * Warum nicht die bestehende Brücke?
 *
 *   `Domain\Bridge` sagt in ihrem eigenen Kopf zu: "Die Brücke nimmt
 *   Daten entgegen, nie Code." Eine Kundenwebsite besteht aus PHP.
 *   Dieses Versprechen zu brechen wäre der falsche Preis - die Brücke
 *   ist aus dem Internet erreichbar und hängt an einem einzigen
 *   geteilten Schlüssel. Der Empfänger hier ist etwas anderes: Er wird
 *   bewusst hingelegt, tut eine Sache, und verschwindet danach wieder.
 *
 * Abgesichert mit dem, was es schon gibt: dieselbe Unterschrift wie die
 * Brücke (`Bridge::sign`), HMAC-SHA256 über Methode, Pfad, Zeitstempel,
 * Einmalwert und Rumpf. Der Schlüssel steht in der Empfangsdatei und
 * geht nie über die Leitung.
 */
final class Empfang
{
    /** Wie die Datei auf der Kundenwebsite heisst, die kurz hier liegt. */
    public const DATEI = 'webatze-empfang.php';

    /**
     * Und wie die, die liegen bleibt.
     *
     * Der Unterschied ist nicht die Technik, sondern was sie darf: Diese
     * hier liest nur. Eine dauerhaft erreichbare *Schreib*stelle auf
     * einer Kundenwebsite ist kein Zustand, den man hinterlaesst - eine
     * Lesestelle ist etwas anderes. Sie gibt im schlimmsten Fall das
     * heraus, was die Website ohnehin ausliefert, und die Geheimnisse
     * (config.php und ihresgleichen) haelt sie zurueck.
     */
    public const DAUERDATEI = 'wa-dateien.php';

    /** Wie lange auf eine Antwort gewartet wird. */
    private const TIMEOUT = 30;

    /**
     * Und wie lange beim blossen Nachsehen.
     *
     * Kürzer, weil das Ergebnis eine Zeile auf einer Seite ist: Eine
     * Probe, die eine halbe Minute steht, beantwortet die Frage nicht
     * mehr rechtzeitig, um noch nützlich zu sein.
     */
    private const TIMEOUT_PROBE = 8;

    /**
     * Der Schlüssel dieser Website - beim ersten Mal angelegt.
     *
     * Eigener Schlüssel, nicht der der Brücke: Wer den Empfänger von
     * der Website liest, hätte sonst auch die Brücke offen.
     */
    public static function schluessel(int $projectId): string
    {
        $vorhanden = (string) Db::value(
            'SELECT empfang_secret FROM projects WHERE id = :id',
            ['id' => $projectId]
        );

        if ($vorhanden !== '') {
            return $vorhanden;
        }

        $neu = bin2hex(random_bytes(32));

        Db::update('projects', ['empfang_secret' => $neu, 'updated_at' => Db::now()],
            'id = :id', ['id' => $projectId]);

        return $neu;
    }

    /** Einen neuen Schlüssel setzen - der alte gilt dann nicht mehr. */
    public static function neuerSchluessel(int $projectId): string
    {
        Db::update('projects', ['empfang_secret' => '', 'updated_at' => Db::now()],
            'id = :id', ['id' => $projectId]);

        return self::schluessel($projectId);
    }

    /**
     * Der Schlüssel der dauerhaften Leseschnittstelle.
     *
     * Ein eigener, nicht der des Empfängers: Der wird von Hand
     * hingelegt und löscht sich wieder, diese bleibt liegen. Zwei
     * Lebensdauern, zwei Schlüssel - sonst widerruft das Entfernen des
     * einen den anderen nicht.
     */
    public static function leseschluessel(int $projectId): string
    {
        $vorhanden = (string) Db::value(
            'SELECT dateien_secret FROM projects WHERE id = :id',
            ['id' => $projectId]
        );

        if ($vorhanden !== '') {
            return $vorhanden;
        }

        $neu = bin2hex(random_bytes(32));

        Db::update('projects', ['dateien_secret' => $neu, 'updated_at' => Db::now()],
            'id = :id', ['id' => $projectId]);

        return $neu;
    }

    /** Einen neuen Leseschlüssel setzen - die liegende Datei gilt dann nicht mehr. */
    public static function neuerLeseschluessel(int $projectId): string
    {
        Db::update('projects', ['dateien_secret' => '', 'updated_at' => Db::now()],
            'id = :id', ['id' => $projectId]);

        return self::leseschluessel($projectId);
    }

    /** Die Leseschnittstelle, fertig zum Mitliefern. */
    public static function dauerdatei(int $projectId): string
    {
        $vorlage = (string) @file_get_contents(APP_DIR . '/Kit/site/php/' . self::DAUERDATEI);

        if ($vorlage === '') {
            return '';
        }

        return str_replace('%%SCHLUESSEL%%', self::leseschluessel($projectId), $vorlage);
    }

    /**
     * Die Empfangsdatei, fertig zum Hinlegen.
     *
     * Der Schlüssel wird in die Vorlage gesetzt - sonst ist die Datei
     * überall dieselbe.
     */
    public static function datei(int $projectId): string
    {
        $vorlage = (string) @file_get_contents(APP_DIR . '/Kit/empfang/' . self::DATEI);

        if ($vorlage === '') {
            return '';
        }

        return str_replace('%%SCHLUESSEL%%', self::schluessel($projectId), $vorlage);
    }

    /**
     * Ein ZIP über HTTPS hinaufschicken - Datei für Datei.
     *
     * Nicht das ganze Archiv auf einmal: Eine Website mit Bildern
     * sprengt jede Speichergrenze auf geteiltem Hosting, und einzeln
     * lässt sich auch der Fortschritt zeigen. Geprüft wird das Archiv
     * vorher hier, mit denselben Regeln wie beim FTP-Weg; der Empfänger
     * prüft jeden Pfad noch einmal.
     *
     * Am Ende faehrt die dauerhafte Leseschnittstelle mit - ab dann
     * geht "Stand holen" ohne jeden Handgriff.
     *
     * @param callable|null $onProgress fn(int $fertig, int $gesamt, string $datei)
     * @return array{ok:bool, files:int, error:string, retryable:bool}
     */
    public static function senden(
        array $projekt,
        string $zipPfad,
        ?callable $onProgress = null,
        float $budget = 120.0,
        bool $aufraeumen = true,
        bool $leseZugang = true
    ): array {
        $adresse = self::adresse($projekt);

        if ($adresse === '') {
            return self::fehler('Diese Website hat keine Adresse - ohne die geht es nicht.', false);
        }

        if (!class_exists(\ZipArchive::class)) {
            return self::fehler('Dieser Server kann keine ZIP-Dateien lesen.', false);
        }

        $zip = new \ZipArchive();

        if ($zip->open($zipPfad) !== true) {
            return self::fehler('Das Archiv liess sich nicht öffnen.', false);
        }

        $plan = FtpDeployer::archivPlan($zip);

        // archivPlan meldet einen Fehler als Text und "kein Fehler" als
        // null - beides muss hier ankommen, ohne dass es kracht.
        $grund = (string) ($plan['fehler'] ?? '');

        if ($grund !== '') {
            $zip->close();

            return self::fehler($grund, false);
        }

        $schluessel = self::schluessel((int) $projekt['id']);
        $begonnen = microtime(true);
        $fertig = 0;
        $gesamt = count($plan['dateien']);

        try {
            foreach ($plan['dateien'] as $datei) {
                if (microtime(true) - $begonnen > $budget) {
                    return self::fehler(sprintf(
                        'Zeit abgelaufen nach %d von %d Dateien. Der Auftrag wird fortgesetzt.',
                        $fertig,
                        $gesamt
                    ), true);
                }

                $inhalt = $zip->getFromIndex($datei['index']);

                if ($inhalt === false) {
                    return self::fehler('Die Datei ' . $datei['ziel'] . ' liess sich nicht lesen.', false);
                }

                $antwort = self::anfrage($adresse, $schluessel, 'schreiben', [
                    'pfad' => $datei['ziel'],
                    'inhalt' => base64_encode($inhalt),
                ]);

                if (!$antwort['ok']) {
                    return self::fehler(
                        'Die Datei ' . $datei['ziel'] . ' kam nicht an: ' . $antwort['error'],
                        $antwort['status'] === 0 || $antwort['status'] >= 500
                    );
                }

                $fertig++;

                if ($onProgress !== null) {
                    $onProgress($fertig, $gesamt, $datei['ziel']);
                }
            }
        } finally {
            $zip->close();
        }

        // Die Leseschnittstelle faehrt mit.
        //
        // Das ist der Punkt, an dem der Weg ohne FTP von einer einmaligen
        // Rettung zu einer dauerhaften Leitung wird: Nach diesem Upload
        // liegt sie auf der Website, und "Stand holen" braucht danach
        // keine Handgriffe mehr - kein Hinlegen, kein FTP-Programm,
        // nichts. Sie liest nur; geschrieben wird weiterhin nur, solange
        // der Empfaenger daneben liegt.
        $lesen = false;

        if ($leseZugang) {
            $inhalt = self::dauerdatei((int) $projekt['id']);

            $lesen = $inhalt !== '' && self::anfrage($adresse, $schluessel, 'schreiben', [
                'pfad' => self::DAUERDATEI,
                'inhalt' => base64_encode($inhalt),
            ])['ok'];
        }

        return [
            'ok' => true,
            'files' => $fertig,
            'error' => '',
            'retryable' => false,
            'lesezugang' => $lesen,
            'aufgeraeumt' => self::vielleichtAufraeumen($adresse, $schluessel, $aufraeumen),
        ];
    }

    /**
     * Den ganzen Stand über HTTPS herunterholen.
     *
     * Das Gegenstück zu `FtpDeployer::fetchTree()` und mit Absicht in
     * derselben Ergebnisform: `ZipExporter::pullLive()` soll den einen
     * gegen den anderen tauschen können, ohne dass irgendetwas danach
     * davon weiss.
     *
     * Auch dieselben Grenzen und dieselbe Überspringliste - was über
     * FTP nicht ins Archiv kommt, soll auch über HTTPS nicht
     * hineinkommen. Zwei Wege, die verschiedene Archive liefern, wären
     * schlimmer als einer.
     *
     * @param callable|null $onProgress fn(int $dateien, string $pfad)
     * @return array{ok:bool, files:int, bytes:int, error:string, abgeschnitten:bool, aufgeraeumt:bool, tmp:array<int,string>}
     */
    public static function holen(
        array $projekt,
        \ZipArchive $zip,
        float $budget = 90.0,
        ?callable $onProgress = null,
        bool $aufraeumen = true
    ): array {
        $weg = self::wegFinden($projekt);

        if (!$weg['ok']) {
            return self::baumFehler('Keine Schnittstelle auf der Website erreichbar: ' . $weg['error']);
        }

        $adresse = $weg['adresse'];
        $schluessel = $weg['schluessel'];
        $ende = microtime(true) + $budget;

        // Die dauerhafte Schnittstelle raeumt sich nicht weg - sie ist
        // kein Besuch, sie gehoert zur Website. Nur der Empfaenger, der
        // von Hand hingelegt wurde, verschwindet danach wieder.
        $aufraeumen = $aufraeumen && $weg['art'] === 'empfang';

        $verzeichnis = self::anfrage($adresse, $schluessel, 'liste', []);

        if (!$verzeichnis['ok']) {
            return self::baumFehler('Die Website nennt ihren Inhalt nicht: ' . $verzeichnis['error']);
        }

        $dateien = (array) ($verzeichnis['daten']['dateien'] ?? []);
        $abgeschnitten = (bool) ($verzeichnis['daten']['abgeschnitten'] ?? false);
        // Die Leseschnittstelle gibt config.php und ihresgleichen nicht
        // heraus. Das ist richtig so - aber es gehoert in die Meldung,
        // sonst haelt jemand ein unvollstaendiges Archiv fuer eine
        // vollstaendige Sicherung.
        $zurueckgehalten = (int) ($verzeichnis['daten']['zurueckgehalten'] ?? 0);
        $anzahl = 0;
        $bytes = 0;

        foreach ($dateien as $eintrag) {
            $relativ = (string) ($eintrag['pfad'] ?? '');

            if ($relativ === '' || FtpDeployer::baumUebergehen($relativ)) {
                continue;
            }

            if (substr_count($relativ, '/') > FtpDeployer::MAX_TREE_DEPTH) {
                continue;
            }

            if ($anzahl >= FtpDeployer::MAX_TREE_FILES
                || $bytes >= FtpDeployer::MAX_TREE_BYTES
                || microtime(true) >= $ende
            ) {
                $abgeschnitten = true;
                break;
            }

            $antwort = self::anfrage($adresse, $schluessel, 'holen', ['pfad' => $relativ]);

            if (!$antwort['ok']) {
                // Eine einzelne Datei, die nicht kommt, beendet nicht
                // den ganzen Stand - sie fehlt, und das Archiv sagt es
                // über "unvollständig".
                Logger::warning('Datei kam nicht über den Empfänger', [
                    'pfad' => $relativ,
                    'grund' => $antwort['error'],
                ]);
                $abgeschnitten = true;

                continue;
            }

            $inhalt = base64_decode((string) ($antwort['daten']['inhalt'] ?? ''), true);

            if ($inhalt === false) {
                $abgeschnitten = true;

                continue;
            }

            $zip->addFromString($relativ, $inhalt);

            $anzahl++;
            $bytes += strlen($inhalt);

            if ($onProgress !== null) {
                $onProgress($anzahl, $relativ);
            }
        }

        $weg = self::vielleichtAufraeumen($adresse, $schluessel, $aufraeumen);

        return [
            'ok' => $anzahl > 0,
            'aufgeraeumt' => $weg,
            'files' => $anzahl,
            'bytes' => $bytes,
            'error' => $anzahl > 0 ? '' : 'Es kam keine einzige Datei an. Liegt der Empfänger im richtigen Ordner?',
            'abgeschnitten' => $abgeschnitten,
            'zurueckgehalten' => $zurueckgehalten,
            // Nichts zwischengespeichert: Der Weg über HTTPS reicht die
            // Inhalte direkt ins Archiv weiter.
            'tmp' => [],
        ];
    }

    /**
     * Steht eine Schnittstelle bereit - und welche?
     *
     * @return array{ok:bool, art:string, error:string}
     */
    public static function erreichbar(array $projekt): array
    {
        $weg = self::wegFinden($projekt);

        return ['ok' => $weg['ok'], 'art' => $weg['art'], 'error' => $weg['error']];
    }

    /**
     * Den Empfänger jetzt entfernen.
     *
     * Das Gegenstück zum "liegen lassen": Wer ihn stehen lässt, muss
     * ihn auch wieder wegräumen können, ohne die 24 Stunden abzuwarten
     * oder ein FTP-Programm zu bemühen.
     */
    public static function weg(array $projekt): array
    {
        $adresse = self::adresse($projekt);

        if ($adresse === '') {
            return ['ok' => false, 'error' => 'Diese Website hat keine Adresse.'];
        }

        $antwort = self::anfrage(
            $adresse,
            self::schluessel((int) $projekt['id']),
            'fertig',
            [],
            self::TIMEOUT_PROBE
        );

        return ['ok' => $antwort['ok'], 'error' => $antwort['error']];
    }

    // ------------------------------------------------------------------
    // Innereien
    // ------------------------------------------------------------------

    /**
     * Nach getaner Arbeit abräumen - wenn nicht anders gewünscht.
     *
     * Eine Schreibstelle, die auf einer Kundenwebsite stehen bleibt,
     * ist kein Zustand, den man hinterlässt - auch wenn sie
     * unterschrieben ist. Deshalb ist Wegräumen die Vorgabe und
     * Liegenlassen die bewusste Ausnahme.
     */
    private static function vielleichtAufraeumen(string $adresse, string $schluessel, bool $aufraeumen): bool
    {
        if (!$aufraeumen) {
            return false;
        }

        return (bool) self::anfrage($adresse, $schluessel, 'fertig', [])['ok'];
    }

    /**
     * Eine unterschriebene Anfrage an den Empfänger.
     *
     * @param array<string, mixed> $rumpf
     * @return array{ok:bool, error:string, status:int, daten:array<string, mixed>}
     */
    private static function anfrage(
        string $adresse,
        string $schluessel,
        string $aktion,
        array $rumpf,
        int $timeout = self::TIMEOUT
    ): array {
        $rumpf['aktion'] = $aktion;
        $text = (string) json_encode($rumpf, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $zeit = time();
        $einmal = bin2hex(random_bytes(16));
        $pfad = (string) (parse_url($adresse, PHP_URL_PATH) ?: '/');

        $unterschrift = Bridge::sign($schluessel, 'POST', $pfad, $zeit, $einmal, $text);

        try {
            $antwort = Http::postRaw($adresse, $text, [
                'Content-Type: application/json',
                'X-WebAtze-Zeit: ' . $zeit,
                'X-WebAtze-Einmal: ' . $einmal,
                'X-WebAtze-Unterschrift: ' . $unterschrift,
            ], $timeout);
        } catch (\Throwable $e) {
            Logger::exception($e);

            return self::abfuhr('Die Website antwortet nicht: ' . $e->getMessage(), 0);
        }

        $status = (int) ($antwort['status'] ?? 0);
        $daten = json_decode((string) ($antwort['body'] ?? ''), true);

        if ($status !== 200 || !is_array($daten) || ($daten['ok'] ?? false) !== true) {
            /**
             * Der Wortlaut, nicht die Zusammenfassung.
             *
             * "Die Website antwortet nicht" hiess bisher dreierlei: kein
             * Zertifikat, kein Name im DNS, oder eine Sperre beim
             * Hoster. Wer das liest, weiss nachher weniger als vorher.
             * cURL sagt jedes davon deutlich - also steht es jetzt da.
             */
            $vonDrueben = is_array($daten) ? (string) ($daten['error'] ?? '') : '';
            $vonCurl = (string) ($antwort['error'] ?? '');

            if ($vonDrueben !== '') {
                $grund = $vonDrueben;
            } elseif ($status === 0) {
                $grund = $vonCurl !== '' ? $vonCurl : 'Die Website antwortet nicht.';
            } elseif ($status === 404) {
                $grund = 'Unter dieser Adresse liegt keine Empfangsdatei (404).';
            } elseif ($status >= 200 && $status < 300) {
                // Gemessen an einer echten Website: Wo ein Front-Controller
                // sitzt - und eine von uns gebaute Website hat einen -,
                // beantwortet er auch die Anfrage an eine Datei, die es
                // nicht gibt, und zwar mit 200 und der Startseite. "Antwort
                // 200 vom Empfänger" waere dann die Unwahrheit an der
                // heikelsten Stelle: Es hat gar kein Empfaenger geantwortet.
                $grund = 'Die Website antwortet, aber nicht der Empfänger - '
                    . 'liegt die Datei wirklich im Verzeichnis der Website?';
            } else {
                $grund = 'Antwort ' . $status . ' vom Empfänger.';
            }

            return self::abfuhr($grund, $status);
        }

        return ['ok' => true, 'error' => '', 'status' => $status, 'daten' => $daten];
    }

    /** @return array{ok:bool, error:string, status:int, daten:array<string, mixed>} */
    private static function abfuhr(string $grund, int $status): array
    {
        return ['ok' => false, 'error' => $grund, 'status' => $status, 'daten' => []];
    }

    /** Wohin die Anfragen gehen. */
    private static function adresse(array $projekt, string $datei = self::DATEI): string
    {
        $domain = trim((string) ($projekt['domain'] ?? ''));

        if ($domain === '') {
            return '';
        }

        if (!str_starts_with($domain, 'http://') && !str_starts_with($domain, 'https://')) {
            $domain = 'https://' . $domain;
        }

        return rtrim($domain, '/') . '/' . $datei;
    }

    /**
     * Welcher Weg steht offen?
     *
     * Zuerst die dauerhafte Leseschnittstelle - liegt sie, ist nichts
     * weiter zu tun, und genau dafuer ist sie da. Erst wenn sie sich
     * nicht meldet, wird der Empfaenger gefragt, der von Hand
     * hingelegt wird.
     *
     * Gefragt wird mit kurzer Zeitgrenze: Zwei Proben nacheinander
     * duerfen zusammen nicht laenger dauern als vorher eine.
     *
     * @return array{ok:bool, adresse:string, schluessel:string, art:string, error:string}
     */
    public static function wegFinden(array $projekt): array
    {
        $id = (int) $projekt['id'];

        if (self::adresse($projekt) === '') {
            return self::keinWeg('Diese Website hat keine Adresse.');
        }

        $wege = [
            ['art' => 'dauerhaft', 'datei' => self::DAUERDATEI, 'schluessel' => self::leseschluessel($id)],
            ['art' => 'empfang', 'datei' => self::DATEI, 'schluessel' => self::schluessel($id)],
        ];

        $ersterGrund = '';

        foreach ($wege as $weg) {
            $adresse = self::adresse($projekt, $weg['datei']);
            $antwort = self::anfrage($adresse, $weg['schluessel'], 'hallo', [], self::TIMEOUT_PROBE);

            if ($antwort['ok']) {
                return [
                    'ok' => true,
                    'adresse' => $adresse,
                    'schluessel' => $weg['schluessel'],
                    'art' => $weg['art'],
                    'error' => '',
                ];
            }

            if ($ersterGrund === '') {
                $ersterGrund = $antwort['error'];
            }
        }

        return self::keinWeg($ersterGrund !== '' ? $ersterGrund : 'Keine Schnittstelle antwortet.');
    }

    /** @return array{ok:bool, adresse:string, schluessel:string, art:string, error:string} */
    private static function keinWeg(string $grund): array
    {
        return ['ok' => false, 'adresse' => '', 'schluessel' => '', 'art' => '', 'error' => $grund];
    }

    /** @return array{ok:bool, files:int, error:string, retryable:bool} */
    private static function fehler(string $text, bool $nochmal): array
    {
        return ['ok' => false, 'files' => 0, 'error' => $text, 'retryable' => $nochmal];
    }

    /** @return array{ok:bool, files:int, bytes:int, error:string, abgeschnitten:bool, tmp:array<int,string>} */
    private static function baumFehler(string $text): array
    {
        return [
            'ok' => false, 'files' => 0, 'bytes' => 0,
            'error' => $text, 'abgeschnitten' => false, 'tmp' => [],
        ];
    }
}
