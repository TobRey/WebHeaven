<?php

declare(strict_types=1);

namespace WebAtze\Build;

use WebAtze\Core\{Config, Db, Logger};
use WebAtze\Ai\Translator;
use WebAtze\Templates\{Page, Renderer};

/**
 * Baut aus den gespeicherten Seiten und Abschnitten die fertige Website.
 *
 * Das Ergebnis ist eine gewöhnliche Website aus HTML, CSS und ein wenig
 * JavaScript – keine Datenbank, kein Rahmenwerk, nichts, was installiert
 * werden müsste. Sie läuft auf jedem Hosting und lässt sich in zehn
 * Jahren noch öffnen.
 *
 * Eine Datenbank entsteht nur, wenn ausdrücklich etwas gewünscht wurde,
 * das etwas speichern muss (Buchungen, Bestellungen). Dann kommt ein
 * eigenes Modul dazu.
 */
final class SiteBuilder
{
    private array $project;
    private array $theme;
    private array $pages;
    private string $outputDir;

    public function __construct(array $project, string $outputDir)
    {
        $this->project = $project;
        $this->outputDir = rtrim($outputDir, '/');

        $theme = json_decode((string) ($project['theme'] ?? '{}'), true);
        $this->theme = is_array($theme) && $theme !== [] ? $theme : Theme::build([]);

        $this->pages = self::loadPages((int) $project['id']);
    }

    /**
     * Alles bauen.
     *
     * @return array{files:int, bytes:int, pages:int}
     */
    public function build(): array
    {
        ensure_dir($this->outputDir);
        ensure_dir($this->outputDir . '/assets/css');
        ensure_dir($this->outputDir . '/assets/js');
        ensure_dir($this->outputDir . '/assets/img');

        $files = 0;

        // --- Stylesheets ------------------------------------------------
        $files += $this->writeStyles();

        // --- Skript -----------------------------------------------------
        $kitJs = APP_DIR . '/Kit/site/js/site.js';
        if (is_file($kitJs)) {
            write_file_atomic($this->outputDir . '/assets/js/site.js', (string) file_get_contents($kitJs));
            $files++;
        }

        // --- Bilder -----------------------------------------------------
        $files += $this->copyAssets();

        // --- Seiten, je Sprache einmal ----------------------------------
        // Die Hauptsprache liegt in der Wurzel, jede weitere in ihrem
        // eigenen Ordner. Das hält die Adressen der Hauptsprache kurz –
        // sie sind die, die verlinkt und gefunden werden.
        $site = $this->site();
        $locales = $site['locales'];
        $primary = $locales[0];
        $critical = self::criticalCss();

        foreach ($locales as $locale) {
            foreach ($this->pages as $page) {
                $html = Page::render($site, $page, $critical, $locale);
                $target = $this->outputDir . '/'
                    . Page::fileNameFor((string) $page['path'], $locale, $primary);

                ensure_dir(dirname($target));
                write_file_atomic($target, $html);
                $files++;
            }
        }

        // --- Beiwerk ----------------------------------------------------
        $files += $this->writeExtras();

        // --- Bearbeitungsbereich, falls gewünscht ------------------------
        $files += AdminKit::install($this->project, $this->site(), $this->outputDir);

        // --- Anleitung unter /doc, falls gewünscht ------------------------
        $files += DocBuilder::write($this->project, $this->site(), $this->outputDir);

        // --- Vorschaubild für geteilte Verweise ---------------------------
        $files += OgImage::write($this->project, $this->theme, $this->outputDir);

        // --- Terminbuchung, falls in den Angaben beschrieben ---------------
        $files += BookingKit::install(
            $this->project,
            json_decode((string) ($this->project['brief'] ?? '{}'), true) ?: [],
            $this->outputDir
        );

        return [
            'files' => $files,
            'bytes' => dir_size($this->outputDir),
            'pages' => count($this->pages),
        ];
    }

    /** Eine einzelne Seite als HTML. */
    public function renderPage(array $page): string
    {
        return Page::render($this->site(), $page, self::criticalCss());
    }

    /**
     * Die Website als einfache Felder – ohne Datenbank.
     *
     * Genau diese Form landet auch in site.json beim Kunden. Sein
     * Bearbeitungsbereich baut damit dieselben Seiten wie hier; es gibt
     * nur einen Rahmen (Templates\Page), nicht zwei, die auseinander
     * laufen könnten.
     */
    public function site(): array
    {
        $brief = json_decode((string) ($this->project['brief'] ?? '{}'), true) ?: [];

        return [
            'brand' => (string) $this->project['name'],
            'locale' => (string) ($this->project['locale'] ?? 'de'),
            'locales' => Translator::localesOf($this->project),
            'theme' => $this->theme,
            'pages' => $this->pages,
            'logo' => $this->logo(),
            'contact' => [
                'email' => (string) ($brief['contact_email'] ?? ''),
                'phone' => (string) ($brief['contact_phone'] ?? ''),
                'address' => (string) ($brief['contact_address'] ?? ''),
            ],
            // Die Domain steht im Kopf jeder Seite: Das Vorschaubild für
            // geteilte Verweise braucht eine vollständige Adresse.
            'domain' => (string) ($this->project['domain'] ?? ''),
            // Ohne die GD-Erweiterung entsteht kein Vorschaubild – dann
            // darf auch keine Seite darauf verweisen.
            'preview_image' => function_exists('imagecreatetruecolor'),
            // Die Zählung wird angehakt - wer sie nicht will, bekommt
            // keine einzige Zeile davon.
            'counter' => !empty($brief['wants_stats']),
            'support' => !empty($brief['wants_support']),
            'docs' => !empty($brief['wants_docs']),
            // Die Zählzeile, aber nur bei angehakter Zählung. Sie steht
            // dann auf JEDER gebauten Seite - eine Seite, die als
            // einzige nicht zählt, verfälscht die Zahlen mehr, als sie
            // nützt.
            'visit_url' => !empty($brief['wants_stats'])
                ? rtrim((string) Config::get('app_url', ''), '/')
                : '',
            'visit_key' => !empty($brief['wants_stats'])
                ? \WebAtze\Domain\Visits::key((int) $this->project['id'])
                : '',
        ];
    }

    /** Das Logo, falls eines hochgeladen wurde. */
    private function logo(): ?array
    {
        $asset = Db::first(
            "SELECT * FROM project_assets WHERE project_id = :p AND kind = 'logo' ORDER BY id DESC LIMIT 1",
            ['p' => (int) $this->project['id']]
        );

        if ($asset === null) {
            return null;
        }

        return [
            'src' => 'assets/img/' . basename((string) $asset['path']),
            'alt' => (string) $this->project['name'],
            'width' => (int) $asset['width'],
            'height' => (int) $asset['height'],
        ];
    }

    /** Alle Stylesheets zu einer Datei zusammenfügen. */
    private function writeStyles(): int
    {
        $kit = APP_DIR . '/Kit/site/css';
        $parts = [Theme::toCss($this->theme)];

        foreach (['tokens', 'base', 'layout', 'sections', 'motion'] as $name) {
            $file = $kit . '/' . $name . '.css';
            if (!is_file($file)) {
                continue;
            }
            $css = (string) file_get_contents($file);

            // tokens.css enthält die Voreinstellungen. Die echten Farben
            // stehen davor – deshalb hier nur den Teil ohne Farben nehmen.
            if ($name === 'tokens') {
                $css = self::stripDefaultColours($css);
            }

            $parts[] = "\n/* ---- " . $name . " ---- */\n" . $css;
        }

        $css = implode("\n", $parts);
        write_file_atomic($this->outputDir . '/assets/css/site.css', self::minifyCss($css));

        return 1;
    }

    /**
     * Aus tokens.css die Farbwerte entfernen.
     *
     * Sie stehen bereits im erzeugten Farbblock davor; doppelte Werte
     * würden die Kundenfarben wieder überschreiben.
     */
    /**
     * Öffentlich, weil das Stylesheet der eigenen Website zur Laufzeit
     * aus denselben Teilen entsteht (Http\StyleController). Zwei
     * Fassungen derselben Regel würden auseinanderlaufen, und dann sähe
     * die eigene Website anders aus als eine gebaute.
     */
    public static function stripDefaultColours(string $css): string
    {
        // Nur genau diese Namen dürfen weg. Eine Suche nach "beginnt mit
        // --c-text" würde auch --c-text-hero und die ganze Schriftskala
        // treffen – dann hätte die Website keine Grössen mehr.
        $tokens = [
            'primary', 'primary-dark', 'primary-light',
            'secondary', 'secondary-dark', 'ink',
            'on-primary', 'on-secondary',
            'bg', 'bg-soft', 'surface', 'surface-2',
            'border', 'border-strong',
            'text', 'text-muted', 'text-faint',
            'dark-bg', 'dark-surface', 'dark-text', 'dark-muted', 'dark-border',
            'success', 'danger',
            'font-display', 'font-body',
        ];

        $pattern = '/^[ \t]*--c-(?:' . implode('|', array_map(
            static fn (string $t): string => preg_quote($t, '/'),
            $tokens
        )) . ')\s*:[^;]*;[ \t]*\r?\n?/mi';

        return preg_replace($pattern, '', $css) ?? $css;
    }

    /** Bilder aus der Projektablage in den Ausgabeordner kopieren. */
    private function copyAssets(): int
    {
        $assets = Db::all(
            'SELECT * FROM project_assets WHERE project_id = :p',
            ['p' => (int) $this->project['id']]
        );

        $count = 0;
        $source = STORAGE_DIR . '/projects/' . (string) $this->project['slug'] . '/assets';

        foreach ($assets as $asset) {
            $name = basename((string) $asset['path']);
            $from = $source . '/' . $name;

            if (!is_file($from)) {
                continue;
            }
            if (@copy($from, $this->outputDir . '/assets/img/' . $name)) {
                $count++;
            }
        }

        // Ein schlichtes Zeichen für den Browser-Tab, falls kein Logo da ist.
        write_file_atomic(
            $this->outputDir . '/assets/img/favicon.svg',
            $this->faviconSvg()
        );

        return $count + 1;
    }

    /** robots.txt, sitemap.xml und die Serverkonfiguration. */
    private function writeExtras(): int
    {
        $domain = trim((string) ($this->project['domain'] ?? ''));
        $base = $domain !== '' ? 'https://' . $domain : '';

        // robots.txt
        $robots = "User-agent: *\nAllow: /\n";
        if ($base !== '') {
            $robots .= "\nSitemap: {$base}/sitemap.xml\n";
        }
        write_file_atomic($this->outputDir . '/robots.txt', $robots);

        // sitemap.xml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        $locales = Translator::localesOf($this->project);
        $primary = $locales[0];

        foreach ($locales as $locale) {
            foreach ($this->pages as $page) {
                $loc = $base . '/' . ltrim(
                    Page::fileNameFor((string) $page['path'], $locale, $primary),
                    '/'
                );
                $xml .= '  <url><loc>' . $this->esc($loc) . '</loc></url>' . "\n";
            }
        }
        $xml .= '</urlset>' . "\n";
        write_file_atomic($this->outputDir . '/sitemap.xml', $xml);

        // .htaccess: Sicherheitsheader, Kompression, Zwischenspeicherung
        write_file_atomic($this->outputDir . '/.htaccess', self::htaccess());

        // 404-Seite – je Sprache eine.
        //
        // Sie trägt wie jede Seite Verweise auf ihre anderssprachigen
        // Fassungen. Gäbe es die nicht, zeigte der Verweis ins Leere –
        // und die Prüfung nach dem Bauen fand genau das. Wer auf
        // Französisch unterwegs ist, soll ausserdem auch die
        // Fehlermeldung auf Französisch bekommen.
        $texte = [
            'de' => ['Seite nicht gefunden', 'Diese Seite gibt es nicht',
                     'Vielleicht hat sich ein Tippfehler eingeschlichen.', 'Zurück zur Startseite'],
            'en' => ['Page not found', 'This page does not exist',
                     'Perhaps there is a typo in the address.', 'Back to the home page'],
            'fr' => ['Page introuvable', "Cette page n'existe pas",
                     "Il y a peut-être une faute de frappe dans l'adresse.", "Retour à l'accueil"],
            'it' => ['Pagina non trovata', 'Questa pagina non esiste',
                     "Forse c'è un errore di battitura nell'indirizzo.", 'Torna alla pagina iniziale'],
        ];

        foreach ($locales as $locale) {
            $t = $texte[$locale] ?? $texte['de'];
            $up = $locale === $primary ? '' : '../';

            $notFound = Page::render($this->site(), [
                'path' => '/404',
                'title' => $t[0],
                'meta_description' => '',
                'in_navigation' => 0,
                'translations' => [],
                'sections' => [[
                    'id' => 0,
                    'type' => 'text',
                    'template_key' => 'mittig',
                    'content' => [
                        'title' => $t[1],
                        'body' => $t[2] . "\n\n"
                            . '<a href="' . $up . 'index.html">' . $t[3] . '</a>',
                    ],
                    'overrides' => [],
                    'translations' => [],
                ]],
            ], self::criticalCss(), $locale);

            $ziel = $locale === $primary
                ? $this->outputDir . '/404.html'
                : $this->outputDir . '/' . $locale . '/404.html';

            ensure_dir(dirname($ziel));
            write_file_atomic($ziel, $notFound);
        }

        // Der Empfänger des Kontaktformulars. Ohne ihn liefe jedes
        // abgeschickte Formular ins Leere.
        return 4 + $this->writeContactHandler() + $this->writeServices();
    }

    /**
     * contact.php und die zugehörige Ablage anlegen.
     *
     * Die Anfragen landen als Datei neben der Website – keine Datenbank,
     * wie überall sonst auch. Der Ordner ist per .htaccess gesperrt und
     * enthält zusätzlich eine index.php, die sofort abbricht: Wo
     * .htaccess nicht greift, greift wenigstens die.
     */
    private function writeContactHandler(): int
    {
        $source = APP_DIR . '/Kit/site/php/contact.php';
        if (!is_file($source)) {
            return 0;
        }

        write_file_atomic($this->outputDir . '/contact.php', (string) file_get_contents($source));

        $dataDir = $this->outputDir . '/data';
        ensure_dir($dataDir);

        $this->writeDataConfig($dataDir);
        if (!is_file($dataDir . '/leads.php')) {
            write_file_atomic($dataDir . '/leads.php', "<?php exit; ?>\n[]\n");
        }

        write_file_atomic($dataDir . '/.htaccess', "Require all denied\n"
            . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
        write_file_atomic($dataDir . '/index.php', "<?php http_response_code(404); exit;\n");

        return 5;
    }

    /**
     * data/config.php – die einzige Datei, die der Kunde anfassen darf.
     *
     * Zwei Sorten Werte stehen darin: Die Empfängeradresse gehört ihm,
     * die Schlüssel gehören der Technik. Deshalb wird beim erneuten
     * Bauen zusammengeführt statt überschrieben: Eine von Hand
     * geänderte Adresse bleibt stehen, ein neuer Schlüssel kommt
     * trotzdem an.
     */
    private function writeDataConfig(string $dataDir): void
    {
        $brief = json_decode((string) ($this->project['brief'] ?? '{}'), true) ?: [];
        $file = $dataDir . '/config.php';

        $old = [];
        if (is_file($file)) {
            $loaded = @include $file;
            $old = is_array($loaded) ? $loaded : [];
        }

        $values = [
            'brand' => (string) $this->project['name'],
            // Vom Kunden änderbar – deshalb hat sein Wert Vorrang.
            'contact_email' => (string) ($old['contact_email'] ?? $brief['contact_email'] ?? ''),
            'assistant_url' => rtrim((string) Config::get('app_url', ''), '/'),
            'assistant_token' => (string) ($this->project['assistant_token'] ?? ''),
            'stats_token' => (string) ($this->project['stats_token'] ?? ''),
            // Der Zugangscode vor der Hilfeseite. Er wird beim ersten
            // Bauen erzeugt und steht danach auf der Projektseite - von
            // dort sage ich ihn dem Kunden.
            'support_code' => \WebAtze\Domain\Websites::supportCode((int) $this->project['id']),
            'support_secret' => (string) ($old['support_secret'] ?? random_token(24)),
            // Dieselben zwei Werte, die auch im Auftragstext stehen. So
            // sieht eine von WebAtze gebaute Website von aussen genauso
            // aus wie eine, die aus dem Auftragstext entstanden ist -
            // eine Datei, ein Satz Schluessel, kein Sonderfall.
            'support_token' => !empty($brief['wants_support'])
                ? \WebAtze\Domain\Websites::supportToken((int) $this->project['id'])
                : '',
            'visit_key' => !empty($brief['wants_stats'])
                ? \WebAtze\Domain\Visits::key((int) $this->project['id'])
                : '',
            // Der Schlüssel der Brücke.
            //
            // Er steht hier und auf dem Server von WebAtze. Über die
            // Leitung geht er nie – er unterschreibt nur. Deshalb steht
            // er in dieser Datei und ausdrücklich NICHT in site(): Das
            // ist genau der Datensatz, den die Brücke bei jeder
            // Veröffentlichung überträgt.
            //
            // Leeren schaltet die Brücke ab. Es ist die Website des
            // Kunden, nicht die von WebAtze.
            'bridge_secret' => (string) ($this->project['bridge_secret'] ?? ''),
        ];

        write_file_atomic(
            $file,
            "<?php\n\n"
            . "// Angaben dieser Website.\n"
            . "//\n"
            . "// Die Empfängeradresse des Kontaktformulars lässt sich hier\n"
            . "// jederzeit ändern; sie bleibt beim nächsten Bauen stehen.\n"
            . "//\n"
            . "// Die Schlüssel darunter weisen diese Website bei WebAtze aus.\n"
            . "// Der Schlüssel zum Sprachmodell ist nicht dabei – der liegt\n"
            . "// ausschliesslich auf dem Server von WebAtze.\n\n"
            . 'return ' . var_export($values, true) . ";\n"
        );
    }

    /**
     * Zählung und Hilfeseite mitliefern – aber nur, was angehakt wurde.
     *
     * Wer die Zählung nicht will, bekommt keine einzige Zeile davon:
     * kein Pixel im HTML, keine Datei auf dem Server, nichts, was
     * jemand abschalten müsste.
     */
    private function writeServices(): int
    {
        $site = $this->site();
        $kit = APP_DIR . '/Kit/site/php';
        $written = 0;

        $copy = function (string $name) use ($kit, &$written): void {
            $source = $kit . '/' . $name;
            if (is_file($source)) {
                write_file_atomic($this->outputDir . '/' . $name, (string) file_get_contents($source));
                $written++;
            }
        };

        if (!empty($site['counter'])) {
            $copy('zaehler.php');
            $copy('zahlen.php');
        }

        if (!empty($site['support'])) {
            $copy('support.php');
        }

        // Die Brücke kommt nur mit, wenn es einen Schlüssel gibt. Ohne
        // ihn weist sie ohnehin jede Anfrage ab - aber eine Datei, die
        // nichts tut, gehört nicht auf einen fremden Server.
        if (trim((string) ($this->project['bridge_secret'] ?? '')) !== '') {
            $copy('wa-bruecke.php');
        }

        return $written;
    }

    private static function htaccess(): string
    {
        return <<<'CONF'
        # Diese Website – erzeugt, aber ganz normale Dateien.
        # Nichts hier muss installiert werden.

        <IfModule mod_headers.c>
            Header always set X-Content-Type-Options "nosniff"
            Header always set X-Frame-Options "SAMEORIGIN"
            Header always set Referrer-Policy "strict-origin-when-cross-origin"
            Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"

            <FilesMatch "\.(css|js|woff2|png|jpg|jpeg|webp|avif|svg|ico)$">
                Header set Cache-Control "public, max-age=2592000"
            </FilesMatch>
        </IfModule>

        <IfModule mod_deflate.c>
            AddOutputFilterByType DEFLATE text/html text/css text/plain text/xml \
                application/javascript application/json image/svg+xml
        </IfModule>

        <IfModule mod_rewrite.c>
            RewriteEngine On
            # Adressen ohne .html zulassen: /kontakt findet kontakt.html
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteCond %{REQUEST_FILENAME}.html -f
            RewriteRule ^(.*)$ $1.html [L]
        </IfModule>

        ErrorDocument 404 /404.html
        Options -Indexes
        AddDefaultCharset UTF-8
        CONF;
    }

    private function faviconSvg(): string
    {
        $primary = (string) ($this->theme['colors']['primary'] ?? '#333333');
        $onPrimary = (string) ($this->theme['colors']['on_primary'] ?? '#ffffff');
        $initial = mb_strtoupper(mb_substr((string) $this->project['name'], 0, 1));

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            . '<rect width="64" height="64" rx="14" fill="' . $this->esc($primary) . '"/>'
            . '<text x="32" y="43" text-anchor="middle" font-family="system-ui,sans-serif"'
            . ' font-size="34" font-weight="700" fill="' . $this->esc($onPrimary) . '">'
            . $this->esc($initial) . '</text></svg>';
    }

    /**
     * Das Nötigste für den ersten Bildaufbau.
     *
     * Ohne das würde die Seite kurz unformatiert erscheinen, bevor das
     * Stylesheet geladen ist – ein sichtbares Zucken, das teurer wirkt
     * als es ist.
     */
    public static function criticalCss(): string
    {
        return self::minifyCss(
            '*,*::before,*::after{box-sizing:border-box}'
            . 'body{margin:0;background:var(--c-bg,#fff);color:var(--c-text,#16172a);'
            . 'font-family:var(--c-font-body,system-ui,sans-serif);line-height:1.65;'
            . '-webkit-font-smoothing:antialiased}'
            . 'img,svg{display:block;max-width:100%;height:auto}'
            . '.s-shell{width:100%;max-width:74rem;margin-inline:auto;padding-inline:clamp(1.15rem,4vw,2.75rem)}'
            . '[data-section]{position:relative;padding-block:clamp(3.5rem,8vw,7rem)}'
            . '[data-section="header"]{padding-block:0}'
            . '.s-header__inner{display:flex;align-items:center;justify-content:space-between;'
            . 'gap:1.5rem;min-height:4.25rem}'
            . '.s-hero__title{font-size:clamp(2.4rem,4.6vw + 1rem,4.5rem);line-height:1.12;'
            . 'letter-spacing:-0.025em;margin:0}'
            . '.s-skip{position:absolute;inset-inline-start:1rem;inset-block-start:-5rem;z-index:200}'
        );
    }

    /** Einfache, verlustfreie Verkleinerung des CSS. */
    public static function minifyCss(string $css): string
    {
        // Kommentare entfernen, aber den Kopf der Datei behalten
        $css = preg_replace('#/\*(?!!).*?\*/#s', '', $css) ?? $css;
        $css = preg_replace('/\s+/', ' ', $css) ?? $css;
        $css = str_replace([' {', '{ ', ' }', '} ', ': ', '; ', ' ,', ', ', ';}'],
                           ['{', '{', '}', '}', ':', ';', ',', ',', '}'], $css);

        return trim($css);
    }

    // ------------------------------------------------------------------
    // Seiten laden
    // ------------------------------------------------------------------

    /** Alle Seiten eines Projekts samt ihrer Abschnitte. */
    public static function loadPages(int $projectId): array
    {
        $pages = Db::all(
            'SELECT * FROM project_pages WHERE project_id = :p ORDER BY sort_order ASC, id ASC',
            ['p' => $projectId]
        );

        foreach ($pages as $index => $page) {
            $sections = Db::all(
                'SELECT * FROM project_sections WHERE page_id = :page ORDER BY sort_order ASC, id ASC',
                ['page' => (int) $page['id']]
            );

            foreach ($sections as $key => $section) {
                $sections[$key]['content'] = json_decode((string) ($section['content'] ?? '{}'), true) ?: [];
                $sections[$key]['overrides'] = json_decode((string) ($section['overrides'] ?? '{}'), true) ?: [];
                $sections[$key]['translations'] = json_decode((string) ($section['translations'] ?? '{}'), true) ?: [];
                $sections[$key]['id'] = (int) $section['id'];
            }

            $pages[$index]['translations'] = json_decode((string) ($page['translations'] ?? '{}'), true) ?: [];
            $pages[$index]['sections'] = $sections;
        }

        return $pages;
    }

    /** '/kontakt' -> 'kontakt.html', '/' -> 'index.html' */
    public static function fileNameFor(string $path): string
    {
        $path = trim($path, '/');
        return $path === '' ? 'index.html' : str_slug($path, 60) . '.html';
    }

    public static function linkFor(string $path): string
    {
        return self::fileNameFor($path);
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
