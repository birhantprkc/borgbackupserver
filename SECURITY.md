# Security Policy

## Supported Versions

| Version | Supported          |
|---------|--------------------|
| 0.8-beta | Yes              |

## Reporting a Vulnerability

If you discover a security vulnerability in Borg Backup Server, **please do not open a public issue.**

Instead, report it privately to the author:

- **Email:** marc@marcpope.com
- **Subject line:** `[BBS Security] <brief description>`

Please include:

- A description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if you have one)

You should receive an acknowledgment within 48 hours. The author will work with you to understand the issue, develop a fix, and coordinate disclosure.

## Scope

The following are in scope:

- Authentication and session management
- SQL injection, XSS, CSRF, and other OWASP Top 10 vulnerabilities
- Agent API authentication bypass
- Passphrase or credential exposure
- Privilege escalation (user to admin)
- Remote code execution

## Out of Scope

- Vulnerabilities in third-party dependencies (report these upstream)
- Denial of service attacks
- Issues requiring physical access to the server
- Social engineering

## Disclosure

We follow coordinated disclosure. Once a fix is released, the vulnerability will be documented in the release notes. Credit will be given to the reporter unless they prefer to remain anonymous.
