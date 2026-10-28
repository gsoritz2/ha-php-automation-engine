<?php
/**
 * Beispiel 3: Edomi-Stil mit KO-Nummern.
 *
 * Zeigt: ko_get() / ko_set() ueber die Zuordnung in ko_map.json.
 * So lassen sich portierte Logikbausteine erst einmal 1:1 laufen lassen -
 * die KO-Nummern bleiben im Code, die Zuordnung liegt in ko_map.json.
 */
declare(strict_types=1);

// Pumpe einschalten, wenn der Pool zu warm wird (KO 20 = switch.pool_pumpe)
every('30s', function (): void {
    $poolTemp = ko_get(1);                       // KO 1 = sensor.pool_temperatur
    if ($poolTemp === null) {
        return;
    }
    $ist = ko_bool(20);                          // Zustand der Pumpe
    if ($poolTemp > 28.0 && !$ist) {
        ko_set(20, true);                        // -> switch.turn_on
        log_info("Pool bei {$poolTemp} °C – Pumpe an (KO 20)");
    } elseif ($poolTemp < 26.0 && $ist) {
        ko_set(20, false);                       // -> switch.turn_off
        log_info("Pool bei {$poolTemp} °C – Pumpe aus (KO 20)");
    }
}, 'pool_pumpe_regelung');

// Edomi-Variablen-Ersatz: state_get()/state_set() bleiben ueber Neustarts erhalten
on_start(function (): void {
    log_info('Beispiel-EDOMI-Logik gestartet, letzter Lauf: ' . (string) state_get('edomi_letzter_lauf', 'nie'));
    state_set('edomi_letzter_lauf', date('c'));
});
