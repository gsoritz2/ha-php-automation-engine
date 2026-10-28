<?php
/**
 * Beispiel 2: Auf Zustaende reagieren.
 *
 * Zeigt: state_trigger() mit Bedingungen, ha_call(), Benachrichtigungen.
 * Diese Datei ist ein Beispiel und darf geloescht werden.
 */
declare(strict_types=1);

// Tuer geht auf (jede Aenderung)
state_trigger('binary_sensor.haustuer', function (string $new, string $old, string $entity): void {
    log_info("$entity: $old -> $new");
    if ($new === 'on') {
        ha_notify('Die Haustür wurde geöffnet.', 'PHP Engine');
    }
});

// Nur wenn es nach 22:00 dunkel wird -> Licht
state_trigger('sun.sun', function (): void {
    $hour = (int) date('G');
    if ($hour >= 22 || $hour < 5) {
        log_info('Sonne untergegangen, es ist Nacht – Licht an (Beispiel)');
        // ha_call_entity('light.turn_on', 'light.flur', ['brightness_pct' => 30]);
    }
}, 'eq:below_horizon');

// Temperaturwarnung nur oberhalb 30 Grad
state_trigger('sensor.aussen_temperatur', function (string $new): void {
    ha_notify("Es ist heiß: {$new} °C", 'PHP Engine');
}, 'above:30');

// Von Hand per HTTP ausloesbar:
//   curl -X POST http://<addon>:8099/api/trigger?name=beispiel_test
engine_trigger('beispiel_test', function (): void {
    log_info('Benannter Trigger "beispiel_test" wurde ausgelöst');
});
