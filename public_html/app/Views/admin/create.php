<?php
/**
 * Das Formular für eine neue Kundenwebsite.
 *
 * Die Reihenfolge folgt dem Gespräch mit dem Kunden: erst wer er ist,
 * dann was er schon hat, dann wie es aussehen soll, zuletzt das Technische.
 */

use WebAtze\Core\Csrf;
use WebAtze\Domain\Brief;

/** @var array $values   bereits eingetragene Werte (nach einem Fehler) */
/** @var array $errors   Fehlermeldungen je Feld */
/** @var array $providers */

$v = static fn (string $key, string $default = ''): string
    => (string) ($values[$key] ?? $default);

$checked = static fn (string $key, bool $default = false): string
    => (!empty($values[$key]) || (!array_key_exists($key, $values) && $default)) ? ' checked' : '';

$err = static function (string $key) use ($errors): string {
    if (!isset($errors[$key])) {
        return '';
    }
    return '<p class="wa-error" id="err-' . e($key) . '">' . e($errors[$key]) . '</p>';
};

$invalid = static fn (string $key): string
    => isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="err-' . e($key) . '"' : '';
?>

<?php if ($errors !== []): ?>
    <div class="wa-note wa-note--danger" role="alert">
        <div>
            <strong>Es fehlt noch etwas.</strong>
            Die markierten Felder unten brauchen eine Korrektur – alles andere bleibt erhalten.
        </div>
    </div>
<?php endif; ?>

<form class="wa-form" method="post" action="" enctype="multipart/form-data" novalidate>
    <?= Csrf::field() ?>

    <?php /* ================= Grunddaten ================= */ ?>
    <fieldset class="wa-fieldset">
        <legend class="wa-fieldset__legend">Über die Firma</legend>
        <p class="wa-fieldset__hint">
            Je genauer diese Angaben, desto besser passt die fertige Website.
            Vor allem der Beschreibungstext lohnt sich.
        </p>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="company_name">Firmenname <span aria-hidden="true">*</span></label>
                <input class="wa-input" type="text" id="company_name" name="company_name" required
                       maxlength="120" value="<?= e($v('company_name')) ?>"<?= $invalid('company_name') ?>>
                <?= $err('company_name') ?>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="slogan">
                    Slogan <span class="wa-label__hint">optional</span>
                </label>
                <input class="wa-input" type="text" id="slogan" name="slogan" maxlength="160"
                       placeholder="z.B. Handwerk mit Handschlagqualität"
                       value="<?= e($v('slogan')) ?>"<?= $invalid('slogan') ?>>
                <?= $err('slogan') ?>
            </div>
        </div>

        <div class="wa-field">
            <label class="wa-label" for="industry">Branche <span aria-hidden="true">*</span></label>
            <input class="wa-input" type="text" id="industry" name="industry" required maxlength="120"
                   placeholder="z.B. Schreinerei, Physiotherapie, Steuerberatung"
                   value="<?= e($v('industry')) ?>"<?= $invalid('industry') ?>>
            <?= $err('industry') ?>
        </div>

        <div class="wa-field">
            <label class="wa-label" for="description">
                Was macht die Firma genau? <span aria-hidden="true">*</span>
            </label>
            <textarea class="wa-textarea" id="description" name="description" required rows="6"
                      maxlength="4000"
                      placeholder="Was wird angeboten, was ist das Besondere, wie lange gibt es die Firma, was soll die Website erreichen?"
                      <?= $invalid('description') ?>><?= e($v('description')) ?></textarea>
            <?= $err('description') ?>
        </div>

        <div class="wa-field">
            <label class="wa-label" for="audience">
                Zielgruppe <span class="wa-label__hint">optional</span>
            </label>
            <textarea class="wa-textarea" id="audience" name="audience" rows="2" maxlength="600"
                      placeholder="Wer soll angesprochen werden? Privatkunden, Firmen, eine bestimmte Region?"
                      <?= $invalid('audience') ?>><?= e($v('audience')) ?></textarea>
            <?= $err('audience') ?>
        </div>
    </fieldset>

    <?php /* ================= Bestehende Website ================= */ ?>
    <fieldset class="wa-fieldset">
        <legend class="wa-fieldset__legend">Bestehende Website</legend>
        <p class="wa-fieldset__hint">
            Gibt es schon eine Website, wird sie <strong>komplett neu aufgebaut</strong>.
            Übernommen werden nur Texte und Bilder – nichts vom alten Aufbau.
        </p>

        <div class="wa-field">
            <label class="wa-label" for="old_url">
                Adresse der alten Website <span class="wa-label__hint">optional</span>
            </label>
            <input class="wa-input" type="text" id="old_url" name="old_url" maxlength="500"
                   placeholder="beispiel.ch" inputmode="url" spellcheck="false"
                   value="<?= e($v('old_url')) ?>"<?= $invalid('old_url') ?>>
            <?= $err('old_url') ?>
        </div>

        <div class="wa-stack wa-stack--sm">
            <label class="wa-checkbox">
                <input type="checkbox" name="take_texts" value="1"<?= $checked('take_texts', true) ?>>
                <span>Texte der alten Website übernehmen und überarbeiten</span>
            </label>
            <label class="wa-checkbox">
                <input type="checkbox" name="take_images" value="1"<?= $checked('take_images', true) ?>>
                <span>Bilder der alten Website übernehmen</span>
            </label>
        </div>
    </fieldset>

    <?php /* ================= Gestaltung ================= */ ?>
    <fieldset class="wa-fieldset">
        <legend class="wa-fieldset__legend">Aussehen</legend>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="style">Grundstimmung</label>
                <select class="wa-select" id="style" name="style">
                    <?php foreach (Brief::STYLES as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $v('style', 'clean') === $key ? ' selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="tone">Ansprache der Besucher</label>
                <select class="wa-select" id="tone" name="tone">
                    <?php foreach (Brief::TONES as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $v('tone', 'sie') === $key ? ' selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="wa-field">
            <label class="wa-label" for="color_mode">Farben</label>
            <select class="wa-select" id="color_mode" name="color_mode" data-toggles="#colour-picker" data-toggle-value="manual">
                <?php foreach (Brief::COLOR_MODES as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $v('color_mode', 'auto') === $key ? ' selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?= $err('color_mode') ?>
        </div>

        <div id="colour-picker" class="wa-colours"<?= $v('color_mode', 'auto') === 'manual' ? '' : ' hidden' ?>>
            <?php
            $colourFields = [
                'color_primary' => ['Hauptfarbe', '#2b1b9e', 'Buttons, Links, wichtige Flächen'],
                'color_secondary' => ['Zweitfarbe', '#17c8c8', 'Akzente, Verläufe, Grafik'],
                'color_accent' => ['Dunkelton', '#0a0a1f', 'Text und dunkle Flächen'],
            ];
            ?>
            <?php foreach ($colourFields as $name => [$label, $default, $purpose]): ?>
                <div class="wa-colour">
                    <label class="wa-label" for="<?= e($name) ?>"><?= e($label) ?></label>
                    <div class="wa-colour__row">
                        <input type="color" class="wa-colour__swatch" id="<?= e($name) ?>_pick"
                               value="<?= e($v($name, $default)) ?>"
                               aria-label="<?= e($label) ?> auswählen"
                               data-colour-for="<?= e($name) ?>">
                        <input class="wa-input wa-colour__hex" type="text" id="<?= e($name) ?>"
                               name="<?= e($name) ?>" maxlength="7" spellcheck="false"
                               placeholder="<?= e($default) ?>"
                               value="<?= e($v($name, $default)) ?>"<?= $invalid($name) ?>>
                    </div>
                    <p class="wa-colour__purpose"><?= e($purpose) ?></p>
                    <?= $err($name) ?>
                </div>
            <?php endforeach; ?>

            <p class="wa-colour__note" data-colour-warning hidden></p>
        </div>

        <div class="wa-field">
            <label class="wa-label" for="design_notes">
                Wie soll es wirken? <span class="wa-label__hint">optional, aber sehr hilfreich</span>
            </label>
            <textarea class="wa-textarea" id="design_notes" name="design_notes" rows="5" maxlength="4000"
                      placeholder="Beschreibe das Design in eigenen Worten. Gibt es Websites, die dir gefallen? Soll es ruhig oder auffällig sein, viel Bild oder viel Text, hell oder dunkel?"
                      <?= $invalid('design_notes') ?>><?= e($v('design_notes')) ?></textarea>
            <?= $err('design_notes') ?>
        </div>

        <div class="wa-field">
            <label class="wa-label" for="logo">
                Logo <span class="wa-label__hint">optional – PNG, JPG oder SVG, höchstens 4 MB</span>
            </label>
            <input class="wa-input" type="file" id="logo" name="logo"
                   accept="image/png,image/jpeg,image/svg+xml,image/webp">
            <p class="wa-fieldset__hint">
                Ohne Logo wird eines aus der alten Website übernommen. Gibt es auch das nicht,
                entsteht ein sauberer Schriftzug als Platzhalter, den du später austauschen kannst.
            </p>
            <?= $err('logo') ?>
        </div>
    </fieldset>

    <?php /* ================= Umfang ================= */ ?>
    <fieldset class="wa-fieldset">
        <legend class="wa-fieldset__legend">Umfang und Sprache</legend>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="scope">Wie viele Seiten?</label>
                <select class="wa-select" id="scope" name="scope">
                    <?php foreach (Brief::SCOPES as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $v('scope', 'small') === $key ? ' selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="locales">Sprachen</label>
                <select class="wa-select" id="locales" name="locales">
                    <?php foreach (Brief::LOCALES as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $v('locales', 'de') === $key ? ' selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="wa-field">
            <label class="wa-label" for="wanted_pages">
                Bestimmte Seiten gewünscht? <span class="wa-label__hint">optional</span>
            </label>
            <textarea class="wa-textarea" id="wanted_pages" name="wanted_pages" rows="3" maxlength="800"
                      placeholder="z.B. Start, Leistungen, Über uns, Galerie, Kontakt – sonst wird passend zur Branche entschieden."
            ><?= e($v('wanted_pages')) ?></textarea>
        </div>
    </fieldset>

    <?php /* ================= Kontaktangaben ================= */ ?>
    <fieldset class="wa-fieldset">
        <legend class="wa-fieldset__legend">Angaben für die neue Website</legend>
        <p class="wa-fieldset__hint">
            Diese Angaben landen im Kontaktbereich, im Fuss und im Impressum.
        </p>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="contact_email">E-Mail</label>
                <input class="wa-input" type="email" id="contact_email" name="contact_email" maxlength="190"
                       value="<?= e($v('contact_email')) ?>"<?= $invalid('contact_email') ?>>
                <?= $err('contact_email') ?>
            </div>
            <div class="wa-field">
                <label class="wa-label" for="contact_phone">Telefon</label>
                <input class="wa-input" type="tel" id="contact_phone" name="contact_phone" maxlength="60"
                       value="<?= e($v('contact_phone')) ?>"<?= $invalid('contact_phone') ?>>
                <?= $err('contact_phone') ?>
            </div>
        </div>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="contact_address">Adresse</label>
                <textarea class="wa-textarea" id="contact_address" name="contact_address" rows="3" maxlength="300"
                          placeholder="Strasse Nr.&#10;PLZ Ort"
                          <?= $invalid('contact_address') ?>><?= e($v('contact_address')) ?></textarea>
                <?= $err('contact_address') ?>
            </div>
            <div class="wa-field">
                <label class="wa-label" for="opening_hours">Öffnungszeiten</label>
                <textarea class="wa-textarea" id="opening_hours" name="opening_hours" rows="3" maxlength="600"
                          placeholder="Mo–Fr 08:00–17:00&#10;Sa nach Vereinbarung"
                ><?= e($v('opening_hours')) ?></textarea>
            </div>
        </div>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="social_instagram">Instagram</label>
                <input class="wa-input" type="text" id="social_instagram" name="social_instagram"
                       maxlength="190" placeholder="@benutzername oder Adresse"
                       value="<?= e($v('social_instagram')) ?>">
            </div>
            <div class="wa-field">
                <label class="wa-label" for="social_facebook">Facebook</label>
                <input class="wa-input" type="text" id="social_facebook" name="social_facebook"
                       maxlength="190" value="<?= e($v('social_facebook')) ?>">
            </div>
            <div class="wa-field">
                <label class="wa-label" for="social_linkedin">LinkedIn</label>
                <input class="wa-input" type="text" id="social_linkedin" name="social_linkedin"
                       maxlength="190" value="<?= e($v('social_linkedin')) ?>">
            </div>
        </div>
    </fieldset>

    <?php /* ================= Kundenbackend ================= */ ?>
    <fieldset class="wa-fieldset">
        <legend class="wa-fieldset__legend">Bearbeitungsbereich für den Kunden</legend>
        <p class="wa-fieldset__hint">
            Mit Bearbeitungsbereich kann der Kunde Texte, Bilder und ganze Abschnitte
            selbst ändern. Er wird zusammen mit der Website ausgeliefert und
            übernimmt automatisch deren Gestaltung.
        </p>

        <label class="wa-checkbox">
            <input type="checkbox" name="wants_admin" value="1" id="wants_admin"
                   data-toggles="#admin-credentials"<?= $checked('wants_admin') ?>>
            <span>Ja, der Kunde bekommt einen eigenen Bearbeitungsbereich</span>
        </label>

        <div id="admin-credentials" class="wa-grid-2"<?= $v('wants_admin') !== '' && !empty($values['wants_admin']) ? '' : ' hidden' ?>>
            <div class="wa-field">
                <label class="wa-label" for="admin_username">Benutzername für den Kunden</label>
                <input class="wa-input" type="text" id="admin_username" name="admin_username"
                       maxlength="64" autocomplete="off" spellcheck="false"
                       value="<?= e($v('admin_username')) ?>"<?= $invalid('admin_username') ?>>
                <?= $err('admin_username') ?>
            </div>
            <div class="wa-field">
                <label class="wa-label" for="admin_password">
                    Passwort <span class="wa-label__hint">mindestens 10 Zeichen</span>
                </label>
                <input class="wa-input" type="text" id="admin_password" name="admin_password"
                       maxlength="200" autocomplete="off" spellcheck="false"
                       value="<?= e($v('admin_password')) ?>"<?= $invalid('admin_password') ?>>
                <button type="button" class="wa-btn wa-btn--quiet wa-btn--sm" data-generate-password="#admin_password">
                    Sicheres Passwort erzeugen
                </button>
                <?= $err('admin_password') ?>
                <p class="wa-fieldset__hint">
                    Sichtbar dargestellt, damit du es dem Kunden weitergeben kannst.
                    Gespeichert wird es nur als nicht rückrechenbarer Prüfwert.
                </p>
            </div>
        </div>
    </fieldset>

    <?php /* ================= Zusatzdienste ================= */ ?>
    <fieldset class="wa-fieldset">
        <legend class="wa-fieldset__legend">Was zusätzlich mitgeliefert wird</legend>
        <p class="wa-fieldset__hint">
            Nichts davon ist eingeschaltet, solange du es nicht anhakst. Was nicht
            angehakt ist, wird auch nicht mitgeliefert – keine Datei, keine Zeile
            im Quelltext. Was angehakt ist, steht im Auftrag mit allen echten
            Werten und läuft ohne einen Handgriff.
        </p>

        <label class="wa-checkbox">
            <input type="checkbox" name="wants_stats" value="1" id="wants_stats"
                   data-toggles="#stats-report"<?= $checked('wants_stats') ?>>
            <span>Besucher zählen</span>
        </label>
        <p class="wa-fieldset__hint">
            Die Zählzeile kommt in den Kopfbereich jeder Seite; die Website
            erscheint danach von selbst unter «Besucher». Gespeichert werden Tag,
            Seite und Herkunft – keine Adressen, keine Kennung über den Tag hinaus.
            Deshalb braucht die Website dafür keinen Zustimmungsbanner.
        </p>

        <div id="stats-report" class="wa-field"<?= !empty($values['wants_stats']) ? '' : ' hidden' ?>>
            <label class="wa-label" for="report_email">
                Wochenbericht an
                <span class="wa-label__hint">freiwillig – leer heisst: kein Bericht</span>
            </label>
            <input class="wa-input" type="email" id="report_email" name="report_email"
                   maxlength="190" autocomplete="off" spellcheck="false"
                   placeholder="kunde@beispiel.ch"
                   value="<?= e($v('report_email')) ?>"<?= $invalid('report_email') ?>>
            <?= $err('report_email') ?>
            <?= $err('wants_stats') ?>
            <p class="wa-fieldset__hint">
                Jeden Montag eine schlichte E-Mail: Aufrufe, Besucher, die
                meistbesuchten Seiten und woher die Leute kamen – im Vergleich
                zur Vorwoche.
            </p>
        </div>

        <label class="wa-checkbox">
            <input type="checkbox" name="wants_docs" value="1" id="wants_docs"<?= $checked('wants_docs') ?>>
            <span>Anleitung unter <code>/doc</code></span>
        </label>
        <p class="wa-fieldset__hint">
            Erklärt dem Kunden in einfacher Sprache, wie seine Website funktioniert:
            welche Seite wofür da ist, wohin die Anfragen gehen, was er noch liefern
            muss. Trägt <code>noindex</code> und steht nicht in der sitemap.
        </p>

        <label class="wa-checkbox">
            <input type="checkbox" name="wants_support" value="1" id="wants_support"<?= $checked('wants_support') ?>>
            <span>Hilfeseite unter <code>/support</code></span>
        </label>
        <p class="wa-fieldset__hint">
            Der Kunde schreibt dir dort; die Frage erscheint bei dir unter «Support».
            Hinter einem Zugangscode, mit begrenzten Versuchen, Honigtopf und
            Zeitfalle – nur der Kunde kommt durch, kein Werbeprogramm.
        </p>

        <?php /*
            Der Unterschied zu frueher liegt nicht im Haken, sondern
            darin, was dahinter passiert. Wer hier etwas anhakt, bekommt
            im Auftrag Adresse, Schluessel und Zugangscode fertig
            eingetragen - danach ist nichts mehr einzurichten.
        */ ?>
        <div class="wa-note">
            <div>
                <strong>Was du anhakst, steht fertig verdrahtet im Auftrag.</strong>
                <p class="wa-fieldset__hint">
                    Adresse, Schlüssel, Zugangscode und Zählzeile werden eingesetzt,
                    nicht beschrieben. Auf der fertigen Website ist danach nichts
                    einzurichten und nichts von Hand einzufügen – auch dann nicht,
                    wenn du den Auftragstext selbst irgendwo einfügst.
                </p>
            </div>
        </div>
    </fieldset>

    <?php /* ================= Domain und Veröffentlichung ================= */ ?>
    <fieldset class="wa-fieldset">
        <legend class="wa-fieldset__legend">Domain und Veröffentlichung</legend>
        <p class="wa-fieldset__hint">
            Alles hier ist freiwillig. Das fertige Paket bekommst du in jedem Fall
            als ZIP – auch ohne Domain und ohne Zugangsdaten.
        </p>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="domain">Domain</label>
                <input class="wa-input" type="text" id="domain" name="domain" maxlength="253"
                       placeholder="beispiel.ch" spellcheck="false" inputmode="url"
                       value="<?= e($v('domain')) ?>"<?= $invalid('domain') ?>>
                <?= $err('domain') ?>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="domain_mode">Was soll mit der Domain passieren?</label>
                <select class="wa-select" id="domain_mode" name="domain_mode" data-toggles="#registrar-row" data-toggle-not="none">
                    <option value="none"<?= $v('domain_mode', 'none') === 'none' ? ' selected' : '' ?>>
                        Nichts – bleibt wie sie ist
                    </option>
                    <option value="point"<?= $v('domain_mode') === 'point' ? ' selected' : '' ?>>
                        Nur umzeigen (Domain bleibt beim Anbieter)
                    </option>
                    <option value="transfer"<?= $v('domain_mode') === 'transfer' ? ' selected' : '' ?>>
                        Umziehen (Domain wechselt den Anbieter)
                    </option>
                </select>
            </div>
        </div>

        <div id="registrar-row" class="wa-field"<?= in_array($v('domain_mode', 'none'), ['point', 'transfer'], true) ? '' : ' hidden' ?>>
            <label class="wa-label" for="registrar">Wo liegt die Domain heute?</label>
            <select class="wa-select" id="registrar" name="registrar">
                <?php foreach ($providers['registrar'] as $key => $info): ?>
                    <option value="<?= e($key) ?>"<?= $v('registrar', 'other') === $key ? ' selected' : '' ?>>
                        <?= e($info['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="wa-fieldset__hint">
                Nach dem Erstellen führt dich ein Assistent Schritt für Schritt durch den Umzug
                und prüft automatisch, ob er geklappt hat.
            </p>
        </div>

        <hr class="wa-rule">

        <div class="wa-field">
            <label class="wa-label" for="hosting_provider">Wo soll die Website liegen?</label>
            <select class="wa-select" id="hosting_provider" name="hosting_provider" data-hosting-select>
                <?php foreach ($providers['hosting'] as $key => $info): ?>
                    <option value="<?= e($key) ?>"
                            data-protocol="<?= e($info['protocol']) ?>"
                            data-port="<?= e((string) $info['port']) ?>"
                            data-path="<?= e($info['path']) ?>"
                            <?= $v('hosting_provider', 'other') === $key ? ' selected' : '' ?>>
                        <?= e($info['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="ftp_protocol">Übertragungsart</label>
                <select class="wa-select" id="ftp_protocol" name="ftp_protocol">
                    <?php foreach (Brief::PROTOCOLS as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $v('ftp_protocol', 'ftp') === $key ? ' selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?= $err('ftp_protocol') ?>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="ftp_host">Server</label>
                <input class="wa-input" type="text" id="ftp_host" name="ftp_host" maxlength="190"
                       placeholder="domain.com" spellcheck="false" autocomplete="off"
                       value="<?= e($v('ftp_host')) ?>"<?= $invalid('ftp_host') ?>>
                <?= $err('ftp_host') ?>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="ftp_port">Port</label>
                <input class="wa-input" type="number" id="ftp_port" name="ftp_port" min="1" max="65535"
                       value="<?= e($v('ftp_port', '21')) ?>"<?= $invalid('ftp_port') ?>>
                <?= $err('ftp_port') ?>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="ftp_username">Benutzername</label>
                <input class="wa-input" type="text" id="ftp_username" name="ftp_username" maxlength="190"
                       placeholder="benutzer@domain.com" autocomplete="off" spellcheck="false"
                       value="<?= e($v('ftp_username')) ?>"<?= $invalid('ftp_username') ?>>
                <?= $err('ftp_username') ?>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="ftp_password">Passwort</label>
                <input class="wa-input" type="password" id="ftp_password" name="ftp_password"
                       maxlength="200" autocomplete="new-password"<?= $invalid('ftp_password') ?>>
                <?= $err('ftp_password') ?>
                <p class="wa-fieldset__hint">Wird verschlüsselt gespeichert.</p>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="ftp_path">Zielverzeichnis</label>
                <input class="wa-input" type="text" id="ftp_path" name="ftp_path" maxlength="255"
                       placeholder="/" spellcheck="false"
                       value="<?= e($v('ftp_path', '/')) ?>"<?= $invalid('ftp_path') ?>>
                <?= $err('ftp_path') ?>
            </div>
        </div>
    </fieldset>

    <?php /* ================= Zusätzliches ================= */ ?>
    <fieldset class="wa-fieldset">
        <legend class="wa-fieldset__legend">Zusätzliche Informationen</legend>
        <p class="wa-fieldset__hint">
            Alles, was sonst nirgends hinpasst. <strong>Wichtig:</strong> Soll die Website mehr
            können als zeigen – etwa Termine buchen oder Reservationen annehmen – dann
            beschreibe das hier. Nur dann entsteht ein Verwaltungssystem. Ohne solche
            Angabe bleibt die Website bewusst rein statisch: schneller, sicherer und
            günstiger im Betrieb.
        </p>
        <p class="wa-fieldset__hint">
            Steht hier etwas von Terminen oder Buchungen, entsteht unter
            <code>/buchen</code> eine Terminseite und im Bearbeitungsbereich des Kunden
            eine Übersicht dazu. Die Öffnungszeiten von oben werden übernommen, soweit
            sie sich lesen lassen; der Kunde stellt sie danach selbst um. Nenne die
            Leistungen mit ihrer Dauer, dann stehen sie gleich richtig darin –
            etwa <em>Haarschnitt, 45 Min</em>, eine pro Zeile.
        </p>
        <p class="wa-fieldset__hint">
            Eine Datenbank braucht auch das nicht: Die Buchungen liegen als Datei neben
            der Website, wie die Anfragen aus dem Kontaktformular. Für einen Betrieb mit
            ein paar Terminen am Tag ist das nicht die schlechtere Lösung, sondern die
            richtige – nichts einzurichten, nichts, das ausfallen kann.
        </p>

        <div class="wa-field">
            <label class="wa-sr" for="extra_notes">Zusätzliche Informationen</label>
            <textarea class="wa-textarea" id="extra_notes" name="extra_notes" rows="8" maxlength="8000"
                      placeholder="Beispiel: Kunden sollen online einen Termin buchen können. Es gibt drei Behandlungsarten mit unterschiedlicher Dauer. Ich brauche eine Übersicht aller Buchungen und eine E-Mail bei jeder neuen Buchung."
                      <?= $invalid('extra_notes') ?>><?= e($v('extra_notes')) ?></textarea>
            <?= $err('extra_notes') ?>
        </div>
    </fieldset>

    <div class="wa-form__actions">
        <button type="submit" class="wa-btn wa-btn--primary wa-btn--lg">
            Website erstellen
        </button>
        <p class="wa-fieldset__hint" style="margin: 0">
            Das Erstellen läuft im Hintergrund. Du siehst den Fortschritt und kannst die
            Seite dabei offen lassen.
        </p>
    </div>
</form>
