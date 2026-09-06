<?php

declare(strict_types=1);

namespace WebAtze\Build;

use WebAtze\Core\{Crypto, Db};

/**
 * Die Zugangsdaten zum Server des Kunden.
 *
 * Diese Klasse verbindet sich mit nichts. Sie hiess einmal
 * `FtpDeployer` und lud Websites hinauf - bis gemessen war, dass genau
 * das von einem Hosting zum anderen nicht geht: Die Anmeldung auf Port
 * 21 gelingt, die zweite Verbindung, die jede Datei braucht, wird
 * verworfen. Vom Rechner zuhause klappt dieselbe Adresse, vom Server
 * aus nie.
 *
 * Also uebertraegt der Mensch, mit seinem eigenen FTP-Programm, und
 * WebAtze ist die Werkstatt dazwischen: ZIP herein, bearbeiten, ZIP
 * heraus. Was hier bleibt, sind die Zugangsdaten selbst - verschluesselt
 * abgelegt, damit sie nachschlagbar sind, wenn FileZilla danach fragt.
 *
 * Ein Klassenname, der etwas verspricht, was die Klasse nicht mehr kann,
 * ist die naechste Fehlersuche wert. Deshalb heisst sie jetzt anders.
 */
final class Zugang
{
    /** Zugangsdaten speichern – das Passwort verschlüsselt. */
    public static function saveTarget(int $projectId, array $data): int
    {
        $existing = Db::first(
            'SELECT id, secret FROM deploy_targets WHERE project_id = :p ORDER BY id DESC LIMIT 1',
            ['p' => $projectId]
        );

        $password = (string) ($data['password'] ?? '');

        // Leeres Feld heisst "unverändert lassen", nicht "löschen".
        $secret = $password !== ''
            ? Crypto::encrypt($password)
            : (string) ($existing['secret'] ?? '');

        // Was hier ankommt, ist kopiert - und oft ist mehr mitgekommen
        // als der Servername. Einmal zurechtruecken, bevor es in die
        // Datenbank geht: Sonst steht der Fehler dauerhaft darin.
        $sauber = self::normalizeHost(
            (string) ($data['host'] ?? ''),
            (int) ($data['port'] ?? 21)
        );

        $values = [
            'protocol' => in_array($data['protocol'] ?? '', ['ftp', 'ftps', 'sftp'], true) ? $data['protocol'] : 'ftp',
            'host' => $sauber['host'],
            'port' => $sauber['port'],
            'username' => mb_substr(trim((string) ($data['username'] ?? '')), 0, 190),
            'secret' => $secret,
            'remote_path' => self::cleanPath((string) ($data['path'] ?? '/')),
            // Null und nicht 0: Die Spalte ist eine Verknuepfung, und
            // "keine" heisst dort NULL. Eine 0 zeigte auf ein Konto,
            // das es nicht gibt.
            'hosting_account_id' => ((int) ($data['hosting_account_id'] ?? 0)) ?: null,
            'updated_at' => Db::now(),
        ];

        if ($existing !== null) {
            Db::update('deploy_targets', $values, 'id = :id', ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        return Db::insert('deploy_targets', array_merge($values, [
            'project_id' => $projectId,
            'created_at' => Db::now(),
        ]));
    }

    /**
     * Die Zugangsdaten einer Website - mit dem Hosting-Zugang aufgeloest.
     *
     * Alle Websites liegen auf demselben Konto: Server, Benutzername
     * und Passwort sind jedes Mal dieselben, nur das Verzeichnis
     * unterscheidet sich. Steht ein Hosting-Zugang daran, kommen die
     * drei von dort und nur `remote_path` von der Website.
     */
    public static function targetFor(int $projectId): ?array
    {
        $target = Db::first(
            'SELECT * FROM deploy_targets WHERE project_id = :p ORDER BY id DESC LIMIT 1',
            ['p' => $projectId]
        );

        return $target === null ? null : self::withAccount($target);
    }

    /**
     * Die Zugangsdaten eines gemeinsamen Kontos einsetzen.
     *
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    public static function withAccount(array $target): array
    {
        $kontoId = (int) ($target['hosting_account_id'] ?? 0);

        if ($kontoId <= 0) {
            return $target;
        }

        $konto = Db::first('SELECT * FROM hosting_accounts WHERE id = :id', ['id' => $kontoId]);

        if ($konto === null) {
            // Das Konto ist weg, das Ziel zeigt ins Leere. Lieber mit
            // dem, was am Ziel selbst steht, weitermachen als gar
            // nichts.
            return $target;
        }

        foreach (['protocol', 'host', 'port', 'username', 'secret'] as $feld) {
            $target[$feld] = $konto[$feld];
        }

        return $target;
    }

    /**
     * Das Passwort im Klartext - zum Nachschlagen fürs FTP-Programm.
     *
     * Der einzige Grund, warum diese Daten überhaupt noch hier liegen:
     * Du brauchst sie, wenn FileZilla danach fragt. Null, wenn sich das
     * Passwort nicht entschlüsseln lässt.
     */
    public static function passwort(array $target): ?string
    {
        return Crypto::decrypt((string) ($target['secret'] ?? ''));
    }

    /**
     * Den Servernamen zurechtruecken.
     *
     * In dieses Feld wird kopiert, was der Anbieter irgendwo anzeigt -
     * und das ist oft mehr als ein Servername: ein "ftp://" davor, ein
     * Pfad dahinter, ein ":21" am Ende. Wortwoertlich uebernommen steht
     * der Fehler dauerhaft in der Datenbank.
     *
     * @return array{host:string, port:int, hinweis:string}
     */
    public static function normalizeHost(string $host, int $port): array
    {
        $roh = trim($host);
        $hinweis = '';

        if (preg_match('#^([a-z][a-z0-9+.\-]*)://#i', $roh, $treffer) === 1) {
            $roh = substr($roh, strlen($treffer[0]));
            $hinweis = 'Das „' . $treffer[1] . '://“ gehoert nicht in dieses Feld.';
        }

        // "benutzer:passwort@server" - der Teil davor ist kein Server.
        $at = strrpos($roh, '@');

        if ($at !== false) {
            $roh = substr($roh, $at + 1);
            $hinweis = 'Der Benutzername gehoert in sein eigenes Feld, nicht vor den Server.';
        }

        $schraeg = strpos($roh, '/');

        if ($schraeg !== false) {
            $roh = substr($roh, 0, $schraeg);
            $hinweis = 'Der Pfad hinter dem Servernamen gehoert ins Feld „Verzeichnis“.';
        }

        // Ein angehaengter Port wandert ins Portfeld. Die Bedingung
        // schuetzt IPv6-Adressen, die selbst voller Doppelpunkte sind.
        if (preg_match('/^([^:]+):(\d{1,5})$/', $roh, $treffer) === 1) {
            $roh = $treffer[1];
            $port = max(1, min(65535, (int) $treffer[2]));
            $hinweis = 'Der Port stand am Servernamen und ist jetzt im Portfeld.';
        }

        $roh = trim($roh, " \t\n\r\0\x0B.");

        return [
            'host' => mb_substr($roh, 0, 190),
            'port' => max(1, min(65535, $port)),
            'hinweis' => $hinweis,
        ];
    }

    private static function cleanPath(string $path): string
    {
        $path = '/' . trim(str_replace('\\', '/', $path), '/');
        $path = preg_replace('#/+#', '/', $path) ?? '/';

        // Kein Ausbrechen aus dem Zielverzeichnis
        $path = str_replace('..', '', $path);

        return rtrim($path, '/') ?: '/';
    }
}
