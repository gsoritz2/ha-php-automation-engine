# PHP-Automationen

Jede `*.php`-Datei in diesem Verzeichnis wird beim Start (und bei Änderung, wenn
Auto-Reload aktiv ist) geladen. Beim Laden registriert das Skript seine Trigger und
Timer; die Callbacks laufen danach im Engine-Prozess.

Kurzreferenz der API:

| Funktion | Zweck |
| --- | --- |
| `state_trigger($entity, $cb, $cond)` | auf Zustandsänderung reagieren (Poll-basiert) |
| `every('30s', $cb)` / `cron('0 7 * * *', $cb)` / `at('07:30', $cb)` | Zeitsteuerung |
| `on_start($cb)` | läuft nach jedem Laden/Reload einmal |
| `engine_trigger('name', $cb)` | per HTTP `POST /api/trigger?name=name` auslösbar |
| `ha_state('sensor.x')` / `ha_state_float()` / `ha_state_bool()` | Zustand lesen |
| `ha_call('light','turn_on',[...])` / `ha_call_entity('light.turn_on','light.x')` | Service aufrufen |
| `ha_set_state('sensor.x', $wert, [...])` | Pseudo-Sensor setzen |
| `ha_notify('Text', 'Titel')` | Benachrichtigung |
| `state_get('key')` / `state_set('key', $v)` | persistente Variablen (überleben Neustart) |
| `ko_get(1)` / `ko_set(20, true)` | Edomi-KO-Zugriff über `ko_map.json` |
| `log_info()` / `log_warn()` / `log_error()` / `log_debug()` | Logging |

Trigger-Bedingungen: `null`/`'changed'`, `'eq:on'`, `'not:off'`, `'from:off'`, `'to:on'`,
`'above:30'`, `'below:5'`, `'in:a,b,c'`, `'attr:brightness=255'` oder ein Callable
`fn($new, $old, $entity) => bool`.

Wichtig: Ein Fehler in einem Callback wird geloggt, beendet aber nicht die Engine.
