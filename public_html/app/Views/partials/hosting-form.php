<?php

/**
 * Ein Hosting-Zugang: anlegen oder ändern.
 *
 * Ohne Anleitung, dafür mit Beispielen in den Feldern selbst. Eine
 * Anleitung liest man einmal und sucht danach trotzdem, welche Angabe
 * in welches Feld gehört; ein Beispiel im Feld beantwortet genau diese
 * Frage, und zwar dort, wo sie entsteht. Die Beispiele nennen deshalb
 * eine echte Form (benutzer@domain.com) und nicht „Ihr Benutzername".
 *
 * @var array<string, mixed> $konto  leer beim Anlegen
 * @var string $base
 */

use WebAtze\Core\Csrf;

$k = $konto ?? [];
$id = (int) ($k['id'] ?? 0);
$neu = $id === 0;
$protokoll = (string) ($k['protocol'] ?? 'ftp');
?>

<form method="post" action="<?= e($base) ?>/einstellungen" class="wa-form" autocomplete="off">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="hosting">
    <input type="hidden" name="hosting_id" value="<?= $id ?>">

    <div class="wa-field">
        <label class="wa-label" for="h-name-<?= $id ?>">Name</label>
        <input class="wa-input" type="text" id="h-name-<?= $id ?>" name="name"
               placeholder="GoDaddy Hauptkonto"
               value="<?= e((string) ($k['name'] ?? '')) ?>">
    </div>

    <div class="wa-field">
        <label class="wa-label" for="h-protocol-<?= $id ?>">Verbindungsart</label>
        <select class="wa-select" id="h-protocol-<?= $id ?>" name="protocol"
                data-ftp-field="protocol">
            <option value="ftp"<?= $protokoll === 'ftp' ? ' selected' : '' ?>>FTP</option>
            <option value="ftps"<?= $protokoll === 'ftps' ? ' selected' : '' ?>>FTP mit Verschlüsselung</option>
            <option value="sftp"<?= $protokoll === 'sftp' ? ' selected' : '' ?>>SFTP</option>
        </select>
    </div>

    <div class="wa-field">
        <label class="wa-label" for="h-host-<?= $id ?>">Server</label>
        <input class="wa-input" type="text" id="h-host-<?= $id ?>" name="host"
               placeholder="domain.com" data-ftp-field="host"
               value="<?= e((string) ($k['host'] ?? '')) ?>">
    </div>

    <div class="wa-field">
        <label class="wa-label" for="h-port-<?= $id ?>">Port</label>
        <input class="wa-input" type="number" id="h-port-<?= $id ?>" name="port"
               min="1" max="65535" data-ftp-field="port"
               value="<?= (int) ($k['port'] ?? 21) ?>">
    </div>

    <div class="wa-field">
        <label class="wa-label" for="h-user-<?= $id ?>">Benutzername</label>
        <input class="wa-input" type="text" id="h-user-<?= $id ?>" name="username"
               placeholder="benutzer@domain.com" autocomplete="off"
               value="<?= e((string) ($k['username'] ?? '')) ?>">
    </div>

    <div class="wa-field">
        <label class="wa-label" for="h-pass-<?= $id ?>">Passwort</label>
        <input class="wa-input" type="password" id="h-pass-<?= $id ?>" name="password"
               autocomplete="new-password"
               placeholder="<?= $neu ? '' : 'unverändert lassen' ?>">
    </div>

    <div class="wa-field wa-field--breit">
        <label class="wa-label" for="h-note-<?= $id ?>">Notiz</label>
        <input class="wa-input" type="text" id="h-note-<?= $id ?>" name="note"
               maxlength="500" placeholder="z. B. welches Paket, wann verlängert"
               value="<?= e((string) ($k['note'] ?? '')) ?>">
    </div>

    <div class="wa-form__actions">
        <button type="submit" class="wa-btn wa-btn--primary">
            <?= $neu ? 'Zugang anlegen' : 'Speichern' ?>
        </button>
    </div>
</form>
