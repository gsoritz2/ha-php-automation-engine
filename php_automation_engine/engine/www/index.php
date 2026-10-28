<?php
declare(strict_types=1);

/**
 * Status-UI des Add-ons (Ingress-Panel "PHP Automation").
 * Reines HTML/CSS/JS ohne Abhaengigkeiten - laeuft auch ohne Internet.
 *
 * Hinweis: im Heredoc stehen bewusst nur einfache Variablen (keine Funktionsaufrufe),
 * damit die Interpolation in jeder PHP-Version eindeutig ist.
 */
function phpengine_ui(array $s): string
{
    $esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $up = (int) ($s['addon']['uptime'] ?? 0);
    $v_uptime = sprintf('%dd %02dh %02dm %02ds', intdiv($up, 86400), intdiv($up % 86400, 3600), intdiv($up % 3600, 60), $up % 60);
    $v_version = $esc($s['addon']['version'] ?? '?');
    $v_php = $esc($s['addon']['php'] ?? '?');
    $v_api = $esc($s['addon']['api_base'] ?? '?');
    $v_tz = $esc($s['addon']['timezone'] ?? '?');
    $v_started = $esc($s['addon']['started'] ?? '?');
    $v_dir = $esc($s['config']['script_dir'] ?? '?');
    $v_autoreload = $esc(($s['config']['auto_reload'] ?? false) ? 'an' : 'aus');

    $stats = $s['stats'];
    $v_scripts = $esc(count($s['scripts']));
    $v_triggers = $esc(count($s['state_triggers']));
    $v_timers = $esc(count($s['timers']));
    $v_fires = $esc($stats['fires']);
    $v_errors = $esc($stats['errors']);
    $v_polls = $esc($stats['poll_count']);
    $v_reloads = $esc($stats['reloads']);
    $v_komap = $esc($s['config']['ko_map_entries']);

    $lastError = $stats['last_error'] !== null
        ? '<div class="bad">Letzter Fehler: ' . $esc($stats['last_error']) . '</div>'
        : '';

    $rowsScripts = '';
    foreach ($s['scripts'] as $sc) {
        $err = $sc['error'] !== null ? '<span class="bad">' . $esc($sc['error']) . '</span>' : '<span class="ok">ok</span>';
        $rowsScripts .= '<tr><td><code>' . $esc($sc['file']) . '</code></td><td>' . $err . '</td><td>' . $esc($sc['age']) . ' s</td>'
            . '<td><button data-run="' . $esc($sc['file']) . '">Testlauf</button></td></tr>';
    }
    if ($rowsScripts === '') {
        $rowsScripts = '<tr><td colspan="4" class="muted">Keine Skripte in <code>' . $v_dir . '</code></td></tr>';
    }

    $rowsTriggers = '';
    foreach ($s['state_triggers'] as $t) {
        $rowsTriggers .= '<tr><td><code>' . $esc($t['entity']) . '</code></td><td>' . $esc($t['state'] ?? '-') . '</td>'
            . '<td>' . $esc($t['condition']) . '</td><td>' . $esc($t['fires']) . '</td><td>' . $esc($t['script']) . '</td></tr>';
    }
    if ($rowsTriggers === '') {
        $rowsTriggers = '<tr><td colspan="5" class="muted">Keine State-Trigger registriert</td></tr>';
    }

    $rowsTimers = '';
    foreach ($s['timers'] as $t) {
        $rowsTimers .= '<tr><td>' . $esc($t['label']) . '</td><td>' . $esc($t['type']) . '</td><td>' . $esc($t['next_in']) . ' s</td>'
            . '<td>' . $esc($t['fires']) . '</td><td>' . $esc($t['script']) . '</td></tr>';
    }
    if ($rowsTimers === '') {
        $rowsTimers = '<tr><td colspan="5" class="muted">Keine Timer registriert</td></tr>';
    }

    $rowsNamed = '';
    foreach ($s['named_triggers'] as $t) {
        $rowsNamed .= '<tr><td><code>' . $esc($t['name']) . '</code></td><td>' . $esc($t['fires']) . '</td><td>' . $esc($t['script']) . '</td>'
            . '<td><button data-trigger="' . $esc($t['name']) . '">Ausl&ouml;sen</button></td></tr>';
    }
    if ($rowsNamed === '') {
        $rowsNamed = '<tr><td colspan="4" class="muted">Keine benannten Trigger</td></tr>';
    }

    $logLines = '';
    foreach ($s['log'] as $line) {
        $cls = str_contains($line, '[ERROR') ? 'bad' : (str_contains($line, '[WARNING') ? 'warn' : '');
        $logLines .= '<div class="' . $cls . '">' . $esc($line) . '</div>';
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PHP Automation Engine</title>
<style>
 :root { color-scheme: dark; }
 body { font-family: -apple-system, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 16px 20px 40px;
        background: #111418; color: #e6e9ef; font-size: 14px; }
 h1 { font-size: 20px; margin: 0 0 4px; }
 h2 { font-size: 15px; margin: 22px 0 8px; color: #9fb0c9; text-transform: uppercase; letter-spacing: .04em; }
 .muted { color: #8b95a7; }
 .ok { color: #6ddc8f; } .bad { color: #ff7a7a; } .warn { color: #ffcc66; }
 .cards { display: flex; flex-wrap: wrap; gap: 10px; margin: 12px 0 4px; }
 .card { background: #1a1f26; border: 1px solid #262d36; border-radius: 10px; padding: 10px 14px; min-width: 120px; }
 .card .k { font-size: 11px; color: #8b95a7; text-transform: uppercase; letter-spacing: .05em; }
 .card .v { font-size: 18px; font-weight: 600; margin-top: 2px; }
 table { border-collapse: collapse; width: 100%; background: #1a1f26; border: 1px solid #262d36; border-radius: 10px; overflow: hidden; }
 th, td { text-align: left; padding: 6px 10px; border-bottom: 1px solid #232a33; font-size: 13px; }
 th { background: #20262f; color: #9fb0c9; font-weight: 600; }
 tr:last-child td { border-bottom: none; }
 code { background: #20262f; padding: 1px 5px; border-radius: 5px; font-size: 12px; }
 button { background: #2a3340; color: #e6e9ef; border: 1px solid #39424f; border-radius: 7px; padding: 4px 10px; cursor: pointer; font-size: 12px; }
 button:hover { background: #354050; }
 pre#log { background: #0d1013; border: 1px solid #262d36; border-radius: 10px; padding: 10px; overflow: auto; max-height: 320px; font-size: 12px; line-height: 1.45; }
 .bar { display: flex; gap: 8px; align-items: center; margin-top: 10px; flex-wrap: wrap; }
</style>
</head>
<body>
<h1>PHP Automation Engine <span class="muted">v$v_version</span></h1>
<div class="muted">PHP $v_php &middot; API $v_api &middot; TZ $v_tz &middot; gestartet $v_started</div>

<div class="cards">
  <div class="card"><div class="k">Laufzeit</div><div class="v">$v_uptime</div></div>
  <div class="card"><div class="k">Skripte</div><div class="v">$v_scripts</div></div>
  <div class="card"><div class="k">State-Trigger</div><div class="v">$v_triggers</div></div>
  <div class="card"><div class="k">Timer</div><div class="v">$v_timers</div></div>
  <div class="card"><div class="k">Feuer</div><div class="v">$v_fires</div></div>
  <div class="card"><div class="k">Fehler</div><div class="v">$v_errors</div></div>
  <div class="card"><div class="k">Polls</div><div class="v">$v_polls</div></div>
  <div class="card"><div class="k">Reloads</div><div class="v">$v_reloads</div></div>
  <div class="card"><div class="k">KO-Map</div><div class="v">$v_komap</div></div>
</div>
$lastError

<div class="bar">
  <button id="reload">Skripte neu laden</button>
  <button id="refresh">Aktualisieren</button>
  <span class="muted">Verzeichnis: <code>$v_dir</code> &middot; Auto-Reload: $v_autoreload</span>
</div>

<h2>Skripte</h2>
<table><tr><th>Datei</th><th>Status</th><th>Alter</th><th></th></tr>$rowsScripts</table>

<h2>State-Trigger</h2>
<table><tr><th>Entity</th><th>Aktuell</th><th>Bedingung</th><th>Feuer</th><th>Skript</th></tr>$rowsTriggers</table>

<h2>Timer</h2>
<table><tr><th>Bezeichnung</th><th>Typ</th><th>N&auml;chster in</th><th>Feuer</th><th>Skript</th></tr>$rowsTimers</table>

<h2>Benannte Trigger (HTTP ausl&ouml;sbar)</h2>
<table><tr><th>Name</th><th>Feuer</th><th>Skript</th><th></th></tr>$rowsNamed</table>

<h2>Log (letzte Zeilen)</h2>
<pre id="log">$logLines</pre>

<script>
const post = async (url, body) => {
  const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
  const t = await r.text();
  alert(url + '\n' + r.status + '\n' + t.slice(0, 400));
  location.reload();
};
document.getElementById('reload').onclick = () => post('api/reload');
document.getElementById('refresh').onclick = () => location.reload();
document.querySelectorAll('button[data-run]').forEach(b => b.onclick = () => post('api/run', { script: b.dataset.run }));
document.querySelectorAll('button[data-trigger]').forEach(b => b.onclick = () => post('api/trigger', { name: b.dataset.trigger }));
</script>
</body>
</html>
HTML;
}
