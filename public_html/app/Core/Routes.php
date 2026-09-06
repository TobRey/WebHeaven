<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Alle Adressen der Anwendung an einer Stelle.
 *
 * Öffentliche Seiten gibt es zweimal: deutsch ohne Vorsilbe ('/leistungen')
 * und englisch mit '/en' davor ('/en/services').
 */
final class Routes
{
    public static function register(Router $router): void
    {
        self::publicPages($router);
        self::machineFiles($router);
        self::counter($router);
        self::api($router);
        self::calendarFeed($router);
        self::preview($router);
        self::assistant($router);
        self::hiddenArea($router);
    }

    // ------------------------------------------------------------------
    // Öffentliche Website
    // ------------------------------------------------------------------

    private static function publicPages(Router $router): void
    {
        $pages = [
            '' => 'SiteController@home',
            'services' => 'SiteController@services',
            'process' => 'SiteController@process',
            'work' => 'SiteController@work',
            'contact' => 'SiteController@contact',
            'imprint' => 'SiteController@imprint',
            'privacy' => 'SiteController@privacy',
        ];

        foreach ($pages as $key => $handler) {
            foreach (I18n::LOCALES as $locale) {
                $path = self::pathFor($key, $locale);
                $router->get($path, $handler);
            }
        }

        // Einzelne Referenz
        $router->get('/referenzen/{slug}', 'SiteController@workDetail');
        $router->get('/en/work/{slug}', 'SiteController@workDetail');

        // Die Miniatur in der Referenzkarte.
        //
        // Sie zeigt eine dauerhafte Kopie der fertigen Kundenwebsite, nicht
        // die Vorschau: Die wird nach 24 Stunden aufgeräumt, und dann stünde
        // in jeder Referenzkarte ein leerer Rahmen.
        $router->get('/referenz-ansicht/{slug}', 'SiteController@workPreview');
        $router->get('/referenz-ansicht/{slug}/', 'SiteController@workPreview');
        $router->get('/referenz-ansicht/{slug}/{path:.+}', 'SiteController@workPreviewFile');
    }

    private static function pathFor(string $key, string $locale): string
    {
        $default = (string) Config::get('default_locale', 'de');
        $prefix = $locale === $default ? '' : '/' . $locale;

        if ($key === '') {
            return $prefix === '' ? '/' : $prefix;
        }

        return $prefix . '/' . I18n::routeSegment($key, $locale);
    }

    // ------------------------------------------------------------------
    // Dateien für Suchmaschinen und Browser
    // ------------------------------------------------------------------

    private static function machineFiles(Router $router): void
    {
        $router->get('/robots.txt', 'SiteController@robots');
        $router->get('/sitemap.xml', 'SiteController@sitemap');
        $router->get('/manifest.webmanifest', 'SiteController@manifest');
    }

    // ------------------------------------------------------------------
    // Der Besucherzähler
    //
    // Kurze Adressen mit Absicht: Sie stehen in fremden Websites, und ein
    // Einzeiler soll ein Einzeiler bleiben. Beide sind offen erreichbar -
    // mehr als "zähl einen Aufruf" lässt sich damit nicht anstellen.
    // ------------------------------------------------------------------

    private static function counter(Router $router): void
    {
        $router->get('/z.js', 'CounterController@script');
        $router->get('/z', 'CounterController@hit');

        // Die Anpassungen aus dem Frontend-Fix. Sie liegen unter
        // storage/ und werden hier ausgeliefert - so braucht der Server
        // keine Schreibrechte im Web-Ordner.
        $router->get('/frontend-fix.css', 'FrontendFixController@stylesheet');

        // Das Stylesheet der eigenen Website, aus PHP statt aus dem
        // gebauten Paket. Die Prüfsumme steht in der Adresse - deshalb
        // darf der Browser es lange behalten.
        $router->get('/site.css', 'StyleController@stylesheet');
    }

    // ------------------------------------------------------------------
    // Öffentliche Schnittstellen
    // ------------------------------------------------------------------

    private static function api(Router $router): void
    {
        $router->group(['csrf'], static function (Router $r): void {
            $r->post('/api/kontakt', 'ContactController@submit');

            // Der Fragebogen. Wer den Verweis hat, darf schreiben – ein
            // Konto braucht der Kunde dafür nicht.
            $r->post('/fragebogen/{token}', 'QuestionnaireController@submit');
        });

        $router->get('/fragebogen/{token}', 'QuestionnaireController@show');
    }

    // ------------------------------------------------------------------
    // Der Kalender fürs Telefon
    // ------------------------------------------------------------------

    /**
     * Der abonnierbare Kalender.
     *
     * Ohne Anmeldung, weil ein Telefon sich nicht anmelden kann. Der
     * Schutz steckt im Zufallswort in der Adresse; die Antwort auf ein
     * falsches ist dieselbe wie auf eine unbekannte Seite.
     */
    private static function calendarFeed(Router $router): void
    {
        $router->get('/kalender/{token:[a-f0-9]+}.ics', 'CompanyController@feed');
    }

    // ------------------------------------------------------------------
    // Vorschau der erstellten Kundenwebsites
    // ------------------------------------------------------------------

    private static function preview(Router $router): void
    {
        $router->get('/vorschau/{token}', 'PreviewController@index');
        $router->get('/vorschau/{token}/', 'PreviewController@index');
        $router->get('/vorschau/{token}/{path:.+}', 'PreviewController@file');
    }

    // ------------------------------------------------------------------
    // Relais für die Kunden-Backends
    //
    // Ein Kundenbackend hat keinen eigenen Zugang zur KI. Es schickt seine
    // Anfrage hierher, wo der Schlüssel liegt. So verlässt der Schlüssel
    // niemals diesen Server.
    // ------------------------------------------------------------------

    private static function assistant(Router $router): void
    {
        $router->post('/assistant/v1/edit', 'AssistantController@edit');
        $router->post('/assistant/v1/ping', 'AssistantController@ping');

        // Der Support-Bereich auf den Kundenwebsites meldet sich ebenso
        // hierher – mit demselben Kennwort.
        $router->post('/assistant/v1/support', 'SupportController@post');
        $router->post('/assistant/v1/support/faden', 'SupportController@thread');

        // Und der Zähler holt seine Zahlen ab bzw. nimmt sie entgegen.
        $router->post('/assistant/v1/zaehler', 'VisitController@collect');
    }

    // ------------------------------------------------------------------
    // Der versteckte Bereich
    // ------------------------------------------------------------------

    private static function hiddenArea(Router $router): void
    {
        $base = '/' . trim((string) Config::get('create_path', 'create'), '/');

        // Anmeldung – ohne Vorprüfung, sonst käme man nie hinein.
        $router->get($base, 'AuthController@showLogin');
        // Der zweite Faktor – ebenfalls ohne Vorprüfung, denn wer hier
        // steht, ist noch nicht angemeldet.
        $router->get($base . '/code', 'AuthController@showCode');

        $router->group(['csrf'], static function (Router $r) use ($base): void {
            $r->post($base, 'AuthController@login');
            $r->post($base . '/code', 'AuthController@submitCode');
            $r->post($base . '/abmelden', 'AuthController@logout');
        });

        $router->group(['auth'], static function (Router $r) use ($base): void {
            // Übersicht
            $r->get($base . '/start', 'DashboardController@index');

            // Websites: die selbst gebauten und die hinzugefügten
            $r->get($base . '/websites', 'WebsiteController@index');
            $r->get($base . '/websites/neu', 'WebsiteController@blank');
            $r->get($base . '/websites/{id}', 'WebsiteController@show');

            // Neues Projekt
            $r->get($base . '/neu', 'CreateController@form');
            $r->get($base . '/projekt/{id}', 'ProjectController@show');
            $r->get($base . '/projekt/{id}/auftrag', 'ProjectController@prompt');
            $r->get($base . '/projekt/{id}/sections', 'ProjectController@sections');
            $r->get($base . '/projekt/{id}/domain', 'DomainController@show');
            $r->get($base . '/projekt/{id}/veroeffentlichen', 'DeployController@show');
            // Die Staende einer Website.
            //
            // Ohne Nummer: der Stand, an dem gerade gearbeitet wird -
            // frisch gepackt, in diesem Augenblick. Mit Nummer: ein
            // aelterer, so wie er damals abgelegt wurde.
            $r->get($base . '/projekt/{id}/stand', 'StandController@jetzt');
            $r->get($base . '/projekt/{id}/stand/{stand}', 'StandController@aelter');
            // Das Vorschaubild in den Listen. Hinter der Anmeldung und
            // ohne ablaufendes Kennwort - anders als die Vorschau, die
            // nach einem Tag erlischt und dann ein leeres Bild ergaebe.
            $r->get($base . '/projekt/{id}/miniatur', 'PreviewController@thumbnail');
            $r->get($base . '/projekt/{id}/miniatur/{path:.+}', 'PreviewController@thumbnail');

            // Verwaltung
            // ------------------------------------------- Unternehmen
            $r->get($base . '/kunden', 'CustomerController@index');
            $r->get($base . '/kunden/neu', 'CustomerController@blank');
            $r->get($base . '/kunden/{id}', 'CustomerController@show');
            $r->get($base . '/mitarbeitende', 'CompanyController@employees');
            $r->get($base . '/kalender', 'CompanyController@calendar');
            $r->get($base . '/buchhaltung', 'CompanyController@books');

            // ------------------------------------------- Kundensuche
            $r->get($base . '/kundensuche', 'ProspectController@index');
            $r->get($base . '/potenzielle-kunden', 'ProspectController@shortlist');

            // ------------------------------------------- Wartung und Tresor
            $r->get($base . '/wartung', 'GuardController@index');
            $r->get($base . '/wartung/sicherung/{id}', 'GuardController@downloadBackup');
            $r->get($base . '/wartung/{id}', 'GuardController@show');
            $r->get($base . '/passwoerter', 'VaultController@index');

            $r->get($base . '/anfragen', 'LeadController@index');
            $r->get($base . '/support', 'SupportAdminController@index');
            $r->get($base . '/referenzen', 'ShowcaseController@index');
            $r->get($base . '/fragebogen', 'QuestionnaireController@index');
            $r->get($base . '/rechnungen', 'BillingController@index');
            $r->get($base . '/rechnungen/{id}/pdf', 'BillingController@download');
            $r->get($base . '/vertraege', 'BillingController@contracts');
            $r->get($base . '/zahlen', 'MaintenanceController@visits');

            // ------------------------------------------- Intranet
            // Nur fuer mich. Die Reihenfolge zaehlt: '/intranet/neu'
            // muss vor '/intranet/{id}' stehen, sonst gilt "neu" als
            // Kennung und die Seite waere nie erreichbar.
            $r->get($base . '/frontend-fix', 'FrontendFixController@index');

            $r->get($base . '/intranet', 'IntranetController@index');
            $r->get($base . '/intranet/neu', 'IntranetController@blank');
            $r->get($base . '/intranet/{id}', 'IntranetController@show');
            // Die alten Adressen bleiben gueltig - Lesezeichen sollen nicht
            // ins Leere laufen, nur weil die Seite umgezogen ist.
            $r->get($base . '/sicherungen', 'GuardController@index');
            $r->get($base . '/sicherungen/{id}', 'GuardController@downloadBackup');
            $r->get($base . '/kosten', 'DashboardController@costs');
            $r->get($base . '/protokoll', 'DashboardController@auditLog');
            $r->get($base . '/einstellungen', 'SettingsController@index');
        });

        $router->group(['auth', 'csrf'], static function (Router $r) use ($base): void {
            $r->post($base . '/neu', 'CreateController@submit');

            $r->post($base . '/websites', 'WebsiteController@save');
            $r->post($base . '/websites/zuordnen', 'WebsiteController@assign');
            $r->post($base . '/websites/entfernen', 'WebsiteController@destroy');

            $r->post($base . '/projekt/{id}/bauen', 'ProjectController@rebuild');
            $r->post($base . '/projekt/{id}/loeschen', 'ProjectController@destroy');
            $r->post($base . '/projekt/{id}/support-schluessel', 'ProjectController@newSupportKeys');
            // Der Stand vom Kunden herein. Von hier aus geht nichts
            // mehr hinaus: Drei Wege zum Kundenserver waren
            // durchgemessen und alle drei tot. Uebertragen wird von
            // Hand, mit einem FTP-Programm - WebAtze packt aus, laesst
            // bearbeiten und packt wieder ein.
            $r->post($base . '/projekt/{id}/uebernehmen', 'DeployController@uebernehmen');
            $r->post($base . '/projekt/{id}/stand/{stand}/wiederherstellen',
                'StandController@wiederherstellen');
            // Die Zugangsdaten bleiben - zum Nachschlagen, nicht zum
            // Verbinden.
            $r->post($base . '/projekt/{id}/ftp', 'DeployController@saveTarget');
            $r->post($base . '/projekt/{id}/domain', 'DomainController@save');
            $r->post($base . '/projekt/{id}/domain/pruefen', 'DomainController@check');
            $r->post($base . '/projekt/{id}/domain/schritt', 'DomainController@step');

            // ------------------------------------------- Unternehmen
            $r->post($base . '/kunden', 'CustomerController@save');
            $r->post($base . '/kunden/{id}', 'CustomerController@save');
            $r->post($base . '/kunden/{id}/loeschen', 'CustomerController@destroy');
            $r->post($base . '/kunden/{id}/posten', 'CustomerController@saveCharge');
            $r->post($base . '/kunden/{id}/posten/loeschen', 'CustomerController@deleteCharge');
            $r->post($base . '/kunden/{id}/bezahlt', 'CustomerController@togglePaid');
            $r->post($base . '/kunden/{id}/rechnung', 'CustomerController@invoice');

            $r->post($base . '/mitarbeitende', 'CompanyController@saveEmployee');
            $r->post($base . '/mitarbeitende/loeschen', 'CompanyController@deleteEmployee');

            $r->post($base . '/aufgaben', 'CompanyController@saveTodo');
            $r->post($base . '/aufgaben/umschalten', 'CompanyController@toggleTodo');
            $r->post($base . '/aufgaben/loeschen', 'CompanyController@deleteTodo');

            $r->post($base . '/termine', 'CompanyController@saveAppointment');
            $r->post($base . '/termine/loeschen', 'CompanyController@deleteAppointment');

            $r->post($base . '/ausgaben', 'CompanyController@saveExpense');
            $r->post($base . '/ausgaben/loeschen', 'CompanyController@deleteExpense');

            // ------------------------------------------- Kundensuche
            $r->post($base . '/kundensuche/auftrag', 'ProspectController@order');
            $r->post($base . '/kundensuche/einlesen', 'ProspectController@import');
            $r->post($base . '/kundensuche/entscheiden', 'ProspectController@decide');
            $r->post($base . '/potenzielle-kunden', 'ProspectController@add');
            $r->post($base . '/potenzielle-kunden/status', 'ProspectController@setStatus');
            $r->post($base . '/potenzielle-kunden/notiz', 'ProspectController@note');
            $r->post($base . '/potenzielle-kunden/loeschen', 'ProspectController@destroy');
            $r->post($base . '/potenzielle-kunden/uebernehmen', 'ProspectController@convert');

            // ------------------------------------------- Wartung
            $r->post($base . '/wartung/waechter', 'GuardController@save');
            $r->post($base . '/wartung/pruefen', 'GuardController@check');
            $r->post($base . '/wartung/alle-pruefen', 'GuardController@checkAll');
            $r->post($base . '/wartung/entfernen', 'GuardController@destroy');
            $r->post($base . '/wartung/sichern', 'GuardController@runBackup');
            $r->post($base . '/wartung/aufbewahrung', 'GuardController@saveRetention');

            // ------------------------------------------- Tresor
            $r->post($base . '/passwoerter/oeffnen', 'VaultController@unlock');
            $r->post($base . '/passwoerter/schliessen', 'VaultController@lock');
            $r->post($base . '/passwoerter/speichern', 'VaultController@save');
            $r->post($base . '/passwoerter/loeschen', 'VaultController@destroy');
            $r->post($base . '/passwoerter/uebernehmen', 'VaultController@adopt');
            $r->post($base . '/passwoerter/zeigen', 'VaultController@reveal');
            $r->post($base . '/passwoerter/schluessel', 'VaultController@repairKey');

            $r->post($base . '/wartung/sicherung/loeschen', 'GuardController@deleteBackup');
            $r->post($base . '/wartung/sicherung/jetzt', 'GuardController@backupNow');

            $r->post($base . '/kalender/neue-adresse', 'CompanyController@newFeedToken');

            $r->post($base . '/anfragen/{id}/status', 'LeadController@setStatus');
            $r->post($base . '/anfragen/{id}/loeschen', 'LeadController@destroy');
            $r->post($base . '/support/{id}/antwort', 'SupportAdminController@reply');
            $r->post($base . '/support/{id}/status', 'SupportAdminController@setStatus');
            $r->post($base . '/support/{id}/loeschen', 'SupportAdminController@destroy');
            $r->post($base . '/referenzen/{id}', 'ShowcaseController@update');
            $r->post($base . '/fragebogen', 'QuestionnaireController@create');
            $r->post($base . '/fragebogen/{id}/uebernehmen', 'QuestionnaireController@adopt');
            $r->post($base . '/fragebogen/{id}/loeschen', 'QuestionnaireController@destroy');
            $r->post($base . '/rechnungen', 'BillingController@create');
            $r->post($base . '/rechnungen/{id}/zustand', 'BillingController@setStatus');
            $r->post($base . '/rechnungen/{id}/rechnung', 'BillingController@convert');
            $r->post($base . '/rechnungen/{id}/senden', 'BillingController@send');
            $r->post($base . '/rechnungen/{id}/loeschen', 'BillingController@destroy');
            $r->post($base . '/frontend-fix', 'FrontendFixController@apply');
            $r->post($base . '/frontend-fix/umschalten', 'FrontendFixController@toggle');
            $r->post($base . '/frontend-fix/loeschen', 'FrontendFixController@destroy');
            $r->post($base . '/frontend-fix/zuruecksetzen', 'FrontendFixController@clear');

            $r->post($base . '/intranet', 'IntranetController@save');
            $r->post($base . '/intranet/anheften', 'IntranetController@pin');
            $r->post($base . '/intranet/loeschen', 'IntranetController@destroy');

            $r->post($base . '/vertraege', 'BillingController@createContract');
            $r->post($base . '/vertraege/abrechnen', 'BillingController@billContracts');
            $r->post($base . '/vertraege/{id}/kuendigen', 'BillingController@cancelContract');
            $r->post($base . '/sicherungen', 'GuardController@runBackup');
            $r->post($base . '/zahlen/{id}', 'MaintenanceController@fetchVisits');
            $r->post($base . '/einstellungen', 'SettingsController@save');
        });

        // ------------------------------------------------------------- Editor
        //
        // Ein Bündel, drei Orte: hier für Kundenwebsites, im
        // Kundenbackend, auf der eigenen Website. Damit das kein Wunsch
        // bleibt, sind die Adressen überall dieselben - hier gegen die
        // Datenbank, beim Kunden gegen data/site.php.
        $router->group(['auth'], static function (Router $r) use ($base): void {
            // Eine fremde Website bearbeiten: keine Abschnitte, sondern
            // die Datei selbst. Die Seite laeuft in einem Rahmen mit
            // gleicher Herkunft - deshalb liegen die Dateien hier hinter
            // der Anmeldung und nicht unter der Vorschau, deren Kennwort
            // nach einem Tag ablaeuft.
            $r->get($base . '/direkt/{id}', 'DirektController@index');
            $r->get($base . '/direkt/{id}/datei/{pfad:.+}', 'DirektController@datei');
            $r->get($base . '/editor/{id}', 'EditorController@index');
            $r->get($base . '/editor/{id}/seite/{seite}/ansicht', 'EditorController@view');
            $r->get($base . '/editor/{id}/seite/{seite}/fassungen', 'EditorController@versions');

            // Die Dateien der gezeigten Website. Muss nach der Ansicht
            // stehen, sonst schluckt das Muster sie mit.
            $r->get($base . '/editor/{id}/seite/{seite}/{path:.+}', 'EditorController@file');
        });

        $router->group(['auth', 'csrf'], static function (Router $r) use ($base): void {
            $r->post($base . '/direkt/{id}/speichern', 'DirektController@speichern');
            $r->post($base . '/direkt/{id}/bild', 'DirektController@bild');
            $r->post($base . '/editor/{id}/ziehen', 'EditorController@move');
            $r->post($base . '/editor/{id}/einsetzen', 'EditorController@add');
            $r->post($base . '/editor/{id}/entfernen', 'EditorController@remove');
            $r->post($base . '/editor/{id}/verdoppeln', 'EditorController@duplicate');
            $r->post($base . '/editor/{id}/eintrag', 'EditorController@item');
            $r->post($base . '/editor/{id}/feld', 'EditorController@field');
            $r->post($base . '/editor/{id}/vorlage', 'EditorController@template');
            $r->post($base . '/editor/{id}/effekte', 'EditorController@effects');
            $r->post($base . '/editor/{id}/bausteine', 'EditorController@blocks');
            $r->post($base . '/editor/{id}/sichtbar', 'EditorController@toggle');
            $r->post($base . '/editor/{id}/anweisung', 'EditorController@instruct');
            $r->post($base . '/editor/{id}/seitenanweisung', 'EditorController@pagePrompt');
            $r->post($base . '/editor/{id}/thema', 'EditorController@theme');
            $r->post($base . '/editor/{id}/veroeffentlichen', 'EditorController@publish');
            $r->post($base . '/editor/{id}/verwerfen', 'EditorController@discard');
            $r->post($base . '/editor/{id}/zurueckholen', 'EditorController@restore');
        });

        // Schnittstellen des Adminbereichs (JSON)
        $router->group(['auth', 'csrf'], static function (Router $r) use ($base): void {
            $r->post('/api/jobs/{id}/abbrechen', 'JobController@cancel');
            $r->post('/api/jobs/{id}/nochmal', 'JobController@retry');
            $r->post('/api/sections/{id}/anweisung', 'SectionController@instruct');
            $r->post('/api/sections/{id}/template', 'SectionController@switchTemplate');
            $r->post('/api/sections/{id}/template/neu', 'SectionController@generateTemplate');
            $r->post('/api/sections/{id}/sichtbar', 'SectionController@toggle');
            $r->post('/api/sections/{id}/reihenfolge', 'SectionController@reorder');
            $r->post('/api/sections/{id}/bild', 'SectionController@replaceImage');
            $r->post('/api/sections/{id}/zuruecksetzen', 'SectionController@revert');
        });

        $router->group(['auth'], static function (Router $r) use ($base): void {
            $r->get('/api/jobs/{id}', 'JobController@status');
            $r->get('/api/sections/{id}', 'SectionController@show');
            $r->get('/api/sections/{id}/templates', 'SectionController@templates');
            $r->get('/api/projekte/{id}/verlauf', 'ProjectController@history');
        });

        // Der Worker kann auch über eine Adresse angestossen werden, damit
        // ein Auftrag nicht bis zum nächsten Cron-Lauf wartet.
        $router->get('/worker/tick', 'WorkerController@tick');
        $router->post('/worker/tick', 'WorkerController@tick');
    }
}
