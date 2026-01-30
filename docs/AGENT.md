# Borg Backup Server — Agent Deployment Guide

The BBS agent is a lightweight Python script that runs on each endpoint (the machine you want to back up). It polls the server for tasks, executes borg commands locally, and reports progress back.

---

## How It Works

```
Endpoint (Agent)                         BBS Server
    |                                        |
    |--- POST /api/agent/register ---------> |  (one-time)
    |--- GET  /api/agent/ssh-key ----------> |  (downloads SSH key)
    |                                        |
    |--- GET  /api/agent/tasks ------------> |  (every 30s)
    |<-- { task: "backup", command: [...] }  |
    |                                        |
    |  [runs borg create over SSH] --------> |  (borg serve --append-only)
    |                                        |
    |--- POST /api/agent/progress ---------> |  (every 5s)
    |--- POST /api/agent/status -----------> |  (on completion)
    |--- POST /api/agent/catalog ----------> |  (file list)
    |                                        |
    |                   [server runs prune]  |  (server-side, local access)
    |                                        |
    |--- GET  /api/agent/tasks ------------> |  (next poll)
    |<-- { tasks: [] }                       |
```

- **HTTPS for control plane** — the agent polls outbound over HTTPS for task orchestration
- **SSH for data plane** — borg connects via SSH to the server to stream backup data
- **Append-only** — the agent's SSH key is restricted to `borg serve --append-only`, preventing deletion of existing archives
- **Server-side pruning** — prune and compact jobs run locally on the server, not via the agent
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
curl -s "https://backups.example.com/api/agent/download?file=install.sh" | sudo bash -s -- \
    --server https://backups.example.com \
    --key YOUR_API_KEY_HERE
```

The installer will:
1. Detect your OS and install borg via the appropriate package manager
2. Copy the agent to `/opt/bbs-agent/bbs-agent.py`
3. Write the config to `/etc/bbs-agent/config.ini` (chmod 600)
4. Download the SSH private key to `/etc/bbs-agent/ssh_key` (chmod 600) for borg SSH access
5. Install and start a systemd service (Linux) or launchd daemon (macOS)

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
| `[ssh]` | `unix_user` | *(auto)* | Unix user for SSH access (e.g. `bbs-webserver1`) |
| `[ssh]` | `server_host` | *(auto)* | BBS server hostname for SSH connections |
| `[ssh]` | `key_path` | `/etc/bbs-agent/ssh_key` | Path to SSH private key |

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

The server responds with the agent ID and configured poll interval. After registration, the agent downloads its SSH private key from `GET /api/agent/ssh-key` if not already present on disk.

### Polling Loop

Every `poll_interval` seconds, the agent:
1. Sends `GET /api/agent/tasks`
2. If tasks are returned, executes them sequentially
3. If no tasks, sleeps and polls again

### Backup Execution

When the agent receives a backup task:
1. Pre-counts files in the target directories (for progress bar)
2. Runs the borg command with `BORG_RSH` set to use the SSH key
3. Borg connects to the server via `ssh://bbs-<name>@server/./repo`
4. The server's `authorized_keys` forces `borg serve --append-only`
5. Backup data streams over SSH to the server's storage
6. Reports progress every 5 seconds (files processed, bytes)
7. Reports final status (completed/failed) with archive stats
8. Uploads file catalog in batches of 1000 entries

### Server-Side Pruning

Prune and compact jobs are **not** sent to agents. They execute locally on the BBS server in the scheduler, which has direct filesystem access to all repositories. This enforces the append-only security model: agents can create archives but never delete them.

### Heartbeat

Every API call updates the agent's `last_heartbeat` timestamp. If no heartbeat is received for 3x the poll interval (default 90 seconds), the server marks the agent offline.

---

## SSH Architecture

### Overview

Backup data flows over SSH, not HTTPS. When a client is created in BBS, the server automatically provisions SSH access:

1. Generates an ed25519 key pair
2. Creates a restricted Unix user (e.g., `bbs-webserver1`) on the server
3. Configures `~/.ssh/authorized_keys` to only allow `borg serve --append-only`
4. Stores the encrypted private key in the database
5. Agent downloads the private key during installation or registration

### Detailed Flow

#### Step 1: Admin Creates Client

In the BBS web UI (**Clients > Add Client**):

- Server generates API key and creates the agent record
- `SshKeyManager::provisionClient()` runs automatically:
  - `ssh-keygen -t ed25519` generates a key pair in a temp directory
  - Derives a Unix username: `bbs-<sanitized-client-name>`
  - Calls `sudo bbs-ssh-helper create-user <user> <home_dir> <public_key>`
  - The helper script (running as root):
    - Creates the Unix user with `useradd --system --shell /bin/sh`
    - Sets home directory to `<storage_path>/<agent_id>/`
    - Writes `authorized_keys`:
      ```
      command="borg serve --append-only --restrict-to-path /mnt/backups/3",restrict ssh-ed25519 AAAA... bbs-agent
      ```
  - Private key is encrypted (AES-256-GCM) and stored in the `agents` table

#### Step 2: Agent Installation on Remote Server

```bash
curl -s "https://bbs-server/api/agent/download?file=install.sh" | sudo bash -s -- \
    --server https://bbs-server --key <API_KEY>
```

The installer:
1. Installs borg and the agent script
2. Writes config to `/etc/bbs-agent/config.ini`
3. Calls `GET /api/agent/ssh-key` with the API key
4. Server decrypts and returns the private key, Unix username, and server hostname
5. Saves private key to `/etc/bbs-agent/ssh_key` (mode `0600`)
6. Starts the agent service

#### Step 3: Agent Registers

On first run, `bbs-agent.py`:
1. Sends `POST /api/agent/register` with system info
2. Calls `download_ssh_key()` — checks if `/etc/bbs-agent/ssh_key` exists (it does from install), no-op
3. Enters the polling loop

#### Step 4: Admin Creates Repository

In the client detail page, when adding a repository:
- Server detects the agent has SSH configured (`ssh_unix_user` is set)
- Builds an SSH repo path: `ssh://bbs-webserver1@bbs-server.example.com/./myrepo`
- This path is stored in the `repositories` table

#### Step 5: Backup Executes

When a scheduled backup fires:
1. Scheduler creates a queued job
2. Queue manager builds the task payload with the borg command and environment:
   ```
   command: ["borg", "create", ..., "ssh://bbs-webserver1@server/./myrepo::backup-2026-01-29_02-00-00", "/etc", "/var/www"]
   env: {
     BORG_PASSPHRASE: "<decrypted>",
     BORG_RSH: "ssh -i /etc/bbs-agent/ssh_key -o StrictHostKeyChecking=accept-new -o BatchMode=yes"
   }
   ```
3. Agent picks up the task, runs borg with the SSH environment
4. Borg connects to the server: `ssh -i /etc/bbs-agent/ssh_key bbs-webserver1@server`
5. Server's `authorized_keys` forces `borg serve --append-only --restrict-to-path /mnt/backups/3`
6. Backup data streams to `/mnt/backups/3/myrepo/` on the server
7. Agent reports progress and final status over HTTPS

#### Step 6: Prune Runs Server-Side

1. Scheduler creates a prune job (after backup, per retention policy)
2. Queue manager marks it as server-side (not sent to agent)
3. Scheduler resolves the local repo path: `/mnt/backups/3/myrepo`
4. Executes `borg prune` directly on the server filesystem
5. No SSH, no agent involvement — full local access

#### Step 7: Client Deletion

When an admin deletes a client:
- `SshKeyManager::deprovisionClient()` runs `sudo bbs-ssh-helper delete-user bbs-webserver1`
- The Unix user is removed from the server
- The agent record and all associated data (repos, plans, archives) cascade-delete from the database

### Security Properties

| Property | How |
|---|---|
| Agent cannot delete backups | `borg serve --append-only` in `authorized_keys` |
| Agent cannot access other clients | `--restrict-to-path` limits to its own home directory |
| Agent has no shell access | `restrict` keyword in `authorized_keys` disables port forwarding, PTY, etc. |
| Private key encrypted at rest | AES-256-GCM in the database, `0600` on agent filesystem |
| Only `bbs-*` users can be managed | Helper script validates username prefix before any operation |
| Server controls retention | Prune runs server-side with full repo access |

### Server Requirements

For SSH to work, the BBS server needs:

1. **`sshd` running** — OpenSSH server must be installed and accepting connections
2. **`borg` installed** — `borg serve` is invoked by `authorized_keys`
3. **`bbs-ssh-helper` installed** — copy and configure the sudo helper:
   ```bash
   cp bin/bbs-ssh-helper /usr/local/bin/bbs-ssh-helper
   chmod 755 /usr/local/bin/bbs-ssh-helper
   echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/bbs-ssh-helper" > /etc/sudoers.d/bbs-ssh-helper
   ```
4. **Default storage location** — must be set in Settings before adding clients (used as the base path for SSH home directories)
5. **`server_host` setting** — must be set in Settings > General (the hostname agents SSH to)

### Files on the Agent

| Path | Purpose |
|---|---|
| `/opt/bbs-agent/bbs-agent.py` | Agent script |
| `/etc/bbs-agent/config.ini` | Server URL, API key, SSH config (mode `0600`) |
| `/etc/bbs-agent/ssh_key` | SSH private key for borg access (mode `0600`) |
| `/var/log/bbs-agent.log` | Agent log |

### Files on the Server (per client)

| Path | Purpose |
|---|---|
| `<storage>/<agent_id>/` | Home directory for `bbs-<name>` user |
| `<storage>/<agent_id>/.ssh/authorized_keys` | Borg serve restriction |
| `<storage>/<agent_id>/<repo_name>/` | Borg repository data |

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
- The SSH private key at `/etc/bbs-agent/ssh_key` must be `600` (root only)
- The agent runs as root to access all files for backup
- HTTPS for control plane (task polling, progress reporting) — ensure the server has a valid SSL certificate
- SSH for data plane (borg backup/restore) — key-based auth, no passwords
- API keys are 64 characters of cryptographic randomness (`bin2hex(random_bytes(32))`)
- SSH keys are ed25519, generated per-client, restricted to `borg serve --append-only`
- The agent never receives or stores repository passphrases — borg commands include them as environment variables, which are not logged
- A compromised agent **cannot** delete or modify existing backups due to append-only restrictions

---

## Troubleshooting

| Problem | Solution |
|---|---|
| Agent not starting | Check log: `cat /var/log/bbs-agent.log` |
| "Connection refused" (HTTPS) | Verify server URL in config, check SSL, ensure port 443 is open |
| "Connection refused" (SSH) | Ensure `sshd` is running on the BBS server, port 22 is open |
| "401 Unauthorized" | API key mismatch — check `/etc/bbs-agent/config.ini` matches the key in the web UI |
| "borg: command not found" | Install borg: `apt install borgbackup` (on both agent and server) |
| Agent shows "offline" on server | Check the agent is running and can reach the server |
| Backup fails with permission error | Agent must run as root for full filesystem access |
| Backup fails with SSH error | Check `/etc/bbs-agent/ssh_key` exists (mode 600). Check server user exists: `id bbs-<name>`. Check authorized_keys is correct. |
| "No SSH key available" | SSH provisioning may have failed at client creation. Check server log in BBS. Ensure `bbs-ssh-helper` is installed and sudoers is configured. |
| Prune not running | Prune runs server-side, not on the agent. Check `scheduler.php` is in cron and borg is installed on the server. |
| Rate limited (429) | Too many failed auth attempts — wait 5 minutes, fix the API key |
