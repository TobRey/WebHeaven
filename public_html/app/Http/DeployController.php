<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Build\Zugang;
use WebAtze\Core\{Audit, Config, Crypto, Db, Jobs, Request, Response, Session, View};

/**
 * Der Stand herein, das Paket hinaus.
 *
 * Von hier aus geht nichts mehr auf den Kundenserver. Drei Wege dorthin
 * waren durchgemessen und alle drei tot - FTP, eine Empfangsdatei über
 * HTTPS, eine dauerhafte Leseschnittstelle. Von einem Hosting zum
 * anderen kommt nichts durch.
 *
 * Also übernimmt der Mensch die Übertragung mit seinem eigenen
 * FTP-Programm, und WebAtze ist die Werkstatt dazwischen: Archiv
 * hochladen, auspacken, bearbeiten, Paket herunterladen. Die
 * Zugangsdaten bleiben trotzdem hier - zum Nachschlagen, wenn FileZilla
 * danach fragt.
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
                'job' => Jobs::activeFor((int) $project['id']),
                'brief' => json_decode((string) $project['brief'], true) ?: [],
                // Gibt es etwas, das noch nicht heruntergeladen wurde?
                'offen' => \WebAtze\Domain\Websites::offeneAenderung($project),
                // Der gemeinsame Zugang: Alle Websites liegen auf
                // demselben Konto, also gehoert er hier zur Auswahl.
                'hostingAccounts' => \WebAtze\Domain\HostingAccount::all(),
            ]),
        ]))->noCache()->noIndex();
    }

    /** FTP-Zugangsdaten speichern. */
    public function saveTarget(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));
        if ($project === null) {
            return Response::notFound();
        }

        // Dieselben Vorgaben wie im Formular. Standen hier andere,
        // brachte ein fehlendes Feld stillschweigend SFTP zurueck.
        $protocol = $request->input('protocol', 'ftp');
        $port = $request->int('port', $protocol === 'sftp' ? 22 : 21);

        // Das Passwort wird verschlüsselt abgelegt. Geht das nicht,
        // soll das hier stehen und nicht als Fehlerseite erscheinen.
        $grund = Crypto::status();

        if ($grund !== '' && $request->input('password') !== '') {
            Session::flash('error', 'Das Passwort lässt sich nicht verschlüsseln, deshalb wurde '
                . 'nichts gespeichert. ' . $grund);

            return $this->back($project);
        }

        Zugang::saveTarget((int) $project['id'], [
            'protocol' => $protocol,
            'host' => $request->input('host'),
            'port' => $port,
            'username' => $request->input('username'),
            'password' => $request->input('password'),
            'path' => $request->input('path', '/'),
            'hosting_account_id' => $request->int('hosting_account_id'),
        ]);

        Audit::log('deploy.target_saved', (string) $project['name'], [
            'host' => $request->input('host'),
            'protokoll' => $protocol,
        ], $request);

        Session::flash('success', 'Zugangsdaten gespeichert. Sie stehen hier zum Nachschlagen - '
            . 'übertragen wirst du mit deinem eigenen FTP-Programm.');

        return $this->back($project);
    }

    /**
     * Den Stand vom Kunden entgegennehmen.
     *
     * Du holst die Website mit deinem FTP-Programm herunter und lädst
     * sie hier als ZIP hoch. Ausgepackt wird nach
     * `storage/projects/<slug>/live`.
     *
     * Geschützt ist der Ordner durch `public_html/storage/.htaccess`
     * (`Require all denied`) - das ist auf Apache die Sperre, und es ist
     * die einzige. Ausgeliefert wird sonst nur über Vorschau und
     * Direktbearbeitung, und beide schieben Bytes mit einer festen
     * Typenliste. Mehr dazu im Kopf von `Build\Uebernahme`.
     */
    public function uebernehmen(Request $request): Response
    {
        return $this->archivAnnehmen($request, 'zip-uebernehmen');
    }

    /** Ein Archiv entgegennehmen und als Auftrag einreihen. */
    private function archivAnnehmen(Request $request, string $typ): Response
    {
        $project = ProjectController::find($request->paramInt('id'));

        if ($project === null) {
            return Response::notFound();
        }

        if (Jobs::activeFor((int) $project['id']) !== null) {
            Session::flash('warning', 'Für dieses Projekt läuft bereits ein Auftrag.');

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

        Jobs::enqueue($typ, ['zip' => $pfad], (int) $project['id']);
        Jobs::nudge();

        Audit::log('project.uebernahme.started', (string) $project['name'], [
            'bytes' => $groesse,
        ], $request);

        Session::flash('success',
            'Das Archiv wird ausgepackt. Der Fortschritt erscheint gleich hier.');

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
