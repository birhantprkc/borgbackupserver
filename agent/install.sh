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

# Parse arguments
while [[ $# -gt 0 ]]; do
    case "$1" in
        --server) SERVER_URL="$2"; shift 2 ;;
        --key)    API_KEY="$2";    shift 2 ;;
        *)        echo "Unknown option: $1"; exit 1 ;;
    esac
done

if [ -z "$SERVER_URL" ] || [ -z "$API_KEY" ]; then
    echo "Usage: install.sh --server https://your-server --key API_KEY"
    exit 1
fi

# Must be root
if [ "$(id -u)" -ne 0 ]; then
    echo "Error: This script must be run as root (use sudo)"
    exit 1
fi

echo "=== Borg Backup Server Agent Installer ==="
echo "Server: $SERVER_URL"
echo ""

# Detect OS
detect_os() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS=$ID
        OS_FAMILY=$ID_LIKE
    elif [ "$(uname)" = "Darwin" ]; then
        OS="macos"
    else
        OS="unknown"
    fi
    echo "Detected OS: $OS"
}

# Install borg via OS package manager
# The agent will handle upgrades later based on server settings (Official or Server binaries)
install_borg() {
    # Check if borg is already installed
    if command -v borg &>/dev/null; then
        echo "borg already installed: $(borg --version)"
        return
    fi

    echo "Installing borg via package manager..."

    case "$OS" in
        ubuntu|debian|pop|linuxmint)
            apt-get update -qq
            apt-get install -y -qq borgbackup python3
            ;;
        centos|rhel|rocky|almalinux)
            if command -v dnf &>/dev/null; then
                dnf install -y epel-release 2>/dev/null || true
                # Enable PowerTools/CRB repo for python3-packaging (required by EPEL borgbackup)
                dnf config-manager --set-enabled powertools 2>/dev/null || \
                    dnf config-manager --set-enabled crb 2>/dev/null || \
                    dnf config-manager --set-enabled PowerTools 2>/dev/null || true
                dnf install -y python3-packaging 2>/dev/null || true
                dnf install -y borgbackup python3 || {
                    echo "Warning: Could not install borgbackup from EPEL"
                    echo "Installing python3 only - borg will be installed based on server settings"
                    dnf install -y python3
                }
            else
                yum install -y epel-release 2>/dev/null || true
                yum install -y python3-packaging 2>/dev/null || true
                yum install -y borgbackup python3 || {
                    echo "Warning: Could not install borgbackup from EPEL"
                    echo "Installing python3 only - borg will be installed based on server settings"
                    yum install -y python3
                }
            fi
            ;;
        fedora)
            dnf install -y borgbackup python3
            ;;
        arch|manjaro|endeavouros)
            pacman -Sy --noconfirm borg python
            ;;
        opensuse*|sles)
            zypper install -y borgbackup python3
            ;;
        macos)
            if command -v brew &>/dev/null; then
                brew install borgbackup python3
            else
                echo "Error: Homebrew required on macOS. Install from https://brew.sh"
                exit 1
            fi
            ;;
        *)
            echo "Error: Unsupported OS '$OS'. Install borg and python3 manually, then re-run."
            exit 1
            ;;
    esac

    if command -v borg &>/dev/null; then
        echo "borg installed: $(borg --version)"
        echo "Note: Agent will check for upgrades based on server settings"
    else
        echo "Warning: borg not installed - agent will attempt to install on first run"
    fi
}

# Install agent files
install_agent() {
    echo "Installing agent to $INSTALL_DIR..."
    mkdir -p "$INSTALL_DIR"
    mkdir -p "$CONFIG_DIR"

    # Download agent script from server
    if command -v curl &>/dev/null; then
        curl -s -o "$INSTALL_DIR/bbs-agent.py" "$SERVER_URL/api/agent/download?file=bbs-agent.py"
    elif command -v wget &>/dev/null; then
        wget -q -O "$INSTALL_DIR/bbs-agent.py" "$SERVER_URL/api/agent/download?file=bbs-agent.py"
    else
        echo "Error: curl or wget required"
        exit 1
    fi

    chmod +x "$INSTALL_DIR/bbs-agent.py"

    # Download uninstaller
    if command -v curl &>/dev/null; then
        curl -s -o "$INSTALL_DIR/uninstall.sh" "$SERVER_URL/api/agent/download?file=uninstall.sh"
    elif command -v wget &>/dev/null; then
        wget -q -O "$INSTALL_DIR/uninstall.sh" "$SERVER_URL/api/agent/download?file=uninstall.sh"
    fi
    chmod +x "$INSTALL_DIR/uninstall.sh" 2>/dev/null || true

    # Write config
    cat > "$CONFIG_DIR/config.ini" <<EOF
[server]
url = $SERVER_URL
api_key = $API_KEY

[agent]
poll_interval = 30
EOF

    chmod 600 "$CONFIG_DIR/config.ini"
    echo "Config written to $CONFIG_DIR/config.ini"
}

# Download SSH key for borg access
install_ssh_key() {
    echo "Downloading SSH key from server..."
    local response
    if command -v curl &>/dev/null; then
        response=$(curl -s -H "Authorization: Bearer $API_KEY" "$SERVER_URL/api/agent/ssh-key")
    elif command -v wget &>/dev/null; then
        response=$(wget -q -O - --header="Authorization: Bearer $API_KEY" "$SERVER_URL/api/agent/ssh-key")
    fi

    if [ -z "$response" ]; then
        echo "Warning: Could not download SSH key. Agent will retry on startup."
        return
    fi

    # Extract SSH key from JSON response (simple parsing)
    local ssh_key
    ssh_key=$(echo "$response" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('ssh_private_key',''))" 2>/dev/null)
    local ssh_user
    ssh_user=$(echo "$response" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('ssh_unix_user',''))" 2>/dev/null)
    local ssh_host
    ssh_host=$(echo "$response" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('server_host',''))" 2>/dev/null)

    if [ -n "$ssh_key" ] && [ "$ssh_key" != "" ]; then
        echo "$ssh_key" > "$CONFIG_DIR/ssh_key"
        chmod 600 "$CONFIG_DIR/ssh_key"
        echo "SSH key installed to $CONFIG_DIR/ssh_key"

        # Remove any stale host keys for the BBS server so SSH doesn't reject
        # connections after a server rebuild
        if [ -n "$ssh_host" ]; then
            ssh-keygen -R "$ssh_host" 2>/dev/null || true
        fi

        # Write SSH config to config.ini
        if [ -n "$ssh_user" ]; then
            echo "" >> "$CONFIG_DIR/config.ini"
            echo "[ssh]" >> "$CONFIG_DIR/config.ini"
            echo "unix_user = $ssh_user" >> "$CONFIG_DIR/config.ini"
            echo "server_host = $ssh_host" >> "$CONFIG_DIR/config.ini"
            echo "key_path = $CONFIG_DIR/ssh_key" >> "$CONFIG_DIR/config.ini"
        fi
    else
        echo "Warning: No SSH key available yet. Will be configured when SSH is provisioned."
    fi
}

# Install service
install_service() {
    if [ "$OS" = "macos" ]; then
        echo "Installing launchd service..."
        if command -v curl &>/dev/null; then
            curl -s -o /Library/LaunchDaemons/com.borgbackupserver.agent.plist \
                "$SERVER_URL/api/agent/download?file=com.borgbackupserver.agent.plist"
        fi
        launchctl unload /Library/LaunchDaemons/com.borgbackupserver.agent.plist 2>/dev/null || true
        launchctl load /Library/LaunchDaemons/com.borgbackupserver.agent.plist
        echo "Service installed and started (launchd)"
    else
        echo "Installing systemd service..."
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
        systemctl daemon-reload
        systemctl enable bbs-agent
        systemctl restart bbs-agent
        echo "Service installed and started (systemd)"
    fi
}

# Main
detect_os
install_borg
install_agent
install_ssh_key
install_service

echo ""
echo "=== Installation complete ==="
echo "Agent installed to: $INSTALL_DIR"
echo "Config: $CONFIG_DIR/config.ini"
if [ "$OS" = "macos" ]; then
    echo "Service: launchctl list | grep borgbackupserver"
    echo "Logs: /var/log/bbs-agent.log"
else
    echo "Service: systemctl status bbs-agent"
    echo "Logs: journalctl -u bbs-agent -f"
fi
