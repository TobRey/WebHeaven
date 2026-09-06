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
/** @var array|null $job @var array $brief @var array $gefunden */

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
    Jede fertige Website liegt als Paket bereit. Hinauf und wieder herunter geht sie
    über HTTPS – dafür liegt einmal eine Empfangsdatei auf der Website des Kunden.
    Wo FTP durchkommt, geht es auch darüber; die Zugangsdaten dafür stehen ganz unten.
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
 * Der Weg über HTTPS - und damit ab jetzt der normale Weg.
 *
 * FTP braucht zwei Verbindungen: eine für die Befehle und für jede
 * Datei eine zweite auf einem hohen Port. Genau die zweite wird auf
 * geteiltem Hosting gern verworfen, und dann hilft kein Einstellen
 * mehr. HTTPS braucht nur eine, auf Port 443, und die ist von jedem
 * Webserver aus offen.
 *
 * Der zweite Vorteil steht in keiner Anleitung und ist der grössere:
 * Der Empfänger arbeitet immer in dem Ordner, in dem er selbst liegt.
 * Die Frage nach dem richtigen Verzeichnis - "/", "/public_html",
 * "/public_html/kunde.ch" - stellt sich hier gar nicht.
 *
 * Der Stand des Empfängers wird nicht beim Seitenaufbau gemessen: Das
 * ist eine Anfrage über die Leitung, und eine Seite, die deswegen
 * hängt, ist ein schlechter Tausch für eine Zeile Auskunft. Also auf
 * Knopfdruck, mit Zeitstempel daneben.
 */
$adresse = trim((string) ($project['domain'] ?? ''));
$stand = (array) ($empfang ?? []);
$gemessen = array_key_exists('ok', $stand);
$liegt = $gemessen && (bool) $stand['ok'];
$art = (string) ($stand['art'] ?? '');
$dauerhaft = $liegt && $art === 'dauerhaft';
$offen = $adresse !== '';
?>
<section class="wa-panel wa-transfer">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Website hochladen und holen</h2>
        <p class="wa-panel__hint">
            Über HTTPS auf Port 443 &ndash; der Ausgang, der auf jedem Hosting offen ist.
            <strong>Holen</strong> geht dauerhaft: Beim ersten Hochladen bleibt eine
            Leseschnittstelle auf der Website liegen, danach braucht es dafür keinen
            Handgriff mehr. <strong>Hochladen</strong> braucht jedes Mal kurz die
            Empfangsdatei &ndash; eine Schreibstelle lässt man nicht dauerhaft offen.
        </p>
    </div>

    <div class="wa-note">
        <div>
            <strong>Schnittstelle:</strong>
            <?php if (!$offen): ?>
                <span class="wa-badge wa-badge--warn">keine Adresse</span>
                Diese Website hat keine Adresse hinterlegt &ndash; ohne sie weiss der Weg nicht,
                wen er anrufen soll.
                <a href="<?= e($base) ?>/websites/<?= $id ?>">Adresse eintragen</a>
            <?php elseif ($dauerhaft): ?>
                <span class="wa-badge wa-badge--ok">Leseschnittstelle liegt dort</span>
                Stand holen geht ohne weiteres Zutun. Nachgesehen am
                <?= e((string) ($stand['zeit'] ?? '')) ?> auf <code><?= e($adresse) ?></code>
            <?php elseif ($liegt): ?>
                <span class="wa-badge wa-badge--ok">Empfänger liegt bereit</span>
                nachgesehen am <?= e((string) ($stand['zeit'] ?? '')) ?> auf
                <code><?= e($adresse) ?></code>
            <?php elseif ($gemessen): ?>
                <span class="wa-badge wa-badge--bad">nichts da</span>
                <?= e((string) ($stand['error'] ?? '')) ?>
                (nachgesehen am <?= e((string) ($stand['zeit'] ?? '')) ?>)
            <?php else: ?>
                <span class="wa-badge">noch nicht nachgesehen</span>
                Ob dort etwas liegt, weiss nur ein Anruf.
            <?php endif; ?>
        </div>
    </div>

    <?php if ($offen): ?>
        <div class="wa-form__actions">
            <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/empfaenger/probe">
                <?= Csrf::field() ?>
                <button type="submit" class="wa-btn">Nachsehen, was dort liegt</button>
            </form>
            <?php /* Jeweils nur, was es zu entfernen gibt. Ein Knopf, der
                     etwas wegräumt, das nicht da ist, verspricht eine
                     Wirkung, die er nicht hat. */ ?>
            <?php if ($dauerhaft): ?>
                <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/lesezugang/sperren"
                      data-confirm="Die Leseschnittstelle sperren? Stand holen geht danach nicht mehr ohne FTP.">
                    <?= Csrf::field() ?>
                    <button type="submit" class="wa-btn">Leseschnittstelle sperren</button>
                </form>
            <?php elseif ($liegt): ?>
                <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/empfaenger/weg"
                      data-confirm="Den Empfänger jetzt von der Website entfernen?">
                    <?= Csrf::field() ?>
                    <button type="submit" class="wa-btn">Empfänger jetzt entfernen</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php /* Zugeklappt, sobald er liegt: Dann sind die drei Handgriffe
             getan und stehen nur noch im Weg. */ ?>
    <details class="wa-help"<?= $liegt ? '' : ' open' ?>>
        <summary>Die drei Handgriffe &ndash; einmal pro Website</summary>
        <div class="wa-help__body">
            <ol>
                <li>
                    <a href="<?= e($base) ?>/projekt/<?= $id ?>/empfaenger">Empfangsdatei herunterladen</a>
                    &ndash; sie wird als <code>webatze-empfang.php.txt</code> gespeichert.
                </li>
                <li>
                    Mit deinem FTP-Programm vom eigenen Rechner in das Verzeichnis der
                    Website legen und dabei in <code>webatze-empfang.php</code>
                    umbenennen (das <code>.txt</code> weg).
                </li>
                <li>Hier hochladen &ndash; es geht dann über HTTPS.</li>
            </ol>
            <p class="wa-label__hint">
                Die Empfangsdatei trägt einen eigenen Schlüssel, nimmt nur unterschriebene
                Anfragen an und <strong>löscht sich nach der Übertragung selbst</strong>
                &ndash; spätestens aber nach 24 Stunden.
            </p>
            <p class="wa-label__hint">
                Beim Hochladen bleibt <code>wa-dateien.php</code> auf der Website liegen.
                Sie <strong>liest nur</strong>, gibt Geheimnisse wie
                <code>config.php</code> nie heraus und lässt sich hier oben jederzeit
                sperren. Ab dann geht &bdquo;Aktuellen Stand holen&ldquo; ohne jeden
                weiteren Handgriff &ndash; auch dann, wenn FTP nie durchkommt.
            </p>
        </div>
    </details>

    <div class="wa-grid-2">
        <div class="wa-field">
            <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/archiv-bruecke"
                  enctype="multipart/form-data" class="wa-form">
                <?= Csrf::field() ?>

                <label class="wa-label" for="archiv-bruecke">Website hochladen (ZIP)</label>
                <input class="wa-input" type="file" id="archiv-bruecke" name="archiv"
                       accept=".zip,application/zip"<?= $offen ? '' : ' disabled' ?>>
                <span class="wa-label__hint">
                    Das Ergebnis aus dem Auftragstext, so wie es kommt. Liegt alles
                    in einem Ordner, wird der weggeschnitten &ndash; die Startseite
                    landet also direkt dort, wo die Empfangsdatei liegt. Höchstens
                    <?= (int) (\WebAtze\Http\DeployController::MAX_ARCHIV_BYTES / 1024 / 1024) ?>&nbsp;MB.
                </span>

                <label class="wa-checkbox">
                    <input type="checkbox" name="liegenlassen" value="1"<?= $offen ? '' : ' disabled' ?>>
                    <span>Empfänger danach liegen lassen</span>
                </label>

                <?php /* Vorgehakt, weil es der Sinn der Sache ist - aber es
                         ist die Website des Kunden, also abwählbar. */ ?>
                <label class="wa-checkbox">
                    <input type="checkbox" name="lesezugang" value="1" checked<?= $offen ? '' : ' disabled' ?>>
                    <span>Leseschnittstelle mitliefern (für „Stand holen“ ohne FTP)</span>
                </label>

                <div class="wa-form__actions">
                    <?php if ($offen): ?>
                        <button type="submit" class="wa-btn wa-btn--primary"
                                data-confirm="Das Archiv jetzt über HTTPS auf die Website laden? Bestehende Dateien werden überschrieben.">
                            Hochladen
                        </button>
                    <?php else: ?>
                        <button type="button" class="wa-btn wa-btn--primary" disabled>Hochladen</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="wa-field">
            <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/stand-bruecke" class="wa-form">
                <?= Csrf::field() ?>

                <span class="wa-label">Alle Dateien herunterladen</span>
                <span class="wa-label__hint">
                    Holt, was in diesem Moment tatsächlich auf dem Server liegt &ndash;
                    samt hochgeladener Bilder, eingegangener Anfragen und im Backend
                    geänderter Texte. Als ZIP, unter &bdquo;Paket&ldquo; zum Herunterladen.
                </span>

                <label class="wa-checkbox">
                    <input type="checkbox" name="liegenlassen" value="1"<?= $offen ? '' : ' disabled' ?>>
                    <span>Empfänger danach liegen lassen</span>
                </label>

                <div class="wa-form__actions">
                    <?php if ($offen): ?>
                        <button type="submit" class="wa-btn">Aktuellen Stand holen</button>
                    <?php else: ?>
                        <button type="button" class="wa-btn" disabled>Aktuellen Stand holen</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</section>

<?php
/**
 * Derselbe Weg über FTP - für die Server, bei denen er durchkommt.
 *
 * Er steht nicht mehr zuoberst, weil er es bei geteiltem Hosting oft
 * nicht tut: Die zweite Verbindung, die jede Datei braucht, wird
 * verworfen, und dann hilft kein Einstellen mehr. SFTP hängt an
 * denselben Zugangsdaten und ist davon nicht betroffen.
 *
 * Die zwei Knöpfe sind ein Paar: Das eine schiebt eine fertige
 * Website auf den Server, das andere holt, was gerade darauf liegt.
 * Beides braucht nur die Zugangsdaten darunter und nichts sonst –
 * insbesondere keinen Bau hier im Haus.
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
        <h2 class="wa-panel__title">Derselbe Weg über FTP oder SFTP</h2>
        <div class="wa-panel__actions">
            <?php if (!$bereit): ?>
                <a class="wa-btn wa-btn--sm" href="#zugang">Zugangsdaten eintragen</a>
            <?php endif; ?>
            <?php /* Braucht keine Zugangsdaten: Er fragt, ob dieser Server
                     ueberhaupt eine FTP-Datenverbindung nach draussen
                     aufbauen darf. Deshalb steht er hier und nicht bei den
                     Zugangsdaten - er beantwortet die Frage, bevor man
                     welche eintraegt. */ ?>
            <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/ftp/ausgang">
                <?= Csrf::field() ?>
                <button type="submit" class="wa-btn wa-btn--sm">
                    Kann dieser Server überhaupt FTP?
                </button>
            </form>
        </div>
        <p class="wa-panel__hint">
            <?php if ($bereit): ?>
                Beides geht über die Zugangsdaten weiter unten. Für das Hochladen
                brauchst du kein hier gebautes Paket &ndash; ein fertiges ZIP genügt.
                Kommt die Datenverbindung nicht durch, nimm den Weg über HTTPS oben.
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
                    Dasselbe wie oben, nur über FTP &ndash; und in das Verzeichnis,
                    das bei den Zugangsdaten steht.
                </span>

                <div class="wa-form__actions">
                    <?php if ($bereit): ?>
                        <button type="submit" class="wa-btn"
                                data-confirm="Das Archiv jetzt über FTP auf den Server des Kunden laden? Bestehende Dateien im Zielverzeichnis werden überschrieben.">
                            Über FTP hochladen
                        </button>
                    <?php else: ?>
                        <button type="button" class="wa-btn" disabled>Über FTP hochladen</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="wa-field">
            <span class="wa-label">Alle Dateien herunterladen</span>
            <span class="wa-label__hint">
                Dasselbe wie oben, nur über FTP statt über HTTPS.
            </span>

            <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/stand-holen"
                  class="wa-form">
                <?= Csrf::field() ?>
                <div class="wa-form__actions">
                    <?php if ($bereit): ?>
                        <button type="submit" class="wa-btn">Stand über FTP holen</button>
                    <?php else: ?>
                        <button type="button" class="wa-btn" disabled>Stand über FTP holen</button>
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
                /* Das "/" steht schon drin, weil es fuer ein cPanel-Unterkonto
                   stimmt: So eines sitzt bereits in seinem Ordner. Wer das
                   Hauptkonto benutzt, traegt den vollen Pfad ein - und wer
                   unsicher ist, drueckt einmal auf "Verbindung testen": Der
                   Test sieht nach und legt die gefundenen Ordner als Knoepfe
                   unter das Feld. */
                ?>
                <input class="wa-input" type="text" id="path" name="path" data-ftp-field="path"
                       placeholder="/"
                       value="<?= e((string) ($target['remote_path'] ?? '/')) ?>">

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
     * Was der letzte Test gemessen hat.
     *
     * Frueher stand hier eine Kette aus acht Stufen. Sie sollte die
     * Ursache zeigen und hat sie laufend verwechselt - ein leerer
     * Ordner als Netzfehler, ein "gibt es nicht" ueber einem 250 Ok.
     * Jetzt steht das Urteil als Meldung oben, und hier liegt nur noch,
     * was tatsaechlich gemessen wurde: aufgeklappt fuer den, der es
     * braucht, und aus dem Weg fuer alle anderen.
     */
    $einzelheiten = (array) ($gefunden['details'] ?? []);
    ?>
    <?php if ($einzelheiten !== []): ?>
        <details class="wa-help">
            <summary>Was der Test gemessen hat</summary>
            <div class="wa-help__body">
                <ol>
                    <?php foreach ($einzelheiten as $zeile): ?>
                        <li><?= e((string) $zeile) ?></li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </details>
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
                <button type="submit" class="wa-btn">Gebautes Paket über FTP hochladen</button>
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
