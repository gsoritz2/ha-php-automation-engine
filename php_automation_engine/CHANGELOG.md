# Changelog

## 0.2.0

* `engine/presets/`: portierte EDOMI-Logikbausteine werden beim Start nach
  `script_dir` installiert, sofern die Datei dort noch nicht existiert
  (bestehende/eigene Dateien bleiben unangetastet)
* Erstes Preset: `20_edomi_solaranlage.php` – 1:1-Portierung des LBS "Solaranlage"
  (Hysterese T_Diff EIN/AUS, Grenzwerte Kollektor/Speicher, Zwangssteuerung),
  Ausgang als Anforderungs-Pseudo-Sensor, Vorgaben per `input_number`/`input_boolean`
  übersteuerbar; 22 Szenariotests in `tests/test_solar_lbs.py`
* **Bugfix:** `ha_state()` hat Werte von Entities ohne `state_trigger` dauerhaft in
  `Engine::$states` zwischengespeichert und danach nie neu gelesen (Ausgänge froren
  auf dem ersten gelesenen Zustand ein). Nicht abonnierte Entities werden jetzt bei
  jedem Zugriff über die API gelesen (1 s TTL).
* **Bugfix:** `HaApi::setState()` hinterließ einen `null`-Eintrag im State-Cache;
  der nächste Lesezugriff konnte `null` statt des echten Zustands liefern
* **Schutz:** Skripte mit Top-Level-Deklaration (`function`/`class`/`const`) werden
  mit klarer Meldung abgelehnt statt beim Reload die Engine zu beenden

## 0.1.2

* Version in UI und `/api/status` kommt aus dem Build-Arg `BUILD_VERSION` des
  Supervisors statt aus einer PHP-Konstante (`dev`, wenn nicht durchgereicht)

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
