<?php

/**
 * Die Übersicht.
 *
 * Die Reihenfolge ist die eines Arbeitstags: Was ist heute los, was
 * läuft nicht, wo fehlt Geld, was ist gerade im Bau. Zahlen über den
 * Gesamtbestand stehen ganz unten – sie ändern sich selten und drängen
 * sich deshalb nicht vor.
 */

use WebAtze\Core\{Config, Csrf};
use WebAtze\Domain\Billing;

/** @var string $heute */
/** @var array<string, array<int, array<string, mixed>>> $termine */
/** @var array<int, array<string, mixed>> $aufgaben */
/** @var int $ueberfaellig */
/** @var array<int, array<string, mixed>> $ausfaelle */
/** @var array<string, int> $wache */
/** @var array<int, array<string, mixed>> $offen */
/** @var int $offenSumme */
/** @var int $offenAnzahl */
/** @var int $monat */
/** @var array<string, int> $stapel */
/** @var array $projects @var array $activeJobs @var array $failedJobs @var array $stats */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');

$statusLabels = [
    'draft' => ['Entwurf', 'new'],
    'building' => ['Wird gebaut', 'running'],
    'ready' => ['Fertig', 'done'],
    'live' => ['Online', 'done'],
    'failed' => ['Fehlgeschlagen', 'failed'],
];

$heuteTermine = $termine[$heute] ?? [];

$spaeter = [];
foreach ($termine as $tag => $liste) {
    if ($tag > $heute) {
        $spaeter[$tag] = $liste;
    }
}

$wochentage = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
$tagName = static fn (string $tag): string =>
    $wochentage[(int) date('w', strtotime($tag) ?: time())] . ', ' . date('d.m.', strtotime($tag) ?: time());
?>

<?php if ($ausfaelle !== []): ?>
<section class="wa-panel wa-panel--alarm">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">
            <?= count($ausfaelle) === 1 ? 'Eine Website antwortet nicht' : count($ausfaelle) . ' Websites antworten nicht' ?>
        </h2>
        <a class="wa-btn wa-btn--small" href="<?= e($base) ?>/wartung">Zum Wartungscenter</a>
    </header>

    <ul class="wa-alarms">
        <?php foreach ($ausfaelle as $w): ?>
            <li class="wa-alarm">
                <strong><?= e((string) ($w['label'] ?: $w['url'])) ?></strong>
                <?php if ((string) ($w['kunde'] ?? '') !== ''): ?>
                    <span class="wa-table__quiet"><?= e((string) $w['kunde']) ?></span>
                <?php endif; ?>
                <p><?= e((string) $w['last_note'] ?: 'Grund unbekannt.') ?></p>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<div class="wa-today">
    <section class="wa-panel">
        <header class="wa-panel__head">
            <h2 class="wa-panel__title">Heute</h2>
            <a class="wa-btn wa-btn--small" href="<?= e($base) ?>/kalender">Kalender</a>
        </header>

        <?php if ($heuteTermine === []): ?>
            <p class="wa-empty">Heute steht nichts an.</p>
        <?php else: ?>
            <ul class="wa-agenda">
                <?php foreach ($heuteTermine as $t): ?>
                    <li class="wa-agenda__item">
                        <span class="wa-agenda__time">
                            <?= e(substr((string) $t['starts_at'], 11, 5)) ?>
                        </span>
                        <span class="wa-agenda__what">
                            <strong><?= e((string) $t['title']) ?></strong>
                            <?php if ((string) ($t['kunde'] ?? '') !== ''): ?>
                                <span class="wa-table__quiet"><?= e((string) $t['kunde']) ?></span>
                            <?php endif; ?>
                            <?php if ((string) $t['place'] !== ''): ?>
                                <span class="wa-table__quiet"><?= e((string) $t['place']) ?></span>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($spaeter !== []): ?>
            <h3 class="wa-panel__sub">Diese Woche</h3>
            <ul class="wa-agenda wa-agenda--quiet">
                <?php foreach ($spaeter as $tag => $liste): ?>
                    <?php foreach ($liste as $t): ?>
                        <li class="wa-agenda__item">
                            <span class="wa-agenda__time"><?= e($tagName((string) $tag)) ?></span>
                            <span class="wa-agenda__what">
                                <?= e((string) $t['title']) ?>
                                <?php if ((string) ($t['kunde'] ?? '') !== ''): ?>
                                    <span class="wa-table__quiet"><?= e((string) $t['kunde']) ?></span>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="wa-panel">
        <header class="wa-panel__head">
            <h2 class="wa-panel__title">Zu erledigen</h2>
            <?php if ($ueberfaellig > 0): ?>
                <span class="wa-badge wa-badge--bad"><?= (int) $ueberfaellig ?> überfällig</span>
            <?php endif; ?>
        </header>

        <?php if ($aufgaben === []): ?>
            <p class="wa-empty">Nichts offen. Selten, aber schön.</p>
        <?php else: ?>
            <ul class="wa-todos">
                <?php foreach ($aufgaben as $a): ?>
                    <?php $faellig = (string) $a['due_on']; ?>
                    <li class="wa-todo<?= $faellig !== '' && $faellig < $heute ? ' is-late' : '' ?>">
                        <form method="post" action="<?= e($base) ?>/aufgaben/umschalten">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="todo_id" value="<?= (int) $a['id'] ?>">
                            <input type="hidden" name="_back" value="/start">
                            <button type="submit" class="wa-check" title="Erledigt">✓</button>
                        </form>
                        <span class="wa-todo__text">
                            <?= e((string) $a['title']) ?>
                            <?php if ((string) ($a['kunde'] ?? '') !== ''): ?>
                                <span class="wa-table__quiet"><?= e((string) $a['kunde']) ?></span>
                            <?php endif; ?>
                            <?php if ($faellig !== ''): ?>
                                <span class="wa-table__quiet">
                                    bis <?= e(date('d.m.', strtotime($faellig) ?: time())) ?>
                                </span>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<div class="wa-tiles">
    <div class="wa-tile<?= $offenSumme > 0 ? ' wa-tile--warn' : '' ?>">
        <span class="wa-tile__label">Offen (<?= (int) $offenAnzahl ?> Posten)</span>
        <strong class="wa-tile__value"><?= e(Billing::money($offenSumme)) ?></strong>
    </div>
    <div class="wa-tile">
        <span class="wa-tile__label">Diesen Monat eingegangen</span>
        <strong class="wa-tile__value"><?= e(Billing::money($monat)) ?></strong>
    </div>
    <div class="wa-tile">
        <span class="wa-tile__label">Aktive Kunden</span>
        <strong class="wa-tile__value"><?= (int) ($stats['kunden'] ?? 0) ?></strong>
    </div>
    <div class="wa-tile<?= $stats['leads_new'] > 0 ? ' wa-tile--warn' : '' ?>">
        <span class="wa-tile__label">Neue Anfragen</span>
        <strong class="wa-tile__value"><?= (int) $stats['leads_new'] ?></strong>
    </div>
</div>

<?php if ($offen !== []): ?>
<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Was noch aussteht</h2>
        <a class="wa-btn wa-btn--small" href="<?= e($base) ?>/buchhaltung">Buchhaltung</a>
    </header>

    <div class="wa-table-wrap">
        <table class="wa-table">
            <tbody>
                <?php foreach ($offen as $o): ?>
                    <tr>
                        <td>
                            <a class="wa-table__main" href="<?= e($base) ?>/kunden/<?= (int) $o['kunde_id'] ?>">
                                <?= e((string) $o['kunde']) ?>
                            </a>
                        </td>
                        <td><?= e((string) $o['label']) ?></td>
                        <td class="wa-table__quiet"><?= e((string) $o['periode']) ?: 'einmalig' ?></td>
                        <td class="wa-table__num"><?= e(Billing::money((int) $o['amount_rappen'])) ?></td>
                        <td class="wa-table__actions">
                            <form method="post" action="<?= e($base) ?>/kunden/<?= (int) $o['kunde_id'] ?>/bezahlt">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="charge_id" value="<?= (int) $o['charge_id'] ?>">
                                <button type="submit" class="wa-check">abhaken</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if (($stapel['neu'] ?? 0) > 0 || ($stapel['liste'] ?? 0) > 0): ?>
<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Kundensuche</h2>
        <a class="wa-btn wa-btn--small" href="<?= e($base) ?>/kundensuche">Durchblättern</a>
    </header>
    <p class="wa-panel__hint">
        <strong><?= (int) ($stapel['neu'] ?? 0) ?></strong> Firmen warten im Stapel,
        <strong><?= (int) ($stapel['liste'] ?? 0) ?></strong> stehen auf der Liste der
        potenziellen Kunden.
    </p>
</section>
<?php endif; ?>

<?php if ($activeJobs !== []): ?>
    <section class="wa-panel">
        <div class="wa-panel__head">
            <h2 class="wa-panel__title">Läuft gerade</h2>
        </div>
        <?php foreach ($activeJobs as $job): ?>
            <div class="wa-job" data-job-watch="<?= (int) $job['id'] ?>">
                <div class="wa-job__row">
                    <strong><?= e((string) ($job['project_name'] ?? 'Auftrag')) ?></strong>
                    <span class="wa-job__value" data-job-label><?= (int) $job['progress'] ?>%</span>
                </div>
                <div class="wa-progress"><div class="wa-progress__bar" data-job-bar
                     style="--value: <?= (int) $job['progress'] ?>%"></div></div>
                <div class="wa-job__row">
                    <span class="wa-job__step" data-job-step><?= e((string) $job['message']) ?></span>
                <span class="wa-job__puls" data-job-puls data-state="lebt">arbeitet …</span>
                    <form method="post" action="/api/jobs/<?= (int) $job['id'] ?>/abbrechen"
                          data-confirm="Diesen Auftrag wirklich abbrechen?">
                        <?= Csrf::field() ?>
                        <button type="submit" class="wa-btn wa-btn--quiet wa-btn--sm">Abbrechen</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if ($failedJobs !== []): ?>
    <section class="wa-panel">
        <div class="wa-panel__head">
            <h2 class="wa-panel__title">Fehlgeschlagen</h2>
            <p class="wa-panel__hint">Diese Aufträge sind endgültig gescheitert. Sie bleiben stehen,
               bis sie angesehen wurden – niemals verschwindet ein Fehler stillschweigend.</p>
        </div>
        <?php foreach ($failedJobs as $job): ?>
            <div class="wa-note wa-note--danger">
                <div>
                    <strong><?= e((string) ($job['project_name'] ?? 'Auftrag')) ?> · <?= e((string) $job['type']) ?></strong>
                    <?= e((string) $job['error']) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Websites</h2>
        <div class="wa-panel__actions">
            <a class="wa-btn wa-btn--sm" href="<?= e($base) ?>/websites">Alle ansehen</a>
            <a class="wa-btn wa-btn--primary wa-btn--sm" href="<?= e($base) ?>/neu">Neue Website</a>
        </div>
    </div>

    <?php if ($projects === []): ?>
        <div class="wa-empty-state">
            <p>Noch keine Website.</p>
            <a class="wa-btn wa-btn--primary" href="<?= e($base) ?>/neu">Erste Website erstellen</a>
            <a class="wa-btn" href="<?= e($base) ?>/websites/neu">Bestehende hinzufügen</a>
        </div>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                    <tr>
                        <th>Name</th><th>Status</th><th>Seiten</th>
                        <th>Stände</th><th>Domain</th><th>Zuletzt</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($projects as $project): ?>
                    <?php [$label, $tone] = $statusLabels[$project['status']] ?? [$project['status'], '']; ?>
                    <tr>
                        <td><a href="<?= e($base) ?>/projekt/<?= (int) $project['id'] ?>"><?= e((string) $project['name']) ?></a></td>
                        <td><span class="wa-badge wa-badge--<?= e($tone) ?>"><?= e($label) ?></span></td>
                        <td><?= (int) $project['page_count'] ?></td>
                        <td><?= (int) $project['stand_count'] ?: '–' ?></td>
                        <td><?= $project['domain'] !== '' ? e((string) $project['domain']) : '–' ?></td>
                        <td><?= e(date('d.m.Y H:i', strtotime((string) $project['updated_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<div class="wa-stats">
    <div class="wa-stat">
        <span class="wa-stat__value"><?= (int) $stats['projects'] ?></span>
        <span class="wa-stat__label">Projekte insgesamt</span>
    </div>
    <div class="wa-stat">
        <span class="wa-stat__value"><?= (int) $stats['live'] ?></span>
        <span class="wa-stat__label">davon online</span>
    </div>
    <div class="wa-stat">
        <span class="wa-stat__value"><?= (int) $wache['aktiv'] ?></span>
        <span class="wa-stat__label">Websites überwacht</span>
    </div>
    <div class="wa-stat">
        <span class="wa-stat__value">$<?= number_format($stats['cost_month'] / 1e6, 2) ?></span>
        <span class="wa-stat__label">KI-Kosten diesen Monat</span>
    </div>
</div>
