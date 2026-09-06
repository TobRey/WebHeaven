<?php

declare(strict_types=1);

/**
 * WebAtze-Empfänger.
 *
 * Diese Datei nimmt eine Website entgegen und gibt sie wieder heraus,
 * wenn FTP nicht durchkommt. Sie wird einmal von Hand hierher gelegt und
 * löscht sich danach selbst wieder.
 *
 * Wer sie hier findet und nicht kennt: Sie darf weg. Sie gehört zu
 * einer Veröffentlichung, die entweder gelaufen oder abgebrochen ist.
 *
 * Absicherung - in dieser Reihenfolge geprüft:
 *
 *   1. Alter. Nach 24 Stunden löscht sie sich selbst, ohne noch etwas
 *      anzunehmen. Eine vergessene Schreibstelle ist eine offene Tür.
 *   2. Unterschrift. HMAC-SHA256 über Methode, Pfad, Zeitstempel,
 *      Einmalwert und den vollständigen Rumpf. Der Schlüssel steht hier
 *      und geht nie über die Leitung.
 *   3. Zeitfenster von zwei Minuten und ein Einmalwert, der vermerkt
 *      wird. Eine mitgeschnittene Anfrage ist entweder zu alt oder
 *      schon dagewesen.
 *   4. Pfad. Jeder Pfad muss unterhalb dieses Ordners bleiben - geprüft
 *      an den einzelnen Namen, nicht am ganzen Text.
 *
 * Die Pfadprüfung steht in *einer* Funktion, und Schreiben wie Lesen
 * gehen beide hindurch. Ein Lesezweig mit eigener, schwächerer Prüfung
 * ist genau die Sorte Fehler, die man erst bemerkt, wenn sie ausgenutzt
 * wurde.
 */

const SCHLUESSEL = '%%SCHLUESSEL%%';
const FENSTER = 120;
const HOECHSTALTER = 86400;
const MAX_BYTES = 20 * 1024 * 1024;

/** Wie viele Dateien eine Auflistung höchstens nennt. */
const MAX_EINTRAEGE = 5000;

/** Wie tief sie dabei steigt. */
const MAX_TIEFE = 12;

/** Der Merker für die schon gesehenen Einmalwerte. */
const MERKER = '.webatze-einmal';

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

    echo json_encode(['ok' => $ok, 'error' => $text] + $mehr);
    exit;
}

/** Sich selbst entfernen. */
function verschwinden(): void
{
    @unlink(__FILE__);
    @unlink(__DIR__ . '/' . MERKER);
}

/**
 * Aus einem gewünschten Pfad einen echten machen - oder abbrechen.
 *
 * Der Unterschied zwischen den beiden Richtungen sitzt am Ende: Beim
 * Schreiben gibt es die Datei noch nicht, also kann nur der Ordner
 * gegengeprüft werden; beim Lesen gibt es sie, also wird sie selbst
 * geprüft. Alles davor ist für beide dasselbe.
 */
function zielPfad(string $ziel, bool $zumLesen): string
{
    if ($ziel === '' || strlen($ziel) > 255 || str_contains($ziel, "\0")) {
        raus(false, 'Ungültiger Pfad.');
    }

    if ($ziel[0] === '/' || $ziel[0] === '\\' || preg_match('/^[A-Za-z]:/', $ziel) === 1) {
        raus(false, 'Nur relative Pfade.');
    }

    // Geprüft wird an den einzelnen Namen und nicht am ganzen Text: Ein
    // ".." das mitten in einem Namen steht, ist harmlos; eines als
    // eigener Schritt führt aus dem Ordner heraus.
    foreach (explode('/', str_replace('\\', '/', $ziel)) as $schritt) {
        if ($schritt === '..' || $schritt === '.' || $schritt === '') {
            raus(false, 'Der Pfad führt aus dem Ordner heraus.');
        }
    }

    $voll = __DIR__ . '/' . $ziel;
    $hier = realpath(__DIR__);

    if ($hier === false) {
        raus(false, 'Der eigene Ordner ist nicht auffindbar.');
    }

    if ($zumLesen) {
        // Gegenprobe am echten Pfad: Ein Symlink verrät sich im Text
        // nicht, im aufgelösten Pfad schon.
        $echt = realpath($voll);

        if ($echt === false || !is_file($echt) || is_link($voll)) {
            raus(false, 'Diese Datei gibt es hier nicht.', 404);
        }

        if (!str_starts_with($echt, $hier . '/')) {
            raus(false, 'Das Ziel liegt ausserhalb.');
        }

        return $echt;
    }

    $ordner = dirname($voll);

    if (!is_dir($ordner) && !@mkdir($ordner, 0755, true) && !is_dir($ordner)) {
        raus(false, 'Der Ordner liess sich nicht anlegen.');
    }

    $echt = realpath($ordner);

    if ($echt === false || !str_starts_with($echt . '/', $hier . '/')) {
        raus(false, 'Das Ziel liegt ausserhalb.');
    }

    return $voll;
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
function sammeln(string $ordner, string $vorsatz, int $tiefe, array &$treffer): void
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

        if (is_link($voll)) {
            continue;
        }

        // Sich selbst und den eigenen Merker nicht: Beide gehören zu
        // dieser Übertragung und nicht zur Website.
        if ($voll === __FILE__ || $name === MERKER) {
            continue;
        }

        if (is_dir($voll)) {
            sammeln($voll, $relativ, $tiefe + 1, $treffer);

            continue;
        }

        if (!is_file($voll)) {
            continue;
        }

        $treffer[] = ['pfad' => $relativ, 'bytes' => (int) @filesize($voll)];
    }
}

// ---------------------------------------------------------------- Alter
if (@filemtime(__FILE__) < time() - HOECHSTALTER) {
    verschwinden();
    raus(false, 'Abgelaufen.', 410);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    raus(false, 'Nur POST.', 405);
}

$rumpf = (string) file_get_contents('php://input');

if (strlen($rumpf) > MAX_BYTES) {
    raus(false, 'Zu gross.', 413);
}

// --------------------------------------------------------- Unterschrift
$zeit = (int) ($_SERVER['HTTP_X_WEBATZE_ZEIT'] ?? 0);
$einmal = (string) ($_SERVER['HTTP_X_WEBATZE_EINMAL'] ?? '');
$gesendet = (string) ($_SERVER['HTTP_X_WEBATZE_UNTERSCHRIFT'] ?? '');
$pfad = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

if ($zeit <= 0 || $einmal === '' || $gesendet === '') {
    raus(false, 'Unterschrift fehlt.', 401);
}

if (abs(time() - $zeit) > FENSTER) {
    raus(false, 'Ausserhalb des Zeitfensters.', 401);
}

$material = implode("\n", ['POST', $pfad, (string) $zeit, $einmal, hash('sha256', $rumpf)]);
$erwartet = hash_hmac('sha256', $material, SCHLUESSEL);

if (!hash_equals($erwartet, $gesendet)) {
    raus(false, 'Unterschrift stimmt nicht.', 401);
}

// ----------------------------------------------------------- Einmalwert
$merker = __DIR__ . '/' . MERKER;
$gesehen = @file_get_contents($merker);
$gesehen = is_string($gesehen) ? explode("\n", $gesehen) : [];

if (in_array($einmal, $gesehen, true)) {
    raus(false, 'Schon dagewesen.', 409);
}

// Nur die letzten paar hundert merken - mehr braucht das Zeitfenster nicht.
$gesehen[] = $einmal;
@file_put_contents($merker, implode("\n", array_slice($gesehen, -500)), LOCK_EX);

// ------------------------------------------------------------- Auftrag
$daten = json_decode($rumpf, true);

if (!is_array($daten)) {
    raus(false, 'Unlesbarer Rumpf.');
}

$aktion = (string) ($daten['aktion'] ?? '');

if ($aktion === 'hallo') {
    raus(true);
}

if ($aktion === 'fertig') {
    verschwinden();
    raus(true);
}

if ($aktion === 'liste') {
    $treffer = [];
    sammeln(__DIR__, '', 0, $treffer);

    raus(true, '', 200, [
        'dateien' => $treffer,
        // Ehrlich sagen, wenn der Deckel erreicht wurde - eine
        // abgeschnittene Liste, die vollständig aussieht, wäre die
        // schlechtere Auskunft.
        'abgeschnitten' => count($treffer) >= MAX_EINTRAEGE,
    ]);
}

if ($aktion === 'holen') {
    $voll = zielPfad((string) ($daten['pfad'] ?? ''), true);
    $groesse = (int) @filesize($voll);

    if ($groesse > MAX_BYTES) {
        raus(false, 'Diese Datei ist zu gross für den Rückweg.', 413);
    }

    $inhalt = @file_get_contents($voll);

    if ($inhalt === false) {
        raus(false, 'Die Datei liess sich nicht lesen.');
    }

    raus(true, '', 200, ['inhalt' => base64_encode($inhalt)]);
}

if ($aktion !== 'schreiben') {
    raus(false, 'Unbekannter Auftrag.');
}

$inhalt = base64_decode((string) ($daten['inhalt'] ?? ''), true);

if ($inhalt === false) {
    raus(false, 'Der Inhalt ist nicht lesbar.');
}

$voll = zielPfad((string) ($daten['pfad'] ?? ''), false);

if (@file_put_contents($voll, $inhalt, LOCK_EX) === false) {
    raus(false, 'Die Datei liess sich nicht schreiben.');
}

raus(true);
