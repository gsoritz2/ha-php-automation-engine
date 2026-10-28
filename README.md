# ha-php-automation-engine

Home-Assistant-App (Add-on), die **PHP-Automationen** ausführt – gedacht als Ersatz
für eine Edomi-Logikengine bzw. als „AppDaemon/pyscript für PHP".

```text
repository.yaml                 # Add-on-Repository für den HA-App-Store
php_automation_engine/
  config.yaml                   # App-Definition (Optionen, Ports, Ingress, API-Zugriff)
  Dockerfile                    # alpine:3.22 + php84 (kein HA-Base-Image, kein s6)
  run.sh                        # liest /data/options.json -> startet die Engine
  DOCS.md, CHANGELOG.md
  translations/{de,en}.yaml
  engine/
    engine.php                  # Hauptprozess: Laden, Pollen, Trigger, HTTP
    lib/ha.php                  # HA-REST-API-Hülle
    lib/api.php                 # Registry + globale Skript-API (state_trigger, ko_get, ...)
    lib/cron.php                # 5-Feld-Cron-Parser
    lib/http.php                # nicht-blockierender HTTP-Server im Engine-Prozess
    lib/log.php                 # Log mit Ringpuffer
    www/index.php               # Status-UI (Ingress-Panel)
    examples/                   # Beispiel-Automationen, ko_map.example.json
    presets/                    # portierte EDOMI-Logikbausteine (werden nach script_dir kopiert)
tests/
  mock_ha.py                    # Mock der HA-REST-API (stdlib)
  run_tests.py                  # Integrationstests gegen echtes PHP
  test_cron.php                 # Unit-Tests (Cron, Dauer, Trigger-Bedingungen)
  test_solar_lbs.py             # Szenariotest des portierten LBS "Solaranlage"
```

## Warum kein Edomi-PHP direkt?

Edomi-Logikbausteine sind PHP, aber sie rufen Framework-Funktionen
(`logic_getInputs()`, `logic_setVar()`, `sql_connect()`) auf, liegen in der
Edomi-MySQL-Datenbank und werden vom Edomi-Logikprozessor mit seinem KO-System
ausgeführt. Ohne Edomi läuft davon nichts. Diese App bietet deshalb eine eigene,
kleine Laufzeitumgebung mit vergleichbarer Semantik plus eine KO-Kompatibilitäts-
schicht (`ko_map.json`), damit portierte Bausteine zunächst unverändert laufen.

## Tests

```bash
python3 tests/run_tests.py /pfad/zu/php      # echtes PHP 8.3/8.4 noetig
```

Die Tests starten einen Mock der HA-API, laden Beispiel-Skripte und prüfen Trigger,
Timer, Service-Aufrufe, KO-Map, Fehlerisolierung, Auto-Reload, Persistenz und die
HTTP-Endpunkte.

## Installation

Repository-URL in HA unter **Einstellungen → Apps → App-Store → ⋮ → Repositories**
eintragen, App installieren, Skripte nach `/share/php_automations/` legen.

Details: `php_automation_engine/DOCS.md`.
