<?php
/**
 * Veröffentlichen: Paket schnüren, herunterladen, hochladen.
 *
 * Das Paket entsteht immer – auch dann, wenn kein FTP-Zugang hinterlegt
 * ist. Es ist der verlässliche Weg, eine fertige Website in die Hand zu
 * bekommen.
 */

use WebAtze\Core\{Config, Csrf};

/** @var array $project @var array|null $target @var array $builds */
/** @var array|null $job @var array $providers @var array $brief */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
$id = (int) $project['id'];

$protocol = (string) ($target['protocol'] ?? 'sftp');
$port = (int) ($target['port'] ?? ($protocol === 'sftp' ? 22 : 21));
$latest = $builds[0] ?? null;
?>

<p class="wa-intro">
    Jede fertige Website liegt als Paket bereit. Wer die Zugangsdaten des Kunden hinterlegt,
    kann sie zusätzlich direkt auf dessen Server laden – danach wird nachgesehen, ob die
    Seite auch wirklich erreichbar ist.
</p>

<?php if ($job !== null): ?>
    <section class="wa-panel">
        <div class="wa-panel__head"><h2 class="wa-panel__title">Läuft gerade</h2></div>
        <div class="wa-job" data-job-watch="<?= (int) $job['id'] ?>" data-job-reload="1">
            <div class="wa-job__row">
                <strong><?= (string) $job['type'] === 'deploy' ? 'Website wird hochgeladen' : 'Auftrag läuft' ?></strong>
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
 * Die zwei Knöpfe, um die es geht: hinauf und herunter.
 *
 * Sie stehen zuoberst und nebeneinander, weil sie ein Paar sind: Das
 * eine schiebt eine fertige Website auf den Server, das andere holt,
 * was gerade darauf liegt. Beides braucht nur die Zugangsdaten
 * darunter und nichts sonst – insbesondere keinen Bau hier im Haus.
 *
 * Ohne hinterlegte Zugangsdaten bleiben beide trotzdem stehen, nur
 * stumpf. Ein Knopf, der ganz verschwindet, erklärt nichts: Man sucht
 * ihn dann dort, wo er nie war. Steht er da und sagt „mir fehlen die
 * Zugangsdaten", weiss man in derselben Sekunde, was zu tun ist.
 */
$bereit = $target !== null;
?>
<section class="wa-panel wa-transfer">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Website hochladen und herunterladen</h2>
        <?php if (!$bereit): ?>
            <div class="wa-panel__actions">
                <a class="wa-btn wa-btn--primary wa-btn--sm" href="#zugang">Zugangsdaten eintragen</a>
            </div>
        <?php endif; ?>
        <p class="wa-panel__hint">
            <?php if ($bereit): ?>
                Beides geht über die Zugangsdaten weiter unten. Für das Hochladen
                brauchst du kein hier gebautes Paket &ndash; ein fertiges ZIP genügt.
            <?php else: ?>
                Beides braucht die Zugangsdaten zum Server des Kunden. Die stehen
                weiter unten unter &bdquo;Zugang zum Server des Kunden&ldquo; und
                fehlen noch &ndash; danach sind beide Knöpfe hier scharf.
            <?php endif; ?>
        </p>
    </div>

    <div class="wa-grid-2">
        <div class="wa-field">
            <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/archiv"
                  enctype="multipart/form-data" class="wa-form">
                <?= Csrf::field() ?>

                <label class="wa-label" for="archiv">Website hochladen (ZIP)</label>
                <input class="wa-input" type="file" id="archiv" name="archiv"
                       accept=".zip,application/zip"<?= $bereit ? '' : ' disabled' ?>>
                <span class="wa-label__hint">
                    Das Ergebnis aus dem Auftragstext, so wie es kommt. Liegt alles
                    in einem Ordner, wird der weggeschnitten &ndash; die Startseite
                    landet also direkt im Zielverzeichnis. Höchstens
                    <?= (int) (\WebAtze\Http\DeployController::MAX_ARCHIV_BYTES / 1024 / 1024) ?>&nbsp;MB.
                </span>

                <div class="wa-form__actions">
                    <?php if ($bereit): ?>
                        <button type="submit" class="wa-btn wa-btn--primary"
                                data-confirm="Das Archiv jetzt auf den Server des Kunden laden? Bestehende Dateien im Zielverzeichnis werden überschrieben.">
                            Hochladen
                        </button>
                    <?php else: ?>
                        <button type="button" class="wa-btn wa-btn--primary" disabled>Hochladen</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="wa-field">
            <span class="wa-label">Alle Dateien herunterladen</span>
            <span class="wa-label__hint">
                Holt, was in diesem Moment tatsächlich auf dem Server liegt &ndash;
                samt hochgeladener Bilder, eingegangener Anfragen und im Backend
                geänderter Texte. Als ZIP, unter &bdquo;Paket&ldquo; zum Herunterladen.
            </span>

            <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/stand-holen"
                  class="wa-form">
                <?= Csrf::field() ?>
                <div class="wa-form__actions">
                    <?php if ($bereit): ?>
                        <button type="submit" class="wa-btn">Aktuellen Stand holen</button>
                    <?php else: ?>
                        <button type="button" class="wa-btn" disabled>Aktuellen Stand holen</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
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
            Das Passwort wird verschlüsselt abgelegt und nie wieder angezeigt – auch hier nicht.
            Wer es ändern will, gibt einfach ein neues ein.
        </p>
    </div>

    <?php
    /**
     * Nur die Anleitung des Anbieters zeigen, der im Formular angegeben
     * wurde – neun aufgeklappte Kästen nebeneinander wären keine Hilfe,
     * sondern eine Wand. Der Rest liegt darunter, einen Klick entfernt.
     */
    $chosen = (string) ($brief['hosting_provider'] ?? 'other');
    if (!isset($providers['hosting'][$chosen])) {
        $chosen = 'other';
    }

    $helpBlock = static function (array $info): void { ?>
        <div class="wa-help__body">
            <ol>
                <?php foreach ($info['steps'] as $step): ?>
                    <li><?= $step /* fest im Code hinterlegt, kein Benutzertext */ ?></li>
                <?php endforeach; ?>
            </ol>
            <p><?= $info['note'] ?></p>
        </div>
    <?php };
    ?>

    <details class="wa-help" open>
        <summary>Wo finde ich die Zugangsdaten bei <?= e($providers['hosting'][$chosen]['name']) ?>?</summary>
        <?php $helpBlock($providers['hosting'][$chosen]); ?>
    </details>

    <details class="wa-help">
        <summary>Bei einem anderen Anbieter</summary>
        <div class="wa-help__body">
            <?php foreach ($providers['hosting'] as $key => $info): ?>
                <?php if ($key === $chosen) { continue; } ?>
                <details class="wa-help">
                    <summary><?= e($info['name']) ?></summary>
                    <?php $helpBlock($info); ?>
                </details>
            <?php endforeach; ?>
        </div>
    </details>

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
                <select class="wa-select" id="hosting_account_id" name="hosting_account_id"
                        data-hosting-select>
                    <option value="0">– eigene Angaben unten –</option>
                    <?php foreach ($konten as $h): ?>
                        <option value="<?= (int) $h['id'] ?>"<?= $gewaehlt === (int) $h['id'] ? ' selected' : '' ?>>
                            <?= e((string) $h['name']) ?>
                            (<?= e((string) $h['username']) ?> auf <?= e((string) $h['host']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="wa-label__hint">
                    Ausgewählt zählt nur noch das <strong>Verzeichnis</strong> weiter
                    unten. Ändert der Anbieter das Passwort, wird es einmal unter
                    Einstellungen geändert und alle Websites stimmen wieder.
                </span>
            </div>
        <?php else: ?>
            <p class="wa-hint">
                Alle Websites beim selben Anbieter? Dann lohnt ein
                <a href="<?= e($base) ?>/einstellungen#hosting">Hosting-Zugang</a>
                &ndash; einmal eintragen statt bei jeder Website neu.
            </p>
        <?php endif; ?>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="protocol">Verbindungsart</label>
                <select class="wa-select" id="protocol" name="protocol" data-ftp-field="protocol">
                    <option value="sftp" <?= $protocol === 'sftp' ? 'selected' : '' ?>>SFTP (empfohlen)</option>
                    <option value="ftps" <?= $protocol === 'ftps' ? 'selected' : '' ?>>FTP mit Verschlüsselung</option>
                    <option value="ftp" <?= $protocol === 'ftp' ? 'selected' : '' ?>>FTP</option>
                </select>
                <span class="wa-label__hint">
                    <strong>Bei GoDaddy: FTP mit Verschlüsselung</strong>, Port 21.
                    GoDaddy schreibt es selbst in die Zugangsdaten
                    (&bdquo;FTP &amp; explicit FTPS port: 21&ldquo;) &ndash; derselbe
                    Port, nur verschlüsselt. Reines FTP schickt das Passwort im
                    Klartext; SFTP ist dort meist nicht freigeschaltet.
                </span>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="port">Port</label>
                <input class="wa-input" type="number" id="port" name="port" min="1" max="65535" data-ftp-field="port"
                       value="<?= $port ?>">
                <span class="wa-label__hint">
                    Beispiel: <code>21</code> für FTP, <code>22</code> für SFTP.
                </span>
            </div>
        </div>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="host">Server</label>
                <input class="wa-input" type="text" id="host" name="host" placeholder="beispiel.ch"
                       data-ftp-field="host"
                       value="<?= e((string) ($target['host'] ?? '')) ?>">
                <span class="wa-label__hint">
                    Nur der Name, ohne <code>ftp://</code> und ohne Pfad.
                    <br>
                    GoDaddy zeigt in cPanel <code>ftp.deine-domain.ch</code> an. Das
                    funktioniert nur, wenn in der DNS-Zone ein <code>ftp</code>-Eintrag
                    steht &ndash; und den legt GoDaddy nicht immer an. Löst der Name
                    nicht auf, nimm dieselbe Domain <strong>ohne <code>ftp.</code></strong>
                    davor, etwa <code>web-atze.com</code>. Sonst hilft der Servername
                    aus cPanel rechts unter &bdquo;Allgemeine Informationen&ldquo;.
                    Der Verbindungstest probiert beides und sagt, welcher geht.
                </span>

                <?php
                /* Der Name, den der letzte Test als auflösend gefunden hat.
                   Er steht hier zum Anklicken: Ihn noch einmal von Hand
                   richtig zu treffen ist genau die Gelegenheit für den
                   nächsten Tippfehler. */
                $hostVorschlag = (string) ($gefunden['vorschlagHost'] ?? '');
                ?>
                <?php if ($hostVorschlag !== ''): ?>
                    <div class="wa-found">
                        <p class="wa-found__title">Dieser Name löst auf &ndash; zum Übernehmen anklicken:</p>
                        <div class="wa-found__list">
                            <button type="button" class="wa-found__item is-suggested"
                                    data-fill="#host" data-fill-value="<?= e($hostVorschlag) ?>">
                                <?= e($hostVorschlag) ?>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="username">Benutzername</label>
                <input class="wa-input" type="text" id="username" name="username" autocomplete="off"
                       placeholder="web@deine-domain.ch"
                       value="<?= e((string) ($target['username'] ?? '')) ?>">
                <span class="wa-label__hint">
                    Beispiel: <code>sarahbernhart@preview2.web-atze.com</code> &ndash;
                    cPanel schreibt Unterkonten immer in dieser vollen Form mit
                    <code>@</code>. Das Hauptkonto hat keines.
                </span>
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
                <input class="wa-input" type="text" id="path" name="path" data-ftp-field="path"
                       placeholder="/public_html"
                       value="<?= e((string) ($target['remote_path'] ?? '/public_html')) ?>">
                <span class="wa-label__hint">
                    <?php /* Hier stand vorher, ein eigener Zugang je Subdomain sei
                             nicht nötig – also das Gegenteil dessen, was cPanel
                             nahelegt. Wer einen anlegt und dann den vollen Pfad
                             einträgt, bekommt genau eine Meldung: fehlgeschlagen. */ ?>
                    Es kommt darauf an, <em>welcher</em> Zugang:
                </span>
                <ul class="wa-label__hint">
                    <li>
                        Benutzername <strong>mit <code>@</code></strong>
                        (cPanel-Unterkonto, etwa <code>web@preview.deine-domain.ch</code>):
                        Verzeichnis <strong><code>/</code></strong>. Ein solches Konto
                        sitzt bereits in seinem Ordner &ndash; was in cPanel als
                        Verzeichnis stand, ist von dort aus die Wurzel.
                    </li>
                    <li>
                        Benutzername <strong>ohne <code>@</code></strong>
                        (Haupt-cPanel-Konto): der volle Pfad, also
                        <code>/public_html</code> bzw. bei einer Subdomain
                        <code>/public_html/preview.deine-domain.ch</code>.
                    </li>
                    <li>Bei Plesk heisst er <code>/httpdocs</code>, bei Infomaniak <code>/web</code>.</li>
                </ul>
                <span class="wa-label__hint">
                    Unsicher? Einmal &bdquo;Verbindung testen&ldquo; &ndash; der Test
                    sieht nach der Anmeldung nach und schlägt den passenden Ordner vor.
                </span>

                <?php
                    $ordner = (array) ($gefunden['ordner'] ?? []);
                    $vorschlag = (string) ($gefunden['vorschlag'] ?? '');
                ?>
                <?php if ($ordner !== []): ?>
                    <div class="wa-found">
                        <p class="wa-found__title">
                            Beim letzten Test dort gefunden &ndash; zum Übernehmen anklicken:
                        </p>
                        <div class="wa-found__list">
                            <?php foreach ($ordner as $eintrag): ?>
                                <button type="button" class="wa-found__item<?= $eintrag === $vorschlag ? ' is-suggested' : '' ?>"
                                        data-fill="#path" data-fill-value="<?= e((string) $eintrag) ?>">
                                    <?= e((string) $eintrag) ?><?= $eintrag === $vorschlag ? ' ·  passt vermutlich' : '' ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn wa-btn--primary">Zugangsdaten speichern</button>
        </div>
    </form>

    <?php
    /**
     * Das Ergebnis des letzten Tests, Stufe fuer Stufe.
     *
     * Frueher stand hier eine einzige rote Zeile. Sieben verschiedene
     * Ursachen sahen damit gleich aus - ein Servername, den es nicht
     * gibt, genauso wie ein Ordner, der eine Ebene tiefer liegt. Die
     * Kette macht sichtbar, wie weit es gekommen ist: Die erste rote
     * Stufe ist die Antwort, und alles Gruene davor ist der Beweis,
     * dass daran nichts mehr zu suchen ist.
     */
    $stufen = (array) ($gefunden['stufen'] ?? []);
    ?>
    <?php if ($stufen !== []): ?>
        <div class="wa-stufen">
            <h3 class="wa-stufen__title">
                Letzter Verbindungstest
                <?php if ((string) ($gefunden['zeit'] ?? '') !== ''): ?>
                    <span class="wa-stufen__time"><?= e((string) $gefunden['zeit']) ?></span>
                <?php endif; ?>
            </h3>
            <ol class="wa-stufen__list">
                <?php foreach ($stufen as $stufe): ?>
                    <li class="wa-stufen__item<?= ($stufe['ok'] ?? false) ? ' is-ok' : ' is-bad' ?>">
                        <span class="wa-stufen__mark" aria-hidden="true"><?= ($stufe['ok'] ?? false) ? '&check;' : '&times;' ?></span>
                        <span class="wa-stufen__name"><?= e((string) ($stufe['name'] ?? '')) ?></span>
                        <span class="wa-stufen__info"><?= e((string) ($stufe['info'] ?? '')) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    <?php endif; ?>

    <?php if ($target !== null): ?>
        <div class="wa-form__actions">
            <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/ftp/testen">
                <?= Csrf::field() ?>
                <button type="submit" class="wa-btn">Verbindung testen</button>
            </form>
            <?php /* Ohne gebautes Paket gibt es nichts hochzuladen - dann
                     bleibt der Knopf weg, statt eine Fehlermeldung zu
                     versprechen. */ ?>
            <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/hochladen"
                  <?= $builds === [] ? 'hidden' : '' ?>
                  data-confirm="Die Website jetzt auf den Server des Kunden laden? Bestehende Dateien im Zielverzeichnis werden überschrieben.">
                <?= Csrf::field() ?>
                <button type="submit" class="wa-btn wa-btn--primary">Website hochladen</button>
            </form>
        </div>

        <?php if ((string) ($target['last_result'] ?? '') !== ''): ?>
            <div class="wa-note">
                <div>
                    <strong>Zuletzt:</strong> <?= e((string) $target['last_result']) ?>
                    <?php if ((string) ($target['last_deployed_at'] ?? '') !== ''): ?>
                        (<?= e(date('d.m.Y H:i', strtotime((string) $target['last_deployed_at']))) ?>)
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
