<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use WebAtze\Core\{Crypto, Db};

/**
 * Ein Hosting-Zugang, den sich mehrere Websites teilen.
 *
 * Alle Websites liegen auf demselben Konto: Server, Benutzername und
 * Passwort sind jedes Mal dieselben, nur das Verzeichnis unterscheidet
 * sich. Bisher wurde das je Website eingetippt und je Website
 * verschlüsselt abgelegt – und beim nächsten Passwortwechsel musste es
 * überall neu eingetragen werden. Bei acht Websites sind das acht
 * Gelegenheiten, eine zu vergessen, und die fällt dann erst beim
 * nächsten Hochladen auf.
 *
 * Der Zugang je Website bleibt trotzdem möglich. Steht kein
 * Hosting-Zugang an einem Ziel, gilt weiterhin, was dort selbst
 * hinterlegt ist – bestehende Websites merken von dieser Änderung
 * nichts.
 */
final class HostingAccount
{
    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return Db::all('SELECT * FROM hosting_accounts ORDER BY name ASC');
    }

    public static function find(int $id): ?array
    {
        return $id > 0
            ? Db::first('SELECT * FROM hosting_accounts WHERE id = :id', ['id' => $id])
            : null;
    }

    /** Wie viele es gibt – für die Frage, ob die Auswahl überhaupt lohnt. */
    public static function count(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM hosting_accounts', [], 0);
    }

    /**
     * Anlegen oder ändern.
     *
     * Ein leeres Passwortfeld heisst „unverändert lassen", nicht
     * „löschen" – dieselbe Regel wie beim Zugang je Website, und aus
     * demselben Grund: Das Passwort wird nie wieder angezeigt, also
     * kann man es beim Ändern eines Namens gar nicht mitschicken.
     *
     * @param array<string, mixed> $daten
     */
    public static function save(array $daten, int $id = 0): int
    {
        $vorhanden = self::find($id);

        $passwort = (string) ($daten['password'] ?? '');

        $secret = $passwort !== ''
            ? Crypto::encrypt($passwort)
            : (string) ($vorhanden['secret'] ?? '');

        // Derselbe Weg, den auch das Ziel je Website nimmt: Was
        // hineinkopiert wurde, wird zuerst zurechtgerückt, damit der
        // Fehler nicht dauerhaft in der Datenbank steht.
        $sauber = \WebAtze\Build\Zugang::normalizeHost(
            (string) ($daten['host'] ?? ''),
            (int) ($daten['port'] ?? 21)
        );

        $werte = [
            'name' => mb_substr(trim((string) ($daten['name'] ?? '')), 0, 120) ?: 'Hosting',
            'provider' => mb_substr(trim((string) ($daten['provider'] ?? 'godaddy')), 0, 40),
            'protocol' => in_array($daten['protocol'] ?? '', ['ftp', 'ftps', 'sftp'], true)
                ? (string) $daten['protocol']
                : 'ftp',
            'host' => $sauber['host'],
            'port' => $sauber['port'],
            'username' => mb_substr(trim((string) ($daten['username'] ?? '')), 0, 191),
            'secret' => $secret,
            'note' => mb_substr(trim((string) ($daten['note'] ?? '')), 0, 500),
            'updated_at' => Db::now(),
        ];

        if ($vorhanden !== null) {
            Db::update('hosting_accounts', $werte, 'id = :id', ['id' => $id]);

            return $id;
        }

        return (int) Db::insert('hosting_accounts', $werte + ['created_at' => Db::now()]);
    }

    /**
     * Einen Zugang entfernen.
     *
     * Die Websites, die ihn benutzt haben, verlieren dabei ihre
     * Zugangsdaten – deshalb wird gesagt, wie viele das sind, bevor
     * jemand darauf drückt.
     */
    public static function remove(int $id): bool
    {
        if (self::find($id) === null) {
            return false;
        }

        Db::update(
            'deploy_targets',
            ['hosting_account_id' => null, 'updated_at' => Db::now()],
            'hosting_account_id = :id',
            ['id' => $id]
        );

        return Db::delete('hosting_accounts', 'id = :id', ['id' => $id]) > 0;
    }

    /** Wie viele Websites diesen Zugang benutzen. */
    public static function usedBy(int $id): int
    {
        return (int) Db::value(
            'SELECT COUNT(*) FROM deploy_targets WHERE hosting_account_id = :id',
            ['id' => $id],
            0
        );
    }
}
