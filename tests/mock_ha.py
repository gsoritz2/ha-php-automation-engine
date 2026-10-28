#!/usr/bin/env python3
"""Minimaler Home-Assistant-API-Mock fuer die Engine-Tests (nur stdlib).

Nachgebildet wird genau das, was die Engine benutzt:
  GET  /core/api/config
  GET  /core/api/states
  GET  /core/api/states/<entity_id>
  POST /core/api/states/<entity_id>
  POST /core/api/services/<domain>/<service>
  POST /core/api/events/<event_type>
  POST /core/api/template

Zusaetzlich Test-Endpunkte:
  GET  /__test/state?entity=...      -> Zustand
  POST /__test/set?entity=..&state=. -> Zustand setzen (loest Trigger aus)
  GET  /__test/calls                 -> alle Service-/Event-Aufrufe
"""
from __future__ import annotations

import json
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

LOCK = threading.Lock()
STATES: dict[str, dict] = {}
CALLS: list[dict] = []


def set_state(entity_id: str, state: str, attributes: dict | None = None) -> None:
    with LOCK:
        STATES[entity_id] = {
            "entity_id": entity_id,
            "state": state,
            "attributes": attributes or {},
        }


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):  # ruhig bleiben
        pass

    # ------------------------------------------------------------- Helfer
    def _send(self, code: int, payload) -> None:
        body = payload if isinstance(payload, str) else json.dumps(payload)
        raw = body.encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def _auth_ok(self) -> bool:
        return self.headers.get("Authorization", "") == "Bearer testtoken"

    def _body(self) -> dict:
        length = int(self.headers.get("Content-Length") or 0)
        if not length:
            return {}
        try:
            return json.loads(self.rfile.read(length).decode("utf-8"))
        except json.JSONDecodeError:
            return {}

    # -------------------------------------------------------------- Routen
    def do_GET(self):  # noqa: N802
        u = urlparse(self.path)
        q = parse_qs(u.query)
        path = u.path

        if path == "/__test/state":
            entity = q.get("entity", [""])[0]
            with LOCK:
                self._send(200, STATES.get(entity, {}))
            return
        if path == "/__test/calls":
            with LOCK:
                self._send(200, CALLS)
            return

        if not self._auth_ok():
            self._send(401, {"message": "unauthorized"})
            return

        if path == "/core/api/config":
            self._send(200, {"location_name": "Test-HA", "version": "2026.10.2", "time_zone": "Europe/Vienna"})
            return
        if path == "/core/api/states":
            with LOCK:
                self._send(200, list(STATES.values()))
            return
        if path.startswith("/core/api/states/"):
            entity = path[len("/core/api/states/"):]
            with LOCK:
                st = STATES.get(entity)
            if st is None:
                self._send(404, {"message": "Entity not found"})
            else:
                self._send(200, st)
            return
        self._send(404, {"message": f"unknown GET {path}"})

    def do_POST(self):  # noqa: N802
        u = urlparse(self.path)
        q = parse_qs(u.query)
        path = u.path
        body = self._body()

        if path == "/__test/set":
            entity = q.get("entity", [""])[0]
            state = q.get("state", [""])[0]
            attrs = json.loads(q.get("attributes", ["{}"])[0]) if "attributes" in q else {}
            set_state(entity, state, attrs)
            self._send(200, {"ok": True})
            return
        if path == "/__test/reset":
            with LOCK:
                CALLS.clear()
            self._send(200, {"ok": True})
            return

        if not self._auth_ok():
            self._send(401, {"message": "unauthorized"})
            return

        if path.startswith("/core/api/states/"):
            entity = path[len("/core/api/states/"):]
            with LOCK:
                STATES[entity] = {
                    "entity_id": entity,
                    "state": body.get("state"),
                    "attributes": body.get("attributes", {}),
                }
                CALLS.append({"kind": "set_state", "entity": entity, "state": body.get("state"), "attributes": body.get("attributes", {})})
            self._send(200, STATES[entity])
            return

        if path.startswith("/core/api/services/"):
            rest = path[len("/core/api/services/"):]
            domain, _, service = rest.partition("/")
            target = body.get("entity_id")
            entities = target if isinstance(target, list) else ([target] if target else [])
            with LOCK:
                CALLS.append({"kind": "service", "domain": domain, "service": service, "data": body, "entities": entities})
                for e in entities:
                    if not isinstance(e, str) or e not in STATES:
                        continue
                    if service in ("turn_on",):
                        STATES[e]["state"] = "on"
                    elif service in ("turn_off",):
                        STATES[e]["state"] = "off"
                    elif service == "set_value" and "value" in body:
                        STATES[e]["state"] = str(body["value"])
            self._send(200, [])
            return

        if path.startswith("/core/api/events/"):
            event_type = path[len("/core/api/events/"):]
            with LOCK:
                CALLS.append({"kind": "event", "event": event_type, "data": body})
            self._send(200, {"message": "Event fired."})
            return

        if path == "/core/api/template":
            self._send(200, "rendered:" + str(body.get("template", "")))
            return

        self._send(404, {"message": f"unknown POST {path}"})


def main() -> None:
    import sys

    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8198
    # Startzustand
    set_state("sensor.aussen_temperatur", "21.5", {"unit_of_measurement": "°C"})
    set_state("binary_sensor.haustuer", "off", {"device_class": "door"})
    set_state("sun.sun", "above_horizon", {})
    set_state("switch.pool_pumpe", "off", {})
    set_state("sensor.pool_temperatur", "27.0", {})
    set_state("number.pool_sollwert", "26.0", {})
    set_state("sensor.pool_modus", "auto", {})
    set_state("sensor.pool_status", "ok", {"mode": "eco"})
    set_state("light.flur", "off", {})
    # Solar-Thermie (fuer den portierten EDOMI-Logikbaustein "Solaranlage")
    set_state("sensor.stiebel_eltron_lwz_solarkollektortemperatur", "9.5", {"unit_of_measurement": "°C"})
    set_state("sensor.stiebel_eltron_lwz_ist_temperatur_warmwasser", "44.1", {"unit_of_measurement": "°C"})
    set_state("input_number.solar_t_diff_ein", "6.0", {})
    set_state("input_number.solar_t_diff_aus", "2.0", {})
    set_state("input_number.solar_t_max_kollektor", "110.0", {})
    set_state("input_number.solar_t_max_speicher", "75.0", {})
    set_state("input_boolean.solar_zwangssteuerung", "off", {})

    srv = ThreadingHTTPServer(("127.0.0.1", port), Handler)
    print(f"mock HA API auf http://127.0.0.1:{port}/core/api", flush=True)
    srv.serve_forever()


if __name__ == "__main__":
    main()
