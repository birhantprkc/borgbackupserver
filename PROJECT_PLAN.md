# Borg Backup Server - Project Plan

## Overview

Borg Backup Server (BBS) is an open-source, multi-user web application that centrally manages [BorgBackup](https://borgbackup.readthedocs.io/) across multiple Linux endpoints. A lightweight HTTPS-based agent runs on each endpoint, receives commands from the server, executes borg operations locally, and reports status/progress back. All backup data is stored on the server.

**Website:** borgbackupserver.com

---

## Architecture

```
Agent (endpoint)                              Server
    |                                            |
    |---- POST /api/agent/register ------------->|  (one-time setup with API key)
    |                                            |
    |---- GET  /api/agent/tasks ---------------->|  (polling loop, every N seconds)
    |<--- {task: "backup", plan_id: 266} --------|
    |                                            |
    |  [runs borg create locally]                |
    |                                            |
    |---- POST /api/agent/progress ------------->|  (file count, bytes, borg output)
    |---- POST /api/agent/status --------------->|  (completion / error)
    |                                            |
    |---- GET  /api/agent/tasks ---------------->|  (next poll)
    |<--- {tasks: []} ---------------------------|
    |                                            |
    |---- POST /api/agent/heartbeat ------------>|  (periodic health check)
```

### Why HTTPS Instead of SSH

- **No inbound ports required on endpoints** - agent polls outbound, works behind firewalls and NAT
- **No SSH key management** - authentication via API keys
- **Simpler installation** - no SSH config, authorized_keys, or user account setup
- **Scales better** - no persistent SSH connections to manage
- **Firewall friendly** - outbound HTTPS (443) is almost never blocked

---

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 8.x |
| Routing | AltoRouter |
| Dependencies | Composer (PSR-4 autoloading) |
| Database | MySQL |
| Caching | Memcached (Phase 5) |
| Frontend | Bootstrap 5 |
| Agent | Python (systemd service) |
| Backup Engine | BorgBackup |
| Agent-Server Comms | HTTPS REST API (agent polls server) |

---

## Directory Structure

```
borgbackupserver/
├── composer.json
├── public/
│   ├── index.php              # Front controller
│   ├── css/
│   ├── js/
│   └── images/                # Logos and assets
├── src/
│   ├── Core/
│   │   ├── App.php            # Application bootstrap
│   │   ├── Router.php         # AltoRouter wrapper
│   │   ├── Config.php         # .env loader
│   │   └── Database.php       # PDO/MySQL wrapper class
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── DashboardController.php
│   │   ├── ClientController.php
│   │   ├── ScheduleController.php
│   │   ├── RepositoryController.php
│   │   ├── QueueController.php
│   │   ├── LogController.php
│   │   ├── SettingsController.php
│   │   ├── UserController.php
│   │   └── Api/
│   │       └── AgentApiController.php
│   ├── Models/
│   │   ├── User.php
│   │   ├── Agent.php
│   │   ├── Repository.php
│   │   ├── BackupPlan.php
│   │   ├── Schedule.php
│   │   ├── BackupJob.php
│   │   ├── Archive.php
│   │   ├── StorageLocation.php
│   │   └── Setting.php
│   ├── Middleware/
│   │   ├── AuthMiddleware.php
│   │   ├── CsrfMiddleware.php
│   │   ├── AdminMiddleware.php
│   │   └── ApiAuthMiddleware.php
│   ├── Services/
│   │   ├── BorgCommandBuilder.php
│   │   ├── QueueManager.php
│   │   ├── SchedulerService.php
│   │   ├── AgentService.php
│   │   └── NotificationService.php
│   └── Views/
│       ├── layouts/
│       │   ├── app.php        # Main layout (sidebar + topbar)
│       │   └── auth.php       # Login/register layout
│       ├── auth/
│       ├── dashboard/
│       ├── clients/
│       ├── schedules/
│       ├── repositories/
│       ├── queue/
│       ├── log/
│       ├── settings/
│       └── users/
├── migrations/
├── config/
│   └── .env.example
├── agent/                     # Agent source + installer
│   ├── install.sh             # One-line installer script
│   ├── bbs-agent.py           # Agent daemon
│   ├── bbs-agent.service      # Systemd unit file
│   └── README.md
├── docs/
│   ├── installation.md
│   ├── user-guide.md
│   ├── agent-deployment.md
│   └── api-reference.md
├── storage/
│   └── logs/
└── design/                    # Prototype screenshots (reference)
```

---

## Database Schema

### `users`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | PK |
| username | VARCHAR(50) | UNIQUE |
| email | VARCHAR(255) | UNIQUE |
| password_hash | VARCHAR(255) | bcrypt |
| role | ENUM('admin','user') | Default 'user' |
| created_at | DATETIME | |
| updated_at | DATETIME | |

### `agents`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | PK |
| name | VARCHAR(100) | Display name |
| hostname | VARCHAR(255) | FQDN or hostname |
| ip_address | VARCHAR(45) | IPv4 or IPv6 |
| api_key | VARCHAR(64) | UNIQUE, for agent auth |
| os_info | VARCHAR(255) | e.g. "CentOS Linux 7.6.1810 x86_64" |
| borg_version | VARCHAR(20) | Reported by agent |
| agent_version | VARCHAR(20) | BBS agent version |
| status | ENUM('setup','online','offline','error') | |
| last_heartbeat | DATETIME | NULL if never seen |
| user_id | INT | FK to users (owner) |
| created_at | DATETIME | |

### `storage_locations`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | PK |
| label | VARCHAR(100) | e.g. "Primary Storage" |
| path | VARCHAR(500) | Filesystem path on server |
| max_size_gb | INT | Optional capacity limit |
| is_default | TINYINT(1) | |
| created_at | DATETIME | |

### `repositories`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | PK |
| agent_id | INT | FK to agents |
| storage_location_id | INT | FK to storage_locations |
| name | VARCHAR(100) | e.g. "c701-2025" |
| path | VARCHAR(500) | Full path on server |
| encryption | VARCHAR(50) | e.g. "authenticated-blake2" |
| passphrase_encrypted | TEXT | Encrypted at rest |
| size_bytes | BIGINT | Updated after each backup |
| archive_count | INT | Updated after each backup |
| created_at | DATETIME | |

### `backup_plans`
The "Backup Plan" ties together what to back up, where, how, and retention policy.

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | PK |
| agent_id | INT | FK to agents |
| repository_id | INT | FK to repositories |
| name | VARCHAR(100) | e.g. "Daily Backup" |
| directories | TEXT | Space-delimited paths: "/home /var /etc" |
| advanced_options | TEXT | Borg CLI flags: "--compression lz4 --exclude-caches" |
| prune_minutes | INT | Retention: keep N minutely |
| prune_hours | INT | Retention: keep N hourly |
| prune_days | INT | Retention: keep N daily |
| prune_weeks | INT | Retention: keep N weekly |
| prune_months | INT | Retention: keep N monthly |
| prune_years | INT | Retention: keep N yearly |
| enabled | TINYINT(1) | |
| created_at | DATETIME | |

### `schedules`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | PK |
| backup_plan_id | INT | FK to backup_plans |
| frequency | VARCHAR(30) | "manual", "10min", "15min", "30min", "hourly", "daily", "weekly", "monthly" |
| times | VARCHAR(255) | Comma-separated times: "01:08,04:08,16:08,20:08" |
| day_of_week | TINYINT | For weekly (0=Sun..6=Sat) |
| day_of_month | TINYINT | For monthly |
| enabled | TINYINT(1) | |
| next_run | DATETIME | Pre-calculated |
| last_run | DATETIME | |
| created_at | DATETIME | |

### `backup_jobs`
Every execution of a backup plan (or manual task) creates a job.

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | PK |
| backup_plan_id | INT | FK to backup_plans (NULL for manual) |
| agent_id | INT | FK to agents |
| repository_id | INT | FK to repositories |
| task_type | ENUM('backup','prune','restore','check','compact') | |
| status | ENUM('queued','sent','running','completed','failed','cancelled') | |
| files_total | INT | Pre-counted on agent |
| files_processed | INT | Updated during progress |
| bytes_total | BIGINT | |
| bytes_processed | BIGINT | |
| duration_seconds | INT | |
| error_log | TEXT | |
| queued_at | DATETIME | |
| started_at | DATETIME | |
| completed_at | DATETIME | |

### `archives`
Each borg archive (restore point) within a repository.

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | PK |
| repository_id | INT | FK to repositories |
| backup_job_id | INT | FK to backup_jobs |
| archive_name | VARCHAR(255) | Borg archive name |
| file_count | INT | |
| original_size | BIGINT | |
| deduplicated_size | BIGINT | |
| created_at | DATETIME | |

### `server_log`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | PK |
| agent_id | INT | FK to agents (nullable) |
| backup_job_id | INT | FK to backup_jobs (nullable) |
| level | ENUM('info','warning','error') | |
| message | TEXT | |
| created_at | DATETIME | |

### `settings`
| Column | Type | Notes |
|--------|------|-------|
| key | VARCHAR(100) | PK |
| value | TEXT | |

Initial settings: `max_queue` (default 4), `server_host`, `agent_poll_interval`, `smtp_host`, `smtp_port`, `smtp_user`, `smtp_pass`, `smtp_from`.

---

## UI Layout

### Sidebar Navigation (all users)
- **Dashboard** - overview stats and activity
- **Clients** - list/manage agents (admin sees all, users see own)
- **Queue** - active and recently completed jobs
- **Log** - server-wide log viewer
- **Settings** - server config (admin only) / user preferences
- **Users** - user management (admin only)
- **Logout**

### Top Bar
- Logo (left)
- Error/alert bell with unresolved count badge
- User dropdown (right): profile, logout

---

## Pages & Features

### 1. Login Page
- Centered card with logo, username, password, sign-in button
- "Forgot password?" link
- Bootstrap 5 card on neutral background

### 2. Dashboard
- **4 stat cards**: Agents Online, Backups Running, Queue Waiting, Errors to Resolve
- **Backups Completed (24h)**: bar chart
- **Server Stats**: CPU load, memory usage, disk partition usage table (mount, % used, size, free)
- **Active Backup Jobs**: table with client, elapsed time, files progress, plan ID, status
- **Recently Completed**: table with client, completed time, files, repo, plan, duration, status badge
- **Server Log Feed**: last N log entries with client, timestamp, log info

### 3. Clients List
- DataTable with search, pagination, entries-per-page
- Columns: Name, Agent Version, Restore Points, Schedules, Repos, Size, Status badge
- "Add Client" button generates API key and shows install command
- Admin sees all clients; users see only their own

### 4. Client Detail
- **Header**: name, hostname, agent version, OS, connection method (HTTPS), status indicator
- **Sub-tabs**: Status, Repos, Schedules, Restore, Delete
- **Status tab**:
  - Repositories summary cards (name, size, recovery points)
  - Schedules summary cards (name, times, linked repo, active status)
  - Backup duration chart (bar chart over time)
  - Recent backups table (date, files, archive count, plan, duration)
- **Repos tab**: visual repo cards + create new repo form (name, encryption type, auto-generated passphrase)
- **Schedules tab**: existing schedule cards + create new schedule form
- **Restore tab**: browse archives, select files/directories, restore to original or alternate path
- **Delete tab**: remove agent and optionally its repositories

### 5. New Schedule Form
- Frequency dropdown: Manual, Every 10/15/30 Min, Hourly, Daily, Weekly, Monthly
- Repository selector
- Backup directories field (space-delimited)
- Advanced options textarea (with common options listed as reference)
- Prune retention: Minutes, Hours, Days, Weeks, Months, Years (individual numeric inputs)

### 6. Repository Management
- Visual cards per repo showing name, ID, size, recovery point count, delete button
- Create form: description/name, encryption type dropdown (authenticated-blake2, repokey-blake2, none, etc.), auto-generated passphrase with note about encrypted storage on server

### 7. Server Queue
- **In Progress** table: date, client, task type, files total, files processed, repo, plan, status
- **Recently Completed** table: same columns with "complete" badges
- Max concurrent jobs controlled by server setting (default 4)

### 8. Log Viewer
- Filterable by level (info/warning/error), agent, date range
- Searchable
- Paginated

### 9. Settings (Admin)
- **Storage Locations**: manage filesystem paths where repos are stored, set capacity limits, default location
- **Max Queue**: concurrent backup job limit
- **Server Host/IP**: the address agents use to reach the server
- **Agent Poll Interval**: how often agents check in (seconds)
- **SMTP Configuration**: host, port, user, password, from address
- **Notifications**: enable/disable email alerts on failure
- **SSH Key Management**: (removed - not needed with HTTPS agent)

### 10. User Management (Admin)
- List users with role badges
- Create/edit/delete users
- Assign agents to users
- Role: admin or user

---

## Agent Design

### Overview
The agent is a lightweight Python daemon that runs as a systemd service on each Linux endpoint. It polls the server for tasks, executes borg commands locally, and reports progress/results back.

### Installation Flow
1. Admin clicks "Add Client" in the web UI
2. Server generates a unique API key and displays a one-liner install command:
   ```
   curl -s https://borgbackupserver.com/agent/install.sh | sudo bash -s -- --server https://your-server.com --key API_KEY_HERE
   ```
3. The install script:
   - Installs borg if not present (via package manager: apt/yum/dnf/pacman)
   - Creates a `bbs-agent` system user
   - Downloads the agent script to `/opt/bbs-agent/`
   - Writes config to `/etc/bbs-agent/config.ini` (server URL, API key)
   - Installs and enables the systemd service
   - Registers with the server (POST /api/agent/register with hostname, OS, borg version)

### Agent Behavior
- **Poll loop**: every N seconds (configurable, default 30), agent calls `GET /api/agent/tasks`
- **Heartbeat**: included with every poll, or standalone `POST /api/agent/heartbeat` if idle
- **Task execution**: when a task is received, agent runs the appropriate borg command
- **Progress reporting**: during backup, agent streams progress via `POST /api/agent/progress` including:
  - Files total (pre-counted via `find` or borg `--dry-run`)
  - Files processed (parsed from borg `--log-json` output)
  - Bytes processed
  - Current file being backed up
- **Completion**: agent sends final status with `POST /api/agent/status` (success or error + log)

### Agent API Endpoints (on the server)
| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | /api/agent/register | One-time registration |
| GET | /api/agent/tasks | Poll for pending tasks |
| POST | /api/agent/progress | Report in-progress updates |
| POST | /api/agent/status | Report task completion/failure |
| POST | /api/agent/heartbeat | Health check |
| POST | /api/agent/info | Report system info (OS, borg version, disk) |

### Agent Authentication
- Every request includes the API key in an `Authorization: Bearer <key>` header
- Server validates the key against the `agents` table
- API key can be rotated from the web UI

### Supported Distros
- Debian / Ubuntu (apt)
- RHEL / CentOS / Rocky / Alma (yum/dnf)
- Fedora (dnf)
- Arch Linux (pacman)
- openSUSE (zypper)

---

## Progress Tracking

One of the key features is real-time backup progress visibility.

### How It Works
1. **Pre-count**: before starting `borg create`, the agent counts files in the target directories (using `find` or borg's `--dry-run --list`) and reports `files_total` to the server
2. **Borg JSON logging**: the agent runs borg with `--log-json --progress`, parsing the JSON output for file progress
3. **Streaming updates**: as borg processes files, the agent sends periodic progress updates to the server (every 5 seconds or every N files)
4. **Server-side**: the dashboard shows a progress bar per active job (files_processed / files_total) and can update via AJAX polling or WebSocket

---

## Queue System

### Job Lifecycle
1. **Queued**: scheduler or manual trigger creates a job with status `queued`
2. **Sent**: server picks the next queued job (respecting max_queue limit) and sends it to the agent on next poll; status becomes `sent`
3. **Running**: agent acknowledges and begins execution; status becomes `running`
4. **Completed/Failed**: agent reports final result; status becomes `completed` or `failed`

### Concurrency Control
- Server setting `max_queue` (default 4) limits how many jobs can be `sent` or `running` simultaneously
- Jobs remain `queued` until a slot opens
- Priority: manual/on-demand jobs can optionally jump the queue

---

## Phased Implementation

### Phase 1: Foundation -- COMPLETED
- [x] Initialize Composer project with PSR-4 autoloading
- [x] Database class (PDO wrapper with prepared statements)
- [x] Migration system and initial schema (11 tables)
- [x] AltoRouter setup with front controller (public/index.php)
- [x] Config loader (.env via phpdotenv)
- [x] Session management
- [x] Authentication (login, logout, bcrypt password hashing)
- [x] CSRF protection middleware
- [x] Base Bootstrap 5 layout (sidebar, topbar, content area)
- [x] Login page
- [x] Basic settings page (admin) with server config + SMTP
- [x] Queue page (in progress + recently completed tables)
- [x] Log viewer page (filterable by level)
- [x] User management page (list, create, delete users)
- [x] Default admin user seeded (admin/admin)

### Phase 2: Client & Repository Management -- COMPLETED
- [x] Clients list page with columns: Name, Version, Restore Points, Schedules, Repos, Size, Owner, Status
- [x] Add Client flow (generate 64-char API key, assign to user)
- [x] Client detail page with sub-tabs (Status, Repos, Schedules, Restore, Install Agent, Delete)
- [x] Repository CRUD (create with encryption options, auto-generated passphrase, delete)
- [x] Repository cards UI (name, size, recovery point count)
- [x] Create repository form (name, storage location, encryption type, passphrase)
- [x] Storage locations management (add/delete from settings page, label, path, max size, default flag)
- [x] Install Agent tab showing curl install command and API key
- [x] Client delete with confirmation
- [x] Role-based access (admin sees all agents, users see own)

### Phase 3: Backup Plans & Scheduling -- COMPLETED
- [x] BackupPlanController with create, update, delete, manual trigger
- [x] ScheduleController with toggle enable/disable, delete
- [x] Schedule creation form (frequency dropdown, times, day of week/month, directories, advanced borg options, prune retention per time period)
- [x] SchedulerService — checks `next_run`, creates queued jobs, calculates next run for all frequency types
- [x] QueueManager — enforces max_queue, promotes queued→sent, builds task payloads for agents
- [x] BorgCommandBuilder — builds borg create/prune/init/list/info/extract commands, generates archive names, builds env vars
- [x] scheduler.php CLI script (cron-ready: `* * * * * php scheduler.php`)
- [x] Manual backup trigger (run now button on each plan)

### Phase 4: Agent -- COMPLETED
- [x] AgentApiController with Bearer token auth (register, tasks, progress, status, heartbeat, info)
- [x] Agent polls → gets task payloads with full borg commands, env vars, job metadata
- [x] Progress endpoint updates files_total/processed, bytes; Status endpoint records completion/failure, creates archive records, updates repo stats
- [x] Python agent daemon (bbs-agent.py) — poll loop, borg execution, JSON log parsing, progress streaming, graceful shutdown
- [x] File pre-counting for progress tracking (os.walk before borg runs)
- [x] Systemd service unit file (bbs-agent.service)
- [x] macOS launchd plist (com.borgbackupserver.agent.plist)
- [x] Multi-distro installer script (install.sh) — detects OS, installs borg via apt/yum/dnf/pacman/brew, installs agent + config, sets up service
- [x] Heartbeat monitoring in scheduler.php (marks agents offline after 3x poll interval)
- [x] Integration tested: agent registers, polls, receives tasks, pre-counts files, reports status back to server

### Phase 5: Dashboard & Monitoring ✅
- [x] Dashboard stat cards (agents, running, queue, errors)
- [x] Backups completed chart (24h bar chart via Chart.js)
- [x] Server stats (CPU, memory, partition usage via ServerStats service)
- [x] Active backup jobs table with progress
- [x] Recently completed table
- [x] Server log feed
- [x] AJAX auto-refresh (15s interval)
- [x] Queue page (in progress + recently completed)
- [x] Log viewer page (filterable, searchable, paginated)
- [x] Error alert bell with badge count

### Phase 6: File Catalog & Restore ✅
- [x] `file_catalog` table (BIGINT id, archive_id, agent_id, file_path, file_name, status, mtime)
- [x] Agent API: `POST /api/agent/catalog` — batch file entry upload
- [x] Borg `--list` flag for file status logging
- [x] Agent collects file_status entries during backup, uploads after completion
- [x] Catalog browse endpoint: `GET /clients/{id}/catalog/{archive_id}` (paginated, searchable)
- [x] Restore tab UI: archive selector, file browser, search, checkboxes, restore button
- [x] Restore job creation: `POST /clients/{id}/restore`
- [x] QueueManager: restore task type with `borg extract` command building
- [x] Restore columns on backup_jobs (restore_archive_id, restore_paths, restore_destination)
- [ ] Repository check/compact/verify operations
- [ ] Manual backup trigger (on-demand) — already done in Phase 3 via plan trigger

### Phase 7: Multi-User ✅
- [x] User management page (admin: list, create, edit, delete)
- [x] Role-based access control (admin sees all, user sees own agents)
- [x] Assign agents to users
- [x] User-scoped dashboard (users see only their agents/jobs/charts/logs)
- [x] Server stats hidden from non-admins
- [x] User profile page (email change, password change)

### Phase 8: Hardening & Polish ✅
- [x] Session security (regenerate ID, httponly/SameSite cookies, 8h timeout)
- [x] Rate limiting on login (5/5min) and agent API (20/5min)
- [x] Queue cancel/retry with confirmation dialogs
- [x] Progress bars and error tooltips on queue page
- [x] SMTP notification service (email on failure to all admins)
- [x] Memcached integration (Cache service, dashboard server stats cached)
- [x] Encrypted passphrase storage (AES-256-GCM, APP_KEY, migration script)
- [ ] Agent auto-update mechanism
- [x] Backup templates (8 server role presets, CRUD in settings, JSON API for form pre-fill)
- [x] Excludes as separate field (new column, BorgCommandBuilder --exclude flags, QueueManager passthrough)
- [x] Borg options as checkboxes (compression, exclude-caches, one-file-system, noatime, numeric-ids, noxattrs, noacls)
- [x] Quick-pick directory buttons (common paths appended to textarea)
- [x] BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK env var in buildEnv()

### Phase 9: Documentation & Release
- [x] Installation guide — `docs/INSTALL.md`
- [x] Agent deployment guide — `docs/AGENT.md`
- [x] User guide — `docs/USER_GUIDE.md`
- [x] API reference — `docs/API.md`
- [x] Contributing guide — `docs/CONTRIBUTING.md`
- [x] License — `LICENSE` (MIT + Beer-Ware Addendum)
- [x] README.md — project overview, quick start, doc links, architecture
- [ ] borgbackupserver.com website

---

## Notes

- The old prototype had licensing/serial number logic - this is removed since the rebuild is open-source
- The "Method" column on client detail changes from "Direct SSH" to "HTTPS Agent"
- The agent poll interval should be configurable per-agent or globally (default 30s)
- Borg encryption passphrase is stored encrypted on the server and sent to the agent only when needed for a task
- The server runs a scheduler process (cron or long-running PHP script) that checks schedules and enqueues jobs
