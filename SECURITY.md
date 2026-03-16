# Security Policy

## Supported Versions

| Version | Supported |
| ------- | --------- |
| Latest release | Yes |
| Older releases | No |

We only provide security fixes for the latest release. Users should always update to the most recent version.

## Reporting a Vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Instead, report vulnerabilities privately using one of these methods:

1. **GitHub Security Advisories** (preferred): Use the [Report a vulnerability](https://github.com/marcpope/borgbackupserver/security/advisories/new) button on this repository
2. **Email**: Contact the maintainer directly at the email listed on the [GitHub profile](https://github.com/marcpope)

### What to include

- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

### What to expect

- Acknowledgment within 72 hours
- We will work with you to understand and validate the issue
- A fix will be developed and released as soon as practical
- You will be credited in the release notes (unless you prefer otherwise)

## Scope

The following are in scope:

- BBS server application (PHP)
- Agent code (`bbs-agent.py`)
- Authentication and authorization bypasses
- SSH key handling and encryption
- Command injection via user input
- Privilege escalation

The following are out of scope:

- Vulnerabilities in upstream dependencies (borg, MariaDB, Apache, etc.) — report these to the respective projects
- Issues requiring physical access to the server
- Social engineering
