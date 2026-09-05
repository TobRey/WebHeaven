<?php

/**
 * Ein Hosting-Zugang: anlegen oder ändern.
 *
 * Jedes Feld hat ein Beispiel, nach dem sich in cPanel suchen lässt –
 * das ist der Unterschied zwischen „was will es von mir" und „ah, so
 * eines habe ich". Die Beispiele nennen bewusst eine echte Form
 * (web@preview.deine-domain.ch) und nicht „Ihr Benutzername".
 *
 * @var array<string, mixed> $konto  leer beim Anlegen
 * @var string $base
 * @var array<string, mixed> $anbieter
 */

use WebAtze\Core\Csrf;

$k = $konto ?? [];
$id = (int) ($k['id'] ?? 0);
$neu = $id === 0;
$protokoll = (string) ($k['protocol'] ?? 'ftps');
$godaddy = $anbieter['hosting']['godaddy'] ?? [];
?>

<?php if ($neu): ?>
    <?php /* Beim ersten Mal aufgeklappt: Wer noch keinen Zugang hat,
             hat die Anleitung noch nicht gelesen. Wer schon einen hat,
             braucht sie nicht mehr im Weg. */ ?>
    <details class="wa-details" open>
        <summary>So findest du die Angaben bei GoDaddy</summary>
        <ol class="wa-steps">
            <?php foreach ((array) ($godaddy['steps'] ?? []) as $schritt): ?>
                <li><?= $schritt ?></li>
            <?php endforeach; ?>
        </ol>
        <p class="wa-hint"><?= $godaddy['note'] ?? '' ?></p>
    </details>
<?php endif; ?>

<form method="post" action="<?= e($base) ?>/einstellungen" class="wa-form" autocomplete="off">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="hosting">
    <input type="hidden" name="hosting_id" value="<?= $id ?>">

    <div class="wa-field">
        <label class="wa-label" for="h-name-<?= $id ?>">Name</label>
        <input class="wa-input" type="text" id="h-name-<?= $id ?>" name="name"
               placeholder="GoDaddy Hauptkonto"
               value="<?= e((string) ($k['name'] ?? '')) ?>">
        <span class="wa-label__hint">Nur für dich, damit du ihn wiedererkennst.</span>
    </div>

    <div class="wa-field">
        <label class="wa-label" for="h-protocol-<?= $id ?>">Verbindungsart</label>
        <select class="wa-select" id="h-protocol-<?= $id ?>" name="protocol"
                data-ftp-field="protocol">
            <option value="ftp"<?= $protokoll === 'ftp' ? ' selected' : '' ?>>FTP</option>
            <option value="ftps"<?= $protokoll === 'ftps' ? ' selected' : '' ?>>FTP mit Verschlüsselung</option>
            <option value="sftp"<?= $protokoll === 'sftp' ? ' selected' : '' ?>>SFTP</option>
        </select>
        <span class="wa-label__hint">
            Bei GoDaddy <strong>FTP mit Verschlüsselung</strong> auf demselben
            Port 21 &ndash; GoDaddy nennt es &bdquo;explicit FTPS&ldquo; und schreibt
            es selbst in die Zugangsdaten. Reines FTP schickt das Passwort im
            Klartext, SFTP ist dort meist nicht freigeschaltet.
        </span>
    </div>

    <div class="wa-field">
        <label class="wa-label" for="h-host-<?= $id ?>">Server</label>
        <input class="wa-input" type="text" id="h-host-<?= $id ?>" name="host"
               placeholder="deine-domain.ch" data-ftp-field="host"
               value="<?= e((string) ($k['host'] ?? '')) ?>">
        <span class="wa-label__hint">
            GoDaddy zeigt in cPanel <code>ftp.deine-domain.ch</code> an. Das
            funktioniert nur, wenn in der DNS-Zone ein <code>ftp</code>-Eintrag
            steht &ndash; und den legt GoDaddy nicht immer an. Löst er nicht auf,
            nimm dieselbe Domain <strong>ohne <code>ftp.</code></strong> davor,
            etwa <code>web-atze.com</code>. Sonst der Servername aus cPanel rechts
            unter &bdquo;Allgemeine Informationen&ldquo;, etwa
            <code>a2plzcpnl1234.prod.iad2.secureserver.net</code>.
            Der Verbindungstest probiert beides und nennt den, der geht.
        </span>
    </div>

    <div class="wa-field">
        <label class="wa-label" for="h-port-<?= $id ?>">Port</label>
        <input class="wa-input" type="number" id="h-port-<?= $id ?>" name="port"
               min="1" max="65535" data-ftp-field="port"
               value="<?= (int) ($k['port'] ?? 21) ?>">
        <span class="wa-label__hint">Beispiel: <code>21</code> für FTP, <code>22</code> für SFTP.</span>
    </div>

    <div class="wa-field">
        <label class="wa-label" for="h-user-<?= $id ?>">Benutzername</label>
        <input class="wa-input" type="text" id="h-user-<?= $id ?>" name="username"
               placeholder="web@deine-domain.ch" autocomplete="off"
               value="<?= e((string) ($k['username'] ?? '')) ?>">
        <span class="wa-label__hint">
            Beispiel: <code>sarahbernhart@preview2.web-atze.com</code> &ndash; cPanel
            schreibt Unterkonten immer in dieser vollen Form mit <code>@</code>.
            Das Hauptkonto hat keines.
        </span>
    </div>

    <div class="wa-field">
        <label class="wa-label" for="h-pass-<?= $id ?>">Passwort</label>
        <input class="wa-input" type="password" id="h-pass-<?= $id ?>" name="password"
               autocomplete="new-password"
               placeholder="<?= $neu ? '' : 'unverändert lassen' ?>">
        <span class="wa-label__hint">
            Das Passwort des <strong>FTP-Kontos</strong>, nicht das von cPanel.
            Es wird verschlüsselt abgelegt und nie wieder angezeigt.
        </span>
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
