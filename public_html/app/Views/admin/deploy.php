<?php
/**
 * Veröffentlichen: Stand hereinholen, Paket herausgeben.
 *
 * Das Paket entsteht immer – auch dann, wenn kein FTP-Zugang hinterlegt
 * ist. Es ist der verlässliche Weg, eine fertige Website in die Hand zu
 * bekommen.
 */

use WebAtze\Core\{Config, Csrf};

/** @var array $project @var array|null $target @var array $builds */
/** @var array|null $job @var array $brief */

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
$latest = $builds[0] ?? null;
?>

<p class="wa-intro">
    Du holst, WebAtze arbeitet, du bringst. Die Website mit deinem FTP-Programm
    herunterladen und hier als ZIP hochladen; bearbeiten und ansehen; das Paket
    herunterladen und selbst wieder hinaufladen. Die Zugangsdaten dafür stehen
    ganz unten &ndash; zum Nachschlagen.
</p>

<?php if ($job !== null): ?>
    <section class="wa-panel">
        <div class="wa-panel__head"><h2 class="wa-panel__title">Läuft gerade</h2></div>
        <div class="wa-job" data-job-watch="<?= (int) $job['id'] ?>">
            <div class="wa-job__row">
                <strong><?= (string) $job['type'] === 'zip-uebernehmen' ? 'Der Stand wird übernommen' : 'Auftrag läuft' ?></strong>
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
 * Der Stand herein, das Paket hinaus.
 *
 * Von hier aus geht nichts mehr auf den Kundenserver. Drei Wege dorthin
 * waren durchgemessen und alle drei tot: FTP mit verworfener
 * Datenverbindung, eine Empfangsdatei über HTTPS, eine dauerhafte
 * Leseschnittstelle. Von einem Hosting zum anderen kommt nichts durch -
 * vom eigenen Rechner aus schon.
 *
 * Also die ehrliche Reihenfolge: Du holst, WebAtze arbeitet, du bringst.
 */
$uebernommen = (bool) ($uebernommen ?? false);
$offen = (bool) ($offen ?? false);
$neustes = $builds[0] ?? null;
?>
<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Stand vom Kunden hereinholen</h2>
        <p class="wa-panel__hint">
            Mit deinem FTP-Programm die Website herunterladen, hier als ZIP hochladen.
            Sie wird ausgepackt; ist es eine von WebAtze gebaute Website, stehen ihre
            Texte und Bilder danach im Editor &ndash; auch die, die der Kunde selbst
            geändert hat.
        </p>
    </div>

    <div class="wa-note">
        <div>
            <?php if ($uebernommen): ?>
                <span class="wa-badge wa-badge--ok">Stand liegt hier</span>
                Ausgepackt und bereit. Ein neues Archiv ersetzt ihn.
            <?php else: ?>
                <span class="wa-badge">noch kein Stand</span>
                Ohne hochgeladenes Archiv arbeitet der Editor mit dem, was hier gebaut wurde.
            <?php endif; ?>
        </div>
    </div>

    <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/uebernehmen"
          enctype="multipart/form-data" class="wa-form">
        <?= Csrf::field() ?>

        <label class="wa-label" for="archiv">Archiv der Website (ZIP)</label>
        <input class="wa-input" type="file" id="archiv" name="archiv"
               accept=".zip,application/zip">
        <span class="wa-label__hint">
            Liegt alles in einem Ordner, wird der weggeschnitten. Höchstens
            <?= (int) (\WebAtze\Http\DeployController::MAX_ARCHIV_BYTES / 1024 / 1024) ?>&nbsp;MB.
        </span>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn wa-btn--primary"
                    data-confirm="Den Stand aus diesem Archiv übernehmen? Der bisherige Inhalt dieser Website wird ersetzt - eine Fassung bleibt erhalten.">
                Übernehmen
            </button>
            <a class="wa-btn" href="<?= e($base) ?>/editor/<?= $id ?>">Editor öffnen</a>
        </div>
    </form>
</section>

<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Website herausgeben</h2>
        <p class="wa-panel__hint">
            Ein Paket schnüren, herunterladen, mit deinem FTP-Programm beim Kunden
            hinaufladen. Das Paket enthält den gebauten Stand &ndash; und alles aus dem
            übernommenen Archiv, was der Bau nicht selbst erzeugt.
        </p>
    </div>

    <div class="wa-note">
        <div>
            <?php if ($offen): ?>
                <span class="wa-badge wa-badge--warn">noch nicht heruntergeladen</span>
                Seit dem letzten Herunterladen wurde hier etwas geändert. Beim Kunden
                liegt es noch nicht.
            <?php elseif ((string) ($project['downloaded_at'] ?? '') !== ''): ?>
                <span class="wa-badge wa-badge--ok">draussen</span>
                Zuletzt heruntergeladen am
                <?= e(date('d.m.Y H:i', strtotime((string) $project['downloaded_at']))) ?>.
            <?php else: ?>
                <span class="wa-badge">noch nie heruntergeladen</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="wa-form__actions">
        <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/zip">
            <?= Csrf::field() ?>
            <button type="submit" class="wa-btn">Neues Paket erstellen</button>
        </form>
        <?php if ($neustes !== null): ?>
            <a class="wa-btn wa-btn--primary"
               href="<?= e($base) ?>/projekt/<?= $id ?>/zip/<?= (int) $neustes['id'] ?>">
                Website herunterladen
            </a>
        <?php endif; ?>
        <?php if (\WebAtze\Domain\Websites::hatVorschau($project)): ?>
            <a class="wa-btn wa-btn--quiet" target="_blank" rel="noopener"
               href="<?= e(\WebAtze\Build\Pipeline::previewUrl($project)) ?>">Vorschau ansehen</a>
        <?php endif; ?>
    </div>
</section>

<?php /* -------------------------------------------------------------- Pakete */ ?>
<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Paket</h2>
        <div class="wa-panel__actions">
            <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/zip">
                <?= Csrf::field() ?>
                <button type="submit" class="wa-btn wa-btn--sm">Neues Paket erstellen</button>
            </form>
        </div>
    </div>

    <?php
    /**
     * Eine hinzugefügte Website hat kein gebautes Paket – sie wurde ja
     * nicht hier gebaut. Ihr zu sagen „muss zuerst gebaut werden" wäre
     * ein Rat, den man nicht befolgen kann. Für sie ist FTP der Weg zum
     * Herunterladen, nicht zum Hochladen, und genau das steht dann da.
     */
    $handgemacht = (string) ($project['source'] ?? '') === 'hand';
    ?>
    <?php if ($builds === [] && $handgemacht): ?>
        <div class="wa-empty-state">
            <p>
                Diese Website wurde nicht hier gebaut, also gibt es kein Paket zum
                Hochladen. Die Zugangsdaten unten sind trotzdem sinnvoll: Damit
                lässt sich der <strong>aktuelle Stand vom Server holen</strong> &ndash;
                als ZIP, mit allem, was inzwischen dort liegt.
            </p>
            <a class="wa-btn" href="<?= e($base) ?>/websites/<?= $id ?>">Zurück zur Website</a>
        </div>
    <?php elseif ($builds === []): ?>
        <div class="wa-empty-state">
            <p>Noch kein Paket vorhanden. Die Website muss zuerst gebaut werden.</p>
            <a class="wa-btn" href="<?= e($base) ?>/projekt/<?= $id ?>">Zurück zum Projekt</a>
        </div>
    <?php else: ?>
        <p class="wa-panel__hint">
            Das Paket wird in <code>public_html</code> des Kunden entpackt und läuft sofort –
            ohne Installation und ohne Kommandozeile. Ältere Versionen bleiben erhalten.
            <br>
            Ein <strong>Live-Stand</strong> ist etwas anderes: nicht das hier Gebaute, sondern
            das, was in dem Moment tatsächlich auf dem Server lag – samt hochgeladener Bilder,
            eingegangener Anfragen und im Backend geänderter Texte. Davon bleiben die letzten
            <?= (int) \WebAtze\Build\ZipExporter::LIVE_BEHALTEN ?> liegen.
        </p>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                    <tr><th>Version</th><th>Dateien</th><th>Grösse</th><th>Erstellt</th><th>Notiz</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($builds as $build): ?>
                    <tr>
                        <td>
                            <?php /* Version 0 heisst: nicht gebaut, sondern vom
                                     Server geholt. Eine eigene Zaehlung waere
                                     eine zweite Reihenfolge neben der gebauten,
                                     und dann bedeutete "v3" zweierlei. */ ?>
                            <?php if ((int) $build['version'] === 0): ?>
                                <span class="wa-badge">Live-Stand</span>
                            <?php else: ?>
                                v<?= (int) $build['version'] ?>
                                <?php if ($latest !== null && (int) $build['id'] === (int) $latest['id']): ?>
                                    <span class="wa-badge wa-badge--done">aktuell</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $build['files_count'] ?></td>
                        <td><?= e(format_bytes((int) $build['zip_bytes'])) ?></td>
                        <td><?= e(date('d.m.Y H:i', strtotime((string) $build['created_at']))) ?></td>
                        <td><?= e((string) $build['notes']) ?></td>
                        <td class="wa-table__right">
                            <a class="wa-btn wa-btn--quiet wa-btn--sm"
                               href="<?= e($base) ?>/projekt/<?= $id ?>/zip/<?= (int) $build['id'] ?>">
                                Herunterladen
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
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
