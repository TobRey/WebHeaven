<?php
/**
 * Einstellungen.
 *
 * Oben steht, was auf der Website erscheint. Unten steht, ob technisch
 * alles läuft – diese Prüfung ist bewusst gnadenlos, damit ein fehlendes
 * Stück nicht erst dann auffällt, wenn ein Kunde wartet.
 */

use WebAtze\Core\{Config, Csrf};

/** @var array $settings @var array|null $user @var bool $aiConfigured */
/** @var bool $imprintComplete @var string $cronHint @var array $diagnostics */
/** @var array $twoFactor */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
$s = static fn (string $key): string => (string) ($settings[$key] ?? '');

$problems = 0;
foreach ($diagnostics as $check) {
    if (empty($check['ok'])) {
        $problems++;
    }
}
?>

<?php if ($problems > 0): ?>
    <div class="wa-note wa-note--warning">
        <div>
            <?= $problems === 1
                ? 'Ein Punkt der Selbstprüfung ist offen.'
                : $problems . ' Punkte der Selbstprüfung sind offen.' ?>
            Sie stehen weiter unten – solange sie offen sind, fehlt der Anwendung etwas.
        </div>
    </div>
<?php endif; ?>

<?php if (!$imprintComplete): ?>
    <div class="wa-note wa-note--warning">
        <div>
            Für ein vollständiges Impressum fehlen noch Angaben. Ohne sie ist die Website
            in der Schweiz und in der EU nicht rechtskonform.
        </div>
    </div>
<?php endif; ?>

<?php /* ------------------------------------------------------------- Angaben */ ?>
<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Angaben auf der Website</h2>
        <p class="wa-panel__hint">
            Diese Werte erscheinen im Kontaktbereich, im Fussbereich und im Impressum.
        </p>
    </div>

    <form class="wa-form" method="post" action="<?= e($base) ?>/einstellungen">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="settings">

        <fieldset class="wa-fieldset">
            <legend class="wa-fieldset__legend">Firma</legend>

            <div class="wa-grid-2">
                <div class="wa-field">
                    <label class="wa-label" for="company_name">Firmenname</label>
                    <input class="wa-input" type="text" id="company_name" name="company_name"
                           value="<?= e($s('company_name')) ?>">
                </div>
                <div class="wa-field">
                    <label class="wa-label" for="owner_name">Inhaber</label>
                    <input class="wa-input" type="text" id="owner_name" name="owner_name"
                           value="<?= e($s('owner_name')) ?>">
                </div>
            </div>

            <div class="wa-grid-2">
                <div class="wa-field">
                    <label class="wa-label" for="street">Strasse und Nummer</label>
                    <input class="wa-input" type="text" id="street" name="street"
                           value="<?= e($s('street')) ?>">
                </div>
                <div class="wa-field">
                    <label class="wa-label" for="zip_city">PLZ und Ort</label>
                    <input class="wa-input" type="text" id="zip_city" name="zip_city"
                           value="<?= e($s('zip_city')) ?>">
                </div>
            </div>

            <div class="wa-grid-2">
                <div class="wa-field">
                    <label class="wa-label" for="country">Land</label>
                    <input class="wa-input" type="text" id="country" name="country"
                           value="<?= e($s('country')) ?>">
                </div>
                <div class="wa-field">
                    <label class="wa-label" for="uid">UID / Handelsregister</label>
                    <input class="wa-input" type="text" id="uid" name="uid"
                           value="<?= e($s('uid')) ?>"
                           placeholder="CHE-123.456.789">
                    <span class="wa-label__hint">Nur nötig, wenn im Register eingetragen.</span>
                </div>
                <div class="wa-field">
                    <label class="wa-label" for="iban">IBAN</label>
                    <input class="wa-input" type="text" id="iban" name="iban"
                           value="<?= e($s('iban')) ?>"
                           placeholder="CH00 0000 0000 0000 0000 0" spellcheck="false">
                    <span class="wa-label__hint">
                        Steht auf jeder Rechnung. Ohne sie weiss der Kunde nicht, wohin
                        er zahlen soll.
                    </span>
                </div>
            </div>
        </fieldset>

        <fieldset class="wa-fieldset">
            <legend class="wa-fieldset__legend">Erreichbarkeit</legend>

            <div class="wa-grid-2">
                <div class="wa-field">
                    <label class="wa-label" for="email">E-Mail</label>
                    <input class="wa-input" type="email" id="email" name="email"
                           value="<?= e($s('email')) ?>">
                    <span class="wa-label__hint">An diese Adresse gehen die Anfragen aus dem Formular.</span>
                </div>
                <div class="wa-field">
                    <label class="wa-label" for="phone">Telefon</label>
                    <input class="wa-input" type="text" id="phone" name="phone"
                           value="<?= e($s('phone')) ?>">
                </div>
            </div>

            <div class="wa-grid-2">
                <div class="wa-field">
                    <label class="wa-label" for="whatsapp">WhatsApp</label>
                    <input class="wa-input" type="text" id="whatsapp" name="whatsapp"
                           value="<?= e($s('whatsapp')) ?>"
                           placeholder="+41 79 000 00 00">
                </div>
                <div class="wa-field">
                    <label class="wa-label" for="instagram">Instagram</label>
                    <input class="wa-input" type="text" id="instagram" name="instagram"
                           value="<?= e($s('instagram')) ?>">
                </div>
            </div>

            <div class="wa-grid-2">
                <div class="wa-field">
                    <label class="wa-label" for="linkedin">LinkedIn</label>
                    <input class="wa-input" type="text" id="linkedin" name="linkedin"
                           value="<?= e($s('linkedin')) ?>">
                </div>
                <div class="wa-field">
                    <label class="wa-label" for="hero_available">Auftragslage im Aufmacher</label>
                    <select class="wa-select" id="hero_available" name="hero_available">
                        <option value="1" <?= $s('hero_available') === '1' ? 'selected' : '' ?>>
                            „Nimmt neue Projekte an" anzeigen
                        </option>
                        <option value="0" <?= $s('hero_available') === '0' ? 'selected' : '' ?>>
                            nicht anzeigen
                        </option>
                    </select>
                </div>
            </div>
        </fieldset>

        <fieldset class="wa-fieldset">
            <legend class="wa-fieldset__legend">Impressum</legend>
            <div class="wa-field">
                <label class="wa-label" for="imprint_extra">Zusätzlicher Text</label>
                <textarea class="wa-textarea" id="imprint_extra" name="imprint_extra"
                          rows="6"><?= e($s('imprint_extra')) ?></textarea>
                <span class="wa-label__hint">
                    Wird unter den Pflichtangaben ausgegeben – etwa Haftungsausschluss oder
                    Angaben zur Aufsichtsbehörde.
                </span>
            </div>
        </fieldset>
        <?php
            $ueberlebt = (int) \WebAtze\Core\Settings::get('worker_survival_seconds', '0');
            $grenze = (string) ini_get('max_execution_time');
        ?>
        <fieldset class="wa-fieldset">
            <legend class="wa-fieldset__legend">Was dein Server zulaesst</legend>
            <p class="wa-fieldset__hint">
                Wird gemessen, nicht vermutet. Bricht der Server einen Durchlauf ab,
                merkt sich der Arbeiter, wie lange er durchgehalten hat, und rechnet
                beim naechsten Mal damit. Nichts einzustellen &ndash; nur zum Nachsehen.
            </p>

            <dl class="wa-kv">
                <dt>Zeitgrenze in der php.ini</dt>
                <dd><?= $grenze === '0' ? 'keine' : e($grenze) . ' Sekunden' ?></dd>

                <dt>Laesst sich die Grenze anheben?</dt>
                <dd><?= function_exists('set_time_limit') ? 'ja' : 'nein &ndash; set_time_limit ist gesperrt' ?></dd>

                <dt>Gemessen durchgehalten</dt>
                <dd>
                    <?php if ($ueberlebt > 0): ?>
                        <?= (int) $ueberlebt ?> Sekunden
                        <?php if ($ueberlebt < 40): ?>
                            &ndash; das ist knapp. Der Arbeiter macht daraus kleinere Haeppchen,
                            der Bau dauert dadurch laenger, bleibt aber sauber.
                        <?php endif; ?>
                    <?php else: ?>
                        noch kein Abbruch beobachtet &ndash; gut so.
                    <?php endif; ?>
                </dd>
            </dl>
        </fieldset>

        <fieldset class="wa-fieldset">
            <legend class="wa-fieldset__legend">Zugang zum Sprachmodell</legend>
            <p class="wa-fieldset__hint">
                Der Schlüssel selbst steht in <code>app/config.php</code> und gehört auch
                dorthin – er hat auf dieser Seite nichts zu suchen.
            </p>

            <div class="wa-field">
                <label class="wa-label" for="anthropic_workspace_id">
                    Arbeitsbereich
                    <span class="wa-label__hint">nur nötig bei einem persönlichen Schlüssel</span>
                </label>
                <input class="wa-input" type="text" id="anthropic_workspace_id"
                       name="anthropic_workspace_id" maxlength="120" spellcheck="false"
                       placeholder="wrkspc_01…"
                       value="<?= e($s('anthropic_workspace_id')) ?>">
                <span class="wa-label__hint">
                    Ein Schlüssel gehört entweder einem Arbeitsbereich oder einer Person.
                    Im zweiten Fall weiss die Schnittstelle nicht, für welchen Bereich sie
                    abrechnen soll, und weist jede Anfrage ab.
                </span>
                <span class="wa-label__hint">
                    <strong>So kommst du an die Kennung:</strong>
                    <a href="https://platform.claude.com" target="_blank" rel="noopener noreferrer">platform.claude.com</a>
                    öffnen und in die Adresszeile schauen – dort steht
                    <code>…/workspaces/<strong>wrkspc_01…</strong></code>. Du kannst die
                    ganze Adresse hier einfügen, die Kennung wird herausgelesen.
                    Nach dem Speichern wird sofort geprüft, ob es damit geht.
                </span>
                <span class="wa-label__hint">
                    <strong>Der andere Weg, ein für alle Mal:</strong> in der Konsole unter
                    „API keys" einen neuen Schlüssel anlegen und dabei einen Arbeitsbereich
                    auswählen statt „Personal". Ein solcher Schlüssel sagt selbst, wohin er
                    gehört – dann bleibt dieses Feld für immer leer.
                </span>
                <span class="wa-label__hint">
                    Selbst herausfinden kann die Anwendung es nicht: Die Liste der
                    Arbeitsbereiche darf nur ein Admin-Schlüssel lesen, und deiner baut
                    Websites, statt die Organisation zu verwalten. Das ist so gewollt.
                </span>

                <p class="wa-fieldset__hint">
                    <button type="submit" name="workspace_test" value="1"
                            class="wa-btn wa-btn--quiet wa-btn--sm"
                            formnovalidate>
                        Ausprobieren, ohne zu speichern
                    </button>
                    Klopft einmal bei der Schnittstelle an und sagt sofort, ob es mit
                    dem geht, was oben im Feld steht. Kostet nichts.
                </p>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="admin_key">
                    Kennung nachschlagen
                    <span class="wa-label__hint">nur falls du sie nirgends findest</span>
                </label>
                <input class="wa-input" type="password" id="admin_key" name="admin_key"
                       autocomplete="off" spellcheck="false"
                       placeholder="sk-ant-admin…">
                <p class="wa-fieldset__hint">
                    <button type="submit" name="workspace_lookup" value="1"
                            class="wa-btn wa-btn--quiet wa-btn--sm" formnovalidate>
                        Nachschlagen
                    </button>
                    Der Standardbereich zeigt in der Konsole keine Kennung an – dann
                    hilft nur fragen. Ein <strong>Admin-Schlüssel</strong> darf die Liste
                    lesen; er wird in der Konsole unter <em>Settings → Admin keys</em>
                    angelegt und beginnt mit <code>sk-ant-admin</code>.
                </p>
                <p class="wa-fieldset__hint">
                    Der Schlüssel wird <strong>nur für diese eine Abfrage</strong> benutzt
                    und nirgends gespeichert. Er darf mehr als der, der Websites baut –
                    so einer gehört nicht in eine Datei, die jede Nacht gesichert wird.
                    Gefunden sich genau ein Arbeitsbereich, wird er gleich eingetragen
                    und geprüft. Danach kannst du den Admin-Schlüssel in der Konsole
                    wieder löschen.
                </p>
            </div>
        </fieldset>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn wa-btn--primary">Einstellungen speichern</button>
        </div>
    </form>
</section>

<?php
/**
 * Der gemeinsame Hosting-Zugang.
 *
 * Alle Websites liegen auf demselben Konto: Server, Benutzername und
 * Passwort sind jedes Mal dieselben, nur das Verzeichnis
 * unterscheidet sich. Bisher wurde das je Website eingetippt – bei
 * acht Websites acht Gelegenheiten, beim nächsten Passwortwechsel eine
 * zu vergessen, und die fällt erst beim nächsten Hochladen auf.
 */
?>
<section class="wa-panel" id="hosting">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Hosting-Zugänge</h2>
        <p class="wa-panel__hint">
            Einmal hinterlegt, bei jeder Website auswählbar. Dort bleibt dann
            nur noch das Verzeichnis einzutragen &ndash; und ändert der Anbieter
            das Passwort, wird es hier einmal geändert.
        </p>
    </header>

    <?php if (($hostingAccounts ?? []) !== []): ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Server</th>
                        <th>Benutzername</th>
                        <th>Websites</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($hostingAccounts as $h): ?>
                        <tr>
                            <td>
                                <strong class="wa-table__main"><?= e((string) $h['name']) ?></strong>
                                <span class="wa-table__quiet">
                                    <?= e(mb_strtoupper((string) $h['protocol'])) ?> · Port <?= (int) $h['port'] ?>
                                </span>
                            </td>
                            <td><?= e((string) $h['host']) ?></td>
                            <td><?= e((string) $h['username']) ?></td>
                            <td class="wa-table__quiet">
                                <?= (int) \WebAtze\Domain\HostingAccount::usedBy((int) $h['id']) ?>
                            </td>
                            <td class="wa-table__actions">
                                <button type="button" class="wa-icon-btn"
                                        data-dialog="#hosting-<?= (int) $h['id'] ?>"
                                        aria-label="<?= e((string) $h['name']) ?> ändern" title="Ändern">
                                    <?= View_partial('partials/admin-icons', ['name' => 'pencil']) ?>
                                </button>

                                <form method="post" action="<?= e($base) ?>/einstellungen"
                                      data-confirm="Diesen Zugang entfernen? Websites, die ihn benutzen, haben danach keine Zugangsdaten mehr.">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="hosting">
                                    <input type="hidden" name="hosting_id" value="<?= (int) $h['id'] ?>">
                                    <button type="submit" name="entfernen" value="hosting"
                                            class="wa-icon-btn wa-icon-btn--danger"
                                            aria-label="<?= e((string) $h['name']) ?> entfernen" title="Entfernen">
                                        <?= View_partial('partials/admin-icons', ['name' => 'trash']) ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php foreach ($hostingAccounts as $h): ?>
            <?= View_partial('partials/dialog', [
                'id' => 'hosting-' . (int) $h['id'],
                'titel' => (string) $h['name'],
                'inhalt' => View_partial('partials/hosting-form', [
                    'konto' => $h,
                    'base' => $base,
                ]),
            ]) ?>
        <?php endforeach; ?>
    <?php else: ?>
        <p class="wa-empty">
            Noch kein Zugang hinterlegt. Wer alle Websites bei demselben Anbieter
            hat, trägt ihn hier einmal ein statt bei jeder Website neu.
        </p>
    <?php endif; ?>

    <details class="wa-details"<?= ($hostingAccounts ?? []) === [] ? ' open' : '' ?>>
        <summary>Zugang hinzufügen</summary>
        <?= View_partial('partials/hosting-form', [
            'konto' => [],
            'base' => $base,
        ]) ?>
    </details>
</section>

<?php
/**
 * Der Editor.
 *
 * Er kommt seit dieser Fassung als eigenes Paket und nicht mehr im
 * Hauptpaket. Das heisst zweierlei: Ein Editor-Update braucht kein
 * neues Hauptpaket, und ein neues Hauptpaket wirft den installierten
 * Editor nicht um.
 *
 * Was hier hochgeladen wird, besteht ausschliesslich aus JavaScript
 * und CSS – das prüft EditorPlugin, bevor eine einzige Datei
 * geschrieben wird. Ein Upload-Feld, das PHP annimmt, wäre eine
 * Hintertür mit Formular.
 */
?>
<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Editor</h2>
        <?php if (($editorPlugin ?? null) !== null): ?>
            <span class="wa-badge wa-badge--ok">
                Fassung <?= e((string) $editorPlugin['version']) ?>
            </span>
        <?php elseif ($editorBereit ?? false): ?>
            <?php /* Der Fall auf dem Entwicklungsrechner: Der Editor
                     liegt aus einem lokalen Build da, ohne dass je ein
                     Paket eingespielt wurde. "Nicht installiert" wäre
                     hier schlicht falsch – er funktioniert ja. */ ?>
            <span class="wa-badge">aus dem Build</span>
        <?php else: ?>
            <span class="wa-badge wa-badge--warn">nicht installiert</span>
        <?php endif; ?>
    </header>

    <?php if (($editorPlugin ?? null) === null && ($editorBereit ?? false)): ?>
        <p class="wa-hint">
            Der Editor liegt aus einem lokalen Build vor und funktioniert.
            Ein hochgeladenes Paket würde ihn ersetzen &ndash; und
            überlebt anders als dieser das nächste Hauptpaket.
        </p>
    <?php elseif (($editorPlugin ?? null) === null): ?>
        <p class="wa-hint">
            Ohne Editor lassen sich Websites weiterhin bauen, ausliefern und
            überwachen &ndash; nur nicht im Browser bearbeiten. Lade
            <code>webatze-editor-<em>fassung</em>.zip</code> hoch; es bleibt
            danach liegen, auch über ein neues Hauptpaket hinweg.
        </p>
    <?php else: ?>
        <p class="wa-hint">
            Installiert am
            <?= e(date('d.m.Y H:i', strtotime((string) $editorPlugin['installed_at']) ?: time())) ?>.
            Ein neues Archiv ersetzt diese Fassung; die Websites bleiben davon unberührt.
            <?php if (!($editorBereit ?? false)): ?>
                <br><strong>Achtung:</strong> Die Angaben sind da, die Dateien fehlen.
                Bitte das Archiv erneut hochladen.
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <form method="post" action="<?= e($base) ?>/einstellungen"
          enctype="multipart/form-data" class="wa-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="editor">

        <div class="wa-field">
            <label class="wa-label" for="plugin">Editor-Paket (.zip)</label>
            <input class="wa-input" type="file" id="plugin" name="plugin" accept=".zip,application/zip">
            <span class="wa-label__hint">
                Höchstens <?= (int) (\WebAtze\Domain\EditorPlugin::MAX_BYTES / 1024 / 1024) ?>&nbsp;MB.
                Enthalten sein dürfen nur <code>assets/editor-*.js</code>,
                <code>assets/editor-*.css</code> und <code>kit/</code> &ndash;
                alles andere wird abgewiesen, nicht übersprungen.
            </span>
        </div>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn wa-btn--primary">
                <?= ($editorPlugin ?? null) === null ? 'Installieren' : 'Ersetzen' ?>
            </button>

            <?php if (($editorPlugin ?? null) !== null): ?>
                <button type="submit" name="entfernen" value="editor" class="wa-btn"
                        data-confirm="Den Editor entfernen? Die Websites bleiben, wie sie sind &ndash; bearbeiten lässt sich danach nichts mehr.">
                    Entfernen
                </button>
            <?php endif; ?>
        </div>
    </form>
</section>

<?php $briefkopf = \WebAtze\Domain\Letterhead::current(); ?>

<section class="wa-panel">
    <h2 class="wa-panel__title">Briefkopf für Offerten und Rechnungen</h2>

    <p class="wa-panel__hint">
        Lade deine Word-Vorlage hoch &ndash; daraus werden Absenderzeilen und Logo
        übernommen. Sie bleibt hinterlegt, bis du eine andere hochlädst.
    </p>

    <p class="wa-panel__hint">
        <strong>Ehrlich gesagt:</strong> Eine .docx lässt sich auf deinem Hosting nicht
        in ein PDF verwandeln &ndash; dafür bräuchte es LibreOffice auf dem Server.
        WebAtze liest deshalb Absender und Logo aus der Vorlage und setzt das PDF
        selbst. Was dabei nicht stimmt, korrigierst du unten von Hand.
    </p>

    <form method="post" action="<?= e($base) ?>/einstellungen" enctype="multipart/form-data" class="wa-form">
        <?= \WebAtze\Core\Csrf::field() ?>
        <input type="hidden" name="action" value="briefkopf">

        <div class="wa-form__grid">
            <div class="wa-field">
                <label class="wa-label" for="vorlage">Word-Vorlage (.docx)</label>
                <input class="wa-input" type="file" id="vorlage" name="vorlage" accept=".docx">
                <span class="wa-label__hint">
                    <?= \WebAtze\Domain\Letterhead::hasTemplate()
                        ? 'Eine Vorlage ist hinterlegt.' : 'Noch keine hinterlegt.' ?>
                </span>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="logo">Logo einzeln (PNG oder JPEG)</label>
                <input class="wa-input" type="file" id="logo" name="logo" accept="image/*">
                <span class="wa-label__hint">
                    <?= \WebAtze\Domain\Letterhead::hasLogo()
                        ? 'Ein Logo ist hinterlegt.' : 'Noch keines hinterlegt.' ?>
                </span>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="letterhead_logo_width">Logobreite</label>
                <input class="wa-input" type="number" id="letterhead_logo_width"
                       name="letterhead_logo_width" min="40" max="220"
                       value="<?= (int) $briefkopf['logo_breite'] ?>">
                <span class="wa-label__hint">In Punkten. 120 ist eine gute Ausgangsgrösse.</span>
            </div>

            <div class="wa-field wa-field--wide">
                <label class="wa-label" for="letterhead_lines">Absenderzeilen</label>
                <textarea class="wa-textarea" id="letterhead_lines" name="letterhead_lines"
                          rows="6"><?= e(implode("\n", $briefkopf['zeilen'])) ?></textarea>
                <span class="wa-label__hint">
                    Eine Zeile je Zeile. Erscheint klein unter dem Logo.
                </span>
            </div>
        </div>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn wa-btn--primary">Briefkopf speichern</button>
        </div>
    </form>

    <?php if (\WebAtze\Domain\Letterhead::hasLogo() || \WebAtze\Domain\Letterhead::hasTemplate()): ?>
        <div class="wa-form__actions">
            <?php if (\WebAtze\Domain\Letterhead::hasLogo()): ?>
                <form method="post" action="<?= e($base) ?>/einstellungen"
                      data-confirm="Logo entfernen?">
                    <?= \WebAtze\Core\Csrf::field() ?>
                    <input type="hidden" name="action" value="briefkopf">
                    <input type="hidden" name="entfernen" value="logo">
                    <button type="submit" class="wa-btn wa-btn--small">Logo entfernen</button>
                </form>
            <?php endif; ?>

            <?php if (\WebAtze\Domain\Letterhead::hasTemplate()): ?>
                <form method="post" action="<?= e($base) ?>/einstellungen"
                      data-confirm="Vorlage entfernen?">
                    <?= \WebAtze\Core\Csrf::field() ?>
                    <input type="hidden" name="action" value="briefkopf">
                    <input type="hidden" name="entfernen" value="vorlage">
                    <button type="submit" class="wa-btn wa-btn--small">Vorlage entfernen</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>


<?php /* ------------------------------------------------------ Zweiter Faktor */ ?>
<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">
            Zweiter Faktor
            <span class="wa-badge wa-badge--<?= $twoFactor['active'] ? 'done' : 'waiting' ?>">
                <?= $twoFactor['active'] ? 'aktiv' : 'nicht eingerichtet' ?>
            </span>
        </h2>
        <p class="wa-panel__hint">
            Hinter diesem Bereich liegen alle Kundendaten, die FTP-Zugänge und der
            Schlüssel zum Sprachmodell. Ein Passwort allein schützt das nicht ausreichend:
            Es kann mitgelesen, durchgesickert oder erraten worden sein. Ein Zeitcode
            vom Telefon hilft in allen drei Fällen.
        </p>
    </div>

    <?php /* --- Die Ersatzcodes: genau einmal zu sehen --- */ ?>
    <?php if ($twoFactor['recovery'] !== []): ?>
        <div class="wa-note wa-note--warning">
            <div>
                <strong>Diese Ersatzcodes jetzt notieren.</strong>
                Sie erscheinen kein zweites Mal. Jeder gilt einmal und ersetzt den
                Zeitcode, wenn das Telefon nicht zur Hand ist.
            </div>
        </div>
        <pre class="wa-code"><code><?= e(implode("\n", $twoFactor['recovery'])) ?></code></pre>
    <?php endif; ?>

    <?php if (!$twoFactor['active'] && ($twoFactor['setupSecret'] ?? '') === ''): ?>

        <form method="post" action="<?= e($base) ?>/einstellungen">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="2fa_start">
            <button type="submit" class="wa-btn wa-btn--primary">Einrichten</button>
        </form>

    <?php elseif (!$twoFactor['active']): ?>

        <p class="wa-panel__hint">
            In einer Authenticator-App (Google Authenticator, Aegis, 1Password, Bitwarden)
            ein neues Konto anlegen und diesen Schlüssel eintragen. Auf dem Telefon
            genügt ein Tippen auf den Verweis.
        </p>

        <pre class="wa-code"><code><?= e(trim(chunk_split($twoFactor['setupSecret'], 4, ' '))) ?></code></pre>

        <p class="wa-panel__hint">
            <a href="<?= e($twoFactor['setupUri']) ?>" rel="nofollow">Direkt in der App öffnen</a>
        </p>

        <form class="wa-form" method="post" action="<?= e($base) ?>/einstellungen" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="2fa_confirm">

            <div class="wa-field">
                <label class="wa-label" for="confirm-code">
                    Erster Code aus der App
                    <span class="wa-label__hint">
                        Scharf wird der zweite Faktor erst damit – sonst könntest du dich
                        aussperren, falls die App den Schlüssel nicht übernommen hat.
                    </span>
                </label>
                <input class="wa-input wa-input--short" type="text" id="confirm-code" name="code"
                       inputmode="numeric" maxlength="6" required placeholder="123456">
            </div>

            <div class="wa-form__actions">
                <button type="submit" class="wa-btn wa-btn--primary">Bestätigen</button>
            </div>
        </form>

    <?php else: ?>

        <p class="wa-panel__hint">
            Noch <strong><?= (int) $twoFactor['codesLeft'] ?></strong> Ersatzcodes übrig.
            <?php if ((int) $twoFactor['codesLeft'] <= 2): ?>
                Das wird knapp – am besten neue erzeugen.
            <?php endif; ?>
        </p>

        <?php if ($twoFactor['devices'] !== []): ?>
            <div class="wa-table-wrap">
                <table class="wa-table">
                    <thead><tr><th>Bekanntes Gerät</th><th>Von</th><th>Bis</th></tr></thead>
                    <tbody>
                    <?php foreach ($twoFactor['devices'] as $device): ?>
                        <tr>
                            <td><?= e(mb_substr((string) $device['label'], 0, 60)) ?></td>
                            <td><?= e((string) $device['ip']) ?></td>
                            <td><?= e(date('d.m.Y', strtotime((string) $device['expires_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="wa-grid-2">
            <form class="wa-form" method="post" action="<?= e($base) ?>/einstellungen" autocomplete="off">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="2fa_codes">
                <div class="wa-field">
                    <label class="wa-label" for="codes-code">Neue Ersatzcodes</label>
                    <input class="wa-input wa-input--short" type="text" id="codes-code" name="code"
                           inputmode="numeric" maxlength="9" required placeholder="Code">
                    <span class="wa-label__hint">Die bisherigen gelten danach nicht mehr.</span>
                </div>
                <div class="wa-form__actions">
                    <button type="submit" class="wa-btn">Erzeugen</button>
                </div>
            </form>

            <form class="wa-form" method="post" action="<?= e($base) ?>/einstellungen" autocomplete="off"
                  data-confirm="Zweiten Faktor wirklich abschalten? Danach genügt das Passwort allein.">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="2fa_disable">
                <div class="wa-field">
                    <label class="wa-label" for="off-code">Abschalten</label>
                    <input class="wa-input wa-input--short" type="text" id="off-code" name="code"
                           inputmode="numeric" maxlength="9" required placeholder="Code">
                    <span class="wa-label__hint">Auch dafür braucht es einen gültigen Code.</span>
                </div>
                <div class="wa-form__actions">
                    <button type="submit" class="wa-btn wa-btn--quiet">Abschalten</button>
                </div>
            </form>
        </div>

        <?php if ($twoFactor['devices'] !== []): ?>
            <form method="post" action="<?= e($base) ?>/einstellungen">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="2fa_forget">
                <button type="submit" class="wa-btn wa-btn--quiet wa-btn--sm">
                    Alle Geräte vergessen
                </button>
            </form>
        <?php endif; ?>

    <?php endif; ?>
</section>

<?php /* ------------------------------------------------------------ Passwort */ ?>
<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Passwort ändern</h2>
        <p class="wa-panel__hint">
            Angemeldet als <strong><?= e((string) ($user['username'] ?? '')) ?></strong>.
            Nach der Änderung werden alle Sitzungen beendet – auch diese hier.
        </p>
    </div>

    <form class="wa-form" method="post" action="<?= e($base) ?>/einstellungen" autocomplete="off">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="password">

        <div class="wa-field">
            <label class="wa-label" for="current_password">Bisheriges Passwort</label>
            <input class="wa-input" type="password" id="current_password" name="current_password"
                   autocomplete="current-password" required>
        </div>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="new_password">Neues Passwort</label>
                <input class="wa-input" type="password" id="new_password" name="new_password"
                       autocomplete="new-password" minlength="10" required>
                <span class="wa-label__hint">Mindestens 10 Zeichen.</span>
            </div>
            <div class="wa-field">
                <label class="wa-label" for="repeat_password">Wiederholen</label>
                <input class="wa-input" type="password" id="repeat_password" name="repeat_password"
                       autocomplete="new-password" minlength="10" required>
            </div>
        </div>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn">Passwort ändern</button>
        </div>
    </form>
</section>

<?php /* ----------------------------------------------------------- Cronjob */ ?>
<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Cronjob</h2>
        <p class="wa-panel__hint">
            In cPanel unter „Erweitert → Cron-Jobs" einmal pro Minute ausführen. Ohne ihn
            werden Aufträge nur beim Anlegen bearbeitet und alte Vorschauen nie aufgeräumt.
        </p>
    </div>
    <pre class="wa-code"><code><?= e($cronHint) ?></code></pre>
</section>

<?php /* ------------------------------------------------------ Selbstprüfung */ ?>
<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Selbstprüfung</h2>
        <p class="wa-panel__hint">
            <?php if ($aiConfigured): ?>
                Der Anthropic-Schlüssel ist hinterlegt – der Generator arbeitet mit echten Texten.
            <?php else: ?>
                Ohne Anthropic-Schlüssel läuft der Generator im Übungsmodus: Struktur und
                Vorlagen entstehen, die Texte sind Platzhalter.
            <?php endif; ?>
        </p>
    </div>

    <div class="wa-table-wrap">
        <table class="wa-table">
            <thead><tr><th></th><th>Punkt</th><th>Stand</th><th>Wozu</th></tr></thead>
            <tbody>
            <?php foreach ($diagnostics as $check): ?>
                <tr>
                    <td>
                        <span class="wa-badge wa-badge--<?= !empty($check['ok']) ? 'done' : 'failed' ?>">
                            <?= !empty($check['ok']) ? 'ok' : 'offen' ?>
                        </span>
                    </td>
                    <td><?= e((string) $check['label']) ?></td>
                    <td><?= e((string) $check['value']) ?></td>
                    <td><?= e((string) $check['hint']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
