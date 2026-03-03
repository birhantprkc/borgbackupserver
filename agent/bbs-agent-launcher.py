#!/usr/bin/env python3
"""
BBS Agent Launcher for Windows.

Compiled to bbs-agent.exe via PyInstaller. Implements the Windows Service API
and manages bbs-agent-run.py as a subprocess using the bundled Python.

The installer places Python embeddable alongside this exe so no system Python
is needed. Falls back to system Python if bundled one isn't found.

Self-update replaces only bbs-agent-run.py — the exe and Python never change.

Build (requires pywin32):
    pip install pyinstaller pywin32
    pyinstaller --onefile --name bbs-agent --console --hidden-import win32timezone agent/bbs-agent-launcher.py
"""

import os
import sys
import subprocess
import time
import logging

# Determine the directory where the exe (or script) lives
if getattr(sys, 'frozen', False):
    _BASE_DIR = os.path.dirname(sys.executable)
else:
    _BASE_DIR = os.path.dirname(os.path.abspath(__file__))

AGENT_SCRIPT = os.path.join(_BASE_DIR, "bbs-agent-run.py")
LOG_FILE = os.path.join(_BASE_DIR, "bbs-agent-launcher.log")

# Set up launcher logging (separate from agent log)
logging.basicConfig(
    filename=LOG_FILE,
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
)
log = logging.getLogger("launcher")


def find_python():
    """Find a working Python 3 interpreter. Prefers bundled, then system."""
    candidates = []

    # 1. Bundled Python embeddable in agent dir
    bundled = os.path.join(_BASE_DIR, "python", "python.exe")
    if os.path.isfile(bundled):
        candidates.append(bundled)

    # 2. System Python via PATH (may not be visible to LocalSystem)
    import shutil
    for name in ("python3", "python"):
        p = shutil.which(name)
        if p:
            candidates.append(p)

    # 3. Common install locations on Windows
    if sys.platform == "win32":
        for env_var in ("LocalAppData", "ProgramFiles"):
            root = os.environ.get(env_var, "")
            if not root:
                continue
            for ver in ("Python313", "Python312", "Python311", "Python310", "Python39"):
                for subdir in ("Programs\\Python", "Python"):
                    p = os.path.join(root, subdir, ver, "python.exe")
                    if os.path.isfile(p):
                        candidates.append(p)

        # Also check all user profiles (service runs as LocalSystem)
        users_dir = os.path.join(os.environ.get("SystemDrive", "C:"), "\\Users")
        if os.path.isdir(users_dir):
            try:
                for user in os.listdir(users_dir):
                    for ver in ("Python313", "Python312", "Python311", "Python310", "Python39"):
                        p = os.path.join(users_dir, user, "AppData", "Local",
                                         "Programs", "Python", ver, "python.exe")
                        if os.path.isfile(p):
                            candidates.append(p)
            except PermissionError:
                pass

    # Deduplicate and verify
    seen = set()
    for c in candidates:
        norm = os.path.normcase(os.path.abspath(c))
        if norm in seen:
            continue
        seen.add(norm)
        try:
            r = subprocess.run([c, "--version"], capture_output=True, timeout=5)
            if r.returncode == 0:
                ver = r.stdout.decode().strip()
                log.info("Found Python: %s (%s)", c, ver)
                return c
        except Exception as e:
            log.debug("Python candidate %s failed: %s", c, e)
            continue

    return None


def run_agent_subprocess(python_exe):
    """Run the agent script as a subprocess, return the Popen object."""
    env = os.environ.copy()
    env["PYTHONUNBUFFERED"] = "1"

    log.info("Starting agent: %s %s", python_exe, AGENT_SCRIPT)
    return subprocess.Popen(
        [python_exe, AGENT_SCRIPT],
        cwd=_BASE_DIR,
        env=env,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )


def run_agent_directly():
    """Run the agent script directly (non-frozen mode only)."""
    if not os.path.isfile(AGENT_SCRIPT):
        print("ERROR: {} not found".format(AGENT_SCRIPT), file=sys.stderr)
        sys.exit(1)
    with open(AGENT_SCRIPT) as f:
        exec(compile(f.read(), AGENT_SCRIPT, "exec"))


# Try to import Windows service support
try:
    import win32serviceutil
    import win32service
    import win32event
    import servicemanager
    HAS_WIN32 = True
except ImportError:
    HAS_WIN32 = False


if HAS_WIN32:
    class BorgBackupAgentService(win32serviceutil.ServiceFramework):
        _svc_name_ = "BorgBackupAgent"
        _svc_display_name_ = "Borg Backup Server Agent"
        _svc_description_ = "Borg Backup Server agent - manages backup jobs for this machine"

        def __init__(self, args):
            win32serviceutil.ServiceFramework.__init__(self, args)
            self.stop_event = win32event.CreateEvent(None, 0, 0, None)
            self.process = None
            self.python_exe = None
            self.is_suspended = False

        def GetAcceptedControls(self):
            rc = win32service.SERVICE_ACCEPT_STOP | win32service.SERVICE_ACCEPT_SHUTDOWN
            try:
                rc |= win32service.SERVICE_ACCEPT_POWEREVENT
            except AttributeError:
                pass
            return rc

        def SvcStop(self):
            log.info("Service stop requested")
            self.ReportServiceStatus(win32service.SERVICE_STOP_PENDING)
            win32event.SetEvent(self.stop_event)
            if self.process and self.process.poll() is None:
                log.info("Terminating agent process (pid=%d)", self.process.pid)
                self.process.terminate()
                try:
                    self.process.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    self.process.kill()

        def SvcOtherEx(self, control, event_type, data):
            """Handle power events (sleep/wake)."""
            # SERVICE_CONTROL_POWEREVENT = 13
            if control == 13:
                PBT_APMSUSPEND = 4
                PBT_APMRESUMEAUTOMATIC = 18
                if event_type == PBT_APMSUSPEND:
                    log.info("System suspending (sleep/hibernate)")
                    self.is_suspended = True
                elif event_type == PBT_APMRESUMEAUTOMATIC:
                    log.info("System resumed from sleep/hibernate")
                    self.is_suspended = False
                    # Restart agent subprocess if it died during sleep
                    if self.process and self.process.poll() is not None and self.python_exe:
                        log.info("Agent subprocess not running after wake, restarting")
                        try:
                            self.process = run_agent_subprocess(self.python_exe)
                            log.info("Agent restarted after wake (pid=%d)", self.process.pid)
                        except Exception as e:
                            log.error("Failed to restart agent after wake: %s", e)

        def SvcDoRun(self):
            servicemanager.LogMsg(
                servicemanager.EVENTLOG_INFORMATION_TYPE,
                servicemanager.PYS_SERVICE_STARTED,
                (self._svc_name_, '')
            )
            log.info("Service starting")
            self.main()
            log.info("Service stopped")

        def main(self):
            if not os.path.isfile(AGENT_SCRIPT):
                msg = "Agent script not found: {}".format(AGENT_SCRIPT)
                log.error(msg)
                servicemanager.LogErrorMsg(msg)
                return

            self.python_exe = find_python()
            if self.python_exe is None:
                msg = "No Python interpreter found. Install Python 3.9+ or place python embeddable in {}".format(
                    os.path.join(_BASE_DIR, "python")
                )
                log.error(msg)
                servicemanager.LogErrorMsg(msg)
                return

            log.info("Using Python: %s", self.python_exe)

            # Start the agent subprocess
            try:
                self.process = run_agent_subprocess(self.python_exe)
            except Exception as e:
                log.error("Failed to start agent: %s", e)
                servicemanager.LogErrorMsg("Failed to start agent: {}".format(e))
                return

            log.info("Agent started (pid=%d)", self.process.pid)

            # Monitor loop: wait for stop event or process exit
            while True:
                result = win32event.WaitForSingleObject(self.stop_event, 5000)
                if result == win32event.WAIT_OBJECT_0:
                    log.info("Stop event received")
                    break
                if self.process.poll() is not None:
                    # Process exited — check if stop was requested
                    if win32event.WaitForSingleObject(self.stop_event, 0) == win32event.WAIT_OBJECT_0:
                        break
                    rc = self.process.returncode
                    log.info("Agent exited (code=%s), restarting in 5s...", rc)
                    # Wait 5s but honor stop requests during the delay
                    wait = win32event.WaitForSingleObject(self.stop_event, 5000)
                    if wait == win32event.WAIT_OBJECT_0:
                        log.info("Stop event received during restart delay")
                        break
                    try:
                        self.process = run_agent_subprocess(self.python_exe)
                        log.info("Agent restarted (pid=%d)", self.process.pid)
                    except Exception as e:
                        log.error("Failed to restart agent: %s", e)
                        servicemanager.LogErrorMsg("Failed to restart agent: {}".format(e))
                        break

            # Cleanup
            if self.process and self.process.poll() is None:
                self.process.terminate()
                try:
                    self.process.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    self.process.kill()


if __name__ == '__main__':
    if HAS_WIN32 and len(sys.argv) > 1:
        # Service install/start/stop/remove commands
        win32serviceutil.HandleCommandLine(BorgBackupAgentService)
    elif HAS_WIN32 and getattr(sys, 'frozen', False):
        # Frozen exe launched without arguments — started by SCM
        try:
            servicemanager.Initialize()
            servicemanager.PrepareToHostSingle(BorgBackupAgentService)
            servicemanager.StartServiceCtrlDispatcher()
        except Exception as e:
            # SCM dispatch failed (e.g., double-clicked)
            log.info("SCM dispatch failed (%s), running in foreground", e)
            python_exe = find_python()
            if python_exe:
                proc = run_agent_subprocess(python_exe)
                if proc:
                    proc.wait()
            else:
                print("ERROR: No Python interpreter found", file=sys.stderr)
                print("Install Python 3.9+ or place python embeddable in: {}".format(
                    os.path.join(_BASE_DIR, "python")), file=sys.stderr)
                sys.exit(1)
    else:
        # Not frozen — just run the agent script directly
        run_agent_directly()
