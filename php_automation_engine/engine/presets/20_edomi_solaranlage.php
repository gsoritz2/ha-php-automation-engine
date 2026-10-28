<?php
/**
 * EDOMI-Logikbaustein "Solaranlage" (1:1-Portierung) – PHP Automation Engine
 * =========================================================================
 *
 * Original (Edomi):
 *   ###[DEF]###
 *   [e#1 = T_Diff EIN  #init=6]     [e#2 = T_Diff AUS  #init=2]
 *   [e#3 = T_Kollektor]             [e#4 = T_Speicher_unten]
 *   [e#5 = T_max Kollektor #init=110] [e#6 = T_max Speicher #init=75]
 *   [e#7 = Zwangssteuerung]
 *   [a#1 = Pumpe]  [a#2 = Status T_Diff]
 *   ###[/DEF]###
 *
 * Portierte Logik (unverändert, inkl. Reihenfolge und Asymmetrien):
 *   1) E7==1 UND E4<=E6 UND E3<=E5                      -> A1 = EIN (und Ende)
 *   2) sonst, wenn E3 oder E4 neu eingetroffen ist:
 *        V1 = E3 - E4 ; A2 = V1
 *        wenn E4<=E6 UND E3<=E5:
 *            V1 >  E1  -> A1 = EIN
 *            V1 <= E2  -> A1 = AUS
 *        sonst: A1 = AUS
 *
 * Wichtig: Top-Level-Funktionen/-Klassen sind hier NICHT erlaubt – die Engine
 * bindet Skripte bei jedem Reload erneut ein, eine zweite Deklaration wäre ein
 * Fatalfehler. Deshalb ist alles in Closures gekapselt.
 *
 * Bewusste Abweichungen (dokumentiert):
 *   - Nicht lesbare Temperaturen (unavailable/unknown) führen zu "keine Aktion"
 *     statt zu einer Rechnung mit Unsinnswerten.
 *   - Der Ausgang wird nur bei Änderung geschrieben (kein Dauerfeuer auf den Bus).
 *   - Optional Mindestlaufzeit/Mindestpause (min_on/min_off, Standard 0 = wie Edomi).
 */
declare(strict_types=1);

$cfg = [
    // ---- E3 / E4 ---------------------------------------------------------
    'kollektor'      => 'sensor.stiebel_eltron_lwz_solarkollektortemperatur',
    'speicher_unten' => 'sensor.stiebel_eltron_lwz_ist_temperatur_warmwasser',

    // ---- A1 Pumpe --------------------------------------------------------
    // null = Pumpe hängt noch an Edomi/KNX: es wird nur die Anforderung gesetzt.
    // Sobald eine HA-Entity existiert, hier eintragen (z. B. 'switch.solarpumpe')
    // – dann schaltet die Logik zusätzlich direkt über turn_on/turn_off.
    'pumpe'          => null,
    'pumpe_soll'     => 'binary_sensor.solarpumpe_anforderung',

    // ---- A2 Status T_Diff ------------------------------------------------
    'status_delta'   => 'sensor.solar_temperaturdifferenz',

    // ---- E1/E2/E5/E6/E7 --------------------------------------------------
    // Default = Edomi-#init. Existiert der Helfer in HA, gewinnt dessen Wert.
    'e1' => ['default' => 6.0,   'helper' => 'input_number.solar_t_diff_ein',    'name' => 'T_Diff EIN'],
    'e2' => ['default' => 2.0,   'helper' => 'input_number.solar_t_diff_aus',    'name' => 'T_Diff AUS'],
    'e5' => ['default' => 110.0, 'helper' => 'input_number.solar_t_max_kollektor', 'name' => 'T_max Kollektor'],
    'e6' => ['default' => 75.0,  'helper' => 'input_number.solar_t_max_speicher', 'name' => 'T_max Speicher'],
    'e7' => ['default' => false, 'helper' => 'input_boolean.solar_zwangssteuerung', 'name' => 'Zwangssteuerung'],

    // ---- optional --------------------------------------------------------
    'min_on'  => 0,   // Sekunden Mindestlaufzeit (0 = wie Edomi)
    'min_off' => 0,   // Sekunden Mindestpause    (0 = wie Edomi)
];

// ---------------------------------------------------------------------------
// E-Eingänge lesen (Helfer hat Vorrang, sonst #init-Default)
// ---------------------------------------------------------------------------
$input = static function (string $key) use ($cfg) {
    $spec = $cfg[$key];
    $helper = $spec['helper'];
    if ($helper !== null) {
        $raw = ha_state($helper);
        if ($raw !== null && !in_array(strtolower((string) $raw), ['unknown', 'unavailable', ''], true)) {
            if (is_bool($spec['default'])) {
                return ha_state_bool($helper, (bool) $spec['default']);
            }
            return is_numeric($raw) ? (float) $raw : (float) $spec['default'];
        }
    }
    return $spec['default'];
};

// Temperatur lesen; null, wenn nicht verfügbar
$temp = static function (string $entity): ?float {
    $raw = ha_state($entity);
    if ($raw === null || !is_numeric($raw)) {
        return null;
    }
    return (float) $raw;
};

// ---------------------------------------------------------------------------
// A1 schreiben – nur bei Änderung, inkl. optionaler Sperrzeiten
// ---------------------------------------------------------------------------
$set_pump = static function (bool $on, string $reason) use ($cfg): void {
    $pseudo = $cfg['pumpe_soll'];
    $cur = ha_state($pseudo);
    $is_on = is_string($cur) && in_array(strtolower($cur), ['on', 'true', '1'], true);
    if ($cur !== null && $is_on === $on) {
        log_debug('A1 Pumpe bleibt ' . ($on ? 'EIN' : 'AUS') . " ($reason)");
        return;
    }

    $last = state_get('solar_letzter_ausgang', null);
    $lastTs = (float) state_get('solar_letzte_schaltung_ts', 0);
    if (is_bool($last) && $last !== $on) {
        $elapsed = time() - $lastTs;
        if ($last === true && $cfg['min_on'] > 0 && $elapsed < $cfg['min_on']) {
            log_info(sprintf('A1 Ausschalten unterdrückt (Mindestlaufzeit %ds, %ds vergangen)', $cfg['min_on'], $elapsed));
            return;
        }
        if ($last === false && $cfg['min_off'] > 0 && $elapsed < $cfg['min_off']) {
            log_info(sprintf('A1 Einschalten unterdrückt (Mindestpause %ds, %ds vergangen)', $cfg['min_off'], $elapsed));
            return;
        }
    }

    ha_set_state($pseudo, $on ? 'on' : 'off', [
        'friendly_name' => 'Solarpumpe Anforderung',
        'device_class' => 'running',
        'icon' => 'mdi:pump',
        'grund' => $reason,
        'zeit' => date('c'),
    ]);

    // Falls eine echte Entity konfiguriert ist: zusätzlich schalten
    if (is_string($cfg['pumpe']) && $cfg['pumpe'] !== '') {
        [$domain] = explode('.', $cfg['pumpe'], 2);
        try {
            ha_call_entity($domain . '.' . ($on ? 'turn_on' : 'turn_off'), $cfg['pumpe']);
        } catch (Throwable $e) {
            log_error('Pumpe konnte nicht geschaltet werden: ' . $e->getMessage());
        }
    }

    ha_event('solarpumpe_anforderung', ['an' => $on, 'grund' => $reason]);
    state_set('solar_letzter_ausgang', $on);
    state_set('solar_letzte_schaltung_ts', time());
    log_info(sprintf('A1 Pumpe -> %s (%s)', $on ? 'EIN' : 'AUS', $reason));
};

// ---------------------------------------------------------------------------
// Der Logikbaustein selbst
// $refresh_temps entspricht "E3.refresh==1 || E4.refresh==1" aus Edomi
// ---------------------------------------------------------------------------
$lbs = static function (bool $refresh_temps) use ($cfg, $input, $temp, $set_pump): void {
    $e1 = (float) $input('e1');
    $e2 = (float) $input('e2');
    $e5 = (float) $input('e5');
    $e6 = (float) $input('e6');
    $e7 = (bool) $input('e7');

    $e3 = $temp($cfg['kollektor']);
    $e4 = $temp($cfg['speicher_unten']);
    if ($e3 === null || $e4 === null) {
        log_warn('Solaranlage: Kollektor- oder Speichertemperatur nicht lesbar – keine Aktion');
        return;
    }

    // 1) Zwangssteuerung
    if ($e7 && $e4 <= $e6 && $e3 <= $e5) {
        $set_pump(true, sprintf('Zwangssteuerung (K %.1f<=%.1f, S %.1f<=%.1f)', $e3, $e5, $e4, $e6));
        return;
    }

    // 2) nur wenn E3/E4 neu eingetroffen sind
    if (!$refresh_temps) {
        return;
    }

    $v1 = $e3 - $e4;

    // A2 Status T_Diff
    ha_set_state($cfg['status_delta'], round($v1, 2), [
        'friendly_name' => 'Solar T-Diff (Kollektor - Speicher)',
        'unit_of_measurement' => 'K',
        'icon' => 'mdi:thermometer-lines',
        'kollektor' => $e3,
        'speicher_unten' => $e4,
        'zeit' => date('c'),
    ]);

    if ($e4 <= $e6 && $e3 <= $e5) {
        if ($v1 > $e1) {
            $set_pump(true, sprintf('T-Diff %.2f K > EIN-Schwelle %.1f K', $v1, $e1));
        }
        if ($v1 <= $e2) {
            $set_pump(false, sprintf('T-Diff %.2f K <= AUS-Schwelle %.1f K', $v1, $e2));
        }
    } else {
        $set_pump(false, sprintf('Grenzwert erreicht (K %.1f/%.1f, S %.1f/%.1f)', $e3, $e5, $e4, $e6));
    }
};

// ---------------------------------------------------------------------------
// Anbindung an die Engine
// ---------------------------------------------------------------------------
$triggerEntities = [$cfg['kollektor'], $cfg['speicher_unten']];
foreach (['e1', 'e2', 'e5', 'e6', 'e7'] as $k) {
    $triggerEntities[] = $cfg[$k]['helper'];
}

state_trigger($triggerEntities, static function (string $new, string $old, string $entity) use ($cfg, $lbs): void {
    $refresh = ($entity === $cfg['kollektor'] || $entity === $cfg['speicher_unten']);
    $lbs($refresh);
});

// Beim (Neu-)Laden einmal auswerten, damit der Ausgang konsistent ist
on_start(static function () use ($lbs, $cfg): void {
    $lbs(true);
    log_info('EDOMI-LBS Solaranlage aktiv: E3=' . $cfg['kollektor'] . ' | E4=' . $cfg['speicher_unten']
        . ' | A1=' . ($cfg['pumpe'] ?? $cfg['pumpe_soll']));
});

// Manuell prüfbar: curl -X POST http://<addon>:8099/api/trigger?name=solar_check
engine_trigger('solar_check', static function () use ($lbs): void {
    $lbs(true);
});
