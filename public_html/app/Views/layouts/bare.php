<?php
/**
 * Nackter Rahmen ohne Navigation – für die Anmeldung.
 *
 * Bewusst ohne Logo und ohne Firmennamen: Wer die Adresse zufällig findet,
 * soll nicht sofort wissen, wozu sie gehört.
 */

use WebAtze\Core\Assets;

/** @var string $content */
/** @var string $nonce */
/** @var array $flash */

$title = $title ?? 'Anmelden';
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
    <title><?= e($title) ?></title>
    <meta name="theme-color" content="#06060f">
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="<?= e(Assets::url('admin.css')) ?>">
    <?php /* Auch die Anmeldeseite folgt der Wahl. Sonst meldet man sich
             auf dunklem Grund an und landet auf hellem - und der erste
             Eindruck ist ein Ruckeln, das nach Fehler aussieht. Einen
             Umschalter gibt es hier nicht: Vor der Anmeldung gibt es
             nichts einzustellen. */ ?>
    <script nonce="<?= e($nonce) ?>">
        document.documentElement.classList.remove('no-js');
        try {
            if (localStorage.getItem('webatze-theme') === 'light') {
                document.documentElement.setAttribute('data-theme', 'light');
                document.querySelector('meta[name="theme-color"]').setAttribute('content', '#ffffff');
            }
        } catch (e) { /* privates Fenster - dann eben dunkel */ }
    </script>
</head>
<body class="wa-bare">
    <main class="wa-bare__main">
        <?php /* Auch hier müssen Meldungen ankommen. Sonst täte ein
                 abgelehnter Anmeldeversuch scheinbar gar nichts. */ ?>
        <?php foreach ($flash ?? [] as $message): ?>
            <div class="wa-note wa-note--<?= e($message['type'] === 'error' ? 'danger' : $message['type']) ?>"
                 role="alert"><div><?= e($message['message']) ?></div></div>
        <?php endforeach; ?>

        <?= $content ?>
    </main>
</body>
</html>
