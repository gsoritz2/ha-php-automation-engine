# PHP Automation Engine

Führt **PHP-Automationen** direkt neben Home Assistant aus – als Ersatz für eine
Edomi-Logikengine oder als „AppDaemon/pyscript für PHP".

Die App startet einen einzigen PHP-Prozess, der

1. alle `*.php`-Dateien aus dem Skriptverzeichnis lädt (Auto-Reload bei Änderung),
2. HA-Zustände pollt und Trigger/Timer ausführt,
3. einen HTTP-Endpunkt für Status-UI (Ingress), Watchdog und Webhooks bereitstellt.

## Installation

1. **Einstellungen → Apps → App-Store → ⋮ → Repositories**
   `https://github.com/gsoritz2/ha-php-automation-engine` hinzufügen.
2. **PHP Automation Engine** installieren, starten.
3. Skripte unter `/share/php_automations/` ablegen (per Samba/File-Editor erreichbar).
   Beim ersten Start werden Beispiele dorthin kopiert.

## Optionen

| Option | Standard | Bedeutung |
| --- | --- | --- |
| `script_dir` | `/share/php_automations` | Verzeichnis der Automations-Skripte |
| `poll_interval` | `2.0` | Sekunden zwischen zwei HA-Zustandsabfragen |
| `log_level` | `info` | `trace`/`debug`/`info`/`warning`/`error` |
| `log_lines` | `500` | Ringpuffer im Speicher (für die UI) |
| `timezone` | `Europe/Vienna` | Zeitzone für `at()`/`cron()` |
| `ko_map` | `ko_map.json` | Edomi-KO-Zuordnung, relativ zum Skriptverzeichnis |
| `auto_reload` | `true` | Skripte bei Dateiänderung automatisch neu laden |

## API der Skripte

```php
<?php
// auf Zustandsänderung reagieren
state_trigger('binary_sensor.haustuer', function (string $new, string $old, string $entity): void {
    if ($new === 'on') {
        ha_notify('Haustür offen', 'PHP Engine');
        ha_call_entity('light.turn_on', 'light.flur', ['brightness_pct' => 30]);
    }
}, 'eq:on');

// Zeitsteuerung
every('30s', fn() => log_info('Tick'));
cron('0 7 * * 1-5', fn() => ha_call_entity('switch.turn_on', 'switch.kaffee'));
at('22:30', fn() => ha_call_entity('light.turn_off', 'light.wohnzimmer'));

// persistent über Neustarts
state_set('zaehler', (int) state_get('zaehler', 0) + 1);

// per HTTP auslösbar: POST /api/trigger?name=mein_trigger
engine_trigger('mein_trigger', fn() => log_info('extern ausgelöst'));
```

Vollständige Funktionsliste: siehe `/share/php_automations/README.md` (wird beim
ersten Start mitkopiert) bzw. `engine/examples/README.md` im Repo.

### Trigger-Bedingungen

`null`/`'changed'`, `'eq:on'`, `'not:off'`, `'from:off'`, `'to:on'`, `'above:30'`,
`'below:5'`, `'in:a,b,c'`, `'attr:brightness=255'` – oder ein Callable
`fn(string $new, string $old, string $entity): bool`.

## Edomi-Logik portieren

`ko_map.json` ordnet Edomi-KO-Nummern HA-Entities zu, damit portierter LBS-Code
zunächst unverändert weiterläuft:

```json
{
  "1":  { "entity": "sensor.pool_temperatur", "type": "float" },
  "20": { "entity": "switch.pool_pumpe", "type": "bool",
          "service_on": "switch.turn_on", "service_off": "switch.turn_off" },
  "21": { "entity": "number.pool_sollwert", "service_set": "number.set_value" },
  "30": { "entity": "sensor.pool_modus", "type": "raw",
          "map": { "auto": 1, "manuell": 2, "urlaub": 3 } },
  "31": { "entity": "sensor.pool_status", "attribute": "mode" }
}
```

| Edomi | hier |
| --- | --- |
| `logic_getInputs($id)` | `ko_get(1)` bzw. `ha_state('sensor.x')` |
| Ausgangs-KO setzen | `ko_set(20, true)` |
| LBS-Variablen (`logic_setVar`) | `state_get()`/`state_set()` |
| `###[LBS]###`-Zyklus | `every('1s', ...)` |
| `###[EXEC]###`-Dauerschleife | `every()` mit eigenem Zustand, oder `engine_trigger()` |
| KNX-GA lesen/schreiben | HA-Entity (KNX-Integration) |

Nicht automatisch portierbar sind Bausteine mit eigener SQL-Tabelle, Socket-Servern
oder VSE-Anbindung – die brauchen echte Handarbeit.

## Mitgelieferte EDOMI-Portierungen (Presets)

Im App-Image liegen unter `engine/presets/` fertig portierte Logikbausteine. Beim
Start werden sie nach `script_dir` kopiert. Ob eine vorhandene Datei überschrieben
wird, entscheidet der md5 der zuletzt ausgelieferten Fassung in
`<script_dir>/.preset_hashes`:

| Zustand der Datei in `script_dir` | Verhalten |
| --- | --- |
| fehlt | wird installiert |
| identisch mit der letzten Auslieferung | wird bei neuer App-Version aktualisiert |
| vom Nutzer geändert | bleibt unangetastet, Hinweis im Log |
| aus einer App-Version ohne Hash-Verwaltung (und ohne `@preset-version`-Marker) | einmalige Aktualisierung |

### `20_edomi_solaranlage.php` — LBS "Solaranlage"

Portiert 1:1 (Reihenfolge, Asymmetrien und Hysterese wie im Original):

| Edomi | hier |
| --- | --- |
| E3 T_Kollektor | `sensor.stiebel_eltron_lwz_solarkollektortemperatur` |
| E4 T_Speicher_unten | `sensor.stiebel_eltron_lwz_ist_temperatur_warmwasser` |
| E1/E2 T_Diff EIN/AUS | Default 6 / 2 K, übersteuerbar via `input_number.solar_t_diff_ein` / `_aus` |
| E5/E6 T_max Kollektor/Speicher | Default 110 / 75 °C, via `input_number.solar_t_max_kollektor` / `_speicher` |
| E7 Zwangssteuerung | Default aus, via `input_boolean.solar_zwangssteuerung` |
| A1 Pumpe | `binary_sensor.solarpumpe_anforderung` (Pseudo-Sensor) + Event `solarpumpe_anforderung` |
| A2 Status T_Diff | `sensor.solar_temperaturdifferenz` (K) |

Sobald die Pumpe in HA eine echte Entity hat (z. B. `switch.solarpumpe`), im Skript
oben `'pumpe' => 'switch.solarpumpe'` eintragen – dann schaltet die Logik zusätzlich
direkt per `turn_on`/`turn_off` statt nur die Anforderung zu setzen.

Manuell prüfbar: `POST /api/trigger?name=solar_check`.

**Keine Top-Level-Deklarationen:** `function`/`class`/`const` auf oberster Ebene sind
nicht erlaubt – Skripte werden bei jedem Reload erneut eingebunden, eine zweite
Deklaration würde die Engine mit einem Fatalfehler beenden. Alles in Closures kapseln;
die Engine lehnt solche Dateien mit klarer Meldung ab, statt abzustürzen.

## HTTP-Endpunkte

Alles läuft im Engine-Prozess, Port **8099** (Ingress-Panel „PHP Automation"):

| Methode | Pfad | Zweck |
| --- | --- | --- |
| GET | `/health` | Watchdog (200 ok) |
| GET | `/` | Status-UI |
| GET | `/api/status` | kompletter Status als JSON |
| GET | `/api/log?lines=100` | Logzeilen |
| GET | `/api/scripts` | Datei + mtime je Skript |
| POST | `/api/reload` | Skripte neu laden |
| POST | `/api/trigger` | `{"name":"mein_trigger"}` auslösen |
| POST | `/api/run` | `{"script":"foo.php"}` einmalig testen |

Aus HA heraus (Port `8099/tcp` in der App-Konfiguration aktivieren):

```yaml
rest_command:
  php_engine_trigger:
    url: "http://local-php-automation-engine:8099/api/trigger"
    method: post
    payload: '{"name": "{{ name }}"}'
    content_type: "application/json"
```

## Verhalten & Grenzen

* **Poll-basiert:** Änderungen werden erst beim nächsten `poll_interval` erkannt
  (Standard 2 s). Für Sekundenbruchteile-Trigger ist das nichts.
* Bei ≤ 25 überwachten Entities wird pro Entity abgefragt, darüber die komplette
  `/states`-Liste.
* Fehler in einem Callback oder beim Laden eines Skripts werden geloggt, die Engine
  läuft weiter; fehlerhafte Skripte erscheinen in der UI rot.
* Zustände, die per `ha_set_state()` gesetzt werden, sind **Pseudo-States**: sie
  erscheinen als Entity, stehen aber nicht im Entity-Registry (kein Recorder-Verlauf
  im gleichen Umfang wie bei echten Integrationen).
* Die Engine ersetzt keine Integration – sie ist ein Ausführungsort für Logik.
