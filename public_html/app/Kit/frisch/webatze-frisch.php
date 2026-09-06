<?php

declare(strict_types=1);

/**
 * Frisch machen.
 *
 * Diese Datei beantwortet eine Frage, die von aussen nicht zu
 * beantworten ist: **Warum sieht der Besucher nicht, was auf dem Server
 * liegt?** Sie läuft auf dem Server der Website selbst, schaut sich um
 * und räumt weg, was sie erreicht.
 *
 * Warum sie hier liegt und nicht ferngesteuert wird: Von einem Hosting
 * zum anderen kommt keine Verbindung durch - FTP, eine Empfangsdatei
 * über HTTPS und eine dauerhafte Leseschnittstelle waren alle drei
 * durchgemessen und tot. Vom eigenen Rechner aus geht es. Also ruft der
 * Mensch sie im Browser auf.
 *
 * Sie ändert **nichts** an der Website. Sie liest Einstellungen und
 * leert Zwischenspeicher, mehr nicht.
 *
 * Absicherung - in dieser Reihenfolge geprüft:
 *
 *   1. Alter. Nach der Frist unten tut sie nichts mehr und löscht sich
 *      selbst. Eine vergessene Datei, die etwas kann, ist eine offene
 *      Tür.
 *   2. Geheimnis. Ohne den richtigen Schlüssel in der Adresse antwortet
 *      sie mit 404 - wie eine Datei, die es nicht gibt. Wer sie zufällig
 *      findet, erfährt nicht einmal, dass es sie gibt.
 *   3. Vergleich in konstanter Zeit (`hash_equals`), damit sich der
 *      Schlüssel nicht Zeichen für Zeichen erraten lässt.
 *
 * Was sie bewusst NICHT zeigt: Pfade, Datenbankzugänge, Inhalte von
 * Konfigurationsdateien, die Liste der Dateien. Sie nennt Software und
 * Einstellungen - das, was zur Frage gehört, und nichts darüber hinaus.
 */

/** Der Schlüssel. Wird beim Packen eingesetzt. */
const SCHLUESSEL = '%%SCHLUESSEL%%';

/** Wie lange sie überhaupt etwas tut. Danach löscht sie sich. */
const HOECHSTALTER = 7 * 86400;

// ------------------------------------------------------------------
// Torwache
// ------------------------------------------------------------------

$alter = time() - (int) (@filemtime(__FILE__) ?: time());

if ($alter > HOECHSTALTER) {
    @unlink(__FILE__);
    fort404();
}

$gegeben = (string) ($_GET['s'] ?? $_POST['s'] ?? '');

if (SCHLUESSEL === '' || strlen($gegeben) < 16 || !hash_equals(SCHLUESSEL, $gegeben)) {
    fort404();
}

$leeren = isset($_POST['leeren']);
$getan = [];

if ($leeren) {
    $getan = aufraeumen();
}

$befund = umschauen();

// ------------------------------------------------------------------
// Was hier los ist
// ------------------------------------------------------------------

/**
 * Die Lage aufnehmen.
 *
 * Jede Zeile hier beantwortet: "Könnte das der Grund sein, dass eine
 * Änderung nicht ankommt?" Was diese Frage nicht berührt, steht nicht
 * da - eine lange Liste hätte man gelesen und trotzdem nichts gewusst.
 */
function umschauen(): array
{
    $befund = [];

    // Zuerst die Frage, die alle anderen erübrigt.
    //
    // Die häufigste Ursache für "es ändert sich nichts" ist gar kein
    // Zwischenspeicher, sondern: Das Archiv wurde in den falschen
    // Ordner entpackt. Dann ist jedes Leeren verlorene Zeit. Der
    // Kurzfinger unten ist der Beweis - er steht auch im Backend neben
    // dem Herunterladen. Stimmen die beiden überein, ist die richtige
    // Datei angekommen und das Problem sitzt dahinter.
    $wurzel = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    $hier = realpath(__DIR__);
    $amPlatz = $wurzel !== '' && $hier !== false && $hier === realpath($wurzel);

    $befund[] = [
        'was' => 'Liegt hier die Website?',
        'wert' => $amPlatz ? 'ja, im Hauptordner' : 'nicht im Hauptordner',
        'urteil' => $amPlatz ? 'gut' : 'warnung',
        'sagt' => $amPlatz
            ? 'Was hier liegt, wird auch ausgeliefert.'
            : 'Diese Datei liegt woanders als die ausgelieferte Website. Wenn du '
                . 'das Archiv hierher entpackt hast, ist es am falschen Ort - und '
                . 'dann ändert sich nichts, ganz ohne Zwischenspeicher.',
    ];

    foreach (['index.php', 'index.html', 'seite.php'] as $name) {
        $voll = __DIR__ . '/' . $name;

        if (!is_file($voll)) {
            continue;
        }

        $befund[] = [
            'was' => $name,
            'wert' => sprintf(
                '%s · geändert %s · Kurzfinger %s',
                groesse((int) filesize($voll)),
                date('d.m.Y H:i', (int) filemtime($voll)),
                substr((string) hash_file('sha256', $voll), 0, 12)
            ),
            'urteil' => 'gut',
            'sagt' => 'Vergleiche den Kurzfinger mit dem, den WebAtze beim '
                . 'Herunterladen genannt hat. Sind sie verschieden, ist diese '
                . 'Datei nie angekommen.',
        ];
    }

    // Der billigste Test auf einen Seitenvorrat im Server.
    //
    // Diese Zahl entsteht bei jedem Aufruf neu. Bleibt sie beim
    // Neuladen stehen, hat die Anfrage PHP gar nicht erreicht - dann
    // liegt ein Zwischenspeicher davor, und zwar auf dem Server, nicht
    // im Browser.
    $befund[] = [
        'was' => 'Kommt die Anfrage bis zu PHP?',
        'wert' => date('H:i:s') . ' / ' . random_int(100000, 999999),
        'urteil' => 'gut',
        'sagt' => 'Lade diese Seite neu. Ändert sich die Zahl nicht, sitzt ein '
            . 'Zwischenspeicher vor PHP - dann hilft nur "Jetzt leeren" unten '
            . 'oder das Abschalten im Hosting-Konto.',
    ];

    $server = (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unbekannt');
    $litespeed = stripos($server, 'litespeed') !== false;

    $befund[] = [
        'was' => 'Server',
        'wert' => $server,
        'urteil' => $litespeed ? 'warnung' : 'gut',
        'sagt' => $litespeed
            ? 'LiteSpeed hält fertige Seiten oft selbst vor. Der Block in der '
                . '.htaccess schaltet das ab; unten wird zusätzlich geleert.'
            : 'Kein eigener Seitenvorrat im Server zu erwarten.',
    ];

    // Der Hauptverdächtige bei "auch nach Browser-Cache-Leeren nicht".
    //
    // OPcache hält den übersetzten PHP-Code. Prüft er die Änderungszeit
    // nicht - oder nur alle paar Minuten -, bleibt eine frisch
    // hochgeladene index.php für ALLE Besucher unsichtbar, und kein
    // Leeren im Browser hilft dagegen.
    if (function_exists('opcache_get_status')) {
        $pruefen = (bool) ini_get('opcache.validate_timestamps');
        $takt = (int) ini_get('opcache.revalidate_freq');
        $an = (bool) ini_get('opcache.enable');

        if (!$an) {
            $befund[] = [
                'was' => 'PHP-Zwischenspeicher',
                'wert' => 'aus',
                'urteil' => 'gut',
                'sagt' => 'Jede Anfrage liest die Datei neu.',
            ];
        } elseif (!$pruefen) {
            $befund[] = [
                'was' => 'PHP-Zwischenspeicher',
                'wert' => 'an, prüft die Änderungszeit NICHT',
                'urteil' => 'schlecht',
                'sagt' => 'Das ist mit hoher Wahrscheinlichkeit dein Grund: Eine '
                    . 'hochgeladene Datei wird gar nicht erst gelesen. Unten leeren.',
            ];
        } elseif ($takt > 10) {
            $befund[] = [
                'was' => 'PHP-Zwischenspeicher',
                'wert' => sprintf('an, prüft alle %d Sekunden', $takt),
                'urteil' => 'warnung',
                'sagt' => sprintf('Eine Änderung braucht bis zu %d Sekunden, bis sie '
                    . 'wirkt. Unten leeren beendet die Wartezeit sofort.', $takt),
            ];
        } else {
            $befund[] = [
                'was' => 'PHP-Zwischenspeicher',
                'wert' => sprintf('an, prüft alle %d Sekunden', $takt),
                'urteil' => 'gut',
                'sagt' => 'Änderungen werden zeitnah bemerkt.',
            ];
        }
    } else {
        $befund[] = [
            'was' => 'PHP-Zwischenspeicher',
            'wert' => 'nicht vorhanden',
            'urteil' => 'gut',
            'sagt' => 'Kein übersetzter Code, der veralten könnte.',
        ];
    }

    // Was der Server bei der eigenen Startseite wirklich mitschickt.
    // Das ist die Probe aufs Exempel: Steht dort nichts, rät der Browser
    // eine Haltbarkeit - und genau daran hing der Fehler.
    $kopf = eigeneStartseite();

    if ($kopf === null) {
        $befund[] = [
            'was' => 'Regel für Seiten',
            'wert' => 'nicht prüfbar',
            'urteil' => 'warnung',
            'sagt' => 'Die eigene Startseite liess sich von hier aus nicht abrufen. '
                . 'Prüfe im Browser mit F12 unter "Netzwerk", ob bei der Seite '
                . '"Cache-Control: no-cache" steht.',
        ];
    } elseif (stripos($kopf, 'no-cache') !== false || stripos($kopf, 'no-store') !== false) {
        $befund[] = [
            'was' => 'Regel für Seiten',
            'wert' => trim($kopf),
            'urteil' => 'gut',
            'sagt' => 'Der Browser fragt bei jeder Seite nach. So soll es sein.',
        ];
    } else {
        $befund[] = [
            'was' => 'Regel für Seiten',
            'wert' => $kopf === '' ? 'gar keine Angabe' : trim($kopf),
            'urteil' => 'schlecht',
            'sagt' => 'Ohne Angabe rät der Browser eine Haltbarkeit und zeigt eine '
                . 'geänderte Seite tagelang nicht. Liegt die .htaccess aus dem '
                . 'ZIP wirklich im selben Ordner?',
        ];
    }

    // Ein Zwischenspeicher-Zusatz im Verzeichnis - der leert sich nicht
    // von selbst, wenn Dateien per FTP hochgeladen werden.
    foreach (['wp-content/cache' => 'WordPress-Zwischenspeicher',
              'cache' => 'Ordner "cache"',
              'var/cache' => 'Ordner "var/cache"'] as $ordner => $name) {
        if (is_dir(__DIR__ . '/' . $ordner)) {
            $befund[] = [
                'was' => $name,
                'wert' => 'vorhanden',
                'urteil' => 'warnung',
                'sagt' => 'Hier liegen vorgefertigte Seiten. Beim Leeren unten werden '
                    . 'sie mit entfernt.',
            ];
        }
    }

    return $befund;
}

/**
 * Wegräumen, was von hier aus erreichbar ist.
 *
 * Absichtlich wenig: Zwischenspeicher leeren, nichts löschen, was zur
 * Website gehört. Was hier nicht steht, kann diese Datei auch nicht
 * kaputtmachen.
 */
function aufraeumen(): array
{
    $getan = [];

    if (function_exists('opcache_reset') && @opcache_reset()) {
        $getan[] = 'Der übersetzte PHP-Code wurde verworfen. Die nächste Anfrage liest '
            . 'die Dateien neu ein.';
    }

    clearstatcache(true);
    $getan[] = 'Die gemerkten Dateiangaben wurden verworfen.';

    // LiteSpeed hört auf diesen Kopf und wirft seinen Vorrat weg.
    if (stripos((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''), 'litespeed') !== false) {
        header('X-LiteSpeed-Purge: *');
        $getan[] = 'Dem Server wurde gesagt, dass er seinen Seitenvorrat wegwerfen soll.';
    }

    foreach (['wp-content/cache', 'cache', 'var/cache'] as $ordner) {
        $pfad = __DIR__ . '/' . $ordner;

        if (is_dir($pfad) && leeren($pfad)) {
            $getan[] = sprintf('Der Ordner %s wurde geleert.', $ordner);
        }
    }

    return $getan;
}

/**
 * Einen Zwischenspeicherordner leeren.
 *
 * Der Ordner selbst bleibt stehen - ihn zu löschen brächte manche
 * Anwendung zum Straucheln, und leer tut er niemandem weh. Symlinks
 * werden entfernt, ihnen aber nicht gefolgt: Ein Verweis, der aus dem
 * Ordner hinauszeigt, darf nicht dazu führen, dass anderswo etwas
 * verschwindet.
 */
function leeren(string $ordner): bool
{
    $echt = realpath($ordner);
    $heim = realpath(__DIR__);

    if ($echt === false || $heim === false || !str_starts_with($echt, $heim . DIRECTORY_SEPARATOR)) {
        return false;
    }

    $eintraege = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($echt, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    $weg = 0;

    foreach ($eintraege as $eintrag) {
        /** @var SplFileInfo $eintrag */
        if ($eintrag->isLink() || $eintrag->isFile()) {
            $weg += (int) @unlink($eintrag->getPathname());
        } elseif ($eintrag->isDir()) {
            @rmdir($eintrag->getPathname());
        }
    }

    return $weg > 0;
}

/** Die eigene Startseite abrufen und ihren Cache-Control-Kopf lesen. */
function eigeneStartseite(): ?string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

    if ($host === '' || !preg_match('~^[a-z0-9.\-]+(:\d+)?$~i', $host)) {
        return null;
    }

    $schema = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        ? 'https' : 'http';

    $sicht = @stream_context_create([
        'http' => ['method' => 'HEAD', 'timeout' => 5, 'ignore_errors' => true],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);

    $kopf = @get_headers($schema . '://' . $host . '/', true, $sicht);

    if ($kopf === false) {
        return null;
    }

    foreach ($kopf as $name => $wert) {
        if (strcasecmp((string) $name, 'Cache-Control') === 0) {
            return is_array($wert) ? (string) end($wert) : (string) $wert;
        }
    }

    return '';
}

function fort404(): never
{
    header('HTTP/1.1 404 Not Found');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><title>404</title><h1>Not Found</h1>';
    exit;
}

function h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function groesse(int $bytes): string
{
    return $bytes < 1024
        ? $bytes . ' B'
        : ($bytes < 1048576
            ? round($bytes / 1024, 1) . ' KB'
            : round($bytes / 1048576, 1) . ' MB');
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Frisch machen</title>
<style>
  :root { color-scheme: light dark; }
  body { margin: 0; padding: 2rem 1rem; font: 16px/1.6 system-ui, sans-serif;
         background: #f6f7fb; color: #16172a; }
  main { max-inline-size: 44rem; margin-inline: auto; }
  h1 { font-size: 1.5rem; margin-block: 0 .25rem; }
  .leise { color: #5a5c72; margin-block-start: 0; }
  .zeile { background: #fff; border: 1px solid #dcdfe8; border-radius: 10px;
           padding: 1rem 1.1rem; margin-block-end: .75rem; }
  .zeile b { display: block; }
  .wert { font-family: ui-monospace, monospace; font-size: .92rem; word-break: break-word; }
  .sagt { color: #43455c; font-size: .95rem; margin-block-start: .35rem; }
  .gut { border-inline-start: 4px solid #12805c; }
  .warnung { border-inline-start: 4px solid #9a6700; }
  .schlecht { border-inline-start: 4px solid #b42318; }
  .marke { font-size: .78rem; font-weight: 700; letter-spacing: .04em;
           text-transform: uppercase; }
  .gut .marke { color: #12805c; } .warnung .marke { color: #9a6700; }
  .schlecht .marke { color: #b42318; }
  button { font: inherit; font-weight: 600; padding: .7rem 1.3rem; border: 0;
           border-radius: 8px; background: #2f3ab2; color: #fff; cursor: pointer; }
  .getan { background: #e7f6f0; border: 1px solid #12805c; border-radius: 10px;
           padding: 1rem 1.1rem; margin-block-end: 1.25rem; }
  .getan ul { margin: .5rem 0 0; padding-inline-start: 1.2rem; }
  footer { margin-block-start: 2rem; font-size: .88rem; color: #5a5c72; }
  @media (prefers-color-scheme: dark) {
    body { background: #12131c; color: #e8eaf2; }
    .zeile { background: #1b1c28; border-color: #33354a; }
    .sagt { color: #b9bcd0; } .leise, footer { color: #9a9cb8; }
    .getan { background: #10261f; }
  }
</style>
</head>
<body>
<main>
  <h1>Frisch machen</h1>
  <p class="leise">Was hier steht, gilt für diese Website auf diesem Server.</p>

  <?php if ($getan !== []): ?>
    <div class="getan">
      <strong>Erledigt.</strong>
      <ul><?php foreach ($getan as $eins): ?><li><?= h($eins) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php foreach ($befund as $zeile): ?>
    <div class="zeile <?= h($zeile['urteil']) ?>">
      <span class="marke"><?= h(match ($zeile['urteil']) {
          'gut' => 'in Ordnung', 'warnung' => 'beachten', default => 'hier klemmt es',
      }) ?></span>
      <b><?= h($zeile['was']) ?></b>
      <span class="wert"><?= h($zeile['wert']) ?></span>
      <p class="sagt"><?= h($zeile['sagt']) ?></p>
    </div>
  <?php endforeach; ?>

  <form method="post" action="?s=<?= h($gegeben) ?>">
    <input type="hidden" name="s" value="<?= h($gegeben) ?>">
    <button type="submit" name="leeren" value="1">Jetzt leeren</button>
  </form>

  <footer>
    <p>
      Diese Datei darf weg, sobald du sie nicht mehr brauchst &ndash; sie löscht sich
      sonst nach einer Woche von selbst.
    </p>
    <p>
      <strong>Was auch das nicht kann:</strong> Zwischenspeicher auf den Geräten
      anderer Leute leeren. Das kann niemand. Die Regel in der
      <code>.htaccess</code> sorgt aber dafür, dass ab jetzt keiner mehr entsteht:
      Der Browser fragt bei jeder Seite nach, statt zu raten.
    </p>
  </footer>
</main>
</body>
</html>
