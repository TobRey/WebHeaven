<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Build\{ImageStore, Theme, Zugang};
use WebAtze\Core\{Audit, Config, Crypto, Db, Jobs, Logger, Request, Response, Session, View};
use WebAtze\Domain\{Brief, Vault};

/**
 * Das Formular für eine neue Kundenwebsite.
 *
 * Nach dem Absenden wird nur ein Auftrag angelegt – die Website entsteht
 * danach im Hintergrund. So bleibt die Seite bedienbar, auch wenn das
 * Erzeugen mehrere Minuten dauert.
 */
final class CreateController
{
    public function form(Request $request): Response
    {
        // Kommt der Aufruf von einem übernommenen Fragebogen, steht die
        // Vorbelegung in der Sitzung. Sie wird beim Lesen entfernt: Beim
        // nächsten neuen Projekt sollen nicht die Angaben des vorigen
        // Kunden im Formular stehen.
        $prefill = (array) (Session::get('create_prefill') ?? []);

        if ($prefill !== []) {
            Session::forget('create_prefill');
        }

        return $this->render($request, $prefill, []);
    }

    public function submit(Request $request): Response
    {
        $input = $request->all();
        $check = Brief::validate($input);

        if (!$check['ok']) {
            // Passwörter nie zurück ins Formular schreiben.
            $values = Brief::withoutSecrets($check['data']);
            return $this->render($request, $values, $check['errors']);
        }

        $brief = $check['data'];

        try {
            $projectId = Db::transaction(function () use ($brief, $request): int {
                $slug = $this->uniqueSlug($brief['company_name']);

                $theme = Theme::build([
                    'primary' => $brief['color_primary'],
                    'secondary' => $brief['color_secondary'],
                    'accent' => $brief['color_accent'],
                ], $brief['style']);

                $id = Db::insert('projects', [
                    'slug' => $slug,
                    'name' => $brief['company_name'],
                    'status' => 'building',
                    'brief' => Brief::withoutSecrets($brief),
                    'theme' => $theme,
                    'locale' => explode(',', $brief['locales'])[0] ?: 'de',
                    'locales' => $brief['locales'],
                    'domain' => $brief['domain'],
                    'wants_admin' => $brief['wants_admin'] ? 1 : 0,
                    'admin_username' => $brief['wants_admin'] ? $brief['admin_username'] : '',
                    'admin_password_hash' => $brief['wants_admin']
                        ? Crypto::hashPassword($brief['admin_password'])
                        : '',
                    // Schlüssel, mit dem sich das Kundenbackend später beim
                    // Assistenten meldet. Der API-Schlüssel bleibt hier.
                    'assistant_token' => random_token(24),
                    // Getrennt davon der Schlüssel fürs Abholen der
                    // Besucherzahlen: Wer ihn hat, sieht Summen – ändern
                    // kann er damit nichts.
                    'stats_token' => $brief['wants_stats'] ? random_token(32) : '',
                    // Der Zaehlschluessel steht spaeter im Auftragstext
                    // und in der Zaehlzeile. Er entsteht nur, wenn die
                    // Zaehlung angehakt wurde - sonst gaebe es sie
                    // stillschweigend doch.
                    'visit_key' => $brief['wants_stats'] ? bin2hex(random_bytes(8)) : '',
                    // Der Schluessel der Hilfeseite ebenso.
                    'support_token' => $brief['wants_support'] ? random_token(24) : '',
                    'report_email' => $brief['wants_stats'] ? $brief['report_email'] : '',
                    'preview_token' => '',
                    'created_at' => Db::now(),
                    'updated_at' => Db::now(),
                ]);

                // Logo, falls hochgeladen
                $this->storeLogo($request, $id, $slug);

                // FTP-Zugang, falls angegeben
                if ($brief['ftp_host'] !== '' && $brief['ftp_username'] !== '') {
                    Zugang::saveTarget($id, [
                        'protocol' => $brief['ftp_protocol'],
                        'host' => $brief['ftp_host'],
                        'port' => $brief['ftp_port'],
                        'username' => $brief['ftp_username'],
                        'password' => $brief['ftp_password'],
                        'path' => $brief['ftp_path'],
                    ]);
                }

                // Domainumzug vormerken
                if ($brief['domain'] !== '' && $brief['domain_mode'] !== 'none') {
                    Db::insert('domain_transfers', [
                        'project_id' => $id,
                        'domain' => $brief['domain'],
                        'mode' => $brief['domain_mode'],
                        'registrar' => $brief['registrar'],
                        'current_step' => 0,
                        'steps' => [],
                        'checks' => [],
                        'created_at' => Db::now(),
                        'updated_at' => Db::now(),
                    ]);
                }

                return $id;
            });
        } catch (\Throwable $e) {
            $id = Logger::exception($e);
            Session::flash('error', 'Das Projekt konnte nicht angelegt werden (Kennnummer ' . $id . ').');
            return $this->render($request, Brief::withoutSecrets($brief), []);
        }

        // Die Zugaenge in den Tresor, solange sie noch im Klartext
        // vorliegen. Gleich danach sind sie es nicht mehr: Das
        // Backend-Passwort wird zum Argon2id-Streuwert, das FTP-Passwort
        // wandert verschluesselt in die Hochlade-Einstellungen.
        try {
            Vault::adoptProject($projectId, $brief);
        } catch (\Throwable $e) {
            // Ein Projekt, das steht, darf nicht daran scheitern, dass
            // der Tresor gerade klemmt.
            Logger::exception($e);
        }

        Audit::log('project.created', $brief['company_name'], [
            'id' => $projectId,
            'umfang' => $brief['scope'],
            'mit_backend' => $brief['wants_admin'],
        ], $request);

        // Kein Bauauftrag mehr.
        //
        // Frueher lief hier eine Warteschlange an: rund achtzig Anfragen
        // an die Schnittstelle, verteilt ueber vierzig Cron-Laeufe. Jeder
        // dieser Uebergaenge war eine Stelle, an der etwas haengenbleiben
        // konnte - und es blieb haengen, immer wieder, jedes Mal woanders.
        //
        // Jetzt entsteht ein Text. Kopieren, einfuegen, fertig. Wer den
        // Bau trotzdem automatisch will, findet die Schaltflaeche auf der
        // Auftragsseite.
        Session::flash('success',
            'Das Projekt ist angelegt. Hier ist der fertige Auftrag zum Kopieren.');

        return Response::redirect($this->base() . '/projekt/' . $projectId . '/auftrag')
            ->noCache()->noIndex();
    }

    // ------------------------------------------------------------------

    /**
     * Wie lange dauert dieser Bau ungefaehr?
     *
     * Gerechnet wird in Aufrufen an die KI: einer fuer die Seitenliste,
     * einer je Seite fuer den Aufbau, ein bis zwei je Seite fuer die
     * Texte, und dasselbe noch einmal je zusaetzlicher Sprache. Bei
     * ungefaehr zwei Aufrufen je Minute ergibt das eine brauchbare
     * Hausnummer - bewusst grosszuegig, denn zu frueh Gesagtes enttaeuscht.
     */
    private static function dauerSchaetzen(array $brief): string
    {
        $seiten = match ((string) ($brief['scope'] ?? 'small')) {
            'large' => 11,
            'medium' => 7,
            default => 5,
        };

        $sprachen = max(1, count(array_filter(explode(',', (string) ($brief['locales'] ?? 'de')))));

        // Aufbau je Seite, Texte je Seite, und beides je Sprache erneut
        // fuer die Uebersetzung.
        $aufrufe = 1 + $seiten + ($seiten * 2) + (($sprachen - 1) * $seiten * 2);
        $minuten = (int) ceil($aufrufe / 2);

        if ($minuten <= 5) {
            return 'fünf Minuten';
        }

        // Auf Viertelstunden runden, damit es nach Schaetzung klingt und
        // nicht nach Versprechen.
        $von = max(5, (int) (floor($minuten / 5) * 5));
        $bis = $von + 10;

        return $von . ' bis ' . $bis . ' Minuten';
    }

    private function render(Request $request, array $values, array $errors): Response
    {
        $providers = require APP_DIR . '/Support/providers.php';

        $content = View::partial('admin/create', [
            'values' => $values,
            'errors' => $errors,
            'providers' => $providers,
        ]);

        return Response::html(View::partial('layouts/admin', [
            'title' => 'Neue Website',
            'content' => $content,
        ]))->noCache()->noIndex();
    }

    /** Aus dem Firmennamen eine freie Kennung machen. */
    private function uniqueSlug(string $name): string
    {
        $base = str_slug($name, 60);
        $slug = $base;
        $suffix = 2;

        while (Db::value('SELECT 1 FROM projects WHERE slug = :s', ['s' => $slug])) {
            $slug = $base . '-' . $suffix;
            $suffix++;

            if ($suffix > 200) {
                $slug = $base . '-' . bin2hex(random_bytes(3));
                break;
            }
        }

        return $slug;
    }

    private function storeLogo(Request $request, int $projectId, string $slug): void
    {
        $file = $request->file('logo');
        if ($file === null) {
            return;
        }

        if ((int) ($file['size'] ?? 0) > 4_000_000) {
            Session::flash('warning', 'Das Logo war grösser als 4 MB und wurde nicht übernommen.');
            return;
        }

        $dir = ensure_dir(STORAGE_DIR . '/projects/' . $slug . '/assets');
        $result = ImageStore::storeUpload($file, $dir);

        if ($result === null) {
            Session::flash('warning', 'Das Logo konnte nicht gelesen werden. Es wurde nicht übernommen.');
            return;
        }

        Db::insert('project_assets', [
            'project_id' => $projectId,
            'kind' => 'logo',
            'path' => $result['name'],
            'original_name' => mb_substr((string) ($file['name'] ?? ''), 0, 200),
            'alt' => 'Logo',
            'width' => $result['width'],
            'height' => $result['height'],
            'bytes' => $result['bytes'],
            'created_at' => Db::now(),
        ]);
    }

    private function base(): string
    {
        return '/' . trim((string) Config::get('create_path', 'create'), '/');
    }
}
