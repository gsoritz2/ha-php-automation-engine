<?php
/**
 * Beispiel 4: Live-Check / Selbsttest.
 *
 * Wird per HTTP ausgeloest und schreibt das Ergebnis als Pseudo-Sensor zurueck
 * nach Home Assistant - so laesst sich von aussen pruefen, ob die Engine
 * wirklich mit der HA-API spricht:
 *
 *   curl -X POST http://<addon>:8099/api/trigger?name=live_check
 *   -> sensor.php_engine_live_check wird "ok" mit Attributen
 */
declare(strict_types=1);

engine_trigger('live_check', function (): void {
    $sun = (string) ha_state('sun.sun', null, 'unbekannt');
    $cfg = ha()->config();

    ha_set_state('sensor.php_engine_live_check', 'ok', [
        'friendly_name' => 'PHP Engine Live-Check',
        'icon' => 'mdi:language-php',
        'letzter_aufruf' => date('c'),
        'sun_state' => $sun,
        'ha_version' => (string) ($cfg['version'] ?? '?'),
        'engine_php' => PHP_VERSION,
    ]);

    ha_event('php_engine_live_check', ['sun' => $sun, 'php' => PHP_VERSION]);
    log_info("LIVE-CHECK ok (sun.sun=$sun, HA " . ($cfg['version'] ?? '?') . ")");
});
