#!/usr/bin/env bash
# Testet die Vorgabe-Installation/-Aktualisierung aus run.sh (ohne Add-on/Container).
# Prueft: Neuanlage, Update einer unveraenderten Vorgabe, Schutz selbst angepasster
# Dateien und den Einmal-Update fuer Altbestaende ohne Hash.
#
# Aufruf: bash tests/test_presets.sh
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUN_SH="$HERE/../php_automation_engine/run.sh"
PHPENGINE_PRESET_DIR="$HERE/../php_automation_engine/engine/presets"
export PHPENGINE_PRESET_DIR

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
SD="$TMP/scripts"; mkdir -p "$SD"

# run.sh ausfuehrbar machen und die Engine-Schritte am Ende abschneiden:
# wir testen nur den Vorgabe-Teil, nicht den Engine-Start.
# -> run.sh wird mit einem Stub-PHP aufgerufen, der sofort endet.
STUB="$TMP/bin"; mkdir -p "$STUB"
printf '#!/bin/sh\nexit 0\n' > "$STUB/php"; chmod +x "$STUB/php"
export PATH="$STUB:$PATH"

PASS=0; FAIL=0
check() { # check <name> <ok 0/1> [detail]
    if [[ "$2" == "0" ]]; then PASS=$((PASS+1)); echo "  PASS  $1";
    else FAIL=$((FAIL+1)); echo "  FAIL  $1   [${3:-}]"; fi
}

run_addon() { # run_addon <script_dir>
    PHPENGINE_SCRIPT_DIR="$1" PHPENGINE_DATA_DIR="$TMP/data" PHPENGINE_HTTP_PORT=0 \
        PHPENGINE_EXAMPLES_DIR="$HERE/../php_automation_engine/engine/examples" \
        bash "$RUN_SH" 2>&1
}

# --- 1) Erstinstallation -----------------------------------------------------
out="$(run_addon "$SD")"
[[ -f "$SD/20_edomi_solaranlage.php" ]]; check "Vorgabe wird installiert" $? "keine Datei"
[[ -f "$SD/.preset_hashes" ]]; check "Hash-Datei wird angelegt" $? "keine .preset_hashes"
grep -q 'Vorgabe installiert: 20_edomi_solaranlage.php' <<<"$out"; check "Log nennt die Installation" $?
grep -q 'ko_map.json' <<< "$(ls "$SD")"; check "ko_map.json wird mit angelegt" $?

# --- 2) Zweiter Start: identisch -> kein Kopieren -----------------------------
cp "$SD/20_edomi_solaranlage.php" "$TMP/ref.php"
out="$(run_addon "$SD")"
grep -q 'Vorgabe aktualisiert' <<<"$out"; [[ $? != 0 ]]; check "Identische Vorgabe wird nicht angefasst" $?
cmp -s "$SD/20_edomi_solaranlage.php" "$TMP/ref.php"; check "Datei bleibt inhaltlich gleich" $?

# --- 3) Nutzer hat die Datei geaendert -> Update darf NICHT kommen ------------
echo "// eigene Anpassung des Nutzers" >> "$SD/20_edomi_solaranlage.php"
cp "$SD/20_edomi_solaranlage.php" "$TMP/user.php"
out="$(run_addon "$SD")"
grep -q 'selbst angepasst - Update wird NICHT eingespielt' <<<"$out"; check "Eigene Anpassung wird erkannt und gemeldet" $?
cmp -s "$SD/20_edomi_solaranlage.php" "$TMP/user.php"; check "Eigene Anpassung bleibt erhalten" $?

# --- 4) Unveraendert seit letzter Auslieferung, aber neue Fassung -> Update ----
# Nutzer-Anpassung zuruecknehmen (Zustand = wie ausgeliefert), Hash stimmt wieder
cp "$TMP/ref.php" "$SD/20_edomi_solaranlage.php"
# neue Fassung simulieren
NEWDIR="$TMP/presets-new"; mkdir -p "$NEWDIR"
printf '%s\n// neue Fassung\n' "$(cat "$TMP/ref.php")" > "$NEWDIR/20_edomi_solaranlage.php"
PHPENGINE_PRESET_DIR="$NEWDIR" run_addon "$SD" > "$TMP/out4" 2>&1
grep -q 'Vorgabe aktualisiert' "$TMP/out4"; check "Unveraenderte Vorgabe wird aktualisiert" $?
grep -q 'neue Fassung' "$SD/20_edomi_solaranlage.php"; check "Neue Fassung ist angekommen" $?

# --- 5) Altbestand ohne Hash (Vorgabe aus Version vor der Hash-Verwaltung) -----
rm -f "$SD/.preset_hashes"
printf '<?php\n// alte Fassung ohne Marker\n' > "$SD/20_edomi_solaranlage.php"
PHPENGINE_PRESET_DIR="$PHPENGINE_PRESET_DIR" run_addon "$SD" > "$TMP/out5" 2>&1
grep -q 'Altbestand ohne Hash' "$TMP/out5"; check "Altbestand ohne Hash wird einmalig aktualisiert" $?
grep -q '@preset-version' "$SD/20_edomi_solaranlage.php"; check "Ausgelieferte Fassung ist danach aktiv" $?

# --- 6) Zweiter Lauf danach: wieder geschuetzt --------------------------------
echo "// wieder eigene Anpassung" >> "$SD/20_edomi_solaranlage.php"
PHPENGINE_PRESET_DIR="$PHPENGINE_PRESET_DIR" run_addon "$SD" > "$TMP/out6" 2>&1
grep -q 'selbst angepasst' "$TMP/out6"; check "Nach dem Einmal-Update greift der Schutz wieder" $?

echo
echo "$PASS bestanden, $FAIL fehlgeschlagen"
[[ "$FAIL" == "0" ]]
