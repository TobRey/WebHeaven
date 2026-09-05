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
 * Hand auf die Kundenwebsite legen, und danach geht alles über 443.
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
    /** Wie die Datei auf der Kundenwebsite heisst. */
    public const DATEI = 'webatze-empfang.php';

    /** Wie lange auf eine Antwort gewartet wird. */
    private const TIMEOUT = 30;

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
     * @param callable|null $onProgress fn(int $fertig, int $gesamt, string $datei)
     * @return array{ok:bool, files:int, error:string, retryable:bool}
     */
    public static function senden(
        array $projekt,
        string $zipPfad,
        ?callable $onProgress = null,
        float $budget = 120.0
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

        // Und wieder abräumen. Eine Schreibstelle, die auf einer
        // Kundenwebsite stehen bleibt, ist kein Zustand, den man
        // hinterlässt - auch wenn sie unterschrieben ist.
        $weg = self::anfrage($adresse, $schluessel, 'fertig', []);

        return [
            'ok' => true,
            'files' => $fertig,
            'error' => '',
            'retryable' => false,
            'aufgeraeumt' => (bool) $weg['ok'],
        ];
    }

    /** Steht der Empfänger schon bereit? */
    public static function erreichbar(array $projekt): array
    {
        $adresse = self::adresse($projekt);

        if ($adresse === '') {
            return ['ok' => false, 'error' => 'Diese Website hat keine Adresse.'];
        }

        $antwort = self::anfrage($adresse, self::schluessel((int) $projekt['id']), 'hallo', []);

        return ['ok' => $antwort['ok'], 'error' => $antwort['error']];
    }

    // ------------------------------------------------------------------
    // Innereien
    // ------------------------------------------------------------------

    /**
     * Eine unterschriebene Anfrage an den Empfänger.
     *
     * @param array<string, mixed> $rumpf
     * @return array{ok:bool, error:string, status:int}
     */
    private static function anfrage(
        string $adresse,
        string $schluessel,
        string $aktion,
        array $rumpf
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
            ], self::TIMEOUT);
        } catch (\Throwable $e) {
            Logger::exception($e);

            return ['ok' => false, 'error' => 'Die Website antwortet nicht.', 'status' => 0];
        }

        $status = (int) ($antwort['status'] ?? 0);
        $daten = json_decode((string) ($antwort['body'] ?? ''), true);

        if ($status !== 200 || !is_array($daten) || ($daten['ok'] ?? false) !== true) {
            $grund = is_array($daten) ? (string) ($daten['error'] ?? '') : '';

            return [
                'ok' => false,
                'error' => $grund !== '' ? $grund : 'Antwort ' . $status . ' vom Empfänger.',
                'status' => $status,
            ];
        }

        return ['ok' => true, 'error' => '', 'status' => $status];
    }

    /** Wohin die Anfragen gehen. */
    private static function adresse(array $projekt): string
    {
        $domain = trim((string) ($projekt['domain'] ?? ''));

        if ($domain === '') {
            return '';
        }

        if (!str_starts_with($domain, 'http://') && !str_starts_with($domain, 'https://')) {
            $domain = 'https://' . $domain;
        }

        return rtrim($domain, '/') . '/' . self::DATEI;
    }

    /** @return array{ok:bool, files:int, error:string, retryable:bool} */
    private static function fehler(string $text, bool $nochmal): array
    {
        return ['ok' => false, 'files' => 0, 'error' => $text, 'retryable' => $nochmal];
    }
}
