<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Build\{FtpDeployer, ZipExporter};
use WebAtze\Core\{Audit, Config, Crypto, Db, Jobs, Logger, Request, Response, Session, View};

/**
 * Paket erzeugen, herunterladen und die Website hochladen.
 */
final class DeployController
{
    /**
     * So gross darf ein hochgeladenes Website-Archiv sein.
     *
     * Eine Website mit Bildern kommt selten über 60 MB; alles darüber
     * ist eher ein Versehen als eine Website. Der Server selbst setzt
     * mit upload_max_filesize meist eine engere Grenze - dann greift
     * die zuerst, und die Meldung sagt das auch.
     */
    public const MAX_ARCHIV_BYTES = 120 * 1024 * 1024;

    public function show(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        $target = Db::first(
            'SELECT * FROM deploy_targets WHERE project_id = :p ORDER BY id DESC LIMIT 1',
            ['p' => (int) $project['id']]
        );

        return Response::html(View::partial('layouts/admin', [
            'title' => 'Veröffentlichen: ' . (string) $project['name'],
            'content' => View::partial('admin/deploy', [
                'project' => $project,
                'target' => $target,
                'builds' => ZipExporter::listFor((int) $project['id']),
                'job' => Jobs::activeFor((int) $project['id']),
                'brief' => json_decode((string) $project['brief'], true) ?: [],
                // Was der letzte Verbindungstest dort gefunden hat.
                'gefunden' => (array) Session::get('ftp_ordner_' . (int) $project['id'], []),
                // Der gemeinsame Zugang: Alle Websites liegen auf
                // demselben Konto, also gehoert er hier zur Auswahl.
                'hostingAccounts' => \WebAtze\Domain\HostingAccount::all(),
            ]),
        ]))->noCache()->noIndex();
    }

    /** Ein neues Paket schnüren. */
    public function createZip(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        try {
            $result = ZipExporter::create($project);
            Audit::log('project.zipped', (string) $project['name'], ['version' => $result['version']], $request);
            Session::flash('success', sprintf(
                'Paket Version %d erstellt (%s).',
                $result['version'],
                format_bytes((int) $result['bytes'])
            ));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }

        return $this->back($project);
    }

    /**
     * Ein Paket herunterladen.
     *
     * Der Pfad wird über die Datenbank aufgelöst und muss zum Projekt
     * gehören – über die Adresse lässt sich nichts anderes erreichen.
     */
    public function download(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        $path = ZipExporter::pathFor((int) $project['id'], $request->paramInt('build'));
        if ($path === null) {
            return Response::notFound('Dieses Paket gibt es nicht.');
        }

        Audit::log('project.download', (string) $project['name'], ['datei' => basename($path)], $request);

        return Response::file($path, 'application/zip', true, basename($path))
            ->noCache()
            ->noIndex();
    }

    /** FTP-Zugangsdaten speichern. */
    public function saveTarget(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        $protocol = $request->input('protocol', 'sftp');
        $port = $request->int('port', $protocol === 'sftp' ? 22 : 21);

        // Das Passwort wird verschlüsselt abgelegt. Geht das nicht,
        // soll das hier stehen und nicht als Fehlerseite erscheinen.
        $grund = Crypto::status();

        if ($grund !== '' && $request->input('password') !== '') {
            Session::flash('error', 'Das Passwort lässt sich nicht verschlüsseln, deshalb wurde '
                . 'nichts gespeichert. ' . $grund);

            return $this->back($project);
        }

        FtpDeployer::saveTarget((int) $project['id'], [
            'protocol' => $protocol,
            'host' => $request->input('host'),
            'port' => $port,
            'username' => $request->input('username'),
            'password' => $request->input('password'),
            'path' => $request->input('path', '/public_html'),
            'hosting_account_id' => $request->int('hosting_account_id'),
        ]);

        Audit::log('deploy.target_saved', (string) $project['name'], [
            'host' => $request->input('host'),
            'protokoll' => $protocol,
        ], $request);

        Session::flash('success', 'Zugangsdaten gespeichert. Am besten gleich die Verbindung testen.');

        return $this->back($project);
    }

    /** Verbindung prüfen, ohne etwas hochzuladen. */
    public function testTarget(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        // Auch der Test selbst darf nicht abstuerzen. Ein Fehler 500 sagt
        // dem Betreiber nichts - und genau der kam frueher, wenn dem
        // Server die FTP-Erweiterung fehlte.
        try {
            $result = FtpDeployer::test((int) $project['id']);
        } catch (\Throwable $e) {
            Logger::exception($e);

            Session::flash('error',
                'Der Verbindungstest ist abgestuerzt. Das sollte nicht passieren - '
                . 'bitte melde dich. Versuch es solange mit SFTP auf Port 22.');

            return $this->back($project);
        }

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        // Die gefundenen Verzeichnisse merken, damit die Seite sie
        // anbieten kann. Bei einer Subdomain ist das der Unterschied
        // zwischen Raten und Auswaehlen.
        Session::put('ftp_ordner_' . (int) $project['id'], [
            'ordner' => (array) ($result['ordner'] ?? []),
            'vorschlag' => (string) ($result['vorschlag'] ?? ''),
            // Der Servername, der auflöst - zum Anklicken statt zum
            // Abtippen.
            'vorschlagHost' => (string) ($result['vorschlagHost'] ?? ''),
            // Die einzelnen Stufen: Servername, Verbindung, Anmeldung,
            // Passivmodus, Startordner, Inhalt, Zielordner, Schreibprobe.
            // Die erste rote Stufe ist die Diagnose - und dass die
            // gruenen davor sitzen, ist die halbe Antwort.
            'stufen' => (array) ($result['stufen'] ?? []),
            'zeit' => date('d.m.Y H:i'),
        ]);

        return $this->back($project);
    }

    /** Die Website hochladen. */
    public function deploy(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        if (Jobs::activeFor((int) $project['id']) !== null) {
            Session::flash('warning', 'Für dieses Projekt läuft bereits ein Auftrag.');
            return $this->back($project);
        }

        $dist = STORAGE_DIR . '/projects/' . (string) $project['slug'] . '/dist';
        if (!is_dir($dist)) {
            Session::flash('error', 'Die Website muss zuerst gebaut werden.');
            return $this->back($project);
        }

        Jobs::enqueue('deploy', [], (int) $project['id']);
        Jobs::nudge();

        Audit::log('deploy.started', (string) $project['name'], [], $request);
        Session::flash('success', 'Der Upload läuft. Der Fortschritt erscheint gleich hier.');

        return $this->back($project);
    }

    /**
     * Den aktuellen Stand vom Server des Kunden holen.
     *
     * Der Unterschied zum Paket daneben ist der Punkt: Das Paket ist
     * das, was hier zuletzt gebaut wurde. Was tatsächlich beim Kunden
     * liegt, ist etwas anderes, sobald dort jemand etwas geändert hat –
     * hochgeladene Bilder, eingegangene Anfragen, im Backend
     * umgeschriebene Texte. Das steht in keinem gebauten Paket.
     */
    public function pullLive(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));

        if ($project === null) {
            return Response::notFound();
        }

        if (Jobs::activeFor((int) $project['id']) !== null) {
            Session::flash('warning', 'Für dieses Projekt läuft bereits ein Auftrag.');

            return $this->back($project);
        }

        $ziel = Db::first(
            'SELECT id FROM deploy_targets WHERE project_id = :p LIMIT 1',
            ['p' => (int) $project['id']]
        );

        if ($ziel === null) {
            Session::flash(
                'error',
                'Für diese Website sind keine Zugangsdaten hinterlegt. '
                . 'Ohne sie lässt sich nicht nachsehen, was dort liegt.'
            );

            return $this->back($project);
        }

        Jobs::enqueue('live', [], (int) $project['id']);
        Jobs::nudge();

        Audit::log('project.pull.started', (string) $project['name'], [], $request);
        Session::flash('success', 'Der Stand wird geholt. Das dauert je nach Grösse eine Weile.');

        return $this->back($project);
    }

    /**
     * Ein fertiges ZIP entgegennehmen und aufs FTP schieben.
     *
     * Der Weg ohne den eingebauten Generator: Auftragstext kopieren,
     * die Website anderswo bauen lassen, das Ergebnis hier hochladen.
     *
     * Das Archiv wird bei uns nie ausgepackt – jeder Eintrag geht als
     * Datenstrom direkt aus dem ZIP auf das FTP. Eine Kundenwebsite
     * enthält PHP, und ausgepackte fremde PHP-Dateien auf dem eigenen
     * Webserver sind eine Hintertür, ganz gleich wie gut der Ordner
     * gesperrt ist.
     */
    public function uploadZip(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));

        if ($project === null) {
            return Response::notFound();
        }

        if (Jobs::activeFor((int) $project['id']) !== null) {
            Session::flash('warning', 'Für dieses Projekt läuft bereits ein Auftrag.');

            return $this->back($project);
        }

        $ziel = Db::first(
            'SELECT id FROM deploy_targets WHERE project_id = :p LIMIT 1',
            ['p' => (int) $project['id']]
        );

        if ($ziel === null) {
            Session::flash('error',
                'Ohne Zugangsdaten gibt es kein Ziel. Trage sie unten ein und teste die Verbindung.');

            return $this->back($project);
        }

        $datei = $request->file('archiv');

        if ($datei === null || (int) ($datei['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            Session::flash('error', $datei === null
                ? 'Es ist keine Datei angekommen.'
                : self::uploadFehler((int) $datei['error']));

            return $this->back($project);
        }

        $tmp = (string) ($datei['tmp_name'] ?? '');

        // is_uploaded_file und nicht bloss is_file: Ohne diese Prüfung
        // liesse sich über den Namen jede Datei auf dem Server als
        // Archiv ausgeben.
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            Session::flash('error', 'Diese Datei kam nicht über das Formular. Abgelehnt.');

            return $this->back($project);
        }

        $groesse = (int) filesize($tmp);

        if ($groesse > self::MAX_ARCHIV_BYTES) {
            Session::flash('error', sprintf(
                'Das Archiv ist %s gross. Mehr als %s nimmt dieser Server nicht an.',
                format_bytes($groesse),
                format_bytes(self::MAX_ARCHIV_BYTES)
            ));

            return $this->back($project);
        }

        $ordner = ensure_dir(STORAGE_DIR . '/uploads');
        $pfad = $ordner . '/website-' . (int) $project['id'] . '-' . bin2hex(random_bytes(6)) . '.zip';

        if (!@move_uploaded_file($tmp, $pfad)) {
            Session::flash('error', 'Das Archiv liess sich nicht ablegen.');

            return $this->back($project);
        }

        Jobs::enqueue('zip-hochladen', ['zip' => $pfad], (int) $project['id']);
        Jobs::nudge();

        Audit::log('deploy.zip.started', (string) $project['name'], [
            'bytes' => $groesse,
        ], $request);

        Session::flash('success',
            'Das Archiv wird hochgeladen. Der Fortschritt erscheint gleich hier.');

        return $this->back($project);
    }

    /** Was PHP zum fehlgeschlagenen Upload sagt, auf Deutsch. */
    private static function uploadFehler(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'Die Datei ist grösser, als dieser Server annimmt (upload_max_filesize).',
            UPLOAD_ERR_PARTIAL => 'Die Übertragung ist abgebrochen. Nochmal versuchen.',
            UPLOAD_ERR_NO_FILE => 'Es war keine Datei ausgewählt.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
                'Der Server kann die Datei nicht zwischenspeichern. Das ist ein '
                . 'Serverproblem, kein Dateiproblem.',
            default => 'Die Datei ist nicht angekommen.',
        };
    }

    private function back(array $project): Response
    {
        return Response::redirect(
            '/' . trim((string) Config::get('create_path', 'create'), '/')
            . '/projekt/' . $project['id'] . '/veroeffentlichen'
        )->noCache()->noIndex();
    }
}
