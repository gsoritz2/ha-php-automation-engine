<?php
declare(strict_types=1);

/**
 * Minimaler, nicht-blockierender HTTP-Server im Engine-Prozess.
 * Vorteil: Status, Logs, Reload und externe Trigger teilen sich den Speicher
 * mit der Automation-Registry - keine IPC-Dateien, keine zweite Instanz.
 *
 * Routen:
 *   GET  /health              -> 200 ok            (fuer den Add-on-Watchdog)
 *   GET  /                    -> Web-UI            (Ingress-Panel)
 *   GET  /api/status          -> JSON-Status
 *   GET  /api/log?lines=100   -> JSON-Logzeilen
 *   POST /api/reload          -> Skripte neu laden
 *   POST /api/trigger?name=x  -> benannten Trigger ausloesen
 *   POST /api/run?script=x.php-> Skript einmalig testen
 */
final class HttpServer
{
    /** @var resource|null */
    private $srv = null;
    private int $port;
    private float $startedAt;

    public function __construct(int $port)
    {
        $this->port = $port;
        $this->startedAt = microtime(true);
        $addr = "tcp://0.0.0.0:$port";
        $errno = 0;
        $errstr = '';
        $srv = @stream_socket_server($addr, $errno, $errstr);
        if ($srv === false) {
            throw new RuntimeException("HTTP-Server auf $addr nicht moeglich: $errstr ($errno)");
        }
        stream_set_blocking($srv, false);
        $this->srv = $srv;
        Log::add('info', "HTTP-Server laeuft auf $addr (Ingress/Status/Webhooks)");
    }

    public function port(): int
    {
        return $this->port;
    }

    /** Bis zu $max Verbindungen pro Tick bedienen. */
    public function tick(int $max = 8): void
    {
        if ($this->srv === null) {
            return;
        }
        for ($i = 0; $i < $max; $i++) {
            $conn = @stream_socket_accept($this->srv, 0);
            if ($conn === false) {
                return;
            }
            $this->handle($conn);
        }
    }

    /** @param resource $conn */
    private function handle($conn): void
    {
        $deadline = microtime(true) + 2.0;
        $head = '';
        while (!str_contains($head, "\r\n\r\n")) {
            $chunk = @fread($conn, 4096);
            if ($chunk === false || $chunk === '') {
                if (feof($conn) || microtime(true) > $deadline) {
                    fclose($conn);
                    return;
                }
                usleep(2000);
                continue;
            }
            $head .= $chunk;
            if (strlen($head) > 65536) {
                break;
            }
        }

        // Achtung: der erste fread() kann bereits Body-Bytes enthalten -> sauber trennen
        $sep = strpos($head, "\r\n\r\n");
        $headerPart = $sep === false ? $head : substr($head, 0, $sep);
        $bodyPrefix = $sep === false ? '' : substr($head, $sep + 4);

        $lines = explode("\r\n", $headerPart);
        $requestLine = array_shift($lines) ?? '';
        $method = 'GET';
        $target = '/';
        if (preg_match('#^([A-Z]+)\s+(\S+)#', $requestLine, $m) === 1) {
            $method = $m[1];
            $target = $m[2];
        }
        $headers = [];
        foreach ($lines as $line) {
            $p = strpos($line, ':');
            if ($p !== false) {
                $headers[strtolower(substr($line, 0, $p))] = trim(substr($line, $p + 1));
            }
        }

        $body = '';
        $len = (int) ($headers['content-length'] ?? 0);
        if ($len > 0 && $len < 1048576) {
            $read = $bodyPrefix;
            while (strlen($read) < $len && microtime(true) < $deadline) {
                $c = @fread($conn, $len - strlen($read));
                if ($c === false || $c === '') {
                    usleep(2000);
                    continue;
                }
                $read .= $c;
            }
            $body = substr($read, 0, $len);
        }

        $path = parse_url($target, PHP_URL_PATH) ?: '/';
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        try {
            $this->route($conn, $method, $path, $query, $body, $headers);
        } catch (Throwable $e) {
            Log::add('error', 'HTTP-Handler warf: ' . $e->getMessage());
            $this->respond($conn, 500, 'application/json', json_encode(['ok' => false, 'error' => $e->getMessage()]));
        }
        fclose($conn);
    }

    private function route($conn, string $method, string $path, array $query, string $body, array $headers): void
    {
        $jsonBody = [];
        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $jsonBody = $decoded;
            }
        }
        $param = static function (string $key, mixed $default = null) use ($query, $jsonBody): mixed {
            return $jsonBody[$key] ?? $query[$key] ?? $default;
        };

        switch ($path) {
            case '/health':
                $this->respond($conn, 200, 'text/plain', 'ok');
                return;

            case '/':
            case '/index.html':
                $status = Engine::status(80);
                $html = phpengine_ui($status);
                $this->respond($conn, 200, 'text/html; charset=utf-8', $html);
                return;

            case '/api/status':
                $this->respond($conn, 200, 'application/json', json_encode(Engine::status((int) $param('lines', 60)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                return;

            case '/api/log':
                $this->respond($conn, 200, 'application/json', json_encode(['log' => Log::tail((int) $param('lines', 100))], JSON_UNESCAPED_UNICODE));
                return;

            case '/api/scripts':
                $this->respond($conn, 200, 'application/json', json_encode(Engine::scanScripts(), JSON_PRETTY_PRINT));
                return;

            case '/api/reload':
                if ($method !== 'POST') {
                    $this->respond($conn, 405, 'application/json', json_encode(['ok' => false, 'error' => 'POST erwartet']));
                    return;
                }
                Engine::$reloads++;
                Engine::loadAll();
                $this->respond($conn, 200, 'application/json', json_encode(['ok' => true, 'reloads' => Engine::$reloads]));
                return;

            case '/api/trigger':
                if ($method !== 'POST') {
                    $this->respond($conn, 405, 'application/json', json_encode(['ok' => false, 'error' => 'POST erwartet']));
                    return;
                }
                $name = (string) $param('name', '');
                if ($name === '') {
                    $this->respond($conn, 400, 'application/json', json_encode(['ok' => false, 'error' => 'Parameter name fehlt']));
                    return;
                }
                $ok = Engine::fireNamed($name);
                $this->respond($conn, $ok ? 200 : 404, 'application/json', json_encode(['ok' => $ok, 'name' => $name]));
                return;

            case '/api/run':
                if ($method !== 'POST') {
                    $this->respond($conn, 405, 'application/json', json_encode(['ok' => false, 'error' => 'POST erwartet']));
                    return;
                }
                $script = (string) $param('script', '');
                if ($script === '') {
                    $this->respond($conn, 400, 'application/json', json_encode(['ok' => false, 'error' => 'Parameter script fehlt']));
                    return;
                }
                if (!str_contains($script, '/')) {
                    $script = Engine::$scriptDir . '/' . $script;
                }
                if (!str_starts_with($script, Engine::$scriptDir)) {
                    $this->respond($conn, 400, 'application/json', json_encode(['ok' => false, 'error' => 'Pfad ausserhalb des Skriptverzeichnisses']));
                    return;
                }
                $this->respond($conn, 200, 'application/json', json_encode(Engine::runOnce($script), JSON_PRETTY_PRINT));
                return;

            default:
                $this->respond($conn, 404, 'application/json', json_encode(['ok' => false, 'error' => "Unbekannter Pfad $path"]));
        }
    }

    private function respond($conn, int $code, string $contentType, string $body): void
    {
        $reason = [200 => 'OK', 400 => 'Bad Request', 404 => 'Not Found', 405 => 'Method Not Allowed', 500 => 'Internal Server Error'][$code] ?? 'OK';
        $head = "HTTP/1.1 $code $reason\r\n"
            . "Content-Type: $contentType\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Cache-Control: no-store\r\n"
            . "Connection: close\r\n\r\n";
        @fwrite($conn, $head . $body);
    }
}
