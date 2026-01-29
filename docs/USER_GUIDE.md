# Borg Backup Server — User Guide

This guide covers day-to-day usage of the BBS web interface for managing backups, restoring files, and monitoring your endpoints.

---

## First-Time Setup

On a fresh install, opening the BBS URL will launch the **Setup Wizard** instead of the login page. The wizard guides you through:

1. **System requirements check** — PHP version, extensions, writable directories
2. **Database connection** — enter MySQL credentials; the wizard creates the database if needed
3. **Admin account** — choose your own username, email, and password
4. **Storage & server** — set the backup storage path and the server hostname agents will connect to
5. **Install** — review settings and apply. The wizard creates tables, writes `config/.env`, and sets up everything in one click.

After completing the wizard, click **Add Your First Client** to get started, or go to the dashboard.

See [Installation Guide](INSTALL.md) for full server prerequisites (packages, web server, SSL, SSH helper, cron).

---

## Logging In

Navigate to your BBS server URL (e.g. `https://backups.example.com/login`).

If you used the setup wizard, log in with the credentials you created during setup. For manual installations, the default credentials are `admin` / `admin` — change your password immediately via **Profile** (top-right dropdown).

### User Roles

| Role | Access |
|---|---|
| **Admin** | Full access: all clients, all users, settings, server stats |
| **User** | Own clients only, no settings or user management, no server stats |

---

## Dashboard

The dashboard shows an overview of your backup infrastructure.

- **Stat cards** — Total agents, running jobs, queued jobs, recent errors (24h)
- **Backup chart** — Bar chart showing completed backups per hour over the last 24 hours
- **Server stats** — CPU load, memory usage, disk partition usage (admin only)
- **Active jobs** — Currently running backups with progress bars
- **Recent backups** — Last completed jobs with status and duration
- **Server log** — Latest 15 log entries

The dashboard auto-refreshes every 15 seconds.

---

## Clients

A **client** represents an endpoint (server or workstation) with the BBS agent installed.

### Adding a Client

1. Go to **Clients** > **Add Client** (admin only)
2. Enter a name (e.g. "web-prod-01")
3. Optionally assign an owner user
4. The system generates a unique API key

### Client Detail Page

Click a client to view its detail page with tabs:

#### Status Tab
- Repository summary cards (size, archive count)
- Backup plans table (name, frequency, status)
- Recent backup jobs table

#### Repos Tab
- Table of repositories with name, storage location, encryption, size, archive count
- Create new repository form:
  - **Name** — Descriptive label (max 20 characters)
  - **Storage Location** — Where to store the borg repo on the server
  - **Encryption** — `repokey-blake2` (recommended), `authenticated-blake2`, `repokey`, or `none`
  - **Password** — Auto-generated, stored encrypted on the server

#### Schedules Tab
- Existing backup plans table with actions:
  - **Run Now** — Trigger an immediate backup
  - **Pause / Resume** — Toggle the schedule on/off
  - **Edit** — Expand inline edit form (name, repo, directories, excludes, options, retention)
  - **Delete** — Remove the plan and its schedule
- Create new backup plan form (see [Creating a Backup Plan](#creating-a-backup-plan))

#### Restore Tab
- Browse archive file tree and restore or download files (see [Restoring Files](#restoring-files))

#### Install Agent Tab
- Shows the one-liner install command with the API key
- Copy button for the API key

#### Delete Tab
- Permanently deletes the client and all associated data (admin only)

---

## Creating a Backup Plan

A backup plan defines what to back up, where, how often, and how long to keep archives.

### Step-by-Step

1. Go to a client's **Schedules** tab
2. Fill out the **Create New Backup Plan** form:

| Field | Description |
|---|---|
| **Plan Name** | Descriptive name (e.g. "Daily Full Backup") |
| **Frequency** | How often: manual, 10/15/30 min, hourly, daily, weekly, monthly |
| **Times** | For daily/weekly/monthly: comma-separated 24h times (e.g. `01:00, 13:00`) |
| **Day of Week/Month** | For weekly or monthly schedules |
| **Repository** | Which repo to store archives in |
| **Template** | Optional: pre-fills directories and excludes for common server roles |
| **Directories** | Paths to back up, one per line. Use quick-pick buttons for common dirs |
| **Excludes** | Glob patterns to exclude, one per line (e.g. `*.tmp`, `*.log`) |
| **Options** | Checkboxes for common borg flags (compression, exclude-caches, noatime, etc.) |
| **Retention** | How many archives to keep per time period (hours, days, weeks, months, years) |

### Templates

Templates pre-fill directories and excludes for common server roles. Select one from the dropdown to auto-populate the form. Available defaults:

| Template | Directories | Description |
|---|---|---|
| Web Server | /var/www, /etc/nginx, /etc/apache2, /etc/letsencrypt | Web server configs and sites |
| MySQL | /var/lib/mysql, /etc/mysql | Database files and config |
| PostgreSQL | /var/lib/postgresql, /etc/postgresql | Database files and config |
| Mail Server | /var/mail, /etc/postfix, /etc/dovecot | Mail storage and config |
| Interworx Server | /home, /etc, /var/lib/mysql, /var/www | Full Interworx backup |
| File Server | /srv, /home, /etc/samba, /etc/nfs | Shared storage |
| Docker Host | /var/lib/docker, /etc/docker, /opt | Docker data and configs |
| Minimal | /etc | Configuration files only |

Admins can add, edit, and delete templates in **Settings > Backup Templates**.

### Borg Options

| Option | Borg Flag | Description |
|---|---|---|
| Compression | `--compression lz4/zstd/zlib` | Compress archive data |
| Exclude caches | `--exclude-caches` | Skip directories with CACHEDIR.TAG |
| One file system | `--one-file-system` | Don't cross filesystem boundaries |
| No atime | `--noatime` | Don't store access times (faster) |
| Numeric IDs | `--numeric-ids` | Store numeric user/group IDs instead of names |
| Skip xattrs | `--noxattrs` | Don't store extended attributes |
| Skip ACLs | `--noacls` | Don't store access control lists |

---

## Monitoring Backups

### Queue Page

Shows all backup jobs with their status:

| Status | Meaning |
|---|---|
| **Queued** | Waiting for a slot (max concurrent jobs limit) |
| **Sent** | Assigned to agent, waiting to start |
| **Running** | In progress (shows progress bar) |
| **Completed** | Finished successfully |
| **Failed** | Error occurred (hover for error message) |

Actions:
- **Cancel** — Cancel a queued or sent job
- **Retry** — Re-queue a failed job

### Log Page

Server-wide log with level filtering (All / Info / Warning / Error). Shows backup start/completion, failures, manual triggers, and system events.

---

## Restoring Files

The restore tab provides two ways to recover backed-up files.

### Browsing the Archive Tree

1. Go to a client's **Restore** tab
2. Select an archive from the dropdown
3. The file tree loads — click folders to expand them
4. Check directories or individual files you want to restore

The tree is lazy-loaded — each folder fetches its contents on click, so even archives with millions of files are browsable.

### Searching Files

Use the search box to find files by name across the entire archive. Results appear in a separate panel with checkboxes.

### Restore to Client

Click **Restore to Client** to queue a restore job. The agent will run `borg extract` on the endpoint, restoring files to their original paths (or a custom destination).

Optionally set a **Restore Destination** to extract files to a different directory instead of their original locations.

### Download as tar.gz

Click **Download .tar.gz** to extract the selected files on the server and download them directly to your browser as a compressed archive. This is useful for:
- Grabbing a config file without SSH access to the endpoint
- Downloading files from an offline endpoint
- Quick access to specific files without waiting for the agent

---

## Settings (Admin Only)

### Server Settings

| Setting | Description |
|---|---|
| **Max Concurrent Jobs** | How many backup/restore jobs can run simultaneously (default 4) |
| **Server Host / IP** | The address agents use to reach the server (shown in install commands) |
| **Agent Poll Interval** | How often agents check for tasks in seconds (default 30) |

### Email Notifications

Configure SMTP to receive email alerts when backups fail. All admin users receive failure notifications.

| Setting | Description |
|---|---|
| **SMTP Host** | Mail server hostname |
| **SMTP Port** | Usually 587 (STARTTLS) |
| **SMTP User** | Authentication username |
| **SMTP Password** | Authentication password |
| **From Address** | Sender email address |

### Storage Locations

Define where borg repositories are stored on the server filesystem. Each location has:
- **Label** — Display name
- **Path** — Filesystem path (e.g. `/mnt/backups`)
- **Max Size (GB)** — Optional capacity limit
- **Default** — Auto-selected when creating new repos

### Backup Templates

Pre-defined directory and exclude sets for common server roles. Templates can be added, edited, and deleted here. They appear in the backup plan creation form as a dropdown.

---

## User Management (Admin Only)

Go to **Users** to:
- View all users with their roles and agent counts
- Create new users (username, email, password, role)
- Edit existing users (change role, reset password)
- Delete users

---

## Profile

Available to all users via the top-right dropdown:
- **Change email** — with duplicate check
- **Change password** — requires current password, minimum 6 characters
- **View** — username, role, member since (read-only)
