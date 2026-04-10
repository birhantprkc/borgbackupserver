# Borg for Windows — Observed Issues

This document captures issues observed when running our Windows borg build
(`marcpope/borg-windows`, currently `v1.4.4-win5`) against a Linux/Docker
borg server. It is intended to be forwarded to the Windows borg build
maintainer (or upstream borgbackup) for investigation.

The Linux/macOS builds of borg do not show these symptoms in the same setup,
so this is most likely Windows-specific.

---

## Environment

- **Client OS:** Windows 10 / Windows 11 Pro
- **Borg version:** `borg-windows v1.4.4-win5`
  (`https://github.com/marcpope/borg-windows/releases/latest`)
- **Server:** borg accessed over SSH (`ssh://user@host//path`) to a
  Linux server running standard `borg serve` (in our case the BBS Docker
  container, but the same is reproducible against a plain Ubuntu host)
- **Repo type:** standard local repo on the server side, accessed via SSH
  transport from the Windows client
- **Backup style:** `borg create` over SSH, single repo, single agent

---

## Issue 1 — Server-side lock not released after a backup that exits with warnings

### Symptom

After a Windows `borg create` finishes with **exit code 1** (warnings:
some source files could not be read due to permissions, ACL issues,
locked files, etc.), the borg lock file on the **server** side is not
removed. The next backup fails with:

```
Failed to create/acquire the lock /var/bbs/home/3/<repo>/lock.exclusive (timeout).
```

The leftover lock file looks like this on the server:

```
lock.exclusive/d1519bcc195a@33236981458177.3828-0
```

The `d1519bcc195a` segment is the **server's** hostname (in this case the
Docker container's auto-generated hostname). This means the lock was
created by the `borg serve` process running on the server, and was never
torn down — even though the SSH connection is gone and the Windows borg
process has long since exited.

Manually deleting `lock.exclusive` (or running `borg break-lock`) lets
the next backup proceed normally.

### Reproduction (suspected)

1. Set up a borg repo on a Linux server, accessed by a Windows borg
   client over SSH (`ssh://user@host//path`).
2. From Windows, run a `borg create` that includes a directory containing
   files the user **cannot read** (e.g. another user's profile, or files
   held open by another Windows process).
3. Let borg finish — it will print warnings for the unreadable paths and
   exit with code **1** (warnings, archive still created).
4. Immediately run `borg create` again against the same repo.
5. The second run fails to acquire the lock; inspect the repo on the
   server and the `lock.exclusive` file is still present.

We have **not** seen this with Linux or macOS borg clients in the same
setup, so the lock-release path on Windows borg appears to behave
differently when borg exits with code 1.

### Why we believe this is a Windows-borg bug, not a wrapper bug

- The Linux/macOS borg client against the same server, with the same
  agent wrapper, does not leave the lock file behind even when exit
  code is 1.
- The borg process on Windows has fully exited by the time we observe
  the leftover lock — there is no orphan process holding it.
- The lock filename's hostname segment is the **server's** hostname, so
  the file was authored by `borg serve` (server-side), not by the
  Windows client. This indicates `borg serve` never received (or never
  acted on) the release request from the client over SSH.

### Things that might be worth checking inside the Windows borg build

1. **Code path on warning exits** — when borg encounters file read errors
   during `create`, does it still call `Repository.commit()` /
   `Lock.release()` on the server side before exiting? On POSIX builds
   it does; the question is whether the Windows build's exception
   handling around partial-success exits short-circuits the normal
   teardown.
2. **SSH transport teardown order** — on warning exits, does the Windows
   build close the borg-serve SSH stream cleanly (sending the
   `goodbye`/`commit`/`rollback` RPC) before tearing down the ssh
   subprocess? If the local ssh process is killed before borg sends the
   release RPC, the server side never sees the release.
3. **Lock file naming** — the PID segment we observe
   (`33236981458177.3828-0`) is well outside the normal Linux PID range
   (max ~4M). It looks like the Windows build may be passing a Windows
   thread/handle ID through to the server's lock-naming routine, or
   there is a 64-bit/32-bit truncation issue when serializing the
   client-side PID over the wire. Worth confirming whether the value is
   ever interpreted as "is this PID still alive?" — if so, the answer
   on the server's PID namespace will always be "no", which may be
   contributing to stale locks not being auto-cleaned by the
   `lock.is_stale()` heuristic.
4. **`atexit` / signal handlers** — on Windows, `atexit` and SIGINT/SIGTERM
   handling are subtly different from POSIX. If borg's lock release
   relies on an `atexit` hook and the Python interpreter on Windows
   takes a different exit path on warning exits (sys.exit vs os._exit
   vs uncaught return), the hook may be skipped.

---

## Issue 2 — Process tree / orphan handling on cancel (already mitigated on the agent side)

### Symptom

When the BBS agent kills the borg process during a user-initiated
cancel, on Linux the agent now kills the entire process group so borg's
ssh transport child gets reaped along with borg. On Windows, the
equivalent (`CREATE_NEW_PROCESS_GROUP` + `terminate()`) terminates the
borg process but **may** leave child processes (notably the `ssh.exe`
spawned for the SSH transport) running until they hit a network timeout.

### Why this is on the list

We worked around this on the agent side in BBS agent v2.24.1 by killing
the whole process group, but on Windows there is no clean POSIX-style
"kill the whole tree" primitive in plain `subprocess`. We currently rely
on `terminate()` + `kill()` of the immediate child.

If the Windows borg build could itself cleanly tear down its SSH
transport child on receipt of `CTRL_BREAK_EVENT` / `CTRL_C_EVENT` (as
opposed to relying on the parent killing its descendants), that would
make cancel/restart behavior more reliable on Windows.

### Suggestion

- Treat `CTRL_BREAK_EVENT` as a normal shutdown trigger that releases
  the server-side lock and tears down the SSH transport (`ssh.exe`)
  child, the same way SIGINT/SIGTERM does on POSIX.

---

## Diagnostics we can collect on demand

If the maintainer would like more data, we can capture any of:

- `borg create --debug --debug-topic=lock --debug-topic=repository` output
  from a Windows run that ends in exit code 1, to see whether the
  `Lock.release()` call is reached on the client side.
- `lsof` / `ProcMon` snapshot of the orphan `ssh.exe` after a cancel.
- A reproducer script (PowerShell + a deliberately unreadable directory)
  that triggers Issue 1 on a fresh repo.
- The exact `lock.exclusive` file content from a stuck server-side repo.
- BBS agent logs around the failing transition (we have these from the
  reporter on issue
  [marcpope/borgbackupserver#91](https://github.com/marcpope/borgbackupserver/issues/91)).

---

## Related downstream issues

- [marcpope/borgbackupserver#91](https://github.com/marcpope/borgbackupserver/issues/91) —
  "Canceling job on UI" — Windows half of the report describes Issue 1
  above, Linux half was an agent-side bug fixed in BBS agent v2.24.1.
