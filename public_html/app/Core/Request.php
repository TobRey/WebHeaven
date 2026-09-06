<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Die eingehende Anfrage – gebündelt und entschärft.
 *
 * Grundsatz: Rohdaten aus $_GET/$_POST werden nirgends direkt benutzt.
 * Alles läuft über die Methoden hier, die auf Typ und Form prüfen.
 */
final class Request
{
    private array $query;
    private array $body;
    private array $server;
    private array $files;
    private array $cookies;
    private string $path;
    private ?array $json = null;
    /** @var array<string,string> */
    private array $routeParams = [];

    public function __construct(
        array $query = [],
        array $body = [],
        array $server = [],
        array $files = [],
        array $cookies = []
    ) {
        $this->query = $query;
        $this->body = $body;
        $this->server = $server;
        $this->files = $files;
        $this->cookies = $cookies;
        $this->path = $this->resolvePath();
    }

    public static function capture(): self
    {
        return new self($_GET, $_POST, $_SERVER, $_FILES, $_COOKIE);
    }

    // ------------------------------------------------------------------
    // Grunddaten
    // ------------------------------------------------------------------

    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /** Pfad ohne Parameter, immer mit führendem Schrägstrich, ohne Schrägstrich am Ende. */
    public function path(): string
    {
        return $this->path;
    }

    public function isSecure(): bool
    {
        if (($this->server['HTTPS'] ?? '') !== '' && $this->server['HTTPS'] !== 'off') {
            return true;
        }
        // Hinter dem Proxy/Loadbalancer von cPanel
        if (($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        return ((int) ($this->server['SERVER_PORT'] ?? 80)) === 443;
    }

    public function host(): string
    {
        $host = (string) ($this->server['HTTP_HOST'] ?? $this->server['SERVER_NAME'] ?? 'localhost');
        // Nur erlaubte Zeichen – schützt vor Host-Header-Tricks
        return preg_replace('/[^A-Za-z0-9.\-:]/', '', $host) ?: 'localhost';
    }

    public function baseUrl(): string
    {
        $configured = rtrim((string) Config::get('app_url', ''), '/');
        if ($configured !== '') {
            return $configured;
        }
        return ($this->isSecure() ? 'https://' : 'http://') . $this->host();
    }

    public function ip(): string
    {
        // REMOTE_ADDR ist der einzige Wert, den ein Besucher nicht selbst setzen kann.
        $ip = (string) ($this->server['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 250);
    }

    public function header(string $name, string $default = ''): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($this->server[$key] ?? $default);
    }

    public function wantsJson(): bool
    {
        return str_contains($this->header('Accept'), 'application/json')
            || str_contains($this->header('Content-Type'), 'application/json')
            || $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    // ------------------------------------------------------------------
    // Werte lesen
    // ------------------------------------------------------------------

    /** Wert aus POST, sonst GET. Immer als bereinigter Text. */
    public function input(string $key, string $default = ''): string
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? $this->jsonValue($key) ?? $default;
        return is_scalar($value) ? $this->clean((string) $value) : $default;
    }

    public function query(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;
        return is_scalar($value) ? $this->clean((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $raw = $this->body[$key] ?? $this->query[$key] ?? $this->jsonValue($key);
        if ($raw === null || !is_scalar($raw) || !is_numeric((string) $raw)) {
            return $default;
        }
        return (int) $raw;
    }

    public function bool(string $key): bool
    {
        $raw = $this->body[$key] ?? $this->query[$key] ?? $this->jsonValue($key);
        if (is_bool($raw)) {
            return $raw;
        }
        return in_array(strtolower((string) $raw), ['1', 'true', 'on', 'ja', 'yes'], true);
    }

    /** Mehrzeiliger Text (Textarea). Zeilenumbrüche bleiben erhalten. */
    public function text(string $key, int $maxLength = 20000): string
    {
        $value = $this->body[$key] ?? $this->jsonValue($key) ?? '';
        if (!is_scalar($value)) {
            return '';
        }
        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $value = preg_replace('/[^\P{C}\n\t]/u', '', $value) ?? '';
        return mb_substr(trim($value), 0, $maxLength);
    }

    /**
     * Ein Wert, so wie er ankam - ungefiltert.
     *
     * Der einzige Leser, der nichts wegnimmt, und deshalb mit Bedacht zu
     * benutzen: Wer ihn nimmt, ist selbst dafür zuständig, dass das
     * Ergebnis nirgends als HTML ausgegeben wird.
     *
     * Es gibt ihn, weil `input()` bei 2000 Zeichen abschneidet und jeden
     * Zeilenumbruch entfernt, und `text()` bei 20 000. Für ein Formular
     * ist das richtig. Für eine ganze HTML-Seite ist es die stille
     * Zerstörung: Sie käme gekürzt an, und die Längenprüfung dahinter
     * sähe eine Zahl, die schon nicht mehr stimmt. Genau so ist es beim
     * Speichern einer Kundenseite passiert - die Datei stand danach in
     * einer einzigen Zeile.
     *
     * Die Grenze steht deshalb hier vorn, in Bytes, und schneidet nicht
     * ab: Was zu gross ist, gibt einen leeren Text, damit der Aufrufer
     * es merkt.
     */
    public function roh(string $key, int $maxBytes = 65536): string
    {
        $value = $this->body[$key] ?? $this->jsonValue($key) ?? '';

        if (!is_scalar($value)) {
            return '';
        }

        $value = (string) $value;

        return strlen($value) > $maxBytes ? '' : $value;
    }

    /** Liste von Werten, z.B. mehrere Checkboxen. */
    public function arrayOf(string $key): array
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? $this->jsonValue($key) ?? [];
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $item) {
            if (is_scalar($item)) {
                $out[$k] = $this->clean((string) $item);
            } elseif (is_array($item)) {
                $out[$k] = $item;
            }
        }
        return $out;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body)
            || array_key_exists($key, $this->query)
            || $this->jsonValue($key) !== null;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body, $this->json ?? []);
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $file;
    }

    public function cookie(string $key, string $default = ''): string
    {
        $value = $this->cookies[$key] ?? $default;
        return is_scalar($value) ? (string) $value : $default;
    }

    /** JSON-Rumpf einer API-Anfrage. */
    public function jsonBody(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '' || strlen($raw) > 2_000_000) {
            return $this->json = [];
        }
        $decoded = json_decode($raw, true);
        return $this->json = is_array($decoded) ? $decoded : [];
    }

    // ------------------------------------------------------------------
    // Route-Parameter (aus /projekt/{id})
    // ------------------------------------------------------------------

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function param(string $key, string $default = ''): string
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function paramInt(string $key, int $default = 0): int
    {
        $value = $this->routeParams[$key] ?? null;
        return is_numeric($value) ? (int) $value : $default;
    }

    // ------------------------------------------------------------------

    private function jsonValue(string $key): mixed
    {
        if ($this->json === null && str_contains($this->header('Content-Type'), 'application/json')) {
            $this->jsonBody();
        }
        return $this->json[$key] ?? null;
    }

    /** Steuerzeichen entfernen, Länge begrenzen, Ränder trimmen. */
    private function clean(string $value, int $maxLength = 2000): string
    {
        $value = preg_replace('/[^\P{C}\t]/u', '', $value) ?? '';
        return mb_substr(trim($value), 0, $maxLength);
    }

    private function resolvePath(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';

        // Läuft die Seite in einem Unterordner? Den Teil abschneiden.
        $scriptDir = str_replace('\\', '/', dirname((string) ($this->server['SCRIPT_NAME'] ?? '/index.php')));
        if ($scriptDir !== '/' && $scriptDir !== '.' && str_starts_with($path, $scriptDir)) {
            $path = substr($path, strlen($scriptDir));
        }

        // Kein Ausbrechen aus dem Pfad
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? '/';
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
