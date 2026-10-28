#!/usr/bin/env python3
"""Szenariotest fuer den portierten EDOMI-Logikbaustein "Solaranlage".

Prueft die Hysterese, die Grenzwertabschaltung, die Zwangssteuerung, die
Helfer-Uebersteuerung, die Edomi-"refresh"-Semantik und die
Aenderungserkennung am Ausgang - gegen echtes PHP und den HA-Mock.

Aufruf:  python3 tests/test_solar_lbs.py /pfad/zu/php
"""
from __future__ import annotations

import json
import os
import shutil
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
PRESET = os.path.join(REPO, "php_automation_engine", "engine", "presets", "20_edomi_solaranlage.php")
MOCK = os.path.join(HERE, "mock_ha.py")

PHP = sys.argv[1] if len(sys.argv) > 1 else "php"
MOCK_PORT = 8398
HTTP_PORT = 8399

KOLL = "sensor.stiebel_eltron_lwz_solarkollektortemperatur"
SPEICHER = "sensor.stiebel_eltron_lwz_ist_temperatur_warmwasser"
PUMPE = "binary_sensor.solarpumpe_anforderung"
DELTA = "sensor.solar_temperaturdifferenz"

PASS: list[str] = []
FAIL: list[str] = []


def check(name: str, ok: bool, detail: str = "") -> None:
    (PASS if ok else FAIL).append(name)
    print(("  PASS  " if ok else "  FAIL  ") + name + (f"   [{detail}]" if detail and not ok else ""))


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
        return e.code, e.read().decode()
    except (urllib.error.URLError, ConnectionError, OSError) as e:
        return 0, str(e)


def mock_set(entity: str, state: str) -> None:
    q = f"http://127.0.0.1:{MOCK_PORT}/__test/set?entity={urllib.parse.quote(entity)}&state={urllib.parse.quote(state)}"
    http("POST", q)


def mock_state(entity: str) -> str | None:
    code, body = http("GET", f"http://127.0.0.1:{MOCK_PORT}/__test/state?entity={urllib.parse.quote(entity)}")
    if code == 200 and isinstance(body, dict):
        return body.get("state")
    return None


def mock_calls() -> list[dict]:
    code, body = http("GET", f"http://127.0.0.1:{MOCK_PORT}/__test/calls")
    return body if isinstance(body, list) else []


def wait_state(entity: str, value: str, timeout: float = 6.0) -> bool:
    end = time.time() + timeout
    while time.time() < end:
        if mock_state(entity) == value:
            return True
        time.sleep(0.1)
    return False


def status() -> dict:
    code, body = http("GET", f"http://127.0.0.1:{HTTP_PORT}/api/status")
    return body if isinstance(body, dict) else {}


def main() -> int:
    tmp = tempfile.mkdtemp(prefix="solarlbs-")
    scripts = os.path.join(tmp, "scripts")
    os.makedirs(scripts, exist_ok=True)
    shutil.copy(PRESET, os.path.join(scripts, "20_edomi_solaranlage.php"))
    logfile = os.path.join(tmp, "engine.log")

    mock = subprocess.Popen([sys.executable, MOCK, str(MOCK_PORT)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(60):
        if http("GET", f"http://127.0.0.1:{MOCK_PORT}/__test/calls", timeout=1)[0] == 200:
            break
        time.sleep(0.2)

    env = dict(os.environ)
    env.update({
        "HA_API_BASE": f"http://127.0.0.1:{MOCK_PORT}/core/api",
        "SUPERVISOR_TOKEN": "testtoken",
        "PHPENGINE_SCRIPT_DIR": scripts,
        "PHPENGINE_LOG": logfile,
        "PHPENGINE_VAR_FILE": os.path.join(tmp, "state.json"),
        "PHPENGINE_HTTP_PORT": str(HTTP_PORT),
        "PHPENGINE_POLL_INTERVAL": "0.3",
        "PHPENGINE_LOG_LEVEL": "debug",
        "PHPENGINE_AUTO_RELOAD": "true",
        "TZ": "Europe/Vienna",
    })
    proc = subprocess.Popen([PHP, ENGINE], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.STDOUT)
    ready = False
    for _ in range(80):
        if http("GET", f"http://127.0.0.1:{HTTP_PORT}/health", timeout=1)[0] == 200:
            ready = True
            break
        time.sleep(0.2)

    try:
        check("Engine startet mit Vorgabe-Skript", ready)
        st = status()
        check("Skript ohne Fehler geladen", st.get("stats", {}).get("script_errors") in ({}, []), str(st.get("stats", {}).get("script_errors")))
        check("7 Eingaenge abonniert (E1-E7)", st.get("stats", {}).get("subscribed_entities") == 7, str(st.get("stats")))
        check("benannter Trigger solar_check registriert", any(t["name"] == "solar_check" for t in st.get("named_triggers", [])))

        print("== Startzustand ==")
        ok = wait_state(PUMPE, "off")
        check("A1 = AUS bei T-Diff -34.6 K", ok, f"pumpe={mock_state(PUMPE)}")
        delta = mock_state(DELTA)
        check("A2 schreibt Temperaturdifferenz (-34.6)", delta is not None and abs(float(delta) + 34.6) < 0.05, str(delta))

        print("== Einschaltschwelle ==")
        mock_set(KOLL, "55.0")            # T-Diff 10.9 > 6 -> EIN
        ok = wait_state(PUMPE, "on")
        check("A1 = EIN bei T-Diff 10.9 K (> E1=6)", ok, f"pumpe={mock_state(PUMPE)}")

        print("== Hysterese ==")
        mock_set(KOLL, "47.0")            # T-Diff 2.9 -> zwischen E1 und E2
        time.sleep(2.5)
        check("A1 bleibt EIN bei T-Diff 2.9 K (zwischen E2=2 und E1=6)", mock_state(PUMPE) == "on", f"pumpe={mock_state(PUMPE)}")

        print("== Ausschaltschwelle ==")
        mock_set(KOLL, "45.5")            # T-Diff 1.4 <= 2 -> AUS
        ok = wait_state(PUMPE, "off")
        check("A1 = AUS bei T-Diff 1.4 K (<= E2=2)", ok, f"pumpe={mock_state(PUMPE)}")

        print("== Grenzwert Kollektor (E5) ==")
        mock_set(KOLL, "120.0")           # > T_max Kollektor 110 -> AUS
        time.sleep(2.5)
        check("A1 = AUS bei Kollektor 120 > T_max 110", mock_state(PUMPE) == "off", f"pumpe={mock_state(PUMPE)}")
        with open(logfile, encoding="utf-8", errors="replace") as fh:
            check("Log nennt Grenzwertabschaltung", "Grenzwert erreicht" in fh.read())

        print("== Zwangssteuerung (E7) ==")
        mock_set(KOLL, "30.0")            # T-Diff -14.1, aber Zwang=1 -> EIN
        mock_set("input_boolean.solar_zwangssteuerung", "on")
        ok = wait_state(PUMPE, "on")
        check("A1 = EIN durch Zwangssteuerung", ok, f"pumpe={mock_state(PUMPE)}")
        mock_set("input_boolean.solar_zwangssteuerung", "off")

        print("== Zwangssteuerung respektiert Grenzwerte ==")
        mock_set(KOLL, "120.0")           # Zwangssteuerung + Grenzwert verletzt -> AUS
        mock_set("input_boolean.solar_zwangssteuerung", "on")
        ok = wait_state(PUMPE, "off")
        check("A1 = AUS bei Zwang + Kollektor > T_max", ok, f"pumpe={mock_state(PUMPE)}")

        # Zwang aus, Ausgang definiert zuruecksetzen (AUS ueber T-Diff <= E2)
        mock_set("input_boolean.solar_zwangssteuerung", "off")
        time.sleep(1.5)                   # Engine soll Zwang=off gesehen haben
        mock_set(KOLL, "44.5")            # T-Diff 0.4 -> AUS
        ok = wait_state(PUMPE, "off")
        check("Ausgang nach Zwangssteuerung zurueckgesetzt", ok, f"pumpe={mock_state(PUMPE)}")

        print("== Helfer-Uebersteuerung (E1 = 20) ==")
        wait_state(PUMPE, "off")
        mock_set("input_number.solar_t_diff_ein", "20.0")
        time.sleep(1.5)
        mock_set(KOLL, "60.0")            # T-Diff 15.9: >6, aber nicht >20 -> bleibt AUS
        time.sleep(3.0)
        check("A1 bleibt AUS bei T-Diff 15.9 K (E1-Helfer = 20)", mock_state(PUMPE) == "off", f"pumpe={mock_state(PUMPE)}")

        print("== Edomi-refresh-Semantik ==")
        mock_set("input_number.solar_t_diff_ein", "1.0")   # nur E1 geaendert: darf nichts schalten
        time.sleep(3.0)
        check("Nur-Aenderung von E1 schaltet die Pumpe nicht", mock_state(PUMPE) == "off", f"pumpe={mock_state(PUMPE)}")
        mock_set(KOLL, "60.5")            # jetzt mit E3-Refresh -> EIN
        ok = wait_state(PUMPE, "on")
        check("A1 = EIN bei T-Diff 16.4 K (> E1=1)", ok, f"pumpe={mock_state(PUMPE)}")

        print("== Ausgang schreibt nur bei Aenderung ==")
        before = len([c for c in mock_calls() if c.get("kind") == "set_state" and c.get("entity") == PUMPE])
        time.sleep(5.0)
        after = len([c for c in mock_calls() if c.get("kind") == "set_state" and c.get("entity") == PUMPE])
        check("Keine weiteren Schreibzugriffe im Leerlauf", before == after, f"{before} -> {after}")

        print("== Nicht lesbare Temperatur ==")
        mock_set(KOLL, "unavailable")
        time.sleep(2.5)
        check("Pumpe bleibt bei 'unavailable' unveraendert", mock_state(PUMPE) == "on", f"pumpe={mock_state(PUMPE)}")
        with open(logfile, encoding="utf-8", errors="replace") as fh:
            check("Log warnt ueber nicht lesbare Temperatur", "nicht lesbar" in fh.read())

        print("== Reload (Auto-Reload) vertraegt das Skript ==")
        os.utime(os.path.join(scripts, "20_edomi_solaranlage.php"))
        time.sleep(3.5)
        st = status()
        check("Nach Reload weiterhin 7 Trigger, keine Fehler",
              st.get("stats", {}).get("subscribed_entities") == 7 and st.get("stats", {}).get("script_errors") in ({}, []),
              str(st.get("stats")))

        print("== Manueller Trigger solar_check ==")
        code, body = http("POST", f"http://127.0.0.1:{HTTP_PORT}/api/trigger", {"name": "solar_check"})
        check("POST /api/trigger name=solar_check -> 200", code == 200, str(body))
    finally:
        proc.kill()
        mock.kill()

    print(f"\n{len(PASS)} bestanden, {len(FAIL)} fehlgeschlagen")
    if FAIL:
        for f in FAIL:
            print("  - " + f)
        print("\n--- Engine-Log (letzte 60 Zeilen) ---")
        try:
            with open(logfile, encoding="utf-8", errors="replace") as fh:
                for line in fh.read().splitlines()[-60:]:
                    print(line)
        except OSError as e:
            print(f"(kein Log: {e})")
        shutil.rmtree(tmp, ignore_errors=True)
        return 1
    shutil.rmtree(tmp, ignore_errors=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
