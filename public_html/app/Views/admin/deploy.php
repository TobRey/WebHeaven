<?php
/**
 * Veröffentlichen: das ZIP herein, das ZIP hinaus.
 *
 * Das Fenster in der Mitte ist dasselbe wie beim Kunden - dieselbe
 * Datei, damit es nicht zwei Fassungen gibt, die auseinanderlaufen.
 * Darunter die Zugangsdaten: zum Nachschlagen für FileZilla, nicht zum
 * Verbinden. Von hier aus geht nichts mehr auf den Kundenserver.
 */

use WebAtze\Core\{Config, Csrf};

/** @var array $project @var array|null $target */
/** @var array|null $job @var array $brief @var bool $offen */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
$id = (int) $project['id'];

/**
 * Die Vorgaben sind die des Anbieters, bei dem alles liegt.
 *
 * FTP auf Port 21 und das Verzeichnis "/" - das ist bei einem
 * cPanel-Unterkonto der Normalfall, und ein Formular, das schon
 * richtig ausgefüllt ist, muss nichts erklären.
 */
$protocol = (string) ($target['protocol'] ?? 'ftp');
$port = (int) ($target['port'] ?? ($protocol === 'sftp' ? 22 : 21));
?>

<p class="wa-intro">
    Du holst, WebAtze arbeitet, du bringst. Die Website mit deinem FTP-Programm
    herunterladen und hier als ZIP hochladen; bearbeiten; herunterladen und selbst
    wieder hinaufladen. Die Zugangsdaten dafür stehen darunter &ndash; zum
    Nachschlagen.
</p>

<?php if ($job !== null): ?>
    <section class="wa-panel">
        <div class="wa-panel__head"><h2 class="wa-panel__title">Läuft gerade</h2></div>
        <div class="wa-job" data-job-watch="<?= (int) $job['id'] ?>">
            <div class="wa-job__row">
                <strong><?= (string) $job['type'] === 'zip-uebernehmen' ? 'Das Archiv wird ausgepackt' : 'Auftrag läuft' ?></strong>
                <span class="wa-job__value" data-job-label><?= (int) $job['progress'] ?>%</span>
            </div>
            <div class="wa-progress">
                <div class="wa-progress__bar" data-job-bar style="--value: <?= (int) $job['progress'] ?>%"></div>
            </div>
            <div class="wa-job__row">
                <span class="wa-job__step" data-job-step><?= e((string) $job['message']) ?></span>
                <span class="wa-job__puls" data-job-puls data-state="lebt">arbeitet …</span>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php
/**
 * Dasselbe Fenster wie beim Kunden - buchstäblich dieselbe Datei.
 *
 * Hier standen vorher drei Abschnitte nebeneinander: Stand hereinholen,
 * Website herausgeben, Pakete. Drei Orte für einen Handgriff, und die
 * Knöpfe darin zeigten auf Unterschiedliches. Genau daraus wurde der
 * Fehler, dass "Herunterladen" die unbearbeitete Fassung herausgab.
 */
?>
<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Website bearbeiten</h2>
    </div>

    <?= View_partial('partials/website-einwurf', ['website' => $project, 'base' => $base]) ?>

    <?php if ($offen ?? false): ?>
        <p class="wa-note">
            <span class="wa-badge wa-badge--warn">noch nicht heruntergeladen</span>
            Seit dem letzten Herunterladen wurde hier etwas geändert. Beim Kunden
            liegt es noch nicht.
        </p>
    <?php endif; ?>

    <?php if (\WebAtze\Domain\Websites::hatVorschau($project)): ?>
        <div class="wa-form__actions">
            <a class="wa-btn wa-btn--quiet" target="_blank" rel="noopener"
               href="<?= e(\WebAtze\Build\Pipeline::previewUrl($project)) ?>">Vorschau ansehen</a>
        </div>
    <?php endif; ?>
</section>

<?php /* ------------------------------------------------------------ Zugangsdaten */ ?>
<section class="wa-panel" id="zugang">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Zugang zum Server des Kunden</h2>
        <p class="wa-panel__hint">
            Das Passwort wird verschlüsselt abgelegt und nie wieder angezeigt.
        </p>
    </div>

    <form class="wa-form" method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/ftp" autocomplete="off">
        <?= Csrf::field() ?>

        <?php
        /**
         * Der gemeinsame Zugang zuerst.
         *
         * Alle Websites liegen auf demselben Konto - Server,
         * Benutzername und Passwort sind jedes Mal dieselben. Wer einen
         * Zugang hinterlegt hat, waehlt ihn hier aus und traegt darunter
         * nur noch das Verzeichnis ein.
         */
        $konten = (array) ($hostingAccounts ?? []);
        $gewaehlt = (int) ($target['hosting_account_id'] ?? 0);
        ?>
        <?php if ($konten !== []): ?>
            <div class="wa-field">
                <label class="wa-label" for="hosting_account_id">Hosting-Zugang</label>
                <?php
                /* Diese Liste traegt bewusst nicht die Kennzeichnung der
                   Anbieterliste aus dem Fragebogen. Die belegt Ueber-
                   tragungsart, Port und Verzeichnis vor; hier stehen aber
                   hinterlegte Zugaenge, die nichts dergleichen mitbringen
                   - und die Felder darunter wurden dadurch bei jedem
                   Seitenaufruf ueberschrieben. */
                ?>
                <select class="wa-select" id="hosting_account_id" name="hosting_account_id">
                    <option value="0">– eigene Angaben unten –</option>
                    <?php foreach ($konten as $h): ?>
                        <option value="<?= (int) $h['id'] ?>"<?= $gewaehlt === (int) $h['id'] ? ' selected' : '' ?>>
                            <?= e((string) $h['name']) ?>
                            (<?= e((string) $h['username']) ?> auf <?= e((string) $h['host']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <p class="wa-hint">
                <a href="<?= e($base) ?>/einstellungen#hosting">Hosting-Zugang anlegen</a>
                &ndash; einmal eintragen statt bei jeder Website neu.
            </p>
        <?php endif; ?>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="protocol">Verbindungsart</label>
                <select class="wa-select" id="protocol" name="protocol" data-ftp-field="protocol">
                    <option value="ftp" <?= $protocol === 'ftp' ? 'selected' : '' ?>>FTP</option>
                    <option value="ftps" <?= $protocol === 'ftps' ? 'selected' : '' ?>>FTP mit Verschlüsselung</option>
                    <option value="sftp" <?= $protocol === 'sftp' ? 'selected' : '' ?>>SFTP</option>
                </select>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="port">Port</label>
                <input class="wa-input" type="number" id="port" name="port" min="1" max="65535" data-ftp-field="port"
                       value="<?= $port ?>">
            </div>
        </div>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="host">Server</label>
                <input class="wa-input" type="text" id="host" name="host" placeholder="domain.com"
                       data-ftp-field="host"
                       value="<?= e((string) ($target['host'] ?? '')) ?>">

            </div>

            <div class="wa-field">
                <label class="wa-label" for="username">Benutzername</label>
                <input class="wa-input" type="text" id="username" name="username" autocomplete="off"
                       placeholder="benutzer@domain.com"
                       value="<?= e((string) ($target['username'] ?? '')) ?>">
            </div>
        </div>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="password">Passwort</label>
                <input class="wa-input" type="password" id="password" name="password"
                       autocomplete="new-password"
                       placeholder="<?= $target !== null ? 'unverändert lassen' : '' ?>">
            </div>

            <div class="wa-field">
                <label class="wa-label" for="path">Verzeichnis</label>
                <?php
                /* Das "/" steht schon drin, weil es fuer ein
                   cPanel-Unterkonto stimmt: So eines sitzt bereits in
                   seinem Ordner. Wer das Hauptkonto benutzt, traegt den
                   vollen Pfad ein - denselben, den auch FileZilla
                   braucht. */
                ?>
                <input class="wa-input" type="text" id="path" name="path" data-ftp-field="path"
                       placeholder="/"
                       value="<?= e((string) ($target['remote_path'] ?? '/')) ?>">

            </div>
        </div>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn wa-btn--primary">Zugangsdaten speichern</button>
        </div>
    </form>

    <?php
    /**
     * Warum diese Felder noch da sind.
     *
     * Nicht mehr zum Verbinden - von hier aus geht nichts mehr auf den
     * Kundenserver. Sondern zum Nachschlagen: Wenn FileZilla nach
     * Server, Benutzer und Verzeichnis fragt, stehen sie hier, und das
     * Passwort liegt verschlüsselt daneben statt auf einem Zettel.
     */
    ?>
</section>
