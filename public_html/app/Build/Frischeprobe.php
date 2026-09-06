<?php

declare(strict_types=1);

namespace WebAtze\Build;

use WebAtze\Core\Config;

/**
 * Die Nachschau, die mit dem Archiv mitfährt.
 *
 * Sie beantwortet eine Frage, die von hier aus nicht zu beantworten
 * ist: Warum zeigt die Website beim Kunden noch die alte Fassung?
 * Zwischen WebAtze und dem Kundenserver kommt keine Verbindung durch -
 * FTP, eine Empfangsdatei über HTTPS und eine Leseschnittstelle waren
 * alle drei durchgemessen und tot. Also fährt die Frage als Datei mit,
 * und der Mensch ruft sie dort im Browser auf.
 *
 * Der Schlüssel wird **abgeleitet, nicht gespeichert**: aus dem
 * Anwendungsschlüssel und der Nummer der Website. Damit ist er für
 * jede Website ein anderer, verlässt diesen Server nie im Klartext und
 * braucht keine eigene Spalte, die man pflegen und irgendwann
 * vergessen müsste. Gesperrt wird über die Frist in der Datei selbst -
 * sie löscht sich nach einer Woche.
 */
final class Frischeprobe
{
    /** Wie sie beim Kunden heisst. */
    public const DATEI = 'webatze-frisch.php';

    /**
     * Der Schlüssel für diese Website.
     *
     * Abgeleitet und nicht gewürfelt: So ist er nach einem Neustart,
     * einem zweiten Herunterladen und auf einem umgezogenen Server
     * derselbe - und die Adresse, die im Backend steht, stimmt auch
     * morgen noch.
     */
    public static function schluessel(int $projektId): string
    {
        $basis = (string) Config::get('app_key', '');

        if ($basis === '') {
            return '';
        }

        return substr(hash_hmac('sha256', 'frisch:' . $projektId, $basis), 0, 32);
    }

    /** Die Adresse, unter der sie aufgerufen wird. */
    public static function adresse(array $projekt): string
    {
        $domain = trim((string) ($projekt['domain'] ?? ''));
        $schluessel = self::schluessel((int) ($projekt['id'] ?? 0));

        if ($schluessel === '') {
            return '';
        }

        if ($domain === '') {
            return self::DATEI . '?s=' . $schluessel;
        }

        if (!str_starts_with($domain, 'http://') && !str_starts_with($domain, 'https://')) {
            $domain = 'https://' . $domain;
        }

        return rtrim($domain, '/') . '/' . self::DATEI . '?s=' . $schluessel;
    }

    /**
     * Die fertige Datei – oder '' , wenn sie sich nicht bauen lässt.
     *
     * Bleibt der Platzhalter stehen, wäre die Datei beim Kunden ohne
     * Schutz. Deshalb gibt es dann lieber gar keine.
     */
    public static function datei(array $projekt): string
    {
        $vorlage = @file_get_contents(APP_DIR . '/Kit/frisch/' . self::DATEI);
        $schluessel = self::schluessel((int) ($projekt['id'] ?? 0));

        if ($vorlage === false || $schluessel === '') {
            return '';
        }

        $fertig = str_replace('%%SCHLUESSEL%%', $schluessel, (string) $vorlage);

        return str_contains($fertig, '%%') ? '' : $fertig;
    }
}
