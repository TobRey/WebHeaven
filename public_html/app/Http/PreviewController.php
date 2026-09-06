<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Assets, Config, Db, Request, Response, Session};

/**
 * Liefert die Vorschau einer erzeugten Kundenwebsite aus.
 *
 * Die Adresse enthält ein langes, zufälliges Kennwort – wer es nicht hat,
 * kommt nicht hinein. Nach der eingestellten Zeit (Standard 24 Stunden)
 * räumt der Worker die Vorschau weg und die Adresse führt ins Leere.
 *
 * Ist man angemeldet, wird in jede HTML-Seite ein Bearbeitungsbalken
 * eingesetzt: jede Section lässt sich anklicken und per Textbefehl
 * ändern. Für alle anderen bleibt die Vorschau eine gewöhnliche Website.
 */
final class PreviewController
{
    private const TYPES = [
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'txt' => 'text/plain; charset=utf-8',
        'xml' => 'application/xml; charset=utf-8',
        'webmanifest' => 'application/manifest+json',
    ];

    public function index(Request $request): Response
    {
        return $this->serve($request, 'index.html');
    }

    public function file(Request $request): Response
    {
        return $this->serve($request, $request->param('path'));
    }

    /**
     * Das Vorschaubild einer Website in der Liste.
     *
     * Ein echter Anblick der Startseite – gerendert vom Browser, der
     * die Liste anzeigt. Auf geteiltem Hosting gibt es keinen Browser
     * auf dem Server, also kann dort auch niemand eine Seite
     * abfotografieren; der Browser des Betrachters kann es umsonst.
     *
     * Warum nicht einfach die Vorschau? Weil deren Kennwort nach
     * vierundzwanzig Stunden abläuft (`preview_expires_at`) – ein
     * Vorschaubild wäre am nächsten Tag leer. Diese Adresse liegt
     * stattdessen hinter der Anmeldung und läuft nicht ab.
     *
     * Ausgeliefert wird der gebaute Stand aus
     * `storage/projects/<slug>/dist`, mit derselben Pfadauflösung wie
     * die Vorschau: `resolve()` löst über realpath() alle Verweise auf
     * und prüft danach, dass das Ergebnis noch im Ordner liegt.
     */
    public function thumbnail(Request $request): Response
    {
        $project = Db::first(
            'SELECT id, slug, name FROM projects WHERE id = :id',
            ['id' => $request->paramInt('id')]
        );

        if ($project === null) {
            return Response::notFound();
        }

        $ordner = STORAGE_DIR . '/projects/' . (string) $project['slug'];
        $pfad = (string) $request->param('path');

        // Erst der gebaute Stand, dann der uebernommene.
        //
        // Eine fremde Website - ein ZIP aus dem Auftragstext - wird hier
        // nie gebaut, hat aber seit dem Auspacken echte Dateien. Ohne
        // diesen zweiten Ort stuende dort ewig ein Anfangsbuchstabe,
        // obwohl die Seite dalaege.
        $file = self::resolve($ordner . '/dist', $pfad)
            ?? self::resolve($ordner . '/' . \WebAtze\Build\Uebernahme::ORDNER, $pfad);

        if ($file === null) {
            return Response::notFound();
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return Response::make((string) file_get_contents($file))
            ->header('Content-Type', self::TYPES[$extension] ?? 'application/octet-stream')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            // Nur wir selbst dürfen das einbetten. Ohne diese Zeile
            // könnte eine fremde Seite die Kundenwebsite in einen
            // Rahmen holen und damit den Anschein erwecken, sie gehöre
            // ihr.
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Cache-Control', 'private, max-age=300')
            ->header('Content-Security-Policy', self::previewPolicy(false));
    }

    private function serve(Request $request, string $path): Response
    {
        $token = self::cleanToken($request->param('token'));
        if ($token === '') {
            return $this->gone();
        }

        $project = Db::first(
            'SELECT * FROM projects WHERE preview_token = :t',
            ['t' => $token]
        );

        if ($project === null) {
            return $this->gone();
        }

        // Abgelaufen? Dann verhält sich die Adresse wie nicht vorhanden.
        $expires = (string) ($project['preview_expires_at'] ?? '');
        if ($expires !== '' && strtotime($expires) < time()) {
            return $this->gone();
        }

        $root = STORAGE_DIR . '/previews/' . $token;
        $file = self::resolve($root, $path);

        if ($file === null) {
            // Fehlende Seite: die 404-Seite der Kundenwebsite zeigen.
            $notFound = self::resolve($root, '404.html');
            if ($notFound === null) {
                return $this->gone();
            }
            $file = $notFound;
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $type = self::TYPES[$extension] ?? 'application/octet-stream';

        $body = (string) file_get_contents($file);

        // Nur für Angemeldete: den Bearbeitungsbalken einsetzen.
        if ($extension === 'html' && Session::isLoggedIn()) {
            $body = $this->injectEditor($body, $project);
        }

        return Response::make($body)
            ->header('Content-Type', $type)
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Cache-Control', 'no-store, private')
            // Die Vorschau zeigt fremde Inhalte – ohne strenge Regeln
            // könnte ein eingeschleustes Skript den Adminbereich stören.
            ->header('Content-Security-Policy', self::previewPolicy(Session::isLoggedIn()));
    }

    /**
     * Adresse in einen Dateipfad auflösen – ohne Ausbruch.
     *
     * realpath() löst alle Verweise und "..“ auf. Anschliessend wird
     * geprüft, ob das Ergebnis wirklich noch im Vorschauordner liegt.
     * Damit sind Pfad-Ausbrüche und Symlink-Tricks ausgeschlossen.
     */
    private static function resolve(string $root, string $path): ?string
    {
        $realRoot = realpath($root);
        if ($realRoot === false) {
            return null;
        }

        $path = str_replace('\\', '/', trim($path, '/'));
        if ($path === '') {
            $path = 'index.html';
        }
        if (str_contains($path, "\0")) {
            return null;
        }

        $candidate = realpath($realRoot . '/' . $path);

        // Verzeichnis: die Startseite darin nehmen
        if ($candidate !== false && is_dir($candidate)) {
            $candidate = realpath($candidate . '/index.html');
        }

        // Ohne Endung: .html anhängen (schöne Adressen)
        if ($candidate === false && !str_contains(basename($path), '.')) {
            $candidate = realpath($realRoot . '/' . $path . '.html');
        }

        if ($candidate === false || !is_file($candidate)) {
            return null;
        }
        if (!str_starts_with($candidate, $realRoot . DIRECTORY_SEPARATOR) && $candidate !== $realRoot) {
            return null;
        }

        return $candidate;
    }

    /** Bearbeitungsbalken und Skript in die Seite einsetzen. */
    /** Einen Datenbankzeitpunkt als ISO-8601 mit Zeitzone ausgeben. */
    private static function isoTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return '';
        }
    }

    private function injectEditor(string $html, array $project): string
    {
        $data = json_out([
            'projectId' => (int) $project['id'],
            'projectName' => (string) $project['name'],
            'token' => Session::csrfToken(),
            'base' => '/' . trim((string) Config::get('create_path', 'create'), '/'),
            'previewBase' => '/vorschau/' . (string) $project['preview_token'] . '/',
            // Mit Zeitzone, sonst rechnet der Browser mit seiner eigenen:
            // Der Server schreibt Zürcher Zeit, ein Browser in UTC läse
            // sie als UTC und käme auf zwei Stunden zu viel.
            'expiresAt' => self::isoTime((string) ($project['preview_expires_at'] ?? '')),
        ]);

        $css = Assets::url('editor.css');
        $js = Assets::url('editor.js');

        $overlay = <<<HTML

        <link rel="stylesheet" href="{$css}">
        <script id="wa-editor-data" type="application/json">{$data}</script>
        <script src="{$js}" defer></script>

        HTML;

        // Vor </body> einsetzen; fehlt der, hinten anhängen.
        $position = strripos($html, '</body>');

        return $position === false
            ? $html . $overlay
            : substr($html, 0, $position) . $overlay . substr($html, $position);
    }

    /**
     * Regeln für die Vorschau.
     *
     * Der Bearbeitungsbalken braucht ein eigenes Skript, deshalb ist
     * 'self' erlaubt. Inline-Skripte bleiben verboten – so kann aus einem
     * eingeschleusten Kundentext kein Code werden.
     */
    private static function previewPolicy(bool $withEditor): string
    {
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            // Karten werden erst auf Klick geladen und kommen von OSM.
            "frame-src 'self' https://www.openstreetmap.org",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ];

        return implode('; ', $directives);
    }

    private static function cleanToken(string $token): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '', $token) ?? '';
    }

    private function gone(): Response
    {
        $html = <<<'HTML'
        <!doctype html><html lang="de"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <meta name="robots" content="noindex">
        <title>Vorschau nicht verfügbar</title>
        <style>
          body{margin:0;display:grid;place-items:center;min-height:100dvh;
               font:16px/1.6 system-ui,sans-serif;background:#06060f;color:#e8e8f5;text-align:center}
          div{max-width:32rem;padding:2rem}
          h1{font-size:1.5rem;margin:0 0 .75rem}
          p{color:#9b9cb8}
        </style></head><body><div>
          <h1>Diese Vorschau gibt es nicht mehr</h1>
          <p>Vorschauen bleiben 24 Stunden erreichbar und werden danach
             gelöscht, damit Platz für neue bleibt.</p>
          <p>Die fertige Website liegt weiterhin als Paket bereit – eine
             neue Vorschau ist jederzeit möglich.</p>
        </div></body></html>
        HTML;

        return Response::html($html, 404)->noIndex()->noCache();
    }
}
