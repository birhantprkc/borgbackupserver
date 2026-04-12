#!/bin/bash
#
# bbs-agent-start.sh — Pre-start wrapper for the BBS agent.
#
# This script runs BEFORE the agent Python script and provides a recovery
# path if the .py file is broken (e.g. SyntaxError from a bad update).
#
# Recovery strategy:
#   1. Syntax-check the current .py
#   2. If broken, try to download a fresh copy from the server
#   3. If download fails, restore from the .bak backup
#   4. Start the agent
#
# This wrapper is intentionally minimal — it uses only basic shell commands
# and curl/wget so it essentially cannot break itself.
#

AGENT_DIR="${BBS_AGENT_DIR:-/opt/bbs-agent}"
AGENT_PY="$AGENT_DIR/bbs-agent.py"
AGENT_BAK="$AGENT_PY.bak"
CONFIG="$AGENT_DIR/config.ini"
PYTHON="${BBS_PYTHON:-python3}"
LOG="${BBS_AGENT_LOG:-/var/log/bbs-agent.log}"

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') [WRAPPER] $1" >> "$LOG" 2>/dev/null
}

# Check if the agent script is syntactically valid
check_syntax() {
    "$PYTHON" -c "import ast; ast.parse(open('$1').read())" 2>/dev/null
    return $?
}

# Try to download a fresh agent script from the server
download_agent() {
    if [ ! -f "$CONFIG" ]; then
        log "No config file, cannot download"
        return 1
    fi

    SERVER=$(grep '^\s*url' "$CONFIG" | head -1 | cut -d= -f2- | tr -d ' ')
    KEY=$(grep '^\s*api_key' "$CONFIG" | head -1 | cut -d= -f2- | tr -d ' ')

    if [ -z "$SERVER" ] || [ -z "$KEY" ]; then
        log "Cannot parse server URL or API key from config"
        return 1
    fi

    URL="$SERVER/api/agent/download?file=bbs-agent.py"
    TMP="$AGENT_PY.recovery"

    if command -v curl &>/dev/null; then
        curl -sf -H "Authorization: Bearer $KEY" "$URL" -o "$TMP" 2>/dev/null
    elif command -v wget &>/dev/null; then
        wget -q --header="Authorization: Bearer $KEY" "$URL" -O "$TMP" 2>/dev/null
    else
        log "Neither curl nor wget available"
        return 1
    fi

    if [ ! -s "$TMP" ]; then
        rm -f "$TMP"
        log "Download returned empty file"
        return 1
    fi

    if check_syntax "$TMP"; then
        cp "$AGENT_PY" "$AGENT_BAK" 2>/dev/null
        mv "$TMP" "$AGENT_PY"
        chmod +x "$AGENT_PY" 2>/dev/null
        log "Downloaded and validated fresh agent script from server"
        return 0
    else
        rm -f "$TMP"
        log "Downloaded script also has syntax errors"
        return 1
    fi
}

# --- Main ---

if [ ! -f "$AGENT_PY" ]; then
    log "Agent script not found at $AGENT_PY — attempting download"
    if ! download_agent; then
        log "FATAL: Cannot recover agent script"
        exit 1
    fi
fi

if ! check_syntax "$AGENT_PY"; then
    log "Agent script has syntax errors — attempting recovery"

    # Try downloading fresh copy from server
    if download_agent; then
        log "Recovery successful via server download"
    elif [ -f "$AGENT_BAK" ] && check_syntax "$AGENT_BAK"; then
        log "Server download failed — restoring from backup"
        cp "$AGENT_BAK" "$AGENT_PY"
    else
        log "FATAL: Cannot recover — no valid script available (server unreachable, no backup)"
        # Sleep before exiting so the service restart loop doesn't spin at 100% CPU
        sleep 60
        exit 1
    fi
fi

# Hand off to the real agent
exec "$PYTHON" "$AGENT_PY" "$@"
