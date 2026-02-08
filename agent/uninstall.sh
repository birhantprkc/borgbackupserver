#!/usr/bin/env bash
#
# BBS Agent Uninstaller — removes the agent, config, and service from this machine.
#
# Usage:
#   sudo bash /opt/bbs-agent/uninstall.sh
#
set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BOLD='\033[1m'; NC='\033[0m'
ok()   { echo -e "  ${GREEN}✔${NC} $*"; }
warn() { echo -e "  ${YELLOW}⚠${NC} $*"; }
die()  { echo -e "  ${RED}✘${NC} $*"; exit 1; }

if [[ "$EUID" -ne 0 ]]; then
    die "Must run as root (use sudo)"
fi

echo -e "${BOLD}BBS Agent Uninstaller${NC}"
echo ""

read -rp "  This will remove the BBS agent from this machine. Continue? [y/N] " yn
if [[ "${yn,,}" != "y" ]]; then
    die "Aborted."
fi

echo ""

# Detect platform
OS="$(uname -s)"

if [[ "$OS" == "Darwin" ]]; then
    # macOS — launchd
    PLIST="/Library/LaunchDaemons/com.borgbackupserver.agent.plist"
    if [[ -f "$PLIST" ]]; then
        launchctl unload "$PLIST" 2>/dev/null || true
        rm -f "$PLIST"
        ok "Removed launchd service"
    else
        warn "No launchd plist found — skipping"
    fi
else
    # Linux — systemd
    if systemctl is-active --quiet bbs-agent 2>/dev/null; then
        systemctl stop bbs-agent
        ok "Stopped bbs-agent service"
    fi
    if systemctl is-enabled --quiet bbs-agent 2>/dev/null; then
        systemctl disable bbs-agent 2>/dev/null
        ok "Disabled bbs-agent service"
    fi
    rm -f /etc/systemd/system/bbs-agent.service
    systemctl daemon-reload 2>/dev/null
    ok "Removed systemd service"
fi

# Remove agent files
if [[ -d /opt/bbs-agent ]]; then
    rm -rf /opt/bbs-agent
    ok "Removed /opt/bbs-agent"
else
    warn "/opt/bbs-agent not found — skipping"
fi

# Remove config and SSH key
if [[ -d /etc/bbs-agent ]]; then
    rm -rf /etc/bbs-agent
    ok "Removed /etc/bbs-agent (config and SSH key)"
else
    warn "/etc/bbs-agent not found — skipping"
fi

# Remove catalog data
if [[ -d /var/lib/bbs-agent ]]; then
    rm -rf /var/lib/bbs-agent
    ok "Removed /var/lib/bbs-agent (catalog data)"
else
    warn "/var/lib/bbs-agent not found — skipping"
fi

# Remove log file
if [[ -f /var/log/bbs-agent.log ]]; then
    rm -f /var/log/bbs-agent.log
    ok "Removed /var/log/bbs-agent.log"
fi

echo ""
echo -e "${GREEN}${BOLD}Agent uninstalled.${NC}"
echo -e "  Note: The client entry on the BBS server must be removed manually via the web UI."
