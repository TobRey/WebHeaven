<?php

/**
 * Alle Websites in einer Liste.
 *
 * Selbst gebaute und hinzugefügte stehen nebeneinander – wer wissen
 * will, was er betreut, will eine Liste und nicht zwei. Woher eine
 * Website kommt, sagt die Spalte «Herkunft»; wohin der Verweis führt,
 * hängt davon ab.
 */

use WebAtze\Core\{Config, Csrf};
use WebAtze\Domain\Websites;

/** @var array<int, array<string, mixed>> $websites */
/** @var string $suche */
/** @var string $herkunft */
/** @var int $kundeId */
/** @var array<string, int> $zahlen */
/** @var array<int, array<string, mixed>> $kunden */
/** @var array<string, string> $status */
/** @var array<string, string> $herkuenfte */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
?>

<div class="wa-tiles">
    <div class="wa-tile">
        <span class="wa-tile__label">Websites</span>
        <strong class="wa-tile__value"><?= (int) $zahlen['gesamt'] ?></strong>
    </div>
    <div class="wa-tile">
        <span class="wa-tile__label">Davon online</span>
        <strong class="wa-tile__value"><?= (int) $zahlen['live'] ?></strong>
    </div>
    <div class="wa-tile">
        <span class="wa-tile__label">Selbst gebaut</span>
        <strong class="wa-tile__value"><?= (int) $zahlen['gebaut'] ?></strong>
    </div>
    <div class="wa-tile<?= $zahlen['ohne_kunde'] > 0 ? ' wa-tile--warn' : '' ?>">
        <span class="wa-tile__label">Ohne Kunde</span>
        <strong class="wa-tile__value"><?= (int) $zahlen['ohne_kunde'] ?></strong>
    </div>
</div>

<section class="wa-panel">
    <header class="wa-panel__head">
        <form method="get" class="wa-filter">
            <input class="wa-input wa-input--short" type="search" name="suche"
                   value="<?= e($suche) ?>" placeholder="Suchen …" aria-label="Websites suchen">

            <select class="wa-select wa-input--short" name="herkunft" aria-label="Herkunft">
                <option value="">Alle Herkünfte</option>
                <?php foreach ($herkuenfte as $wert => $text): ?>
                    <option value="<?= e($wert) ?>"<?= $herkunft === $wert ? ' selected' : '' ?>>
                        <?= e($text) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select class="wa-select wa-input--short" name="kunde" aria-label="Kunde">
                <option value="0">Alle Kunden</option>
                <?php foreach ($kunden as $k): ?>
                    <option value="<?= (int) $k['id'] ?>"<?= $kundeId === (int) $k['id'] ? ' selected' : '' ?>>
                        <?= e((string) $k['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="wa-btn">Zeigen</button>
        </form>

        <div class="wa-panel__actions">
            <a class="wa-btn" href="<?= e($base) ?>/websites/neu">Website hinzufügen</a>
            <a class="wa-btn wa-btn--primary" href="<?= e($base) ?>/neu">Neue Website bauen</a>
        </div>
    </header>

    <?php if ($websites === []): ?>
        <p class="wa-empty">
            <?= $suche !== '' || $herkunft !== '' || $kundeId > 0
                ? 'Dazu passt nichts.'
                : 'Noch keine Website.' ?>
            <a href="<?= e($base) ?>/websites/neu">Eine bestehende hinzufügen</a>
            oder <a href="<?= e($base) ?>/neu">eine neue bauen lassen</a>.
        </p>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>Website</th>
                        <th>Kunde</th>
                        <th>Herkunft</th>
                        <th>Zustand</th>
                        <th>Erreichbarkeit</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($websites as $w): ?>
                        <?php
                            $hand = (string) $w['source'] === 'hand';
                            $ziel = $base . ($hand ? '/websites/' : '/projekt/') . (int) $w['id'];
                            $adresse = Websites::url($w);
                            $waechter = $w['waechter'] ?? null;
                        ?>
                        <tr>
                            <?php /* Der Anblick der Seite, nicht nur ihr Name -
                                     bei acht Websites erkennt man die gesuchte
                                     schneller am Bild als am Text. */ ?>
                            <td class="wa-thumbcell">
                                <?= View_partial('partials/website-thumb', [
                                    'website' => $w,
                                    'base' => $base,
                                ]) ?>
                            </td>
                            <td class="wa-sitecell">
                                <a class="wa-table__main" href="<?= e($ziel) ?>"><?= e((string) $w['name']) ?></a>
                                <?php if ((string) $w['domain'] !== ''): ?>
                                    <a class="wa-table__quiet" href="<?= e($adresse) ?>"
                                       target="_blank" rel="noopener noreferrer">
                                        <?= e((string) $w['domain']) ?>
                                    </a>
                                <?php endif; ?>
                                <?php if ((string) $w['platform'] !== ''): ?>
                                    <span class="wa-table__quiet"><?= e((string) $w['platform']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((string) ($w['kunde'] ?? '') !== ''): ?>
                                    <a href="<?= e($base) ?>/kunden/<?= (int) $w['customer_id'] ?>">
                                        <?= e((string) $w['kunde']) ?>
                                    </a>
                                <?php else: ?>
                                    <form method="post" action="<?= e($base) ?>/websites/zuordnen" class="wa-assign">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="website_id" value="<?= (int) $w['id'] ?>">
                                        <select class="wa-select wa-input--short" name="customer_id"
                                                aria-label="Kunde für <?= e((string) $w['name']) ?>">
                                            <option value="0">– keiner –</option>
                                            <?php foreach ($kunden as $k): ?>
                                                <option value="<?= (int) $k['id'] ?>"><?= e((string) $k['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="wa-btn wa-btn--small">Zuordnen</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td class="wa-table__quiet">
                                <?= $hand ? 'Hinzugefügt' : 'Von WebAtze' ?>
                            </td>
                            <td>
                                <?php /* Kurz auf der Marke, ausgeschrieben im Titel:
                                         Als ganzer Satz war der Zustand zweihundert
                                         Pixel breit und schob die Liste ueber den Rand. */ ?>
                                <span class="wa-badge<?= (string) $w['status'] === 'live' ? ' wa-badge--ok' : '' ?>"
                                      title="<?= e(Websites::STATUS_LANG[(string) $w['status']] ?? '') ?>">
                                    <?= e($status[(string) $w['status']] ?? (string) $w['status']) ?>
                                </span>
                                <?php /* Seit die Uebertragung von Hand laeuft, ist das
                                         die wichtigste Auskunft der Liste: Was hier
                                         geaendert wurde, liegt beim Kunden erst, wenn
                                         es jemand hinbringt. */ ?>
                                <?php if (Websites::offeneAenderung($w)): ?>
                                    <a class="wa-badge wa-badge--warn"
                                       href="<?= e($base) ?>/projekt/<?= (int) $w['id'] ?>/veroeffentlichen"
                                       title="Seit dem letzten Herunterladen wurde hier etwas geändert.">
                                        nicht heruntergeladen
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($waechter === null): ?>
                                    <span class="wa-table__quiet">nicht überwacht</span>
                                <?php elseif ((string) ($waechter['last_checked_at'] ?? '') === ''): ?>
                                    <span class="wa-badge">noch nicht geprüft</span>
                                <?php elseif ((int) $waechter['last_ok'] === 1): ?>
                                    <span class="wa-badge wa-badge--ok">läuft</span>
                                    <span class="wa-table__quiet"><?= (int) $waechter['last_ms'] ?> ms</span>
                                <?php else: ?>
                                    <a class="wa-badge wa-badge--bad"
                                       href="<?= e($base) ?>/wartung/<?= (int) $waechter['id'] ?>">Ausfall</a>
                                <?php endif; ?>
                            </td>
                            <td class="wa-table__actions">
                                <?php /* Zeichen fuer die zwei Handgriffe, die
                                         ueberall gleich aussehen. Das haelt die
                                         Spalte schmal genug, dass die Tabelle
                                         gar nicht erst ueberlaeuft. */ ?>
                                <?php if ($adresse !== ''): ?>
                                    <a class="wa-icon-btn" href="<?= e($adresse) ?>"
                                       target="_blank" rel="noopener noreferrer"
                                       aria-label="<?= e((string) $w['name']) ?> im Browser öffnen"
                                       title="Website öffnen">
                                        <?= View_partial('partials/admin-icons', ['name' => 'external']) ?>
                                    </a>
                                <?php endif; ?>

                                <a class="wa-icon-btn" href="<?= e($ziel) ?>"
                                   aria-label="<?= e((string) $w['name']) ?> ansehen"
                                   title="Ansehen">
                                    <?= View_partial('partials/admin-icons', ['name' => 'eye']) ?>
                                </a>

                                <?php /* Der Weg zu FTP und Veroeffentlichen. Er
                                         behaelt seinen Text: „Server" ist kein
                                         Handgriff, den ein Zeichen erklaert. */ ?>
                                <a class="wa-btn wa-btn--small"
                                   href="<?= e($base) ?>/projekt/<?= (int) $w['id'] ?>/veroeffentlichen">
                                    Server
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
