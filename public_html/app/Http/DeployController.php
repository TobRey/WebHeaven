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
                // Und was die letzte Probe ueber den Empfaenger sagt.
                // Bewusst aus der Sitzung und nicht frisch gemessen:
                // Eine Anfrage ueber die Leitung darf keinen
                // Seitenaufbau aufhalten.
                'empfang' => (array) Session::get('empfang_' . (int) $project['id'], []),
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

        FtpDeployer::saveTarget((int) $project['id'], [
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

        Session::flash('success', 'Zugangsdaten gespeichert. Am besten gleich die Verbindung testen.');

        return $this->back($project);
    }

    /**
     * Kann dieser Server überhaupt FTP?
     *
     * Die Frage, die sich am Kundenserver nie beantworten liess:
     * Scheitert dort die Datenverbindung, kann die Ursache eingehend
     * bei ihm liegen - oder ausgehend bei uns. Von einem Endpunkt aus
     * sieht beides gleich aus. Diese Probe fragt dasselbe an einem
     * fremden Ziel und trennt die beiden Fälle.
     */
    public function ausgang(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));

        if ($project === null) {
            return Response::notFound();
        }

        try {
            $ergebnis = \WebAtze\Build\Ftp::ausgangsprobe();
        } catch (\Throwable $e) {
            Logger::exception($e);
            Session::flash('error', 'Die Probe ist abgestuerzt. Bitte melde dich.');

            return $this->back($project);
        }

        Session::flash($ergebnis['ok'] ? 'success' : 'warning', $ergebnis['satz']);
        Session::put('ftp_ordner_' . (int) $project['id'], [
            'details' => $ergebnis['details'],
        ]);

        return $this->back($project);
    }

    /**
     * Die Empfangsdatei zum Hinlegen.
     *
     * Sie wird einmal von Hand auf die Kundenwebsite geladen - mit dem
     * FTP-Programm, das vom eigenen Rechner aus funktioniert. Danach
     * geht alles über HTTPS.
     */
    public function empfangsdatei(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));

        if ($project === null) {
            return Response::notFound();
        }

        $inhalt = \WebAtze\Build\Empfang::datei((int) $project['id']);

        if ($inhalt === '') {
            Session::flash('error', 'Die Vorlage für den Empfänger fehlt im Paket.');

            return $this->back($project);
        }

        Audit::log('empfang.datei', (string) $project['name'], [], $request);

        // Bewusst als text/plain und mit .txt am Namen: Eine PHP-Datei,
        // die der Browser direkt herunterlaedt, ist auf manchen Systemen
        // eine Warnung wert - und beim Hochladen wird sie ohnehin
        // umbenannt. Der Hinweis dazu steht auf der Seite.
        return Response::text($inhalt)
            ->header('Content-Disposition', 'attachment; filename="'
                . \WebAtze\Build\Empfang::DATEI . '.txt"')
            ->noCache()
            ->noIndex();
    }

    /**
     * Nachsehen, ob der Empfänger schon dort liegt.
     *
     * Auf Knopfdruck und nicht beim Seitenaufbau: Es ist eine Anfrage
     * über die Leitung, und die kann dauern. Das Ergebnis bleibt in der
     * Sitzung stehen, genau wie das des Verbindungstests - dann sagt
     * die Seite auch beim nächsten Aufruf noch, was zuletzt gemessen
     * wurde, und wann.
     */
    public function empfangProbe(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));

        if ($project === null) {
            return Response::notFound();
        }

        try {
            $ergebnis = \WebAtze\Build\Empfang::erreichbar($project);
        } catch (\Throwable $e) {
            Logger::exception($e);
            Session::flash('error', 'Die Probe ist abgestürzt. Bitte melde dich.');

            return $this->back($project);
        }

        $this->empfangMerken((int) $project['id'], $ergebnis['ok'], (string) $ergebnis['error']);

        Session::flash($ergebnis['ok'] ? 'success' : 'warning', $ergebnis['ok']
            ? 'Der Empfänger liegt bereit. Das ZIP kann hinauf.'
            : 'Der Empfänger meldet sich nicht: ' . $ergebnis['error']);

        return $this->back($project);
    }

    /**
     * Den Empfänger jetzt entfernen.
     *
     * Das Gegenstück zum Häkchen "liegen lassen". Ohne diesen Knopf
     * wäre das Liegenlassen eine Einbahnstrasse bis zum Ablauf nach 24
     * Stunden - und eine Schreibstelle, die man nicht mehr zumachen
     * kann, lässt man besser gar nicht erst offen.
     */
    public function empfangWeg(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));

        if ($project === null) {
            return Response::notFound();
        }

        try {
            $ergebnis = \WebAtze\Build\Empfang::weg($project);
        } catch (\Throwable $e) {
            Logger::exception($e);
            Session::flash('error', 'Das Entfernen ist abgestürzt. Bitte melde dich.');

            return $this->back($project);
        }

        // Nach dem Entfernen liegt er nicht mehr - und wenn er sich
        // nicht meldet, liegt er auch nicht mehr. Beides ist "weg".
        $this->empfangMerken((int) $project['id'], false, '');

        Audit::log('empfang.entfernt', (string) $project['name'], [
            'geklappt' => $ergebnis['ok'],
        ], $request);

        Session::flash($ergebnis['ok'] ? 'success' : 'warning', $ergebnis['ok']
            ? 'Der Empfänger ist entfernt.'
            : 'Er hat sich nicht gemeldet: ' . $ergebnis['error']
              . ' Falls er noch liegt, verschwindet er spätestens nach 24 Stunden von selbst.');

        return $this->back($project);
    }

    /** Ein hochgeladenes Archiv über HTTPS schicken statt über FTP. */
    public function uploadUeberBruecke(Request $request): Response
    {
        return $this->archivAnnehmen($request, 'zip-per-bruecke');
    }

    /**
     * Den aktuellen Stand über HTTPS holen statt über FTP.
     *
     * Derselbe Live-Stand wie beim FTP-Weg, dieselbe Zeile in der
     * Paketliste - nur eine andere Leitung. Ohne das wäre "FTP
     * vergessen" ein halber Weg: hinauf ja, herunter nicht.
     */
    public function pullLiveBruecke(Request $request): Response
    {
        $project = ProjectController::find($request->paramInt('id'));

        if ($project === null) {
            return Response::notFound();
        }

        if (Jobs::activeFor((int) $project['id']) !== null) {
            Session::flash('warning', 'Für dieses Projekt läuft bereits ein Auftrag.');

            return $this->back($project);
        }

        if (trim((string) ($project['domain'] ?? '')) === '') {
            Session::flash('error', 'Ohne Adresse der Website weiss ich nicht, wen ich anrufen soll.');

            return $this->back($project);
        }

        Jobs::enqueue('stand-per-bruecke', [
            'liegenlassen' => $request->bool('liegenlassen'),
        ], (int) $project['id']);
        Jobs::nudge();

        Audit::log('project.pull.started', (string) $project['name'], ['weg' => 'https'], $request);
        Session::flash('success', 'Der Stand wird über HTTPS geholt. Das dauert je nach Grösse eine Weile.');

        return $this->back($project);
    }

    /** Was zuletzt über den Empfänger gemessen wurde, für die Ansicht. */
    private function empfangMerken(int $projectId, bool $ok, string $grund): void
    {
        Session::put('empfang_' . $projectId, [
            'ok' => $ok,
            'error' => $grund,
            'zeit' => date('d.m.Y H:i'),
        ]);
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

        // Gruen erst, wenn jede Stufe gruen ist.
        //
        // "ok" beantwortet nur die Frage, ob der Zielordner da ist -
        // absichtlich, denn ein Zugang, der lesen aber nicht schreiben
        // darf, taugt zum Stand-Holen. Als Farbe der Meldung genommen
        // ergab das aber eine gruene Erfolgsmeldung ueber einer Kette
        // mit einem roten Kreuz darin. Wer das sieht, glaubt der Farbe
        // und sucht den Fehler spaeter woanders.
        $alleGruen = true;

        foreach ((array) ($result['stufen'] ?? []) as $stufe) {
            if (!($stufe['ok'] ?? false)) {
                $alleGruen = false;
                break;
            }
        }

        Session::flash(
            $result['ok'] ? ($alleGruen ? 'success' : 'warning') : 'error',
            $result['message']
        );

        // Die gefundenen Verzeichnisse merken, damit die Seite sie
        // anbieten kann. Bei einer Subdomain ist das der Unterschied
        // zwischen Raten und Auswaehlen.
        Session::put('ftp_ordner_' . (int) $project['id'], [
            'ordner' => (array) ($result['ordner'] ?? []),
            'vorschlag' => (string) ($result['vorschlag'] ?? ''),
            // Der Servername, der auflöst - zum Anklicken statt zum
            // Abtippen.
            'vorschlagHost' => (string) ($result['vorschlagHost'] ?? ''),
            // Was tatsaechlich gemessen wurde - ein Satz steht in der
            // Meldung, die Einzelheiten liegen aufklappbar darunter.
            'details' => (array) ($result['details'] ?? []),
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
        return $this->archivAnnehmen($request, 'zip-hochladen');
    }

    /**
     * Ein Archiv entgegennehmen und als Auftrag einreihen.
     *
     * Zwei Wege, ein Rumpf: Ob es danach ueber FTP oder ueber HTTPS
     * hinausgeht, entscheidet allein der Auftragstyp - alles davor ist
     * dasselbe, und das soll es auch bleiben.
     */
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

        // Der Weg ueber HTTPS braucht keine FTP-Zugangsdaten - er
        // braucht die Adresse der Website und die Empfangsdatei darauf.
        if ($typ === 'zip-hochladen') {
            $ziel = Db::first(
                'SELECT id FROM deploy_targets WHERE project_id = :p LIMIT 1',
                ['p' => (int) $project['id']]
            );

            if ($ziel === null) {
                Session::flash('error',
                    'Ohne Zugangsdaten gibt es kein Ziel. Trage sie unten ein und teste die Verbindung.');

                return $this->back($project);
            }
        } elseif (trim((string) ($project['domain'] ?? '')) === '') {
            Session::flash('error',
                'Ohne Adresse der Website weiss ich nicht, wen ich anrufen soll.');

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

        Jobs::enqueue($typ, [
            'zip' => $pfad,
            // Nur der Weg ueber HTTPS kennt das - beim FTP-Weg liegt
            // nichts herum, das man liegen lassen koennte.
            'liegenlassen' => $request->bool('liegenlassen'),
        ], (int) $project['id']);
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
