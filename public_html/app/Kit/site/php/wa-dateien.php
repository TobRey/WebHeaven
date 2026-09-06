<?php

declare(strict_types=1);

/**
 * Die Leseschnittstelle.
 *
 * Über diese Datei holt WebAtze den aktuellen Stand dieser Website -
 * hochgeladene Bilder, eingegangene Anfragen, im Backend geänderte
 * Texte. Über HTTPS auf Port 443, also über den einen Ausgang, der auf
 * jedem Hosting offen ist.
 *
 * Warum es sie gibt: FTP braucht zwei Verbindungen, eine für die
 * Befehle und für jede Datei eine zweite auf einem hohen Port. Genau
 * die zweite wird von einem Webhosting zum anderen oft verworfen. Dann
 * hilft kein Einstellen mehr - gemessen an einem Server, dessen
 * PASV-Adresse von aussen erreichbar war und vom anderen Hosting aus in
 * eine Zeitüberschreitung lief.
 *
 * Sie liest, und sie schreibt nicht. Das ist der Unterschied zum
 * Empfänger, der beim Hochladen kurz hier liegt und sich danach selbst
 * löscht: Eine dauerhaft erreichbare *Schreib*stelle auf einer fremden
 * Website ist kein Zustand, den man hinterlässt. Eine Lesestelle ist
 * etwas anderes - im schlimmsten Fall gibt sie das heraus, was die
 * Website ohnehin ausliefert.
 *
 * Damit das auch stimmt, bleiben die Geheimnisse aussen vor:
 * data/config.php und alles, was so heisst, wird nie herausgegeben.
 * Dort steht der Schlüssel der Brücke, und der gehört niemandem sonst.
 *
 * Wer sie hier findet und nicht mehr will: Sie darf weg. Ein Löschen
 * genügt, die Website läuft ohne sie unverändert weiter.
 *
 * Abgesichert wie die Brücke - in dieser Reihenfolge geprüft:
 *
 *   1. Unterschrift. HMAC-SHA256 über Methode, Pfad, Zeitstempel,
 *      Einmalwert und den vollständigen Rumpf. Der Schlüssel steht hier
 *      und geht nie über die Leitung.
 *   2. Zeitfenster von zwei Minuten und ein Einmalwert, der vermerkt
 *      wird. Eine mitgeschnittene Anfrage ist entweder zu alt oder
 *      schon dagewesen.
 *   3. Pfad. Jeder Pfad muss unterhalb dieses Ordners bleiben - geprüft
 *      an den einzelnen Namen und danach am aufgelösten Pfad gegen
 *      Symlinks.
 */

const SCHLUESSEL = '%%SCHLUESSEL%%';
const FENSTER = 120;
const MAX_BYTES = 20 * 1024 * 1024;

/** Wie viele Dateien eine Auflistung höchstens nennt. */
const MAX_EINTRAEGE = 5000;

/** Und wie tief sie dabei steigt. */
const MAX_TIEFE = 12;

/** Der Merker für die schon gesehenen Einmalwerte. */
const MERKER = '.webatze-gelesen';

/**
 * Was nie herausgegeben wird.
 *
 * Nicht der Pfad, sondern der blosse Name - eine config.php ist in
 * jedem Ordner dasselbe Problem. Und sich selbst gibt sie auch nicht
 * heraus: Darin steht ihr Schlüssel.
 */
const VERSCHWIEGEN = [
    'config.php',
    '.env',
    '.htpasswd',
    MERKER,
    // Das Werkzeug gehoert nicht ins Werkstueck: Empfaenger und Merker
    // gehoeren zur Uebertragung, nicht zur Website. Und in beiden steht
    // ein Schluessel - der hat in einem Archiv nichts verloren, das
    // heruntergeladen und weitergereicht wird.
    'webatze-empfang.php',
    '.webatze-einmal',
];

/**
 * Antwort und Schluss.
 *
 * @param array<string, mixed> $mehr
 */
function raus(bool $ok, string $text = '', int $status = 200, array $mehr = []): void
{
    http_response_code($ok ? $status : ($status === 200 ? 400 : $status));
    header('Content-Type: application/json');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');

    echo json_encode(['ok' => $ok, 'error' => $text] + $mehr);
    exit;
}

/** Gehört dieser Name zu den Geheimnissen? */
function verschwiegen(string $name): bool
{
    return in_array(strtolower($name), array_map('strtolower', VERSCHWIEGEN), true);
}

/**
 * Aus einem gewünschten Pfad einen echten machen - oder abbrechen.
 *
 * Dieselbe Prüfung wie beim Empfänger, nur ohne den Schreibzweig: erst
 * an den einzelnen Namen, dann am aufgelösten Pfad. Ein ".." mitten in
 * einem Namen ist harmlos; eines als eigener Schritt führt hinaus, und
 * ein Symlink verrät sich im Text überhaupt nicht.
 */
function lesePfad(string $ziel): string
{
    if ($ziel === '' || strlen($ziel) > 255 || str_contains($ziel, "\0")) {
        raus(false, 'Ungültiger Pfad.');
    }

    if ($ziel[0] === '/' || $ziel[0] === '\\' || preg_match('/^[A-Za-z]:/', $ziel) === 1) {
        raus(false, 'Nur relative Pfade.');
    }

    foreach (explode('/', str_replace('\\', '/', $ziel)) as $schritt) {
        if ($schritt === '..' || $schritt === '.' || $schritt === '') {
            raus(false, 'Der Pfad führt aus dem Ordner heraus.');
        }
    }

    if (verschwiegen(basename($ziel))) {
        raus(false, 'Diese Datei wird nicht herausgegeben.', 403);
    }

    $voll = __DIR__ . '/' . $ziel;
    $hier = realpath(__DIR__);
    $echt = realpath($voll);

    if ($hier === false || $echt === false || !is_file($echt) || is_link($voll)) {
        raus(false, 'Diese Datei gibt es hier nicht.', 404);
    }

    if ($echt === __FILE__) {
        raus(false, 'Diese Datei wird nicht herausgegeben.', 403);
    }

    if (!str_starts_with($echt, $hier . '/')) {
        raus(false, 'Das Ziel liegt ausserhalb.');
    }

    return $echt;
}

/**
 * Den eigenen Ordner ablaufen.
 *
 * Ohne Symlinks: Einer, der nach draussen zeigt, brächte den halben
 * Server ins Archiv. Und mit Deckel, weil eine Auflistung, die nicht
 * fertig wird, so nutzlos ist wie gar keine.
 *
 * @param array<int, array{pfad:string, bytes:int}> $treffer
 */
function sammeln(string $ordner, string $vorsatz, int $tiefe, array &$treffer, int &$zurueck = 0): void
{
    if ($tiefe > MAX_TIEFE || count($treffer) >= MAX_EINTRAEGE) {
        return;
    }

    $namen = @scandir($ordner);

    if ($namen === false) {
        return;
    }

    sort($namen);

    foreach ($namen as $name) {
        if ($name === '.' || $name === '..' || count($treffer) >= MAX_EINTRAEGE) {
            continue;
        }

        $voll = $ordner . '/' . $name;
        $relativ = $vorsatz === '' ? $name : $vorsatz . '/' . $name;

        if (is_link($voll) || $voll === __FILE__ || verschwiegen($name)) {
            // Gezaehlt statt verschwiegen: Ein Archiv, dem etwas fehlt,
            // soll das sagen. Was fehlt, steht in VERSCHWIEGEN und ist
            // kein Zufall - aber "vollstaendig" waere gelogen.
            if (!is_link($voll) && $voll !== __FILE__) {
                $zurueck++;
            }

            continue;
        }

        if (is_dir($voll)) {
            sammeln($voll, $relativ, $tiefe + 1, $treffer, $zurueck);

            continue;
        }

        if (!is_file($voll)) {
            continue;
        }

        $treffer[] = ['pfad' => $relativ, 'bytes' => (int) @filesize($voll)];
    }
}

// --------------------------------------------------------------- Anfrage
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    raus(false, 'Nur POST.', 405);
}

$rumpf = (string) file_get_contents('php://input');

if (strlen($rumpf) > 65536) {
    raus(false, 'Zu gross.', 413);
}

// --------------------------------------------------------- Unterschrift
$zeit = (int) ($_SERVER['HTTP_X_WEBATZE_ZEIT'] ?? 0);
$einmal = (string) ($_SERVER['HTTP_X_WEBATZE_EINMAL'] ?? '');
$gesendet = (string) ($_SERVER['HTTP_X_WEBATZE_UNTERSCHRIFT'] ?? '');
$pfad = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

if (SCHLUESSEL === '' || str_contains(SCHLUESSEL, '%%')) {
    // Ohne eingesetzten Schluessel tut sie gar nichts. Eine Datei, die
    // jeden hereinliesse, weil beim Ausliefern etwas schiefging, waere
    // schlimmer als keine.
    raus(false, 'Nicht eingerichtet.', 503);
}

if ($zeit <= 0 || $einmal === '' || $gesendet === '') {
    raus(false, 'Unterschrift fehlt.', 401);
}

if (abs(time() - $zeit) > FENSTER) {
    raus(false, 'Ausserhalb des Zeitfensters.', 401);
}

$material = implode("\n", ['POST', $pfad, (string) $zeit, $einmal, hash('sha256', $rumpf)]);

if (!hash_equals(hash_hmac('sha256', $material, SCHLUESSEL), $gesendet)) {
    raus(false, 'Unterschrift stimmt nicht.', 401);
}

// ----------------------------------------------------------- Einmalwert
$merker = __DIR__ . '/' . MERKER;
$gesehen = @file_get_contents($merker);
$gesehen = is_string($gesehen) ? explode("\n", $gesehen) : [];

if (in_array($einmal, $gesehen, true)) {
    raus(false, 'Schon dagewesen.', 409);
}

$gesehen[] = $einmal;
@file_put_contents($merker, implode("\n", array_slice($gesehen, -500)), LOCK_EX);

// -------------------------------------------------------------- Auftrag
$daten = json_decode($rumpf, true);

if (!is_array($daten)) {
    raus(false, 'Unlesbarer Rumpf.');
}

$aktion = (string) ($daten['aktion'] ?? '');

if ($aktion === 'hallo') {
    raus(true, '', 200, ['art' => 'dauerhaft']);
}

if ($aktion === 'liste') {
    $treffer = [];
    $zurueck = 0;
    sammeln(__DIR__, '', 0, $treffer, $zurueck);

    raus(true, '', 200, [
        'dateien' => $treffer,
        'abgeschnitten' => count($treffer) >= MAX_EINTRAEGE,
        'zurueckgehalten' => $zurueck,
    ]);
}

if ($aktion === 'holen') {
    $voll = lesePfad((string) ($daten['pfad'] ?? ''));

    if ((int) @filesize($voll) > MAX_BYTES) {
        raus(false, 'Diese Datei ist zu gross für den Rückweg.', 413);
    }

    $inhalt = @file_get_contents($voll);

    if ($inhalt === false) {
        raus(false, 'Die Datei liess sich nicht lesen.');
    }

    raus(true, '', 200, ['inhalt' => base64_encode($inhalt)]);
}

// Schreiben gibt es hier nicht - und zwar mit Absicht. Dafuer wird der
// Empfaenger kurz hingelegt und loescht sich danach wieder.
raus(false, 'Diese Schnittstelle liest nur.', 405);
