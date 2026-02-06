# Security

Borg Backup Server (BBS) is designed with a security-first architecture. This document describes how BBS protects your backup infrastructure, data, and credentials.

## Immutable Backups (Append-Only)

BBS enforces **append-only mode** on all client connections. Agents connect to the server via SSH with forced commands:

```
borg serve --append-only --restrict-to-path /var/bbs/home/{agent_id}
```

This means:

- **Agents can only create new backup archives** — they cannot delete, modify, or overwrite existing ones
- **Agents are restricted to their own directory** — they cannot access other clients' data
- **No shell access** — agents cannot execute arbitrary commands on the server

Even if a client machine is fully compromised, the attacker cannot delete or tamper with existing backups. Only the BBS server itself (via the web interface or scheduler) can prune old archives according to retention policies.

## Zero Trust: Server Has No Access to Clients

BBS operates on a pull model — agents poll the server for work. The BBS server **never initiates SSH connections to client machines** and holds no credentials for client systems. This eliminates an entire class of lateral movement attacks: compromising the backup server does not give an attacker access to your production infrastructure.

## Agent Authentication & Isolation

Each agent authenticates with a unique API key over HTTPS. Agents are fully isolated from each other:

- **Unique SSH user per client** — each agent gets a dedicated system user (`bbs-{id}`) with its own home directory
- **Job ownership verification** — agents can only retrieve and report on their own jobs
- **Rate-limited API** — failed authentication attempts are rate-limited (20 attempts per 5 minutes) to prevent brute force

## Encryption at Rest

All repository passphrases, SSH private keys, and TOTP secrets are encrypted in the database using **AES-256-GCM** with a server-specific `APP_KEY`. Repository data itself is encrypted by Borg using the user's chosen encryption mode (default: `repokey-blake2` with AES-256).

Passwords are hashed with **bcrypt** and never stored in plaintext. Two-factor recovery codes are also bcrypt-hashed.

## Web Application Security

The BBS web interface follows OWASP best practices:

- **SQL injection prevention** — all database queries use parameterized prepared statements (PDO with emulated prepares disabled)
- **CSRF protection** — all forms include a per-session token verified with constant-time comparison, plus `SameSite=Strict` cookies
- **XSS prevention** — all user-supplied data is escaped with `htmlspecialchars` before rendering
- **Command injection prevention** — all shell arguments are escaped with `escapeshellarg` and validated against allowed paths
- **Session security** — cookies are `HttpOnly` and `SameSite=Strict`; session IDs are regenerated on login
- **Brute force protection** — login (5 attempts/5 min), 2FA verification (10 attempts/5 min), and agent API endpoints are all rate-limited by IP

## Role-Based Access Control

BBS enforces fine-grained permissions:

- **Admin** — full system access including settings, user management, and notification services
- **User** — limited to assigned clients with granular permissions (trigger backup, manage repos, manage plans, restore, repo maintenance)

Every API request verifies job and agent ownership — agents can only interact with their own data.

## Two-Factor Authentication

BBS supports TOTP-based two-factor authentication compatible with any authenticator app (Google Authenticator, Authy, 1Password, etc.). Admins can enforce 2FA for all users. Recovery codes are bcrypt-hashed and single-use.

## Automatic Updates

Agents stay current with the latest security patches through managed updates:

- **Agent updates** — new agent versions are deployed from the web interface (single or bulk) with automatic validation and rollback
- **Borg updates** — Borg binary versions are managed centrally and can be updated individually or in bulk
- **Server updates** — BBS checks for new releases daily and can be upgraded from the web UI or CLI

## Data Protection & Offsite Backups

BBS protects against data loss at multiple levels:

- **Nightly server backups** — the BBS database, configuration, and encryption keys are backed up daily with 7-day retention
- **S3 offsite sync** — server backups and individual repositories can be automatically synced to S3-compatible storage (AWS, Wasabi, Backblaze B2, etc.) for geographic redundancy
- **S3 disaster recovery** — repositories can be restored from S3 in two modes: replace (overwrite local) or copy (create new repo), with orphaned repository detection for full server rebuilds
- **Append-only protection** — even if the server's local disk fails, offsite copies in S3 remain intact and recoverable

## Vulnerability Scanning

The BBS codebase is regularly scanned for security vulnerabilities using static analysis and dependency auditing tools including Composer audit, PHPStan, and manual code review.

## Security Architecture Summary

| Layer | Protection |
|-------|-----------|
| **Client → Server** | Append-only SSH, restricted to `borg serve`, no shell access |
| **Server → Client** | None — server never connects to clients (zero trust) |
| **Agent API** | HTTPS + API key auth + rate limiting + job ownership checks |
| **Web Interface** | CSRF tokens, bcrypt passwords, session security, rate limiting |
| **Data at Rest** | AES-256-GCM (credentials), AES-256 Borg encryption (archives) |
| **Database** | Parameterized queries, no raw SQL, emulated prepares disabled |
| **Access Control** | Role-based (admin/user) + per-agent permissions |
| **Offsite** | S3 sync with disaster recovery and orphaned repo detection |
| **Updates** | Centrally managed agent + Borg updates from web UI |

## Reporting a Vulnerability

If you discover a security vulnerability in Borg Backup Server, **please do not open a public issue.**

Instead, report it privately:

- **Email:** marcpope@me.com
- **Subject line:** `[BBS Security] <brief description>`

Please include:

- A description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if you have one)

You should receive an acknowledgment within 48 hours.

### Scope

The following are in scope:

- Authentication and session management
- SQL injection, XSS, CSRF, and other OWASP Top 10 vulnerabilities
- Agent API authentication bypass
- Passphrase or credential exposure
- Privilege escalation (user to admin)
- Remote code execution

### Out of Scope

- Vulnerabilities in third-party dependencies (report these upstream)
- Denial of service attacks
- Issues requiring physical access to the server
- Social engineering

### Disclosure

We follow coordinated disclosure. Once a fix is released, the vulnerability will be documented in the release notes. Credit will be given to the reporter unless they prefer to remain anonymous.
