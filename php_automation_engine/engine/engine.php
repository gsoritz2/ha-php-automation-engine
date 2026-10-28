<?php
declare(strict_types=1);

/**
 * PHP Automation Engine - Hauptprozess.
 *
 * Ein Prozess, drei Aufgaben:
 *   1. Automations-Skripte aus dem Skriptverzeichnis laden (und bei Aenderung neu laden)
 *   2. HA-Zustaende pollen und Trigger/Timer ausfuehren
 *   3. HTTP-Endpunkt fuer Ingress-UI, Watchdog und Webhooks bedienen
 *
 * Konfiguration kommt aus Umgebungsvariablen (vom run.sh aus /data/options.json gesetzt).
 * Fuer lokale Tests reichen dieselben Variablen - es braucht keinen Supervisor.
 */

require __DIR__ . '/lib/log.php';
require __DIR__ . '/lib/ha.php';
require __DIR__ . '/lib/cron.php';
require __DIR__ . '/lib/api.php';
require __DIR__ . '/lib/http.php';
require __DIR__ . '/www/index.php';

/** Fallback, wenn BUILD_VERSION nicht durchgereicht wurde. */
const ENGINE_VERSION = 'dev';

function env_or(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

function env_bool(string $key, bool $default): bool
{
    $v = env_or($key);
    if ($v === null) {
        return $default;
    }
    return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
}

$config = [
    'version' => env_or('PHPENGINE_VERSION', ENGINE_VERSION) ?? ENGINE_VERSION,
    'script_dir' => env_or('PHPENGINE_SCRIPT_DIR', '/share/php_automations'),
    'ko_map_file' => null,
    'var_file' => env_or('PHPENGINE_VAR_FILE', '/data/state.json'),
    'auto_reload' => env_bool('PHPENGINE_AUTO_RELOAD', true),
    'poll_interval' => (float) (env_or('PHPENGINE_POLL_INTERVAL', '2.0') ?? '2.0'),
    'log_level' => env_or('PHPENGINE_LOG_LEVEL', 'info'),
    'log_lines' => (int) (env_or('PHPENGINE_LOG_LINES', '500') ?? '500'),
    'log_file' => env_or('PHPENGINE_LOG', '/data/engine.log'),
    'http_port' => (int) (env_or('PHPENGINE_HTTP_PORT', '8099') ?? '8099'),
];
$koMapName = env_or('PHPENGINE_KO_MAP', 'ko_map.json');
$config['ko_map_file'] = str_contains((string) $koMapName, '/')
    ? (string) $koMapName
    : rtrim((string) $config['script_dir'], '/') . '/' . $koMapName;

if ($tz = env_or('TZ')) {
    date_default_timezone_set($tz);
}
$config['poll_interval'] = max(0.2, min(60.0, (float) $config['poll_interval']));

$logDir = dirname((string) $config['log_file']);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
Log::init((string) $config['log_level'], (string) $config['log_file'], (int) $config['log_lines']);

Log::add('info', '=== PHP Automation Engine ' . ENGINE_VERSION . ' startet ===');
Log::add('info', 'PHP ' . PHP_VERSION . ' | TZ ' . date_default_timezone_get() . ' | Skripte: ' . $config['script_dir'] . ' | Poll: ' . $config['poll_interval'] . 's');

$api = new HaApi();
Engine::boot($api, $config);

try {
    Engine::$http = new HttpServer((int) $config['http_port']);
} catch (Throwable $e) {
    Log::add('warning', 'HTTP-Server nicht verfuegbar (Status/UI deaktiviert): ' . $e->getMessage());
    Engine::$http = null;
}

// Konnektivitaetstest (blockiert den Start nicht)
try {
    $cfg = $api->config();
    Log::add('info', 'HA verbunden: ' . ($cfg['location_name'] ?? '?') . ' / ' . ($cfg['version'] ?? '?') . ' / ' . ($cfg['time_zone'] ?? '?'));
} catch (Throwable $e) {
    Log::add('error', 'HA-API nicht erreichbar: ' . $e->getMessage());
}

Engine::loadAll(true);
Engine::poll(false); // Erstbefuellung ohne Trigger-Feuer

$lastPoll = 0.0;
$lastShutdownCheck = 0.0;

// Endlosschleife: ein Durchlauf erledigt alles Nicht-Blockierende.
// Kein sleep(2) - die 100 ms Taktung unten hält Timer/Trigger reaktionsschnell.
while (true) {
    $loopStart = microtime(true);

    try {
        // 1) HA-Zustände abfragen und State-Trigger auslösen (nur im Poll-Intervall)
        if (($loopStart - $lastPoll) >= (float) $config['poll_interval']) {
            $lastPoll = $loopStart;
            Engine::poll(true);              // true = Änderungen dürfen Trigger feuern
        }
        // 2) fällige Timer (every/cron/at) ausführen
        Engine::runTimers();
        // 3) HTTP-Anfragen bedienen (Ingress-UI, /health-Watchdog, Webhooks)
        if (Engine::$http !== null) {
            Engine::$http->tick();
        }
        // 4) geänderte Skriptdateien erkennen und neu laden
        Engine::maybeReload();
        // 5) persistente Skript-Variablen höchstens 1×/s auf Platte schreiben
        Engine::saveVars();
    } catch (Throwable $e) {
        Engine::$errors++;
        Engine::$lastError = $e->getMessage();
        Log::add('error', 'Engine-Loop: ' . get_class($e) . ': ' . $e->getMessage());
    }

    Engine::$lastLoop = microtime(true);
    $elapsed = Engine::$lastLoop - $loopStart;
    $sleep = 0.1 - $elapsed;
    if ($sleep > 0) {
        usleep((int) ($sleep * 1_000_000));
    }
}
