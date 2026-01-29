# Contributing to Borg Backup Server

> **Note:** We are not accepting contributions at this time. The project is still in early development and not yet finalized. Feel free to open issues for bugs or feature requests, but please hold off on pull requests until we open things up. Watch the repo for updates.

---

## Development Setup

```bash
git clone https://github.com/marcpope/borgbackupserver.git
cd borgbackupserver
composer install
cp config/.env.example config/.env
# Edit config/.env with your local MySQL credentials
php migrate.php
cd public && php -S localhost:8080
```

Login: `admin` / `admin`

---

## Project Structure

```
src/
  Core/           App bootstrap, routing, base controller, database, migrations
  Controllers/    Route handlers (one class per resource)
  Controllers/Api Agent API endpoints
  Services/       Business logic (BorgCommandBuilder, QueueManager, Scheduler, etc.)
  Views/          PHP templates (Bootstrap 5)
  Views/layouts/  Page layouts (app, auth)
migrations/       SQL and PHP migration files (run in order)
agent/            Python agent, installer, service files
public/           Web root (index.php front controller, CSS, images)
docs/             Documentation
```

---

## Conventions

- **PHP 8.1+** — use match expressions, named arguments, typed properties
- **No framework** — intentionally lightweight (AltoRouter + PDO + plain PHP views)
- **PSR-4 autoloading** — namespace `BBS\` maps to `src/`
- **SQL in controllers** — queries live in controller methods, not in model classes
- **No ORM** — direct PDO via the `Database` class
- **Bootstrap 5** — loaded via CDN, custom styles in `public/css/style.css`
- **Migrations** — sequential numbered files in `migrations/`, run via `php migrate.php`

---

## Adding a Migration

1. Create `migrations/NNN_description.sql` (or `.php` for data migrations)
2. SQL migrations are executed as-is; PHP migrations are `require`'d
3. Use `IF NOT EXISTS` / `IF EXISTS` for idempotency
4. Run: `php migrate.php`

---

## Submitting Changes

1. Fork the repository
2. Create a branch: `git checkout -b feature/my-thing`
3. Make your changes
4. Test locally (dev server + create a plan + trigger a backup)
5. Open a pull request with a clear description of what changed and why

---

## Reporting Issues

Open an issue on GitHub with:
- What you expected to happen
- What actually happened
- PHP version, OS, browser
- Relevant log output (server log, agent log, browser console)

---

## Areas That Could Use Help

- **Testing** — there are no automated tests yet (unit tests, integration tests)
- **Agent features** — bandwidth throttling, pre/post backup hooks
- **UI polish** — responsive improvements, dark mode
- **Packaging** — Docker image, DEB/RPM packages
- **Internationalization** — the UI is English-only

---

## Code of Conduct

Be kind. Write clear commit messages. Don't break the scheduler.
