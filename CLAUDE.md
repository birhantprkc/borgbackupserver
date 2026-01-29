# Claude Code Instructions

## First Steps
Always read these files at the start of every session to restore full project context:
- `PROJECT_PLAN.md` — Architecture, database schema, tech stack, phased implementation checklist
- `DEVLOG.md` — Session-by-session progress, decisions made, file index, what's next

## Project
- Borg Backup Server (BBS) — open-source PHP web app managing BorgBackup across Linux endpoints via HTTPS agent
- Project path: `/Volumes/Frogger1/Projects/bbs`

## Dev Environment
- Dev server: `cd public && php -S localhost:8080`
- Database: MySQL, db `bbs`, user `root`, pass `quadra65` (CLI passwordless via `~/.my.cnf`)
- Migrations: `php migrate.php` from project root
- Login: `admin` / `admin`

## Tech Stack
- PHP 8.x, AltoRouter, Composer (PSR-4), Bootstrap 5, MySQL
- PDO wrapper in `src/Core/Database.php` — use this for all queries
- Base controller in `src/Core/Controller.php` — extend this for all controllers
- Routes registered in `src/Core/App.php`

## Conventions
- Controllers go in `src/Controllers/`, views in `src/Views/`
- Views use plain PHP templates, rendered via `$this->view('folder/file', $data)`
- Auth layout for login: `$this->authView('auth/login', $data)`
- Always call `$this->requireAuth()` or `$this->requireAdmin()` at top of controller methods
- CSRF: include `<input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">` in POST forms, call `$this->verifyCsrf()` in handler
- Flash messages: `$this->flash('success|danger|info', 'message')` then redirect
- Update `DEVLOG.md` after completing each phase or session
