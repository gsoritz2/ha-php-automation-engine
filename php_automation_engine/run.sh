#!/usr/bin/env bash
# Startskript des Add-ons. Liest /data/options.json und startet die Engine im Vordergrund.
set -euo pipefail

OPTIONS=/data/options.json
opt() { # opt <key> <default>
    if [[ -f "$OPTIONS" ]]; then
        jq -r --arg k "$1" --arg d "$2" 'if (.[$k] // null) == null then $d else (.[$k] | tostring) end' "$OPTIONS" 2>/dev/null || echo "$2"
    else
        echo "$2"
    fi
}

SCRIPT_DIR="$(opt script_dir /share/php_automations)"
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
export PHPENGINE_LOG="${PHPENGINE_LOG:-/data/engine.log}"
export PHPENGINE_HTTP_PORT="${PHPENGINE_HTTP_PORT:-8099}"

mkdir -p /data "$SCRIPT_DIR"

# Beispiel-Automationen nur beim allerersten Start ausrollen
if [[ ! -f "$SCRIPT_DIR/.seeded" ]]; then
    cp -n /app/examples/*.php "$SCRIPT_DIR"/ 2>/dev/null || true
    cp -n /app/examples/ko_map.example.json "$SCRIPT_DIR"/ 2>/dev/null || true
    cp -n /app/examples/README.md "$SCRIPT_DIR"/ 2>/dev/null || true
    touch "$SCRIPT_DIR/.seeded"
    echo "[run.sh] Beispiel-Automationen nach $SCRIPT_DIR kopiert"
fi

# Arbeitskopie der KO-Map immer vorhalten (auch bei bestehenden Installationen),
# damit ko_get()/ko_set() nicht bei jedem Zugriff warnen
if [[ ! -f "$SCRIPT_DIR/ko_map.json" ]]; then
    cp /app/examples/ko_map.example.json "$SCRIPT_DIR/ko_map.json" 2>/dev/null || true
    echo "[run.sh] ko_map.json aus dem Beispiel angelegt"
fi

echo "[run.sh] PHP $(php -r 'echo PHP_VERSION;') | Skripte: $SCRIPT_DIR | Poll: ${POLL_INTERVAL}s | Log: $LOG_LEVEL"
echo "[run.sh] API-Basis: ${HA_API_BASE:-http://supervisor/core/api}"

# PHP-Builtin-Server brauchen wir nicht: die Engine bringt ihren eigenen HTTP-Server mit.
exec php /app/engine.php
