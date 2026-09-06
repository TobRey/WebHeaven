<?php

/**
 * Eine fremde Website bearbeiten.
 *
 * Die Seite läuft im Rahmen, mit gleicher Herkunft wie diese Seite -
 * deshalb greift das Skript daneben direkt hinein. In die Kundendatei
 * wird nichts eingeschleust: Was gespeichert wird, ist die Seite selbst,
 * ohne eine Spur der Bearbeitung.
 *
 * @var array $projekt @var list<string> $seiten @var string $seite
 * @var array $pakete @var bool $offen
 */

use WebAtze\Core\{Assets, Config, Csrf};

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
$id = (int) $projekt['id'];
$neustes = ($pakete ?? [])[0] ?? null;
$hatSeiten = ($seiten ?? []) !== [];

$daten = json_out([
    'id' => $id,
    'base' => $base,
    'seite' => (string) ($seite ?? ''),
    'token' => Csrf::token(),
]);
?>
<link rel="stylesheet" href="<?= e(Assets::url('direkt.css')) ?>">
<script id="wa-direkt-daten" type="application/json"><?= $daten ?></script>
<script src="<?= e(Assets::url('direkt.js')) ?>" defer></script>

<div class="wa-direkt" data-direkt>
    <div class="wa-direkt__leiste">
        <div class="wa-direkt__gruppe">
            <a class="wa-btn wa-btn--quiet wa-btn--sm"
               href="<?= e($base) ?>/projekt/<?= $id ?>/veroeffentlichen">← Zurück</a>
            <strong class="wa-direkt__name"><?= e((string) $projekt['name']) ?></strong>

            <?php if ($hatSeiten): ?>
                <label class="wa-sr" for="wa-direkt-seite">Seite</label>
                <select class="wa-select wa-select--sm" id="wa-direkt-seite" data-direkt-seite>
                    <?php foreach ($seiten as $eine): ?>
                        <option value="<?= e($eine) ?>"<?= $eine === $seite ? ' selected' : '' ?>>
                            <?= e($eine) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>

        <div class="wa-direkt__gruppe">
            <?php /* Der Zustand ist die halbe Auskunft: Wer nicht sieht,
                     dass etwas offen ist, speichert nicht - und
                     wundert sich, dass der Kunde nichts merkt. */ ?>
            <span class="wa-direkt__stand" data-direkt-stand>bereit</span>

            <button type="button" class="wa-btn wa-btn--sm" data-direkt-speichern disabled>
                Speichern
            </button>

            <?php if ($neustes !== null): ?>
                <a class="wa-btn wa-btn--primary wa-btn--sm"
                   href="<?= e($base) ?>/projekt/<?= $id ?>/zip/<?= (int) $neustes['id'] ?>"
                   data-direkt-holen>Website herunterladen</a>
            <?php endif; ?>

            <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/zip"
                  class="wa-direkt__form">
                <?= Csrf::field() ?>
                <button type="submit" class="wa-btn wa-btn--sm">Neues Paket</button>
            </form>
        </div>
    </div>

    <?php if (!$hatSeiten): ?>
        <div class="wa-direkt__leer">
            <div>
                <h2>Hier liegt noch keine Seite</h2>
                <p>
                    Lade zuerst das Archiv der Website hoch &ndash; das ZIP, das du mit
                    deinem FTP-Programm vom Hosting des Kunden geholt hast. Danach steht
                    die Seite hier und lässt sich Wort für Wort bearbeiten.
                </p>
                <a class="wa-btn wa-btn--primary"
                   href="<?= e($base) ?>/projekt/<?= $id ?>/veroeffentlichen">
                    Archiv hochladen
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="wa-direkt__hinweis" data-direkt-hinweis>
            <strong>Text ändern:</strong> anklicken und schreiben.
            <strong>Bild tauschen:</strong> auf das Bild klicken.
            Danach speichern und das Paket herunterladen.
        </div>

        <div class="wa-direkt__buehne">
            <iframe class="wa-direkt__rahmen" data-direkt-rahmen
                    title="Die Website zum Bearbeiten"
                    src="<?= e($base) ?>/direkt/<?= $id ?>/datei/<?= e($seite) ?>"></iframe>
        </div>

        <?php /* Bewusst versteckt und nicht per JavaScript erzeugt: Ein
                 Dateifeld, das der Browser selbst kennt, oeffnet die
                 Dateiauswahl auch dann, wenn sonst etwas klemmt. */ ?>
        <input type="file" class="wa-sr" data-direkt-bildfeld
               accept="image/png,image/jpeg,image/webp,image/gif,image/avif,image/svg+xml">
    <?php endif; ?>
</div>
