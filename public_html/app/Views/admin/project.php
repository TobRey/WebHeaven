<?php
/**
 * Ein einzelnes Projekt.
 *
 * Die Seite beantwortet drei Fragen in dieser Reihenfolge: Läuft gerade
 * etwas? Wie sieht es aus? Und was wurde ursprünglich bestellt.
 */

use WebAtze\Core\{Config, Csrf};
use WebAtze\Domain\Brief;
use WebAtze\Templates\{Catalog, Schema};

/** @var array $project @var array $brief @var array $theme @var array|null $job */
/** @var array $pages @var array|null $target @var array|null $transfer */
/** @var int $cost @var string $previewUrl */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
$id = (int) $project['id'];

$statusLabels = [
    'draft' => ['Entwurf', 'new'],
    'building' => ['Wird gebaut', 'running'],
    'ready' => ['Fertig', 'done'],
    'live' => ['Online', 'done'],
    'failed' => ['Fehlgeschlagen', 'failed'],
];
[$statusLabel, $statusTone] = $statusLabels[(string) $project['status']]
    ?? [(string) $project['status'], 'waiting'];

/* Nur die Auftragsarten, die es noch gibt. "deploy" und "zip" standen
   hier, nachdem beide gestrichen waren - eine Beschriftung fuer etwas,
   das nie mehr auftaucht. */
$jobLabels = [
    'generate' => 'Website wird gebaut',
    'rebuild' => 'Website wird neu gebaut',
    'zip-uebernehmen' => 'Das Archiv wird ausgepackt',
];

$sectionCount = 0;
foreach ($pages as $page) {
    $sectionCount += count($page['sections'] ?? []);
}

$colours = $theme['colors'] ?? [];
$staende = \WebAtze\Build\Staende::liste($id);
?>

<div class="wa-page-head">
    <div>
        <span class="wa-badge wa-badge--<?= e($statusTone) ?>"><?= e($statusLabel) ?></span>
        <?php if ((string) $project['domain'] !== ''): ?>
            <span class="wa-page-head__meta"><?= e((string) $project['domain']) ?></span>
        <?php endif; ?>
        <span class="wa-page-head__meta">
            angelegt am <?= e(date('d.m.Y', strtotime((string) $project['created_at']))) ?>
        </span>
    </div>
    <div class="wa-page-head__actions">
        <a class="wa-btn wa-btn--primary wa-btn--sm"
           href="<?= e($base) ?>/editor/<?= $id ?>">Editor öffnen</a>

        <?php if ($previewUrl !== ''): ?>
            <a class="wa-btn wa-btn--sm" href="<?= e($previewUrl) ?>" target="_blank" rel="noopener">
                Vorschau öffnen
            </a>
        <?php endif; ?>
        <a class="wa-btn wa-btn--sm" href="<?= e($base) ?>/projekt/<?= $id ?>/veroeffentlichen">Veröffentlichen</a>
        <a class="wa-btn wa-btn--sm" href="<?= e($base) ?>/projekt/<?= $id ?>/domain">Domain</a>
    </div>
</div>

<?php /* -------------------------------------------------- Laufender Auftrag */ ?>
<?php if ($job !== null): ?>
    <section class="wa-panel wa-panel--accent">
    <h2 class="wa-panel__title">Auftrag zum Kopieren</h2>
    <p class="wa-panel__hint">
        Der fertige Text für Claude Code &ndash; alles aus dem Formular darin.
    </p>
    <a class="wa-btn wa-btn--primary" href="<?= e($base) ?>/projekt/<?= $id ?>/auftrag">
        Auftrag anzeigen
    </a>
</section>

<section class="wa-panel">
        <div class="wa-panel__head">
            <h2 class="wa-panel__title">
                <?= (string) $job['status'] === 'failed' ? 'Abgebrochen' : 'Läuft gerade' ?>
            </h2>
        </div>
        <div class="wa-job" data-job-watch="<?= (int) $job['id'] ?>"
             data-job-reload="1">
            <div class="wa-job__row">
                <strong><?= e($jobLabels[(string) $job['type']] ?? (string) $job['type']) ?></strong>
                <span class="wa-job__value" data-job-label><?= (int) $job['progress'] ?>%</span>
            </div>
            <div class="wa-progress">
                <div class="wa-progress__bar" data-job-bar style="--value: <?= (int) $job['progress'] ?>%"></div>
            </div>
            <div class="wa-job__row">
                <span class="wa-job__step" data-job-step><?= e((string) $job['message']) ?></span>
                <span class="wa-job__puls" data-job-puls data-state="lebt">arbeitet …</span>
                <div class="wa-panel__actions">
                    <?php /* Der Zwischenstand bleibt erhalten. Wer eine fehlende
                             Einstellung nachträgt, macht dort weiter, wo es
                             geklemmt hat – schon geschriebene Texte werden nicht
                             ein zweites Mal bezahlt. */ ?>
                    <button type="button" class="wa-btn wa-btn--sm" data-job-resume
                            <?= (string) $job['status'] === 'failed' ? '' : 'hidden' ?>>
                        Fortsetzen
                    </button>
                    <form method="post" action="/api/jobs/<?= (int) $job['id'] ?>/abbrechen"
                          data-confirm="Diesen Auftrag wirklich abbrechen?">
                        <?= Csrf::field() ?>
                        <button type="submit" class="wa-btn wa-btn--quiet wa-btn--sm">Abbrechen</button>
                    </form>
                </div>
            </div>
        </div>
        <?php if ((string) ($job['error'] ?? '') !== ''): ?>
            <div class="wa-note wa-note--danger">
                <div>
                    <?= e((string) $job['error']) ?>
                    <?php if (str_contains((string) $job['error'], 'Arbeitsbereich')): ?>
                        <br>
                        <a href="<?= e($base) ?>/einstellungen#anthropic_workspace_id">
                            Zu den Einstellungen
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php /* ------------------------------------------------------ Kurzübersicht */ ?>
<div class="wa-stats">
    <div class="wa-stat">
        <span class="wa-stat__value"><?= count($pages) ?></span>
        <span class="wa-stat__label"><?= count($pages) === 1 ? 'Seite' : 'Seiten' ?></span>
    </div>
    <div class="wa-stat">
        <span class="wa-stat__value"><?= $sectionCount ?></span>
        <span class="wa-stat__label">Abschnitte</span>
    </div>
    <div class="wa-stat">
        <span class="wa-stat__value"><?= count($staende) ?: '–' ?></span>
        <span class="wa-stat__label">Stände</span>
    </div>
    <div class="wa-stat">
        <span class="wa-stat__value">$<?= number_format($cost / 1e6, 2) ?></span>
        <span class="wa-stat__label">KI-Kosten</span>
    </div>
</div>

<?php /* -------------------------------------------------------------- Seiten */ ?>
<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Aufbau der Website</h2>
        <p class="wa-panel__hint">
            Jeder Abschnitt lässt sich in der Vorschau anklicken und dort einzeln ändern –
            am Text, am Bild oder über eine der 20 Vorlagen.
        </p>
    </div>

    <?php if ($pages === []): ?>
        <div class="wa-empty-state">
            <p>Noch keine Seiten. Sie entstehen, sobald der Auftrag durchgelaufen ist.</p>
        </div>
    <?php else: ?>
        <div class="wa-pagelist">
            <?php foreach ($pages as $page): ?>
                <details class="wa-pagelist__item"<?= (int) $page['sort_order'] === 0 ? ' open' : '' ?>>
                    <summary>
                        <strong><?= e((string) $page['title']) ?></strong>
                        <code><?= e((string) $page['path']) ?></code>
                        <span class="wa-pagelist__count">
                            <?= count($page['sections']) ?> Abschnitte
                        </span>
                    </summary>
                    <ol class="wa-sectionlist">
                        <?php foreach ($page['sections'] as $section): ?>
                            <?php
                            $type = (string) $section['type'];
                            $meta = Schema::forType($type);
                            ?>
                            <li class="wa-sectionlist__row<?= ((int) $section['hidden'] === 1) ? ' is-hidden' : '' ?>">
                                <span class="wa-sectionlist__type"><?= e($meta['label'] ?? $type) ?></span>
                                <span class="wa-sectionlist__variant">
                                    <?= e(Catalog::label($type, (string) $section['template_key'])) ?>
                                </span>
                                <?php if ((int) $section['hidden'] === 1): ?>
                                    <span class="wa-badge wa-badge--waiting">ausgeblendet</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php /* ---------------------------------------------------- Herunterladen */ ?>
<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Website herunterladen</h2>
        <p class="wa-panel__hint">
            Ein ZIP mit dem Stand, der jetzt hier liegt &ndash; in diesem Augenblick
            gepackt. Damit lädst du die Website mit deinem FTP-Programm beim Kunden
            hinauf.
        </p>
    </div>

    <?php
    /* Hier stand eine Tabelle mit Paketen und Versionsnummern. Sie war
       genau der Fehler: Der Knopf zeigte auf ein Archiv, das dalag,
       nicht auf das, was auf dem Bildschirm stand - und nach einer
       Uebernahme war das ausgerechnet die unbearbeitete Fassung. Jetzt
       gibt es einen Knopf, und der packt frisch. */
    ?>
    <div class="wa-form__actions">
        <a class="wa-btn wa-btn--primary" href="<?= e($base) ?>/projekt/<?= $id ?>/stand">
            Website herunterladen
        </a>
    </div>
</section>

<?php /* ------------------------------------------------------------ Bestellt */ ?>
<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Was bestellt wurde</h2>
        <p class="wa-panel__hint">Die Angaben aus dem Formular. Zugangsdaten stehen hier bewusst nicht.</p>
    </div>

    <?php if ($colours !== []): ?>
        <div class="wa-swatches">
            <?php /* Die dritte Wunschfarbe wird im Theme zum Grundton. */ ?>
            <?php foreach (['primary' => 'Hauptfarbe', 'secondary' => 'Zweitfarbe', 'ink' => 'Grundton'] as $key => $label): ?>
                <?php if (!isset($colours[$key])) { continue; } ?>
                <div class="wa-swatch">
                    <span class="wa-swatch__chip" style="background: <?= e((string) $colours[$key]) ?>"></span>
                    <span class="wa-swatch__label"><?= e($label) ?></span>
                    <code><?= e((string) $colours[$key]) ?></code>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <dl class="wa-facts">
        <?php
        $facts = [
            'Slogan' => (string) ($brief['slogan'] ?? ''),
            'Branche' => (string) ($brief['industry'] ?? ''),
            'Was die Firma macht' => (string) ($brief['description'] ?? ''),
            'Zielgruppe' => (string) ($brief['audience'] ?? ''),
            'Stil' => Brief::STYLES[$brief['style'] ?? ''] ?? '',
            'Ansprache' => Brief::TONES[$brief['tone'] ?? ''] ?? '',
            'Designwünsche' => (string) ($brief['design_notes'] ?? ''),
            'Umfang' => Brief::SCOPES[$brief['scope'] ?? ''] ?? '',
            'Alte Website' => (string) ($brief['old_url'] ?? ''),
            'Gewünschte Seiten' => (string) ($brief['wanted_pages'] ?? ''),
            'E-Mail' => (string) ($brief['contact_email'] ?? ''),
            'Telefon' => (string) ($brief['contact_phone'] ?? ''),
            'Adresse' => (string) ($brief['contact_address'] ?? ''),
            'Öffnungszeiten' => (string) ($brief['opening_hours'] ?? ''),
            'Zusatzinfos' => (string) ($brief['extra_notes'] ?? ''),
        ];
        foreach ($facts as $label => $value):
            if (trim($value) === '') { continue; }
        ?>
            <dt><?= e($label) ?></dt>
            <dd><?= nl2br(e($value)) ?></dd>
        <?php endforeach; ?>

        <dt>Kundenbackend</dt>
        <dd>
            <?php if ((int) $project['wants_admin'] === 1): ?>
                ja, Zugang <code><?= e((string) $project['admin_username']) ?></code>
                (das Passwort ist nur beim Kunden bekannt)
            <?php else: ?>
                nein – die Website ist rein statisch
            <?php endif; ?>
        </dd>

        <dt>Datenbank</dt>
        <dd>
            <?= Brief::needsDatabase($brief)
                ? 'ja – die Zusatzinfos beschreiben etwas, das gespeichert werden muss'
                : 'nein – Inhalte liegen als Dateien, das ist schneller und sicherer' ?>
        </dd>

        <?php
        // Was zusätzlich mitgeliefert wurde. Die Adressen stehen nur da,
        // wenn eine Domain hinterlegt ist – sonst zeigen sie ins Leere.
        $domain = trim((string) $project['domain']);
        $siteUrl = $domain !== '' ? 'https://' . preg_replace('~^https?://~i', '', $domain) : '';
        ?>

        <dt>Zusätzlich mitgeliefert</dt>
        <dd>
            <?php
            $extras = [];
            if (!empty($brief['wants_stats'])) {
                $extras[] = 'Besucherzählung'
                    . ((string) $project['report_email'] !== ''
                        ? ' mit Wochenbericht an ' . e((string) $project['report_email'])
                        : ' ohne Wochenbericht');
            }
            if (!empty($brief['wants_support'])) {
                $extras[] = $siteUrl !== ''
                    ? 'Hilfeseite unter <a href="' . e($siteUrl) . '/support" rel="noopener noreferrer" target="_blank">'
                      . e($domain) . '/support</a>'
                    : 'Hilfeseite unter /support';
            }
            if (!empty($brief['wants_docs'])) {
                $extras[] = $siteUrl !== ''
                    ? 'Anleitung unter <a href="' . e($siteUrl) . '/doc" rel="noopener noreferrer" target="_blank">'
                      . e($domain) . '/doc</a>'
                    : 'Anleitung unter /doc';
            }
            echo $extras === []
                ? 'nichts – weder Zählung noch Hilfeseite noch Anleitung'
                : implode('<br>', $extras);
            ?>
        </dd>

        <?php /*
            Der Code vor der Hilfeseite. Er steht hier und nirgends
            sonst - der Kunde bekommt ihn von mir, nicht aus einer
            E-Mail, die durch drei Postfaecher gelaufen ist.
        */ ?>
        <?php if (!empty($brief['wants_support'])): ?>
            <dt>Zugangscode für /support</dt>
            <dd>
                <code><?= e(\WebAtze\Domain\Websites::supportCode($id)) ?></code>
                <p class="wa-hint">
                    Nur der Kunde bekommt ihn. Ohne ihn zeigt die Hilfeseite kein Formular –
                    so kann kein Werbeprogramm darüber schreiben.
                </p>
            </dd>
        <?php endif; ?>

        <?php if (!empty($brief['wants_stats'])): ?>
            <dt>Zählzeile</dt>
            <dd>
                <code>&lt;script defer src="<?= e(rtrim((string) Config::get('app_url', ''), '/')) ?>/z.js?k=<?= e(\WebAtze\Domain\Visits::key($id)) ?>"&gt;&lt;/script&gt;</code>
                <p class="wa-hint">
                    Steht im Auftrag und kommt beim Bauen auf jede Seite. Nur falls du sie
                    irgendwo von Hand brauchst.
                </p>
            </dd>
        <?php endif; ?>
    </dl>

    <?php /*
        Der Schluessel steht im Auftragstext, damit auf der Kundenwebsite
        nichts einzurichten ist. Ein Auftragstext wird kopiert und
        weitergereicht - also braucht es einen Weg, ihn zurueckzunehmen.
    */ ?>
    <?php if (!empty($brief['wants_support']) || !empty($brief['wants_stats'])): ?>
    <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/support-schluessel"
          data-confirm="Neuen Schlüssel und neuen Zugangscode erzeugen? Die bisherige Hilfeseite dieser Website funktioniert danach nicht mehr, bis sie neu gebaut ist.">
        <?= Csrf::field() ?>
        <button type="submit" class="wa-btn wa-btn--quiet wa-btn--sm">
            Schlüssel und Code neu erzeugen
        </button>
    </form>
    <?php endif; ?>
</section>

<?php /* ----------------------------------------------------- Prüfungen */ ?>
<?php if (!empty($checks)): ?>
    <?php
    $namen = [
        'tempo' => 'Tempo',
        'verweise' => 'Verweise',
        'recht' => 'Rechtliches',
        'text' => 'Rechtschreibung',
        'editor' => 'Im Editor bearbeitbar',
    ];
    $stufe = [
        'ok' => ['Passt', 'wa-badge--done'],
        'info' => ['Hinweis', ''],
        'warn' => ['Ansehen', 'wa-badge--running'],
        'error' => ['Muss geändert werden', 'wa-badge--failed'],
    ];
    ?>
    <section class="wa-panel">
        <div class="wa-panel__head">
            <h2 class="wa-panel__title">Nach dem Bauen geprüft</h2>
            <p class="wa-panel__hint">
                Geprüft wurde die fertige Website, nicht der Bauplan. Was hier steht,
                ist tatsächlich so herausgekommen.
            </p>
        </div>

        <?php foreach ($checks as $check): ?>
            <?php
            $kind = (string) $check['kind'];
            $findings = (array) $check['findings'];
            ?>
            <div class="wa-check">
                <h3 class="wa-check__title">
                    <?= e($namen[$kind] ?? ucfirst($kind)) ?>
                    <span class="wa-badge <?= (int) $check['passed'] === 1 ? 'wa-badge--done' : 'wa-badge--running' ?>">
                        <?= (int) $check['score'] ?> von 100
                    </span>
                </h3>
                <ul class="wa-check__list">
                    <?php foreach ($findings as $finding): ?>
                        <?php $level = (string) ($finding['level'] ?? 'info'); ?>
                        <li>
                            <span class="wa-badge <?= e($stufe[$level][1] ?? '') ?>">
                                <?= e($stufe[$level][0] ?? 'Hinweis') ?>
                            </span>
                            <?= e((string) ($finding['text'] ?? '')) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>

        <p class="wa-panel__body">
            Das Rechtliche ist eine Prüfung auf Vollständigkeit, keine Rechtsberatung:
            Ob die Pflichtseiten da sind, ob sie mehr als eine Überschrift enthalten
            und ob die Seite ungefragt jemanden Dritten einbindet. Was drinsteht,
            muss ein Mensch verantworten.
        </p>
    </section>
<?php endif; ?>

<?php /* ----------------------------------------------------- Kurzer Ausblick */ ?>
<div class="wa-grid-2">
    <section class="wa-panel">
        <div class="wa-panel__head"><h2 class="wa-panel__title">Hochladen</h2></div>
        <?php if ($target !== null): ?>
            <p class="wa-panel__hint">
                <?= e(strtoupper((string) $target['protocol'])) ?> auf
                <code><?= e((string) $target['host']) ?></code>,
                Verzeichnis <code><?= e((string) $target['remote_path']) ?></code>.
                <?php if ((string) ($target['last_deployed_at'] ?? '') !== ''): ?>
                    Zuletzt hochgeladen am
                    <?= e(date('d.m.Y H:i', strtotime((string) $target['last_deployed_at']))) ?>.
                <?php endif; ?>
            </p>
        <?php else: ?>
            <p class="wa-panel__hint">Noch kein Zugang hinterlegt.</p>
        <?php endif; ?>
        <a class="wa-btn wa-btn--sm" href="<?= e($base) ?>/projekt/<?= $id ?>/veroeffentlichen">
            Zum Veröffentlichen
        </a>
    </section>

    <section class="wa-panel">
        <div class="wa-panel__head"><h2 class="wa-panel__title">Domain</h2></div>
        <?php if ($transfer !== null && (string) $transfer['domain'] !== ''): ?>
            <p class="wa-panel__hint">
                <code><?= e((string) $transfer['domain']) ?></code> –
                <?= (string) $transfer['mode'] === 'transfer' ? 'Umzug zum neuen Anbieter' : 'zeigt auf den neuen Server' ?>,
                Schritt <?= (int) $transfer['current_step'] + 1 ?>.
            </p>
        <?php else: ?>
            <p class="wa-panel__hint">Noch keine Domain hinterlegt.</p>
        <?php endif; ?>
        <a class="wa-btn wa-btn--sm" href="<?= e($base) ?>/projekt/<?= $id ?>/domain">Zum Assistenten</a>
    </section>
</div>

<?php /* -------------------------------------------------------- Gefahrenzone */ ?>
<section class="wa-panel wa-panel--danger">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Neu bauen oder löschen</h2>
    </div>
    <p class="wa-panel__hint">
        Beim Neubau entstehen Struktur und Texte frisch. Alles, was in der Vorschau von Hand
        geändert wurde, geht dabei verloren – die bisherigen Pakete bleiben erhalten.
    </p>
    <div class="wa-form__actions">
        <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/bauen"
              data-confirm="Website komplett neu bauen? Änderungen in der Vorschau gehen verloren.">
            <?= Csrf::field() ?>
            <button type="submit" class="wa-btn">Website neu bauen</button>
        </form>
        <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/loeschen"
              data-confirm="Das Projekt „<?= e((string) $project['name']) ?>“ mit allen Seiten, Paketen und Dateien endgültig löschen?">
            <?= Csrf::field() ?>
            <button type="submit" class="wa-btn wa-btn--quiet">Projekt löschen</button>
        </form>
    </div>
</section>
