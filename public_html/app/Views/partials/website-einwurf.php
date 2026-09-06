<?php

/**
 * Das Fenster, mit dem eine Website bearbeitet wird.
 *
 * Die ganze Schleife an einer Stelle, in der Reihenfolge, in der sie
 * abläuft: Archiv einwerfen, bearbeiten, Paket herausnehmen.
 *
 * Warum ein Archiv und kein Knopf, der es holt: Von einem Hosting zum
 * anderen kommt keine Verbindung durch - gemessen an FTP, an einer
 * Empfangsdatei über HTTPS und an einer dauerhaften Leseschnittstelle.
 * Vom eigenen Rechner aus geht es. Also überträgt der Mensch, und
 * WebAtze ist die Werkstatt dazwischen.
 *
 * @var array<string, mixed> $website
 * @var string $base
 */

use WebAtze\Build\Uebernahme;
use WebAtze\Core\Csrf;

$w = $website ?? [];
$id = (int) ($w['id'] ?? 0);
$liegt = Uebernahme::vorhanden($w);
?>
<div class="wa-einwurf">
    <ol class="wa-einwurf__schritte">
        <li>
            <strong>Archiv einwerfen.</strong>
            Das ZIP, das du mit deinem FTP-Programm vom Hosting des Kunden geholt hast.
        </li>
        <li><strong>Bearbeiten.</strong> Texte anklicken und schreiben, Bilder tauschen.</li>
        <li>
            <strong>Paket herausnehmen</strong> und mit deinem FTP-Programm wieder
            hinaufladen. Erst dann ist die Website beim Kunden geändert.
        </li>
    </ol>

    <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/uebernehmen"
          enctype="multipart/form-data" class="wa-form">
        <?= Csrf::field() ?>

        <div class="wa-field">
            <label class="wa-label" for="einwurf-<?= $id ?>">Archiv der Website (ZIP)</label>
            <input class="wa-input" type="file" id="einwurf-<?= $id ?>" name="archiv"
                   accept=".zip,application/zip" required>
            <span class="wa-label__hint">
                Liegt alles in einem Ordner, wird der weggeschnitten.
                <?php if ($liegt): ?>
                    Es liegt bereits ein Stand hier &ndash; ein neues Archiv ersetzt ihn.
                <?php endif; ?>
            </span>
        </div>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn wa-btn--primary"
                    data-confirm="Den Stand aus diesem Archiv übernehmen? Ein bereits hochgeladener Stand wird ersetzt.">
                Hochladen und bearbeiten
            </button>

            <?php /* Nur wenn schon etwas daliegt: Ein Knopf, der in einen
                     leeren Editor fuehrt, verspricht eine Wirkung, die er
                     nicht hat. */ ?>
            <?php if ($liegt): ?>
                <a class="wa-btn" href="<?= e($base) ?>/direkt/<?= $id ?>">
                    Ohne neues Archiv weiterbearbeiten
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>
