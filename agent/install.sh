#!/usr/bin/env bash
#
# Borg Backup Server Agent Installer
# Usage: curl -s https://your-server/get-agent | sudo bash -s -- --server https://your-server --key API_KEY
#
set -e

INSTALL_DIR="/opt/bbs-agent"
CONFIG_DIR="/etc/bbs-agent"
SERVER_URL=""
API_KEY=""

# ═══════════════════════════════════════════════════════════════════════════════
# Colors and formatting
# ═══════════════════════════════════════════════════════════════════════════════
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
DIM='\033[2m'
NC='\033[0m' # No Color

# Symbols
CHECK="${GREEN}✓${NC}"
CROSS="${RED}✗${NC}"
ARROW="${CYAN}→${NC}"
BULLET="${DIM}•${NC}"

# ═══════════════════════════════════════════════════════════════════════════════
# Spinner for long-running tasks
# ═══════════════════════════════════════════════════════════════════════════════
spinner_pid=""
spin() {
    local chars="⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏"
    local i=0
    while true; do
        printf "\r  ${CYAN}%s${NC} %s" "${chars:$i:1}" "$1"
        i=$(( (i + 1) % 10 ))
        sleep 0.1
    done
}

start_spinner() {
    spin "$1" &
    spinner_pid=$!
    disown
}

stop_spinner() {
    if [ -n "$spinner_pid" ]; then
        kill "$spinner_pid" 2>/dev/null || true
        wait "$spinner_pid" 2>/dev/null || true
        spinner_pid=""
        printf "\r\033[K"  # Clear line
    fi
}

# ═══════════════════════════════════════════════════════════════════════════════
# Output helpers
# ═══════════════════════════════════════════════════════════════════════════════
print_header() {
    echo ""
    echo -e "${BOLD}${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BOLD}${BLUE}║${NC}         ${BOLD}Borg Backup Server — Agent Installer${NC}              ${BOLD}${BLUE}║${NC}"
    echo -e "${BOLD}${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

print_step() {
    echo -e "  ${ARROW} ${BOLD}$1${NC}"
}

print_success() {
    echo -e "  ${CHECK} $1"
}

print_warning() {
    echo -e "  ${YELLOW}⚠${NC} $1"
}

print_error() {
    echo -e "  ${CROSS} ${RED}$1${NC}"
}

print_info() {
    echo -e "  ${BULLET} ${DIM}$1${NC}"
}

# ═══════════════════════════════════════════════════════════════════════════════
# Parse arguments
# ═══════════════════════════════════════════════════════════════════════════════
while [[ $# -gt 0 ]]; do
    case "$1" in
        --server) SERVER_URL="$2"; shift 2 ;;
        --key)    API_KEY="$2";    shift 2 ;;
        *)        echo -e "${RED}Unknown option: $1${NC}"; exit 1 ;;
    esac
done

if [ -z "$SERVER_URL" ] || [ -z "$API_KEY" ]; then
    echo -e "${RED}Usage: install.sh --server https://your-server --key API_KEY${NC}"
    exit 1
fi

# Must be root
if [ "$(id -u)" -ne 0 ]; then
    echo -e "${RED}Error: This script must be run as root (use sudo)${NC}"
    exit 1
fi

# ═══════════════════════════════════════════════════════════════════════════════
# Detect OS
# ═══════════════════════════════════════════════════════════════════════════════
detect_os() {
    print_step "Detecting operating system..."

    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS=$ID
        OS_VERSION=$VERSION_ID
        OS_PRETTY=$PRETTY_NAME
    elif [ "$(uname)" = "Darwin" ]; then
        OS="macos"
        OS_VERSION=$(sw_vers -productVersion 2>/dev/null || echo "unknown")
        OS_PRETTY="macOS $OS_VERSION"
    else
        OS="unknown"
        OS_PRETTY="Unknown OS"
    fi

    print_success "Detected: ${BOLD}$OS_PRETTY${NC}"
}

# ═══════════════════════════════════════════════════════════════════════════════
# Install borg via OS package manager
# ═══════════════════════════════════════════════════════════════════════════════
install_borg() {
    print_step "Checking for Borg Backup..."

    # Check for borg - prefer /usr/local/bin (where agent installs newer versions)
    local borg_cmd=""
    if [ -x /usr/local/bin/borg ]; then
        borg_cmd="/usr/local/bin/borg"
    elif command -v borg &>/dev/null; then
        borg_cmd="borg"
    fi

    if [ -n "$borg_cmd" ]; then
        local borg_ver=$($borg_cmd --version 2>/dev/null | head -1)
        print_success "Already installed: ${BOLD}$borg_ver${NC}"
        BORG_INSTALLED="existing"
        BORG_VERSION="$borg_ver"
        return
    fi

    print_info "Borg not found, installing via package manager..."
    start_spinner "Installing borgbackup and python3..."

    case "$OS" in
        ubuntu|debian|pop|linuxmint)
            apt-get update -qq >/dev/null 2>&1
            apt-get install -y -qq borgbackup python3 >/dev/null 2>&1
            ;;
        centos|rhel|rocky|almalinux)
            if command -v dnf &>/dev/null; then
                dnf install -y epel-release >/dev/null 2>&1 || true
                dnf config-manager --set-enabled powertools >/dev/null 2>&1 || \
                    dnf config-manager --set-enabled crb >/dev/null 2>&1 || \
                    dnf config-manager --set-enabled PowerTools >/dev/null 2>&1 || true
                dnf install -y python3-packaging >/dev/null 2>&1 || true
                dnf install -y borgbackup python3 >/dev/null 2>&1 || {
                    dnf install -y python3 >/dev/null 2>&1
                }
            else
                yum install -y epel-release >/dev/null 2>&1 || true
                yum install -y python3-packaging >/dev/null 2>&1 || true
                yum install -y borgbackup python3 >/dev/null 2>&1 || {
                    yum install -y python3 >/dev/null 2>&1
                }
            fi
            ;;
        fedora)
            dnf install -y borgbackup python3 >/dev/null 2>&1
            ;;
        arch|manjaro|endeavouros)
            pacman -Sy --noconfirm borg python >/dev/null 2>&1
            ;;
        opensuse*|sles)
            zypper install -y borgbackup python3 >/dev/null 2>&1
            ;;
        macos)
            if command -v brew &>/dev/null; then
                brew install borgbackup python3 >/dev/null 2>&1
            else
                stop_spinner
                print_error "Homebrew required on macOS. Install from https://brew.sh"
                exit 1
            fi
            ;;
        *)
            stop_spinner
            print_error "Unsupported OS '$OS'. Install borg and python3 manually, then re-run."
            exit 1
            ;;
    esac

    stop_spinner

    if command -v borg &>/dev/null; then
        local borg_ver=$(borg --version 2>/dev/null | head -1)
        print_success "Installed: ${BOLD}$borg_ver${NC}"
        BORG_INSTALLED="new"
        BORG_VERSION="$borg_ver"
    else
        print_warning "Borg not available in package manager"
        print_info "Agent will install borg on first run based on server settings"
        BORG_INSTALLED="pending"
        BORG_VERSION="will be installed"
    fi
}

# ═══════════════════════════════════════════════════════════════════════════════
# Install agent files
# ═══════════════════════════════════════════════════════════════════════════════
install_agent() {
    print_step "Installing agent files..."

    mkdir -p "$INSTALL_DIR"
    mkdir -p "$CONFIG_DIR"

    start_spinner "Downloading agent from server..."

    # Download agent script from server
    if command -v curl &>/dev/null; then
        curl -sf -o "$INSTALL_DIR/bbs-agent.py" "$SERVER_URL/api/agent/download?file=bbs-agent.py"
    elif command -v wget &>/dev/null; then
        wget -q -O "$INSTALL_DIR/bbs-agent.py" "$SERVER_URL/api/agent/download?file=bbs-agent.py"
    else
        stop_spinner
        print_error "curl or wget required"
        exit 1
    fi

    chmod +x "$INSTALL_DIR/bbs-agent.py"

    # Download uninstaller
    if command -v curl &>/dev/null; then
        curl -sf -o "$INSTALL_DIR/uninstall.sh" "$SERVER_URL/api/agent/download?file=uninstall.sh" 2>/dev/null || true
    elif command -v wget &>/dev/null; then
        wget -q -O "$INSTALL_DIR/uninstall.sh" "$SERVER_URL/api/agent/download?file=uninstall.sh" 2>/dev/null || true
    fi
    chmod +x "$INSTALL_DIR/uninstall.sh" 2>/dev/null || true

    stop_spinner
    print_success "Agent downloaded to ${DIM}$INSTALL_DIR${NC}"

    # Write config
    cat > "$CONFIG_DIR/config.ini" <<EOF
[server]
url = $SERVER_URL
api_key = $API_KEY

[agent]
poll_interval = 30
EOF

    chmod 600 "$CONFIG_DIR/config.ini"
    print_success "Configuration saved to ${DIM}$CONFIG_DIR/config.ini${NC}"
}

# ═══════════════════════════════════════════════════════════════════════════════
# Download SSH key for borg access
# ═══════════════════════════════════════════════════════════════════════════════
install_ssh_key() {
    print_step "Setting up SSH keys..."

    start_spinner "Downloading SSH key from server..."

    local response
    if command -v curl &>/dev/null; then
        response=$(curl -sf -H "Authorization: Bearer $API_KEY" "$SERVER_URL/api/agent/ssh-key" 2>/dev/null || echo "")
    elif command -v wget &>/dev/null; then
        response=$(wget -q -O - --header="Authorization: Bearer $API_KEY" "$SERVER_URL/api/agent/ssh-key" 2>/dev/null || echo "")
    fi

    stop_spinner

    if [ -z "$response" ]; then
        print_warning "SSH key not available yet"
        print_info "Key will be configured when SSH is provisioned on server"
        SSH_STATUS="pending"
        return
    fi

    # Extract SSH key from JSON response
    local ssh_key
    ssh_key=$(echo "$response" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('ssh_private_key',''))" 2>/dev/null)
    local ssh_host
    ssh_host=$(echo "$response" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('server_host',''))" 2>/dev/null)

    if [ -n "$ssh_key" ] && [ "$ssh_key" != "" ]; then
        echo "$ssh_key" > "$CONFIG_DIR/ssh_key"
        chmod 600 "$CONFIG_DIR/ssh_key"
        print_success "SSH key installed to ${DIM}$CONFIG_DIR/ssh_key${NC}"
        SSH_STATUS="installed"

        # Remove stale host keys
        if [ -n "$ssh_host" ]; then
            ssh-keygen -R "$ssh_host" >/dev/null 2>&1 || true
        fi
    else
        print_warning "SSH key not provisioned yet"
        print_info "Key will be configured when you add a repository"
        SSH_STATUS="pending"
    fi
}

# ═══════════════════════════════════════════════════════════════════════════════
# Install service
# ═══════════════════════════════════════════════════════════════════════════════
install_service() {
    print_step "Installing system service..."

    if [ "$OS" = "macos" ]; then
        start_spinner "Configuring launchd service..."

        if command -v curl &>/dev/null; then
            curl -sf -o /Library/LaunchDaemons/com.borgbackupserver.agent.plist \
                "$SERVER_URL/api/agent/download?file=com.borgbackupserver.agent.plist" 2>/dev/null || true
        fi
        launchctl unload /Library/LaunchDaemons/com.borgbackupserver.agent.plist 2>/dev/null || true
        launchctl load /Library/LaunchDaemons/com.borgbackupserver.agent.plist

        stop_spinner
        print_success "Service installed ${DIM}(launchd)${NC}"
        SERVICE_TYPE="launchd"
    else
        start_spinner "Configuring systemd service..."

        cat > /etc/systemd/system/bbs-agent.service <<EOF
[Unit]
Description=Borg Backup Server Agent
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=root
ExecStart=/usr/bin/python3 $INSTALL_DIR/bbs-agent.py
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF
        systemctl daemon-reload >/dev/null 2>&1
        systemctl enable bbs-agent >/dev/null 2>&1
        systemctl restart bbs-agent >/dev/null 2>&1

        stop_spinner
        print_success "Service installed and started ${DIM}(systemd)${NC}"
        SERVICE_TYPE="systemd"
    fi
}

# ═══════════════════════════════════════════════════════════════════════════════
# Print final summary
# ═══════════════════════════════════════════════════════════════════════════════
print_summary() {
    # Re-check borg version - prefer /usr/local/bin (where agent installs newer versions)
    if [ -x /usr/local/bin/borg ]; then
        BORG_VERSION=$(/usr/local/bin/borg --version 2>/dev/null | head -1)
    elif command -v borg &>/dev/null; then
        BORG_VERSION=$(borg --version 2>/dev/null | head -1)
    fi

    echo ""
    echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BOLD}${GREEN}║${NC}                  ${BOLD}${GREEN}Installation Complete!${NC}                     ${BOLD}${GREEN}║${NC}"
    echo -e "${BOLD}${GREEN}╚══════════════════════════════════════════════════════════════╝${NC}"
    echo ""

    # Installation details
    echo -e "  ${BOLD}Installation Details${NC}"
    echo -e "  ─────────────────────────────────────────────────────────────"
    echo -e "  ${BULLET} Agent location:  ${CYAN}$INSTALL_DIR/bbs-agent.py${NC}"
    echo -e "  ${BULLET} Configuration:   ${CYAN}$CONFIG_DIR/config.ini${NC}"
    echo -e "  ${BULLET} Borg version:    ${CYAN}$BORG_VERSION${NC}"
    echo -e "  ${BULLET} SSH key:         ${CYAN}${SSH_STATUS:-pending}${NC}"
    echo ""

    # Server connection
    echo -e "  ${BOLD}Server Connection${NC}"
    echo -e "  ─────────────────────────────────────────────────────────────"
    echo -e "  ${BULLET} Server URL:      ${CYAN}$SERVER_URL${NC}"
    echo ""

    # Useful commands
    echo -e "  ${BOLD}Useful Commands${NC}"
    echo -e "  ─────────────────────────────────────────────────────────────"
    if [ "$SERVICE_TYPE" = "launchd" ]; then
        echo -e "  ${BULLET} Check status:    ${YELLOW}launchctl list | grep borgbackupserver${NC}"
        echo -e "  ${BULLET} View logs:       ${YELLOW}tail -f /var/log/bbs-agent.log${NC}"
        echo -e "  ${BULLET} Restart agent:   ${YELLOW}launchctl kickstart -k system/com.borgbackupserver.agent${NC}"
    else
        echo -e "  ${BULLET} Check status:    ${YELLOW}systemctl status bbs-agent${NC}"
        echo -e "  ${BULLET} View logs:       ${YELLOW}journalctl -u bbs-agent -f${NC}"
        echo -e "  ${BULLET} Restart agent:   ${YELLOW}systemctl restart bbs-agent${NC}"
    fi
    echo -e "  ${BULLET} Uninstall:       ${YELLOW}$INSTALL_DIR/uninstall.sh${NC}"
    echo ""

    # Next steps
    echo -e "  ${BOLD}Next Steps${NC}"
    echo -e "  ─────────────────────────────────────────────────────────────"
    echo -e "  ${ARROW} Return to your BBS dashboard to configure backup plans"
    echo -e "  ${ARROW} The agent will automatically check in and appear online"
    echo ""
}

# ═══════════════════════════════════════════════════════════════════════════════
# Main
# ═══════════════════════════════════════════════════════════════════════════════
trap 'stop_spinner' EXIT

print_header
echo -e "  ${BULLET} Server: ${CYAN}$SERVER_URL${NC}"
echo ""

detect_os
install_borg
install_agent
install_ssh_key
install_service
print_summary
