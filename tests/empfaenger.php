<?php

/**
 * Den Empfänger wirklich laufen lassen.
 *
 * Bisher wurde er nur gelesen: Steht "hash_equals" darin, steht
 * "realpath" darin. Das findet eine gelöschte Wache – nicht eine, die
 * dasteht und nicht greift. Und seit er auch herausgibt statt nur
 * anzunehmen, ist das der Unterschied, auf den es ankommt.
 *
 * Also hinter einem echten Webserver: `php -S` mit dem Spielplatz als
 * Wurzel. Anders geht es auch nicht ehrlich – in der Kommandozeile ist
 * `php://input` leer, und ein Empfänger, der seinen Rumpf nie zu sehen
 * bekommt, weist alles ab und sähe dabei sicher aus.
 *
 * Gesprochen wird roh über den Socket und nicht über cURL: Die Anfragen
 * hier sollen auch dann ankommen, wenn in der Umgebung ein Proxy
 * eingetragen ist.
 */

declare(strict_types=1);

/**
 * Einen Spielplatz anlegen, den Empfänger hineinlegen und einen
 * Webserver davor stellen.
 *
 * @return array{ordner:string, port:int, prozess:resource}|null
 */
function empfaenger_platz(string $schluessel): ?array
{
    $ordner = sys_get_temp_dir() . '/wa-empfang-' . bin2hex(random_bytes(6));

    mkdir($ordner, 0777, true);

    $vorlage = (string) file_get_contents(
        dirname(__DIR__) . '/public_html/app/Kit/empfang/webatze-empfang.php'
    );

    file_put_contents(
        $ordner . '/webatze-empfang.php',
        str_replace('%%SCHLUESSEL%%', $schluessel, $vorlage)
    );

    $port = empfaenger_freierPort();

    $prozess = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $ordner],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $rohre,
        $ordner
    );

    if (!is_resource($prozess)) {
        empfaenger_weg($ordner);

        return null;
    }

    // Warten, bis er antwortet - nicht blind eine Sekunde schlafen.
    for ($versuch = 0; $versuch < 100; $versuch++) {
        $draht = @stream_socket_client('tcp://127.0.0.1:' . $port, $nr, $grund, 0.2);

        if (is_resource($draht)) {
            fclose($draht);

            return ['ordner' => $ordner, 'port' => $port, 'prozess' => $prozess, 'rohre' => $rohre];
        }

        usleep(50000);
    }

    empfaenger_ende(['ordner' => $ordner, 'prozess' => $prozess, 'rohre' => $rohre]);

    return null;
}

/** Ein Port, den in diesem Moment niemand hat. */
function empfaenger_freierPort(): int
{
    $horcher = stream_socket_server('tcp://127.0.0.1:0', $nr, $grund);
    $name = (string) stream_socket_get_name($horcher, false);
    fclose($horcher);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
}

/**
 * Den Empfänger einmal anfragen.
 *
 * @param array<string, mixed> $rumpf
 * @param array<string, mixed> $abwandlung method, zeit, einmal, unterschrift, schluessel, pfad
 * @return array{status:int, ok:bool, error:string, daten:array<string, mixed>}
 */
function empfaenger_lauf(array $platz, array $rumpf, string $schluessel, array $abwandlung = []): array
{
    $text = (string) json_encode($rumpf, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $methode = (string) ($abwandlung['method'] ?? 'POST');
    $uri = (string) ($abwandlung['pfad'] ?? '/webatze-empfang.php');
    $zeit = (int) ($abwandlung['zeit'] ?? time());
    $einmal = (string) ($abwandlung['einmal'] ?? bin2hex(random_bytes(16)));

    $koepfe = [
        'Host: 127.0.0.1:' . $platz['port'],
        'Content-Type: application/json',
        'Content-Length: ' . strlen($text),
        'Connection: close',
    ];

    // "ohne" heisst wirklich ohne: Ein leerer Kopf waere etwas anderes
    // als ein fehlender, und genau das soll pruefbar bleiben.
    if (($abwandlung['ohneUnterschrift'] ?? false) !== true) {
        $unterschrift = array_key_exists('unterschrift', $abwandlung)
            ? (string) $abwandlung['unterschrift']
            : \WebAtze\Domain\Bridge::sign(
                (string) ($abwandlung['schluessel'] ?? $schluessel),
                'POST',
                $uri,
                $zeit,
                $einmal,
                $text
            );

        $koepfe[] = 'X-WebAtze-Zeit: ' . $zeit;
        $koepfe[] = 'X-WebAtze-Einmal: ' . $einmal;
        $koepfe[] = 'X-WebAtze-Unterschrift: ' . $unterschrift;
    }

    $anfrage = $methode . ' ' . $uri . " HTTP/1.1\r\n"
        . implode("\r\n", $koepfe) . "\r\n\r\n" . $text;

    $draht = @stream_socket_client('tcp://127.0.0.1:' . $platz['port'], $nr, $grund, 5.0);

    if (!is_resource($draht)) {
        return ['status' => 0, 'ok' => false, 'error' => 'Keine Verbindung: ' . $grund, 'daten' => []];
    }

    stream_set_timeout($draht, 5);
    fwrite($draht, $anfrage);

    $antwort = '';

    while (!feof($draht)) {
        $stueck = fread($draht, 8192);

        if ($stueck === false || $stueck === '') {
            break;
        }

        $antwort .= $stueck;
    }

    fclose($draht);

    $trennung = strpos($antwort, "\r\n\r\n");
    $kopf = $trennung === false ? $antwort : substr($antwort, 0, $trennung);
    $leib = $trennung === false ? '' : substr($antwort, $trennung + 4);

    preg_match('#^HTTP/1\.\d (\d+)#', $kopf, $treffer);

    $daten = json_decode(trim($leib), true);

    return [
        'status' => (int) ($treffer[1] ?? 0),
        'ok' => is_array($daten) && ($daten['ok'] ?? false) === true,
        'error' => is_array($daten) ? (string) ($daten['error'] ?? '') : 'Unlesbar: ' . trim($leib),
        'daten' => is_array($daten) ? $daten : [],
    ];
}

/** Den Webserver beenden und den Spielplatz abräumen. */
function empfaenger_ende(array $platz): void
{
    foreach ((array) ($platz['rohre'] ?? []) as $rohr) {
        if (is_resource($rohr)) {
            fclose($rohr);
        }
    }

    if (is_resource($platz['prozess'] ?? null)) {
        proc_terminate($platz['prozess']);
        proc_close($platz['prozess']);
    }

    empfaenger_weg((string) ($platz['ordner'] ?? ''));
}

/** Den Ordner wieder wegräumen. */
function empfaenger_weg(string $ordner): void
{
    if ($ordner === '' || !is_dir($ordner)) {
        return;
    }

    $eintraege = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($ordner, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($eintraege as $eintrag) {
        // Symlinks zuerst: Einer auf einen Ordner ist keiner, und rmdir
        // liefe daran vorbei - oder schlimmer, hindurch.
        if (is_link($eintrag->getPathname()) || $eintrag->isFile()) {
            @unlink($eintrag->getPathname());
        } else {
            @rmdir($eintrag->getPathname());
        }
    }

    @rmdir($ordner);
}
