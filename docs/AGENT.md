# Borg Backup Server — Agent Deployment Guide

The BBS agent is a lightweight Python script that runs on each endpoint (the machine you want to back up). It polls the server for tasks, executes borg commands locally, and reports progress back.

---

## How It Works

```
Endpoint (Agent)                         BBS Server
    |                                        |
    |--- POST /api/agent/register ---------> |  (one-time)
    |                                        |
    |--- GET  /api/agent/tasks ------------> |  (every 30s)
    |<-- { task: "backup", command: [...] }  |
    |                                        |
    |  [runs borg create locally]            |
    |                                        |
    |--- POST /api/agent/progress ---------> |  (every 5s)
    |--- POST /api/agent/status -----------> |  (on completion)
    |--- POST /api/agent/catalog ----------> |  (file list)
    |                                        |
    |--- GET  /api/agent/tasks ------------> |  (next poll)
    |<-- { tasks: [] }                       |
```

- **No inbound ports required** — the agent polls outbound over HTTPS
- **No SSH** — authentication via API key in HTTP headers
- **Runs as root** — borg needs filesystem access to back up all files
- **Single file** — no dependencies beyond Python 3 stdlib

---

## Prerequisites

- **Python 3.6+** (pre-installed on most Linux distributions)
- **BorgBackup** — installed automatically by the installer, or manually: `apt install borgbackup`
- **Outbound HTTPS** — the agent must be able to reach the BBS server on port 443

---

## Automatic Installation

The easiest method. From the BBS web UI:

1. Go to **Clients** > click your client > **Install Agent** tab
2. Copy the install command shown
3. Run it on the endpoint:

```bash
curl -s https://backups.example.com/agent/install.sh | sudo bash -s -- \
    --server https://backups.example.com \
    --key YOUR_API_KEY_HERE
```

The installer will:
1. Detect your OS and install borg via the appropriate package manager
2. Copy the agent to `/opt/bbs-agent/bbs-agent.py`
3. Write the config to `/etc/bbs-agent/config.ini` (chmod 600)
4. Install and start a systemd service (Linux) or launchd daemon (macOS)

### Supported Operating Systems

| OS | Package Manager |
|---|---|
| Ubuntu, Debian, Pop!_OS, Linux Mint | apt |
| CentOS, RHEL, Rocky, AlmaLinux | yum / dnf |
| Fedora | dnf |
| Arch, Manjaro, EndeavourOS | pacman |
| openSUSE, SLES | zypper |
| macOS | Homebrew |

---

## Manual Installation

If you prefer to install manually or the automatic installer doesn't support your OS:

### 1. Install BorgBackup

```bash
# Debian/Ubuntu
apt install -y borgbackup

# RHEL/Rocky
dnf install -y borgbackup

# macOS
brew install borgbackup

# Or standalone binary:
# https://borgbackup.readthedocs.io/en/stable/installation.html
```

### 2. Copy the Agent

```bash
mkdir -p /opt/bbs-agent
cp agent/bbs-agent.py /opt/bbs-agent/
chmod +x /opt/bbs-agent/bbs-agent.py
```

### 3. Create the Config File

```bash
mkdir -p /etc/bbs-agent
cat > /etc/bbs-agent/config.ini <<EOF
[server]
url = https://backups.example.com
api_key = YOUR_API_KEY_HERE

[agent]
poll_interval = 30
EOF

chmod 600 /etc/bbs-agent/config.ini
```

### 4. Create the Service

**Linux (systemd):**

```bash
cat > /etc/systemd/system/bbs-agent.service <<EOF
[Unit]
Description=Borg Backup Server Agent
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/bin/python3 /opt/bbs-agent/bbs-agent.py
Restart=on-failure
RestartSec=10
StandardOutput=append:/var/log/bbs-agent.log
StandardError=append:/var/log/bbs-agent.log

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now bbs-agent
```

**macOS (launchd):**

```bash
cat > /Library/LaunchDaemons/com.borgbackupserver.agent.plist <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.borgbackupserver.agent</string>
    <key>ProgramArguments</key>
    <array>
        <string>/usr/bin/python3</string>
        <string>/opt/bbs-agent/bbs-agent.py</string>
    </array>
    <key>KeepAlive</key>
    <true/>
    <key>StandardOutPath</key>
    <string>/var/log/bbs-agent.log</string>
    <key>StandardErrorPath</key>
    <string>/var/log/bbs-agent.log</string>
</dict>
</plist>
EOF

launchctl load /Library/LaunchDaemons/com.borgbackupserver.agent.plist
```

---

## Configuration Reference

Config file: `/etc/bbs-agent/config.ini`

| Section | Key | Default | Description |
|---|---|---|---|
| `[server]` | `url` | *(required)* | Full URL to the BBS server (e.g. `https://backups.example.com`) |
| `[server]` | `api_key` | *(required)* | 64-character hex API key from the server |
| `[agent]` | `poll_interval` | `30` | Seconds between task polls (overridden by server on registration) |

### Environment Variables

| Variable | Description |
|---|---|
| `BBS_AGENT_CONFIG` | Override config file path (default: `/etc/bbs-agent/config.ini`) |
| `BBS_AGENT_LOG` | Override log file path (default: `/var/log/bbs-agent.log`) |

---

## Agent Lifecycle

### Registration

On first start, the agent sends a registration request with:
- Hostname
- IP address
- OS info (from `/etc/os-release`)
- Borg version
- Agent version

The server responds with the agent ID and configured poll interval.

### Polling Loop

Every `poll_interval` seconds, the agent:
1. Sends `GET /api/agent/tasks`
2. If tasks are returned, executes them sequentially
3. If no tasks, sleeps and polls again

### Backup Execution

When the agent receives a backup task:
1. Pre-counts files in the target directories (for progress bar)
2. Runs the borg command (built by the server)
3. Reports progress every 5 seconds (files processed, bytes)
4. Reports final status (completed/failed) with archive stats
5. Uploads file catalog in batches of 1000 entries

### Heartbeat

Every API call updates the agent's `last_heartbeat` timestamp. If no heartbeat is received for 3x the poll interval (default 90 seconds), the server marks the agent offline.

---

## Managing the Agent

### Check Status

```bash
# Linux
systemctl status bbs-agent

# macOS
launchctl list | grep borgbackupserver
```

### View Logs

```bash
tail -f /var/log/bbs-agent.log
```

### Restart

```bash
# Linux
systemctl restart bbs-agent

# macOS
launchctl stop com.borgbackupserver.agent
launchctl start com.borgbackupserver.agent
```

### Stop / Disable

```bash
# Linux
systemctl stop bbs-agent
systemctl disable bbs-agent

# macOS
launchctl unload /Library/LaunchDaemons/com.borgbackupserver.agent.plist
```

### Uninstall

```bash
# Stop service
systemctl stop bbs-agent
systemctl disable bbs-agent

# Remove files
rm /etc/systemd/system/bbs-agent.service
rm -rf /opt/bbs-agent
rm -rf /etc/bbs-agent
rm /var/log/bbs-agent.log

systemctl daemon-reload
```

---

## Security Considerations

- The config file contains the API key — permissions should be `600` (root only)
- The agent runs as root to access all files for backup
- Communication is over HTTPS — ensure the server has a valid SSL certificate
- API keys are 64 characters of cryptographic randomness (`bin2hex(random_bytes(32))`)
- The agent never receives or stores repository passphrases — borg commands include them as environment variables, which are not logged

---

## Troubleshooting

| Problem | Solution |
|---|---|
| Agent not starting | Check log: `cat /var/log/bbs-agent.log` |
| "Connection refused" | Verify server URL in config, check SSL, ensure port 443 is open |
| "401 Unauthorized" | API key mismatch — check `/etc/bbs-agent/config.ini` matches the key in the web UI |
| "borg: command not found" | Install borg: `apt install borgbackup` |
| Agent shows "offline" on server | Check the agent is running and can reach the server |
| Backup fails with permission error | Agent must run as root for full filesystem access |
| Rate limited (429) | Too many failed auth attempts — wait 5 minutes, fix the API key |
