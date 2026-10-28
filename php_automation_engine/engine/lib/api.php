<?php
declare(strict_types=1);

/**
 * Zentrale Registry + die globale Funktions-API, die Automations-Skripte benutzen.
 *
 * Aufbau analog zu pyscript/AppDaemon, plus eine Edomi-Schicht (ko_get/ko_set),
 * damit bestehende Logikbausteine mechanisch portiert werden koennen.
 */
final class Engine
{
    public const PER_ENTITY_LIMIT = 25;

    public static HaApi $api;
    public static ?HttpServer $http = null;

    /** @var array<string,array<string,mixed>|null> */
    public static array $states = [];
    /** @var array<string,array<int,array<string,mixed>>> */
    public static array $stateTriggers = [];
    /** @var array<int,array<string,mixed>> */
    public static array $timers = [];
    /** @var array<string,array<string,mixed>> */
    public static array $namedTriggers = [];
    /** @var array<int,array<string,mixed>> */
    public static array $startCallbacks = [];
    /** @var array<string,int> Datei => mtime */
    public static array $scripts = [];
    /** @var array<string,string> Datei => Fehlermeldung */
    public static array $scriptErrors = [];
    /** @var array<string,mixed> persistente Skript-Variablen */
    public static array $vars = [];
    /** @var array<string,mixed> */
    public static array $koMap = [];

    public static string $scriptDir = '/share/php_automations';
    public static string $koMapFile = '/share/php_automations/ko_map.json';
    public static string $varFile = '/data/state.json';
    public static string $currentScript = '';
    public static bool $autoReload = true;
    public static bool $varsDirty = false;
    public static float $varsSavedAt = 0.0;

    public static float $startedAt = 0.0;
    public static float $lastPoll = 0.0;
    public static float $lastLoop = 0.0;
    public static int $pollCount = 0;
    public static int $fires = 0;
    public static int $errors = 0;
    public static int $reloads = 0;
    public static ?string $lastError = null;
    public static string $version = '0.0.0';

    // ------------------------------------------------------------------ Setup

    public static function boot(HaApi $api, array $config): void
    {
        self::$api = $api;
        self::$scriptDir = rtrim((string) ($config['script_dir'] ?? self::$scriptDir), '/');
        self::$koMapFile = (string) ($config['ko_map_file'] ?? (self::$scriptDir . '/ko_map.json'));
        self::$varFile = (string) ($config['var_file'] ?? '/data/state.json');
        self::$autoReload = (bool) ($config['auto_reload'] ?? true);
        self::$version = (string) ($config['version'] ?? '0.0.0');
        self::$startedAt = microtime(true);
        self::loadVars();
        self::loadKoMap();
    }

    public static function loadVars(): void
    {
        if (is_readable(self::$varFile)) {
            $d = json_decode((string) @file_get_contents(self::$varFile), true);
            if (is_array($d)) {
                self::$vars = $d;
            }
        }
    }

    public static function saveVars(bool $force = false): void
    {
        if (!self::$varsDirty && !$force) {
            return;
        }
        if (!$force && (microtime(true) - self::$varsSavedAt) < 1.0) {
            return;
        }
        @file_put_contents(self::$varFile, json_encode(self::$vars, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        self::$varsDirty = false;
        self::$varsSavedAt = microtime(true);
    }

    public static function loadKoMap(): void
    {
        self::$koMap = [];
        if (!is_readable(self::$koMapFile)) {
            return;
        }
        $d = json_decode((string) @file_get_contents(self::$koMapFile), true);
        if (is_array($d)) {
            self::$koMap = $d;
            Log::add('info', sprintf('KO-Map geladen: %d Eintraege aus %s', count($d), self::$koMapFile));
        } else {
            Log::add('error', 'KO-Map ' . self::$koMapFile . ' ist kein gueltiges JSON.');
        }
    }

    /** @return array<string,mixed>|null */
    public static function koEntry(int|string $nr): ?array
    {
        $key = (string) $nr;
        $e = self::$koMap[$key] ?? null;
        return is_array($e) ? $e : null;
    }

    // ------------------------------------------------------------ Skript-Laden

    /** @return array<string,int> */
    public static function scanScripts(): array
    {
        $out = [];
        if (!is_dir(self::$scriptDir)) {
            @mkdir(self::$scriptDir, 0775, true);
        }
        foreach (glob(self::$scriptDir . '/*.php') ?: [] as $f) {
            $out[$f] = (int) @filemtime($f);
        }
        ksort($out);
        return $out;
    }

    public static function loadScript(string $file): void
    {
        // Skripte werden bei jedem Reload erneut eingebunden: eine zweite Top-Level-
        // Deklaration (function/class/const) waere ein Fatalfehler und wuerde die
        // Engine beenden. Deshalb vorab pruefen und sauber ablehnen.
        $src = @file_get_contents($file);
        if (is_string($src) && preg_match('/^[\t ]*(function|class|interface|trait|enum|const)\s/i', $src, $m) === 1) {
            $msg = "Top-Level-Deklaration '" . $m[1] . "' nicht erlaubt - Inhalt in eine Closure kapseln (Skripte werden bei jedem Reload erneut eingebunden)";
            self::$scriptErrors[$file] = $msg;
            self::$errors++;
            self::$lastError = basename($file) . ': ' . $msg;
            Log::add('error', sprintf('Skript %s wird nicht geladen: %s', basename($file), $msg));
            return;
        }

        $prev = self::$currentScript;
        self::$currentScript = $file;
        try {
            $loader = static function (string $__phpengine_file): void {
                include $__phpengine_file;
            };
            $loader($file);
            unset(self::$scriptErrors[$file]);
        } catch (Throwable $e) {
            self::$scriptErrors[$file] = $e->getMessage();
            self::$errors++;
            self::$lastError = basename($file) . ': ' . $e->getMessage();
            Log::add('error', sprintf('Skript %s: %s (%s:%d)', basename($file), $e->getMessage(), basename($e->getFile()), $e->getLine()));
        } finally {
            self::$currentScript = $prev;
        }
    }

    public static function loadAll(bool $initial = false): void
    {
        self::$stateTriggers = [];
        self::$timers = [];
        self::$namedTriggers = [];
        self::$startCallbacks = [];
        self::$scripts = self::scanScripts();

        foreach (array_keys(self::$scripts) as $file) {
            self::loadScript($file);
        }
        self::loadKoMap();

        $count = count(self::$scripts);
        Log::add('info', sprintf(
            '%d Skript(e) geladen: %d State-Trigger, %d Timer, %d benannte Trigger%s',
            $count,
            array_sum(array_map('count', self::$stateTriggers)),
            count(self::$timers),
            count(self::$namedTriggers),
            $initial ? '' : ' (Reload)'
        ));

        foreach (self::$startCallbacks as $s) {
            self::invoke((string) $s['script'], 'on_start', $s['cb'], []);
        }
    }

    public static function maybeReload(): void
    {
        if (!self::$autoReload) {
            return;
        }
        $now = self::scanScripts();
        if ($now === self::$scripts) {
            return;
        }
        $added = array_diff_key($now, self::$scripts);
        $removed = array_diff_key(self::$scripts, $now);
        $changed = [];
        foreach ($now as $f => $m) {
            if (isset(self::$scripts[$f]) && self::$scripts[$f] !== $m) {
                $changed[] = basename($f);
            }
        }
        Log::add('info', sprintf(
            'Skript-Aenderung erkannt (neu: %d, geaendert: %s, entfernt: %d) - lade neu',
            count($added),
            $changed === [] ? '-' : implode(', ', $changed),
            count($removed)
        ));
        self::$reloads++;
        self::loadAll();
    }

    // ------------------------------------------------------------------ Polling

    public static function poll(bool $fire = true): void
    {
        $entities = array_keys(self::$stateTriggers);
        $new = [];
        if ($entities !== []) {
            if (count($entities) <= self::PER_ENTITY_LIMIT) {
                foreach ($entities as $e) {
                    $s = self::$api->getState($e, false);
                    if (is_array($s)) {
                        $new[$e] = $s;
                    }
                }
            } else {
                $wanted = array_flip($entities);
                foreach (self::$api->getStates() as $s) {
                    $id = $s['entity_id'] ?? null;
                    if (is_string($id) && isset($wanted[$id])) {
                        $new[$id] = $s;
                    }
                }
            }
        }

        self::$pollCount++;
        self::$lastPoll = microtime(true);

        foreach ($entities as $e) {
            $old = self::$states[$e] ?? null;
            $cur = $new[$e] ?? null;
            self::$states[$e] = $cur;

            if (!$fire || $cur === null) {
                continue;
            }
            if ($old === null) {
                continue; // Erstbefuellung: kein Trigger-Feuer beim Start
            }
            $newState = (string) ($cur['state'] ?? '');
            $oldState = (string) ($old['state'] ?? '');
            $newAttrs = $cur['attributes'] ?? [];
            $oldAttrs = $old['attributes'] ?? [];
            if ($newState === $oldState && $newAttrs == $oldAttrs) {
                continue;
            }
            foreach (self::$stateTriggers[$e] ?? [] as $idx => $t) {
                if (!self::condMatches($t['cond'] ?? null, $newState, $oldState, $e, $newAttrs, $oldAttrs)) {
                    continue;
                }
                self::$stateTriggers[$e][$idx]['fires'] = (int) ($t['fires'] ?? 0) + 1;
                self::$stateTriggers[$e][$idx]['last'] = time();
                self::invoke((string) ($t['script'] ?? '?'), "state_trigger($e)", $t['cb'], [$newState, $oldState, $e]);
            }
        }
    }

    /** @return array<string,mixed> */
    public static function stateValue(string $entity): array
    {
        $s = self::$states[$entity] ?? null;
        return is_array($s) ? $s : [];
    }

    public static function condMatches(mixed $cond, string $new, string $old, string $entity, array $newAttrs = [], array $oldAttrs = []): bool
    {
        if ($cond === null || $cond === '' || $cond === 'changed') {
            return true;
        }
        if (is_callable($cond)) {
            try {
                return (bool) $cond($new, $old, $entity);
            } catch (Throwable $e) {
                Log::add('error', "Bedingung fuer $entity warf: " . $e->getMessage());
                return false;
            }
        }
        if (is_array($cond)) {
            foreach ($cond as $op => $val) {
                if (!self::condMatches("$op:" . (is_bool($val) ? ($val ? 'true' : 'false') : (string) $val), $new, $old, $entity, $newAttrs, $oldAttrs)) {
                    return false;
                }
            }
            return true;
        }
        if (!is_string($cond)) {
            return true;
        }

        $spec = trim($cond);
        $parts = explode(':', $spec, 2);
        $op = strtolower($parts[0]);
        $val = $parts[1] ?? '';
        if ($op === '' || $op === 'changed') {
            return true;
        }
        if (str_starts_with($op, '>')) {
            $val = ltrim($op, '>');
            $op = 'above';
        } elseif (str_starts_with($op, '<')) {
            $val = ltrim($op, '<');
            $op = 'below';
        } elseif (str_starts_with($op, '=')) {
            $val = substr($op, 1);
            $op = 'eq';
        }

        $num = is_numeric($new) ? (float) $new : null;
        switch ($op) {
            case 'above':
                return $num !== null && $num > (float) $val;
            case 'below':
                return $num !== null && $num < (float) $val;
            case 'eq':
            case 'to':
                return $new === $val || (is_numeric($val) && $num !== null && $num === (float) $val);
            case 'not':
            case 'from_not':
                return $new !== $val;
            case 'from':
                return $old === $val;
            case 'in':
                return in_array($new, array_map('trim', explode(',', $val)), true);
            case 'attr':
                [$attrName, $attrVal] = array_pad(explode('=', $val, 2), 2, '');
                return isset($newAttrs[trim($attrName)]) && (string) $newAttrs[trim($attrName)] === trim($attrVal);
            default:
                Log::add('warning', "Unbekannte Trigger-Bedingung '$spec' - werte als erfuellt");
                return true;
        }
    }

    // ------------------------------------------------------------------- Timer

    public static function runTimers(): void
    {
        $now = microtime(true);
        foreach (self::$timers as $i => $t) {
            if ($t['next'] > $now) {
                continue;
            }
            $guard = 0;
            while (self::$timers[$i]['next'] <= $now && $guard++ < 100) {
                self::$timers[$i]['fires'] = (int) ($t['fires'] ?? 0) + 1;
                self::$timers[$i]['last'] = time();
                self::invoke((string) ($t['script'] ?? '?'), 'timer(' . ($t['label'] ?? $t['type']) . ')', $t['cb'], []);
                if (($t['type'] ?? '') === 'interval') {
                    self::$timers[$i]['next'] = self::$timers[$i]['next'] + (float) $t['seconds'];
                } else {
                    /** @var CronExpr $cron */
                    $cron = $t['cron'];
                    $next = $cron->next(new DateTimeImmutable('@' . (int) self::$timers[$i]['next']));
                    self::$timers[$i]['next'] = (float) $next->getTimestamp();
                }
                $t = self::$timers[$i];
            }
        }
    }

    public static function parseDuration(mixed $spec): float
    {
        if (is_numeric($spec)) {
            return (float) $spec;
        }
        $s = strtolower(trim((string) $spec));
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(s|sec|sek|m|min|h|d)?$/', $s, $m) !== 1) {
            throw new InvalidArgumentException("Ungueltige Dauer '$spec' (erlaubt: 30, 30s, 5m, 2h, 1d)");
        }
        $v = (float) $m[1];
        return match ($m[2] ?? 's') {
            'm', 'min' => $v * 60,
            'h' => $v * 3600,
            'd' => $v * 86400,
            default => $v,
        };
    }

    // ------------------------------------------------------------------ Aufrufe

    /** @param array<int,mixed> $args */
    public static function invoke(string $script, string $what, callable $cb, array $args): void
    {
        try {
            $cb(...$args);
        } catch (Throwable $e) {
            self::$errors++;
            self::$lastError = "$what: " . $e->getMessage();
            Log::add('error', sprintf('%s in %s warf %s: %s (%s:%d)', $what, basename($script), get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine()));
        }
    }

    public static function fireNamed(string $name): bool
    {
        $t = self::$namedTriggers[$name] ?? null;
        if ($t === null) {
            return false;
        }
        self::$namedTriggers[$name]['fires'] = (int) ($t['fires'] ?? 0) + 1;
        self::$namedTriggers[$name]['last'] = time();
        self::invoke((string) ($t['script'] ?? '?'), "trigger($name)", $t['cb'], []);
        return true;
    }

    /** Skript einmalig ausfuehren, ohne Trigger/Timer dauerhaft zu registrieren. */
    public static function runOnce(string $file): array
    {
        if (!is_file($file)) {
            return ['ok' => false, 'error' => "Datei nicht gefunden: $file"];
        }
        $snap = [self::$stateTriggers, self::$timers, self::$namedTriggers, self::$startCallbacks, self::$currentScript];
        [self::$stateTriggers, self::$timers, self::$namedTriggers, self::$startCallbacks] = [[], [], [], []];
        self::loadScript($file);
        $info = [
            'ok' => !isset(self::$scriptErrors[$file]),
            'error' => self::$scriptErrors[$file] ?? null,
            'registered' => [
                'state_triggers' => array_sum(array_map('count', self::$stateTriggers)),
                'timers' => count(self::$timers),
                'named_triggers' => count(self::$namedTriggers),
            ],
        ];
        foreach (self::$startCallbacks as $s) {
            self::invoke($file, 'on_start', $s['cb'], []);
        }
        [self::$stateTriggers, self::$timers, self::$namedTriggers, self::$startCallbacks, self::$currentScript] = $snap;
        return $info;
    }

    // ------------------------------------------------------------------ Status

    /** @return array<string,mixed> */
    public static function status(int $logLines = 60): array
    {
        $triggers = [];
        foreach (self::$stateTriggers as $entity => $list) {
            foreach ($list as $t) {
                $triggers[] = [
                    'entity' => $entity,
                    'state' => self::stateValue($entity)['state'] ?? null,
                    'condition' => is_string($t['cond'] ?? null) ? $t['cond'] : ($t['cond'] === null ? 'changed' : 'callable'),
                    'fires' => (int) ($t['fires'] ?? 0),
                    'last' => (int) ($t['last'] ?? 0),
                    'script' => basename((string) ($t['script'] ?? '?')),
                ];
            }
        }
        $timers = [];
        foreach (self::$timers as $t) {
            $timers[] = [
                'label' => $t['label'] ?? $t['type'],
                'type' => $t['type'],
                'next' => (int) $t['next'],
                'next_in' => max(0, (int) $t['next'] - time()),
                'fires' => (int) ($t['fires'] ?? 0),
                'script' => basename((string) ($t['script'] ?? '?')),
            ];
        }
        $named = [];
        foreach (self::$namedTriggers as $name => $t) {
            $named[] = [
                'name' => $name,
                'fires' => (int) ($t['fires'] ?? 0),
                'script' => basename((string) ($t['script'] ?? '?')),
            ];
        }
        $scripts = [];
        foreach (self::$scripts as $file => $mtime) {
            $scripts[] = [
                'file' => basename($file),
                'path' => $file,
                'mtime' => $mtime,
                'age' => max(0, time() - $mtime),
                'error' => self::$scriptErrors[$file] ?? null,
            ];
        }

        return [
            'addon' => [
                'name' => 'PHP Automation Engine',
                'version' => self::$version,
                'php' => PHP_VERSION,
                'api_base' => self::$api->base(),
                'timezone' => date_default_timezone_get(),
                'uptime' => (int) (microtime(true) - self::$startedAt),
                'started' => date('c', (int) self::$startedAt),
            ],
            'config' => [
                'script_dir' => self::$scriptDir,
                'auto_reload' => self::$autoReload,
                'ko_map_file' => self::$koMapFile,
                'ko_map_entries' => count(self::$koMap),
                'var_file' => self::$varFile,
            ],
            'stats' => [
                'poll_count' => self::$pollCount,
                'last_poll_ago' => self::$lastPoll > 0 ? round(microtime(true) - self::$lastPoll, 1) : null,
                'fires' => self::$fires,
                'errors' => self::$errors,
                'reloads' => self::$reloads,
                'subscribed_entities' => count(self::$stateTriggers),
                'cached_states' => count(self::$states),
                'script_errors' => self::$scriptErrors,
                'last_error' => self::$lastError,
            ],
            'scripts' => $scripts,
            'state_triggers' => $triggers,
            'timers' => $timers,
            'named_triggers' => $named,
            'log' => Log::tail($logLines),
        ];
    }
}

// ======================================================================
//  Globale API fuer Automations-Skripte
// ======================================================================

function ha(): HaApi
{
    return Engine::$api;
}

/**
 * Zustand (state) einer Entity; optional ein Attribut.
 *
 * Wichtig: Engine::$states wird nur fuer *abonnierte* Entities (state_trigger)
 * aktuell gehalten. Fuer alle anderen Entities wird bei jedem Aufruf die API
 * gefragt (mit 1s-TTL-Cache in HaApi) - sonst wuerde ein einmal gelesener Wert
 * fuer immer als aktuell gelten.
 */
function ha_state(string $entity, ?string $attribute = null, mixed $default = null): mixed
{
    $s = isset(Engine::$stateTriggers[$entity]) ? Engine::stateValue($entity) : [];
    if ($s === []) {
        $s = Engine::$api->getState($entity);
        if ($s === null) {
            return $default;
        }
    }
    if ($attribute === null) {
        return $s['state'] ?? $default;
    }
    return $s['attributes'][$attribute] ?? $default;
}

function ha_state_float(string $entity, float $default = 0.0): float
{
    $v = ha_state($entity);
    return is_numeric($v) ? (float) $v : $default;
}

function ha_state_bool(string $entity, bool $default = false): bool
{
    $v = ha_state($entity);
    if (is_bool($v)) {
        return $v;
    }
    if (is_string($v)) {
        return in_array(strtolower($v), ['on', 'true', '1', 'yes', 'open', 'home', 'heat', 'playing'], true);
    }
    return $default;
}

/** Alle Entities einer Domain (oder alle, wenn $domain null). */
function ha_states(?string $domain = null): array
{
    $all = Engine::$api->getStates();
    if ($domain === null) {
        return $all;
    }
    return array_values(array_filter($all, static fn(array $s): bool => str_starts_with((string) ($s['entity_id'] ?? ''), $domain . '.')));
}

/**
 * Service aufrufen. Entweder
 *   ha_call('light', 'turn_on', ['brightness' => 200], ['entity_id' => 'light.kueche'])
 * oder direkt mit entity_id im data-Array.
 */
function ha_call(string $domain, string $service, array $data = [], array $target = []): mixed
{
    $r = Engine::$api->callService($domain, $service, $data, $target);
    Log::add('debug', "Service $domain.$service " . json_encode($data + $target, JSON_UNESCAPED_UNICODE));
    return $r;
}

/** Kurzform: ha_call_entity('light.turn_on', 'light.kueche', ['brightness'=>200]) */
function ha_call_entity(string $serviceString, string $entityId, array $data = []): mixed
{
    [$domain, $service] = array_pad(explode('.', $serviceString, 2), 2, '');
    return ha_call($domain, $service, $data, ['entity_id' => $entityId]);
}

/** Pseudo-State setzen (wie ein Sensor- oder input_text-Wert). */
function ha_set_state(string $entityId, mixed $state, array $attributes = []): mixed
{
    return Engine::$api->setState($entityId, $state, $attributes);
}

function ha_event(string $eventType, array $data = []): mixed
{
    return Engine::$api->fireEvent($eventType, $data);
}

function ha_template(string $template): ?string
{
    return Engine::$api->renderTemplate($template);
}

function ha_notify(string $message, ?string $title = null, string $service = 'persistent_notification.create'): void
{
    [$domain, $svc] = array_pad(explode('.', $service, 2), 2, 'create');
    $data = ['message' => $message];
    if ($title !== null) {
        $data['title'] = $title;
    }
    ha_call($domain, $svc, $data);
}

function log_trace(string $msg): void
{
    Log::add('trace', $msg);
}
function log_debug(string $msg): void
{
    Log::add('debug', $msg);
}
function log_info(string $msg): void
{
    Log::add('info', $msg);
}
function log_warn(string $msg): void
{
    Log::add('warning', $msg);
}
function log_error(string $msg): void
{
    Log::add('error', $msg);
}

/**
 * State-Trigger.
 *   state_trigger('binary_sensor.tuer', fn($new,$old,$e) => ..., 'eq:on');
 * Bedingungen: null/'changed', 'eq:x', 'not:x', 'from:x', 'to:x',
 * 'above:20', 'below:5', 'in:a,b,c', 'attr:brightness=255', oder Callable($new,$old,$entity).
 */
function state_trigger(string|array $entities, callable $callback, mixed $condition = null, array $opts = []): void
{
    foreach ((array) $entities as $e) {
        Engine::$stateTriggers[$e][] = [
            'cb' => $callback,
            'cond' => $condition,
            'opts' => $opts,
            'fires' => 0,
            'last' => 0,
            'script' => Engine::$currentScript,
        ];
    }
}

/** Alle $seconds / '30s' / '5m' / '2h' / '1d' ausfuehren. */
function every(mixed $spec, callable $callback, string $label = ''): void
{
    $seconds = Engine::parseDuration($spec);
    Engine::$timers[] = [
        'type' => 'interval',
        'cb' => $callback,
        'seconds' => $seconds,
        'next' => microtime(true) + $seconds,
        'fires' => 0,
        'label' => $label !== '' ? $label : ('every ' . $spec),
        'script' => Engine::$currentScript,
    ];
}

/** Cron-Ausdruck (5 Felder): cron('0 7 * * *', fn() => ...) */
function cron(string $expr, callable $callback, string $label = ''): void
{
    $c = new CronExpr($expr);
    $next = $c->next(new DateTimeImmutable('now'));
    Engine::$timers[] = [
        'type' => 'cron',
        'cb' => $callback,
        'cron' => $c,
        'next' => (float) $next->getTimestamp(),
        'fires' => 0,
        'label' => $label !== '' ? $label : ('cron ' . $expr),
        'script' => Engine::$currentScript,
    ];
}

/** Taeglich um HH:MM. */
function at(string $hhmm, callable $callback): void
{
    if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m) !== 1) {
        throw new InvalidArgumentException("at() erwartet HH:MM, bekam '$hhmm'");
    }
    cron(sprintf('%d %d * * *', (int) $m[2], (int) $m[1]), $callback, "at $hhmm");
}

/** Wird nach jedem Laden/Reload der Skripte einmal ausgefuehrt. */
function on_start(callable $callback): void
{
    Engine::$startCallbacks[] = ['cb' => $callback, 'script' => Engine::$currentScript];
}

/** Von aussen (HTTP /api/trigger?name=...) ausloesbarer Trigger. */
function engine_trigger(string $name, callable $callback): void
{
    Engine::$namedTriggers[$name] = ['cb' => $callback, 'fires' => 0, 'last' => 0, 'script' => Engine::$currentScript];
}

/** Persistente Skript-Variablen (ueberleben Reload und Neustart). */
function state_get(string $key, mixed $default = null): mixed
{
    return Engine::$vars[$key] ?? $default;
}

function state_set(string $key, mixed $value): void
{
    Engine::$vars[$key] = $value;
    Engine::$varsDirty = true;
}

// ------------------------------------------------------- Edomi-Kompatibilitaet

/** KO-Wert lesen (Mapping aus ko_map.json: KO-Nummer -> Entity). */
function ko_get(int|string $nr, mixed $default = null): mixed
{
    $m = Engine::koEntry($nr);
    if ($m === null) {
        Log::add('warning', "ko_get($nr): keine Zuordnung in " . Engine::$koMapFile);
        return $default;
    }
    $state = ha_state((string) $m['entity'], $m['attribute'] ?? null);
    if ($state === null) {
        return $default;
    }
    if (isset($m['map']) && is_array($m['map'])) {
        $key = is_bool($state) ? ($state ? 'true' : 'false') : (string) $state;
        if (array_key_exists($key, $m['map'])) {
            return $m['map'][$key];
        }
    }
    return match ($m['type'] ?? 'raw') {
        'bool' => in_array(strtolower((string) $state), ['on', 'true', '1', 'open', 'home'], true),
        'float', 'int' => is_numeric($state) ? (float) $state : $default,
        default => $state,
    };
}

function ko_bool(int|string $nr, bool $default = false): bool
{
    $v = ko_get($nr, $default);
    return is_bool($v) ? $v : (bool) $v;
}

/**
 * KO setzen. Reihenfolge:
 *  1. service_on / service_off (bei bool) bzw. service_set
 *  2. service (immer)
 *  3. sonst: Pseudo-State auf der Entity setzen
 */
function ko_set(int|string $nr, mixed $value): bool
{
    $m = Engine::koEntry($nr);
    if ($m === null) {
        Log::add('warning', "ko_set($nr): keine Zuordnung in " . Engine::$koMapFile);
        return false;
    }
    $entity = (string) $m['entity'];
    $data = is_array($m['data'] ?? null) ? $m['data'] : [];

    try {
        $asBool = is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1';
        if ($asBool) {
            $on = (bool) (is_bool($value) ? $value : (int) $value === 1);
            $svc = $on ? ($m['service_on'] ?? null) : ($m['service_off'] ?? null);
            if (is_string($svc) && $svc !== '') {
                ha_call_entity($svc, $entity, $data);
                return true;
            }
        }
        if (is_string($m['service_set'] ?? null) && $m['service_set'] !== '') {
            ha_call_entity((string) $m['service_set'], $entity, $data + ['value' => $value]);
            return true;
        }
        if (is_string($m['service'] ?? null) && $m['service'] !== '') {
            ha_call_entity((string) $m['service'], $entity, $data + ['value' => $value]);
            return true;
        }
        ha_set_state($entity, is_bool($value) ? ($value ? 'on' : 'off') : $value, $data);
        return true;
    } catch (Throwable $e) {
        Log::add('error', "ko_set($nr) fehlgeschlagen: " . $e->getMessage());
        return false;
    }
}

/** @return array<string,mixed> */
function ko_map(): array
{
    return Engine::$koMap;
}
