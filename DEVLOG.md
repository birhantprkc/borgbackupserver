# Borg Backup Server - Development Log

> **Start of session instructions:** Read this file and `PROJECT_PLAN.md` to restore context.

---

## Project Quick Reference

- **Project path:** `/Volumes/Frogger1/Projects/bbs`
- **Dev server:** `cd public && php -S localhost:8080`
- **Database:** MySQL, database name `bbs`, user `root`, password `quadra65`
- **MySQL CLI:** passwordless via `~/.my.cnf`
- **Login credentials:** `admin` / `admin`
- **Migrations:** `php migrate.php` from project root
- **Design screenshots:** `/design/` folder (9 screenshots of old prototype)
- **Logos:** `/assets/images/bbs-logo.png` and `bbs-logo-small.png` (also copied to `public/images/`)

---

## Architecture Decisions

- **HTTPS agent instead of SSH** - Agent polls server outbound, no inbound ports needed on endpoints, API key auth instead of SSH keys. Firewall-friendly, simpler installation.
- **Backup Plan concept** - A "Backup Plan" ties together: target directories, repository, borg CLI options, and retention policy. Schedules reference a plan.
- **Queue with max concurrency** - Server setting `max_queue` (default 4) controls how many backup jobs run simultaneously.
- **Progress tracking** - Agent pre-counts files before backup, then streams borg's `--log-json` output back to server for real-time progress bars.

---

## Session 1 — 2026-01-28

### Phase 1: Foundation (COMPLETED)

Built the entire project skeleton:

**Core classes (`src/Core/`):**
- `Config.php` — Loads `.env` via phpdotenv, static `get()` method
- `Database.php` — PDO singleton with `query()`, `fetchOne()`, `fetchAll()`, `insert()`, `update()`, `delete()`, `count()`
- `Migrator.php` — Reads SQL files from `/migrations/`, tracks executed migrations in `migrations` table
- `App.php` — Bootstraps config, session, AltoRouter. All routes registered here.
- `Controller.php` — Base controller with: `view()`, `authView()`, `redirect()`, `json()`, `requireAuth()`, `requireAdmin()`, `csrfToken()`, `verifyCsrf()`, `flash()`, `getFlash()`, `currentUser()`, `isAdmin()`

**Database (11 tables):**
`users`, `agents`, `repositories`, `backup_plans`, `schedules`, `backup_jobs`, `archives`, `server_log`, `settings`, `storage_locations`, `migrations`

Default settings seeded: `max_queue=4`, `server_host`, `agent_poll_interval=30`, SMTP fields.
Default admin user seeded: `admin` / `admin` (bcrypt hashed).

**Layouts (`src/Views/layouts/`):**
- `app.php` — Main layout: dark sidebar (90px wide, icon+label nav), white topbar with page title, alert bell with error badge, user dropdown. Includes flash message rendering.
- `auth.php` — Centered card layout for login page with logo.

**Controllers and pages built:**
| Controller | Routes | View |
|---|---|---|
| `AuthController` | GET/POST `/login`, GET `/logout` | `auth/login.php` |
| `DashboardController` | GET `/`, `/dashboard` | `dashboard/index.php` — 4 stat cards (agents, running, queue, errors), active jobs table, recently completed table, server log feed |
| `ClientController` | GET `/clients`, `/clients/add`, `/clients/{id}`, POST `/clients/add`, `/clients/{id}/delete` | `clients/index.php` — list with Name/Version/Restore Points/Schedules/Repos/Size/Owner/Status; `clients/add.php` — name + user assignment; `clients/detail.php` — tabbed detail (Status/Repos/Schedules/Restore/Install Agent/Delete) |
| `RepositoryController` | POST `/repositories/create`, `/repositories/{id}/delete` | Inline in client detail repos tab |
| `ScheduleController` | POST stubs for create/toggle/delete | Stub — returns "available in Phase 3" |
| `QueueController` | GET `/queue` | `queue/index.php` — in-progress + recently completed tables |
| `LogController` | GET `/log` | `log/index.php` — filterable by level (all/info/warning/error) |
| `SettingsController` | GET/POST `/settings`, POST `/settings/storage/add`, `/settings/storage/{id}/delete` | `settings/index.php` — server config (max queue, server host, poll interval), SMTP config, storage locations table with add/delete |
| `UserController` | GET `/users`, POST `/users/add`, `/users/{id}/edit`, `/users/{id}/delete` | `users/index.php` — user table with create form, role badges, agent counts, delete |

**API routes registered (controllers not yet built):**
- POST `/api/agent/register`
- GET `/api/agent/tasks`
- POST `/api/agent/progress`, `/api/agent/status`, `/api/agent/heartbeat`, `/api/agent/info`

**Static assets:**
- `public/css/style.css` — Sidebar styling (#2c3e50 dark), active state (orange #e67e22), stat card hover, table header styling
- `public/images/` — Logo files copied from assets
- `public/.htaccess` — Apache rewrite rules for front controller
- Bootstrap 5.3.3 + Bootstrap Icons 1.11.3 loaded via CDN

### Phase 2: Client & Repository Management (COMPLETED)

- Client list page shows all agents with computed columns (repo count, schedule count, total size, restore points)
- Add Client generates 64-char hex API key via `bin2hex(random_bytes(32))`
- Client detail page has 6 tabs via `?tab=` query param: status, repos, schedules, restore, install, delete
- Status tab shows repo summary cards, schedule summary cards, recent backup jobs table
- Repos tab shows visual cards (name, size, recovery points) + create form with encryption dropdown (repokey-blake2, authenticated-blake2, repokey, none) and auto-generated passphrase (5 segments, e.g. `A1B2-C3D4-E5F6-G7H8-I9J0`)
- Install Agent tab shows curl one-liner with API key and a copy button
- Delete tab with confirmation
- Storage locations: add from settings (label, path, max GB, default checkbox), delete with confirmation
- Role-based access throughout: admin sees all agents, users see only their own (filtered by `user_id`)

### What's Next: Phase 3

Backup Plans & Scheduling:
- Backup Plan CRUD (name, agent, repo, directories, borg options, retention)
- Schedule CRUD (frequency, times, day of week/month)
- Schedule creation form on client detail schedules tab
- Scheduler service (checks `next_run`, creates queued jobs)
- Queue manager (enforces max_queue, assigns jobs to agents on poll)
- BorgCommandBuilder service (translates plan into borg CLI arguments)

---

## File Index

```
borgbackupserver/
├── composer.json                    # PSR-4 autoload, altorouter, phpdotenv
├── composer.lock
├── migrate.php                      # CLI migration runner
├── PROJECT_PLAN.md                  # Full project plan with architecture, schema, phases
├── DEVLOG.md                        # This file
├── .gitignore                       # vendor/, config/.env, logs, .DS_Store
├── config/
│   ├── .env                         # DB_HOST, DB_NAME, DB_USER, DB_PASS, APP_* (gitignored)
│   └── .env.example
├── migrations/
│   ├── 001_initial_schema.sql       # All 10 tables + default settings
│   ├── 002_seed_admin.sql           # Default admin user
│   ├── 003_file_catalog.sql         # file_catalog table for searchable backup contents
│   ├── 004_restore_columns.sql      # restore_archive_id, restore_paths, restore_destination on backup_jobs
│   ├── 005_rate_limiting.sql        # rate_limits table
│   ├── 006_encrypt_passphrases.php  # Encrypt existing plaintext passphrases
│   ├── 007_backup_templates.sql     # backup_templates table + excludes column on backup_plans
│   ├── 008_user_timezone.sql        # timezone column on users
│   ├── 009_update_borg_task.sql     # update_borg task type
│   └── 010_notifications.sql        # notifications table + notification settings
├── public/
│   ├── index.php                    # Front controller
│   ├── .htaccess                    # Apache rewrite
│   ├── css/style.css
│   └── images/                      # Logos
├── src/
│   ├── Core/
│   │   ├── App.php                  # Bootstrap + route registration
│   │   ├── Config.php               # .env loader
│   │   ├── Controller.php           # Base controller (auth, csrf, view, flash, json)
│   │   ├── Database.php             # PDO singleton wrapper
│   │   └── Migrator.php             # SQL migration runner
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── ClientController.php
│   │   ├── DashboardController.php
│   │   ├── LogController.php
│   │   ├── NotificationController.php # Notification list, mark read
│   │   ├── QueueController.php
│   │   ├── RepositoryController.php
│   │   ├── BackupPlanController.php  # Create/update/delete/trigger plans
│   │   ├── ScheduleController.php   # Toggle/delete schedules
│   │   ├── SettingsController.php
│   │   ├── UserController.php
│   │   ├── ProfileController.php    # Email + password change
│   │   └── Api/
│   │       └── AgentApiController.php # Agent polling, progress, catalog, heartbeat
│   ├── Models/                      # Empty — queries are in controllers for now
│   ├── Middleware/                   # Empty — auth/csrf handled in base Controller
│   ├── Services/
│   │   ├── BorgCommandBuilder.php   # Builds borg CLI commands for all operations
│   │   ├── SchedulerService.php     # Checks due schedules, creates queued jobs
│   │   ├── QueueManager.php         # Enforces max_queue, promotes queued→sent, builds agent payloads
│   │   ├── NotificationService.php   # Notify, resolve, dedup, email, cleanup
│   │   ├── Cache.php                # Memcached singleton with graceful fallback
│   │   ├── Mailer.php               # Raw socket SMTP with STARTTLS
│   │   └── Encryption.php           # AES-256-GCM encrypt/decrypt
│   └── Views/
│       ├── layouts/
│       │   ├── app.php              # Main layout (sidebar + topbar)
│       │   └── auth.php             # Login layout
│       ├── auth/login.php
│       ├── dashboard/index.php
│       ├── clients/
│       │   ├── index.php            # Client list
│       │   ├── add.php              # Add client form
│       │   └── detail.php           # Tabbed detail (status/repos/schedules/restore/install/delete)
│       ├── queue/index.php
│       ├── log/index.php
│       ├── notifications/index.php   # Notification list with mark-read
│       ├── settings/index.php       # Tabbed: General, Notifications, Storage, Templates
│       ├── users/index.php
│       └── profile/index.php
├── scheduler.php                    # CLI scheduler — run via cron every minute
├── storage/logs/
├── assets/images/                   # Source logos
└── design/                          # 9 prototype screenshots for reference
```

---

## Session 1 (continued) — Phase 3

### Phase 3: Backup Plans & Scheduling (COMPLETED)

**New Controllers:**
- `BackupPlanController` — Full CRUD for backup plans. `store()` creates both a backup_plan and its associated schedule in one form submission. `trigger()` creates a manual queued job. `update()` and `delete()` with access control.
- `ScheduleController` — Rewritten from stubs. `toggle()` enables/disables schedule. `delete()` removes schedule.

**New Routes (in App.php):**
- POST `/plans/create`, `/plans/{id}/edit`, `/plans/{id}/delete`, `/plans/{id}/trigger`
- POST `/schedules/{id}/toggle`, `/schedules/{id}/delete`

**Schedules Tab (client detail):**
- Shows existing plans as cards with: name, frequency, times, repo, directories, retention summary
- Action buttons per plan: Run Now (trigger), Pause/Enable (toggle), Delete
- Status badges: Active (green), Manual/On Demand (blue), Paused (grey)
- Create form with: name, frequency dropdown (manual/10min/15min/30min/hourly/daily/weekly/monthly), times field (comma-separated 24h), day of week (for weekly), day of month (for monthly), repository selector, directories field, advanced borg options textarea with common examples, prune retention (6 numeric inputs: minutes/hours/days/weeks/months/years)
- JavaScript shows/hides times/day fields based on frequency selection
- Form requires at least one repository to exist (shows warning if none)

**Services:**
- `BorgCommandBuilder` — Static methods to build borg CLI commands:
  - `buildCreateCommand()` — borg create with --log-json, --progress, advanced options, repo::archive, directories
  - `buildPruneCommand()` — borg prune with --keep-minutely/hourly/daily/weekly/monthly/yearly
  - `buildInitCommand()` — borg init with encryption
  - `buildListCommand()` — borg list --json
  - `buildInfoCommand()` — borg info --json
  - `buildExtractCommand()` — borg extract with optional destination and path selection
  - `generateArchiveName()` — e.g. "main-2026-01-28_21-35-09"
  - `buildEnv()` — sets BORG_PASSPHRASE from repo passphrase
  - `toTaskPayload()` — wraps command + env into JSON payload for agent

- `SchedulerService` — `run()` method:
  - Finds all enabled schedules where next_run <= now
  - Skips if plan already has a queued/sent/running job (prevents duplicates)
  - Creates queued backup_job, logs it, calculates and sets next next_run
  - Handles all frequency types: interval-based (10/15/30min, hourly), time-based (daily with multiple times, weekly, monthly)

- `QueueManager`:
  - `processQueue()` — counts active jobs, fills available slots from queued jobs FIFO, promotes to 'sent', logs
  - `getTasksForAgent()` — returns task payloads for a specific agent (used by agent API in Phase 4)

**CLI:**
- `scheduler.php` — Combines SchedulerService + QueueManager. Cron line: `* * * * * php /path/to/scheduler.php`

**Tested:**
- Created storage location, repository, backup plan with daily schedule (4 times/day)
- next_run correctly calculated as next occurrence of first scheduled time
- Manual trigger creates queued job with server_log entry
- scheduler.php promotes queued→sent and logs it
- All pages (dashboard, queue, log) reflect the job data

### Phase 4: Agent (COMPLETED)

**Server-side API (`src/Controllers/Api/AgentApiController.php`):**
- `authenticateAgent()` — validates Bearer token from Authorization header, updates heartbeat + status on every request
- `register()` — receives hostname, OS, borg version, agent version; returns agent ID, name, poll interval
- `tasks()` — runs QueueManager to promote queued→sent, then returns task payloads for this agent. Each payload includes full borg command array, env vars (BORG_PASSPHRASE), job_id, archive_name, directories
- `progress()` — updates files_total, files_processed, bytes; sets started_at on first call, status to 'running'
- `status()` — records completion or failure, calculates duration, creates archive record on success, updates repo size/archive_count
- `heartbeat()` — returns server_time (auth already updates heartbeat)
- `info()` — updates agent OS/borg/version info
- `getJsonInput()` — parses JSON request body from php://input

**Python Agent (`agent/bbs-agent.py`):**
- Single-file, no dependencies beyond Python 3 stdlib
- `load_config()` — reads /etc/bbs-agent/config.ini (overridable via BBS_AGENT_CONFIG env)
- `register()` — sends system info (hostname, OS, borg version, IP), receives poll interval
- `get_system_info()` — reads /etc/os-release, runs `borg --version`, detects IP via UDP socket
- `count_files()` — os.walk pre-count for progress tracking
- `execute_task()` — runs borg subprocess, parses --log-json stderr for archive_progress entries, reports progress every 5s, handles return codes (0=success, 1=warnings/success, 2+=failure), reports final status with archive stats
- `main()` — SIGTERM/SIGINT handlers for graceful shutdown, register then poll loop
- Env overrides: BBS_AGENT_CONFIG, BBS_AGENT_LOG for development

**Service files:**
- `agent/bbs-agent.service` — systemd unit, restart on failure, runs as root
- `agent/com.borgbackupserver.agent.plist` — macOS launchd, KeepAlive, logs to /var/log/bbs-agent.log

**Installer (`agent/install.sh`):**
- `--server URL --key API_KEY` arguments
- Detects OS via /etc/os-release or uname
- Installs borg: apt (debian/ubuntu/pop/mint), yum/dnf (centos/rhel/rocky/alma), dnf (fedora), pacman (arch/manjaro), zypper (opensuse), brew (macOS)
- Installs agent to /opt/bbs-agent/, config to /etc/bbs-agent/config.ini (chmod 600)
- Sets up systemd service (Linux) or launchd daemon (macOS)

**Heartbeat monitoring (scheduler.php):**
- Step 3 added: marks agents offline if last_heartbeat > 3x poll_interval ago

**Integration tested:**
- Agent started → registered with server (hostname, OS, borg version)
- Polled → received backup task with full borg command
- Pre-counted 8,601 files in /home /var /etc
- Attempted borg (failed: not installed on dev Mac) → reported failure to server
- Server recorded: job status=failed, files_total=8601, error_log="borg command not found"
- Clean shutdown on SIGTERM

**New files:**
- `src/Controllers/Api/AgentApiController.php`
- `agent/bbs-agent.py`
- `agent/bbs-agent.service`
- `agent/com.borgbackupserver.agent.plist`
- `agent/install.sh`

### Phase 5: Dashboard & Monitoring (COMPLETED)

**New Service — `ServerStats.php`:**
- `getCpuLoad()` — sys_getloadavg() with core count (sysctl on macOS, nproc on Linux), percent calculation capped at 100%
- `getMemory()` — Linux: parses /proc/meminfo; macOS: sysctl hw.memsize + vm_stat page counts
- `getPartitions()` — df command parsing + storage_locations from DB (deduped), cross-platform
- `formatBytes()` — human-readable byte formatting (B/KB/MB/GB/TB)

**Dashboard rebuilt (`dashboard/index.php`):**
- 4 stat cards with AJAX-updatable element IDs (agents, running, queued, errors)
- 24h bar chart (Chart.js 4.4.7) — backups completed per hour, hourly labels, every-3rd label shown
- Server Stats card: CPU load + memory progress bars with color coding (green/yellow/red thresholds)
- Partition Usage table with progress bars and color-coded thresholds
- Active Backup Jobs with animated striped progress bars (files processed/total)
- Recently Completed table with duration formatting
- Server Log feed (15 entries, level badges)
- 15-second AJAX auto-refresh via setInterval hitting `/dashboard/json`

**DashboardController updates:**
- `getDashboardData()` — returns all dashboard data including chart data (24h hourly buckets with gap-filling), server stats
- `apiJson()` — JSON endpoint for AJAX refresh (renamed from `json()` to avoid parent method conflict)
- Route: GET `/dashboard/json` → `DashboardController@apiJson`

**Tested:**
- JSON endpoint returns valid data: agent count, CPU %, memory %, partition count, 24 chart data points
- Dashboard page renders all sections (chart, stats, tables, log)
- AJAX refresh targets correct element IDs

### Phase 6: File Catalog & Restore (COMPLETED)

**New Migration — `003_file_catalog.sql`:**
- `file_catalog` table: BIGINT id, archive_id (FK), agent_id (FK), file_path (TEXT), file_name (VARCHAR 255), file_size, status (ENUM A/M/U/E), mtime
- Indexes: `(agent_id, file_name)`, `(archive_id)`, `(agent_id, file_name, archive_id)`

**New Migration — `004_restore_columns.sql`:**
- Added to backup_jobs: `restore_archive_id` (INT), `restore_paths` (JSON), `restore_destination` (VARCHAR 512)

**Agent API — `POST /api/agent/catalog`:**
- Accepts batched file entries: `{ archive_id, files: [{ path, size, status, mtime }] }`
- Bulk INSERT for performance (1000 files per batch from agent)
- Extracts `file_name` (basename) server-side for search indexing
- `status()` now returns `archive_id` in response so agent can upload catalog

**BorgCommandBuilder update:**
- Added `--list` flag to `buildCreateCommand()` so borg outputs file status entries in JSON log

**Python Agent updates (`bbs-agent.py`):**
- Collects `file_status` entries from borg's `--list --log-json` output during backup
- New `upload_catalog()` function: sends file entries in batches of 1000 after successful completion
- `execute_task()` now captures `archive_id` from status response and triggers catalog upload

**Restore Tab UI (`clients/detail.php`):**
- Archive selector dropdown (repo name, file count, date)
- Search box with AJAX file catalog browsing (paginated, 100 per page)
- Scrollable file table with checkboxes, status badges (New/Modified/Unchanged/Error)
- Select All / Deselect All buttons
- Optional restore destination field
- Restore button creates restore job via POST form with CSRF protection
- Pagination (prev/next) for large catalogs

**Client-facing endpoints:**
- `GET /clients/{id}/catalog/{archive_id}` — paginated JSON with search (`?search=nginx&page=2`)
- `POST /clients/{id}/restore` — creates queued restore job with selected file paths

**QueueManager restore support:**
- `buildRestorePayload()` — builds `borg extract` command from archive + selected paths + optional destination
- Both `processQueue()` and `getTasksForAgent()` handle `task_type = 'restore'`

**Tested:**
- Migration ran successfully
- Catalog API inserts entries correctly (tested with mock data)
- Catalog browse endpoint returns paginated results sorted by path
- Search filters by file_name and file_path (LIKE matching)
- Restore tab renders with archive selector and file browser
- Restore job creation queues correctly

**New/modified files:**
- `migrations/003_file_catalog.sql` (new)
- `migrations/004_restore_columns.sql` (new)
- `src/Controllers/Api/AgentApiController.php` (catalog endpoint + archive_id in status response)
- `src/Controllers/ClientController.php` (catalog + restoreSubmit methods, archives data)
- `src/Core/App.php` (3 new routes)
- `src/Services/QueueManager.php` (restore task handling)
- `src/Services/BorgCommandBuilder.php` (--list flag)
- `src/Views/clients/detail.php` (restore tab UI)
- `agent/bbs-agent.py` (catalog collection + upload)

### Phase 7: Multi-User Scoping & Profile (COMPLETED)

**User-scoped dashboard:**
- All dashboard queries now filter by `user_id` for non-admin users
- Stat cards (agents, running, queued, errors) scoped per user
- Recent jobs, active jobs, server log, and chart data all filtered
- Server stats (CPU, memory, partitions) visible to admins only
- Chart column width adjusts when server stats hidden
- AJAX refresh respects admin/user scoping

**Profile page (`/profile`):**
- New `ProfileController` with index/update methods
- Email change with duplicate check
- Password change with current password verification, confirmation match, min length 6
- Read-only fields: username, role, member since
- Profile link added to user dropdown in sidebar layout

**New files:**
- `src/Controllers/ProfileController.php`
- `src/Views/profile/index.php`

**Modified:**
- `src/Controllers/DashboardController.php` (user-scoped queries, conditional server stats)
- `src/Views/dashboard/index.php` (conditional server stats sections, scoped AJAX)
- `src/Views/layouts/app.php` (profile link in dropdown)
- `src/Core/App.php` (profile routes)

### Phase 8: Hardening & Polish (COMPLETED)

**Session security:**
- `session_regenerate_id(true)` on login to prevent session fixation
- Secure cookie params: httponly, SameSite=Strict, lifetime=0 (session cookie)
- Session timeout: 8 hours of inactivity, auto-logout with flash message
- `last_activity` tracked on every authenticated request

**Rate limiting:**
- New `rate_limits` table (ip, endpoint, attempts, window_start)
- `checkRateLimit()` / `resetRateLimit()` helper methods in base Controller
- Login: 5 attempts per 5 minutes, reset on success
- Agent API: 20 failed auth attempts per 5 minutes, returns 429
- Auto-cleanup of expired rate limit entries

**Queue cancel/retry:**
- Cancel button on queued/sent jobs (sets status=failed, logs cancellation)
- Retry button on failed jobs (creates new queued job from failed job data)
- Routes: `POST /queue/{id}/cancel`, `POST /queue/{id}/retry`
- Confirmation dialogs on both actions
- Progress bars on running jobs in queue view
- Error tooltips on failed jobs (hover to see error message)

**New migration — `005_rate_limiting.sql`:**
- `rate_limits` table with ip_address, endpoint, attempts, window_start

**Modified files:**
- `src/Core/App.php` (secure session cookies, queue routes)
- `src/Core/Controller.php` (session timeout, rate limiting helpers)
- `src/Controllers/AuthController.php` (session regeneration, login rate limit)
- `src/Controllers/Api/AgentApiController.php` (API auth rate limiting)
- `src/Controllers/QueueController.php` (cancel/retry methods)
- `src/Views/queue/index.php` (cancel/retry buttons, progress bars, error tooltips)

### Phase 8b: Remaining Items (COMPLETED)

**Memcached integration (`src/Services/Cache.php`):**
- Singleton with graceful fallback (works without memcached installed)
- `get()`, `set()`, `delete()`, `flush()`, `remember()` (get-or-compute pattern)
- Connects to MEMCACHED_HOST/MEMCACHED_PORT from .env (defaults: 127.0.0.1:11211)
- Dashboard caches server stats: CPU/memory (10s TTL), partitions (30s TTL)
- PHP memcached extension installed via manual compile on macOS (pecl had zlib path issues)
- memcached server installed via brew

**SMTP email notifications (`src/Services/Mailer.php`):**
- Raw socket SMTP client with STARTTLS support (port 587)
- AUTH LOGIN authentication
- `send()` — sends email to a single recipient
- `notifyFailure()` — sends failure notification to all admin users
- Integrated into AgentApiController `status()` — auto-emails admins on backup failure
- Settings-driven: uses smtp_host, smtp_port, smtp_user, smtp_pass, smtp_from_email, smtp_from_name from settings table
- Gracefully disabled when SMTP not configured

**Encrypted passphrase storage (`src/Services/Encryption.php`):**
- AES-256-GCM encryption with random 12-byte nonce
- Key derived from APP_KEY in .env via SHA-256
- `encrypt()` / `decrypt()` static methods
- Packed format: base64(nonce + auth_tag + ciphertext)
- Repository passphrases now encrypted at rest
- BorgCommandBuilder decrypts on-the-fly when building agent task payloads
- Backwards compatible: falls back to plaintext if decryption fails (pre-migration data)
- Migration script `006_encrypt_passphrases.php` encrypts existing plaintext passphrases
- APP_KEY added to .env and .env.example

**New files:**
- `src/Services/Cache.php`
- `src/Services/Mailer.php`
- `src/Services/Encryption.php`
- `migrations/006_encrypt_passphrases.php`

**Modified:**
- `src/Controllers/DashboardController.php` (cache integration)
- `src/Controllers/Api/AgentApiController.php` (email on failure)
- `src/Controllers/RepositoryController.php` (encrypt passphrase on store)
- `src/Services/BorgCommandBuilder.php` (decrypt passphrase in buildEnv)
- `config/.env` (APP_KEY added)
- `config/.env.example` (APP_KEY placeholder)

### Phase 8c: Backup Plan UX Improvements (COMPLETED)

**Backup templates:**
- New `backup_templates` table (name, description, directories, excludes, advanced_options)
- 8 seed templates: Web Server, MySQL, PostgreSQL, Mail Server, Interworx Server, File Server, Docker Host, Minimal
- Settings page: template CRUD (add, inline edit, delete) in new Templates section
- JSON API endpoint `GET /settings/templates/{id}/json` for AJAX pre-fill
- Template selector dropdown on schedules tab auto-fills directories, excludes, and options

**Excludes field:**
- New `excludes` column on `backup_plans` table (added in migration 007)
- Separate textarea below directories in create/edit form
- `BorgCommandBuilder::buildCreateCommand()` parses excludes (one per line) into `--exclude` flags
- `QueueManager` passes `excludes` through in plan array for both `processQueue()` and `getTasksForAgent()`
- `BackupPlanController` handles excludes in store() and update()

**Borg options as checkboxes:**
- Replaced freeform "advanced options" textarea with checkboxes: compression, exclude-caches, one-file-system, noatime, numeric-ids, noxattrs, noacls
- Compression type selector (lz4/zstd/zlib/none) shown when compression checked
- JavaScript builds `advanced_options` string from selected checkboxes on form submit
- Hidden input carries the assembled value

**Quick-pick directory buttons:**
- Common directory buttons (/home, /etc, /var, /opt, /srv, /root, /usr/local) below directories textarea
- Click to append directory path to textarea
- Replaces deferred directory tree browser (poll latency makes tree impractical)

**`BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK=yes`:**
- Added to `BorgCommandBuilder::buildEnv()` — needed because agent runs on different machine than repo creator

**New migration — `007_backup_templates.sql`:**
- `backup_templates` table with seed data (8 server role templates)
- `ALTER TABLE backup_plans ADD COLUMN excludes TEXT DEFAULT NULL`

**New routes:**
- POST `/settings/templates/add`, `/settings/templates/{id}/edit`, `/settings/templates/{id}/delete`
- GET `/settings/templates/{id}/json`

**Modified files:**
- `migrations/007_backup_templates.sql` (new)
- `src/Controllers/SettingsController.php` (template CRUD + JSON endpoint)
- `src/Controllers/BackupPlanController.php` (excludes field handling)
- `src/Services/BorgCommandBuilder.php` (exclude patterns, BORG_UNKNOWN env var, --list flag)
- `src/Services/QueueManager.php` (excludes in plan array and SQL queries)
- `src/Views/clients/detail.php` (template selector, checkboxes, excludes, quick-pick buttons)
- `src/Views/settings/index.php` (templates section)
- `src/Core/App.php` (template routes)

### Phase 8d: UI Cleanup & Restore Improvements (COMPLETED)

**Table-based layouts:**
- Replaced card-based repo display on Repos tab with a clean table (Name, Storage, Encryption, Size, Archives, Actions)
- Replaced card-based schedule display on Schedules tab with a table (Plan, Frequency, Repository, Directories, Retention, Status, Actions)
- Added inline collapsible edit form per plan row (pencil button expands edit row below with all plan fields)
- Status tab: repos summary simplified to compact stat cards; schedules summary replaced with table

**Collapsible file tree for restore:**
- New endpoint `GET /clients/{id}/catalog/{archive_id}/tree?path=/` — returns immediate children (subdirectories with file counts/sizes, and files) for a given path prefix
- Built from flat `file_catalog` table using `SUBSTRING_INDEX` grouping to extract directory segments
- Lazy-loading: clicking a directory fetches its children on demand (no full tree loaded at once)
- Checkboxes cascade: checking a directory selects everything under it, removes redundant child selections
- Right-side selection panel shows chosen paths with remove buttons
- Search still works alongside tree (separate panel with paginated results and checkboxes)
- Selecting a directory path (e.g. `/etc/`) sends it to `borg extract` which restores the entire subtree

**Download as tar.gz:**
- New endpoint `POST /clients/{id}/download` — extracts selected paths server-side and streams as `.tar.gz`
- Runs `borg extract` to a temp directory on the server (repos are local to server)
- Pipes through `tar czf -` to stream directly to browser without writing the archive to disk
- Cleanup of temp directory in `finally` block
- Two buttons in restore UI: "Restore to Client" (queues agent job) and "Download .tar.gz" (immediate server-side download)

**Borg path fix:**
- `BorgCommandBuilder::buildExtractCommand()` now strips leading `/` from paths — borg extract expects `etc/nginx` not `/etc/nginx`
- Applied to both agent restore jobs and server-side downloads

**New routes:**
- `GET /clients/{id}/catalog/{archive_id}/tree` → `ClientController@catalogTree`
- `POST /clients/{id}/download` → `ClientController@download`

**Modified files:**
- `src/Controllers/ClientController.php` (catalogTree, download, removeDir methods)
- `src/Services/BorgCommandBuilder.php` (ltrim paths in buildExtractCommand)
- `src/Views/clients/detail.php` (table layouts, collapsible tree UI, download button)
- `src/Core/App.php` (2 new routes)

### Phase 9: Documentation (COMPLETED)

**New files in `docs/`:**
- `INSTALL.md` — Server installation guide: system packages, database setup, env config, APP_KEY generation, migrations, web server (Apache/Nginx), SSL, cron scheduler, file permissions, post-install checklist, upgrading, troubleshooting
- `AGENT.md` — Agent deployment guide: architecture diagram, auto-install via curl one-liner, manual install steps, config reference, systemd/launchd service setup, agent lifecycle (registration, polling, backup execution, heartbeat), management commands, uninstall, security considerations, troubleshooting
- `USER_GUIDE.md` — End-user guide: login/roles, dashboard overview, client management (all tabs), creating backup plans (step-by-step with all fields), templates reference, borg options table, monitoring (queue/log), restoring files (tree browse, search, restore to client, download as tar.gz), settings, user management, profile
- `API.md` — API reference: authentication (Bearer token), rate limiting, all 7 agent endpoints with request/response examples (register, tasks, progress, status, heartbeat, info, catalog), error responses, internal web API endpoints (dashboard JSON, catalog, tree, templates)

**Additional files:**
- `LICENSE` — MIT License with Beer-Ware Addendum (if this software saved your backups, buy the maintainer a beer)
- `docs/CONTRIBUTING.md` — Development setup, project structure, conventions, migration guide, areas needing help
- `README.md` — Project overview, features list, quick start, documentation links, architecture diagram, tech stack

### Session 2 — 2026-01-29

**Per-user timezone:**
- Added `timezone` column to `users` table (default `America/New_York`)
- Profile page has timezone dropdown (common zones + full list)
- Stored in session at login, applied via `date_default_timezone_set()` on every authenticated request
- App-wide fallback: `America/New_York`

**Relative "Last Seen" timestamps:**
- Client detail header shows "4m ago", "2h ago", "3d ago" instead of full datetime

**Borg update feature:**
- New `update_borg` task type added to `backup_jobs` enum
- "Update Borg" button on client detail header (next to borg version display)
- `ClientController::updateBorg()` queues the job
- `QueueManager` sends simple `{ task: "update_borg" }` payload
- Agent `execute_update_borg()` detects OS package manager (apt, dnf, yum, pacman, brew, pip3) and runs update
- After successful update, agent re-reports system info so borg version refreshes

**Client detail header redesign:**
- Shows borg version and agent version in info line
- Stats row: Repositories, Archives, Total Size, Backup Plans, Last Backup, Last Seen
- Inline edit form (pencil button) for client name
- `ClientController::update()` method + `POST /clients/{id}/edit` route

**Dashboard improvements:**
- Renamed "Agents" to "Clients" for consistency
- All 4 stat cards are clickable links (Clients→/clients, Running→/queue, Queue→/queue, Errors→/log?level=error)

**Layout overhaul:**
- Moved logo from sidebar into full-width top navbar
- Logo sits in 90px-wide area with light gray background (`#f5f5f5`), aligned with sidebar
- Topbar: light blue-gray (`#dce6f0`), sticky, page title + bell + user dropdown
- Sidebar: starts below topbar, sticky, tighter icon/text spacing

**Normalized file catalog:**
- New `file_paths` table: stores each unique path once per agent (ROW_FORMAT=COMPRESSED)
- `file_catalog` refactored to junction table: composite PK `(archive_id, file_path_id)` + file_size, status, mtime
- `AgentApiController::catalog()` does `INSERT IGNORE` into `file_paths`, fetches IDs, then inserts into junction
- `ClientController` catalog/tree queries updated to JOIN through `file_paths`
- Dramatically reduces storage for repeated backups (two integers vs full path strings per entry)

**Clean schema.sql:**
- Single-file database setup for new installs: `mysql -u root -p bbs < schema.sql`
- Contains all tables, indexes, seed data (admin user, settings, templates)
- README and INSTALL.md updated to reference `schema.sql`
- Incremental migrations kept in `migrations/` for reference

**GitHub repo:**
- Dashboard screenshot added to README
- All changes pushed to github.com/marcpope/borgbackupserver

**New files:**
- `migrations/008_user_timezone.sql`
- `migrations/009_update_borg_task.sql`
- `schema.sql`
- `docs/images/dashboard.png`

**Modified files:**
- `src/Core/App.php` (timezone, update-borg route, edit route)
- `src/Core/Controller.php` (per-user timezone in requireAuth)
- `src/Controllers/AuthController.php` (timezone in session)
- `src/Controllers/ClientController.php` (update, updateBorg, normalized catalog queries)
- `src/Controllers/ProfileController.php` (timezone update)
- `src/Controllers/Api/AgentApiController.php` (normalized catalog insert)
- `src/Services/QueueManager.php` (update_borg task handling)
- `src/Views/clients/detail.php` (header redesign, borg version, edit form, relative timestamps)
- `src/Views/dashboard/index.php` (clickable stat cards, Clients rename)
- `src/Views/layouts/app.php` (logo in topbar, layout restructure)
- `src/Views/profile/index.php` (timezone dropdown)
- `public/css/style.css` (topbar, sidebar, logo styling)
- `migrations/003_file_catalog.sql` (normalized schema for fresh installs)
- `README.md` (screenshot, schema.sql instructions)
- `docs/INSTALL.md` (schema.sql instructions)
- `agent/bbs-agent.py` (execute_update_borg, task routing)

### Notification System (COMPLETED)

**Concept:** Notifications are active system problems (not activity logs). They represent ongoing issues that get auto-resolved or manually acknowledged. The `server_log` table remains the audit trail.

**New table — `notifications`:**
- `type` ENUM: `backup_failed`, `agent_offline`, `storage_low`, `missed_schedule`
- `agent_id`, `reference_id` (plan_id or storage_location_id depending on type)
- `severity` ENUM: `warning`, `critical`
- `occurrence_count` — deduplication: same type+agent+reference increments instead of creating duplicates
- `first_occurred_at`, `last_occurred_at`, `read_at`, `resolved_at`
- Index on `(resolved_at, read_at)` for unread/unresolved queries

**Deduplication:** Grouping key is `type + agent_id + reference_id`. If a matching unresolved notification exists, increment count, update message, clear `read_at` (reappears as unread).

**New service — `NotificationService.php`:**
- `notify(type, agentId, referenceId, message, severity)` — upsert with deduplication
- `resolve(type, agentId, referenceId)` — sets `resolved_at` on matching unresolved notification
- `markRead(id)`, `markAllRead()` — mark notifications as read
- `unreadCount()` — count of unread AND unresolved (drives bell icon badge)
- `getAll(limit, offset)` — paginated, unresolved first, ordered by last_occurred_at DESC
- `cleanup()` — purges resolved notifications older than `notification_retention_days` setting
- `sendEmailIfEnabled()` — on first occurrence, checks `email_on_{type}` setting and emails all admins

**New controller — `NotificationController.php`:**
- `index()` — list all notifications with mark-read buttons
- `markRead(id)` — POST, mark single notification read
- `markAllRead()` — POST, mark all read

**New view — `notifications/index.php`:**
- "Mark All as Read" button at top
- Table with: type icon, message (with occurrence count badge), client name, severity badge, last occurred time, status (New/Read/Resolved), mark-read button
- Resolved notifications shown dimmed/struck-through
- Type icons: backup_failed=x-circle (red), agent_offline=wifi-off (orange), storage_low=hdd (yellow), missed_schedule=clock-history (orange)

**Trigger points (changes to existing files):**

`AgentApiController.php`:
- `authenticateAgent()` — resolves `agent_offline` on every heartbeat
- `status()` — on failed backup: fires `backup_failed` notification; on completed backup: resolves `backup_failed`

`SchedulerService.php`:
- After queuing a job: resolves `missed_schedule` for that plan
- New query detects overdue schedules where agent is offline: fires `missed_schedule`

`scheduler.php`:
- After marking agents offline: fires `agent_offline` for each newly offline agent
- Fails active jobs (sent/running) for offline agents — frees queue slots, fires `backup_failed` if applicable
- New storage check step: loops `storage_locations`, checks `disk_free_space()`/`disk_total_space()`, fires/resolves `storage_low`
- Calls `NotificationService::cleanup()` to purge old notifications

**Bell icon (`layouts/app.php`):**
- Changed from `server_log` error count to `NotificationService::unreadCount()`
- Links to `/notifications` instead of `/log?level=error`

**Email notification preferences:**
- 4 toggles in settings: `email_on_backup_failed`, `email_on_agent_offline`, `email_on_storage_low`, `email_on_missed_schedule`
- Emails only fire on first occurrence (not on deduplication increments)
- Sends to all admin users via existing Mailer service

**Dashboard — Backup Storage card:**
- Replaced Partition Usage with "Backup Storage" card showing configured storage locations
- Each location shows: label, path, usage progress bar (color-coded), used/total, free space, repo count with total size
- Data from `storage_locations` JOIN `repositories` + live `disk_total_space()`/`disk_free_space()` calls
- Cached 30 seconds

**Dashboard — Partitions restored:**
- Added Partitions card back alongside Backup Storage (stacked in same column as Server Stats)
- Shows all OS partitions (mount, usage bar, free space) so MySQL/system partition growth is visible

**Settings page — tabbed layout:**
- Reorganized into 4 tabs: General, Notifications, Storage, Templates
- Tab selection via `?tab=` query parameter (bookmarkable, preserved on save)
- General: server host, max concurrent jobs, agent poll interval
- Notifications: retention days, storage threshold, SMTP config, email toggle checkboxes
- Storage: storage locations table with add/delete
- Templates: backup templates with add/inline-edit/delete

**New settings:**
- `notification_retention_days` (default 30)
- `storage_alert_threshold` (default 90%)
- `email_on_backup_failed` (default on)
- `email_on_agent_offline` (default on)
- `email_on_storage_low` (default on)
- `email_on_missed_schedule` (default off)

**New routes:**
- `GET /notifications` → `NotificationController@index`
- `POST /notifications/{id}/read` → `NotificationController@markRead`
- `POST /notifications/read-all` → `NotificationController@markAllRead`

**New files:**
- `migrations/010_notifications.sql`
- `src/Services/NotificationService.php`
- `src/Controllers/NotificationController.php`
- `src/Views/notifications/index.php`

**Modified files:**
- `schema.sql` (notifications table, new settings)
- `src/Core/App.php` (3 notification routes)
- `src/Views/layouts/app.php` (bell icon → NotificationService)
- `src/Controllers/Api/AgentApiController.php` (notify/resolve in authenticateAgent + status)
- `src/Services/SchedulerService.php` (resolve missed_schedule, detect overdue + offline)
- `scheduler.php` (agent_offline notify, fail stale jobs, storage check, cleanup)
- `src/Controllers/SettingsController.php` (new allowed keys, checkbox handling, tab redirects)
- `src/Views/settings/index.php` (tabbed layout, notification/email fields)
- `src/Controllers/DashboardController.php` (storage locations data with disk usage)
- `src/Views/dashboard/index.php` (Backup Storage card, Partitions card restored)

### What's Next

- borgbackupserver.com website
- Deploy to test Linux VM for end-to-end testing
