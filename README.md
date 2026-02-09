# Borg Backup Server

![Dashboard](public/images/borgbackupserver.png)

A self-hosted web application for centrally managing [BorgBackup](https://borgbackup.readthedocs.io/) across multiple Linux and macOS endpoints. A lightweight agent polls the server for tasks over HTTPS, backs up over SSH to the server, and reports progress back. No inbound connections to endpoints required — works behind firewalls and NAT. Includes a setup wizard for zero-config installation.

**View Live Demo **
Visit the website to spin up a free, self contained demo to try: [Borg Backup Server](https://www.borgbackupserver.com/)


## Features

- **Agent-based architecture** — endpoints initiate all connections; no inbound ports needed on clients
- **SSH with append-only security** — agents back up over SSH but cannot delete existing archives
- **Setup wizard** — browser-based installer configures database, admin account, and storage in minutes
- **Real-time progress** — live progress bars during backups
- **File-level restore** — browse archive contents in a collapsible tree, restore individual files or entire directories
- **Download archives** — extract and download files as .tar.gz directly from the browser
- **Plugin system** — extend backups with pre/post hooks for databases, applications, and custom scripts
- **Database plugins** — MySQL, PostgreSQL, and control panel dumps (cPanel, Interworx) with point-in-time restore
- **Flexible scheduling** — hourly to monthly intervals, multiple plans per client, manual trigger
- **Backup templates** — pre-configured directory sets for common server roles
- **Retention policies** — per-plan prune settings (hourly/daily/weekly/monthly/yearly)
- **S3 offsite sync** — mirror repositories to S3-compatible storage (AWS, Wasabi, Backblaze B2)
- **Multi-user** — role-based access (admin sees all, users see own clients)
- **Two-factor authentication** — TOTP-based 2FA with recovery codes
- **Queue management** — concurrent job limits, cancel/retry, progress tracking
- **Encrypted passphrases** — repository passwords encrypted at rest (AES-256-GCM)
- **Email alerts** — SMTP notifications on backup failure, agent offline, storage low
- **Dashboard** — backup charts, server stats, active jobs, auto-refresh
- **Server self-backup** — daily automated backup of BBS itself with optional S3 sync


---

## Quick Start

Start with a fresh **Ubuntu 22.04+** server, then run:

```bash
curl -sO https://raw.githubusercontent.com/marcpope/borgbackupserver/main/bin/bbs-install
sudo bash bbs-install --hostname backups.example.com
```

The installer handles everything — packages, Apache, MySQL, SSL, and cron. When it finishes, open the URL and the setup wizard walks you through the rest.

See the **[full documentation on the Wiki](https://github.com/marcpope/borgbackupserver/wiki)** for installation details, agent setup, configuration, and usage guides.

---

## Docker

Pre-built images are published to [Docker Hub](https://hub.docker.com/r/marcpope/borgbackupserver) on every release:

```bash
curl -sO https://raw.githubusercontent.com/marcpope/borgbackupserver/main/docker-compose.yml
docker compose up -d
```

Get admin credentials from the container logs:

```bash
docker compose logs bbs
```

Open `http://localhost:8080` and log in. See the **[Docker Installation guide](https://github.com/marcpope/borgbackupserver/wiki/Docker-Installation)** for full configuration, storage, reverse proxy, and update documentation.

---

## Documentation

All documentation lives on the **[GitHub Wiki](https://github.com/marcpope/borgbackupserver/wiki)**:

- [System Requirements](https://github.com/marcpope/borgbackupserver/wiki/System-Requirements)
- [Installation](https://github.com/marcpope/borgbackupserver/wiki/Installation)
- [Getting Started](https://github.com/marcpope/borgbackupserver/wiki/Getting-Started)
- [Agent Setup](https://github.com/marcpope/borgbackupserver/wiki/Agent-Setup)
- [Backup Plans](https://github.com/marcpope/borgbackupserver/wiki/Backup-Plans)
- [Restoring Files](https://github.com/marcpope/borgbackupserver/wiki/Restoring-Files)
- [Plugins](https://github.com/marcpope/borgbackupserver/wiki/Plugins)
- [S3 Offsite Sync](https://github.com/marcpope/borgbackupserver/wiki/S3-Offsite-Sync)
- [Settings](https://github.com/marcpope/borgbackupserver/wiki/Settings)
- [CLI Reference](https://github.com/marcpope/borgbackupserver/wiki/CLI-Reference)
- [Troubleshooting](https://github.com/marcpope/borgbackupserver/wiki/Troubleshooting)
- [Contributing](docs/CONTRIBUTING.md)

---

## Architecture

<img width="100%" alt="Borg Backup Server Architecture" src="https://github.com/user-attachments/assets/5c9c2b9a-d639-43ba-b4e3-1406d8aa284c" />


- **HTTPS** for control plane (task polling, progress, status)
- **SSH** for data plane (borg backup/restore via `borg serve`)
- **Append-only** — agents cannot delete existing archives; pruning runs server-side

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.1+ (no framework) |
| Database | MySQL 8.0 |
| Frontend | Bootstrap 5, Chart.js |
| Agent | Python 3 (stdlib only) |
| Backup engine | BorgBackup |
| Offsite sync | rclone |

---

## License

[MIT License](LICENSE) with Beer-Ware Addendum.

If this software saved your backups (or your job), consider buying the maintainer a beer.
