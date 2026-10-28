<?php
/**
 * EDOMI-Logikbaustein "Solaranlage" (1:1-Portierung) – PHP Automation Engine
 * =========================================================================
 * @preset-version 2
 *
 * ORIGINAL (Edomi-DEF):
 *   ###[DEF]###
 *   [name = Solaranlage]
 *   [e#1 = T_Diff EIN       #init=6]    [e#2 = T_Diff AUS      #init=2]
 *   [e#3 = T_Kollektor]                 [e#4 = T_Speicher_unten]
 *   [e#5 = T_max Kollektor  #init=110]  [e#6 = T_max Speicher  #init=75]
 *   [e#7 = Zwangssteuerung]
 *   [a#1 = Pumpe]                       [a#2 = Status T_Diff]
 *   [v#1 = 0]
 *   ###[/DEF]###
 *
 * ORIGINAL (Edomi-LBS), Zeile für Zeile portiert:
 *   function LB_LBSID($id) {
 *       if ($E=getLogicEingangDataAll($id)) {
 *           If ($E[7]['value']==1 && $E[4]['value']<=$E[6]['value'] && $E[3]['value']<=$E[5]['value']) {
 *               setLogicLinkAusgang($id,1,1);
 *           }
 *           else {
 *               If ($E[3]['refresh']==1 || $E[4]['refresh']==1) {
 *                   $v1 = $E[3]['value'] - $E[4]['value'];
 *                   setLogicLinkAusgang($id,2,$v1);
 *                   IF ($E[4]['value']<=$E[6]['value'] && $E[3]['value']<=$E[5]['value']) {
 *                       If ($v1 >  $E[1]['value']) {setLogicLinkAusgang($id,1,1);}
 *                       If ($v1 <= $E[2]['value']) {setLogicLinkAusgang($id,1,0);}
 *                   }
 *                   else {setLogicLinkAusgang($id,1,0);}
 *               }
 *           }
 *       }
 *   }
 *
 * MAPPING Edomi -> hier:
 *   $E[n]['value']               -> $input('eN') bzw. $temp(<entity>)
 *   $E[n]['refresh']             -> das $entity-Argument des state_trigger-Callbacks
 *                                   (nur die gerade geänderte Entity hat "refresh")
 *   setLogicLinkAusgang($id,1,x) -> $set_pump(true|false, <Grund>)
 *   setLogicLinkAusgang($id,2,$v1)-> ha_set_state('<delta-entity>', $v1)
 *   #init-Werte                  -> 'default' im $cfg-Array (per Helfer übersteuerbar)
 *
 * Top-Level-Funktionen/-Klassen sind hier NICHT erlaubt: die Engine bindet Skripte
 * bei jedem Reload erneut ein – eine zweite Deklaration wäre ein Fatalfehler.
 * Deshalb ist die gesamte Logik in Closures gekapselt.
 */
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Konfiguration: Entities und Vorgabewerte.
// ---------------------------------------------------------------------------
$cfg = [
    // --- E3 T_Kollektor / E4 T_Speicher_unten ------------------------------
    'kollektor'      => 'sensor.stiebel_eltron_lwz_solarkollektortemperatur',
    'speicher_unten' => 'sensor.stiebel_eltron_lwz_ist_temperatur_warmwasser',

    // --- A1 Pumpe ----------------------------------------------------------
    // null        = Pumpe hängt (noch) an Edomi/KNX: es wird nur die Anforderung
    //               als Pseudo-Sensor + Event geschrieben.
    // 'switch.xy' = HA-Entity eintragen, dann schaltet die Logik zusätzlich
    //               direkt per turn_on/turn_off (Anforderung wird trotzdem gesetzt).
    'pumpe'          => null,
    'pumpe_soll'     => 'binary_sensor.solarpumpe_anforderung',

    // --- A2 Status T_Diff --------------------------------------------------
    'status_delta'   => 'sensor.solar_temperaturdifferenz',

    // --- E1/E2/E5/E6/E7 ----------------------------------------------------
    // 'default' entspricht dem Edomi-#init. Liefert der Helfer in HA einen
    // gültigen Wert, gewinnt der Helfer – sonst gilt der Default.
    'e1' => ['default' => 6.0,   'helper' => 'input_number.solar_t_diff_ein',      'name' => 'T_Diff EIN'],
    'e2' => ['default' => 2.0,   'helper' => 'input_number.solar_t_diff_aus',      'name' => 'T_Diff AUS'],
    'e5' => ['default' => 110.0, 'helper' => 'input_number.solar_t_max_kollektor', 'name' => 'T_max Kollektor'],
    'e6' => ['default' => 75.0,  'helper' => 'input_number.solar_t_max_speicher',  'name' => 'T_max Speicher'],
    'e7' => ['default' => false, 'helper' => 'input_boolean.solar_zwangssteuerung', 'name' => 'Zwangssteuerung'],

    // --- optional (nicht im Edomi-Original) --------------------------------
    'min_on'  => 0,   // Sekunden Mindestlaufzeit der Pumpe (0 = Edomi-Verhalten)
    'min_off' => 0,   // Sekunden Mindestpause der Pumpe    (0 = Edomi-Verhalten)
];

// ---------------------------------------------------------------------------
// E-Eingänge lesen – entspricht $E[n]['value'] inkl. #init:
// Helfer hat Vorrang, sonst der Default. Ungültige Helfer-Werte
// (unknown/unavailable/leer) fallen ebenfalls auf den Default zurück.
// ---------------------------------------------------------------------------
$input = static function (string $key) use ($cfg) {
    $spec = $cfg[$key];                       // z. B. ['default' => 6.0, 'helper' => 'input_number...']
    $helper = $spec['helper'];                // Entity-ID des Helfers (kann null sein)
    if ($helper !== null) {
        $raw = ha_state($helper);             // Helfer-Zustand über die HA-API lesen
        // Nur echte Werte akzeptieren – "unknown"/"unavailable" heißt: fehlt/startet noch
        if ($raw !== null && !in_array(strtolower((string) $raw), ['unknown', 'unavailable', ''], true)) {
            if (is_bool($spec['default'])) {
                return ha_state_bool($helper, (bool) $spec['default']);   // E7: Ja/Nein-Eingang
            }
            return is_numeric($raw) ? (float) $raw : (float) $spec['default'];
        }
    }
    return $spec['default'];                  // entspricht dem Edomi-#init-Wert
};

// ---------------------------------------------------------------------------
// Temperatur lesen. Rückgabe null, wenn kein gültiger Zahlenwert anliegt
// (Sensor weg/unavailable) – dann rechnet die Logik NICHT weiter. Das ist eine
// bewusste Abweichung: das Original würde mit dem ungültigen Wert weiterrechnen.
// ---------------------------------------------------------------------------
$temp = static function (string $entity): ?float {
    $raw = ha_state($entity);                 // entspricht $E[3]['value'] / $E[4]['value']
    if ($raw === null || !is_numeric($raw)) {
        return null;                          // kein brauchbarer Messwert
    }
    return (float) $raw;
};

// ---------------------------------------------------------------------------
// A1 schreiben – entspricht setLogicLinkAusgang($id, 1, <0|1>).
// Zusätzlich gegenüber dem Original:
//   - nur bei tatsächlicher Änderung schreiben (kein Dauerfeuer auf Bus/API)
//   - optionale Sperrzeiten min_on/min_off
//   - Grund + Zeitstempel als Attribute, Event "solarpumpe_anforderung"
// ---------------------------------------------------------------------------
$set_pump = static function (bool $on, string $reason) use ($cfg): void {
    $pseudo = $cfg['pumpe_soll'];

    // Aktuellen Ausgangszustand ermitteln (Pseudo-Sensor bzw. echte Entity).
    // ha_state() liest nicht abonnierte Entities frisch über die API.
    $cur = ha_state($pseudo);
    $is_on = is_string($cur) && in_array(strtolower($cur), ['on', 'true', '1'], true);

    // Zustand ist bereits wie gewünscht -> nichts tun (verhindert Schreibschleifen).
    if ($cur !== null && $is_on === $on) {
        log_debug('A1 Pumpe bleibt ' . ($on ? 'EIN' : 'AUS') . " ($reason)");
        return;
    }

    // Sperrzeiten: letzter Ausgangszustand + Zeitpunkt liegen persistent
    // (überleben Reload und Neustart) im Skript-Zustandsspeicher.
    $last = state_get('solar_letzter_ausgang', null);
    $lastTs = (float) state_get('solar_letzte_schaltung_ts', 0);
    if (is_bool($last) && $last !== $on) {
        $elapsed = time() - $lastTs;
        if ($last === true && $cfg['min_on'] > 0 && $elapsed < $cfg['min_on']) {
            log_info(sprintf('A1 Ausschalten unterdrückt (Mindestlaufzeit %ds, %ds vergangen)', $cfg['min_on'], $elapsed));
            return;                           // Ausschaltbefehl verwerfen
        }
        if ($last === false && $cfg['min_off'] > 0 && $elapsed < $cfg['min_off']) {
            log_info(sprintf('A1 Einschalten unterdrückt (Mindestpause %ds, %ds vergangen)', $cfg['min_off'], $elapsed));
            return;                           // Einschaltbefehl verwerfen
        }
    }

    // Pseudo-Sensor schreiben: 'on'/'off' samt Grund und Zeitstempel.
    ha_set_state($pseudo, $on ? 'on' : 'off', [
        'friendly_name' => 'Solarpumpe Anforderung',
        'device_class' => 'running',
        'icon' => 'mdi:pump',
        'grund' => $reason,
        'zeit' => date('c'),
    ]);

    // Falls eine echte Entity konfiguriert ist: zusätzlich direkt schalten.
    // Domain aus der Entity ableiten (switch. -> switch.turn_on, light. -> light.turn_on).
    if (is_string($cfg['pumpe']) && $cfg['pumpe'] !== '') {
        [$domain] = explode('.', $cfg['pumpe'], 2);
        try {
            ha_call_entity($domain . '.' . ($on ? 'turn_on' : 'turn_off'), $cfg['pumpe']);
        } catch (Throwable $e) {
            log_error('Pumpe konnte nicht geschaltet werden: ' . $e->getMessage());
        }
    }

    // Event für HA-Automationen (z. B. Weiterleitung auf eine KNX-Gruppenadresse),
    // solange Edomi/KNX die Pumpe steuert.
    ha_event('solarpumpe_anforderung', ['an' => $on, 'grund' => $reason]);

    // Eigenen Ausgangszustand merken (für die Sperrzeiten) und protokollieren.
    state_set('solar_letzter_ausgang', $on);
    state_set('solar_letzte_schaltung_ts', time());
    log_info(sprintf('A1 Pumpe -> %s (%s)', $on ? 'EIN' : 'AUS', $reason));
};

// ---------------------------------------------------------------------------
// Der Logikbaustein selbst.
// $refresh_temps entspricht exakt "E3.refresh==1 || E4.refresh==1" aus Edomi:
// true nur, wenn der Trigger ausgelöst wurde, weil E3 oder E4 sich geändert hat.
// ---------------------------------------------------------------------------
$lbs = static function (bool $refresh_temps) use ($cfg, $input, $temp, $set_pump): void {
    // Alle Eingänge einlesen (E1..E7) – Reihenfolge wie im Original.
    $e1 = (float) $input('e1');               // T_Diff EIN
    $e2 = (float) $input('e2');               // T_Diff AUS
    $e5 = (float) $input('e5');               // T_max Kollektor
    $e6 = (float) $input('e6');               // T_max Speicher
    $e7 = (bool) $input('e7');                // Zwangssteuerung

    $e3 = $temp($cfg['kollektor']);           // T_Kollektor
    $e4 = $temp($cfg['speicher_unten']);      // T_Speicher_unten

    // Schutz: ohne gültige Temperaturen keine Entscheidung treffen
    // (das Original würde hier mit ungültigen/0-Werten rechnen).
    if ($e3 === null || $e4 === null) {
        log_warn('Solaranlage: Kollektor- oder Speichertemperatur nicht lesbar – keine Aktion');
        return;
    }

    // =======================================================================
    // 1) Erster Zweig des Originals:
    //      If ($E[7]['value']==1 && $E[4]['value']<=$E[6]['value'] && $E[3]['value']<=$E[5]['value'])
    //          setLogicLinkAusgang($id,1,1);
    //    -> Zwangssteuerung schaltet EIN, sofern beide Grenzwerte eingehalten sind.
    //    Wichtig: dieser Zweig hat im Original KEIN "else: AUS" und beendet die
    //    Auswertung (also auch kein A2-Update) – beides ist hier genauso übernommen.
    // =======================================================================
    if ($e7 && $e4 <= $e6 && $e3 <= $e5) {
        $set_pump(true, sprintf('Zwangssteuerung (K %.1f<=%.1f, S %.1f<=%.1f)', $e3, $e5, $e4, $e6));
        return;                               // entspricht dem Ende des if-Zweigs
    }

    // =======================================================================
    // 2) Sonst: nur auswerten, wenn E3 oder E4 neu eingetroffen ist
    //      If ($E[3]['refresh']==1 || $E[4]['refresh']==1) { ... }
    //    Änderungen an E1/E2/E5/E6/E7 allein lösen also KEINE Schaltung aus.
    // =======================================================================
    if (!$refresh_temps) {
        return;
    }

    // $v1 = $E[3]['value'] - $E[4]['value'];  -> Temperaturdifferenz Kollektor - Speicher
    $v1 = $e3 - $e4;

    // setLogicLinkAusgang($id,2,$v1);  -> A2 Status T_Diff
    // (wird wie im Original nur bei einem E3/E4-Refresh aktualisiert)
    ha_set_state($cfg['status_delta'], round($v1, 2), [
        'friendly_name' => 'Solar T-Diff (Kollektor - Speicher)',
        'unit_of_measurement' => 'K',
        'icon' => 'mdi:thermometer-lines',
        'kollektor' => $e3,                   // Rohwerte als Attribute für die Diagnose
        'speicher_unten' => $e4,
        'zeit' => date('c'),
    ]);

    // IF ($E[4]['value']<=$E[6]['value'] && $E[3]['value']<=$E[5]['value'])
    //    -> Speicher noch nicht am Limit UND Kollektor noch nicht am Limit
    if ($e4 <= $e6 && $e3 <= $e5) {
        // If ($v1 >  $E[1]['value']) {setLogicLinkAusgang($id,1,1);}  -> Pumpe EIN
        if ($v1 > $e1) {
            $set_pump(true, sprintf('T-Diff %.2f K > EIN-Schwelle %.1f K', $v1, $e1));
        }
        // If ($v1 <= $E[2]['value']) {setLogicLinkAusgang($id,1,0);}  -> Pumpe AUS
        // Zwischen E2 und E1 passiert absichtlich nichts -> das ist die Hysterese.
        if ($v1 <= $e2) {
            $set_pump(false, sprintf('T-Diff %.2f K <= AUS-Schwelle %.1f K', $v1, $e2));
        }
    } else {
        // else {setLogicLinkAusgang($id,1,0);}  -> Grenzwert erreicht: Pumpe AUS
        $set_pump(false, sprintf('Grenzwert erreicht (K %.1f/%.1f, S %.1f/%.1f)', $e3, $e5, $e4, $e6));
    }
};

// ---------------------------------------------------------------------------
// Anbindung an die Engine: E1..E7 als Trigger-Entities registrieren.
// Der Poll der Engine meldet jede Änderung an den Callback – das ersetzt die
// Edomi-Ausführung "bei jedem Refresh eines Eingangs".
// ---------------------------------------------------------------------------
$triggerEntities = [$cfg['kollektor'], $cfg['speicher_unten']];       // E3, E4
foreach (['e1', 'e2', 'e5', 'e6', 'e7'] as $k) {
    $triggerEntities[] = $cfg[$k]['helper'];                          // E1, E2, E5, E6, E7
}

state_trigger($triggerEntities, static function (string $new, string $old, string $entity) use ($cfg, $lbs): void {
    // $entity ist die gerade geänderte Entity -> das ist das "refresh"-Signal.
    // Nur bei E3/E4 darf der Differenz-/Hysterese-Teil rechnen.
    $refresh = ($entity === $cfg['kollektor'] || $entity === $cfg['speicher_unten']);
    $lbs($refresh);
});

// Beim (Neu-)Laden der Skripte einmal auswerten, damit Ausgang und A2 sofort
// einen gültigen Stand haben (entspricht dem ersten Durchlauf nach Edomi-Start).
on_start(static function () use ($lbs, $cfg): void {
    $lbs(true);
    log_info('EDOMI-LBS Solaranlage aktiv: E3=' . $cfg['kollektor'] . ' | E4=' . $cfg['speicher_unten']
        . ' | A1=' . ($cfg['pumpe'] ?? $cfg['pumpe_soll']));
});

// Manuell auslösbar zum Testen:
//   curl -X POST http://<addon>:8099/api/trigger?name=solar_check
engine_trigger('solar_check', static function () use ($lbs): void {
    $lbs(true);                           // wie ein E3/E4-Refresh
});
