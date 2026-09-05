<?php

/**
 * Der fertige Auftrag zum Kopieren.
 *
 * Absichtlich schlicht: eine Schaltfläche, ein Textfeld, sonst nichts.
 * Wer hier ist, will kopieren und weitermachen.
 */

use WebAtze\Core\Csrf;

/** @var array<string, mixed> $project */
/** @var string $prompt */
/** @var string $dateiname */
/** @var string $base */
/** @var array<string, mixed> $anschluss */

$id = (int) $project['id'];
$anschluss = $anschluss ?? [];
?>

<section class="wa-panel">
    <header class="wa-panel__head">
        <div>
            <h2 class="wa-panel__title">Auftrag für <?= e((string) $project['name']) ?></h2>
            <p class="wa-panel__hint">
                Kopieren, in Claude Code einfügen, absenden. Mehr braucht es nicht.
            </p>
        </div>

        <div class="wa-panel__actions">
            <button type="button" class="wa-btn wa-btn--primary"
                    data-copy="#auftragstext"
                    data-copy-done="Kopiert – jetzt einfügen">
                In die Zwischenablage
            </button>

            <a class="wa-btn" download="<?= e($dateiname) ?>"
               href="data:text/markdown;charset=utf-8,<?= rawurlencode($prompt) ?>">
                Als Datei
            </a>
        </div>
    </header>

    <textarea id="auftragstext" class="wa-prompt" rows="24" readonly
              spellcheck="false" aria-label="Der Auftragstext"><?= e($prompt) ?></textarea>

    <p class="wa-panel__hint">
        <?= number_format(mb_strlen($prompt), 0, ',', "'") ?> Zeichen.
        Alles aus dem Formular steht darin &ndash; wer den Text einfügt, hat
        das Formular nicht gesehen und kann nicht nachfragen.
    </p>
</section>

<?php /*
    Was im Auftrag steckt. Es steht hier nicht zum Abschreiben, sondern
    zum Nachschauen: Der Zugangscode ist das Einzige, was ich dem Kunden
    weitergebe - alles andere baut sich von selbst ein.
*/ ?>
<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Was schon verdrahtet ist</h2>
        <p class="wa-panel__hint">
            Diese Werte stehen fertig im Auftrag. Auf der neuen Website ist danach
            nichts mehr einzurichten.
        </p>
    </header>

    <dl class="wa-facts">
        <dt>Anleitung</dt>
        <dd><code>/doc</code> – wird mitgebaut</dd>

        <dt>Hilfeseite</dt>
        <dd>
            <code>/support</code> – meldet sich an
            <code><?= e((string) ($anschluss['url'] ?? '')) ?>/assistant/v1/support</code>
            und erscheint bei dir unter «Support»
        </dd>

        <dt>Zugangscode für den Kunden</dt>
        <dd>
            <code><?= e((string) ($anschluss['support_code'] ?? '')) ?></code>
            <p class="wa-hint">
                Das Einzige, was du weitergibst. Ohne ihn zeigt die Hilfeseite kein
                Formular – so kann kein Werbeprogramm darüber schreiben.
            </p>
        </dd>

        <dt>Besucherzählung</dt>
        <dd>
            <code>&lt;script defer src="<?= e((string) ($anschluss['url'] ?? '')) ?>/z.js?k=<?= e((string) ($anschluss['visit_key'] ?? '')) ?>"&gt;&lt;/script&gt;</code>
            <p class="wa-hint">
                Steht im Auftrag und kommt auf jede Seite. Du fügst nichts von Hand ein.
            </p>
        </dd>
    </dl>

    <div class="wa-note">
        <div>
            <strong>Im Auftragstext steht ein echter Schlüssel.</strong>
            <p class="wa-hint">
                Er kann genau zweierlei: eine Supportnachricht dieser einen Website
                senden und deren Gesprächsfaden lesen. Nicht den Abschnitts-Editor,
                nicht andere Websites, keine Daten ändern. Wenn ein Auftragstext
                einmal irgendwo landet, wo er nicht hingehört, erzeugst du unter
                <a href="<?= e($base) ?>/projekt/<?= $id ?>">Projekt</a> einen neuen –
                der alte gilt dann nicht mehr.
            </p>
        </div>
    </div>
</section>

<section class="wa-panel">
    <h2 class="wa-panel__title">So geht es weiter</h2>

    <ol class="wa-steps">
        <li>Text kopieren und einfügen.</li>
        <li>
            Am Ende kommt <strong>ein ZIP</strong> heraus &ndash; der Auftragstext
            verlangt es so. Herunterladen, nicht auspacken.
        </li>
        <li>
            Unter <a href="<?= e($base) ?>/projekt/<?= $id ?>/veroeffentlichen">Veröffentlichen</a>
            bei &bdquo;Website hochladen&ldquo; auswählen. Das Archiv geht direkt
            aufs Webhosting &ndash; ohne Umweg über einen Bau hier im Haus.
        </li>
    </ol>

    <p class="wa-panel__hint">
        Der Auftrag lässt sich jederzeit neu erzeugen. Änderst du etwas am
        Projekt, ändert sich auch der Text.
    </p>
</section>

<section class="wa-panel">
    <h2 class="wa-panel__title">Lieber automatisch?</h2>

    <p class="wa-panel__hint">
        WebAtze kann die Website auch selbst bauen, ohne Kopieren. Das läuft
        über die Schnittstelle, kostet Guthaben und dauert je nach Umfang
        einige Minuten.
    </p>

    <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/bauen"
          data-confirm="Die Website automatisch bauen lassen? Das kostet Guthaben.">
        <?= Csrf::field() ?>
        <button type="submit" class="wa-btn">Automatisch bauen lassen</button>
    </form>
</section>
