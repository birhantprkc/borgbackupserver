# Borg Backup Server

A self-hosted web application for centrally managing [BorgBackup](https://borgbackup.readthedocs.io/) across multiple Linux and macOS endpoints. No SSH required — a lightweight HTTPS agent polls the server for tasks, executes borg locally, and reports back.

---

## Features

- **Agent-based architecture** — no inbound ports, no SSH keys, works behind firewalls
- **Real-time progress** — live progress bars during backups
- **File-level restore** — browse archive contents in a collapsible tree, restore individual files or entire directories
- **Download archives** — extract and download files as .tar.gz directly from the browser
- **Flexible scheduling** — 10min to monthly intervals, multiple times per day, manual trigger
- **Backup templates** — pre-configured directory sets for common server roles (web, database, mail, etc.)
- **Retention policies** — per-plan prune settings (hourly/daily/weekly/monthly/yearly)
- **Multi-user** — role-based access (admin sees all, users see own clients)
- **Queue management** — concurrent job limits, cancel/retry, priority ordering
- **Encrypted passphrases** — repository passwords encrypted at rest (AES-256-GCM)
- **Email alerts** — SMTP notifications on backup failure
- **Dashboard** — backup charts, server stats, active jobs, log feed with 15s auto-refresh

## Screenshots

![Dashboard](docs/images/dashboard.png)

---

## Quick Start

### Server

```bash
git clone https://github.com/marcpope/borgbackupserver.git
cd borgbackupserver
composer install
cp config/.env.example config/.env
# Edit config/.env with database credentials and generate APP_KEY
php migrate.php
```

See [docs/INSTALL.md](docs/INSTALL.md) for full server setup (Apache/Nginx, SSL, cron).

### Agent

From the BBS web UI, create a client, then run the install command on your endpoint:

```bash
curl -s https://your-server/agent/install.sh | sudo bash -s -- \
    --server https://your-server --key YOUR_API_KEY
```

See [docs/AGENT.md](docs/AGENT.md) for manual install and configuration.

---

## Documentation

| Document | Description |
|---|---|
| [Installation Guide](docs/INSTALL.md) | Server setup, database, web server, cron |
| [Agent Deployment](docs/AGENT.md) | Agent install, config, service management |
| [User Guide](docs/USER_GUIDE.md) | Using the web UI, creating plans, restoring files |
| [API Reference](docs/API.md) | Agent API endpoints with request/response examples |
| [Contributing](docs/CONTRIBUTING.md) | Development setup, conventions, how to help |

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.1+ (no framework) |
| Routing | AltoRouter |
| Database | MySQL / MariaDB |
| Frontend | Bootstrap 5, Chart.js |
| Agent | Python 3 (stdlib only) |
| Backup engine | BorgBackup |

---

## Architecture

```
Endpoint                                 BBS Server
  [bbs-agent.py]                         [PHP + MySQL]
       |                                      |
       |--- register (hostname, OS) --------> |
       |                                      |
       |--- poll for tasks -----------------> |
       |<-- backup command + passphrase ----- |
       |                                      |
       |  [runs borg create locally]          |
       |                                      |
       |--- progress (files, bytes) --------> |  (every 5s)
       |--- status (completed/failed) ------> |
       |--- file catalog (batch) -----------> |
       |                                      |
       |--- poll for tasks -----------------> |  (next cycle)
```

---

## License

[MIT License](LICENSE) with Beer-Ware Addendum.

If this software saved your backups (or your job), consider buying the maintainer a beer.
