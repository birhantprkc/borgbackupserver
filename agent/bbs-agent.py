#!/usr/bin/env python3
"""
Borg Backup Server Agent
Polls the BBS server for tasks, executes borg commands, reports progress/status.
"""

import datetime
import json
import logging
import os
import platform
import signal
import socket
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
from configparser import ConfigParser

# Python 3.4 compatibility: subprocess.run() was added in Python 3.5.
# Provide a minimal polyfill so all code can use subprocess.run() uniformly.
if not hasattr(subprocess, "run"):
    class _CompletedProcess(object):
        def __init__(self, args, returncode, stdout=None, stderr=None):
            self.args = args
            self.returncode = returncode
            self.stdout = stdout
            self.stderr = stderr

    def _subprocess_run(cmd, stdin=None, stdout=None, stderr=None, timeout=None,
                        env=None, cwd=None, universal_newlines=False, **kwargs):
        proc = subprocess.Popen(cmd, stdin=stdin, stdout=stdout, stderr=stderr,
                                env=env, cwd=cwd, universal_newlines=universal_newlines)
        try:
            out, err = proc.communicate(timeout=timeout)
        except subprocess.TimeoutExpired:
            proc.kill()
            proc.communicate()
            raise
        return _CompletedProcess(cmd, proc.returncode, out, err)

    subprocess.run = _subprocess_run
    subprocess.CompletedProcess = _CompletedProcess

AGENT_VERSION = "2.9.0"
BORG_PATH = None  # Resolved in get_system_info()
IS_WINDOWS = sys.platform == "win32"

# Ensure UTF-8 filesystem encoding for handling filenames with non-ASCII characters.
# CentOS 7 and older systems may default to ASCII, causing encoding errors.
# Setting env vars after Python starts does NOT change sys.getfilesystemencoding(),
# so we must re-exec with the correct locale if needed.
if not IS_WINDOWS and sys.getfilesystemencoding().lower() in ("ascii", "ansi_x3.4-1968") and \
   not os.environ.get("_BBS_LOCALE_RETRY"):
    os.environ["_BBS_LOCALE_RETRY"] = "1"
    import locale
    for loc in ("C.UTF-8", "en_US.UTF-8", "en_US.utf8"):
        try:
            locale.setlocale(locale.LC_ALL, loc)
            os.environ["LC_ALL"] = loc
            os.environ["LANG"] = loc
            os.execv(sys.executable, [sys.executable] + sys.argv)
        except locale.Error:
            continue

# Platform-specific paths
if IS_WINDOWS:
    _APPDATA = os.environ.get("ProgramData", r"C:\ProgramData")
    _AGENT_DIR = os.path.join(_APPDATA, "bbs-agent")
    CONFIG_PATH      = os.path.join(_AGENT_DIR, "config.ini")
    LOG_PATH         = os.path.join(_AGENT_DIR, "bbs-agent.log")
    SSH_KEY_PATH     = os.path.join(_AGENT_DIR, "ssh_key")
    BORG_SOURCE_PATH = os.path.join(_AGENT_DIR, "borg_source")
    SSH_INFO_PATH    = os.path.join(_AGENT_DIR, "ssh_info.json")
    REMOTE_KEY_PATH  = os.path.join(os.environ.get("TEMP", "."), "bbs-remote-ssh-key")
else:
    CONFIG_PATH      = "/etc/bbs-agent/config.ini"
    LOG_PATH         = "/var/log/bbs-agent.log"
    SSH_KEY_PATH     = "/etc/bbs-agent/ssh_key"
    BORG_SOURCE_PATH = "/etc/bbs-agent/borg_source"
    SSH_INFO_PATH    = "/etc/bbs-agent/ssh_info.json"
    REMOTE_KEY_PATH  = "/tmp/bbs-remote-ssh-key"

# Allow overrides for development
if os.environ.get("BBS_AGENT_CONFIG"):
    CONFIG_PATH = os.environ["BBS_AGENT_CONFIG"]
if os.environ.get("BBS_AGENT_LOG"):
    LOG_PATH = os.environ["BBS_AGENT_LOG"]

logger = logging.getLogger("bbs-agent")
running = True
task_running = False  # Set True while executing a task, enables heartbeat thread


def setup_logging():
    log_dir = os.path.dirname(LOG_PATH)
    if log_dir and not os.path.exists(log_dir):
        os.makedirs(log_dir, exist_ok=True)

    handlers = [logging.FileHandler(LOG_PATH, encoding="utf-8")]
    # Only add stdout handler if stdout is a real terminal (not redirected to the
    # same log file by launchd/systemd, which would cause duplicate lines)
    try:
        is_tty = os.isatty(sys.stdout.fileno())
    except Exception:
        is_tty = False
    if is_tty:
        handlers.append(logging.StreamHandler(sys.stdout))
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s [%(levelname)s] %(message)s",
        handlers=handlers,
    )


def load_ssh_info():
    """Load SSH connection info for catalog streaming."""
    if not os.path.exists(SSH_INFO_PATH):
        return None
    try:
        with open(SSH_INFO_PATH, "r") as f:
            return json.load(f)
    except Exception:
        return None


def load_config():
    if not os.path.exists(CONFIG_PATH):
        logger.error("Config file not found: {}".format(CONFIG_PATH))
        sys.exit(1)

    config = ConfigParser()
    config.read(CONFIG_PATH)

    return {
        "server_url": config.get("server", "url").rstrip("/"),
        "api_key": config.get("server", "api_key"),
        "poll_interval": config.getint("agent", "poll_interval", fallback=30),
    }


def api_request(config, endpoint, method="GET", data=None, timeout=30):
    """Make an authenticated request to the BBS server."""
    url = "{}{}".format(config['server_url'], endpoint)
    headers = {
        "Authorization": "Bearer {}".format(config['api_key']),
        "Content-Type": "application/json",
    }

    body = None
    if data is not None:
        body = json.dumps(data).encode("utf-8")

    req = urllib.request.Request(url, data=body, headers=headers, method=method)

    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        error_body = e.read().decode("utf-8", errors="replace")
        logger.error("API error {} on {}: {}".format(e.code, endpoint, error_body))
        return None
    except urllib.error.URLError as e:
        logger.error("Connection error on {}: {}".format(endpoint, e.reason))
        return None
    except Exception as e:
        logger.error("Request error on {}: {}".format(endpoint, e))
        return None


def get_borg_source():
    """Get the stored borg source (official/server/unknown)."""
    try:
        if os.path.exists(BORG_SOURCE_PATH):
            with open(BORG_SOURCE_PATH) as f:
                source = f.read().strip()
                if source in ("official", "server"):
                    return source
    except Exception:
        pass
    return "unknown"


def set_borg_source(source):
    """Store the borg source for future reporting."""
    try:
        os.makedirs(os.path.dirname(BORG_SOURCE_PATH), exist_ok=True)
        with open(BORG_SOURCE_PATH, "w") as f:
            f.write(source)
    except Exception as e:
        logger.warning("Failed to save borg source: {}".format(e))


def get_system_info():
    """Gather system information for registration."""
    info = {
        "hostname": socket.gethostname() or socket.getfqdn(),
        "os_info": "{} {} {}".format(platform.system(), platform.release(), platform.machine()),
        "agent_version": AGENT_VERSION,
    }

    # Try to get more detailed OS info
    if IS_WINDOWS:
        info["os_info"] = "{} {} {}".format(platform.system(), platform.version(), platform.machine())
    else:
        try:
            with open("/etc/os-release") as f:
                os_release = {}
                for line in f:
                    if "=" in line:
                        key, val = line.strip().split("=", 1)
                        os_release[key] = val.strip('"')
                if "PRETTY_NAME" in os_release:
                    info["os_info"] = "{} {}".format(os_release['PRETTY_NAME'], platform.machine())
        except FileNotFoundError:
            pass

    # Platform and architecture info (for borg binary matching)
    info["platform"] = platform.system().lower()  # linux, darwin, freebsd, windows
    arch = platform.machine()
    if arch in ("aarch64", "arm64"):
        info["architecture"] = "arm64"
    elif arch in ("x86_64", "amd64"):
        info["architecture"] = "x86_64"
    else:
        info["architecture"] = arch

    # Detect glibc version on Linux (for binary compatibility matching)
    if info["platform"] == "linux":
        try:
            import ctypes
            libc = ctypes.CDLL("libc.so.6")
            gnu_get_libc_version = libc.gnu_get_libc_version
            gnu_get_libc_version.restype = ctypes.c_char_p
            glibc_ver = gnu_get_libc_version().decode("utf-8")
            # Convert "2.31" to "glibc231"
            info["glibc_version"] = "glibc" + glibc_ver.replace(".", "")
        except Exception:
            info["glibc_version"] = None

    # Get borg version and detect installation method
    global BORG_PATH
    borg_path = None
    if IS_WINDOWS:
        candidates = [r"C:\Program Files\BorgBackup\borg.exe", r"C:\Program Files (x86)\BorgBackup\borg.exe"]
    else:
        candidates = ["/usr/local/bin/borg", "/usr/bin/borg", "/opt/homebrew/bin/borg"]
    for candidate in candidates:
        if os.path.exists(candidate):
            borg_path = candidate
            break
    if not borg_path:
        try:
            which_cmd = "where" if IS_WINDOWS else "which"
            which_result = subprocess.run(
                [which_cmd, "borg"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=5
            )
            if which_result.returncode == 0:
                borg_path = which_result.stdout.decode("utf-8", errors="replace").strip()
        except Exception:
            pass

    BORG_PATH = borg_path
    if borg_path:
        info["borg_binary_path"] = borg_path
        # Detect install method based on path
        if "/usr/local/bin" in borg_path:
            info["borg_install_method"] = "binary"
        elif "site-packages" in borg_path or ".local/bin" in borg_path:
            info["borg_install_method"] = "pip"
        else:
            info["borg_install_method"] = "package"
    else:
        info["borg_install_method"] = "unknown"

    # Get borg source (official/server) from stored state
    info["borg_source"] = get_borg_source()

    try:
        borg_cmd = borg_path if borg_path else "borg"
        result = subprocess.run(
            [borg_cmd, "--version"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10
        )
        if result.returncode == 0:
            info["borg_version"] = result.stdout.decode("utf-8", errors="replace").strip().replace("borg ", "")
    except FileNotFoundError:
        logger.warning("borg not found in PATH")
        info["borg_version"] = "not installed"
    except Exception:
        pass

    # Get primary IP
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(("8.8.8.8", 80))
        info["ip_address"] = s.getsockname()[0]
        s.close()
    except Exception:
        pass

    return info


def register(config):
    """Register this agent with the server."""
    info = get_system_info()
    logger.info("Registering with server: {}".format(config['server_url']))

    result = api_request(config, "/api/agent/register", method="POST", data=info)

    if result and result.get("status") == "ok":
        logger.info(
            "Registered as agent #{} ({})".format(result['agent_id'], result.get('name', ''))
        )
        # Update poll interval from server
        if "poll_interval" in result:
            config["poll_interval"] = result["poll_interval"]

        # Update SSH connection info from server (handles server changes/re-installs)
        if result.get("server_host") and result.get("ssh_unix_user"):
            _save_ssh_info(result)

        # Download SSH key for borg SSH access
        download_ssh_key(config)

        # Test SSH connectivity and re-download key if broken
        test_ssh_connection(config)

        return True
    else:
        logger.error("Registration failed")
        return False


def download_ssh_key(config):
    """Download SSH private key from the server if not already present."""
    have_key = os.path.exists(SSH_KEY_PATH)
    have_info = os.path.exists(SSH_INFO_PATH)

    if have_key and have_info:
        return True

    if have_key and not have_info:
        # Key exists but ssh_info.json missing (upgraded from older agent).
        # Fetch connection info from server without re-downloading the key.
        logger.info("SSH key present but ssh_info.json missing -- fetching connection info")
        result = api_request(config, "/api/agent/ssh-key")
        if result and result.get("status") == "ok":
            _save_ssh_info(result)
        return True

    logger.info("Downloading SSH key from server...")
    result = api_request(config, "/api/agent/ssh-key")

    if not result or result.get("status") != "ok":
        logger.warning("No SSH key available from server (may not be provisioned yet)")
        return False

    private_key = result.get("ssh_private_key", "")
    if not private_key:
        logger.warning("Server returned empty SSH key")
        return False

    try:
        key_dir = os.path.dirname(SSH_KEY_PATH)
        os.makedirs(key_dir, exist_ok=True)
        with open(SSH_KEY_PATH, "w") as f:
            f.write(private_key)
        if IS_WINDOWS:
            # Lock down SSH key so OpenSSH doesn't reject it for "too open" permissions
            subprocess.run(
                ["icacls", SSH_KEY_PATH, "/inheritance:r", "/grant:r", "SYSTEM:(R)", "Administrators:(R)"],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
            )
        else:
            os.chmod(SSH_KEY_PATH, 0o600)
        logger.info("SSH key saved to {}".format(SSH_KEY_PATH))
        _save_ssh_info(result)
        return True
    except Exception as e:
        logger.error("Failed to save SSH key: {}".format(e))
        return False


def _save_ssh_info(result):
    """Save SSH connection info for catalog streaming."""
    ssh_user = result.get("ssh_unix_user", "")
    server_host = result.get("server_host", "")
    ssh_port = result.get("ssh_port", 22)
    if ssh_user and server_host:
        ssh_info = {
            "ssh_unix_user": ssh_user,
            "server_host": server_host,
            "ssh_port": ssh_port,
        }
        with open(SSH_INFO_PATH, "w") as f:
            json.dump(ssh_info, f)
        logger.info("SSH configured: {}@{}:{}".format(ssh_user, server_host, ssh_port))


def test_ssh_connection(config):
    """Test SSH connectivity to the server and re-download key if broken."""
    ssh_info = load_ssh_info()
    if not ssh_info or not os.path.exists(SSH_KEY_PATH):
        return  # No SSH setup yet, nothing to test

    ssh_user = ssh_info.get("ssh_unix_user", "")
    server_host = ssh_info.get("server_host", "")
    ssh_port = str(ssh_info.get("ssh_port", 22))
    if not ssh_user or not server_host:
        return

    def _try_ssh():
        """Attempt SSH connection, return (success, stderr_output).

        Uses 'ping' command via bbs-ssh-gate. Return code 0 means
        auth + command both succeeded. 'command not allowed' means
        auth succeeded but gate doesn't support ping yet (still OK).
        'Permission denied' means auth actually failed (key mismatch).
        """
        try:
            proc = subprocess.Popen(
                [
                    "ssh",
                    "-i", SSH_KEY_PATH,
                    "-p", ssh_port,
                    "-o", "StrictHostKeyChecking=no",
                    "-o", "BatchMode=yes",
                    "-o", "ConnectTimeout=10",
                    "{}@{}".format(ssh_user, server_host),
                    "ping",
                ],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
            )
            stdout, stderr = proc.communicate(timeout=30)
            stdout_str = stdout.decode("utf-8", errors="replace").strip()
            stderr_str = stderr.decode("utf-8", errors="replace").strip()
            if proc.returncode == 0 and "pong" in stdout_str:
                return True, ""
            # Gate rejected command = auth worked, gate just needs update
            if "command not allowed" in stderr_str:
                return True, ""
            # Auth failure = key mismatch, needs re-download
            if "Permission denied" in stderr_str:
                return False, stderr_str
            # Other errors (network, timeout) -- not a key issue
            return True, ""
        except Exception as e:
            return True, ""  # Network issues aren't key problems

    ok, err = _try_ssh()
    if ok:
        logger.info("SSH connectivity test passed")
        return

    logger.warning("SSH connectivity test failed: {}".format(err))

    # Force re-download the key
    logger.info("Re-downloading SSH key from server...")
    try:
        os.unlink(SSH_KEY_PATH)
    except OSError:
        pass
    try:
        os.unlink(SSH_INFO_PATH)
    except OSError:
        pass

    if not download_ssh_key(config):
        logger.error("SSH key re-download failed")
        return

    # Retry with fresh key
    ok, err = _try_ssh()
    if ok:
        logger.info("SSH connectivity test passed after key re-download")
    else:
        logger.error("SSH connectivity test still failing after key re-download: {}".format(err))


def count_files(directories):
    """Pre-count files in directories for progress tracking."""
    total = 0
    for dir_path in directories.split():
        dir_path = dir_path.strip()
        if not os.path.exists(dir_path):
            continue
        try:
            for root, dirs, files in os.walk(dir_path):
                total += len(files)
        except PermissionError:
            continue
    return total


def execute_update_borg(config, task):
    """Update borg via binary download, with optional pip fallback."""
    job_id = task.get("job_id")
    target_version = task.get("target_version", "")
    download_url = task.get("download_url")
    install_method = task.get("install_method", "binary")
    binary_path = task.get("binary_path", "/usr/local/bin/borg")
    fallback_to_pip = task.get("fallback_to_pip", True)
    source = task.get("source", "official")  # 'official' or 'server'

    logger.info("Executing borg update job #{} to v{} via {} (source={})".format(job_id, target_version, install_method, source))

    # Handle skip - agent is incompatible with selected server version
    if install_method == "skip":
        logger.info("Skipping borg update - no compatible binary for this agent")
        api_request(config, "/api/agent/status", method="POST", data={
            "job_id": job_id,
            "result": "completed",
            "output_log": "Skipped - no compatible binary available for this platform",
        })
        return

    # Report running
    api_request(config, "/api/agent/progress", method="POST", data={
        "job_id": job_id, "files_total": 0, "files_processed": 0,
    })

    error_output = ""
    update_output = ""
    result = "failed"

    try:
        if IS_WINDOWS:
            # Windows: always update from borg-windows GitHub releases
            result, update_output, error_output = _install_borg_windows()

        elif install_method == "binary" and download_url:
            result, update_output, error_output = _install_borg_binary(
                download_url, binary_path, target_version
            )
            # If binary install failed, try package manager fallback
            if result == "failed" and fallback_to_pip:
                logger.warning("Binary install failed ({}), falling back to package manager".format(error_output))
                result, update_output, error_output = _install_borg_package_manager()
                # If package manager also failed, try pip as last resort
                if result == "failed":
                    logger.warning("Package manager failed ({}), falling back to pip".format(error_output))
                    result, update_output, error_output = _install_borg_pip(target_version)

        elif install_method == "pip":
            # Server explicitly requested pip (no binary available)
            # First try package manager (more reliable), then pip
            result, update_output, error_output = _install_borg_package_manager()
            if result == "failed":
                logger.warning("Package manager failed ({}), falling back to pip".format(error_output))
                result, update_output, error_output = _install_borg_pip(target_version)

        else:
            error_output = "No download URL or install method provided"

    except Exception as e:
        error_output = str(e)
        logger.error("Borg update error: {}".format(e))

    # Report status
    status_data = {"job_id": job_id, "result": result}
    if error_output:
        status_data["error_log"] = error_output[:10000]
    elif result == "completed":
        status_data["output_log"] = update_output[:10000]
    api_request(config, "/api/agent/status", method="POST", data=status_data)

    # Re-report system info so borg_version gets updated
    if result == "completed":
        # Save the source for future reporting
        set_borg_source(source)
        info = get_system_info()
        api_request(config, "/api/agent/info", method="POST", data=info)
        logger.info("Updated borg version: {} (source={})".format(info.get('borg_version', 'unknown'), source))


def _install_borg_binary(download_url, binary_path, target_version):
    """Download a pre-compiled borg binary from GitHub and install it."""
    logger.info("Downloading borg binary from {}".format(download_url))

    req = urllib.request.Request(
        download_url,
        headers={"User-Agent": "bbs-agent/{}".format(AGENT_VERSION)}
    )

    try:
        with urllib.request.urlopen(req, timeout=300) as resp:
            binary_data = resp.read()
    except urllib.error.HTTPError as e:
        return "failed", "", "Download failed: HTTP {}".format(e.code)
    except Exception as e:
        return "failed", "", "Download failed: {}".format(e)

    # Basic size check (borg binaries are typically 10MB+)
    if len(binary_data) < 1 * 1024 * 1024:
        return "failed", "", "Downloaded file too small ({} bytes), likely not a valid binary".format(len(binary_data))

    # Write to temp file
    tmp_path = binary_path + ".tmp"
    try:
        os.makedirs(os.path.dirname(binary_path), exist_ok=True)
        with open(tmp_path, "wb") as f:
            f.write(binary_data)
        if not IS_WINDOWS:
            os.chmod(tmp_path, 0o755)
    except Exception as e:
        return "failed", "", "Failed to write binary: {}".format(e)

    # Test the binary — run both --version and a functional test (help create)
    # to catch glibc incompatibilities that --version alone may not trigger
    try:
        test_proc = subprocess.run(
            [tmp_path, "--version"],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10
        )
        if test_proc.returncode != 0:
            os.remove(tmp_path)
            stderr = test_proc.stderr.decode("utf-8", errors="replace")
            return "failed", "", "Downloaded binary failed version check: {}".format(stderr)

        actual_version = test_proc.stdout.decode("utf-8", errors="replace").strip().replace("borg ", "")
        logger.info("Binary version check passed: {}".format(actual_version))

        # Functional test: exercises more of the binary to catch glibc issues
        func_proc = subprocess.run(
            [tmp_path, "help", "create"],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10
        )
        if func_proc.returncode != 0:
            os.remove(tmp_path)
            stderr = func_proc.stderr.decode("utf-8", errors="replace")
            return "failed", "", "Downloaded binary failed functional test: {}".format(stderr)
        logger.info("Binary functional test passed")
    except Exception as e:
        if os.path.exists(tmp_path):
            os.remove(tmp_path)
        return "failed", "", "Binary test failed: {}".format(e)

    # Backup old binary and install new one
    try:
        backup_path = binary_path + ".bak"
        if os.path.exists(binary_path):
            os.rename(binary_path, backup_path)
        os.rename(tmp_path, binary_path)
        # Keep .bak around for manual recovery if needed — it will be
        # overwritten on the next successful update anyway
    except Exception as e:
        # Try to restore backup
        if os.path.exists(backup_path) and not os.path.exists(binary_path):
            os.rename(backup_path, binary_path)
        return "failed", "", "Failed to install binary: {}".format(e)

    # Remove package manager borg to avoid having two versions (Unix only)
    # /usr/local/bin takes precedence in PATH, but it's cleaner to have just one
    pkg_borg = "/usr/bin/borg"
    if not IS_WINDOWS and os.path.exists(pkg_borg) and binary_path != pkg_borg:
        try:
            # Try to uninstall via package manager
            if os.path.exists("/usr/bin/apt-get"):
                subprocess.run(["apt-get", "remove", "-y", "borgbackup"],
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=60)
            elif os.path.exists("/usr/bin/dnf"):
                subprocess.run(["dnf", "remove", "-y", "borgbackup"],
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=60)
            elif os.path.exists("/usr/bin/yum"):
                subprocess.run(["yum", "remove", "-y", "borgbackup"],
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=60)
            elif os.path.exists("/usr/bin/pacman"):
                subprocess.run(["pacman", "-R", "--noconfirm", "borg"],
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=60)
            logger.info("Removed package manager borg to avoid duplicate installations")
        except Exception as e:
            logger.warning("Could not remove package manager borg: {}".format(e))

    output = "Borg updated to v{} via binary install at {}".format(target_version, binary_path)
    logger.info(output)
    return "completed", output, ""


def _install_borg_windows():
    """Update borg on Windows from the borg-windows GitHub releases."""
    import zipfile
    import tempfile

    borg_dir = os.path.join(os.environ.get("ProgramFiles", r"C:\Program Files"), "BorgBackup")
    borg_exe = os.path.join(borg_dir, "borg", "borg.exe")
    api_url = "https://api.github.com/repos/marcpope/borg-windows/releases/latest"

    # Get current version for comparison
    old_version = ""
    if os.path.isfile(borg_exe):
        try:
            r = subprocess.run([borg_exe, "--version"], capture_output=True, timeout=10)
            old_version = r.stdout.decode("utf-8", errors="replace").strip()
        except Exception:
            pass

    # Query GitHub API for latest release
    logger.info("Querying borg-windows latest release...")
    try:
        req = urllib.request.Request(api_url, headers={"User-Agent": "bbs-agent/{}".format(AGENT_VERSION)})
        with urllib.request.urlopen(req, timeout=30) as resp:
            release = json.loads(resp.read().decode("utf-8"))
    except Exception as e:
        return "failed", "", "Failed to query GitHub releases: {}".format(e)

    tag = release.get("tag_name", "unknown")
    assets = release.get("assets", [])
    zip_url = None
    for asset in assets:
        if asset.get("name") == "borg-windows.zip":
            zip_url = asset.get("browser_download_url")
            break

    if not zip_url:
        return "failed", "", "No borg-windows.zip found in release {}".format(tag)

    logger.info("Downloading borg-windows {} from {}".format(tag, zip_url))

    # Download zip
    try:
        req = urllib.request.Request(zip_url, headers={"User-Agent": "bbs-agent/{}".format(AGENT_VERSION)})
        with urllib.request.urlopen(req, timeout=300) as resp:
            zip_data = resp.read()
    except Exception as e:
        return "failed", "", "Failed to download borg-windows.zip: {}".format(e)

    if len(zip_data) < 1 * 1024 * 1024:
        return "failed", "", "Downloaded zip too small ({} bytes)".format(len(zip_data))

    # Extract to borg directory
    zip_path = os.path.join(tempfile.gettempdir(), "borg-windows-update.zip")
    try:
        with open(zip_path, "wb") as f:
            f.write(zip_data)
        os.makedirs(borg_dir, exist_ok=True)
        with zipfile.ZipFile(zip_path, "r") as zf:
            zf.extractall(borg_dir)
        os.remove(zip_path)
    except Exception as e:
        return "failed", "", "Failed to extract borg-windows.zip: {}".format(e)

    # Verify new binary works
    if not os.path.isfile(borg_exe):
        return "failed", "", "borg.exe not found after extraction at {}".format(borg_exe)

    try:
        r = subprocess.run([borg_exe, "--version"], capture_output=True, timeout=10)
        new_version = r.stdout.decode("utf-8", errors="replace").strip()
        if r.returncode != 0:
            return "failed", "", "New borg binary failed version check"
    except Exception as e:
        return "failed", "", "Failed to test new borg binary: {}".format(e)

    output = "Borg updated from '{}' to '{}' (release {})".format(old_version, new_version, tag)
    logger.info(output)
    return "completed", output, ""


def _install_borg_pip(target_version):
    """Install borg via pip. Removes any existing non-pip binary first."""
    if IS_WINDOWS:
        return "failed", "", "pip install not supported on Windows"
    # Check if there's an existing binary at /usr/local/bin/borg that's not from pip
    # (pip-installed borg is a script, not a large ELF binary)
    existing_binary = "/usr/local/bin/borg"
    if os.path.exists(existing_binary):
        try:
            # Check if it's a large binary (ELF binaries are typically 10MB+)
            size = os.path.getsize(existing_binary)
            if size > 5 * 1024 * 1024:  # > 5MB = likely a compiled binary, not pip
                logger.info("Removing existing binary at {} ({} bytes) to allow pip install".format(existing_binary, size))
                backup_path = existing_binary + ".bak"
                os.rename(existing_binary, backup_path)
                # Keep backup in case pip fails - we'll clean up on success
        except Exception as e:
            logger.warning("Could not check/remove existing binary: {}".format(e))

    version_spec = "borgbackup=={}".format(target_version) if target_version and target_version != "latest" else "borgbackup"
    cmd = ["pip3", "install", "--upgrade", version_spec]
    logger.info("Installing borg via pip: {}".format(' '.join(cmd)))

    try:
        proc = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=600)
        stdout_text = proc.stdout.decode("utf-8", errors="replace").strip()
        stderr_text = proc.stderr.decode("utf-8", errors="replace").strip()

        if proc.returncode == 0:
            # Clean up backup if pip succeeded
            backup_path = existing_binary + ".bak"
            if os.path.exists(backup_path):
                try:
                    os.remove(backup_path)
                except Exception:
                    pass
            # Get actual installed version
            try:
                result = subprocess.run(["borg", "--version"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10)
                if result.returncode == 0:
                    installed_ver = result.stdout.decode().strip().replace("borg ", "")
                    output = "Borg updated to v{} via pip".format(installed_ver)
                else:
                    output = "Borg updated via pip"
            except Exception:
                output = "Borg updated via pip"
            logger.info(output)
            return "completed", output, ""
        else:
            # Restore backup if pip failed
            backup_path = existing_binary + ".bak"
            if os.path.exists(backup_path) and not os.path.exists(existing_binary):
                try:
                    os.rename(backup_path, existing_binary)
                    logger.info("Restored backup binary after pip failure")
                except Exception:
                    pass
            error = stderr_text or stdout_text or "Exit code {}".format(proc.returncode)
            logger.error("pip install failed: {}".format(error))
            return "failed", "", error
    except subprocess.TimeoutExpired:
        return "failed", "", "pip install timed out"
    except Exception as e:
        return "failed", "", str(e)


def _install_borg_package_manager():
    """Install/update borg via OS package manager. Removes any existing /usr/local/bin/borg first."""
    if IS_WINDOWS:
        return "failed", "", "Package manager install not supported on Windows"
    # Remove any existing binary at /usr/local/bin/borg so package manager version is used
    existing_binary = "/usr/local/bin/borg"
    if os.path.exists(existing_binary):
        try:
            size = os.path.getsize(existing_binary)
            logger.info("Removing existing binary at {} ({} bytes) to use package manager".format(existing_binary, size))
            backup_path = existing_binary + ".bak"
            os.rename(existing_binary, backup_path)
        except Exception as e:
            logger.warning("Could not remove existing binary: {}".format(e))

    if os.path.exists("/usr/bin/apt-get"):
        cmd = ["apt-get", "install", "-y", "borgbackup"]
        pre_cmd = ["apt-get", "update", "-qq"]
    elif os.path.exists("/usr/bin/dnf"):
        cmd = ["dnf", "install", "-y", "borgbackup"]
        pre_cmd = None
    elif os.path.exists("/usr/bin/yum"):
        cmd = ["yum", "install", "-y", "borgbackup"]
        pre_cmd = None
    elif os.path.exists("/usr/bin/pacman"):
        cmd = ["pacman", "-Sy", "--noconfirm", "borg"]
        pre_cmd = None
    elif os.path.exists("/usr/local/bin/brew") or os.path.exists("/opt/homebrew/bin/brew"):
        cmd = ["brew", "install", "borgbackup"]
        pre_cmd = None
    else:
        # Restore backup if no package manager
        backup_path = existing_binary + ".bak"
        if os.path.exists(backup_path) and not os.path.exists(existing_binary):
            os.rename(backup_path, existing_binary)
        return "failed", "", "No supported package manager found"

    try:
        if pre_cmd:
            subprocess.run(pre_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120)

        proc = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=600)
        stdout_text = proc.stdout.decode("utf-8", errors="replace").strip()
        stderr_text = proc.stderr.decode("utf-8", errors="replace").strip()

        if proc.returncode == 0:
            # Clean up backup on success
            backup_path = existing_binary + ".bak"
            if os.path.exists(backup_path):
                try:
                    os.remove(backup_path)
                except Exception:
                    pass
            # Get installed version
            try:
                result = subprocess.run(["borg", "--version"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10)
                if result.returncode == 0:
                    installed_ver = result.stdout.decode().strip().replace("borg ", "")
                    output = "Borg updated to v{} via package manager".format(installed_ver)
                else:
                    output = "Borg installed via package manager"
            except Exception:
                output = "Borg installed via package manager"
            return "completed", output, ""
        else:
            # Restore backup on failure
            backup_path = existing_binary + ".bak"
            if os.path.exists(backup_path) and not os.path.exists(existing_binary):
                try:
                    os.rename(backup_path, existing_binary)
                    logger.info("Restored backup binary after package manager failure")
                except Exception:
                    pass
            error = stderr_text or stdout_text or "Exit code {}".format(proc.returncode)
            return "failed", "", error
    except subprocess.TimeoutExpired:
        return "failed", "", "Update command timed out"
    except Exception as e:
        return "failed", "", str(e)


def execute_update_agent(config, task):
    """Download and replace the agent script from the server, then restart."""
    job_id = task.get("job_id")
    logger.info("Executing agent update job #{}".format(job_id))

    # Report running
    api_request(config, "/api/agent/progress", method="POST", data={
        "job_id": job_id, "files_total": 0, "files_processed": 0,
    })

    error_output = ""
    result = "failed"
    update_output = ""

    try:
        # Download new agent script from server
        url = "{}/api/agent/download?file=bbs-agent.py".format(config['server_url'])
        headers = {
            "Authorization": "Bearer {}".format(config['api_key']),
        }
        req = urllib.request.Request(url, headers=headers, method="GET")

        with urllib.request.urlopen(req, timeout=60) as resp:
            new_script = resp.read().decode("utf-8")

        # Validate the downloaded script
        if "AGENT_VERSION" not in new_script or len(new_script) < 1000:
            error_output = "Downloaded script failed validation"
            logger.error(error_output)
        else:
            # On Windows (launcher pattern): update bbs-agent-run.py next to the exe
            # On Unix: replace the running script in-place
            if IS_WINDOWS:
                script_path = os.path.join(os.path.dirname(sys.executable), "bbs-agent-run.py")
            else:
                script_path = os.path.abspath(__file__)
            logger.info("Replacing agent at: {}".format(script_path))

            # Write new script to temp file first, then move
            tmp_path = script_path + ".tmp"
            with open(tmp_path, "w", encoding="utf-8") as f:
                f.write(new_script)
            if not IS_WINDOWS:
                os.chmod(tmp_path, os.stat(script_path).st_mode)
            os.replace(tmp_path, script_path)

            # Extract new version from downloaded script
            new_version = "unknown"
            for line in new_script.split("\n")[:50]:
                m = __import__("re").match(r'^AGENT_VERSION\s*=\s*["\']([^"\']+)["\']', line)
                if m:
                    new_version = m.group(1)
                    break

            result = "completed"
            update_output = "Agent updated to v{}".format(new_version)
            logger.info(update_output)

    except urllib.error.HTTPError as e:
        error_output = "Download failed: HTTP {}".format(e.code)
        logger.error(error_output)
    except Exception as e:
        error_output = str(e)
        logger.error("Agent update error: {}".format(e))

    # Report status
    status_data = {"job_id": job_id, "result": result}
    if error_output:
        status_data["error_log"] = error_output[:10000]
    elif result == "completed":
        status_data["output_log"] = update_output[:10000]
    api_request(config, "/api/agent/status", method="POST", data=status_data)

    # Re-report system info so agent_version gets updated, then restart
    if result == "completed":
        info = get_system_info()
        api_request(config, "/api/agent/info", method="POST", data=info)
        logger.info("Restarting agent with new script...")
        if IS_WINDOWS:
            # Windows launcher pattern: restart the Windows Service
            # The service manager will re-launch the exe, which loads the updated .py
            subprocess.Popen(
                'cmd /c "timeout /t 2 /nobreak >nul & sc stop BorgBackupAgent & timeout /t 2 /nobreak >nul & sc start BorgBackupAgent"',
                creationflags=0x00000008,  # DETACHED_PROCESS
            )
            sys.exit(0)
        else:
            os.execv(sys.executable, [sys.executable] + sys.argv)


def log_to_server(config, job_id, message, level="info"):
    """Send a log message to the server for display in the job activity log."""
    try:
        api_request(config, "/api/agent/progress", method="POST", data={
            "job_id": job_id,
            "log_message": message,
            "log_level": level,
        })
    except Exception:
        pass  # Don't fail the job over a log message


PLUGIN_DISPLAY_NAMES = {
    "mysql_dump": "MySQL Dump",
    "pg_dump": "PostgreSQL Dump",
}


def execute_plugins(plugins, config=None, job_id=None):
    """Execute pre-backup plugins. Returns dict of results keyed by slug."""
    results = {}
    for plugin in plugins:
        slug = plugin.get("slug", "")
        cfg = plugin.get("config", {})
        display = PLUGIN_DISPLAY_NAMES.get(slug, slug)
        logger.info("Running pre-backup plugin: {}".format(slug))
        if config and job_id:
            api_request(config, "/api/agent/progress", method="POST", data={
                "job_id": job_id,
                "status_message": "Running plugin: {}".format(display),
            })
        func_name = "execute_plugin_{}".format(slug)
        func = globals().get(func_name)
        if not func:
            logger.warning("Plugin {} not implemented, skipping".format(slug))
            continue
        result = func(cfg)
        results[slug] = result
        logger.info("Plugin {} completed".format(slug))

        # Send plugin summary to server log
        if config and job_id:
            summary = _plugin_summary(slug, cfg, result)
            if summary:
                log_to_server(config, job_id, summary)
    return results


def _plugin_summary(slug, config, result):
    """Build a human-readable summary of what a plugin did."""
    if slug == "mysql_dump":
        dump_files = result.get("dump_files", [])
        dump_dir = result.get("dump_dir", "")
        db_names = [os.path.basename(f).split(".")[0] for f in dump_files]
        total_size = sum(os.path.getsize(f) for f in dump_files if os.path.exists(f))
        size_str = _format_size(total_size)
        return "MySQL dump: {} database(s) ({}) dumped to {} ({})".format(len(dump_files), ', '.join(db_names), dump_dir, size_str)
    if slug == "pg_dump":
        dump_files = result.get("dump_files", [])
        dump_dir = result.get("dump_dir", "")
        db_names = [os.path.basename(f).split(".")[0] for f in dump_files]
        total_size = sum(os.path.getsize(f) for f in dump_files if os.path.exists(f))
        size_str = _format_size(total_size)
        return "PostgreSQL dump: {} database(s) ({}) dumped to {} ({})".format(len(dump_files), ', '.join(db_names), dump_dir, size_str)
    if slug == "shell_hook":
        parts = []
        pre = result.get("pre_script", "")
        if pre:
            code = result.get("pre_exit_code", "?")
            parts.append("pre-script: {} (exit {})".format(pre, code))
        post = result.get("post_script", "")
        if post:
            parts.append("post-script: {} (pending)".format(post))
        output = result.get("pre_output", "").strip()
        if output:
            # Truncate for summary
            if len(output) > 200:
                output = output[:200] + "..."
            parts.append("output: {}".format(output))
        return "Shell hook: {}".format(' | '.join(parts)) if parts else None
    return None


def _format_size(bytes_val):
    """Format bytes into human-readable size."""
    for unit in ['B', 'KB', 'MB', 'GB', 'TB']:
        if bytes_val < 1024:
            if unit != 'B':
                return "{:.1f} {}".format(bytes_val, unit)
            else:
                return "{} {}".format(bytes_val, unit)
        bytes_val /= 1024
    return "{:.1f} PB".format(bytes_val)


def cleanup_plugins(plugins, plugin_results, config=None, job_id=None):
    """Run post-backup cleanup for plugins."""
    for plugin in plugins:
        slug = plugin.get("slug", "")
        cfg = plugin.get("config", {})
        func = globals().get("cleanup_plugin_{}".format(slug))
        if func:
            try:
                cleanup_result = func(cfg, plugin_results.get(slug, {}))
                if config and job_id:
                    if cfg.get("cleanup_after", True):
                        dump_dir = plugin_results.get(slug, {}).get("dump_dir", "")
                        if dump_dir:
                            log_to_server(config, job_id, "Plugin cleanup: removed dump files from {}".format(dump_dir))
                    if slug == "shell_hook" and cleanup_result:
                        log_to_server(config, job_id, "Shell hook post-script: {}".format(cleanup_result))
            except Exception as e:
                logger.warning("Plugin cleanup for {} failed: {}".format(slug, e))
                if config and job_id:
                    log_to_server(config, job_id, "Plugin cleanup for {} failed: {}".format(slug, e), "warning")


def execute_plugin_mysql_dump(config):
    """Dump MySQL/MariaDB databases before backup."""
    dump_dir = config.get("dump_dir", "/home/bbs/mysql")
    os.makedirs(dump_dir, exist_ok=True)

    host = config.get("host", "localhost")
    port = str(config.get("port", 3306))
    user = config.get("user")
    password = config.get("password")
    databases = config.get("databases", "*")
    per_database = config.get("per_database", True)
    compress = config.get("compress", True)
    exclude = config.get("exclude_databases", ["information_schema", "performance_schema", "sys"])
    extra_options = config.get("extra_options", "--single-transaction --quick --routines --triggers --events")

    if not user or not password:
        raise Exception("MySQL plugin requires user and password")

    if isinstance(databases, str) and databases.strip() == "*":
        # List all databases
        list_cmd = [
            "mysql",
            "--host={}".format(host),
            "--port={}".format(port),
            "--user={}".format(user),
            "--password={}".format(password),
            "-e", "SHOW DATABASES;",
            "-s", "--skip-column-names",
        ]
        result = subprocess.run(list_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if result.returncode != 0:
            raise Exception("Failed to list databases: {}".format(result.stderr.decode('utf-8', errors='replace').strip()))
        if isinstance(exclude, str):
            exclude = [x.strip() for x in exclude.split(",")]
        databases = [
            db.strip() for db in result.stdout.decode("utf-8", errors="replace").strip().split("\n")
            if db.strip() and db.strip() not in exclude
        ]
    elif isinstance(databases, str):
        databases = [d.strip() for d in databases.split(",") if d.strip()]

    base_cmd = ["mysqldump", "--host={}".format(host), "--port={}".format(port), "--user={}".format(user), "--password={}".format(password)]
    if extra_options:
        base_cmd.extend(extra_options.split())

    dump_files = []

    if per_database:
        for db in databases:
            filename = "{}.sql.gz".format(db) if compress else "{}.sql".format(db)
            dump_path = os.path.join(dump_dir, filename)
            logger.info("Dumping database {} to {}".format(db, dump_path))

            cmd = base_cmd + [db]
            if compress:
                dump_proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                if IS_WINDOWS:
                    import gzip as gzip_mod
                    with gzip_mod.open(dump_path, "wb") as f:
                        while True:
                            chunk = dump_proc.stdout.read(65536)
                            if not chunk:
                                break
                            f.write(chunk)
                    dump_proc.wait()
                else:
                    with open(dump_path, "wb") as f:
                        gzip_proc = subprocess.Popen(["gzip"], stdin=dump_proc.stdout, stdout=f, stderr=subprocess.PIPE)
                    dump_proc.stdout.close()
                    gzip_proc.wait()
                    dump_proc.wait()
                if dump_proc.returncode != 0:
                    stderr = dump_proc.stderr.read().decode() if dump_proc.stderr else ""
                    raise Exception("mysqldump failed for {}: {}".format(db, stderr))
            else:
                with open(dump_path, "w") as f:
                    r = subprocess.run(cmd, stdout=f, stderr=subprocess.PIPE)
                    if r.returncode != 0:
                        raise Exception("mysqldump failed for {}: {}".format(db, r.stderr.decode('utf-8', errors='replace')))

            dump_files.append(dump_path)
    else:
        filename = "all_databases.sql.gz" if compress else "all_databases.sql"
        dump_path = os.path.join(dump_dir, filename)
        logger.info("Dumping all databases to {}".format(dump_path))

        cmd = base_cmd + ["--all-databases"]
        if compress:
            dump_proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            if IS_WINDOWS:
                import gzip as gzip_mod
                with gzip_mod.open(dump_path, "wb") as f:
                    while True:
                        chunk = dump_proc.stdout.read(65536)
                        if not chunk:
                            break
                        f.write(chunk)
                dump_proc.wait()
            else:
                with open(dump_path, "wb") as f:
                    gzip_proc = subprocess.Popen(["gzip"], stdin=dump_proc.stdout, stdout=f, stderr=subprocess.PIPE)
                dump_proc.stdout.close()
                gzip_proc.wait()
                dump_proc.wait()
            if dump_proc.returncode != 0:
                stderr = dump_proc.stderr.read().decode() if dump_proc.stderr else ""
                raise Exception("mysqldump failed: {}".format(stderr))
        else:
            with open(dump_path, "w") as f:
                r = subprocess.run(cmd, stdout=f, stderr=subprocess.PIPE)
                if r.returncode != 0:
                    raise Exception("mysqldump failed: {}".format(r.stderr.decode('utf-8', errors='replace')))

        dump_files.append(dump_path)

    logger.info("MySQL dump complete: {} file(s) in {}".format(len(dump_files), dump_dir))
    db_names = [os.path.basename(f).split(".")[0] for f in dump_files]
    return {
        "dump_files": dump_files,
        "dump_dir": dump_dir,
        "databases": db_names,
        "per_database": per_database,
        "compress": compress,
    }


def cleanup_plugin_mysql_dump(config, plugin_result):
    """Delete dump files after backup if cleanup_after is enabled."""
    if not config.get("cleanup_after", True):
        return
    dump_dir = plugin_result.get("dump_dir")
    if not dump_dir or not os.path.exists(dump_dir):
        return
    import shutil
    logger.info("Cleaning up MySQL dumps in {}".format(dump_dir))
    for f in os.listdir(dump_dir):
        fpath = os.path.join(dump_dir, f)
        if os.path.isfile(fpath) and (f.endswith(".sql") or f.endswith(".sql.gz")):
            os.remove(fpath)


def test_plugin_mysql_dump(config):
    """Test MySQL connectivity without dumping."""
    host = config.get("host", "localhost")
    port = str(config.get("port", 3306))
    user = config.get("user")
    password = config.get("password")
    if not user or not password:
        raise Exception("MySQL plugin requires user and password")

    cmd = ["mysql", "--host={}".format(host), "--port={}".format(port), "--user={}".format(user),
           "--password={}".format(password), "-e", "SELECT 1;", "-s", "--skip-column-names"]
    result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
    if result.returncode != 0:
        raise Exception("Connection failed: {}".format(result.stderr.decode('utf-8', errors='replace').strip()))

    # Test SHOW DATABASES for permissions
    cmd2 = ["mysql", "--host={}".format(host), "--port={}".format(port), "--user={}".format(user),
            "--password={}".format(password), "-e", "SHOW DATABASES;", "-s", "--skip-column-names"]
    result2 = subprocess.run(cmd2, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
    dbs = result2.stdout.decode("utf-8", errors="replace").strip().split("\n")
    dbs = [d for d in dbs if d]
    return "Connection successful. Found {} database(s): {}".format(len(dbs), ', '.join(dbs[:10]))


def execute_plugin_pg_dump(config):
    """Dump PostgreSQL databases before backup."""
    dump_dir = config.get("dump_dir", "/home/bbs/pgdump")
    os.makedirs(dump_dir, exist_ok=True)

    host = config.get("host", "localhost")
    port = str(config.get("port", 5432))
    user = config.get("user")
    password = config.get("password")
    databases = config.get("databases", "*")
    compress = config.get("compress", True)
    exclude = config.get("exclude_databases", ["template0", "template1", "postgres"])
    extra_options = config.get("extra_options", "--no-owner --no-privileges")

    if not user or not password:
        raise Exception("PostgreSQL plugin requires user and password")

    pg_env = os.environ.copy()
    pg_env["PGPASSWORD"] = password

    if isinstance(databases, str) and databases.strip() == "*":
        # List all databases via psql
        list_cmd = ["psql", "-h", host, "-p", port, "-U", user, "-l", "-t", "-A"]
        result = subprocess.run(list_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env)
        if result.returncode != 0:
            raise Exception("Failed to list databases: {}".format(result.stderr.decode('utf-8', errors='replace').strip()))
        if isinstance(exclude, str):
            exclude = [x.strip() for x in exclude.split(",")]
        # psql -l -t -A outputs: dbname|owner|encoding|collate|ctype|access
        databases = []
        for line in result.stdout.decode("utf-8", errors="replace").strip().split("\n"):
            parts = line.split("|")
            if parts and parts[0].strip() and parts[0].strip() not in exclude:
                databases.append(parts[0].strip())
    elif isinstance(databases, str):
        databases = [d.strip() for d in databases.split(",") if d.strip()]

    dump_files = []

    for db in databases:
        filename = "{}.sql.gz".format(db) if compress else "{}.sql".format(db)
        dump_path = os.path.join(dump_dir, filename)
        logger.info("Dumping PostgreSQL database {} to {}".format(db, dump_path))

        cmd = ["pg_dump", "-h", host, "-p", port, "-U", user]
        if extra_options:
            cmd.extend(extra_options.split())
        cmd.append(db)

        if compress:
            dump_proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env)
            if IS_WINDOWS:
                import gzip as gzip_mod
                with gzip_mod.open(dump_path, "wb") as f:
                    while True:
                        chunk = dump_proc.stdout.read(65536)
                        if not chunk:
                            break
                        f.write(chunk)
                dump_proc.wait()
            else:
                with open(dump_path, "wb") as f:
                    gzip_proc = subprocess.Popen(["gzip"], stdin=dump_proc.stdout, stdout=f, stderr=subprocess.PIPE)
                dump_proc.stdout.close()
                gzip_proc.wait()
                dump_proc.wait()
            if dump_proc.returncode != 0:
                stderr = dump_proc.stderr.read().decode() if dump_proc.stderr else ""
                raise Exception("pg_dump failed for {}: {}".format(db, stderr))
        else:
            with open(dump_path, "w") as f:
                r = subprocess.run(cmd, stdout=f, stderr=subprocess.PIPE, env=pg_env)
                if r.returncode != 0:
                    raise Exception("pg_dump failed for {}: {}".format(db, r.stderr.decode('utf-8', errors='replace')))

        dump_files.append(dump_path)

    logger.info("PostgreSQL dump complete: {} file(s) in {}".format(len(dump_files), dump_dir))
    db_names = [os.path.basename(f).split(".")[0] for f in dump_files]
    return {
        "dump_files": dump_files,
        "dump_dir": dump_dir,
        "databases": db_names,
        "per_database": True,
        "compress": compress,
    }


def cleanup_plugin_pg_dump(config, plugin_result):
    """Delete PostgreSQL dump files after backup if cleanup_after is enabled."""
    if not config.get("cleanup_after", True):
        return
    dump_dir = plugin_result.get("dump_dir")
    if not dump_dir or not os.path.exists(dump_dir):
        return
    logger.info("Cleaning up PostgreSQL dumps in {}".format(dump_dir))
    for f in os.listdir(dump_dir):
        fpath = os.path.join(dump_dir, f)
        if os.path.isfile(fpath) and (f.endswith(".sql") or f.endswith(".sql.gz")):
            os.remove(fpath)


def test_plugin_pg_dump(config):
    """Test PostgreSQL connectivity without dumping."""
    host = config.get("host", "localhost")
    port = str(config.get("port", 5432))
    user = config.get("user")
    password = config.get("password")
    if not user or not password:
        raise Exception("PostgreSQL plugin requires user and password")

    pg_env = os.environ.copy()
    pg_env["PGPASSWORD"] = password

    cmd = ["psql", "-h", host, "-p", port, "-U", user, "-c", "SELECT 1;", "-t", "-A", "postgres"]
    result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env, timeout=15)
    if result.returncode != 0:
        raise Exception("Connection failed: {}".format(result.stderr.decode('utf-8', errors='replace').strip()))

    # List databases
    cmd2 = ["psql", "-h", host, "-p", port, "-U", user, "-l", "-t", "-A"]
    result2 = subprocess.run(cmd2, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env, timeout=15)
    dbs = []
    for line in result2.stdout.decode("utf-8", errors="replace").strip().split("\n"):
        parts = line.split("|")
        if parts and parts[0].strip():
            dbs.append(parts[0].strip())
    return "Connection successful. Found {} database(s): {}".format(len(dbs), ', '.join(dbs[:10]))


def execute_plugin_shell_hook(config):
    """Run pre-backup shell script hook."""
    pre_script = config.get("pre_script", "").strip()
    post_script = config.get("post_script", "").strip()
    timeout = int(config.get("timeout", 300))
    abort_on_failure = config.get("abort_on_failure", True)

    result = {
        "pre_script": pre_script,
        "post_script": post_script,
        "pre_output": "",
        "pre_exit_code": None,
    }

    if not pre_script:
        logger.info("Shell hook: no pre-script configured, skipping")
        return result

    if not os.path.isfile(pre_script):
        msg = "Pre-script not found: {}".format(pre_script)
        if abort_on_failure:
            raise Exception(msg)
        logger.warning(msg)
        return result

    if not os.access(pre_script, os.X_OK):
        msg = "Pre-script not executable: {}".format(pre_script)
        if abort_on_failure:
            raise Exception(msg)
        logger.warning(msg)
        return result

    logger.info("Shell hook: running pre-script {}".format(pre_script))
    try:
        proc = subprocess.run(
            [pre_script],
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=timeout,
            universal_newlines=True,
        )
        output = proc.stdout[:10240] if proc.stdout else ""
        result["pre_output"] = output
        result["pre_exit_code"] = proc.returncode

        if proc.returncode != 0:
            msg = "Pre-script exited with code {}: {}".format(proc.returncode, output)
            if abort_on_failure:
                raise Exception(msg)
            logger.warning(msg)
        else:
            logger.info("Pre-script completed successfully (exit 0)")
    except subprocess.TimeoutExpired:
        msg = "Pre-script timed out after {}s: {}".format(timeout, pre_script)
        if abort_on_failure:
            raise Exception(msg)
        logger.warning(msg)

    return result


def cleanup_plugin_shell_hook(config, plugin_result):
    """Run post-backup shell script hook."""
    post_script = config.get("post_script", "").strip()
    timeout = int(config.get("timeout", 300))

    if not post_script:
        return None

    if not os.path.isfile(post_script):
        logger.warning("Post-script not found: {}".format(post_script))
        return "{} not found".format(post_script)

    if not os.access(post_script, os.X_OK):
        logger.warning("Post-script not executable: {}".format(post_script))
        return "{} not executable".format(post_script)

    logger.info("Shell hook: running post-script {}".format(post_script))
    try:
        proc = subprocess.run(
            [post_script],
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=timeout,
            universal_newlines=True,
        )
        output = proc.stdout[:10240] if proc.stdout else ""
        if proc.returncode != 0:
            logger.warning("Post-script exited with code {}: {}".format(proc.returncode, output))
            return "{} exited {}: {}".format(post_script, proc.returncode, output[:500])
        else:
            logger.info("Post-script completed successfully (exit 0)")
            return "{} completed (exit 0)".format(post_script) + (": {}".format(output[:500]) if output.strip() else "")
    except subprocess.TimeoutExpired:
        logger.warning("Post-script timed out after {}s: {}".format(timeout, post_script))
        return "{} timed out after {}s".format(post_script, timeout)


def test_plugin_shell_hook(config):
    """Test that configured shell hook scripts exist and are executable."""
    pre_script = config.get("pre_script", "").strip()
    post_script = config.get("post_script", "").strip()
    results = []

    if not pre_script and not post_script:
        raise Exception("No scripts configured. Set at least a pre-script or post-script path.")

    for label, path in [("Pre-script", pre_script), ("Post-script", post_script)]:
        if not path:
            results.append("{}: not configured (skipped)".format(label))
            continue
        if not os.path.isfile(path):
            raise Exception("{} not found: {}".format(label, path))
        if not os.access(path, os.X_OK):
            raise Exception("{} not executable: {} - run: chmod +x {}".format(label, path, path))
        results.append("{}: {} ok".format(label, path))

    return " | ".join(results)


def execute_restore_pg(config, task):
    """Restore PostgreSQL databases from a borg archive."""
    job_id = task.get("job_id")
    command = task.get("command", [])
    if command and command[0] == "borg" and BORG_PATH:
        command[0] = BORG_PATH
    env_vars = task.get("env", {})
    cwd = task.get("cwd")
    databases = task.get("databases", [])
    pg_config = task.get("pg_config", {})

    host = pg_config.get("host", "localhost")
    port = str(pg_config.get("port", 5432))
    user = pg_config.get("user")
    password = pg_config.get("password")
    compress = pg_config.get("compress", True)
    dump_dir = pg_config.get("dump_dir", "/home/bbs/pgdump")

    if not user or not password:
        api_request(config, "/api/agent/status", method="POST", data={
            "job_id": job_id, "result": "failed",
            "error_log": "PostgreSQL restore requires user and password in plugin config",
        })
        return

    # Write temporary SSH key for remote SSH repos
    remote_ssh_key = task.get("remote_ssh_key")
    remote_key_path = REMOTE_KEY_PATH
    if remote_ssh_key:
        try:
            normalized_key = remote_ssh_key.replace("\r\n", "\n").replace("\r", "\n").rstrip() + "\n"
            with open(remote_key_path, "w") as kf:
                kf.write(normalized_key)
            if IS_WINDOWS:
                subprocess.run(
                    ["icacls", remote_key_path, "/inheritance:r", "/grant:r", "SYSTEM:(R)", "Administrators:(R)"],
                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
                )
            else:
                os.chmod(remote_key_path, 0o600)
            logger.info("Wrote temporary SSH key for remote repo")
        except Exception as e:
            logger.error("Failed to write remote SSH key: {}".format(e))
            api_request(config, "/api/agent/status", method="POST", data={
                "job_id": job_id, "result": "failed",
                "error_log": "Failed to write remote SSH key: {}".format(e),
            })
            return

    pg_env = os.environ.copy()
    pg_env["PGPASSWORD"] = password

    # Step 1: Extract dump files from borg archive
    logger.info("Job #{}: Extracting PostgreSQL dumps from archive".format(job_id))
    if cwd:
        os.makedirs(cwd, exist_ok=True)

    env = os.environ.copy()
    env.update(env_vars)

    try:
        proc = subprocess.run(
            command, env=env, cwd=cwd,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            timeout=3600,
        )
        if proc.returncode > 1:
            api_request(config, "/api/agent/status", method="POST", data={
                "job_id": job_id, "result": "failed",
                "error_log": "borg extract failed: {}".format(proc.stderr.decode('utf-8', errors='replace')[:5000]),
            })
            return
    except Exception as e:
        api_request(config, "/api/agent/status", method="POST", data={
            "job_id": job_id, "result": "failed",
            "error_log": "borg extract error: {}".format(e),
        })
        return
    finally:
        # Clean up temporary SSH key
        if remote_ssh_key and os.path.exists(remote_key_path):
            try:
                os.unlink(remote_key_path)
            except Exception:
                pass

    # Step 2: Import each database
    imported = []
    errors = []
    total = len(databases)
    for i, db_entry in enumerate(databases):
        db_name = db_entry.get("database")
        mode = db_entry.get("mode", "replace")
        target_db = db_entry.get("target_name", "{}_copy".format(db_name)) if mode == "rename" else db_name

        # Find the dump file
        if compress:
            dump_file = os.path.join(dump_dir, "{}.sql.gz".format(db_name))
        else:
            dump_file = os.path.join(dump_dir, "{}.sql".format(db_name))

        if not os.path.exists(dump_file):
            errors.append("{}: dump file not found at {}".format(db_name, dump_file))
            continue

        logger.info("Job #{}: Importing {} as {} ({}/{})".format(job_id, db_name, target_db, i+1, total))

        # Report progress
        api_request(config, "/api/agent/progress", method="POST", data={
            "job_id": job_id,
            "files_processed": i,
            "files_total": total,
            "output_log": "Importing {} as {}...".format(db_name, target_db),
        })

        try:
            psql_base = ["psql", "-h", host, "-p", port, "-U", user]

            # Create target database if renaming
            if mode == "rename":
                create_cmd = psql_base + ["-c", 'CREATE DATABASE "{}";'.format(target_db), "postgres"]
                r = subprocess.run(create_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env, timeout=30)
                if r.returncode != 0:
                    stderr = r.stderr.decode('utf-8', errors='replace')
                    if "already exists" not in stderr:
                        errors.append("{}: failed to create {}: {}".format(db_name, target_db, stderr))
                        continue

            # Import the dump
            import_cmd = psql_base + ["-d", target_db]

            if compress:
                if IS_WINDOWS:
                    import gzip as gzip_mod
                    with gzip_mod.open(dump_file, "rb") as gz:
                        psql_proc = subprocess.Popen(import_cmd, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env)
                        while True:
                            chunk = gz.read(65536)
                            if not chunk:
                                break
                            psql_proc.stdin.write(chunk)
                        psql_proc.stdin.close()
                        psql_proc.wait()
                else:
                    gunzip = subprocess.Popen(["gunzip", "-c", dump_file], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    psql_proc = subprocess.Popen(import_cmd, stdin=gunzip.stdout, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env)
                    gunzip.stdout.close()
                    psql_proc.wait()
                    gunzip.wait()
                if psql_proc.returncode != 0:
                    stderr = psql_proc.stderr.read().decode("utf-8", errors="replace")
                    errors.append("{}: import failed: {}".format(db_name, stderr[:500]))
                    continue
            else:
                with open(dump_file, "r") as f:
                    r = subprocess.run(import_cmd, stdin=f, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env, timeout=3600)
                    if r.returncode != 0:
                        errors.append("{}: import failed: {}".format(db_name, r.stderr.decode('utf-8', errors='replace')[:500]))
                        continue

            imported.append("{} -> {}".format(db_name, target_db))

        except Exception as e:
            errors.append("{}: {}".format(db_name, e))

    # Report final status
    if errors and not imported:
        result = "failed"
        error_log = "; ".join(errors)
    else:
        result = "completed"
        error_log = "; ".join(errors) if errors else None

    status_data = {
        "job_id": job_id,
        "result": result,
        "files_total": total,
        "files_processed": len(imported),
    }
    if error_log:
        status_data["error_log"] = error_log[:10000]
    if imported:
        status_data["output_log"] = "Imported: {}".format(', '.join(imported))

    api_request(config, "/api/agent/status", method="POST", data=status_data)


def execute_restore_mysql(config, task):
    """Restore MySQL databases from a borg archive."""
    job_id = task.get("job_id")
    command = task.get("command", [])
    if command and command[0] == "borg" and BORG_PATH:
        command[0] = BORG_PATH
    env_vars = task.get("env", {})
    cwd = task.get("cwd")
    databases = task.get("databases", [])
    mysql_config = task.get("mysql_config", {})

    host = mysql_config.get("host", "localhost")
    port = str(mysql_config.get("port", 3306))
    user = mysql_config.get("user")
    password = mysql_config.get("password")
    compress = mysql_config.get("compress", True)
    per_database = mysql_config.get("per_database", True)
    dump_dir = mysql_config.get("dump_dir", "/home/bbs/mysql")

    if not user or not password:
        api_request(config, "/api/agent/status", method="POST", data={
            "job_id": job_id, "result": "failed",
            "error_log": "MySQL restore requires user and password in plugin config",
        })
        return

    # Write temporary SSH key for remote SSH repos
    remote_ssh_key = task.get("remote_ssh_key")
    remote_key_path = REMOTE_KEY_PATH
    if remote_ssh_key:
        try:
            normalized_key = remote_ssh_key.replace("\r\n", "\n").replace("\r", "\n").rstrip() + "\n"
            with open(remote_key_path, "w") as kf:
                kf.write(normalized_key)
            if IS_WINDOWS:
                subprocess.run(
                    ["icacls", remote_key_path, "/inheritance:r", "/grant:r", "SYSTEM:(R)", "Administrators:(R)"],
                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
                )
            else:
                os.chmod(remote_key_path, 0o600)
            logger.info("Wrote temporary SSH key for remote repo")
        except Exception as e:
            logger.error("Failed to write remote SSH key: {}".format(e))
            api_request(config, "/api/agent/status", method="POST", data={
                "job_id": job_id, "result": "failed",
                "error_log": "Failed to write remote SSH key: {}".format(e),
            })
            return

    # Step 1: Extract dump files from borg archive
    logger.info("Job #{}: Extracting MySQL dumps from archive".format(job_id))
    if cwd:
        os.makedirs(cwd, exist_ok=True)

    env = os.environ.copy()
    env.update(env_vars)

    try:
        proc = subprocess.run(
            command, env=env, cwd=cwd,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            timeout=3600,
        )
        if proc.returncode > 1:
            api_request(config, "/api/agent/status", method="POST", data={
                "job_id": job_id, "result": "failed",
                "error_log": "borg extract failed: {}".format(proc.stderr.decode('utf-8', errors='replace')[:5000]),
            })
            return
    except Exception as e:
        api_request(config, "/api/agent/status", method="POST", data={
            "job_id": job_id, "result": "failed",
            "error_log": "borg extract error: {}".format(e),
        })
        return
    finally:
        # Clean up temporary SSH key
        if remote_ssh_key and os.path.exists(remote_key_path):
            try:
                os.unlink(remote_key_path)
            except Exception:
                pass

    # Step 2: Import each database
    imported = []
    errors = []
    total = len(databases)
    for i, db_entry in enumerate(databases):
        db_name = db_entry.get("database")
        mode = db_entry.get("mode", "replace")  # replace or rename
        target_db = db_entry.get("target_name", "{}_copy".format(db_name)) if mode == "rename" else db_name

        # Find the dump file
        if per_database:
            if compress:
                dump_file = os.path.join(dump_dir, "{}.sql.gz".format(db_name))
            else:
                dump_file = os.path.join(dump_dir, "{}.sql".format(db_name))
        else:
            dump_file = os.path.join(dump_dir, "all_databases.sql.gz" if compress else "all_databases.sql")

        if not os.path.exists(dump_file):
            errors.append("{}: dump file not found at {}".format(db_name, dump_file))
            continue

        logger.info("Job #{}: Importing {} as {} ({}/{})".format(job_id, db_name, target_db, i+1, total))

        # Report progress
        api_request(config, "/api/agent/progress", method="POST", data={
            "job_id": job_id,
            "files_processed": i,
            "files_total": total,
            "output_log": "Importing {} as {}...".format(db_name, target_db),
        })

        try:
            mysql_base = ["mysql", "--host={}".format(host), "--port={}".format(port), "--user={}".format(user), "--password={}".format(password)]

            # Create target database if renaming
            if mode == "rename":
                create_cmd = mysql_base + ["-e", "CREATE DATABASE IF NOT EXISTS `{}`;".format(target_db)]
                r = subprocess.run(create_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30)
                if r.returncode != 0:
                    errors.append("{}: failed to create {}: {}".format(db_name, target_db, r.stderr.decode('utf-8', errors='replace')))
                    continue

            # Import the dump
            import_cmd = mysql_base + [target_db]

            if per_database:
                if compress:
                    # gunzip | mysql
                    if IS_WINDOWS:
                        import gzip as _gzip
                        decomp = subprocess.Popen(["python", "-c",
                            "import gzip,sys,shutil;shutil.copyfileobj(gzip.open(sys.argv[1],'rb'),sys.stdout.buffer)",
                            dump_file], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    else:
                        decomp = subprocess.Popen(["gunzip", "-c", dump_file], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    mysql_proc = subprocess.Popen(import_cmd, stdin=decomp.stdout, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    decomp.stdout.close()
                    mysql_proc.wait()
                    decomp.wait()
                    if mysql_proc.returncode != 0:
                        stderr = mysql_proc.stderr.read().decode("utf-8", errors="replace")
                        errors.append("{}: import failed: {}".format(db_name, stderr[:500]))
                        continue
                else:
                    with open(dump_file, "r") as f:
                        r = subprocess.run(import_cmd, stdin=f, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=3600)
                        if r.returncode != 0:
                            errors.append("{}: import failed: {}".format(db_name, r.stderr.decode('utf-8', errors='replace')[:500]))
                            continue
            else:
                # all_databases dump -- import without specifying target db (uses embedded USE statements)
                import_cmd = mysql_base  # no db name
                if compress:
                    if IS_WINDOWS:
                        import gzip as _gzip
                        decomp = subprocess.Popen(["python", "-c",
                            "import gzip,sys,shutil;shutil.copyfileobj(gzip.open(sys.argv[1],'rb'),sys.stdout.buffer)",
                            dump_file], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    else:
                        decomp = subprocess.Popen(["gunzip", "-c", dump_file], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    mysql_proc = subprocess.Popen(import_cmd, stdin=decomp.stdout, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    decomp.stdout.close()
                    mysql_proc.wait()
                    decomp.wait()
                    if mysql_proc.returncode != 0:
                        stderr = mysql_proc.stderr.read().decode("utf-8", errors="replace")
                        errors.append("all_databases: import failed: {}".format(stderr[:500]))
                else:
                    with open(dump_file, "r") as f:
                        r = subprocess.run(import_cmd, stdin=f, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=3600)
                        if r.returncode != 0:
                            errors.append("all_databases: import failed: {}".format(r.stderr.decode('utf-8', errors='replace')[:500]))
                # Only import once for all_databases mode
                imported.append("all databases (from {})".format(dump_file))
                break

            imported.append("{} -> {}".format(db_name, target_db))

        except Exception as e:
            errors.append("{}: {}".format(db_name, e))

    # Report final status
    if errors and not imported:
        result = "failed"
        error_log = "; ".join(errors)
    else:
        result = "completed"
        error_log = "; ".join(errors) if errors else None

    status_data = {
        "job_id": job_id,
        "result": result,
        "files_total": total,
        "files_processed": len(imported),
    }
    if error_log:
        status_data["error_log"] = error_log[:10000]
    if imported:
        status_data["output_log"] = "Imported: {}".format(', '.join(imported))

    api_request(config, "/api/agent/status", method="POST", data=status_data)


def execute_task(config, task):
    """Execute a borg task and report progress/status."""
    job_id = task.get("job_id")
    task_type = task.get("task")
    command = task.get("command", [])
    env_vars = task.get("env", {})
    archive_name = task.get("archive_name", "")
    directories = task.get("directories", "")
    plugins = task.get("plugins", [])
    cwd = task.get("cwd")  # Working directory for extract (restore) tasks

    # Replace bare "borg" with detected absolute path (server sends "borg" but
    # it may not be in PATH on systems using SCL or non-standard installs)
    if command and command[0] == "borg" and BORG_PATH:
        command[0] = BORG_PATH

    logger.info("Executing {} job #{}: {}".format(task_type, job_id, ' '.join(command)))

    # Handle plugin test
    if task_type == "plugin_test":
        plugin_data = task.get("plugin", {})
        slug = plugin_data.get("slug", "")
        cfg = plugin_data.get("config", {})
        test_func = globals().get("test_plugin_{}".format(slug))
        if test_func:
            try:
                result_msg = test_func(cfg)
                api_request(config, "/api/agent/status", method="POST", data={
                    "job_id": job_id, "result": "completed", "output_log": result_msg,
                })
            except Exception as e:
                api_request(config, "/api/agent/status", method="POST", data={
                    "job_id": job_id, "result": "failed", "error_log": str(e),
                })
        else:
            api_request(config, "/api/agent/status", method="POST", data={
                "job_id": job_id, "result": "failed",
                "error_log": "No test handler for plugin: {}".format(slug),
            })
        return

    # Handle MySQL database restore
    if task_type == "restore_mysql":
        execute_restore_mysql(config, task)
        return

    # Handle PostgreSQL database restore
    if task_type == "restore_pg":
        execute_restore_pg(config, task)
        return

    # Report running immediately so the UI shows activity
    api_request(config, "/api/agent/progress", method="POST", data={
        "job_id": job_id,
        "status_message": "Starting task...",
    })

    # Execute pre-backup plugins
    plugin_results = {}
    if task_type == "backup" and plugins:
        try:
            logger.info("Running {} pre-backup plugin(s)".format(len(plugins)))
            plugin_results = execute_plugins(plugins, config, job_id)
        except Exception as e:
            logger.error("Pre-backup plugin failed: {}".format(e))
            api_request(config, "/api/agent/status", method="POST", data={
                "job_id": job_id,
                "result": "failed",
                "error_log": "Pre-backup plugin failed: {}".format(e),
            })
            return

    # Pre-count files for progress
    files_total = 0
    if task_type == "backup" and directories:
        api_request(config, "/api/agent/progress", method="POST", data={
            "job_id": job_id,
            "status_message": "Counting files...",
        })
        files_total = count_files(directories)
        logger.info("Pre-counted {} files to backup".format(files_total))

    # Report initial progress with file count
    api_request(config, "/api/agent/progress", method="POST", data={
        "job_id": job_id,
        "files_total": files_total,
        "files_processed": 0,
        "status_message": "Backing up {:,} files...".format(files_total) if task_type == "backup" else None,
    })

    # Build environment
    env = os.environ.copy()
    env.update(env_vars)

    # On Windows, translate Unix SSH paths in BORG_RSH to local paths
    # Use forward slashes - Windows SSH accepts them and backslashes get
    # stripped as escape characters when passed through subprocess/shell
    if IS_WINDOWS and "BORG_RSH" in env:
        env["BORG_RSH"] = env["BORG_RSH"].replace(
            "/etc/bbs-agent/ssh_key", SSH_KEY_PATH.replace("\\", "/")
        ).replace(
            "/tmp/bbs-remote-ssh-key", REMOTE_KEY_PATH.replace("\\", "/")
        )

    # Always allow relocated repos - common after S3 restore or copying repositories
    # This prevents "repository was previously located at X" interactive prompts
    env["BORG_RELOCATED_REPO_ACCESS_IS_OK"] = "yes"
    env["BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK"] = "yes"

    # Ensure UTF-8 locale for borg (handles filenames with non-ASCII characters)
    if not IS_WINDOWS and ("LC_ALL" not in env or not env["LC_ALL"].endswith("UTF-8")):
        env["LC_ALL"] = "C.UTF-8"
        env["LANG"] = "C.UTF-8"

    # Clear stale cache locks left by crashed borg processes
    clear_stale_cache_locks()

    # Execute borg command
    files_processed = 0
    original_size = 0
    deduplicated_size = 0
    error_output = ""
    last_progress_time = time.time()
    catalog_count = 0
    catalog_ssh = None  # SSH subprocess for streaming catalog to server
    catalog_pipe_failed = False

    if task_type == "backup":
        ssh_info = load_ssh_info()
        if ssh_info and ssh_info.get("ssh_unix_user") and ssh_info.get("server_host"):
            try:
                catalog_ssh = subprocess.Popen(
                    [
                        "ssh",
                        "-i", SSH_KEY_PATH,
                        "-p", str(ssh_info.get("ssh_port", 22)),
                        "-o", "StrictHostKeyChecking=no",
                        "-o", "BatchMode=yes",
                        "{}@{}".format(ssh_info['ssh_unix_user'], ssh_info['server_host']),
                        "catalog-write {}".format(job_id),
                    ],
                    stdin=subprocess.PIPE,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.PIPE,
                )
                logger.info("Catalog SSH pipe opened for job {}".format(job_id))
            except Exception as e:
                logger.warning("Could not open catalog SSH pipe: {}".format(e))
                catalog_ssh = None
        else:
            logger.info("SSH info not available, catalog streaming disabled")

    # For restore tasks, create and use the target directory
    if cwd:
        os.makedirs(cwd, exist_ok=True)

    # Write temporary SSH key for remote SSH repos (key provided by server in task payload)
    remote_ssh_key = task.get("remote_ssh_key")
    remote_key_path = REMOTE_KEY_PATH
    if remote_ssh_key:
        try:
            # Normalize line endings (Windows \r\n -> Unix \n) and ensure trailing newline
            normalized_key = remote_ssh_key.replace("\r\n", "\n").replace("\r", "\n").rstrip() + "\n"
            with open(remote_key_path, "w") as kf:
                kf.write(normalized_key)
            if IS_WINDOWS:
                subprocess.run(
                    ["icacls", remote_key_path, "/inheritance:r", "/grant:r", "SYSTEM:(R)", "Administrators:(R)"],
                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
                )
            else:
                os.chmod(remote_key_path, 0o600)
            logger.info("Wrote temporary SSH key for remote repo")
        except Exception as e:
            logger.error("Failed to write remote SSH key: {}".format(e))
            api_request(config, "/api/agent/status", method="POST", data={
                "job_id": job_id, "result": "failed",
                "error_log": "Failed to write remote SSH key: {}".format(e),
            })
            return

    try:
        proc = subprocess.Popen(
            command,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=env,
            cwd=cwd,
        )

        # Read stderr for JSON log output (borg writes progress to stderr)
        for raw_line in proc.stderr:
            line = raw_line.decode("utf-8", errors="replace")
            line = line.strip()
            if not line:
                continue

            # Try to parse JSON log entries from borg
            try:
                entry = json.loads(line)
                msg_type = entry.get("type", "")

                if msg_type == "archive_progress":
                    files_processed = entry.get("nfiles", files_processed)
                    original_size = entry.get("original_size", original_size)

                    # Report progress every 5 seconds
                    now = time.time()
                    if now - last_progress_time >= 5:
                        progress_data = {
                            "job_id": job_id,
                            "files_total": files_total,
                            "files_processed": files_processed,
                            "bytes_processed": original_size,
                        }
                        api_request(
                            config,
                            "/api/agent/progress",
                            method="POST",
                            data=progress_data,
                        )
                        last_progress_time = now

                elif msg_type in ("file_status", "file_item") and task_type == "backup" and catalog_ssh:
                    # Stream file entry to server via SSH pipe
                    fpath = entry.get("path", "")
                    fsize = 0
                    fmtime = None
                    if fpath:
                        try:
                            if IS_WINDOWS:
                                stat_path = fpath
                            else:
                                stat_path = "/" + fpath if not fpath.startswith("/") else fpath
                            st = os.stat(stat_path)
                            fsize = st.st_size
                            fmtime = datetime.datetime.fromtimestamp(st.st_mtime).strftime("%Y-%m-%d %H:%M:%S")
                        except (OSError, UnicodeEncodeError):
                            pass
                    line = json.dumps({
                        "path": fpath,
                        "status": entry.get("status", "U")[0].upper(),
                        "size": fsize,
                        "mtime": fmtime,
                    }) + "\n"
                    try:
                        catalog_ssh.stdin.write(line.encode("utf-8"))
                        catalog_ssh.stdin.flush()
                    except (BrokenPipeError, OSError):
                        logger.error("Catalog SSH pipe broken, catalog streaming stopped")
                        catalog_ssh = None
                        catalog_pipe_failed = True
                    catalog_count += 1

                elif msg_type == "log_message":
                    log_level = entry.get("levelname", "INFO")
                    message = entry.get("message", "")
                    if log_level in ("WARNING", "ERROR", "CRITICAL"):
                        error_output += message + "\n"
                        logger.warning("borg: {}".format(message))

            except ValueError:
                # Non-JSON output, might be regular progress text
                if "Error" in line or "error" in line:
                    error_output += line + "\n"
                logger.debug("borg: {}".format(line))

        # Wait for process to complete
        proc.wait(timeout=86400)  # 24h timeout
        stdout_output = proc.stdout.read().decode("utf-8", errors="replace")

        # Parse borg info from stdout if available
        if stdout_output:
            try:
                borg_result = json.loads(stdout_output)
                if "archive" in borg_result:
                    stats = borg_result["archive"].get("stats", {})
                    original_size = stats.get("original_size", original_size)
                    deduplicated_size = stats.get("deduplicated_size", deduplicated_size)
                    files_processed = stats.get("nfiles", files_processed)
            except (ValueError, KeyError):
                pass

        if proc.returncode == 0:
            result = "completed"
            logger.info(
                "Job #{} completed: {} files, "
                "{} bytes original, {} bytes dedup".format(job_id, files_processed, original_size, deduplicated_size)
            )
        elif proc.returncode == 1:
            # borg returns 1 for warnings (still successful)
            result = "completed"
            logger.warning("Job #{} completed with warnings".format(job_id))
        else:
            result = "failed"
            logger.error(
                "Job #{} failed with return code {}".format(job_id, proc.returncode)
            )
            if not error_output:
                error_output = "borg exited with code {}".format(proc.returncode)

    except subprocess.TimeoutExpired:
        proc.kill()
        result = "failed"
        error_output = "Task timed out after 24 hours"
        logger.error("Job #{} timed out".format(job_id))
    except FileNotFoundError:
        result = "failed"
        error_output = "borg command not found"
        logger.error("Job #{}: borg not found".format(job_id))
    except Exception as e:
        result = "failed"
        error_output = str(e)
        logger.error("Job #{} error: {}".format(job_id, e))
    finally:
        # Close the catalog SSH pipe
        if catalog_ssh:
            try:
                catalog_ssh.stdin.close()
                catalog_ssh.wait(timeout=30)
                if catalog_ssh.returncode != 0:
                    logger.error("Catalog SSH pipe exited with code {}".format(catalog_ssh.returncode))
                    catalog_pipe_failed = True
                else:
                    logger.info("Catalog SSH pipe closed, {} entries streamed".format(catalog_count))
            except Exception as e:
                logger.error("Error closing catalog SSH pipe: {}".format(e))
                catalog_pipe_failed = True
                try:
                    catalog_ssh.kill()
                except Exception:
                    pass

    # If catalog pipe failed during an otherwise successful backup, mark as failed
    if result == "completed" and catalog_pipe_failed:
        result = "failed"
        error_output = "Backup completed but catalog streaming failed"
        logger.error("Job #{}: {}".format(job_id, error_output))

    # Build status data
    status_data = {
        "job_id": job_id,
        "files_total": files_total if files_total else files_processed,
        "files_processed": files_processed,
        "original_size": original_size,
        "deduplicated_size": deduplicated_size,
        "bytes_total": original_size,
        "bytes_processed": original_size,
    }

    if archive_name:
        status_data["archive_name"] = archive_name
    if error_output:
        status_data["error_log"] = error_output[:10000]  # Limit size

    # Report backed-up databases from mysql_dump plugin
    if result == "completed" and task_type == "backup" and plugin_results.get("mysql_dump"):
        mysql_result = plugin_results["mysql_dump"]
        status_data["databases_backed_up"] = {
            "databases": mysql_result.get("databases", []),
            "per_database": mysql_result.get("per_database", True),
            "compress": mysql_result.get("compress", True),
        }

    # Report backed-up databases from pg_dump plugin
    if result == "completed" and task_type == "backup" and plugin_results.get("pg_dump"):
        pg_result = plugin_results["pg_dump"]
        status_data["databases_backed_up"] = {
            "databases": pg_result.get("databases", []),
            "per_database": True,
            "compress": pg_result.get("compress", True),
        }

    # Report final status to server. For completed backups with catalog,
    # the server will detect and import the catalog file from disk.
    status_data["result"] = result
    api_request(config, "/api/agent/status", method="POST", data=status_data, timeout=600)

    # Run post-backup plugin cleanup
    if result == "completed" and task_type == "backup" and plugins and plugin_results:
        cleanup_plugins(plugins, plugin_results, config, job_id)

    # Clean up temporary SSH key for remote repos
    if remote_ssh_key and os.path.exists(remote_key_path):
        try:
            os.unlink(remote_key_path)
            logger.info("Cleaned up temporary SSH key")
        except Exception as e:
            logger.warning("Failed to clean up temporary SSH key: {}".format(e))


def clear_stale_cache_locks():
    """Remove stale borg cache locks that can block operations after a crash."""
    if IS_WINDOWS:
        cache_dir = os.path.join(os.environ.get("LOCALAPPDATA", os.path.expanduser("~")), "borg", "Cache")
    else:
        cache_dir = os.path.expanduser("~/.cache/borg")
    if not os.path.isdir(cache_dir):
        return
    import shutil
    for entry in os.listdir(cache_dir):
        lock_path = os.path.join(cache_dir, entry, "lock.exclusive")
        if os.path.exists(lock_path):
            try:
                if os.path.isdir(lock_path):
                    shutil.rmtree(lock_path)
                else:
                    os.remove(lock_path)
                logger.info("Cleared stale cache lock: {}".format(lock_path))
            except Exception as e:
                logger.warning("Could not clear cache lock {}: {}".format(lock_path, e))


def signal_handler(signum, frame):
    global running
    logger.info("Shutdown signal received")
    running = False


def heartbeat_thread(config):
    """Background thread that sends heartbeats while tasks are running.

    This prevents the agent from appearing offline during long-running
    backup operations. The thread only sends heartbeats when task_running
    is True, and exits when running becomes False.
    """
    global running, task_running
    heartbeat_interval = max(config.get("poll_interval", 30), 10)

    while running:
        if task_running:
            try:
                # Send a lightweight heartbeat ping
                api_request(config, "/api/agent/heartbeat", method="POST", data={})
            except Exception as e:
                logger.debug("Heartbeat failed: {}".format(e))

        # Sleep in small increments so we can exit promptly
        for _ in range(heartbeat_interval):
            if not running:
                break
            time.sleep(1)


def main():
    global running, task_running

    setup_logging()
    logger.info("BBS Agent v{} starting".format(AGENT_VERSION))

    signal.signal(signal.SIGINT, signal_handler)
    if IS_WINDOWS:
        try:
            signal.signal(signal.SIGBREAK, signal_handler)
        except (AttributeError, OSError):
            pass
    else:
        signal.signal(signal.SIGTERM, signal_handler)

    config = load_config()

    # Register with server
    if not register(config):
        logger.error("Failed to register, retrying in 30s...")
        time.sleep(30)
        if not register(config):
            logger.error("Registration failed after retry, exiting")
            sys.exit(1)

    logger.info(
        "Polling {} every {}s".format(config['server_url'], config['poll_interval'])
    )

    # Start heartbeat thread for keeping alive during long tasks
    hb_thread = threading.Thread(target=heartbeat_thread, args=(config,), daemon=True)
    hb_thread.start()

    while running:
        try:
            # Poll for tasks
            result = api_request(config, "/api/agent/tasks")

            # Update poll interval if server sends one
            if result and "poll_interval" in result:
                config["poll_interval"] = int(result["poll_interval"])

            if result and result.get("tasks"):
                for task in result["tasks"]:
                    if not running:
                        break

                    task_running = True
                    try:
                        if task.get("task") == "update_borg":
                            execute_update_borg(config, task)
                        elif task.get("task") == "update_agent":
                            execute_update_agent(config, task)
                        else:
                            execute_task(config, task)
                    finally:
                        task_running = False
            elif result is None:
                # Connection error -- server might be down
                logger.warning("Failed to poll server, will retry")

        except Exception as e:
            logger.error("Poll loop error: {}".format(e))

        # Wait for next poll
        for _ in range(config["poll_interval"]):
            if not running:
                break
            time.sleep(1)

    logger.info("Agent stopped")


if __name__ == "__main__":
    main()
