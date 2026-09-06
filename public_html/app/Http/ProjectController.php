<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Build\{Pipeline, SiteBuilder, SiteChecker};
use WebAtze\Core\{Audit, Config, Db, Jobs, Request, Response, Session, View};
use WebAtze\Domain\{PromptText, Visits, Websites};

/**
 * Ein einzelnes Projekt: Stand, Abschnitte, Verlauf, Neubau, Löschen.
 */
final class ProjectController
{
    /**
     * Der fertige Auftrag zum Kopieren.
     *
     * Der Weg ohne Schnittstelle: Das Formular erzeugt einen Text, der
     * von Hand eingefuegt wird. Eine Anfrage, eine Antwort - und keine
     * Warteschlange, kein Cronjob, keine achtzig Aufrufe, die
     * hintereinander klappen muessen.
     */
    public function prompt(Request $request): Response
    {
        $project = self::find($request->paramInt('id'));

        if ($project === null) {
            return Response::notFound();
        }

        $brief = json_decode((string) $project['brief'], true) ?: [];
        $id = (int) $project['id'];

        // Die echten Werte fuer Support und Zaehlung. Sie entstehen beim
        // ersten Nachfragen und bleiben dann stehen.
        //
        // Sie gehoeren in den Auftragstext, damit auf der fertigen
        // Website nichts mehr einzurichten ist - auch dann nicht, wenn
        // der Text kopiert und von Hand eingefuegt wurde. Vorher standen
        // hier Platzhalter mit dem Hinweis "traegt Tobias nach", und
        // genau dieser Handgriff blieb liegen.
        // Erzeugt wird nur, was auch bestellt wurde: Ein Schlüssel, den
        // niemand braucht, ist ein Schlüssel, der irgendwann irgendwo
        // steht.
        $anschluss = [
            'url' => (string) Config::get('app_url', ''),
            'support_token' => !empty($brief['wants_support']) ? Websites::supportToken($id) : '',
            'support_code' => !empty($brief['wants_support']) ? Websites::supportCode($id) : '',
            'visit_key' => !empty($brief['wants_stats']) ? Visits::key($id) : '',
        ];

        return Response::html(View::partial('layouts/admin', [
            'title' => 'Auftrag: ' . (string) $project['name'],
            'content' => View::partial('admin/prompt', [
                'project' => $project,
                'prompt' => PromptText::build($brief, $anschluss),
                'dateiname' => PromptText::filename($brief),
                'anschluss' => $anschluss,
            ]),
        ]))->noCache()->noIndex();
    }

    /**
     * Schluessel und Zugangscode dieser Website erneuern.
     *
     * Beides steht im Auftragstext, damit auf der Kundenwebsite nichts
     * einzurichten ist - und ein Auftragstext wird kopiert und
     * weitergereicht. Also braucht es einen Weg zurueck.
     *
     * Danach ist die bestehende Hilfeseite dieser Website stumm, bis sie
     * mit dem neuen Auftrag neu gebaut ist. Das ist der Preis und steht
     * so auch in der Sicherheitsabfrage.
     */
    public function newSupportKeys(Request $request): Response
    {
        $project = self::find($request->paramInt('id'));

        if ($project === null) {
            return Response::notFound();
        }

        $id = (int) $project['id'];

        Websites::newSupportToken($id);
        Websites::newSupportCode($id);

        Audit::log('project.support_keys', (string) $project['name'], ['id' => $id], $request);

        Session::flash(
            'success',
            'Neuer Schlüssel und neuer Zugangscode. Bau die Website mit dem neuen '
            . 'Auftrag noch einmal – bis dahin nimmt die Hilfeseite nichts entgegen.'
        );

        return Response::redirect(self::base() . '/projekt/' . $id . '/auftrag')
            ->noCache()->noIndex();
    }

    public function show(Request $request): Response
    {
        $project = self::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        $job = Jobs::activeFor((int) $project['id']);
        $pages = SiteBuilder::loadPages((int) $project['id']);

        $target = Db::first(
            'SELECT * FROM deploy_targets WHERE project_id = :p ORDER BY id DESC LIMIT 1',
            ['p' => (int) $project['id']]
        );

        $transfer = Db::first(
            'SELECT * FROM domain_transfers WHERE project_id = :p ORDER BY id DESC LIMIT 1',
            ['p' => (int) $project['id']]
        );

        $cost = (int) Db::value(
            'SELECT COALESCE(SUM(cost_micro), 0) FROM ai_calls WHERE project_id = :p',
            ['p' => (int) $project['id']],
            0
        );

        $theme = json_decode((string) $project['theme'], true) ?: [];

        return Response::html(View::partial('layouts/admin', [
            'title' => (string) $project['name'],
            'content' => View::partial('admin/project', [
                'project' => $project,
                'brief' => json_decode((string) $project['brief'], true) ?: [],
                'theme' => $theme,
                'job' => $job,
                'pages' => $pages,
                'target' => $target,
                'transfer' => $transfer,
                'cost' => $cost,
                'previewUrl' => Pipeline::previewUrl($project),
                'checks' => SiteChecker::latest((int) $project['id']),
            ]),
        ]))->noCache()->noIndex();
    }

    public function sections(Request $request): Response
    {
        $project = self::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        return Response::redirect(Pipeline::previewUrl($project) ?: self::base() . '/projekt/' . $project['id'])
            ->noCache()->noIndex();
    }

    /** Website neu bauen – Struktur und Texte entstehen frisch. */
    public function rebuild(Request $request): Response
    {
        $project = self::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        if (Jobs::activeFor((int) $project['id']) !== null) {
            Session::flash('warning', 'Für dieses Projekt läuft bereits ein Auftrag.');
            return $this->back($project);
        }

        Db::update('projects', ['status' => 'building', 'updated_at' => Db::now()],
            'id = :id', ['id' => (int) $project['id']]);

        Jobs::enqueue('rebuild', [], (int) $project['id']);
        Jobs::nudge();

        Audit::log('project.rebuild', (string) $project['name'], [], $request);
        Session::flash('success', 'Die Website wird neu gebaut. Der Fortschritt erscheint gleich hier.');

        return $this->back($project);
    }

    public function destroy(Request $request): Response
    {
        $project = self::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        $name = (string) $project['name'];
        $slug = (string) $project['slug'];
        $id = (int) $project['id'];

        Db::transaction(static function () use ($id): void {
            foreach (['project_sections', 'project_pages', 'project_assets', 'builds',
                      'deploy_targets', 'domain_transfers', 'section_changes'] as $table) {
                Db::delete($table, 'project_id = :p', ['p' => $id]);
            }
            Db::delete('jobs', 'project_id = :p', ['p' => $id]);
            Db::update('showcase', ['project_id' => null], 'project_id = :p', ['p' => $id]);
            Db::delete('projects', 'id = :id', ['id' => $id]);
        });

        // Dateien erst nach der Datenbank – wäre sie fehlgeschlagen,
        // stünde sonst ein Eintrag ohne Dateien da.
        delete_tree(STORAGE_DIR . '/projects/' . $slug);
        delete_tree(STORAGE_DIR . '/zips/' . $slug);

        $token = (string) $project['preview_token'];
        if ($token !== '') {
            delete_tree(STORAGE_DIR . '/previews/' . preg_replace('/[^A-Za-z0-9_-]/', '', $token));
        }

        Audit::log('project.deleted', $name, ['id' => $id], $request);
        Session::flash('success', 'Das Projekt "' . $name . '" wurde vollständig gelöscht.');

        return Response::redirect(self::base() . '/start')->noCache()->noIndex();
    }

    /** Änderungsverlauf eines Projekts, für die Anzeige im Editor. */
    public function history(Request $request): Response
    {
        $project = self::find($request->paramInt('id'));
        if ($project === null) {
            return Response::json(['ok' => false, 'error' => 'Nicht gefunden.'], 404);
        }

        $entries = Db::all(
            'SELECT c.*, s.type AS section_type
             FROM section_changes c
             LEFT JOIN project_sections s ON s.id = c.section_id
             WHERE c.project_id = :p
             ORDER BY c.id DESC LIMIT 60',
            ['p' => (int) $project['id']]
        );

        $out = [];
        foreach ($entries as $entry) {
            $out[] = [
                'id' => (int) $entry['id'],
                'section_id' => (int) $entry['section_id'],
                'section_type' => (string) ($entry['section_type'] ?? ''),
                'source' => (string) $entry['source'],
                'actor' => (string) $entry['actor'],
                'instruction' => (string) ($entry['instruction'] ?? ''),
                'summary' => (string) $entry['summary'],
                'created_at' => (string) $entry['created_at'],
            ];
        }

        return Response::json(['ok' => true, 'entries' => $out])->noCache();
    }

    // ------------------------------------------------------------------

    public static function find(int $id): ?array
    {
        return $id > 0
            ? Db::first('SELECT * FROM projects WHERE id = :id', ['id' => $id])
            : null;
    }

    private function back(array $project): Response
    {
        return Response::redirect(self::base() . '/projekt/' . $project['id'])->noCache()->noIndex();
    }

    public static function base(): string
    {
        return '/' . trim((string) Config::get('create_path', 'create'), '/');
    }
}
