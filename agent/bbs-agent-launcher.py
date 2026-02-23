#!/usr/bin/env python3
"""
BBS Agent Launcher for Windows.

This is a tiny launcher compiled to bbs-agent.exe via PyInstaller.
It loads and executes bbs-agent-run.py from the same directory,
enabling self-update without replacing the exe.

Build:
    pip install pyinstaller
    pyinstaller --onefile --name bbs-agent --console agent/bbs-agent-launcher.py
"""

import os
import sys

script = os.path.join(os.path.dirname(sys.executable), "bbs-agent-run.py")

if not os.path.isfile(script):
    print("ERROR: {} not found".format(script), file=sys.stderr)
    print("The agent script (bbs-agent-run.py) must be in the same directory as this exe.", file=sys.stderr)
    sys.exit(1)

with open(script) as f:
    exec(compile(f.read(), script, "exec"))
