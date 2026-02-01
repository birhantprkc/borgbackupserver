# BBS — Borg Backup Server

## Tech Stack
- **Backend:** PHP 8.1+ (no framework, custom MVC in `src/`)
- **Database:** MySQL 8.0 (NOT SQLite) — all queries use MySQL syntax
- **Frontend:** Bootstrap 5, vanilla JS, server-rendered PHP views
- **Agent:** Python 3 (`agent/bbs-agent.py`) — runs on client machines, polls server for tasks
- **OS:** Ubuntu Server 22.04+ only — no other distros supported

## Project Structure
- `src/Controllers/` — route handlers (extend `src/Core/Controller.php`)
- `src/Services/` — business logic (PluginManager, QueueManager, etc.)
- `src/Views/` — PHP templates (Bootstrap)
- `src/Core/App.php` — routes, `Controller.php` — base controller, `Database.php` — MySQL PDO wrapper
- `agent/bbs-agent.py` — client agent
- `schema.sql` — consolidated DB schema (source of truth for fresh installs)
- `migrations/` — incremental SQL migrations
- `bin/bbs-install` — installer, `bin/bbs-update` — updater
- `scheduler.php` — cron-driven task runner

## Version Numbers
- **Server version:** `VERSION` file in project root (currently `0.8.8-beta`)
- **Agent version:** `AGENT_VERSION` constant in `agent/bbs-agent.py` (currently `1.3.2`) — only bump when agent code changes

## Server Installation Path
Software is installed at `/var/www/bbs/` on all servers. Config lives at `/var/www/bbs/config/.env`.

## Beta / Test Server
- **Host:** `ssh bbs@beta.borgbackupserver.com` (SSH keys installed, no password needed)
- **Sudo:** passwordless sudo available for troubleshooting
- **MySQL access:** `sudo mysql` (no password needed via sudo)
- **Deploy dev code to beta:** `sudo /var/www/bbs/bin/bbs-update /var/www/bbs main`
  - This pulls the latest `main` branch from GitHub onto the beta server
- **Run pending migrations:** `sudo /var/www/bbs/bin/bbs-update /var/www/bbs main` runs them automatically

## Git & GitHub Workflow
- **Repo:** `marcpope/borgbackupserver` on GitHub
- **Commit and push:** Commit to `main`, push to origin. Always use descriptive commit messages.
- **Releases:** Tagged as `vX.Y.Z-beta` (e.g., `v0.8.8-beta`). To create a release:
  1. Bump `VERSION` file and `AGENT_VERSION` in `agent/bbs-agent.py`
  2. Commit the version bump
  3. Tag: `git tag vX.Y.Z-beta`
  4. Push: `git push origin main --tags`
  5. Production servers pull the latest tag via `bbs-update` (without the `main` argument)

## Patterns & Conventions
- **Auth:** `$this->requireAuth()`, `$this->verifyCsrf()`, agent ownership check
- **Flash + redirect:** `$this->flash('success', 'msg'); $this->redirect('/path');`
- **JSON response:** `$this->json(['status' => 'ok', ...])`
- **DB queries:** `$this->db->fetchOne()`, `$this->db->fetchAll()`, `$this->db->insert()`, `$this->db->update()`, `$this->db->delete()`
- **Job queue:** `backup_jobs` table — statuses: queued → sent → running → completed/failed
- **Agent communication:** Agent polls `GET /api/agent/tasks`, reports via `POST /api/agent/status`

## Plugin System
- `plugins` table — master plugin list (slug-based dispatch)
- `agent_plugins` — per-agent enable/disable
- `plugin_configs` — named reusable configurations (e.g., "Production DB")
- `backup_plan_plugins` — links plans to plugin configs via `plugin_config_id`
- Plugin UI lives on the **Plugins tab** of client detail page
- Schema-driven forms via `PluginManager::getPluginSchema()`
