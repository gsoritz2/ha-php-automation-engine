<?php
/**
 * Beispiel 1: Timer.
 *
 * Zeigt: every() / cron() / at(), Pseudo-Sensoren setzen, persistenten Zustand.
 * Diese Datei ist ein Beispiel und darf geloescht werden.
 */
declare(strict_types=1);

// Alle 60 Sekunden einen Wert berechnen und als Pseudo-Sensor bereitstellen
every('60s', function (): void {
    $count = (int) state_get('beispiel_timer_laeufe', 0) + 1;
    state_set('beispiel_timer_laeufe', $count);

    $temp = ha_state_float('sensor.aussen_temperatur', -99.0);
    ha_set_state('sensor.php_engine_laeufe', $count, [
        'friendly_name' => 'PHP Engine Läufe',
        'unit_of_measurement' => 'x',
        'icon' => 'mdi:language-php',
    ]);
    log_info("Beispiel-Timer Lauf #$count (Aussentemperatur: $temp)");
}, 'beispiel_60s');

// Taeglich um 07:30
at('07:30', function (): void {
    log_info('Guten Morgen – Beispiel-Automation um 07:30');
}, 'beispiel_morgen');

// Werktags um 06:00 (Mo-Fr = Cron-Feld 5: 1-5)
cron('0 6 * * 1-5', function (): void {
    log_info('Werktags 06:00 – Beispiel-Automation');
}, 'beispiel_werktags');
