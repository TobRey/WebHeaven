<?php

declare(strict_types=1);

/**
 * WebAtze-Empfänger.
 *
 * Diese Datei nimmt eine Website entgegen, wenn FTP nicht durchkommt.
 * Sie wird einmal von Hand hierher gelegt, tut eine Sache, und löscht
 * sich danach selbst wieder.
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
 *   4. Pfad. Jeder Zielpfad muss unterhalb dieses Ordners bleiben -
 *      geprüft an den einzelnen Namen, nicht am ganzen Text.
 */

const SCHLUESSEL = '%%SCHLUESSEL%%';
const FENSTER = 120;
const HOECHSTALTER = 86400;
const MAX_BYTES = 20 * 1024 * 1024;

/** Antwort und Schluss. */
function raus(bool $ok, string $text = '', int $status = 200): void
{
    http_response_code($ok ? $status : ($status === 200 ? 400 : $status));
    header('Content-Type: application/json');
    header('X-Robots-Tag: noindex, nofollow');

    echo json_encode(['ok' => $ok, 'error' => $text]);
    exit;
}

/** Sich selbst entfernen. */
function verschwinden(): void
{
    @unlink(__FILE__);
    @unlink(__DIR__ . '/.webatze-einmal');
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
$merker = __DIR__ . '/.webatze-einmal';
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

if ($aktion !== 'schreiben') {
    raus(false, 'Unbekannter Auftrag.');
}

$ziel = (string) ($daten['pfad'] ?? '');
$inhalt = base64_decode((string) ($daten['inhalt'] ?? ''), true);

if ($inhalt === false) {
    raus(false, 'Der Inhalt ist nicht lesbar.');
}

// ---------------------------------------------------------------- Pfad
//
// Geprüft wird an den einzelnen Namen und nicht am ganzen Text: Ein
// "..", das mitten in einem Namen steht, ist harmlos; eines als
// eigener Schritt führt aus dem Ordner heraus.
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

$voll = __DIR__ . '/' . $ziel;
$ordner = dirname($voll);

if (!is_dir($ordner) && !@mkdir($ordner, 0755, true) && !is_dir($ordner)) {
    raus(false, 'Der Ordner liess sich nicht anlegen.');
}

// Und zum Schluss die Gegenprobe am echten Pfad: Symlinks und alles
// andere, was der Text nicht verrät.
$echt = realpath($ordner);
$hier = realpath(__DIR__);

if ($echt === false || $hier === false || !str_starts_with($echt . '/', $hier . '/')) {
    raus(false, 'Das Ziel liegt ausserhalb.');
}

if (@file_put_contents($voll, $inhalt, LOCK_EX) === false) {
    raus(false, 'Die Datei liess sich nicht schreiben.');
}

raus(true);
