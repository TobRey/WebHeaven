<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Legt die Tabellen an und hält sie aktuell.
 *
 * Die Tabellen werden einmal beschrieben und für MySQL wie für SQLite
 * übersetzt. So laufen die automatischen Tests ohne MySQL-Server, während
 * auf dem Hosting dieselbe Struktur entsteht.
 *
 * WICHTIG: Das hier ist ausschliesslich die Datenbank von WebAtze selbst.
 * Generierte Kundenwebsites bekommen niemals von sich aus eine Datenbank.
 */
final class Schema
{
    /** Grobe Fassungsnummer, nur zum Lesen im Protokoll. */
    public const VERSION = 4;

    /** Wo der eingespielte Stand vermerkt ist. */
    private const STAMP = '/schema-version.txt';

    /**
     * Dafür sorgen, dass die Tabellen zur eingespielten Fassung passen.
     *
     * Das ist der Grund, warum es diese Methode gibt: Ein Update-Paket
     * enthält bewusst keine install.php – sonst könnte ein Fehlklick die
     * Einrichtung überschreiben. Damit lief aber auch migrate() nie, und
     * neue Tabellen entstanden auf dem Hosting nicht. Jede Seite, die
     * eine davon brauchte, endete im Fünfhunderter.
     *
     * Der Normalfall kostet einen Dateizugriff: Steht im Merker dieselbe
     * Zahl wie in VERSION, passiert nichts weiter.
     */
    public static function ensureCurrent(): void
    {
        $merker = STORAGE_DIR . self::STAMP;
        $stand = self::fingerprint();

        if (is_file($merker) && trim((string) file_get_contents($merker)) === $stand) {
            return;
        }

        // Zwei gleichzeitige Anfragen sollen nicht beide migrieren.
        //
        // Die Sperre ist aber nur eine Bequemlichkeit, keine Bedingung:
        // Lässt sich die Datei nicht anlegen - kein Schreibrecht, volle
        // Platte -, wird trotzdem migriert. Sonst führte ausgerechnet
        // ein Rechteproblem zurück in genau die Fünfhunderter, gegen die
        // das hier gebaut ist. Doppelt ausgeführte Migrationen sind
        // harmlos: migrate() überspringt, was schon vermerkt ist, und
        // execute() verzeiht «existiert bereits».
        ensure_dir(dirname($merker));

        $sperre = @fopen($merker . '.lock', 'c');
        $habeSperre = false;

        if ($sperre !== false) {
            $habeSperre = flock($sperre, LOCK_EX | LOCK_NB);

            if (!$habeSperre) {
                // Jemand anderes ist schon dabei. Warten, bis er fertig
                // ist, dann hat diese Anfrage ihre Tabellen auch.
                flock($sperre, LOCK_EX);
                flock($sperre, LOCK_UN);
                fclose($sperre);

                return;
            }
        }

        try {
            self::migrate();

            if (@file_put_contents($merker, $stand) === false) {
                // Ohne Merker läuft migrate() bei jedem Aufruf erneut.
                // Das ist langsam, aber richtig - und es soll auffallen.
                Logger::warning('Der Merker für den Tabellenstand lässt sich nicht schreiben.', [
                    'datei' => $merker,
                ]);
            }

            Logger::info('Tabellen auf den neuesten Stand gebracht.', ['fassung' => self::VERSION]);
        } catch (\Throwable $e) {
            // Nicht durchreichen: Eine Migration, die scheitert, soll
            // eine sprechende Meldung im Protokoll hinterlassen und
            // nicht die ganze Seite mitreissen.
            Logger::exception($e);
        } finally {
            if ($sperre !== false) {
                if ($habeSperre) {
                    flock($sperre, LOCK_UN);
                }

                fclose($sperre);
            }
        }
    }

    /**
     * Ein Fingerabdruck über die Liste der Migrationen.
     *
     * Absichtlich nicht die handgepflegte VERSION: Wer eine Migration
     * ergänzt und das Hochzählen vergisst, liefert ein Update aus, das
     * auf dem Hosting Tabellen sucht, die es nicht gibt. Genau das ist
     * schon einmal passiert. Ein abgeleiteter Wert lässt sich nicht
     * vergessen.
     */
    private static function fingerprint(): string
    {
        return substr(md5(implode(',', array_keys(self::statements()))), 0, 16);
    }

    public static function migrate(): void
    {
        $pdo = Db::connection();
        $sqlite = Db::isSqlite();

        // Meta-Tabelle zuerst – sie merkt sich den Stand.
        $pdo->exec(self::translate(
            'CREATE TABLE IF NOT EXISTS migrations (
                name {string:191} NOT NULL PRIMARY KEY,
                applied_at {datetime} NOT NULL
            )',
            $sqlite
        ));

        foreach (self::statements() as $name => $sql) {
            $done = Db::value('SELECT 1 FROM migrations WHERE name = :n', ['n' => $name]);
            if ($done) {
                continue;
            }
            foreach (self::split($sql) as $part) {
                self::execute($pdo, self::translate($part, $sqlite));
            }
            Db::insert('migrations', ['name' => $name, 'applied_at' => Db::now()]);
        }

        self::renameOldColumns($pdo, $sqlite);
    }

    /**
     * Spalten, die früher anders hiessen.
     *
     * Das geht nicht als gewöhnliche Migration: Auf einem frischen Stand
     * gibt es die alte Spalte gar nicht, und «unbekannte Spalte» ist
     * kein Fehler, den execute() verschlucken darf – sonst verschluckt
     * es auch echte.
     */
    private static function renameOldColumns(\PDO $pdo, bool $sqlite): void
    {
        // charges.interval → charges.billing_interval.
        //
        // INTERVAL ist in MySQL ein reserviertes Wort. Auf MySQL ist die
        // Tabelle deshalb nie entstanden; auf SQLite schon, und dort
        // steht der alte Name noch.
        if (!self::hasTable($pdo, 'charges') || !self::hasColumn($pdo, 'charges', 'interval', $sqlite)) {
            return;
        }

        self::execute($pdo, $sqlite
            ? 'ALTER TABLE charges RENAME COLUMN "interval" TO billing_interval'
            // CHANGE statt RENAME COLUMN: RENAME COLUMN gibt es erst ab
            // MySQL 8.0, und auf geteiltem Hosting läuft oft noch 5.7.
            : "ALTER TABLE charges CHANGE `interval` billing_interval "
              . "VARCHAR(20) NOT NULL DEFAULT 'einmalig'");
    }

    private static function hasTable(\PDO $pdo, string $table): bool
    {
        try {
            $pdo->query('SELECT 1 FROM ' . Db::quoteIdentifier($table) . ' LIMIT 1');

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    private static function hasColumn(\PDO $pdo, string $table, string $column, bool $sqlite): bool
    {
        try {
            if ($sqlite) {
                $spalten = $pdo->query('PRAGMA table_info(' . Db::quoteIdentifier($table) . ')');

                foreach ($spalten === false ? [] : $spalten->fetchAll(\PDO::FETCH_ASSOC) as $zeile) {
                    if (strcasecmp((string) ($zeile['name'] ?? ''), $column) === 0) {
                        return true;
                    }
                }

                return false;
            }

            $anweisung = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
            );
            $anweisung->execute(['t' => $table, 'c' => $column]);

            return $anweisung->fetchColumn() !== false;
        } catch (\PDOException) {
            return false;
        }
    }

    /** @return array<string,string> */
    private static function statements(): array
    {
        return [
            // ---------------------------------------------------------- Konten
            '001_users' => '
                CREATE TABLE IF NOT EXISTS users (
                    id {id},
                    username {string:64} NOT NULL,
                    password_hash {string:255} NOT NULL,
                    display_name {string:120} NOT NULL DEFAULT \'\',
                    role {string:20} NOT NULL DEFAULT \'owner\',
                    created_at {datetime} NOT NULL,
                    last_login_at {datetime} NULL
                );
                CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username ON users (username);
            ',

            '002_sessions' => '
                CREATE TABLE IF NOT EXISTS auth_sessions (
                    id {string:64} NOT NULL PRIMARY KEY,
                    user_id {int} NOT NULL,
                    ip {string:45} NOT NULL DEFAULT \'\',
                    user_agent {string:255} NOT NULL DEFAULT \'\',
                    created_at {datetime} NOT NULL,
                    last_seen_at {datetime} NOT NULL,
                    expires_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_sessions_user ON auth_sessions (user_id);
                CREATE INDEX IF NOT EXISTS idx_sessions_expires ON auth_sessions (expires_at);
            ',

            '003_login_attempts' => '
                CREATE TABLE IF NOT EXISTS login_attempts (
                    id {id},
                    ip {string:45} NOT NULL,
                    username {string:64} NOT NULL DEFAULT \'\',
                    success {bool} NOT NULL DEFAULT 0,
                    attempted_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_attempts_ip ON login_attempts (ip, attempted_at);
                CREATE INDEX IF NOT EXISTS idx_attempts_user ON login_attempts (username, attempted_at);
            ',

            // ---------------------------------------------------------- Projekte
            '004_projects' => '
                CREATE TABLE IF NOT EXISTS projects (
                    id {id},
                    slug {string:120} NOT NULL,
                    name {string:191} NOT NULL,
                    status {string:24} NOT NULL DEFAULT \'draft\',
                    brief {text} NULL,
                    theme {text} NULL,
                    plan {text} NULL,
                    locale {string:5} NOT NULL DEFAULT \'de\',
                    locales {string:64} NOT NULL DEFAULT \'de\',
                    domain {string:191} NOT NULL DEFAULT \'\',
                    wants_admin {bool} NOT NULL DEFAULT 0,
                    admin_username {string:64} NOT NULL DEFAULT \'\',
                    admin_password_hash {string:255} NOT NULL DEFAULT \'\',
                    assistant_token {string:64} NOT NULL DEFAULT \'\',
                    preview_token {string:64} NOT NULL DEFAULT \'\',
                    preview_expires_at {datetime} NULL,
                    published_at {datetime} NULL,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE UNIQUE INDEX IF NOT EXISTS idx_projects_slug ON projects (slug);
                CREATE INDEX IF NOT EXISTS idx_projects_status ON projects (status);
                CREATE INDEX IF NOT EXISTS idx_projects_preview ON projects (preview_token);
            ',

            '005_project_pages' => '
                CREATE TABLE IF NOT EXISTS project_pages (
                    id {id},
                    project_id {int} NOT NULL,
                    path {string:191} NOT NULL,
                    title {string:191} NOT NULL,
                    meta_description {string:255} NOT NULL DEFAULT \'\',
                    sort_order {int} NOT NULL DEFAULT 0,
                    in_navigation {bool} NOT NULL DEFAULT 1,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_pages_project ON project_pages (project_id, sort_order);
            ',

            '006_project_sections' => '
                CREATE TABLE IF NOT EXISTS project_sections (
                    id {id},
                    project_id {int} NOT NULL,
                    page_id {int} NOT NULL,
                    type {string:40} NOT NULL,
                    template_key {string:60} NOT NULL,
                    content {text} NULL,
                    overrides {text} NULL,
                    hidden {bool} NOT NULL DEFAULT 0,
                    sort_order {int} NOT NULL DEFAULT 0,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_sections_page ON project_sections (page_id, sort_order);
                CREATE INDEX IF NOT EXISTS idx_sections_project ON project_sections (project_id);
            ',

            '007_project_assets' => '
                CREATE TABLE IF NOT EXISTS project_assets (
                    id {id},
                    project_id {int} NOT NULL,
                    kind {string:24} NOT NULL DEFAULT \'image\',
                    path {string:255} NOT NULL,
                    original_name {string:255} NOT NULL DEFAULT \'\',
                    alt {string:255} NOT NULL DEFAULT \'\',
                    width {int} NOT NULL DEFAULT 0,
                    height {int} NOT NULL DEFAULT 0,
                    bytes {int} NOT NULL DEFAULT 0,
                    source_url {string:500} NOT NULL DEFAULT \'\',
                    created_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_assets_project ON project_assets (project_id);
            ',

            '008_section_changes' => '
                CREATE TABLE IF NOT EXISTS section_changes (
                    id {id},
                    project_id {int} NOT NULL,
                    section_id {int} NOT NULL,
                    source {string:24} NOT NULL DEFAULT \'ai\',
                    actor {string:64} NOT NULL DEFAULT \'\',
                    instruction {text} NULL,
                    summary {string:500} NOT NULL DEFAULT \'\',
                    patch {text} NULL,
                    before_state {text} NULL,
                    created_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_changes_section ON section_changes (section_id, id);
                CREATE INDEX IF NOT EXISTS idx_changes_project ON section_changes (project_id, id);
            ',

            // ---------------------------------------------------------- Aufträge
            '009_jobs' => '
                CREATE TABLE IF NOT EXISTS jobs (
                    id {id},
                    project_id {int} NULL,
                    type {string:40} NOT NULL,
                    step {string:40} NOT NULL DEFAULT \'\',
                    status {string:20} NOT NULL DEFAULT \'queued\',
                    progress {int} NOT NULL DEFAULT 0,
                    message {string:255} NOT NULL DEFAULT \'\',
                    payload {text} NULL,
                    state {text} NULL,
                    error {text} NULL,
                    attempts {int} NOT NULL DEFAULT 0,
                    locked_until {datetime} NULL,
                    run_after {datetime} NULL,
                    created_at {datetime} NOT NULL,
                    started_at {datetime} NULL,
                    finished_at {datetime} NULL
                );
                CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs (status, run_after);
                CREATE INDEX IF NOT EXISTS idx_jobs_project ON jobs (project_id, id);
            ',

            '010_ai_calls' => '
                CREATE TABLE IF NOT EXISTS ai_calls (
                    id {id},
                    project_id {int} NULL,
                    job_id {int} NULL,
                    purpose {string:40} NOT NULL DEFAULT \'\',
                    model {string:60} NOT NULL DEFAULT \'\',
                    input_tokens {int} NOT NULL DEFAULT 0,
                    cache_write_tokens {int} NOT NULL DEFAULT 0,
                    cache_read_tokens {int} NOT NULL DEFAULT 0,
                    output_tokens {int} NOT NULL DEFAULT 0,
                    cost_micro {int} NOT NULL DEFAULT 0,
                    duration_ms {int} NOT NULL DEFAULT 0,
                    ok {bool} NOT NULL DEFAULT 1,
                    created_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_ai_project ON ai_calls (project_id);
                CREATE INDEX IF NOT EXISTS idx_ai_created ON ai_calls (created_at);
            ',

            // ---------------------------------------------------------- Auslieferung
            '011_builds' => '
                CREATE TABLE IF NOT EXISTS builds (
                    id {id},
                    project_id {int} NOT NULL,
                    version {int} NOT NULL DEFAULT 1,
                    zip_path {string:255} NOT NULL DEFAULT \'\',
                    zip_bytes {int} NOT NULL DEFAULT 0,
                    files_count {int} NOT NULL DEFAULT 0,
                    notes {string:500} NOT NULL DEFAULT \'\',
                    created_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_builds_project ON builds (project_id, version);
            ',

            '012_deploy_targets' => '
                CREATE TABLE IF NOT EXISTS deploy_targets (
                    id {id},
                    project_id {int} NOT NULL,
                    protocol {string:10} NOT NULL DEFAULT \'ftp\',
                    host {string:191} NOT NULL DEFAULT \'\',
                    port {int} NOT NULL DEFAULT 21,
                    username {string:191} NOT NULL DEFAULT \'\',
                    secret {text} NULL,
                    remote_path {string:255} NOT NULL DEFAULT \'/public_html\',
                    last_result {string:500} NOT NULL DEFAULT \'\',
                    last_deployed_at {datetime} NULL,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_deploy_project ON deploy_targets (project_id);
            ',

            '013_domain_transfers' => '
                CREATE TABLE IF NOT EXISTS domain_transfers (
                    id {id},
                    project_id {int} NOT NULL,
                    domain {string:191} NOT NULL,
                    mode {string:20} NOT NULL DEFAULT \'transfer\',
                    registrar {string:60} NOT NULL DEFAULT \'\',
                    target_ip {string:45} NOT NULL DEFAULT \'\',
                    target_nameservers {string:500} NOT NULL DEFAULT \'\',
                    current_step {int} NOT NULL DEFAULT 0,
                    steps {text} NULL,
                    checks {text} NULL,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_transfer_project ON domain_transfers (project_id);
            ',

            // ---------------------------------------------------------- Website
            '014_leads' => '
                CREATE TABLE IF NOT EXISTS leads (
                    id {id},
                    name {string:191} NOT NULL DEFAULT \'\',
                    email {string:191} NOT NULL DEFAULT \'\',
                    phone {string:60} NOT NULL DEFAULT \'\',
                    company {string:191} NOT NULL DEFAULT \'\',
                    subject {string:191} NOT NULL DEFAULT \'\',
                    message {text} NULL,
                    locale {string:5} NOT NULL DEFAULT \'de\',
                    source {string:60} NOT NULL DEFAULT \'website\',
                    status {string:20} NOT NULL DEFAULT \'new\',
                    ip {string:45} NOT NULL DEFAULT \'\',
                    mail_sent {bool} NOT NULL DEFAULT 0,
                    created_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_leads_status ON leads (status, id);
            ',

            '015_references' => '
                CREATE TABLE IF NOT EXISTS showcase (
                    id {id},
                    project_id {int} NULL,
                    slug {string:120} NOT NULL,
                    title {string:191} NOT NULL,
                    subtitle {string:255} NOT NULL DEFAULT \'\',
                    body_de {text} NULL,
                    body_en {text} NULL,
                    tags {string:255} NOT NULL DEFAULT \'\',
                    live_url {string:255} NOT NULL DEFAULT \'\',
                    preview_path {string:255} NOT NULL DEFAULT \'\',
                    accent_color {string:9} NOT NULL DEFAULT \'\',
                    published {bool} NOT NULL DEFAULT 1,
                    sort_order {int} NOT NULL DEFAULT 0,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE UNIQUE INDEX IF NOT EXISTS idx_showcase_slug ON showcase (slug);
                CREATE INDEX IF NOT EXISTS idx_showcase_pub ON showcase (published, sort_order);
            ',

            '016_settings' => '
                CREATE TABLE IF NOT EXISTS settings (
                    key_name {string:120} NOT NULL PRIMARY KEY,
                    value {text} NULL,
                    updated_at {datetime} NOT NULL
                );
            ',

            '017_audit_log' => '
                CREATE TABLE IF NOT EXISTS audit_log (
                    id {id},
                    user_id {int} NULL,
                    actor {string:64} NOT NULL DEFAULT \'\',
                    action {string:80} NOT NULL,
                    subject {string:191} NOT NULL DEFAULT \'\',
                    meta {text} NULL,
                    ip {string:45} NOT NULL DEFAULT \'\',
                    created_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log (created_at);
                CREATE INDEX IF NOT EXISTS idx_audit_action ON audit_log (action);
            ',

            '018_rate_limits' => '
                CREATE TABLE IF NOT EXISTS rate_limits (
                    bucket {string:191} NOT NULL PRIMARY KEY,
                    hits {int} NOT NULL DEFAULT 0,
                    reset_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_rate_reset ON rate_limits (reset_at);
            ',

            // ---------------------------------------------------- Zweiter Faktor
            '019_second_factor' => '
                ALTER TABLE users ADD COLUMN totp_secret {string:64} NOT NULL DEFAULT \'\';
                ALTER TABLE users ADD COLUMN totp_confirmed_at {datetime} NULL;
                ALTER TABLE users ADD COLUMN recovery_codes {text} NULL;
            ',

            // Ein bestätigtes Gerät muss nicht bei jeder Anmeldung neu fragen.
            '020_trusted_devices' => '
                CREATE TABLE IF NOT EXISTS trusted_devices (
                    id {string:64} NOT NULL PRIMARY KEY,
                    user_id {int} NOT NULL,
                    label {string:120} NOT NULL DEFAULT \'\',
                    ip {string:45} NOT NULL DEFAULT \'\',
                    expires_at {datetime} NOT NULL,
                    created_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_devices_user ON trusted_devices (user_id, expires_at);
            ',

            // ------------------------------------------------------- Fragebogen
            // Der Kunde füllt selbst aus, statt dass alles abgetippt wird.
            '021_questionnaires' => '
                CREATE TABLE IF NOT EXISTS questionnaires (
                    id {id},
                    token {string:64} NOT NULL,
                    company {string:191} NOT NULL DEFAULT \'\',
                    email {string:191} NOT NULL DEFAULT \'\',
                    note {string:500} NOT NULL DEFAULT \'\',
                    answers {text} NULL,
                    status {string:20} NOT NULL DEFAULT \'open\',
                    project_id {int} NULL,
                    opened_at {datetime} NULL,
                    submitted_at {datetime} NULL,
                    expires_at {datetime} NOT NULL,
                    created_at {datetime} NOT NULL
                );
                CREATE UNIQUE INDEX IF NOT EXISTS idx_quest_token ON questionnaires (token);
                CREATE INDEX IF NOT EXISTS idx_quest_status ON questionnaires (status, id);
            ',

            // -------------------------------------------------- Geld und Verträge
            '022_documents' => '
                CREATE TABLE IF NOT EXISTS documents (
                    id {id},
                    project_id {int} NULL,
                    kind {string:20} NOT NULL DEFAULT \'offer\',
                    number {string:40} NOT NULL DEFAULT \'\',
                    title {string:191} NOT NULL DEFAULT \'\',
                    recipient {text} NULL,
                    items {text} NULL,
                    intro {text} NULL,
                    outro {text} NULL,
                    total_rappen {int} NOT NULL DEFAULT 0,
                    vat_percent {int} NOT NULL DEFAULT 0,
                    currency {string:8} NOT NULL DEFAULT \'CHF\',
                    status {string:20} NOT NULL DEFAULT \'draft\',
                    issued_on {string:10} NOT NULL DEFAULT \'\',
                    due_on {string:10} NOT NULL DEFAULT \'\',
                    paid_on {string:10} NOT NULL DEFAULT \'\',
                    file_path {string:255} NOT NULL DEFAULT \'\',
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_docs_kind ON documents (kind, status, id);
                CREATE INDEX IF NOT EXISTS idx_docs_project ON documents (project_id);
            ',

            '023_contracts' => '
                CREATE TABLE IF NOT EXISTS contracts (
                    id {id},
                    project_id {int} NOT NULL,
                    plan {string:40} NOT NULL DEFAULT \'basis\',
                    price_rappen {int} NOT NULL DEFAULT 0,
                    interval_months {int} NOT NULL DEFAULT 12,
                    started_on {string:10} NOT NULL DEFAULT \'\',
                    next_invoice_on {string:10} NOT NULL DEFAULT \'\',
                    cancelled_on {string:10} NOT NULL DEFAULT \'\',
                    note {text} NULL,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_contracts_next ON contracts (next_invoice_on);
                CREATE INDEX IF NOT EXISTS idx_contracts_project ON contracts (project_id);
            ',

            // ----------------------------------------------------- Besucherzahlen
            // Gezählt wird beim Kunden, geholt wird einmal am Tag. Es kommen
            // nur Summen an – nie eine einzelne Person.
            '024_visits' => '
                CREATE TABLE IF NOT EXISTS visits (
                    id {id},
                    project_id {int} NOT NULL,
                    day {string:10} NOT NULL,
                    path {string:191} NOT NULL DEFAULT \'/\',
                    views {int} NOT NULL DEFAULT 0,
                    visitors {int} NOT NULL DEFAULT 0,
                    referrer {string:191} NOT NULL DEFAULT \'\',
                    created_at {datetime} NOT NULL
                );
                CREATE UNIQUE INDEX IF NOT EXISTS idx_visits_key ON visits (project_id, day, path, referrer);
                CREATE INDEX IF NOT EXISTS idx_visits_day ON visits (project_id, day);
            ',

            // --------------------------------------------------------- Support
            '025_support' => '
                CREATE TABLE IF NOT EXISTS support_threads (
                    id {id},
                    project_id {int} NOT NULL,
                    subject {string:191} NOT NULL DEFAULT \'\',
                    status {string:20} NOT NULL DEFAULT \'open\',
                    asker_name {string:120} NOT NULL DEFAULT \'\',
                    asker_email {string:191} NOT NULL DEFAULT \'\',
                    unread_for_owner {bool} NOT NULL DEFAULT 1,
                    unread_for_customer {bool} NOT NULL DEFAULT 0,
                    last_message_at {datetime} NOT NULL,
                    created_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_threads_status ON support_threads (status, last_message_at);
                CREATE INDEX IF NOT EXISTS idx_threads_project ON support_threads (project_id);

                CREATE TABLE IF NOT EXISTS support_messages (
                    id {id},
                    thread_id {int} NOT NULL,
                    author {string:20} NOT NULL DEFAULT \'customer\',
                    body {text} NULL,
                    created_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_messages_thread ON support_messages (thread_id, id);
            ',

            // ------------------------------------------------------- Sicherungen
            '026_backups' => '
                CREATE TABLE IF NOT EXISTS backups (
                    id {id},
                    scope {string:20} NOT NULL DEFAULT \'project\',
                    project_id {int} NULL,
                    path {string:255} NOT NULL DEFAULT \'\',
                    bytes {int} NOT NULL DEFAULT 0,
                    files {int} NOT NULL DEFAULT 0,
                    note {string:255} NOT NULL DEFAULT \'\',
                    created_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_backups_scope ON backups (scope, created_at);
            ',

            // -------------------------------------------------- Mehrsprachigkeit
            // Die Übersetzungen liegen beim Abschnitt, nicht als eigene
            // Seitenbäume: So bleibt eine Änderung am Original genau eine
            // Änderung, und die Sprachen laufen nicht auseinander.
            '028_translations' => '
                ALTER TABLE project_sections ADD COLUMN translations {text} NULL;
                ALTER TABLE project_pages ADD COLUMN translations {text} NULL;
            ',

            // ------------------------------------------------- Prüfung nach dem Bau
            '027_checks' => '
                CREATE TABLE IF NOT EXISTS site_checks (
                    id {id},
                    project_id {int} NOT NULL,
                    kind {string:30} NOT NULL,
                    passed {bool} NOT NULL DEFAULT 0,
                    score {int} NOT NULL DEFAULT 0,
                    findings {text} NULL,
                    created_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_checks_project ON site_checks (project_id, kind);
            ',

            // ------------------------------------------------ Wochenbericht
            // "stats_token" gilt nur für das Abholen der Summen einer
            // einzigen Website. Er steht getrennt vom Assistenten-Schlüssel:
            // Wer ihn hat, sieht Zahlen – ändern kann er damit nichts.
            '029_reports' => '
                ALTER TABLE projects ADD COLUMN stats_token {string:64} NOT NULL DEFAULT \'\';
                ALTER TABLE projects ADD COLUMN report_email {string:191} NOT NULL DEFAULT \'\';
                ALTER TABLE projects ADD COLUMN report_sent_on {string:10} NOT NULL DEFAULT \'\';
                ALTER TABLE projects ADD COLUMN visits_synced_at {datetime} NULL;
            ',

            // ------------------------------------------------- Unternehmen
            //
            // Bis hierher kannte WebAtze nur Projekte. Ein Kunde ist aber
            // mehr als ein Projekt: Er hat wiederkehrende Kosten, offene
            // Aufgaben, Termine, und irgendwann zahlt er - oder eben nicht.
            // Das alles stand bisher auf Zetteln.
            '030_customers' => '
                CREATE TABLE IF NOT EXISTS customers (
                    id {id},
                    name {string:191} NOT NULL,
                    contact_name {string:191} NOT NULL DEFAULT \'\',
                    email {string:191} NOT NULL DEFAULT \'\',
                    phone {string:60} NOT NULL DEFAULT \'\',
                    address {text} NULL,
                    website {string:191} NOT NULL DEFAULT \'\',
                    project_id {int} NULL,
                    employee_id {int} NULL,
                    status {string:20} NOT NULL DEFAULT \'aktiv\',
                    notes {text} NULL,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_customers_status ON customers (status, name);
                CREATE INDEX IF NOT EXISTS idx_customers_project ON customers (project_id);

                CREATE TABLE IF NOT EXISTS employees (
                    id {id},
                    name {string:191} NOT NULL,
                    email {string:191} NOT NULL DEFAULT \'\',
                    phone {string:60} NOT NULL DEFAULT \'\',
                    role {string:120} NOT NULL DEFAULT \'\',
                    hourly_rappen {int} NOT NULL DEFAULT 0,
                    active {bool} NOT NULL DEFAULT 1,
                    notes {text} NULL,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_employees_active ON employees (active, name);
            ',

            // Ein Kostenposten je Zeile: Aufbau, Domain, Wartung, was auch
            // immer. Jeder mit eigenem Betrag und eigenem Rhythmus - das
            // ist der Punkt, an dem eine Tabellenkalkulation aufgibt.
            //
            // Die Spalte heisst billing_interval und nicht interval:
            // INTERVAL ist in MySQL ein reserviertes Wort. SQLite nimmt
            // es an, MySQL bricht die ganze Migration ab - und dann
            // fehlen alle Tabellen ab dieser Stelle.
            '031_charges' => '
                CREATE TABLE IF NOT EXISTS charges (
                    id {id},
                    customer_id {int} NOT NULL,
                    label {string:191} NOT NULL,
                    kind {string:30} NOT NULL DEFAULT \'weiteres\',
                    amount_rappen {int} NOT NULL DEFAULT 0,
                    billing_interval {string:20} NOT NULL DEFAULT \'einmalig\',
                    starts_on {string:10} NOT NULL DEFAULT \'\',
                    ends_on {string:10} NOT NULL DEFAULT \'\',
                    active {bool} NOT NULL DEFAULT 1,
                    note {string:255} NOT NULL DEFAULT \'\',
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_charges_customer ON charges (customer_id, active);

                CREATE TABLE IF NOT EXISTS payments (
                    id {id},
                    customer_id {int} NOT NULL,
                    charge_id {int} NULL,
                    period {string:7} NOT NULL DEFAULT \'\',
                    label {string:191} NOT NULL DEFAULT \'\',
                    amount_rappen {int} NOT NULL DEFAULT 0,
                    paid_on {string:10} NOT NULL DEFAULT \'\',
                    method {string:40} NOT NULL DEFAULT \'\',
                    note {string:255} NOT NULL DEFAULT \'\',
                    created_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_payments_customer ON payments (customer_id, paid_on);
                CREATE INDEX IF NOT EXISTS idx_payments_charge ON payments (charge_id, period);
            ',

            // Rechnungen kannten bisher nur Projekte. Ein Kunde kann aber
            // mehrere Projekte haben - und eine Rechnung fuer Domain und
            // Wartung gehoert zu gar keinem.
            '033_documents_customer' => '
                ALTER TABLE documents ADD COLUMN customer_id {int} NULL;
            ',

            '032_tasks' => '
                CREATE TABLE IF NOT EXISTS todos (
                    id {id},
                    customer_id {int} NULL,
                    employee_id {int} NULL,
                    title {string:255} NOT NULL,
                    note {text} NULL,
                    due_on {string:10} NOT NULL DEFAULT \'\',
                    priority {string:10} NOT NULL DEFAULT \'normal\',
                    done_at {datetime} NULL,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_todos_customer ON todos (customer_id, done_at);
                CREATE INDEX IF NOT EXISTS idx_todos_due ON todos (due_on);

                CREATE TABLE IF NOT EXISTS appointments (
                    id {id},
                    customer_id {int} NULL,
                    employee_id {int} NULL,
                    title {string:255} NOT NULL,
                    note {text} NULL,
                    starts_at {string:16} NOT NULL DEFAULT \'\',
                    ends_at {string:16} NOT NULL DEFAULT \'\',
                    place {string:191} NOT NULL DEFAULT \'\',
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_appointments_start ON appointments (starts_at);
                CREATE INDEX IF NOT EXISTS idx_appointments_customer ON appointments (customer_id);

                CREATE TABLE IF NOT EXISTS expenses (
                    id {id},
                    label {string:191} NOT NULL,
                    category {string:60} NOT NULL DEFAULT \'sonstiges\',
                    amount_rappen {int} NOT NULL DEFAULT 0,
                    spent_on {string:10} NOT NULL DEFAULT \'\',
                    recurring {string:20} NOT NULL DEFAULT \'einmalig\',
                    note {string:255} NOT NULL DEFAULT \'\',
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_expenses_date ON expenses (spent_on);
            ',

            // ------------------------------------------------ Kundensuche
            //
            // Eine Firma, die noch kein Kunde ist, ist etwas anderes als
            // eine Anfrage: Sie hat sich nicht gemeldet. Deshalb eine
            // eigene Tabelle - mit dem Recherchetext, damit beim Anrufen
            // alles beisammen liegt, und mit 'nie', damit eine einmal
            // weggeschobene Firma nie wieder auftaucht.
            '034_prospects' => '
                CREATE TABLE IF NOT EXISTS prospects (
                    id {id},
                    name {string:191} NOT NULL,
                    branch {string:120} NOT NULL DEFAULT \'\',
                    place {string:120} NOT NULL DEFAULT \'\',
                    website {string:191} NOT NULL DEFAULT \'\',
                    email {string:191} NOT NULL DEFAULT \'\',
                    phone {string:60} NOT NULL DEFAULT \'\',
                    address {string:255} NOT NULL DEFAULT \'\',
                    contact_name {string:191} NOT NULL DEFAULT \'\',
                    site_state {string:30} NOT NULL DEFAULT \'\',
                    reason {text} NULL,
                    research {text} NULL,
                    score {int} NOT NULL DEFAULT 0,
                    source {string:20} NOT NULL DEFAULT \'auftrag\',
                    status {string:20} NOT NULL DEFAULT \'neu\',
                    note {text} NULL,
                    customer_id {int} NULL,
                    decided_at {datetime} NULL,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_prospects_status ON prospects (status, id);
                CREATE INDEX IF NOT EXISTS idx_prospects_name ON prospects (name);
            ',

            // ------------------------------------------- Hosting-Aufsicht
            //
            // Der Verlauf steht getrennt, weil er wachst und der Zustand
            // nicht. Wer wissen will, ob eine Seite lauft, will eine
            // Zeile lesen und nicht zehntausend.
            '035_monitors' => '
                CREATE TABLE IF NOT EXISTS monitors (
                    id {id},
                    customer_id {int} NULL,
                    project_id {int} NULL,
                    label {string:191} NOT NULL DEFAULT \'\',
                    url {string:255} NOT NULL,
                    expect {string:191} NOT NULL DEFAULT \'\',
                    active {bool} NOT NULL DEFAULT 1,
                    every_minutes {int} NOT NULL DEFAULT 15,
                    last_checked_at {datetime} NULL,
                    last_ok {bool} NOT NULL DEFAULT 1,
                    last_code {int} NOT NULL DEFAULT 0,
                    last_ms {int} NOT NULL DEFAULT 0,
                    last_note {string:255} NOT NULL DEFAULT \'\',
                    fail_streak {int} NOT NULL DEFAULT 0,
                    down_since {datetime} NULL,
                    cert_expires_on {string:10} NOT NULL DEFAULT \'\',
                    notify {bool} NOT NULL DEFAULT 1,
                    notified_at {datetime} NULL,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_monitors_active ON monitors (active, last_checked_at);
                CREATE INDEX IF NOT EXISTS idx_monitors_customer ON monitors (customer_id);

                CREATE TABLE IF NOT EXISTS monitor_checks (
                    id {id},
                    monitor_id {int} NOT NULL,
                    checked_at {datetime} NOT NULL,
                    ok {bool} NOT NULL DEFAULT 1,
                    code {int} NOT NULL DEFAULT 0,
                    ms {int} NOT NULL DEFAULT 0,
                    note {string:255} NOT NULL DEFAULT \'\'
                );
                CREATE INDEX IF NOT EXISTS idx_checks_monitor ON monitor_checks (monitor_id, checked_at);
            ',

            // ------------------------------------------------- Websites
            //
            // Bis hierher kannte WebAtze nur selbst gebaute Websites. Ein
            // Kunde hat aber oft schon eine - von jemand anderem gemacht,
            // in WordPress, irgendwo gehostet. Auch die will betreut,
            // ueberwacht und in Rechnung gestellt werden.
            //
            // Deshalb dieselbe Tabelle statt einer zweiten: Wer alle
            // seine Websites sehen will, will eine Liste und nicht zwei.
            // 'source' sagt, woher sie kommt.
            '037_projects_customer' => '
                ALTER TABLE projects ADD COLUMN customer_id {int} NULL;
                ALTER TABLE projects ADD COLUMN source {string:10} NOT NULL DEFAULT \'ki\';
                ALTER TABLE projects ADD COLUMN platform {string:60} NOT NULL DEFAULT \'\';
                ALTER TABLE projects ADD COLUMN hosting {string:120} NOT NULL DEFAULT \'\';
                ALTER TABLE projects ADD COLUMN notes {text} NULL;
                ALTER TABLE projects ADD COLUMN live_since {string:10} NOT NULL DEFAULT \'\';
                CREATE INDEX IF NOT EXISTS idx_projects_customer ON projects (customer_id);
                CREATE INDEX IF NOT EXISTS idx_projects_source ON projects (source);
            ',

            // -------------------------------------------------- Der Tresor
            //
            // Das Passwort steht ausschliesslich verschlusselt in
            // secret_enc. Der Schlussel dazu liegt in config.php, nicht in
            // der Datenbank - eine gestohlene Datenbanksicherung allein
            // gibt nichts her.
            '036_secrets' => '
                CREATE TABLE IF NOT EXISTS secrets (
                    id {id},
                    customer_id {int} NULL,
                    label {string:191} NOT NULL,
                    kind {string:30} NOT NULL DEFAULT \'weiteres\',
                    username {string:191} NOT NULL DEFAULT \'\',
                    secret_enc {text} NULL,
                    url {string:255} NOT NULL DEFAULT \'\',
                    note {text} NULL,
                    opened_at {datetime} NULL,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_secrets_customer ON secrets (customer_id, label);
            ',

            // ------------------------------------------------- Zugangscode /support
            //
            // Die Hilfeseite einer Kundenwebsite steht offen im Netz. Wer
            // sie findet, kann schreiben - und schreibt dann mir. Ein
            // kurzer Code davor macht daraus wieder einen Draht zwischen
            // zwei Leuten statt eines Briefkastens fuer jedermann.
            '038_projects_support_code' => '
                ALTER TABLE projects ADD COLUMN support_code {string:32} NOT NULL DEFAULT \'\';
            ',

            // ----------------------------------------------------------- Intranet
            //
            // Kurze Notizen, die nur der angemeldete Benutzer sieht. Kein
            // Kunde, kein Besucher, keine Schnittstelle. Deshalb steht hier
            // auch keine Sichtbarkeit: Was in dieser Tabelle liegt, ist
            // ausnahmslos intern.
            '039_notes' => '
                CREATE TABLE IF NOT EXISTS notes (
                    id {id},
                    title {string:191} NOT NULL,
                    body {text} NULL,
                    tag {string:40} NOT NULL DEFAULT \'\',
                    pinned {bool} NOT NULL DEFAULT 0,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_notes_pinned ON notes (pinned, updated_at);
                CREATE INDEX IF NOT EXISTS idx_notes_tag ON notes (tag);
            ',

            // ------------------------------------------------------- Besucher
            //
            // Zwei Ergaenzungen zur bestehenden Zaehlung:
            //
            // "visit_seen" haelt fest, welche Kennung an einem Tag schon
            // gezaehlt wurde - sonst waeren Aufrufe und Besucher dieselbe
            // Zahl. Die Kennung entsteht aus einem Salz, das jede Nacht
            // wechselt; rueckwaerts rechnen kann damit niemand, und am
            // naechsten Tag ist dieselbe Person eine andere Kennung.
            //
            // "visit_key" auf den Projekten ist der Schluessel, mit dem
            // sich eine Website beim Zaehler ausweist. Er darf oeffentlich
            // sein: Mehr als "zaehl einen Aufruf" laesst sich damit nicht
            // anstellen.
            '040_visits_own' => '
                CREATE TABLE IF NOT EXISTS visit_seen (
                    id {id},
                    project_id {int} NOT NULL,
                    day {string:10} NOT NULL,
                    fingerprint {string:64} NOT NULL,
                    created_at {datetime} NOT NULL
                );
                CREATE UNIQUE INDEX IF NOT EXISTS idx_visit_seen_key
                    ON visit_seen (project_id, day, fingerprint);
                ALTER TABLE projects ADD COLUMN visit_key {string:32} NOT NULL DEFAULT \'\';
                CREATE INDEX IF NOT EXISTS idx_projects_visit_key ON projects (visit_key);
            ',

            // ------------------------------------------------ Schluessel /support
            //
            // Ein eigener Schluessel nur fuer die Hilfeseite, getrennt vom
            // assistant_token. Der Grund ist der Auftragstext: Damit auf
            // der Kundenwebsite nichts von Hand eingerichtet werden muss,
            // steht der Schluessel darin - und der Text wird kopiert und
            // weitergereicht.
            //
            // Mit dem assistant_token liesse sich auch der Abschnitts-
            // Editor ansteuern, und der kostet bei jedem Aufruf Geld.
            // Dieser hier kann genau zweierlei: eine Supportnachricht
            // senden und den eigenen Gespraechsfaden lesen. Mehr nicht,
            // und zuruecknehmen laesst er sich jederzeit.
            '041_projects_support_token' => '
                ALTER TABLE projects ADD COLUMN support_token {string:64} NOT NULL DEFAULT \'\';
                CREATE INDEX IF NOT EXISTS idx_projects_support_token ON projects (support_token);
            ',

            // ------------------------------------- Rechnung trifft Buchhaltung
            //
            // Eine bezahlte Rechnung soll in der Buchhaltung stehen -
            // sonst zeigt die Statistik einen Gewinn, den es nicht gibt.
            // Die Verknuepfung geht in beide Richtungen: Wird die
            // Rechnung wieder auf offen gestellt oder geloescht,
            // verschwindet die Einnahme mit ihr.
            '042_payments_document' => '
                ALTER TABLE payments ADD COLUMN document_id {int} NULL;
                CREATE INDEX IF NOT EXISTS idx_payments_document ON payments (document_id);
            ',

            // ------------------------------------------------------ Frontend-Fix
            //
            // Anpassungen an der eigenen Website, per Textbefehl erzeugt.
            //
            // Gespeichert wird der Befehl UND das Ergebnis. Der Befehl,
            // damit spaeter nachvollziehbar ist, warum eine Regel da
            // steht; das Ergebnis, weil es sonst bei jedem Seitenaufruf
            // neu erfragt werden muesste - und das kostet Geld und
            // Sekunden.
            //
            // Jede Anpassung laesst sich einzeln abschalten. Das ist der
            // eigentliche Sicherheitsgurt: Was schiefgeht, ist mit einem
            // Klick wieder weg, ohne dass jemand eine Datei anfassen muss.
            '043_frontend_fixes' => '
                CREATE TABLE IF NOT EXISTS frontend_fixes (
                    id {id},
                    prompt {text} NULL,
                    css {text} NULL,
                    summary {string:255} NOT NULL DEFAULT \'\',
                    scope {string:60} NOT NULL DEFAULT \'\',
                    active {bool} NOT NULL DEFAULT 1,
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_fixes_active ON frontend_fixes (active, id);
            ',

            // ------------------------------------------------- Der Editor
            //
            // Vier Dinge, die der Editor braucht und die es bisher nicht
            // gab.
            //
            // effects: Hintergrund, Bewegung und Parallaxe eines
            // Abschnitts. Getrennt von overrides, weil overrides Masse
            // sind (Spalten, Abstand) und effects Verhalten. Wer beides
            // in eine Spalte legt, kann spaeter nicht mehr sagen, was
            // beim Umschalten der Vorlage mitgehen soll.
            //
            // style_pack: welches Stilpaket eine Website traegt. 'basis'
            // ist der Grundstil, den jede Kundenwebsite bekommt.
            //
            // chrome: die Seitenkulisse - 3D-Buehne, Aura, Raster. Sie
            // gehoert zur Seite, nicht zu einem Abschnitt, denn sie
            // liegt hinter allen.
            //
            // route_key: der Schluessel aus I18n::routeMap(). Eine
            // Kundenwebsite besteht aus Dateien und braucht ihn nicht;
            // die eigene wird geroutet und ohne ihn waeren /leistungen
            // und /en/services zwei verschiedene Seiten.
            '044_editor_felder' => '
                ALTER TABLE project_sections ADD COLUMN effects {text} NULL;
                ALTER TABLE projects ADD COLUMN style_pack {string:40} NOT NULL DEFAULT \'basis\';
                ALTER TABLE projects ADD COLUMN chrome {text} NULL;
                ALTER TABLE project_pages ADD COLUMN route_key {string:40} NOT NULL DEFAULT \'\';
                ALTER TABLE project_pages ADD COLUMN layout {string:40} NOT NULL DEFAULT \'flach\';
            ',

            // ----------------------------------- Entwurf und Veroeffentlicht
            //
            // Gearbeitet wird am Entwurf: Das sind die Zeilen in
            // project_sections. Veroeffentlicht wird eine Abschrift
            // davon - und die bleibt liegen.
            //
            // Zwei Dinge werden dadurch moeglich, die vorher nicht
            // gingen: "Entwurf verwerfen" (zurueck auf die letzte
            // Veroeffentlichung) und "auf die Fassung von Dienstag
            // zurueck". Beides braucht man erst, wenn man es braucht -
            // und dann sofort.
            '045_veroeffentlichungen' => '
                CREATE TABLE IF NOT EXISTS publications (
                    id {id},
                    project_id {int} NOT NULL,
                    page_id {int} NOT NULL,
                    data {text} NULL,
                    note {string:191} NOT NULL DEFAULT \'\',
                    actor {string:120} NOT NULL DEFAULT \'\',
                    created_at {datetime} NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_publications_page
                    ON publications (page_id, id);
                CREATE INDEX IF NOT EXISTS idx_publications_project
                    ON publications (project_id, id);
            ',

            // -------------------------------------------------- Die Bruecke
            //
            // Der Schluessel, mit dem WebAtze auf dem Kundenserver
            // veroeffentlichen darf. Er steht auf beiden Servern und
            // geht nie ueber die Leitung - unterschrieben wird damit
            // nur.
            //
            // bridge_seen haelt fest, wann die Bruecke zuletzt geantwortet
            // hat und welchen Stand der Kunde hat. Ohne das wuerde jede
            // Veroeffentlichung blind losschicken.
            '046_bruecke' => '
                ALTER TABLE projects ADD COLUMN bridge_secret {string:191} NOT NULL DEFAULT \'\';
                ALTER TABLE projects ADD COLUMN bridge_seen {datetime} NULL;
                ALTER TABLE projects ADD COLUMN bridge_state {string:64} NOT NULL DEFAULT \'\';
                ALTER TABLE projects ADD COLUMN editor_version {string:20} NOT NULL DEFAULT \'\';
            ',

            // ------------------------------------------ Alles am Kunden
            //
            // Das Menue schrumpft von 22 Punkten auf 6, und was
            // verschwindet, muss vom Kunden aus erreichbar sein. Drei
            // Tabellen hingen bisher nur am Projekt: Ein Vertrag ohne
            // Website hatte gar keinen Kunden, und ein Kunde ohne
            // Website hatte keine Vertraege - obwohl beides vorkommt.
            //
            // Befuellt wird aus projects.customer_id. Das ist die
            // Richtung, die gewinnt: customers.project_id kann nur eine
            // Website halten, projects.customer_id beliebig viele.
            '047_alles_am_kunden' => '
                ALTER TABLE contracts ADD COLUMN customer_id {int} NULL;
                ALTER TABLE support_threads ADD COLUMN customer_id {int} NULL;
                ALTER TABLE questionnaires ADD COLUMN customer_id {int} NULL;

                UPDATE contracts SET customer_id = (
                    SELECT p.customer_id FROM projects p WHERE p.id = contracts.project_id
                ) WHERE customer_id IS NULL;

                UPDATE support_threads SET customer_id = (
                    SELECT p.customer_id FROM projects p WHERE p.id = support_threads.project_id
                ) WHERE customer_id IS NULL;

                UPDATE questionnaires SET customer_id = (
                    SELECT p.customer_id FROM projects p WHERE p.id = questionnaires.project_id
                ) WHERE customer_id IS NULL;

                CREATE INDEX IF NOT EXISTS idx_contracts_customer ON contracts (customer_id);
                CREATE INDEX IF NOT EXISTS idx_threads_customer ON support_threads (customer_id);
                CREATE INDEX IF NOT EXISTS idx_questionnaires_customer ON questionnaires (customer_id);
            ',

            // ------------------------------------------ Ein Hosting-Zugang
            //
            // Alle Websites liegen auf demselben Konto: Server,
            // Benutzername und Passwort sind jedes Mal dieselben, nur
            // das Verzeichnis unterscheidet sich. Bisher wurde das je
            // Website eingetippt und je Website verschluesselt
            // abgelegt - und beim naechsten Passwortwechsel musste es
            // ueberall neu eingetragen werden.
            //
            // deploy_targets bleibt, wie es ist. Steht kein Zugang
            // daran, gilt weiterhin, was dort selbst hinterlegt ist -
            // bestehende Websites merken von dieser Aenderung nichts.
            '048_hosting_zugaenge' => '
                CREATE TABLE IF NOT EXISTS hosting_accounts (
                    id {id},
                    name {string:120} NOT NULL DEFAULT \'\',
                    provider {string:40} NOT NULL DEFAULT \'godaddy\',
                    protocol {string:10} NOT NULL DEFAULT \'ftp\',
                    host {string:191} NOT NULL DEFAULT \'\',
                    port {int} NOT NULL DEFAULT 21,
                    username {string:191} NOT NULL DEFAULT \'\',
                    secret {text} NULL,
                    note {string:500} NOT NULL DEFAULT \'\',
                    created_at {datetime} NOT NULL,
                    updated_at {datetime} NOT NULL
                );

                ALTER TABLE deploy_targets ADD COLUMN hosting_account_id {int} NULL;
                CREATE INDEX IF NOT EXISTS idx_deploy_account ON deploy_targets (hosting_account_id);
            ',

            // ----------------------------------------------------------
            // 049: der Schluessel des Empfaengers
            //
            // Eigener Schluessel je Website, getrennt von dem der
            // Bruecke: Wer die Empfangsdatei auf der Kundenwebsite
            // liest, haette sonst auch die Bruecke offen.
            // ----------------------------------------------------------
            '049_empfang_secret' => '
                ALTER TABLE projects ADD COLUMN empfang_secret {string:64} NOT NULL DEFAULT \'\';
            ',
        ];
    }

    /** Mini-Platzhalter in echten SQL-Typ übersetzen. */
    private static function translate(string $sql, bool $sqlite): string
    {
        $map = $sqlite
            ? [
                '{id}' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                '{int}' => 'INTEGER',
                '{bool}' => 'INTEGER',
                '{text}' => 'TEXT',
                '{datetime}' => 'TEXT',
            ]
            : [
                '{id}' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
                '{int}' => 'INT',
                '{bool}' => 'TINYINT(1)',
                '{text}' => 'MEDIUMTEXT',
                '{datetime}' => 'DATETIME',
        ];

        $sql = strtr($sql, $map);

        // {string:191} -> VARCHAR(191) bzw. TEXT bei SQLite
        $sql = preg_replace_callback(
            '/\{string:(\d+)\}/',
            static fn (array $m): string => $sqlite ? 'TEXT' : 'VARCHAR(' . $m[1] . ')',
            $sql
        ) ?? $sql;

        $sql = rtrim(trim($sql), ';');

        if (!$sqlite) {
            // Nur Tabellen bekommen die Speicher-Angabe, Indizes nicht.
            if (stripos($sql, 'CREATE TABLE') === 0) {
                $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            }
            // MySQL (anders als MariaDB) kennt "IF NOT EXISTS" bei Indizes nicht.
            // Doppelte Anlagen fängt execute() ohnehin ab.
            if (stripos($sql, 'CREATE INDEX') === 0 || stripos($sql, 'CREATE UNIQUE INDEX') === 0) {
                $sql = str_ireplace(' IF NOT EXISTS ', ' ', $sql);
            }
        }

        return trim($sql);
    }

    /**
     * Anweisung ausführen und "existiert schon" als Erfolg werten.
     *
     * Migrationen laufen nur einmal, aber wenn eine Tabelle von Hand angelegt
     * wurde oder ein früherer Durchlauf mittendrin abbrach, soll das Nachholen
     * nicht am bereits erledigten Teil scheitern.
     */
    private static function execute(\PDO $pdo, string $sql): void
    {
        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            $message = strtolower($e->getMessage());
            $harmless = str_contains($message, 'already exists')
                || str_contains($message, 'duplicate key name')
                || str_contains($message, 'duplicate column');
            if (!$harmless) {
                throw $e;
            }
        }
    }

    /** Mehrere Anweisungen in einem Block trennen. */
    private static function split(string $sql): array
    {
        $parts = array_map('trim', explode(';', $sql));
        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }
}
