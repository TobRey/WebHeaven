<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Build\Staende;
use WebAtze\Core\{Audit, Config, Db, Request, Response, Session};

/**
 * Stände herunterladen und wiederherstellen.
 *
 * Ein Stand ist eine Fassung der Website. Der aktive ist der, an dem
 * gerade gearbeitet wird; die älteren liegen daneben.
 *
 * Der wichtigste Satz steht in `Build\Staende`: **Herunterladen packt
 * immer den Ordner, in dem gearbeitet wird - frisch, bei jedem Klick.**
 * Vorher zeigte der Knopf auf das zuletzt abgelegte Archiv, und nach
 * einer Übernahme war das ausgerechnet das gerade hochgeladene. Wer eine
 * Überschrift änderte, speicherte und herunterlud, bekam die Seite von
 * vorher zurück und musste glauben, das Speichern sei kaputt.
 */
final class StandController
{
    /**
     * Den Stand herunterladen, an dem gerade gearbeitet wird.
     *
     * Gepackt wird in diesem Augenblick, aus dem Arbeitsordner. Damit
     * ist das, was herauskommt, immer das, was auf dem Bildschirm steht.
     */
    public function jetzt(Request $request): Response
    {
        $projekt = ProjectController::find($request->paramInt('id'));

        if ($projekt === null) {
            return Response::notFound();
        }

        $datei = Staende::jetztPacken($projekt, 'Herunterladen');

        if ($datei === null) {
            Session::flash('error', 'Hier liegt noch keine Website zum Herunterladen. '
                . 'Lade zuerst ein Archiv hoch.');

            return $this->zurueck($projekt);
        }

        Audit::log('stand.heruntergeladen', (string) $projekt['name'], [
            'bytes' => (int) (filesize($datei) ?: 0),
        ], $request);

        // Ab hier gilt der Stand als draussen. Was danach geändert wird,
        // ist beim Kunden noch nicht angekommen - die Übertragung selbst
        // sieht WebAtze ja nicht mehr.
        Db::update('projects', ['downloaded_at' => Db::now()], 'id = :id', ['id' => (int) $projekt['id']]);

        return Response::file($datei, 'application/zip', true, basename($datei))
            ->noCache()
            ->noIndex();
    }

    /** Einen älteren Stand herunterladen - so, wie er abgelegt wurde. */
    public function aelter(Request $request): Response
    {
        $projekt = ProjectController::find($request->paramInt('id'));

        if ($projekt === null) {
            return Response::notFound();
        }

        $stand = Staende::finden((int) $projekt['id'], $request->paramInt('stand'));
        $datei = $stand === null ? null : Staende::pfad($stand);

        if ($datei === null) {
            return Response::notFound('Diesen Stand gibt es nicht mehr.');
        }

        Audit::log('stand.heruntergeladen', (string) $projekt['name'], [
            'stand' => (int) $stand['id'],
        ], $request);

        return Response::file($datei, 'application/zip', true, basename($datei))
            ->noCache()
            ->noIndex();
    }

    /**
     * Einen älteren Stand wieder aktiv setzen.
     *
     * Was gerade im Arbeitsordner liegt, wird vorher als eigener Stand
     * gesichert - siehe `Staende::wiederherstellen()`. Ohne das wäre
     * dieser Knopf eine Falle.
     */
    public function wiederherstellen(Request $request): Response
    {
        $projekt = ProjectController::find($request->paramInt('id'));

        if ($projekt === null) {
            return Response::notFound();
        }

        $ergebnis = Staende::wiederherstellen($projekt, $request->paramInt('stand'));

        if (!$ergebnis['ok']) {
            Session::flash('error', $ergebnis['error']);

            return $this->zurueck($projekt);
        }

        Session::flash('success', 'Der Stand ist wieder aktiv. Was vorher hier lag, '
            . 'steht als eigener Stand in der Liste.');

        return Response::redirect(self::basis() . '/direkt/' . (int) $projekt['id'])
            ->noCache()
            ->noIndex();
    }

    private function zurueck(array $projekt): Response
    {
        $kunde = (int) ($projekt['customer_id'] ?? 0);

        return Response::redirect($kunde > 0
            ? self::basis() . '/kunden/' . $kunde
            : self::basis() . '/websites')->noCache()->noIndex();
    }

    private static function basis(): string
    {
        return '/' . trim((string) Config::get('create_path', 'create'), '/');
    }
}
