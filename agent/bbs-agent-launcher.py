#!/usr/bin/env python3
"""
BBS Agent Launcher for Windows.

This is a tiny launcher compiled to bbs-agent.exe via PyInstaller.
It implements the Windows Service API so sc.exe can manage it,
then loads and executes bbs-agent-run.py from the same directory.
Self-update replaces only the .py file — the exe never changes.

Build (requires pywin32):
    pip install pyinstaller pywin32
    pyinstaller --onefile --name bbs-agent --console --hidden-import win32timezone agent/bbs-agent-launcher.py
"""

import os
import sys
import subprocess
import threading

# Determine the directory where the exe (or script) lives
if getattr(sys, 'frozen', False):
    _BASE_DIR = os.path.dirname(sys.executable)
else:
    _BASE_DIR = os.path.dirname(os.path.abspath(__file__))

AGENT_SCRIPT = os.path.join(_BASE_DIR, "bbs-agent-run.py")


def run_agent_directly():
    """Run the agent script directly (non-service mode)."""
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

        def SvcStop(self):
            self.ReportServiceStatus(win32service.SERVICE_STOP_PENDING)
            win32event.SetEvent(self.stop_event)
            if self.process:
                self.process.terminate()
                try:
                    self.process.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    self.process.kill()

        def SvcDoRun(self):
            servicemanager.LogMsg(
                servicemanager.EVENTLOG_INFORMATION_TYPE,
                servicemanager.PYS_SERVICE_STARTED,
                (self._svc_name_, '')
            )
            self.main()

        def main(self):
            if not os.path.isfile(AGENT_SCRIPT):
                servicemanager.LogErrorMsg(
                    "BBS Agent script not found: {}".format(AGENT_SCRIPT)
                )
                return

            # Determine the Python executable to use
            if getattr(sys, 'frozen', False):
                python_exe = os.path.join(
                    os.path.dirname(sys.executable),
                    "python.exe"
                )
                # If there's no separate python.exe, use the system Python
                if not os.path.isfile(python_exe):
                    python_exe = sys.executable
            else:
                python_exe = sys.executable

            # Run the agent as a subprocess so we can manage its lifecycle
            self.process = subprocess.Popen(
                [python_exe, AGENT_SCRIPT],
                cwd=_BASE_DIR,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )

            # Wait for either the stop event or the process to exit
            while True:
                result = win32event.WaitForSingleObject(self.stop_event, 5000)
                if result == win32event.WAIT_OBJECT_0:
                    # Stop requested
                    break
                if self.process.poll() is not None:
                    # Process exited on its own — restart it (unless stop was requested)
                    result2 = win32event.WaitForSingleObject(self.stop_event, 0)
                    if result2 == win32event.WAIT_OBJECT_0:
                        break
                    # Restart the agent
                    self.process = subprocess.Popen(
                        [python_exe, AGENT_SCRIPT],
                        cwd=_BASE_DIR,
                        stdout=subprocess.DEVNULL,
                        stderr=subprocess.DEVNULL,
                    )

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
    elif HAS_WIN32 and not getattr(sys, 'frozen', False):
        # Running as script with win32 — just run directly
        run_agent_directly()
    elif HAS_WIN32:
        # Frozen exe launched without arguments — assume started by SCM
        try:
            servicemanager.Initialize()
            servicemanager.PrepareToHostSingle(BorgBackupAgentService)
            servicemanager.StartServiceCtrlDispatcher()
        except Exception:
            # If SCM dispatch fails, run directly (e.g., double-clicked)
            run_agent_directly()
    else:
        # No win32 — just run the agent script directly
        run_agent_directly()
