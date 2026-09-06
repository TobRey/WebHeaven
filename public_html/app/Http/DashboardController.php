<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Audit, Db, Request, Response, View};
use WebAtze\Domain\{Billing, Calendar, Monitor, Prospects};

/**
 * Übersicht, Kostenaufstellung und Protokoll.
 */
final class DashboardController
{
    /**
     * Die Übersicht.
     *
     * Oben steht, was heute zu tun ist, und was gerade nicht läuft.
     * Darunter das Geld. Ganz unten die Projekte. Die Reihenfolge ist
     * die eines Arbeitstags, nicht die der Datenbank: Wer morgens
     * hereinkommt, will nicht zuerst wissen, wie viele Projekte es gibt.
     */
    public function index(Request $request): Response
    {
        $heute = date('Y-m-d');

        // Einmal holen, zweimal verwenden: die Liste und ihre Summe.
        // openItems() geht jeden Kunden einzeln durch - das soll nicht
        // zweimal je Seitenaufruf passieren.
        $offen = Billing::openItems();
        $offenSumme = 0;

        foreach ($offen as $posten) {
            $offenSumme += (int) $posten['amount_rappen'];
        }

        return $this->page('Übersicht', 'admin/dashboard', [
            'heute' => $heute,
            'termine' => Calendar::between($heute, date('Y-m-d', time() + 7 * 86400)),
            'aufgaben' => Db::all(
                // Zweimal ':leer' waere kuerzer, ginge aber nur auf
                // SQLite: MySQL erlaubt denselben benannten Platzhalter
                // nur einmal je Abfrage.
                "SELECT t.*, c.name AS kunde FROM todos t
                 LEFT JOIN customers c ON c.id = t.customer_id
                 WHERE t.done_at IS NULL AND (t.due_on = :leer1 OR t.due_on <= :bald)
                 ORDER BY CASE WHEN t.due_on = :leer2 THEN 1 ELSE 0 END,
                          t.due_on ASC, t.priority DESC LIMIT 12",
                ['leer1' => '', 'leer2' => '', 'bald' => date('Y-m-d', time() + 7 * 86400)]
            ),
            'ueberfaellig' => (int) Db::value(
                "SELECT COUNT(*) FROM todos WHERE done_at IS NULL AND due_on <> :leer AND due_on < :heute",
                ['leer' => '', 'heute' => $heute],
                0
            ),
            'ausfaelle' => Monitor::down(),
            'wache' => Monitor::summary(),
            'offen' => array_slice($offen, 0, 8),
            'offenSumme' => $offenSumme,
            'offenAnzahl' => count($offen),
            'monat' => (int) Db::value(
                'SELECT COALESCE(SUM(amount_rappen), 0) FROM payments WHERE paid_on >= :von',
                ['von' => date('Y-m') . '-01'],
                0
            ),
            'stapel' => Prospects::counts(),
            'activeJobs' => Db::all(
                "SELECT j.*, p.name AS project_name, p.slug AS project_slug
                 FROM jobs j LEFT JOIN projects p ON p.id = j.project_id
                 WHERE j.status IN ('queued', 'running')
                 ORDER BY j.id DESC LIMIT 10"
            ),
            'failedJobs' => Db::all(
                "SELECT j.*, p.name AS project_name
                 FROM jobs j LEFT JOIN projects p ON p.id = j.project_id
                 WHERE j.status = 'failed' AND j.finished_at > :since
                 ORDER BY j.id DESC LIMIT 5",
                ['since' => date('Y-m-d H:i:s', time() - 7 * 86400)]
            ),
            'projects' => Db::all(
                'SELECT p.*,
                        (SELECT COUNT(*) FROM project_pages WHERE project_id = p.id) AS page_count,
                        (SELECT COUNT(*) FROM site_versions WHERE project_id = p.id) AS stand_count
                 FROM projects p
                 ORDER BY p.updated_at DESC, p.id DESC
                 LIMIT 12'
            ),
            'stats' => $this->stats(),
        ]);
    }

    public function costs(Request $request): Response
    {
        $month = date('Y-m');

        $summary = Db::first(
            "SELECT COUNT(*) AS calls,
                    COALESCE(SUM(cost_micro), 0) AS cost,
                    COALESCE(SUM(input_tokens + cache_read_tokens + cache_write_tokens), 0) AS input_tokens,
                    COALESCE(SUM(output_tokens), 0) AS output_tokens
             FROM ai_calls WHERE created_at >= :from",
            ['from' => $month . '-01 00:00:00']
        ) ?? [];

        $perProject = Db::all(
            "SELECT p.id, p.name, p.slug,
                    COUNT(a.id) AS calls,
                    COALESCE(SUM(a.cost_micro), 0) AS cost
             FROM ai_calls a
             JOIN projects p ON p.id = a.project_id
             GROUP BY p.id, p.name, p.slug
             ORDER BY cost DESC
             LIMIT 40"
        );

        $perMonth = Db::all(
            "SELECT " . (Db::isSqlite()
                ? "substr(created_at, 1, 7)"
                : "DATE_FORMAT(created_at, '%Y-%m')") . " AS month,
                    COUNT(*) AS calls,
                    COALESCE(SUM(cost_micro), 0) AS cost
             FROM ai_calls
             GROUP BY month
             ORDER BY month DESC
             LIMIT 12"
        );

        $total = (int) Db::value('SELECT COALESCE(SUM(cost_micro), 0) FROM ai_calls', [], 0);

        return $this->page('Kosten', 'admin/costs', [
            'summary' => $summary,
            'perProject' => $perProject,
            'perMonth' => $perMonth,
            'total' => $total,
            'month' => $month,
        ]);
    }

    public function auditLog(Request $request): Response
    {
        $page = max(1, $request->int('seite', 1));
        $perPage = 60;

        $entries = Audit::recent($perPage, ($page - 1) * $perPage);
        $total = (int) Db::value('SELECT COUNT(*) FROM audit_log', [], 0);

        return $this->page('Protokoll', 'admin/audit', [
            'entries' => $entries,
            'page' => $page,
            'pages' => (int) ceil($total / $perPage),
            'total' => $total,
        ]);
    }

    // ------------------------------------------------------------------

    private function stats(): array
    {
        $costThisMonth = (int) Db::value(
            'SELECT COALESCE(SUM(cost_micro), 0) FROM ai_calls WHERE created_at >= :from',
            ['from' => date('Y-m') . '-01 00:00:00'],
            0
        );

        return [
            'projects' => (int) Db::value('SELECT COUNT(*) FROM projects', [], 0),
            'live' => (int) Db::value("SELECT COUNT(*) FROM projects WHERE status = 'live'", [], 0),
            'leads_new' => (int) Db::value("SELECT COUNT(*) FROM leads WHERE status = 'new'", [], 0),
            'kunden' => (int) Db::value("SELECT COUNT(*) FROM customers WHERE status = 'aktiv'", [], 0),
            'cost_month' => $costThisMonth,
        ];
    }

    private function page(string $title, string $template, array $data): Response
    {
        return Response::html(View::partial('layouts/admin', array_merge($data, [
            'title' => $title,
            'content' => View::partial($template, $data),
        ])))->noCache()->noIndex();
    }
}
