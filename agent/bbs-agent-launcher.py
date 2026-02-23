#!/usr/bin/env python3
"""
BBS Agent Launcher for Windows.

This is a tiny launcher compiled to bbs-agent.exe via PyInstaller.
It implements the Windows Service API so sc.exe can manage it,
then loads and executes bbs-agent-run.py in-process.
Self-update replaces only the .py file — the exe never changes.

Build (requires pywin32):
    pip install pyinstaller pywin32
    pyinstaller --onefile --name bbs-agent --console --hidden-import win32timezone agent/bbs-agent-launcher.py
"""

import os
import sys
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

        def SvcStop(self):
            self.ReportServiceStatus(win32service.SERVICE_STOP_PENDING)
            win32event.SetEvent(self.stop_event)
            # Send SIGBREAK to our own process to trigger the agent's shutdown
            import signal
            os.kill(os.getpid(), signal.CTRL_BREAK_EVENT)

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

            # Run the agent script in a thread so the service framework
            # can handle stop requests on the main thread
            agent_thread = threading.Thread(target=run_agent_directly, daemon=True)
            agent_thread.start()

            # Wait for the stop event
            win32event.WaitForSingleObject(self.stop_event, win32event.INFINITE)

            # Give the agent thread a moment to clean up
            agent_thread.join(timeout=15)


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
        except Exception:
            # If SCM dispatch fails, run directly (e.g., double-clicked)
            run_agent_directly()
    else:
        # No win32 or running as script — just run the agent directly
        run_agent_directly()
