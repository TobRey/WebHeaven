<?php
/**
 * Rahmen des Adminbereichs.
 *
 * Bewusst dieselben Design-Tokens wie die öffentliche Website – der
 * Verwaltungsbereich soll sich wie ein Teil davon anfühlen, nicht wie
 * ein fremdes Werkzeug.
 */

use WebAtze\Core\{Assets, Config, Csrf};

/** @var string $content */
/** @var string $nonce */
/** @var array|null $authUser */
/** @var array $flash */
/** @var string $currentPath */

$title = $title ?? 'Verwaltung';
$base = '/' . trim((string) Config::get('create_path', 'create'), '/');

// Zeichen an den Menuepunkten: eine Zahl je Bereich, an dem etwas auf
// mich wartet. Gezaehlt wird weiterhin fein, angezeigt wird grob -
// seit das Menue nur noch sechs Punkte hat, muessen mehrere Zahlen an
// einem davon zusammenfinden. Sonst waere nach dem Umbau kein einziges
// Zeichen mehr sichtbar, und eine Supportfrage laege wieder drei Tage.
$zeichen = \WebAtze\Domain\Notices::forMenu();

/**
 * Sechs Punkte. Vorher waren es zweiundzwanzig.
 *
 * Zweiundzwanzig Eintraege sind keine Navigation mehr, sondern eine
 * Suchaufgabe: Man liest sie nicht, man scannt sie, und beim dritten
 * Mal klickt man daneben. Und die meisten davon waren Listen ueber
 * alle Kunden hinweg - waehrend die Frage, die man tatsaechlich hat,
 * fast immer einen einzelnen Kunden betrifft.
 *
 * Also gilt jetzt: Was zu einem Kunden gehoert, steht beim Kunden.
 * Rechnungen, Vertraege, Websites, Passwoerter, Support, Fragebogen,
 * Besucherzahlen - alles auf seiner Seite, mit "Rechnung erstellen"
 * daneben statt einem Umweg ueber eine gefilterte Gesamtliste.
 *
 * Was zu keinem gehoert, sammelt "Ohne Zuordnung" oben in der
 * Kundenliste. Ohne diese Sammelstelle waere es unerreichbar geworden,
 * und dann duerfte das Menue gar nicht schrumpfen.
 *
 * Keine einzige Route wurde geloescht. Alte Lesezeichen, Verweise aus
 * dem Protokoll und Links in verschickten E-Mails funktionieren
 * weiter - nur steht ihr Ziel nicht mehr im Menue. Sechs Punkte sind
 * eine Vermutung darueber, was taeglich gebraucht wird; ein
 * Lesezeichen, das ins Leere laeuft, waere teurer als ein Eintrag zu
 * viel im Router.
 */
$nav = [
    ['/start', 'Übersicht', 'grid'],
    ['/kunden', 'Kunden', 'users'],
    ['/neu', 'Neue Website', 'plus'],
    ['/kalender', 'Kalender', 'calendar'],
    ['/buchhaltung', 'Buchhaltung', 'coins'],
    ['/einstellungen', 'Einstellungen', 'gear'],
];

/**
 * Was unter den sechs Punkten liegt.
 *
 * Aus dem Menue genommen heisst nicht abgeschafft: Diese Bereiche
 * beantworten Fragen, die tatsaechlich ueber alle Kunden gehen - "wer
 * schuldet mir noch was", "welche Website ist ausgefallen", "was habe
 * ich letzten Monat ausgegeben". Nur sind das keine Fragen, die man
 * jeden Morgen stellt, und deshalb kosten sie jetzt einen Klick mehr
 * statt einen Menueplatz.
 *
 * Gezeigt wird die Zeile ueberall unterhalb des jeweiligen Punktes -
 * zentral hier und nicht in jeder Ansicht einzeln, weil sie sonst
 * genau dort fehlt, wo man sie das erste Mal sucht.
 */
$unterNav = [
    '/start' => [
        // Der Bereich selbst gehoert in die Liste: Ueber sie wird
        // bestimmt, in welchem Bereich man steht, und ohne diesen
        // Eintrag zeigte die Uebersicht ihre eigene Unternavigation
        // nicht an - man sah sie erst, wenn man schon drin war.
        ['/start', 'Was ansteht'],
        ['/anfragen', 'Anfragen'],
        ['/support', 'Support'],
        ['/fragebogen', 'Fragebögen'],
        ['/wartung', 'Wartungscenter'],
        ['/potenzielle-kunden', 'Potenzielle Kunden'],
    ],
    '/kunden' => [
        ['/kunden', 'Alle Kunden'],
        ['/kundensuche', 'Kundensuche'],
        ['/websites', 'Alle Websites'],
    ],
    '/buchhaltung' => [
        ['/buchhaltung', 'Übersicht'],
        ['/rechnungen', 'Offerten und Rechnungen'],
        ['/vertraege', 'Wartungsverträge'],
        ['/kosten', 'Kosten'],
    ],
    '/einstellungen' => [
        ['/einstellungen', 'Grundeinstellungen'],
        ['/mitarbeitende', 'Mitarbeitende'],
        ['/passwoerter', 'Passwörter'],
        ['/referenzen', 'Referenzen'],
        ['/zahlen', 'Besucher'],
        ['/intranet', 'Intranet'],
        ['/protokoll', 'Protokoll'],
    ],
];

/* Zu welchem der sechs Punkte gehoert die Seite, auf der wir gerade
   sind? Gesucht wird ueber die Unternavigation selbst - so bleibt die
   Zuordnung an genau einer Stelle stehen. */
$bereich = '';

foreach ($unterNav as $wurzel => $eintraege) {
    foreach ($eintraege as [$pfad, $unused]) {
        $ziel = $base . $pfad;

        if ($currentPath === $ziel || str_starts_with($currentPath, $ziel . '/')) {
            $bereich = $wurzel;
            break 2;
        }
    }
}
?>
<!doctype html>
<html lang="de" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <?php /* "same-origin" statt "no-referrer": Die geheime Adresse verlässt
             diese Seite trotzdem nie. "no-referrer" hingegen lässt Browser
             bei jedem Formular "Origin: null" senden – dann scheitert die
             Herkunftsprüfung und keine einzige Schaltfläche funktioniert. */ ?>
    <meta name="referrer" content="same-origin">
    <title><?= e($title) ?> · Verwaltung</title>
    <meta name="theme-color" content="#06060f">
    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(Assets::url('admin.css')) ?>">
    <?php
    /**
     * Hell oder dunkel, bevor das erste Bild gezeichnet wird.
     *
     * Steht das erst im geladenen Skript, blitzt bei jedem Seitenwechsel
     * kurz der dunkle Entwurf auf, obwohl hell gewählt ist. Deshalb hier
     * oben und ohne Umweg.
     *
     * Derselbe Schlüssel wie auf der öffentlichen Website: Wer dort hell
     * gewählt hat, bekommt es auch hier - eine Einstellung, nicht zwei.
     */
    ?>
    <script nonce="<?= e($nonce) ?>">
        document.documentElement.classList.remove('no-js');
        try {
            if (localStorage.getItem('webatze-theme') === 'light') {
                document.documentElement.setAttribute('data-theme', 'light');
                document.querySelector('meta[name="theme-color"]').setAttribute('content', '#ffffff');
            }
        } catch (e) { /* privates Fenster - dann eben dunkel */ }
    </script>
    <script type="module" src="<?= e(Assets::url('admin.js')) ?>" defer></script>
    <script nonce="<?= e($nonce) ?>">window.WA_CSRF = <?= json_out(Csrf::token()) ?>;</script>

    <?php
    /**
     * Der Editor ist ein eigenes Bündel und wird nur dort geladen, wo er
     * gebraucht wird. Er ist das grösste Stück JavaScript im
     * Adminbereich, und die wenigsten Seiten brauchen ihn.
     *
     * Seit er als eigenes Paket ausgeliefert wird, kann er auch fehlen.
     * Vorher hätte das zwei stille 404er ergeben – die Seite lädt,
     * nichts funktioniert, und niemand erfährt warum. Deshalb wird
     * vorher nachgesehen, und wo er fehlt, steht es da.
     */
    ?>
    <?php if (($bodyClass ?? '') === 'wa-admin--editor' && Assets::exists('editor.js')): ?>
        <link rel="stylesheet" href="<?= e(Assets::url('editor.css')) ?>">
        <script type="module" src="<?= e(Assets::url('editor.js')) ?>" defer></script>
    <?php endif; ?>
</head>
<body class="wa-admin<?= ($bodyClass ?? '') !== '' ? ' ' . e((string) $bodyClass) : '' ?>">

<a class="wa-skip" href="#admin-inhalt">Direkt zum Inhalt</a>

<?php /*
    Das Menue auf dem Handy haengt bewusst nicht an JavaScript. Ein
    Verwaltungsbereich, dessen Navigation verschwindet, sobald ein
    Skript nicht laedt - zwischengespeicherte alte Datei, blockierte
    Adresse, abgeschaltetes JavaScript - ist unbedienbar. Deshalb
    schaltet ein Kaestchen die Leiste, rein ueber CSS. Das Skript
    ergaenzt nur noch das Verdunkeln und die Escape-Taste.
*/ ?>
<input type="checkbox" id="wa-admin-nav" class="wa-admin__nav-state" aria-label="Menue anzeigen">

<aside class="wa-admin__side">
    <a class="wa-admin__brand" href="<?= e($base) ?>/start">
        <?= View_partial('partials/logo', ['size' => 1.8, 'markOnly' => true]) ?>
        <span>WebAtze</span>
    </a>

    <nav class="wa-admin__nav" aria-label="Verwaltung">
        <?php foreach ($nav as [$path, $label, $icon]): ?>
            <?php $href = $base . $path; ?>
            <?php
                /* Nicht nur str_starts_with: '/create/kundensuche' beginnt
                   mit '/create/kunden', und dann leuchteten beide Eintraege.
                   Es zaehlt der Eintrag selbst oder ein Pfad darunter. */
                $hier = $currentPath === $href
                    || str_starts_with($currentPath, $href . '/')
                    // Auch von einem Unterbereich aus: Wer im Protokoll
                    // steht, soll sehen, dass er unter Einstellungen ist.
                    || $bereich === $path;
            ?>
            <?php $meldung = $zeichen[$path] ?? null; ?>
            <a class="wa-admin__link" href="<?= e($href) ?>"
               <?= $hier ? 'aria-current="page"' : '' ?>>
                <?= View_partial('partials/admin-icons', ['name' => $icon]) ?>
                <span><?= e($label) ?></span>
                <?php if ($meldung !== null): ?>
                    <?php /* Die Zahl allein sagt einem Screenreader nichts -
                             deshalb steht daneben, worum es geht. */ ?>
                    <span class="wa-admin__zeichen<?= $meldung['dringend'] ? ' wa-admin__zeichen--dringend' : '' ?>"
                          aria-hidden="true"><?= (int) $meldung['anzahl'] > 99 ? '99+' : (int) $meldung['anzahl'] ?></span>
                    <span class="wa-sr"><?= (int) $meldung['anzahl'] ?> <?= e($meldung['was']) ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="wa-admin__side-foot">
        <?php
        /* Beschriftet wird, was der Klick bringt - nicht, was gerade
           gilt. "Hell" auf dunklem Grund ist eine Ansage, "Dunkel" auf
           dunklem Grund eine Zustandsmeldung, und die liest man als
           Ansage und drueckt zweimal. Das Umschalten selbst uebernimmt
           das Skript; ohne Skript bleibt der Knopf weg statt tot
           dazustehen. */
        ?>
        <button type="button" class="wa-admin__link wa-admin__link--button wa-theme-switch"
                data-admin-theme hidden>
            <span class="wa-theme-switch__icon wa-theme-switch__icon--sun">
                <?= View_partial('partials/admin-icons', ['name' => 'sun']) ?>
            </span>
            <span class="wa-theme-switch__icon wa-theme-switch__icon--moon">
                <?= View_partial('partials/admin-icons', ['name' => 'moon']) ?>
            </span>
            <span data-admin-theme-label>Heller Modus</span>
        </button>
        <a class="wa-admin__link" href="/" target="_blank" rel="noopener">
            <?= View_partial('partials/admin-icons', ['name' => 'external']) ?>
            <span>Website ansehen</span>
        </a>
        <form method="post" action="<?= e($base) ?>/abmelden">
            <?= Csrf::field() ?>
            <button type="submit" class="wa-admin__link wa-admin__link--button">
                <?= View_partial('partials/admin-icons', ['name' => 'logout']) ?>
                <span>Abmelden<?= $authUser ? ' (' . e($authUser['username']) . ')' : '' ?></span>
            </button>
        </form>
    </div>
</aside>

<div class="wa-admin__main">
    <header class="wa-admin__topbar">
        <label for="wa-admin-nav" class="wa-burger wa-admin__burger" data-admin-nav-toggle>
            <span></span><span></span><span></span>
        </label>
        <h1 class="wa-admin__title"><?= e($title) ?></h1>
        <?php if (!empty($headerActions)): ?>
            <div class="wa-admin__actions"><?= $headerActions ?></div>
        <?php endif; ?>
    </header>

    <?php if ($bereich !== '' && count($unterNav[$bereich]) > 1): ?>
        <nav class="wa-subnav" aria-label="Bereiche">
            <?php foreach ($unterNav[$bereich] as [$pfad, $text]): ?>
                <?php
                    $ziel = $base . $pfad;
                    $aktiv = $currentPath === $ziel || str_starts_with($currentPath, $ziel . '/');
                ?>
                <a class="wa-subnav__link<?= $aktiv ? ' is-active' : '' ?>"
                   href="<?= e($ziel) ?>"<?= $aktiv ? ' aria-current="page"' : '' ?>>
                    <?= e($text) ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <main id="admin-inhalt" class="wa-admin__content">
        <?php if (($bodyClass ?? '') === 'wa-admin--editor' && !Assets::exists('editor.js')): ?>
            <div class="wa-note wa-note--warning" role="status">
                <div>
                    <strong>Der Editor ist nicht installiert.</strong>
                    Er kommt als eigenes Paket, damit ein Editor-Update kein neues
                    Hauptpaket braucht &ndash; und ein neues Hauptpaket nicht den
                    Editor umwirft.
                    <a href="<?= e($base) ?>/einstellungen#editor">Jetzt hochladen</a>.
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($flash as $message): ?>
            <div class="wa-note wa-note--<?= e($message['type'] === 'error' ? 'danger' : $message['type']) ?>" role="status">
                <div><?= e($message['message']) ?></div>
            </div>
        <?php endforeach; ?>

        <?= $content ?>
    </main>
</div>

</body>
</html>
