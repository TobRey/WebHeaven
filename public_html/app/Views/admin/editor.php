<?php

/**
 * Der Editor.
 *
 * Diese Ansicht ist bewusst fast leer: Sie spannt den Rahmen auf und
 * legt die Angaben daneben, die der Editor beim Start braucht. Alles
 * Weitere – Auswahl, Ziehen, Einstellungen – baut das Bündel selbst,
 * und zwar dasselbe Bündel, das auch im Bearbeitungsbereich beim Kunden
 * läuft.
 *
 * Der Rahmen zeigt die Seite, wie der Besucher sie sähe, und liegt auf
 * derselben Herkunft. Das ist keine Bequemlichkeit: Über eine fremde
 * Domain hinweg dürfte der Editor den Inhalt weder lesen noch verändern.
 */

use WebAtze\Core\{Config, Csrf};

/** @var array $projekt */
/** @var array<int, array<string, mixed>> $seiten */
/** @var array $seite */
/** @var bool $entwurf */
/** @var array $daten */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
?>

<?php
/**
 * Die zwei Enden der Schleife.
 *
 * Übertragen wird von Hand: Du holst die Website mit deinem
 * FTP-Programm herunter, lädst sie hier hoch, bearbeitest sie, lädst
 * das Paket herunter und bringst es zurück. Deshalb steht beides hier
 * oben - der Weg herein und der Weg hinaus, an der Stelle, an der
 * gearbeitet wird.
 */
$pakete = (array) ($pakete ?? []);
$neustes = $pakete[0] ?? null;
$offen = (bool) ($offen ?? false);
?>
<div class="wa-editor" data-editor>
    <div class="wa-editor__schleife">
        <div class="wa-editor__zustand">
            <?php if ($offen): ?>
                <span class="wa-badge wa-badge--warn">noch nicht heruntergeladen</span>
            <?php elseif ((string) ($projekt['downloaded_at'] ?? '') !== ''): ?>
                <span class="wa-badge wa-badge--ok">draussen</span>
                seit <?= e(date('d.m.Y H:i', strtotime((string) $projekt['downloaded_at']))) ?>
            <?php endif; ?>
        </div>

        <div class="wa-editor__group">
            <details class="wa-editor__einwurf">
                <summary class="wa-btn wa-btn--quiet wa-btn--sm">Stand hochladen</summary>
                <form method="post" action="<?= e($base) ?>/projekt/<?= (int) $projekt['id'] ?>/uebernehmen"
                      enctype="multipart/form-data" class="wa-editor__form">
                    <?= \WebAtze\Core\Csrf::field() ?>
                    <input class="wa-input" type="file" name="archiv" accept=".zip,application/zip">
                    <button type="submit" class="wa-btn wa-btn--sm"
                            data-confirm="Den Stand aus diesem Archiv übernehmen? Der bisherige Inhalt wird ersetzt - eine Fassung bleibt erhalten.">
                        Übernehmen
                    </button>
                </form>
            </details>

            <?php if ($neustes !== null): ?>
                <a class="wa-btn wa-btn--sm"
                   href="<?= e($base) ?>/projekt/<?= (int) $projekt['id'] ?>/zip/<?= (int) $neustes['id'] ?>">
                    Website herunterladen
                </a>
            <?php else: ?>
                <a class="wa-btn wa-btn--sm"
                   href="<?= e($base) ?>/projekt/<?= (int) $projekt['id'] ?>/veroeffentlichen">
                    Paket erstellen
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="wa-editor__bar">
        <div class="wa-editor__group">
            <a class="wa-btn wa-btn--quiet wa-btn--sm"
               href="<?= e($base) ?>/projekt/<?= (int) $projekt['id'] ?>">← Projekt</a>

            <?php if (count($seiten) > 1): ?>
                <label class="wa-sr" for="ed-seite">Seite</label>
                <select class="wa-select wa-select--sm" id="ed-seite" data-editor-page>
                    <?php foreach ($seiten as $eine): ?>
                        <option value="<?= (int) $eine['id'] ?>"
                            <?= (int) $eine['id'] === (int) $seite['id'] ? 'selected' : '' ?>>
                            <?= e((string) $eine['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <span class="wa-editor__name"><?= e((string) $seite['title']) ?></span>
            <?php endif; ?>
        </div>

        <div class="wa-editor__group wa-editor__group--breiten" data-editor-widths>
            <button type="button" class="wa-btn wa-btn--quiet wa-btn--sm is-active"
                    data-width="desktop">Desktop</button>
            <button type="button" class="wa-btn wa-btn--quiet wa-btn--sm"
                    data-width="tablet">Tablet</button>
            <button type="button" class="wa-btn wa-btn--quiet wa-btn--sm"
                    data-width="handy">Handy</button>
        </div>

        <div class="wa-editor__group">
            <span class="wa-editor__stand" data-editor-state
                  data-draft="<?= $entwurf ? '1' : '0' ?>">
                <?= $entwurf ? 'Entwurf' : 'Veröffentlicht' ?>
            </span>

            <button type="button" class="wa-btn wa-btn--quiet wa-btn--sm"
                    data-editor-action="verwerfen">Verwerfen</button>

            <button type="button" class="wa-btn wa-btn--sm"
                    data-editor-action="veroeffentlichen">Veröffentlichen</button>
        </div>
    </div>

    <div class="wa-editor__stage" data-editor-stage>
        <iframe class="wa-editor__frame"
                data-editor-frame
                title="Die Seite, wie der Besucher sie sieht"
                src="<?= e((string) $daten['rahmen']) ?>"></iframe>
    </div>
</div>

<script id="wa-editor-data" type="application/json"><?= json_encode(
    $daten,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>
