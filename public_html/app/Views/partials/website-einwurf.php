<?php

/**
 * Das Fenster, mit dem eine Website bearbeitet wird.
 *
 * Zwei Dinge, mehr nicht: oben ein Feld für das ZIP, darunter die
 * Stände. Was hier vorher stand - eine Anleitung in drei Schritten,
 * Pakete, Versionen, gebaut gegen übernommen - beschrieb die
 * Innereien und nicht die Handgriffe.
 *
 * Der Ablauf: Mit dem FTP-Programm die Website vom Hosting des Kunden
 * holen, hier hochladen, bearbeiten, herunterladen, wieder hinaufladen.
 * WebAtze ist die Werkstatt dazwischen - eine Leitung zum Kundenserver
 * gibt es nicht, drei Wege dorthin waren durchgemessen und alle drei
 * tot.
 *
 * @var array<string, mixed> $website
 * @var string $base
 */

use WebAtze\Build\{Frischeprobe, Staende};
use WebAtze\Core\Csrf;

$w = $website ?? [];
$id = (int) ($w['id'] ?? 0);

$staende = Staende::liste($id);
$aktiv = null;
$aeltere = [];

foreach ($staende as $eintrag) {
    if ($aktiv === null && (int) $eintrag['is_active'] === 1) {
        $aktiv = $eintrag;

        continue;
    }

    $aeltere[] = $eintrag;
}

/** Datum und Uhrzeit, so wie man sie sagt. */
$wann = static function (?string $zeit): string {
    $t = $zeit === null || $zeit === '' ? false : strtotime($zeit);

    return $t === false ? '' : date('d.m.Y', $t) . ' um ' . date('H:i', $t) . ' Uhr';
};
?>
<div class="wa-einwurf">

    <?php /* ------------------------------------------------ Hochladen */ ?>
    <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/uebernehmen"
          enctype="multipart/form-data" class="wa-form wa-einwurf__form">
        <?= Csrf::field() ?>

        <div class="wa-field">
            <label class="wa-label" for="einwurf-<?= $id ?>">ZIP der Website hochladen</label>
            <input class="wa-input" type="file" id="einwurf-<?= $id ?>" name="archiv"
                   accept=".zip,application/zip" required>
        </div>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn wa-btn--primary">Hochladen und bearbeiten</button>
        </div>
    </form>

    <?php /* -------------------------------------------------- Aktiver */ ?>
    <?php if ($aktiv !== null): ?>
        <div class="wa-einwurf__aktiv">
            <div class="wa-einwurf__zeile">
                <span class="wa-badge wa-badge--ok">aktiv</span>
                <strong><?= e((string) $aktiv['note']) ?></strong>
                <span class="wa-einwurf__wann"><?= e($wann((string) $aktiv['created_at'])) ?></span>
            </div>

            <?php
            /* Die Angabe, um die es geht: Wer speichert und nicht sieht,
               dass gespeichert wurde, muss es glauben - und genau dieser
               Zweifel war der Anlass fuer diesen Umbau. */
            $zuletzt = $wann((string) ($aktiv['saved_at'] ?? ''));
            ?>
            <p class="wa-einwurf__wann">
                <?php if ($zuletzt !== ''): ?>
                    Zuletzt gespeichert am <?= e($zuletzt) ?>.
                <?php else: ?>
                    Noch nichts daran geändert.
                <?php endif; ?>
            </p>

            <div class="wa-einwurf__knoepfe">
                <a class="wa-btn wa-btn--primary" href="<?= e($base) ?>/direkt/<?= $id ?>">
                    Bearbeiten
                </a>
                <a class="wa-btn" href="<?= e($base) ?>/projekt/<?= $id ?>/stand">
                    Herunterladen
                </a>
            </div>
        </div>
    <?php else: ?>
        <p class="wa-empty">
            Hier liegt noch keine Website. Lade das ZIP hoch, das du mit deinem
            FTP-Programm vom Hosting des Kunden geholt hast.
        </p>
    <?php endif; ?>

    <?php
    /* -------------------------------------------------- Nachschau
       Der Fall, der zweimal einen halben Tag gekostet hat: Das ZIP war
       richtig, das Hochladen auch - und die Seite zeigte trotzdem die
       alte Fassung. Von hier aus ist das nicht zu sehen; die Datei im
       Archiv sieht es. */
    $nachschau = Frischeprobe::adresse($w);
    ?>
    <?php if ($nachschau !== ''): ?>
        <details class="wa-einwurf__nachschau">
            <summary>Beim Kunden steht noch die alte Fassung?</summary>
            <p class="wa-einwurf__wann">
                Im heruntergeladenen Archiv liegt <code>webatze-frisch.php</code>. Nach dem
                Entpacken einmal aufrufen &ndash; sie sagt, woran es liegt, und leert, was
                von dort erreichbar ist. Nach einer Woche löscht sie sich selbst.
            </p>
            <input class="wa-input" readonly onclick="this.select()"
                   value="<?= e($nachschau) ?>">
        </details>
    <?php endif; ?>

    <?php /* -------------------------------------------------- Ältere */ ?>
    <?php if ($aeltere !== []): ?>
        <div class="wa-einwurf__alt">
            <h4 class="wa-einwurf__titel">Ältere Stände</h4>

            <ul class="wa-einwurf__liste">
                <?php foreach ($aeltere as $alt): ?>
                    <li class="wa-einwurf__eintrag">
                        <?php
                        /* Zwei Zeitpunkte nebeneinander waren zu viel fuer eine
                           Zeile - sie brach um, und die Knoepfe rutschten
                           darunter. Es steht der eine da, auf den es ankommt:
                           wann zuletzt daran gearbeitet wurde, sonst wann er
                           hereinkam. */
                        $gesp = $wann((string) ($alt['saved_at'] ?? ''));
                        ?>
                        <div>
                            <strong><?= e($gesp !== '' ? $gesp : $wann((string) $alt['created_at'])) ?></strong>
                            <span class="wa-einwurf__wann">
                                <?= e($gesp !== '' ? 'gespeichert' : (string) $alt['note']) ?>
                            </span>
                        </div>

                        <div class="wa-einwurf__knoepfe">
                            <?php /* Wiederherstellen sichert vorher, woran gerade
                                     gearbeitet wird - siehe Staende::wiederherstellen().
                                     Der Hinweis steht trotzdem hier: Wer den Knopf
                                     drueckt, soll vorher wissen, was passiert. */ ?>
                            <form method="post"
                                  action="<?= e($base) ?>/projekt/<?= $id ?>/stand/<?= (int) $alt['id'] ?>/wiederherstellen">
                                <?= Csrf::field() ?>
                                <button type="submit" class="wa-btn wa-btn--small"
                                        data-confirm="Diesen Stand wieder aktiv setzen? Der jetzige wird vorher gesichert.">
                                    Wiederherstellen
                                </button>
                            </form>

                            <a class="wa-btn wa-btn--small"
                               href="<?= e($base) ?>/projekt/<?= $id ?>/stand/<?= (int) $alt['id'] ?>">
                                Herunterladen
                            </a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>
