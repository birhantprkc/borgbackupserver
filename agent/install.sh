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

# Install borg — downloads pre-compiled binary from GitHub releases
# Falls back to pip, then package manager if binary download fails
BORG_BINARY_PATH="/usr/local/bin/borg"
BORG_DEFAULT_VERSION="1.4.3"

install_borg() {
    if [ -f "$BORG_BINARY_PATH" ]; then
        echo "borg already installed at $BORG_BINARY_PATH: $($BORG_BINARY_PATH --version)"
        return
    fi

    # Also check if borg is installed elsewhere
    if command -v borg &>/dev/null; then
        echo "borg already installed: $(borg --version)"
        return
    fi

    echo "Installing borg v${BORG_DEFAULT_VERSION}..."

    # Detect architecture
    ARCH=$(uname -m)
    case "$ARCH" in
        x86_64|amd64)  ARCH="x86_64" ;;
        aarch64|arm64) ARCH="arm64" ;;
        *) echo "Warning: Unknown architecture $ARCH, trying pip install"
           install_borg_pip
           return ;;
    esac

    # Detect platform and build download URL
    PLATFORM=$(uname -s | tr '[:upper:]' '[:lower:]')
    DOWNLOAD_URL=""

    case "$PLATFORM" in
        linux)
            # Detect glibc version
            GLIBC_VER=$(ldd --version 2>&1 | head -1 | grep -oP '\d+\.\d+$' || echo "")
            if [ -z "$GLIBC_VER" ]; then
                echo "Warning: Could not detect glibc version, trying pip install"
                install_borg_pip
                return
            fi
            GLIBC_SHORT=$(echo "$GLIBC_VER" | tr -d '.')
            # Try glibc-matched binary (prefer locally-built for glibc231)
            if [ "$GLIBC_SHORT" -ge 235 ] && [ "$ARCH" = "x86_64" ]; then
                DOWNLOAD_URL="https://github.com/borgbackup/borg/releases/download/${BORG_DEFAULT_VERSION}/borg-linux-glibc235-${ARCH}-gh"
            elif [ "$GLIBC_SHORT" -ge 235 ] && [ "$ARCH" = "arm64" ]; then
                DOWNLOAD_URL="https://github.com/borgbackup/borg/releases/download/${BORG_DEFAULT_VERSION}/borg-linux-glibc235-${ARCH}-gh"
            elif [ "$GLIBC_SHORT" -ge 231 ] && [ "$ARCH" = "x86_64" ]; then
                DOWNLOAD_URL="https://github.com/borgbackup/borg/releases/download/${BORG_DEFAULT_VERSION}/borg-linux-glibc231-${ARCH}"
            fi
            ;;
        darwin)
            if [ "$ARCH" = "arm64" ]; then
                DOWNLOAD_URL="https://github.com/borgbackup/borg/releases/download/${BORG_DEFAULT_VERSION}/borg-macos-14-arm64-gh"
            else
                DOWNLOAD_URL="https://github.com/borgbackup/borg/releases/download/${BORG_DEFAULT_VERSION}/borg-macos-13-x86_64-gh"
            fi
            ;;
    esac

    if [ -n "$DOWNLOAD_URL" ]; then
        echo "Downloading borg binary from $DOWNLOAD_URL"
        TMP_PATH="${BORG_BINARY_PATH}.tmp"
        if curl -fSL -o "$TMP_PATH" "$DOWNLOAD_URL" 2>/dev/null || wget -q -O "$TMP_PATH" "$DOWNLOAD_URL" 2>/dev/null; then
            chmod 755 "$TMP_PATH"
            # Test the binary
            if "$TMP_PATH" --version &>/dev/null; then
                mv "$TMP_PATH" "$BORG_BINARY_PATH"
                echo "borg installed: $($BORG_BINARY_PATH --version)"
                return
            else
                echo "Warning: Downloaded binary failed version check"
                rm -f "$TMP_PATH"
            fi
        else
            echo "Warning: Binary download failed"
            rm -f "$TMP_PATH"
        fi
    fi

    # Fallback: try pip install
    echo "Trying pip install as fallback..."
    install_borg_pip || install_borg_package_manager
}

install_borg_pip() {
    if command -v pip3 &>/dev/null; then
        pip3 install "borgbackup==${BORG_DEFAULT_VERSION}" && {
            echo "borg installed via pip: $(borg --version)"
            return 0
        }
    fi
    return 1
}

install_borg_package_manager() {
    echo "Trying package manager as last resort..."
    case "$OS" in
        ubuntu|debian|pop|linuxmint)
            apt-get update -qq
            apt-get install -y -qq borgbackup python3
            ;;
        centos|rhel|rocky|almalinux)
            if command -v dnf &>/dev/null; then
                dnf install -y epel-release
                dnf install -y borgbackup python3
            else
                yum install -y epel-release
                yum install -y borgbackup python3
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
                echo "Error: Could not install borg. Install manually."
                exit 1
            fi
            ;;
        *)
            echo "Error: Unsupported OS '$OS'. Install borg manually and re-run."
            exit 1
            ;;
    esac
    echo "borg installed: $(borg --version)"
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
