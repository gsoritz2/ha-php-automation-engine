#!/usr/bin/env bash
# Startskript des Add-ons. Liest /data/options.json und startet die Engine im Vordergrund.
set -euo pipefail

# /data ist im Add-on das persistente Verzeichnis des Supervisors;
# PHPENGINE_DATA_DIR erlaubt Tests ausserhalb des Containers.
DATA_DIR="${PHPENGINE_DATA_DIR:-/data}"
OPTIONS="$DATA_DIR/options.json"
opt() { # opt <key> <default>
    if [[ -f "$OPTIONS" ]]; then
        jq -r --arg k "$1" --arg d "$2" 'if (.[$k] // null) == null then $d else (.[$k] | tostring) end' "$OPTIONS" 2>/dev/null || echo "$2"
    else
        echo "$2"
    fi
}

# PHPENGINE_SCRIPT_DIR hat Vorrang (Tests / abweichende Installationen),
# sonst gilt die App-Option script_dir.
SCRIPT_DIR="${PHPENGINE_SCRIPT_DIR:-$(opt script_dir /share/php_automations)}"
POLL_INTERVAL="$(opt poll_interval 2.0)"
LOG_LEVEL="$(opt log_level info)"
LOG_LINES="$(opt log_lines 500)"
TZ_OPT="$(opt timezone Europe/Vienna)"
KO_MAP="$(opt ko_map ko_map.json)"
AUTO_RELOAD="$(opt auto_reload true)"

export TZ="$TZ_OPT"
export PHPENGINE_SCRIPT_DIR="$SCRIPT_DIR"
export PHPENGINE_POLL_INTERVAL="$POLL_INTERVAL"
export PHPENGINE_LOG_LEVEL="$LOG_LEVEL"
export PHPENGINE_LOG_LINES="$LOG_LINES"
export PHPENGINE_KO_MAP="$KO_MAP"
export PHPENGINE_AUTO_RELOAD="$AUTO_RELOAD"
export PHPENGINE_LOG="${PHPENGINE_LOG:-$DATA_DIR/engine.log}"
export PHPENGINE_HTTP_PORT="${PHPENGINE_HTTP_PORT:-8099}"

mkdir -p "$DATA_DIR" "$SCRIPT_DIR"

# Beispiel-Automationen nur beim allererstmaligen Start ausrollen
EXAMPLES_DIR="${PHPENGINE_EXAMPLES_DIR:-/app/examples}"
if [[ ! -f "$SCRIPT_DIR/.seeded" ]]; then
    cp -n "$EXAMPLES_DIR"/*.php "$SCRIPT_DIR"/ 2>/dev/null || true
    cp -n "$EXAMPLES_DIR"/ko_map.example.json "$SCRIPT_DIR"/ 2>/dev/null || true
    cp -n "$EXAMPLES_DIR"/README.md "$SCRIPT_DIR"/ 2>/dev/null || true
    touch "$SCRIPT_DIR/.seeded"
    echo "[run.sh] Beispiel-Automationen nach $SCRIPT_DIR kopiert"
fi

# Arbeitskopie der KO-Map immer vorhalten (auch bei bestehenden Installationen),
# damit ko_get()/ko_set() nicht bei jedem Zugriff warnen
if [[ ! -f "$SCRIPT_DIR/ko_map.json" ]]; then
    cp "$EXAMPLES_DIR/ko_map.example.json" "$SCRIPT_DIR/ko_map.json" 2>/dev/null || true
    echo "[run.sh] ko_map.json aus dem Beispiel angelegt"
fi

# ---------------------------------------------------------------------------
# Mitgelieferte Vorgabe-Skripte (z. B. portierte EDOMI-Logikbausteine).
#
# Regel: eine Vorgabe wird installiert bzw. aktualisiert, solange der Nutzer sie
# nicht selbst angepasst hat. Nachgehalten wird das ueber den md5 der zuletzt
# ausgelieferten Fassung in $SCRIPT_DIR/.preset_hashes.
#   - Datei fehlt                          -> installieren
#   - md5 stimmt mit der ausgelieferten ueberein -> aktualisieren (unangetastet)
#   - md5 weicht ab (Nutzer hat editiert)  -> unveraendert lassen, nur Hinweis
#   - kein Hash vermerkt und ohne Marker (Altbestand vor Hash-Verwaltung) -> einmalig aktualisieren
# Override fuer Tests: PHPENGINE_PRESET_DIR
# ---------------------------------------------------------------------------
PRESET_DIR="${PHPENGINE_PRESET_DIR:-/app/presets}"
PRESET_STATE="$SCRIPT_DIR/.preset_hashes"

preset_hash() {
    if command -v md5sum >/dev/null 2>&1; then md5sum "$1" | awk '{print $1}'; else md5 -q "$1"; fi
}

if [[ -d "$PRESET_DIR" ]]; then
    touch "$PRESET_STATE"
    for f in "$PRESET_DIR"/*.php; do
        [[ -e "$f" ]] || continue
        base="$(basename "$f")"
        target="$SCRIPT_DIR/$base"
        new_hash="$(preset_hash "$f")"
        old_hash="$(awk -v k="$base" '$1 == k {print $2}' "$PRESET_STATE")"
        # Marker im Ziel pruefen: fehlt er UND ist kein Hash vermerkt, stammt die Datei
        # aus einer Engine-Version vor der Hash-Verwaltung -> einmalig aktualisieren.
        # (grep -c liefert bei 0 Treffern "0" und Exitcode 1 -> Ergebnis separat absichern)
        marker_count="$(grep -c '@preset-version' "$target" 2>/dev/null)" || true
        marker_count="${marker_count:-0}"
        apply=0

        if [[ ! -f "$target" ]]; then
            cp "$f" "$target" && echo "[run.sh] Vorgabe installiert: $base"
            apply=1
        elif [[ -n "$old_hash" && "$(preset_hash "$target")" == "$old_hash" && "$old_hash" != "$new_hash" ]]; then
            cp "$f" "$target" && echo "[run.sh] Vorgabe aktualisiert: $base (unveraendert seit letzter Auslieferung)"
            apply=1
        elif [[ -z "$old_hash" && "$marker_count" == "0" ]]; then
            cp "$f" "$target" && echo "[run.sh] Vorgabe aktualisiert: $base (Altbestand ohne Hash) "
            apply=1
        elif [[ "$(preset_hash "$target")" == "$new_hash" ]]; then
            apply=2   # identisch - nur Hash vermerken
        else
            echo "[run.sh] Vorgabe $base ist selbst angepasst - Update wird NICHT eingespielt"
        fi

        if [[ "$apply" != 0 ]]; then
            awk -v k="$base" '$1 != k' "$PRESET_STATE" > "$PRESET_STATE.tmp" && mv "$PRESET_STATE.tmp" "$PRESET_STATE"
            echo "$base $new_hash" >> "$PRESET_STATE"
        fi
    done
fi

echo "[run.sh] PHP $(php -r 'echo PHP_VERSION;') | Skripte: $SCRIPT_DIR | Poll: ${POLL_INTERVAL}s | Log: $LOG_LEVEL"
echo "[run.sh] API-Basis: ${HA_API_BASE:-http://supervisor/core/api}"

# PHP-Builtin-Server brauchen wir nicht: die Engine bringt ihren eigenen HTTP-Server mit.
exec php /app/engine.php
