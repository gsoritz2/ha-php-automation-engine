# Changelog

## 0.1.0

* Erstes Release: PHP-Automation-Engine als HA-App
* State-Trigger (poll-basiert) mit Bedingungen, `every()`/`cron()`/`at()`, `on_start()`,
  benannte HTTP-Trigger, persistente Variablen
* HA-API-Hülle (`ha_state`, `ha_call`, `ha_set_state`, `ha_notify`, `ha_template`, `ha_event`)
* Edomi-Kompatibilitätsschicht `ko_get()`/`ko_set()` über `ko_map.json`
* Eingebauter HTTP-Server: Ingress-Status-UI, `/health`-Watchdog, Webhooks, `/api/run`
* Auto-Reload der Skripte, Fehlerisolierung pro Skript/Callback, Log-Ringpuffer
