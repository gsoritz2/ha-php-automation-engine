# Changelog

## 0.1.1

* `ko_map.json` wird beim Start immer vorgehalten (auch bei bestehenden Installationen),
  damit `ko_get()`/`ko_set()` ohne Warnungen laufen
* Beispiel 4 (`04_beispiel_live_check.php`): Selbsttest über
  `POST /api/trigger?name=live_check` schreibt `sensor.php_engine_live_check`

## 0.1.0

* Erstes Release: PHP-Automation-Engine als HA-App
* State-Trigger (poll-basiert) mit Bedingungen, `every()`/`cron()`/`at()`, `on_start()`,
  benannte HTTP-Trigger, persistente Variablen
* HA-API-Hülle (`ha_state`, `ha_call`, `ha_set_state`, `ha_notify`, `ha_template`, `ha_event`)
* Edomi-Kompatibilitätsschicht `ko_get()`/`ko_set()` über `ko_map.json`
* Eingebauter HTTP-Server: Ingress-Status-UI, `/health`-Watchdog, Webhooks, `/api/run`
* Auto-Reload der Skripte, Fehlerisolierung pro Skript/Callback, Log-Ringpuffer
