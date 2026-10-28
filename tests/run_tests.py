#!/usr/bin/env python3
"""Integrationstests fuer die PHP Automation Engine.

Startet den Mock-HA, die Engine (echtes PHP) und prueft Trigger, Timer,
Service-Aufrufe, KO-Map, Fehlerisolierung, Reload und Persistenz.

Aufruf:  python3 tests/run_tests.py /pfad/zu/php
"""
from __future__ import annotations

import json
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(HERE)
ENGINE = os.path.join(REPO, "php_automation_engine", "engine", "engine.php")
MOCK = os.path.join(HERE, "mock_ha.py")

PHP = sys.argv[1] if len(sys.argv) > 1 else "php"
MOCK_PORT = 8198
HTTP_PORT = 8199

PASS: list[str] = []
FAIL: list[str] = []


def check(name: str, ok: bool, detail: str = "") -> None:
    (PASS if ok else FAIL).append(name)
    print(("  PASS  " if ok else "  FAIL  ") + name + (f"   [{detail}]" if detail and not ok else ""))


def free_port(start: int) -> int:
    p = start
    while p < start + 200:
        with socket.socket() as s:
            if s.connect_ex(("127.0.0.1", p)) != 0:
                return p
        p += 1
    raise RuntimeError("kein freier Port")


def http(method: str, url: str, payload=None, timeout: float = 5.0):
    data = None if payload is None else json.dumps(payload).encode()
    req = urllib.request.Request(url, data=data, method=method, headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            raw = r.read().decode()
            try:
                return r.status, json.loads(raw)
            except json.JSONDecodeError:
                return r.status, raw
    except urllib.error.HTTPError as e:
        raw = e.read().decode()
        try:
            return e.code, json.loads(raw)
        except json.JSONDecodeError:
            return e.code, raw
    except (urllib.error.URLError, ConnectionError, OSError) as e:
        return 0, str(e)


def mock_set(entity: str, state: str, attrs: dict | None = None) -> None:
    q = f"http://127.0.0.1:{MOCK_PORT}/__test/set?entity={entity}&state={state}"
    if attrs is not None:
        q += "&attributes=" + urllib.parse.quote(json.dumps(attrs))
    http("POST", q)


def mock_calls() -> list[dict]:
    _, calls = http("GET", f"http://127.0.0.1:{MOCK_PORT}/__test/calls")
    return calls if isinstance(calls, list) else []


def mock_reset() -> None:
    http("POST", f"http://127.0.0.1:{MOCK_PORT}/__test/reset")


def wait_for(pred, timeout: float = 8.0, interval: float = 0.15) -> bool:
    end = time.time() + timeout
    while time.time() < end:
        if pred():
            return True
        time.sleep(interval)
    return False


def status() -> dict:
    code, body = http("GET", f"http://127.0.0.1:{HTTP_PORT}/api/status")
    return body if isinstance(body, dict) else {}


LOG_FILE = ""


def log_text() -> str:
    """Komplettes Engine-Log von der Platte (kein Ringpuffer-Fenster)."""
    try:
        with open(LOG_FILE, encoding="utf-8", errors="replace") as fh:
            return fh.read()
    except OSError:
        return ""


def start_engine(env: dict) -> subprocess.Popen:
    proc = subprocess.Popen([PHP, ENGINE], env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    ready = wait_for(lambda: http("GET", f"http://127.0.0.1:{HTTP_PORT}/health", timeout=1)[0] == 200, timeout=10)
    if not ready:
        out = ""
        try:
            proc.kill()
            out = proc.stdout.read()  # type: ignore[union-attr]
        except Exception:
            pass
        raise RuntimeError("Engine wurde nicht bereit:\n" + out[-3000:])
    return proc


def main() -> int:
    tmp = tempfile.mkdtemp(prefix="phpengine-test-")
    scripts = os.path.join(tmp, "scripts")
    os.makedirs(scripts, exist_ok=True)

    mock = subprocess.Popen([sys.executable, MOCK, str(MOCK_PORT)], stdout=subprocess.PIPE, text=True)
    wait_for(lambda: http("GET", f"http://127.0.0.1:{MOCK_PORT}/__test/calls", timeout=1)[0] == 200, timeout=10)

    env = dict(os.environ)
    env.update({
        "HA_API_BASE": f"http://127.0.0.1:{MOCK_PORT}/core/api",
        "SUPERVISOR_TOKEN": "testtoken",
        "PHPENGINE_SCRIPT_DIR": scripts,
        "PHPENGINE_LOG": os.path.join(tmp, "engine.log"),
        "PHPENGINE_VAR_FILE": os.path.join(tmp, "state.json"),
        "PHPENGINE_HTTP_PORT": str(HTTP_PORT),
        "PHPENGINE_POLL_INTERVAL": "0.3",
        "PHPENGINE_LOG_LEVEL": "debug",
        "PHPENGINE_AUTO_RELOAD": "true",
        "TZ": "Europe/Vienna",
    })

    # ---------------------------------------------------------------- Skripte
    with open(os.path.join(scripts, "ko_map.json"), "w") as fh:
        json.dump({
            "1": {"entity": "sensor.pool_temperatur", "type": "float"},
            "20": {"entity": "switch.pool_pumpe", "type": "bool", "service_on": "switch.turn_on", "service_off": "switch.turn_off"},
            "21": {"entity": "number.pool_sollwert", "type": "float", "service_set": "number.set_value"},
            "30": {"entity": "sensor.pool_modus", "type": "raw", "map": {"auto": 1, "manuell": 2}},
            "31": {"entity": "sensor.pool_status", "type": "raw", "attribute": "mode"},
        }, fh)

    with open(os.path.join(scripts, "10_trigger.php"), "w") as fh:
        fh.write("""<?php
state_trigger('sensor.aussen_temperatur', function (string $new, string $old, string $e): void {
    log_info("HIT-TEMP $e $old->$new");
    ha_set_state('sensor.php_test_hit', $new, ['unit_of_measurement' => 'C']);
}, 'above:30');

state_trigger('binary_sensor.haustuer', function (string $new): void {
    log_info("HIT-TUER $new");
    ha_call_entity('light.turn_on', 'light.flur', ['brightness_pct' => 30]);
}, 'eq:on');

engine_trigger('mein_test', function (): void {
    log_info('HIT-NAMED');
    ha_event('php_engine_test', ['quelle' => 'named_trigger']);
});

state_trigger('sun.sun', function (): void {
    log_info('HIT-SUN');
}, 'from:above_horizon');
""")

    with open(os.path.join(scripts, "20_timer.php"), "w") as fh:
        fh.write("""<?php
every('1s', function (): void {
    $n = (int) state_get('ticker', 0) + 1;
    state_set('ticker', $n);
}, 'ticker_1s');

cron('* * * * *', function (): void {
    log_info('HIT-CRON');
}, 'jede_minute');
""")

    with open(os.path.join(scripts, "30_ko.php"), "w") as fh:
        fh.write("""<?php
every('0.5s', function (): void {
    $t = ko_get(1);
    if ($t !== null && $t > 28.0 && !ko_bool(20)) {
        ko_set(20, true);
        log_info("HIT-KO-ON $t");
    }
    if ($t !== null && $t < 26.0 && ko_bool(20)) {
        ko_set(20, false);
        log_info('HIT-KO-OFF');
    }
    if (ko_get(30) === 2) {
        log_info('HIT-KO-MAP');
    }
    if (ko_get(31) === 'eco') {
        log_info('HIT-KO-ATTR');
    }
    ko_set(21, 27.5);
}, 'ko_loop');
""")

    with open(os.path.join(scripts, "40_broken_throw.php"), "w") as fh:
        fh.write("""<?php
throw new RuntimeException('absichtlicher Ladefehler');

state_trigger('light.flur', function (): void {});
""")

    with open(os.path.join(scripts, "50_runtime_error.php"), "w") as fh:
        fh.write("""<?php
state_trigger('light.flur', function (): void {
    throw new RuntimeException('Fehler im Callback');
}, 'eq:on');
""")

    print("== Unit-Tests (Cron, Dauer, Bedingungen) ==")
    unit = subprocess.run([PHP, os.path.join(HERE, "test_cron.php")], capture_output=True, text=True)
    print(unit.stdout.strip())
    if unit.returncode != 0:
        print(unit.stderr.strip()[-2000:])
    check("Unit-Tests test_cron.php laufen durch", unit.returncode == 0, f"rc={unit.returncode}")

    global LOG_FILE
    LOG_FILE = env["PHPENGINE_LOG"]

    print("== Engine starten ==")
    proc = start_engine(env)
    try:
        st = status()
        check("Engine startet und liefert Status", bool(st.get("addon")), str(st)[:200])
        check("Skripte geladen (5 Dateien)", len(st.get("scripts", [])) == 5, str(len(st.get("scripts", []))))
        check("Ladefehler isoliert: 40_broken_throw.php", any(s["error"] for s in st.get("scripts", []) if s["file"] == "40_broken_throw.php"))
        check("KO-Map geladen (5 Eintraege)", st.get("config", {}).get("ko_map_entries") == 5, str(st.get("config")))
        check("Timer registriert (ticker + cron + ko_loop)", len(st.get("timers", [])) == 3, str(len(st.get("timers", []))))
        check("Cron-Timer hat naechsten Lauf <= 60s", any(t["type"] == "cron" and t["next_in"] <= 60 for t in st.get("timers", [])))
        check("benannter Trigger registriert", any(t["name"] == "mein_test" for t in st.get("named_triggers", [])))

        print("== State-Trigger ==")
        mock_reset()
        mock_set("sensor.aussen_temperatur", "35.5")
        ok = wait_for(lambda: "HIT-TEMP" in log_text())
        check("Trigger above:30 feuert", ok, log_text()[-400:])
        check("Pseudo-Sensor wurde gesetzt", any(c["kind"] == "set_state" and c["entity"] == "sensor.php_test_hit" and c["state"] == "35.5" for c in mock_calls()), str(mock_calls())[:300])

        mock_set("sensor.aussen_temperatur", "20.0")
        time.sleep(1.0)
        check("Bedingung above:30 feuert bei 20 Grad nicht erneut",
              log_text().count("HIT-TEMP") == 1,
              str(log_text().count("HIT-TEMP")))

        mock_reset()
        mock_set("binary_sensor.haustuer", "on")
        ok = wait_for(lambda: any(c["kind"] == "service" and c["domain"] == "light" and c["service"] == "turn_on" for c in mock_calls()))
        check("Service-Aufruf light.turn_on ausgeloest", ok, str(mock_calls())[:300])
        check("Service-Daten (brightness_pct) korrekt uebergeben",
              any(c["data"].get("brightness_pct") == 30 for c in mock_calls() if c["kind"] == "service"),
              str(mock_calls())[:400])

        print("== Timer ==")
        first = int(status().get("timers", [{}])[0].get("fires", 0)) if status().get("timers") else 0
        ok = wait_for(lambda: any(t["label"] == "ticker_1s" and t["fires"] >= 2 for t in status().get("timers", [])), timeout=8)
        check("every('1s') feuert mehrfach", ok, str(status().get("timers")))

        print("== Benannter Trigger (HTTP) ==")
        mock_reset()
        code, body = http("POST", f"http://127.0.0.1:{HTTP_PORT}/api/trigger", {"name": "mein_test"})
        check("POST /api/trigger -> 200", code == 200, f"{code} {body}")
        ok = wait_for(lambda: any(c["kind"] == "event" and c["event"] == "php_engine_test" for c in mock_calls()))
        check("ha_event aus benanntem Trigger angekommen", ok, str(mock_calls())[:300])
        code, body = http("POST", f"http://127.0.0.1:{HTTP_PORT}/api/trigger", {"name": "gibtsnicht"})
        check("Unbekannter Trigger -> 404", code == 404, str(code))

        print("== KO-Map ==")
        # Ausgangslage herstellen: Pumpe aus, Pool kalt, Aufrufprotokoll leeren
        mock_set("switch.pool_pumpe", "off")
        mock_set("sensor.pool_temperatur", "27.0")
        mock_set("sensor.pool_modus", "auto")
        time.sleep(1.2)
        mock_reset()
        mock_set("sensor.pool_temperatur", "29.5")
        ok = wait_for(lambda: any(c["kind"] == "service" and c["domain"] == "switch" and c["service"] == "turn_on" for c in mock_calls()))
        check("ko_set(20,true) -> switch.turn_on", ok, str(mock_calls())[:300])
        ok = wait_for(lambda: any(c["kind"] == "service" and c["service"] == "set_value" and str(c["data"].get("value")) == "27.5" for c in mock_calls()))
        check("ko_set(21,27.5) -> number.set_value", ok, str(mock_calls())[:400])
        mock_set("sensor.pool_modus", "manuell")
        ok = wait_for(lambda: "HIT-KO-MAP" in log_text())
        check("ko_get mit Enum-Map (manuell -> 2)", ok, log_text()[-300:])
        check("ko_get liest Attribut (mode=eco -> HIT-KO-ATTR)", "HIT-KO-ATTR" in log_text())

        print("== Fehlerisolierung ==")
        mock_reset()
        mock_set("light.flur", "off")
        time.sleep(1.2)
        mock_set("light.flur", "on")
        ok = wait_for(lambda: "Fehler im Callback" in log_text())
        check("Fehler im Callback wird geloggt", ok, log_text()[-300:])
        mock_set("sensor.aussen_temperatur", "31.0")
        time.sleep(0.5)
        mock_set("sensor.aussen_temperatur", "32.0")
        ok = wait_for(lambda: log_text().count("HIT-TEMP") >= 2)
        check("Engine laeuft nach Callback-Fehler weiter", ok, str(status().get("stats")))
        check("Fehlerzaehler > 0", status().get("stats", {}).get("errors", 0) > 0, str(status().get("stats")))

        print("== Testlauf /api/run ==")
        code, body = http("POST", f"http://127.0.0.1:{HTTP_PORT}/api/run", {"script": "20_timer.php"})
        check("/api/run meldet ok", code == 200 and isinstance(body, dict) and body.get("ok") is True, str(body)[:300])
        check("/api/run registriert Trigger nur temporaer", len(status().get("timers", [])) == 3, str(len(status().get("timers", []))))

        print("== Reload bei Dateiaenderung ==")
        before = len(status().get("state_triggers", []))
        with open(os.path.join(scripts, "60_neu.php"), "w") as fh:
            fh.write("<?php\nstate_trigger('light.flur', function (): void { log_info('HIT-NEU'); }, 'eq:on');\n")
        ok = wait_for(lambda: any(t["script"] == "60_neu.php" for t in status().get("state_triggers", [])), timeout=10)
        check("Auto-Reload nimmt neues Skript auf", ok, str(status().get("stats")))
        check("Trigger-Anzahl gestiegen", len(status().get("state_triggers", [])) == before + 1, f"{before} -> {len(status().get('state_triggers', []))}")

        print("== Persistenz ==")
        code, body = http("POST", f"http://127.0.0.1:{HTTP_PORT}/api/reload")
        ticker_before = json.load(open(env["PHPENGINE_VAR_FILE"])).get("ticker")
        check("Persistente Variable ticker wurde gespeichert", isinstance(ticker_before, int) and ticker_before >= 2, str(ticker_before))

        print("== Web-UI ==")
        code, body = http("GET", f"http://127.0.0.1:{HTTP_PORT}/")
        check("Ingress-UI liefert HTML", code == 200 and isinstance(body, str) and "PHP Automation Engine" in body, str(code))
        code, body = http("GET", f"http://127.0.0.1:{HTTP_PORT}/health")
        check("/health -> ok (Watchdog)", code == 200 and body == "ok", str(body))
        code, body = http("GET", f"http://127.0.0.1:{HTTP_PORT}/api/nonsense")
        check("Unbekannter Pfad -> 404", code == 404, str(code))
    finally:
        proc.kill()
        mock.kill()
        shutil.rmtree(tmp, ignore_errors=True)

    print(f"\n{len(PASS)} bestanden, {len(FAIL)} fehlgeschlagen")
    if FAIL:
        print("Fehlgeschlagen:")
        for f in FAIL:
            print("  - " + f)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
