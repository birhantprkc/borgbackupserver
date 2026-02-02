# Borg Version Management — Implementation Plan

## Problem

Borg is currently installed via OS package managers (apt, dnf, yum, etc.) on each client, yielding **inconsistent versions** across the fleet. This can cause compatibility issues and bugs when server and clients run different borg versions. There is no centralized way to control which borg version clients use.

## Solution

Centralized borg version management: the BBS server queries GitHub for available borg releases, admin selects a target version, and agents download pre-compiled binaries directly from GitHub (with pip as fallback).

---

## Research Findings

### Borg Release Structure (github.com/borgbackup/borg/releases)

| Series | Status | Latest | Repo Format |
|--------|--------|--------|-------------|
| 1.2.x | Old-stable | 1.2.9 | Compatible with 1.4 |
| 1.4.x | **Stable** | 1.4.3 | Compatible with 1.2 |
| 2.0.x | Beta only | 2.0.0b20 | **Breaking** — not compatible with 1.x repos |

- **1.2 → 1.4 upgrade is safe** — same repository format, no `borg upgrade` needed
- **2.0 is breaking** — requires `borg transfer` to migrate repos. We exclude all beta/rc releases.
- TAM authentication: repos created with < 1.2.5 need `borg upgrade --tam REPO` once after upgrading

### Binary Asset Naming

**1.4.x convention** (structured):
- `borg-linux-glibc231-x86_64` — locally built
- `borg-linux-glibc235-x86_64-gh` — GitHub Actions built
- `borg-linux-glibc235-arm64-gh`
- `borg-macos-13-x86_64-gh`, `borg-macos-14-arm64-gh`
- `borg-freebsd-14-x86_64-gh`

**1.2.x convention** (legacy):
- `borg-linuxold64` → glibc231/x86_64
- `borg-linuxnew64` → glibc235/x86_64
- `borg-linuxnewer64` → glibc238/x86_64
- `borg-macos64`, `borg-freebsd64`

### Installation Methods

| Method | Pros | Cons |
|--------|------|------|
| **Binary download** (recommended) | Exact version control, no dependencies | Must match OS/arch/glibc |
| **pip install** (fallback) | Works anywhere Python exists | Compiles from source, slow, needs build deps |
| **Package manager** (current) | Simple | Version varies by distro, no control |

---

## Current State of Codebase

- **Agent** (`agent/bbs-agent.py`): installs borg via package manager, reports `borg_version` to server
- **Agent installer** (`agent/install.sh`): uses apt/dnf/yum/pacman/brew to install borgbackup
- **`execute_update_borg()`** (agent line 221): runs package manager upgrade command
- **Settings page** (`src/Views/settings/index.php`): has tabs — General, Notifications, Templates, Updates
- **Client detail page**: shows borg version badge, has "Update Borg" button per-client
- **Schema**: `agents` table has `borg_version VARCHAR(20)`, `backup_jobs.task_type` includes `update_borg`
- **Task flow**: server inserts `backup_jobs` row → agent polls `/api/agent/tasks` → executes → reports via `/api/agent/status`

---

## Implementation Plan

### Phase 1: Database Migration

**File:** `migrations/025_borg_version_management.sql`

```sql
-- Available borg releases from GitHub
CREATE TABLE borg_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(20) NOT NULL UNIQUE,
    release_tag VARCHAR(30) NOT NULL,
    release_date DATE NOT NULL,
    is_prerelease TINYINT(1) NOT NULL DEFAULT 0,
    release_notes TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_version (version)
) ENGINE=InnoDB;

-- Platform-specific binary assets per release
CREATE TABLE borg_version_assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    borg_version_id INT NOT NULL,
    platform VARCHAR(20) NOT NULL,
    architecture VARCHAR(20) NOT NULL,
    glibc_version VARCHAR(20) DEFAULT NULL,
    asset_name VARCHAR(100) NOT NULL,
    download_url VARCHAR(500) NOT NULL,
    file_size BIGINT DEFAULT NULL,
    FOREIGN KEY (borg_version_id) REFERENCES borg_versions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_asset (borg_version_id, platform, architecture, glibc_version)
) ENGINE=InnoDB;

-- Track borg version that created/last wrote to each repo
ALTER TABLE repositories
    ADD COLUMN borg_version_created VARCHAR(20) DEFAULT NULL,
    ADD COLUMN borg_version_last VARCHAR(20) DEFAULT NULL;

-- New agent columns
ALTER TABLE agents
    ADD COLUMN borg_install_method ENUM('package','binary','pip','unknown') DEFAULT 'unknown' AFTER borg_version,
    ADD COLUMN borg_binary_path VARCHAR(255) DEFAULT NULL AFTER borg_install_method;

-- Settings for borg version management
INSERT INTO settings (`key`, `value`) VALUES
    ('target_borg_version', ''),
    ('last_borg_version_check', ''),
    ('fallback_to_pip', '1');
```

Also update `schema.sql` with the same additions.

**Repository borg version tracking:** The `repositories` table gets two new columns:
- `borg_version_created` — set when the repo is first initialized (`borg init`), records which borg version created it
- `borg_version_last` — updated after each successful backup, tracks the latest borg version that wrote to the repo

This data will be useful later for:
- Running `borg upgrade --tam` on repos created before 1.2.5
- Identifying repos that need `borg transfer` if 2.x migration ever happens
- Flagging compatibility warnings (e.g., "this repo was created with 1.1.x, consider running upgrade checks")

---

### Phase 2: Server — BorgVersionService

**New file:** `src/Services/BorgVersionService.php`

Responsibilities:
1. **`syncVersionsFromGitHub()`** — call GitHub API (`repos/borgbackup/borg/releases`), filter out pre-releases/betas/RCs, store in `borg_versions` + `borg_version_assets`
2. **`parseAssetMetadata(assetName)`** — handle both 1.2.x and 1.4.x naming conventions, extract platform/arch/glibc
3. **`getAssetForPlatform(version, platform, arch, glibcVersion)`** — find best matching binary for an agent's OS. For glibc matching: pick highest glibc that is <= agent's glibc version
4. **`getStoredVersions()`** — return all synced versions from DB
5. **`getTargetVersion()` / `setTargetVersion()`** — read/write `settings.target_borg_version`

Asset name parsing maps:
- 1.4.x regex: `borg-{platform}-{glibc}-{arch}[-gh]`
- 1.4.x macOS regex: `borg-macos-{osver}-{arch}[-gh]`
- 1.2.x hardcoded map: `borg-linuxold64` → linux/x86_64/glibc231, etc.
- Skip `.tgz`, `.asc` files — only store the raw binary assets

---

### Phase 3: Server — Settings UI ("Borg Versions" Tab)

**Modify:** `src/Views/settings/index.php` — add new tab

**New routes in `src/Core/App.php`:**
- `GET /settings/borg-versions/sync` → `SettingsController@syncBorgVersions`
- `POST /settings/borg-versions/set-target` → `SettingsController@setTargetBorgVersion`
- `POST /settings/update-borg-bulk` → `SettingsController@updateBorgBulk`

**Tab UI contains:**
- "Sync from GitHub" button — triggers `syncVersionsFromGitHub()`
- Version dropdown — shows available versions with release dates, admin selects target
- "Set Target Version" button — saves selection
- Client version matrix — shows all agents with their current borg version, colored badges (green=matches target, yellow=outdated)
- "Update All" button — queues `update_borg` jobs for all outdated agents
- Downgrade warning banner when target < some agents' current version

---

### Phase 4: Server — Enhanced Task Payload

**Modify:** `src/Services/QueueManager.php`

When building `update_borg` task payload, include:
```json
{
    "task": "update_borg",
    "job_id": 123,
    "target_version": "1.4.3",
    "download_url": "https://github.com/borgbackup/borg/releases/download/1.4.3/borg-linux-glibc235-x86_64-gh",
    "install_method": "binary",
    "binary_path": "/usr/local/bin/borg",
    "fallback_to_pip": true
}
```

The server determines the correct `download_url` by matching the agent's reported `platform`, `architecture`, and `glibc_version` against `borg_version_assets`.

---

### Phase 5: Agent — Enhanced System Reporting

**Modify:** `agent/bbs-agent.py` — `get_system_info()`

Add new fields to system info payload:
- `platform` — `platform.system().lower()` (linux, darwin, freebsd)
- `architecture` — normalized to `x86_64` or `arm64`
- `glibc_version` — detected via `ctypes.CDLL('libc.so.6').gnu_get_libc_version()`, formatted as `glibc231`
- `borg_install_method` — `binary`, `pip`, `package`, or `unknown`
- `borg_binary_path` — actual path to borg binary

**Modify:** `src/Controllers/Api/AgentApiController.php` — accept and store the new fields in `agents` table.

---

### Phase 6: Agent — Binary Download Install

**Modify:** `agent/bbs-agent.py` — rewrite `execute_update_borg()`

New flow:
1. Read `target_version`, `download_url`, `install_method`, `fallback_to_pip` from task payload
2. If `install_method == "binary"` and `download_url` provided:
   - Download binary via `urllib.request.urlopen()`
   - Write to `/usr/local/bin/borg.tmp`
   - `chmod 755`
   - Test with `borg.tmp --version` — verify it outputs expected version
   - Rename to `/usr/local/bin/borg` (backup old binary first)
3. If binary fails and `fallback_to_pip` is true:
   - Run `pip3 install borgbackup=={target_version}`
4. Report success/failure via `/api/agent/status`
5. Re-report system info via `/api/agent/info`

**Backward compatibility:** If task payload lacks `download_url` (old server), fall back to current package manager logic.

---

### Phase 7: Agent Installer Update

**Modify:** `agent/install.sh`

Update `install_borg()` to download binary from GitHub instead of using package manager, matching the same logic as the agent update flow. This ensures new agent installs also get the controlled version.

---

## Files to Create/Modify

| File | Action | Description |
|------|--------|-------------|
| `migrations/025_borg_version_management.sql` | Create | Schema migration |
| `schema.sql` | Modify | Add new tables/columns |
| `src/Services/BorgVersionService.php` | Create | GitHub API integration, version management |
| `src/Controllers/SettingsController.php` | Modify | New tab routes and handlers |
| `src/Views/settings/index.php` | Modify | "Borg Versions" tab UI |
| `src/Core/App.php` | Modify | New routes |
| `src/Services/QueueManager.php` | Modify | Enhanced update_borg task payload |
| `src/Controllers/Api/AgentApiController.php` | Modify | Accept new agent info fields + repo borg version |
| `agent/bbs-agent.py` | Modify | Binary download, enhanced system info |
| `agent/install.sh` | Modify | Binary-based initial install |
| `scheduler.php` | Modify | Daily auto-sync of borg versions |

## Design Decisions

- **Downgrades**: Warn but allow — show a warning banner when target version is lower than some agents' current version, but let admin proceed
- **Install path**: Always `/usr/local/bin/borg` — not configurable, keeps things simple
- **Auto-sync**: Daily via `scheduler.php` — add a task that calls `BorgVersionService::syncVersionsFromGitHub()` once per day (check `last_borg_version_check` setting timestamp)

---

## Verification Plan

1. **Migration**: Run migration on beta server, verify tables created
2. **Sync**: Trigger GitHub sync from Settings > Borg Versions, verify versions populate
3. **Set target**: Select 1.4.3 as target, verify setting saved
4. **Agent info**: Restart a test agent, verify it reports platform/arch/glibc
5. **Single update**: Click "Update Borg" on one client, verify binary downloaded and installed to `/usr/local/bin/borg`
6. **Bulk update**: Use "Update All" button, verify jobs queued for outdated agents
7. **Fallback**: Test with an agent where binary download fails (e.g., unsupported platform), verify pip fallback works
8. **Post-update**: Verify agent reports new borg_version and install_method after update
9. **Backup runs**: Run a backup after borg update to verify borg still works correctly
