<?php

/**
 * Ein Kunde: alles, was zu ihm gehört, auf einer Seite.
 *
 * Websites, Offerten und Rechnungen, Wartungsverträge, Passwörter,
 * Support, Kosten, Zahlungen, Aufgaben, Termine.
 *
 * Das war vorher über sechs Menüpunkte verteilt, und jeder davon
 * zeigte eine Liste über alle Kunden hinweg. Wer wissen wollte, wie es
 * um einen einzelnen steht, musste sechsmal filtern. Jetzt steht es
 * hier – und die Listen bleiben als Auswertung erhalten, für die
 * Fragen, die tatsächlich über alle Kunden gehen.
 */

use WebAtze\Core\{Config, Csrf};
use WebAtze\Domain\{Billing, Websites};

/** @var array<string, mixed> $kunde */
/** @var array<string, mixed> $stand */
/** @var array<int, array<string, mixed>> $historie */
/** @var array<int, array<string, mixed>> $todos */
/** @var array<int, array<string, mixed>> $termine */
/** @var array<int, array<string, mixed>> $mitarbeitende */
/** @var array<int, array<string, mixed>> $projekte */
/** @var array<int, array<string, mixed>> $rechnungen */
/** @var array<int, array<string, mixed>> $websites */
/** @var array<int, array<string, mixed>> $vertraege */
/** @var array<int, array<string, mixed>> $passwoerter */
/** @var array<int, array<string, mixed>> $fragebogen */
/** @var array<int, array<string, mixed>> $faeden */
/** @var bool $tresorOffen */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
$id = (int) ($kunde['id'] ?? 0);
$neu = $id === 0;
?>

<?php if (!$neu): ?>
<div class="wa-tiles">
    <div class="wa-tile<?= $stand['offen_rappen'] > 0 ? ' wa-tile--warn' : '' ?>">
        <span class="wa-tile__label">Offen</span>
        <strong class="wa-tile__value"><?= e(Billing::money((int) $stand['offen_rappen'])) ?></strong>
    </div>
    <div class="wa-tile">
        <span class="wa-tile__label">Monatlich</span>
        <strong class="wa-tile__value"><?= e(Billing::money((int) $stand['monatlich_rappen'])) ?></strong>
    </div>
    <div class="wa-tile">
        <span class="wa-tile__label">Jährlich</span>
        <strong class="wa-tile__value"><?= e(Billing::money((int) $stand['jaehrlich_rappen'])) ?></strong>
    </div>
</div>

<section class="wa-panel">
    <header class="wa-panel__head">
        <div>
            <h2 class="wa-panel__title">Kosten und Zahlungen</h2>
            <p class="wa-panel__hint">
                Monatliche Posten sind jeden Monat neu offen, jährliche jedes Jahr.
                Abgehakte Perioden bleiben in der Historie stehen.
            </p>
        </div>
    </header>

    <?php if ($stand['posten'] === []): ?>
        <p class="wa-empty">Noch kein Kostenposten. Trag unten den ersten ein.</p>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                    <tr>
                        <th>Posten</th>
                        <th>Rhythmus</th>
                        <th class="wa-table__num">Betrag</th>
                        <th>Periode</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stand['posten'] as $p): ?>
                        <tr<?= empty($p['active']) ? ' class="is-inactive"' : '' ?>>
                            <td>
                                <span class="wa-table__main"><?= e((string) $p['label']) ?></span>
                                <span class="wa-badge"><?= e(Billing::KINDS[$p['kind']] ?? (string) $p['kind']) ?></span>
                                <?php if ((string) $p['note'] !== ''): ?>
                                    <br><span class="wa-table__quiet"><?= e((string) $p['note']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="wa-table__quiet">
                                <?= e(Billing::INTERVALS[$p['interval']] ?? (string) $p['interval']) ?>
                            </td>
                            <td class="wa-table__num"><?= e(Billing::money((int) $p['amount_rappen'])) ?></td>
                            <td class="wa-table__quiet">
                                <?= $p['periode'] !== '' ? e((string) $p['periode']) : 'einmalig' ?>
                                <?php if ($p['bezahlt'] && (string) $p['bezahlt_am'] !== ''): ?>
                                    <br>am <?= e((string) $p['bezahlt_am']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="wa-table__actions">
                                <?php if (!$p['faellig']): ?>
                                    <span class="wa-table__quiet">läuft nicht</span>
                                <?php else: ?>
                                    <form method="post" action="<?= e($base) ?>/kunden/<?= $id ?>/bezahlt">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="charge_id" value="<?= (int) $p['id'] ?>">
                                        <button type="submit"
                                                class="wa-check<?= $p['bezahlt'] ? ' is-paid' : '' ?>"
                                                aria-pressed="<?= $p['bezahlt'] ? 'true' : 'false' ?>">
                                            <?= $p['bezahlt'] ? '✓ bezahlt' : 'offen' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <button type="button" class="wa-btn wa-btn--small"
                                        data-toggles="#posten-<?= (int) $p['id'] ?>">Ändern</button>
                            </td>
                        </tr>
                        <tr id="posten-<?= (int) $p['id'] ?>" hidden>
                            <td colspan="5">
                                <?= View_partial('admin/partials/charge-form', [
                                    'base' => $base, 'id' => $id, 'posten' => $p,
                                ]) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <details class="wa-details">
        <summary>Kostenposten hinzufügen</summary>
        <?= View_partial('admin/partials/charge-form', ['base' => $base, 'id' => $id, 'posten' => null]) ?>
    </details>
</section>

<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Aufgaben</h2>
    </header>

    <?php if ($todos === []): ?>
        <p class="wa-empty">Nichts offen.</p>
    <?php else: ?>
        <ul class="wa-todos">
            <?php foreach ($todos as $t): ?>
                <li class="wa-todo<?= $t['done_at'] !== null ? ' is-done' : '' ?>">
                    <form method="post" action="<?= e($base) ?>/aufgaben/umschalten">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="todo_id" value="<?= (int) $t['id'] ?>">
                        <button type="submit" class="wa-todo__check"
                                aria-label="<?= $t['done_at'] !== null ? 'Wieder offen' : 'Erledigt' ?>">
                            <?= $t['done_at'] !== null ? '✓' : '' ?>
                        </button>
                    </form>

                    <div class="wa-todo__body">
                        <span class="wa-todo__title"><?= e((string) $t['title']) ?></span>
                        <?php if ((string) $t['due_on'] !== ''): ?>
                            <span class="wa-badge<?= (string) $t['due_on'] < date('Y-m-d') && $t['done_at'] === null
                                ? ' wa-badge--failed' : '' ?>">bis <?= e((string) $t['due_on']) ?></span>
                        <?php endif; ?>
                        <?php if ((string) $t['priority'] === 'hoch'): ?>
                            <span class="wa-badge wa-badge--failed">wichtig</span>
                        <?php endif; ?>
                        <?php if ((string) ($t['note'] ?? '') !== ''): ?>
                            <p class="wa-todo__note"><?= e((string) $t['note']) ?></p>
                        <?php endif; ?>
                    </div>

                    <form method="post" action="<?= e($base) ?>/aufgaben/loeschen"
                          data-confirm="Diese Aufgabe löschen?">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="todo_id" value="<?= (int) $t['id'] ?>">
                        <button type="submit" class="wa-btn wa-btn--small">Löschen</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <details class="wa-details">
        <summary>Aufgabe hinzufügen</summary>
        <form method="post" action="<?= e($base) ?>/aufgaben" class="wa-form wa-form--inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="customer_id" value="<?= $id ?>">

            <div class="wa-field">
                <label class="wa-label" for="todo-title">Was ist zu tun?</label>
                <input class="wa-input" type="text" id="todo-title" name="title" required>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="todo-due">Bis wann</label>
                <input class="wa-input" type="date" id="todo-due" name="due_on">
            </div>

            <div class="wa-field">
                <label class="wa-label" for="todo-prio">Dringlichkeit</label>
                <select class="wa-select" id="todo-prio" name="priority">
                    <option value="normal">normal</option>
                    <option value="hoch">wichtig</option>
                    <option value="tief">kann warten</option>
                </select>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="todo-person">Wer</label>
                <select class="wa-select" id="todo-person" name="employee_id">
                    <option value="0">–</option>
                    <?php foreach ($mitarbeitende as $m): ?>
                        <option value="<?= (int) $m['id'] ?>"><?= e((string) $m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="wa-form__actions">
                <button type="submit" class="wa-btn wa-btn--primary">Notieren</button>
            </div>
        </form>
    </details>
</section>

<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Termine</h2>
        <a class="wa-btn" href="<?= e($base) ?>/kalender">Zum Kalender</a>
    </header>

    <?php if ($termine === []): ?>
        <p class="wa-empty">Keine Termine.</p>
    <?php else: ?>
        <ul class="wa-list">
            <?php foreach ($termine as $t): ?>
                <li class="wa-list__row">
                    <div>
                        <strong><?= e((string) $t['title']) ?></strong><br>
                        <span class="wa-table__quiet">
                            <?= e((string) $t['starts_at']) ?>
                            <?= (string) $t['place'] !== '' ? ' · ' . e((string) $t['place']) : '' ?>
                        </span>
                    </div>
                    <form method="post" action="<?= e($base) ?>/termine/loeschen"
                          data-confirm="Diesen Termin absagen?">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="appointment_id" value="<?= (int) $t['id'] ?>">
                        <input type="hidden" name="zurueck" value="kunde">
                        <button type="submit" class="wa-btn wa-btn--small">Absagen</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <details class="wa-details">
        <summary>Termin eintragen</summary>
        <form method="post" action="<?= e($base) ?>/termine" class="wa-form wa-form--inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="customer_id" value="<?= $id ?>">
            <input type="hidden" name="zurueck" value="kunde">

            <div class="wa-field">
                <label class="wa-label" for="term-title">Worum geht es?</label>
                <input class="wa-input" type="text" id="term-title" name="title" required>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="term-start">Beginn</label>
                <input class="wa-input" type="datetime-local" id="term-start" name="starts_at" required>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="term-end">Ende</label>
                <input class="wa-input" type="datetime-local" id="term-end" name="ends_at">
            </div>

            <div class="wa-field">
                <label class="wa-label" for="term-place">Wo</label>
                <input class="wa-input" type="text" id="term-place" name="place">
            </div>

            <div class="wa-form__actions">
                <button type="submit" class="wa-btn wa-btn--primary">Eintragen</button>
            </div>
        </form>
    </details>
</section>

<?php if (!$neu): ?>
<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Websites</h2>
        <div class="wa-panel__actions">
            <a class="wa-btn wa-btn--small" href="<?= e($base) ?>/websites?kunde=<?= $id ?>">Alle ansehen</a>
            <a class="wa-btn wa-btn--small" href="<?= e($base) ?>/websites/neu">Website hinzufügen</a>
        </div>
    </header>

    <?php if (($websites ?? []) === []): ?>
        <p class="wa-empty">
            Diesem Kunden ist noch keine Website zugeordnet.
            <a href="<?= e($base) ?>/websites/neu">Eine bestehende hinzufügen</a>
            oder <a href="<?= e($base) ?>/neu">eine neue bauen lassen</a>.
        </p>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <tbody>
                    <?php foreach ($websites as $w): ?>
                        <?php
                            $handgemacht = (string) $w['source'] === 'hand';
                            $ziel = $base . ($handgemacht ? '/websites/' : '/projekt/') . (int) $w['id'];
                            $waechter = $w['waechter'] ?? null;
                        ?>
                        <tr>
                            <td class="wa-thumbcell">
                                <?= View_partial('partials/website-thumb', [
                                    'website' => $w,
                                    'base' => $base,
                                ]) ?>
                            </td>
                            <td class="wa-sitecell">
                                <a class="wa-table__main" href="<?= e($ziel) ?>"><?= e((string) $w['name']) ?></a>
                                <?php if ((string) $w['domain'] !== ''): ?>
                                    <span class="wa-table__quiet"><?= e((string) $w['domain']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="wa-table__quiet">
                                <?= $handgemacht ? 'Hinzugefügt' : 'Von WebAtze' ?>
                                <?php if ((string) $w['platform'] !== ''): ?>
                                    · <?= e((string) $w['platform']) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($waechter === null): ?>
                                    <span class="wa-table__quiet">nicht überwacht</span>
                                <?php elseif ((int) $waechter['last_ok'] === 1): ?>
                                    <span class="wa-badge wa-badge--ok">läuft</span>
                                <?php else: ?>
                                    <span class="wa-badge wa-badge--bad">Ausfall</span>
                                <?php endif; ?>
                            </td>
                            <td class="wa-table__actions">
                                <?php if ((string) $w['domain'] !== ''): ?>
                                    <a class="wa-icon-btn"
                                       href="<?= e(Websites::url($w)) ?>"
                                       target="_blank" rel="noopener noreferrer"
                                       aria-label="<?= e((string) $w['name']) ?> im Browser öffnen"
                                       title="Website öffnen">
                                        <?= View_partial('partials/admin-icons', ['name' => 'external']) ?>
                                    </a>
                                <?php endif; ?>

                                <?php /* Der Weg zu FTP und Veröffentlichen. Seit
                                         die Website-Liste nicht mehr im Menü
                                         steht, führte hierher gar kein Weg
                                         mehr – man kam an die Zugangsdaten
                                         nicht mehr heran. */ ?>
                                <a class="wa-btn wa-btn--small"
                                   href="<?= e($base) ?>/projekt/<?= (int) $w['id'] ?>/veroeffentlichen">
                                    Server
                                </a>

                                <?php
                                /* Der ganze Weg in einem Fenster: ZIP einwerfen,
                                   bearbeiten, herunterladen. Es enthaelt genau
                                   zwei Dinge - das Feld und die Liste der
                                   Staende. Die Raute vor der Kennung ist nicht
                                   Zierde: Ohne sie sucht querySelector nach
                                   einem Element namens <bearbeiten-1>, findet
                                   nichts, und der Knopf tut stumm gar nichts. */ ?>
                                <button type="button" class="wa-btn wa-btn--primary wa-btn--small"
                                        data-dialog="#bearbeiten-<?= (int) $w['id'] ?>">
                                    Website editieren
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        /**
         * Die Fenster hinter der Tabelle.
         *
         * Ein <dialog> darf nicht in einem <tbody> liegen - und im <td>
         * erbte es die Breite einer schmalen Spalte am rechten Rand.
         */
        ?>
        <?php foreach ($websites as $w): ?>
            <?= View_partial('partials/dialog', [
                'id' => 'bearbeiten-' . (int) $w['id'],
                'titel' => (string) $w['name'] . ' bearbeiten',
                'inhalt' => View_partial('partials/website-einwurf', [
                    'website' => $w,
                    'base' => $base,
                ]),
            ]) ?>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php
/**
 * Offerten und Rechnungen dieses Kunden.
 *
 * Diese Liste wurde seit jeher geladen und weggeworfen: Der Controller
 * holte sie, der Kopfkommentar kündigte sie an, gerendert wurde sie
 * nie. Stattdessen stand hier ein Link auf eine Liste aller Rechnungen
 * aller Kunden – also genau der Umweg, der jetzt wegfällt.
 */
?>
<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Offerten und Rechnungen</h2>
        <div class="wa-panel__actions">
            <a class="wa-btn wa-btn--small" href="<?= e($base) ?>/rechnungen?art=offer&amp;kunde=<?= $id ?>">
                Offerte schreiben
            </a>
            <a class="wa-btn wa-btn--primary wa-btn--small"
               href="<?= e($base) ?>/rechnungen?art=invoice&amp;kunde=<?= $id ?>">
                Rechnung erstellen
            </a>
        </div>
    </header>

    <?php if (($rechnungen ?? []) === []): ?>
        <p class="wa-empty">
            Für diesen Kunden gibt es noch keine Offerte und keine Rechnung.
        </p>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                    <tr>
                        <th>Nummer</th>
                        <th>Titel</th>
                        <th>Datum</th>
                        <th>Zustand</th>
                        <th class="wa-table__num">Betrag</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rechnungen as $d): ?>
                        <?php
                            $art = (string) ($d['kind'] ?? 'offer');
                            $zustand = (string) ($d['status'] ?? 'draft');
                            $ueberfaellig = $art === 'invoice'
                                && $zustand === 'sent'
                                && (string) ($d['due_on'] ?? '') !== ''
                                && (string) $d['due_on'] < date('Y-m-d');
                        ?>
                        <tr>
                            <td>
                                <span class="wa-table__main"><?= e((string) ($d['number'] ?? '')) ?></span>
                                <span class="wa-table__quiet">
                                    <?= e(\WebAtze\Build\DocumentBuilder::KINDS[$art] ?? $art) ?>
                                </span>
                            </td>
                            <td><?= e((string) ($d['title'] ?? '')) ?></td>
                            <td class="wa-table__quiet"><?= e((string) ($d['issued_on'] ?? '')) ?></td>
                            <td>
                                <?php if ($ueberfaellig): ?>
                                    <span class="wa-badge wa-badge--bad">überfällig</span>
                                <?php elseif ($zustand === 'paid'): ?>
                                    <span class="wa-badge wa-badge--ok">bezahlt</span>
                                <?php else: ?>
                                    <span class="wa-badge">
                                        <?= e(\WebAtze\Build\DocumentBuilder::STATUS[$zustand] ?? $zustand) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="wa-table__num">
                                <?= e(Billing::money((int) ($d['total_rappen'] ?? 0))) ?>
                            </td>
                            <td class="wa-table__actions">
                                <a class="wa-btn wa-btn--small"
                                   href="<?= e($base) ?>/rechnungen/<?= (int) $d['id'] ?>/pdf">PDF</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php /* ---------------------------------------------------- Verträge */ ?>
<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Wartungsverträge</h2>
        <div class="wa-panel__actions">
            <a class="wa-btn wa-btn--small" href="<?= e($base) ?>/vertraege?kunde=<?= $id ?>">
                Vertrag anlegen
            </a>
        </div>
    </header>

    <?php if (($vertraege ?? []) === []): ?>
        <p class="wa-empty">Kein laufender Vertrag.</p>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                    <tr>
                        <th>Website</th>
                        <th>Paket</th>
                        <th>Nächste Rechnung</th>
                        <th class="wa-table__num">Betrag</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vertraege as $v): ?>
                        <?php $beendet = (string) ($v['cancelled_on'] ?? '') !== ''; ?>
                        <tr>
                            <td><?= e((string) ($v['website'] ?? '—')) ?></td>
                            <td class="wa-table__quiet"><?= e((string) ($v['plan'] ?? '')) ?></td>
                            <td class="wa-table__quiet">
                                <?= $beendet
                                    ? 'gekündigt am ' . e((string) $v['cancelled_on'])
                                    : e((string) ($v['next_invoice_on'] ?? '')) ?>
                            </td>
                            <td class="wa-table__num">
                                <?= e(Billing::money((int) ($v['price_rappen'] ?? 0))) ?>
                                <span class="wa-table__quiet">
                                    / <?= (int) ($v['interval_months'] ?? 12) ?> Mt.
                                </span>
                            </td>
                            <td class="wa-table__actions">
                                <?php if (!$beendet): ?>
                                    <form method="post"
                                          action="<?= e($base) ?>/vertraege/<?= (int) $v['id'] ?>/kuendigen"
                                          data-confirm="Diesen Vertrag wirklich kündigen?">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="wa-btn wa-btn--small">Kündigen</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php
/**
 * Passwörter dieses Kunden.
 *
 * Gezeigt wird nur, dass es sie gibt – Bezeichnung, Art, Benutzername.
 * Das Geheimnis selbst kommt weiterhin ausschliesslich über den Tresor
 * und dessen eigene Entsperrung heraus. Diese Seite bekommt es nie zu
 * sehen, auch nicht kurz.
 */
?>
<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Passwörter</h2>
        <div class="wa-panel__actions">
            <a class="wa-btn wa-btn--small" href="<?= e($base) ?>/passwoerter?kunde=<?= $id ?>">
                Im Tresor öffnen
            </a>
        </div>
    </header>

    <?php if (($passwoerter ?? []) === []): ?>
        <p class="wa-empty">Für diesen Kunden liegt nichts im Tresor.</p>
    <?php else: ?>
        <ul class="wa-keys">
            <?php foreach ($passwoerter as $s): ?>
                <li class="wa-keys__item">
                    <span class="wa-keys__label"><?= e((string) $s['label']) ?></span>
                    <span class="wa-keys__kind"><?= e((string) $s['kind']) ?></span>
                    <?php if ((string) ($s['username'] ?? '') !== ''): ?>
                        <span class="wa-keys__user"><?= e((string) $s['username']) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="wa-hint">
            <?= ($tresorOffen ?? false)
                ? 'Der Tresor ist offen. Zum Ansehen im Tresor öffnen.'
                : 'Der Tresor ist zu. Zum Ansehen dort das Tresorpasswort eingeben.' ?>
        </p>
    <?php endif; ?>
</section>

<?php /* ------------------------------------- Support und Fragebögen */ ?>
<?php if (($faeden ?? []) !== [] || ($fragebogen ?? []) !== []): ?>
<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Support und Fragebögen</h2>
    </header>

    <?php if (($faeden ?? []) !== []): ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <tbody>
                    <?php foreach ($faeden as $f): ?>
                        <tr>
                            <td>
                                <a class="wa-table__main"
                                   href="<?= e($base) ?>/support#faden-<?= (int) $f['id'] ?>">
                                    <?= e((string) ($f['subject'] ?? 'Ohne Betreff')) ?>
                                </a>
                                <span class="wa-table__quiet"><?= e((string) ($f['website'] ?? '')) ?></span>
                            </td>
                            <td class="wa-table__quiet"><?= e((string) ($f['last_message_at'] ?? '')) ?></td>
                            <td>
                                <?php if ((int) ($f['unread_for_owner'] ?? 0) === 1): ?>
                                    <span class="wa-badge wa-badge--warn">ungelesen</span>
                                <?php elseif ((string) ($f['status'] ?? '') === 'open'): ?>
                                    <span class="wa-badge">offen</span>
                                <?php else: ?>
                                    <span class="wa-badge wa-badge--ok">erledigt</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (($fragebogen ?? []) !== []): ?>
        <ul class="wa-keys">
            <?php foreach ($fragebogen as $q): ?>
                <li class="wa-keys__item">
                    <span class="wa-keys__label">
                        Fragebogen <?= e((string) ($q['company'] ?? '')) ?>
                    </span>
                    <span class="wa-keys__kind"><?= e((string) ($q['status'] ?? '')) ?></span>
                    <a class="wa-keys__user" href="<?= e($base) ?>/fragebogen">ansehen</a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>

<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Zahlungshistorie</h2>
        <div class="wa-panel__actions">
            <form method="post" action="<?= e($base) ?>/kunden/<?= $id ?>/rechnung">
                <?= Csrf::field() ?>
                <button type="submit" class="wa-btn"
                        <?= $stand['offen_rappen'] > 0 ? '' : 'disabled' ?>>
                    Rechnung aus offenen Posten
                </button>
            </form>
        </div>
    </header>

    <?php if ($historie === []): ?>
        <p class="wa-empty">Noch nichts eingegangen.</p>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                    <tr>
                        <th>Bezahlt am</th>
                        <th>Wofür</th>
                        <th>Periode</th>
                        <th class="wa-table__num">Betrag</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historie as $z): ?>
                        <tr>
                            <td><?= e((string) $z['paid_on']) ?></td>
                            <td><?= e((string) $z['label']) ?></td>
                            <td class="wa-table__quiet"><?= e((string) $z['period']) ?: 'einmalig' ?></td>
                            <td class="wa-table__num"><?= e(Billing::money((int) $z['amount_rappen'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title"><?= $neu ? 'Neuer Kunde' : 'Stammdaten' ?></h2>
    </header>

    <form method="post" action="<?= e($base) ?>/kunden<?= $neu ? '' : '/' . $id ?>" class="wa-form">
        <?= Csrf::field() ?>

        <div class="wa-form__grid">
            <div class="wa-field">
                <label class="wa-label" for="name">Firma</label>
                <input class="wa-input" type="text" id="name" name="name" required
                       value="<?= e((string) ($kunde['name'] ?? '')) ?>">
            </div>

            <div class="wa-field">
                <label class="wa-label" for="contact_name">Ansprechperson</label>
                <input class="wa-input" type="text" id="contact_name" name="contact_name"
                       value="<?= e((string) ($kunde['contact_name'] ?? '')) ?>">
            </div>

            <div class="wa-field">
                <label class="wa-label" for="email">E-Mail</label>
                <input class="wa-input" type="email" id="email" name="email"
                       value="<?= e((string) ($kunde['email'] ?? '')) ?>">
            </div>

            <div class="wa-field">
                <label class="wa-label" for="phone">Telefon</label>
                <input class="wa-input" type="text" id="phone" name="phone"
                       value="<?= e((string) ($kunde['phone'] ?? '')) ?>">
            </div>

            <div class="wa-field wa-field--wide">
                <label class="wa-label" for="address">Adresse</label>
                <textarea class="wa-textarea" id="address" name="address" rows="3"><?= e((string) ($kunde['address'] ?? '')) ?></textarea>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="website">Website</label>
                <input class="wa-input" type="text" id="website" name="website"
                       value="<?= e((string) ($kunde['website'] ?? '')) ?>">
            </div>

            <div class="wa-field">
                <label class="wa-label" for="status">Zustand</label>
                <select class="wa-select" id="status" name="status">
                    <?php foreach (['aktiv' => 'Aktiv', 'ruhend' => 'Ruhend',
                                    'beendet' => 'Beendet'] as $wert => $text): ?>
                        <option value="<?= e($wert) ?>"<?= (string) ($kunde['status'] ?? 'aktiv') === $wert
                            ? ' selected' : '' ?>><?= e($text) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="employee_id">Betreut von</label>
                <select class="wa-select" id="employee_id" name="employee_id">
                    <option value="0">–</option>
                    <?php foreach ($mitarbeitende as $m): ?>
                        <option value="<?= (int) $m['id'] ?>"<?= (int) ($kunde['employee_id'] ?? 0) === (int) $m['id']
                            ? ' selected' : '' ?>><?= e((string) $m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="project_id">Projekt</label>
                <select class="wa-select" id="project_id" name="project_id">
                    <option value="0">–</option>
                    <?php foreach ($projekte as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"<?= (int) ($kunde['project_id'] ?? 0) === (int) $p['id']
                            ? ' selected' : '' ?>><?= e((string) $p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="wa-field wa-field--wide">
                <label class="wa-label" for="notes">Notizen</label>
                <textarea class="wa-textarea" id="notes" name="notes" rows="4"><?= e((string) ($kunde['notes'] ?? '')) ?></textarea>
            </div>
        </div>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn wa-btn--primary">Speichern</button>
        </div>
    </form>

    <?php if (!$neu): ?>
        <form method="post" action="<?= e($base) ?>/kunden/<?= $id ?>/loeschen" class="wa-form__danger"
              data-confirm="Diesen Kunden wirklich löschen? Kosten, Aufgaben und Termine gehen mit. Die Zahlungen bleiben in der Buchhaltung.">
            <?= Csrf::field() ?>
            <button type="submit" class="wa-btn wa-btn--danger">Kunden löschen</button>
        </form>
    <?php endif; ?>
</section>
