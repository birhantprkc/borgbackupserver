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
from pathlib import Path

AGENT_VERSION = "1.9.2"
CONFIG_PATH = "/etc/bbs-agent/config.ini"
LOG_PATH = "/var/log/bbs-agent.log"
SSH_KEY_PATH = "/etc/bbs-agent/ssh_key"
BORG_SOURCE_PATH = "/etc/bbs-agent/borg_source"

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

    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s [%(levelname)s] %(message)s",
        handlers=[
            logging.FileHandler(LOG_PATH),
            logging.StreamHandler(sys.stdout),
        ],
    )


def load_config():
    if not os.path.exists(CONFIG_PATH):
        logger.error(f"Config file not found: {CONFIG_PATH}")
        sys.exit(1)

    config = ConfigParser()
    config.read(CONFIG_PATH)

    return {
        "server_url": config.get("server", "url").rstrip("/"),
        "api_key": config.get("server", "api_key"),
        "poll_interval": config.getint("agent", "poll_interval", fallback=30),
    }


def api_request(config, endpoint, method="GET", data=None):
    """Make an authenticated request to the BBS server."""
    url = f"{config['server_url']}{endpoint}"
    headers = {
        "Authorization": f"Bearer {config['api_key']}",
        "Content-Type": "application/json",
    }

    body = None
    if data is not None:
        body = json.dumps(data).encode("utf-8")

    req = urllib.request.Request(url, data=body, headers=headers, method=method)

    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        error_body = e.read().decode("utf-8", errors="replace")
        logger.error(f"API error {e.code} on {endpoint}: {error_body}")
        return None
    except urllib.error.URLError as e:
        logger.error(f"Connection error on {endpoint}: {e.reason}")
        return None
    except Exception as e:
        logger.error(f"Request error on {endpoint}: {e}")
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
        logger.warning(f"Failed to save borg source: {e}")


def get_system_info():
    """Gather system information for registration."""
    info = {
        "hostname": socket.getfqdn(),
        "os_info": f"{platform.system()} {platform.release()} {platform.machine()}",
        "agent_version": AGENT_VERSION,
    }

    # Try to get more detailed OS info from /etc/os-release
    try:
        with open("/etc/os-release") as f:
            os_release = {}
            for line in f:
                if "=" in line:
                    key, val = line.strip().split("=", 1)
                    os_release[key] = val.strip('"')
            if "PRETTY_NAME" in os_release:
                info["os_info"] = f"{os_release['PRETTY_NAME']} {platform.machine()}"
    except FileNotFoundError:
        pass

    # Platform and architecture info (for borg binary matching)
    info["platform"] = platform.system().lower()  # linux, darwin, freebsd
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
            # Convert "2.31" → "glibc231"
            info["glibc_version"] = "glibc" + glibc_ver.replace(".", "")
        except Exception:
            info["glibc_version"] = None

    # Get borg version and detect installation method
    borg_path = None
    for candidate in ["/usr/local/bin/borg", "/usr/bin/borg", "/opt/homebrew/bin/borg"]:
        if os.path.exists(candidate):
            borg_path = candidate
            break
    if not borg_path:
        try:
            which_result = subprocess.run(
                ["which", "borg"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=5
            )
            if which_result.returncode == 0:
                borg_path = which_result.stdout.decode("utf-8", errors="replace").strip()
        except Exception:
            pass

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
    logger.info(f"Registering with server: {config['server_url']}")

    result = api_request(config, "/api/agent/register", method="POST", data=info)

    if result and result.get("status") == "ok":
        logger.info(
            f"Registered as agent #{result['agent_id']} ({result.get('name', '')})"
        )
        # Update poll interval from server
        if "poll_interval" in result:
            config["poll_interval"] = result["poll_interval"]

        # Download SSH key for borg SSH access
        download_ssh_key(config)

        return True
    else:
        logger.error("Registration failed")
        return False


def download_ssh_key(config):
    """Download SSH private key from the server if not already present."""
    if os.path.exists(SSH_KEY_PATH):
        logger.info(f"SSH key already present at {SSH_KEY_PATH}")
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
        os.chmod(SSH_KEY_PATH, 0o600)
        logger.info(f"SSH key saved to {SSH_KEY_PATH}")

        # Log SSH user info (port comes from server in BORG_RSH)
        ssh_user = result.get("ssh_unix_user", "")
        server_host = result.get("server_host", "")
        if ssh_user:
            logger.info(f"SSH configured: {ssh_user}@{server_host}")

        return True
    except Exception as e:
        logger.error(f"Failed to save SSH key: {e}")
        return False


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

    logger.info(f"Executing borg update job #{job_id} to v{target_version} via {install_method} (source={source})")

    # Handle skip - agent is incompatible with selected server version
    if install_method == "skip":
        logger.info(f"Skipping borg update - no compatible binary for this agent")
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
        if install_method == "binary" and download_url:
            result, update_output, error_output = _install_borg_binary(
                download_url, binary_path, target_version
            )
            # If binary install failed, try package manager fallback
            if result == "failed" and fallback_to_pip:
                logger.warning(f"Binary install failed ({error_output}), falling back to package manager")
                result, update_output, error_output = _install_borg_package_manager()
                # If package manager also failed, try pip as last resort
                if result == "failed":
                    logger.warning(f"Package manager failed ({error_output}), falling back to pip")
                    result, update_output, error_output = _install_borg_pip(target_version)

        elif install_method == "pip":
            # Server explicitly requested pip (no binary available)
            # First try package manager (more reliable), then pip
            result, update_output, error_output = _install_borg_package_manager()
            if result == "failed":
                logger.warning(f"Package manager failed ({error_output}), falling back to pip")
                result, update_output, error_output = _install_borg_pip(target_version)

        else:
            error_output = "No download URL or install method provided"

    except Exception as e:
        error_output = str(e)
        logger.error(f"Borg update error: {e}")

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
        logger.info(f"Updated borg version: {info.get('borg_version', 'unknown')} (source={source})")


def _install_borg_binary(download_url, binary_path, target_version):
    """Download a pre-compiled borg binary from GitHub and install it."""
    logger.info(f"Downloading borg binary from {download_url}")

    req = urllib.request.Request(
        download_url,
        headers={"User-Agent": f"bbs-agent/{AGENT_VERSION}"}
    )

    try:
        with urllib.request.urlopen(req, timeout=300) as resp:
            binary_data = resp.read()
    except urllib.error.HTTPError as e:
        return "failed", "", f"Download failed: HTTP {e.code}"
    except Exception as e:
        return "failed", "", f"Download failed: {e}"

    # Basic size check (borg binaries are typically 10MB+)
    if len(binary_data) < 1 * 1024 * 1024:
        return "failed", "", f"Downloaded file too small ({len(binary_data)} bytes), likely not a valid binary"

    # Write to temp file
    tmp_path = binary_path + ".tmp"
    try:
        os.makedirs(os.path.dirname(binary_path), exist_ok=True)
        with open(tmp_path, "wb") as f:
            f.write(binary_data)
        os.chmod(tmp_path, 0o755)
    except Exception as e:
        return "failed", "", f"Failed to write binary: {e}"

    # Test the binary
    try:
        test_proc = subprocess.run(
            [tmp_path, "--version"],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10
        )
        if test_proc.returncode != 0:
            os.remove(tmp_path)
            stderr = test_proc.stderr.decode("utf-8", errors="replace")
            return "failed", "", f"Downloaded binary failed version check: {stderr}"

        actual_version = test_proc.stdout.decode("utf-8", errors="replace").strip().replace("borg ", "")
        logger.info(f"Binary version check passed: {actual_version}")
    except Exception as e:
        if os.path.exists(tmp_path):
            os.remove(tmp_path)
        return "failed", "", f"Binary test failed: {e}"

    # Backup old binary and install new one
    try:
        backup_path = binary_path + ".bak"
        if os.path.exists(binary_path):
            os.rename(binary_path, backup_path)
        os.rename(tmp_path, binary_path)
        # Clean up backup
        if os.path.exists(backup_path):
            os.remove(backup_path)
    except Exception as e:
        # Try to restore backup
        if os.path.exists(backup_path) and not os.path.exists(binary_path):
            os.rename(backup_path, binary_path)
        return "failed", "", f"Failed to install binary: {e}"

    # Remove package manager borg to avoid having two versions
    # /usr/local/bin takes precedence in PATH, but it's cleaner to have just one
    pkg_borg = "/usr/bin/borg"
    if os.path.exists(pkg_borg) and binary_path != pkg_borg:
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
            logger.info(f"Removed package manager borg to avoid duplicate installations")
        except Exception as e:
            logger.warning(f"Could not remove package manager borg: {e}")

    output = f"Borg updated to v{target_version} via binary install at {binary_path}"
    logger.info(output)
    return "completed", output, ""


def _install_borg_pip(target_version):
    """Install borg via pip. Removes any existing non-pip binary first."""
    # Check if there's an existing binary at /usr/local/bin/borg that's not from pip
    # (pip-installed borg is a script, not a large ELF binary)
    existing_binary = "/usr/local/bin/borg"
    if os.path.exists(existing_binary):
        try:
            # Check if it's a large binary (ELF binaries are typically 10MB+)
            size = os.path.getsize(existing_binary)
            if size > 5 * 1024 * 1024:  # > 5MB = likely a compiled binary, not pip
                logger.info(f"Removing existing binary at {existing_binary} ({size} bytes) to allow pip install")
                backup_path = existing_binary + ".bak"
                os.rename(existing_binary, backup_path)
                # Keep backup in case pip fails - we'll clean up on success
        except Exception as e:
            logger.warning(f"Could not check/remove existing binary: {e}")

    version_spec = f"borgbackup=={target_version}" if target_version and target_version != "latest" else "borgbackup"
    cmd = ["pip3", "install", "--upgrade", version_spec]
    logger.info(f"Installing borg via pip: {' '.join(cmd)}")

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
                    output = f"Borg updated to v{installed_ver} via pip"
                else:
                    output = f"Borg updated via pip"
            except Exception:
                output = f"Borg updated via pip"
            logger.info(output)
            return "completed", output, ""
        else:
            # Restore backup if pip failed
            backup_path = existing_binary + ".bak"
            if os.path.exists(backup_path) and not os.path.exists(existing_binary):
                try:
                    os.rename(backup_path, existing_binary)
                    logger.info(f"Restored backup binary after pip failure")
                except Exception:
                    pass
            error = stderr_text or stdout_text or f"Exit code {proc.returncode}"
            logger.error(f"pip install failed: {error}")
            return "failed", "", error
    except subprocess.TimeoutExpired:
        return "failed", "", "pip install timed out"
    except Exception as e:
        return "failed", "", str(e)


def _install_borg_package_manager():
    """Install/update borg via OS package manager. Removes any existing /usr/local/bin/borg first."""
    # Remove any existing binary at /usr/local/bin/borg so package manager version is used
    existing_binary = "/usr/local/bin/borg"
    if os.path.exists(existing_binary):
        try:
            size = os.path.getsize(existing_binary)
            logger.info(f"Removing existing binary at {existing_binary} ({size} bytes) to use package manager")
            backup_path = existing_binary + ".bak"
            os.rename(existing_binary, backup_path)
        except Exception as e:
            logger.warning(f"Could not remove existing binary: {e}")

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
                    output = f"Borg updated to v{installed_ver} via package manager"
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
            error = stderr_text or stdout_text or f"Exit code {proc.returncode}"
            return "failed", "", error
    except subprocess.TimeoutExpired:
        return "failed", "", "Update command timed out"
    except Exception as e:
        return "failed", "", str(e)


def execute_update_agent(config, task):
    """Download and replace the agent script from the server, then restart."""
    job_id = task.get("job_id")
    logger.info(f"Executing agent update job #{job_id}")

    # Report running
    api_request(config, "/api/agent/progress", method="POST", data={
        "job_id": job_id, "files_total": 0, "files_processed": 0,
    })

    error_output = ""
    result = "failed"
    update_output = ""

    try:
        # Download new agent script from server
        url = f"{config['server_url']}/api/agent/download?file=bbs-agent.py"
        headers = {
            "Authorization": f"Bearer {config['api_key']}",
        }
        req = urllib.request.Request(url, headers=headers, method="GET")

        with urllib.request.urlopen(req, timeout=60) as resp:
            new_script = resp.read().decode("utf-8")

        # Validate the downloaded script
        if "AGENT_VERSION" not in new_script or len(new_script) < 1000:
            error_output = "Downloaded script failed validation"
            logger.error(error_output)
        else:
            # Determine current script path
            script_path = os.path.abspath(__file__)
            logger.info(f"Replacing agent at: {script_path}")

            # Write new script to temp file first, then move
            tmp_path = script_path + ".tmp"
            with open(tmp_path, "w") as f:
                f.write(new_script)
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
            update_output = f"Agent updated to v{new_version}"
            logger.info(update_output)

    except urllib.error.HTTPError as e:
        error_output = f"Download failed: HTTP {e.code}"
        logger.error(error_output)
    except Exception as e:
        error_output = str(e)
        logger.error(f"Agent update error: {e}")

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


def execute_plugins(plugins, config=None, job_id=None):
    """Execute pre-backup plugins. Returns dict of results keyed by slug."""
    results = {}
    for plugin in plugins:
        slug = plugin.get("slug", "")
        cfg = plugin.get("config", {})
        logger.info(f"Running pre-backup plugin: {slug}")
        func_name = f"execute_plugin_{slug}"
        func = globals().get(func_name)
        if not func:
            logger.warning(f"Plugin {slug} not implemented, skipping")
            continue
        result = func(cfg)
        results[slug] = result
        logger.info(f"Plugin {slug} completed")

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
        return f"MySQL dump: {len(dump_files)} database(s) ({', '.join(db_names)}) dumped to {dump_dir} ({size_str})"
    if slug == "pg_dump":
        dump_files = result.get("dump_files", [])
        dump_dir = result.get("dump_dir", "")
        db_names = [os.path.basename(f).split(".")[0] for f in dump_files]
        total_size = sum(os.path.getsize(f) for f in dump_files if os.path.exists(f))
        size_str = _format_size(total_size)
        return f"PostgreSQL dump: {len(dump_files)} database(s) ({', '.join(db_names)}) dumped to {dump_dir} ({size_str})"
    if slug == "shell_hook":
        parts = []
        pre = result.get("pre_script", "")
        if pre:
            code = result.get("pre_exit_code", "?")
            parts.append(f"pre-script: {pre} (exit {code})")
        post = result.get("post_script", "")
        if post:
            parts.append(f"post-script: {post} (pending)")
        output = result.get("pre_output", "").strip()
        if output:
            # Truncate for summary
            if len(output) > 200:
                output = output[:200] + "..."
            parts.append(f"output: {output}")
        return f"Shell hook: {' | '.join(parts)}" if parts else None
    return None


def _format_size(bytes_val):
    """Format bytes into human-readable size."""
    for unit in ['B', 'KB', 'MB', 'GB', 'TB']:
        if bytes_val < 1024:
            return f"{bytes_val:.1f} {unit}" if unit != 'B' else f"{bytes_val} {unit}"
        bytes_val /= 1024
    return f"{bytes_val:.1f} PB"


def cleanup_plugins(plugins, plugin_results, config=None, job_id=None):
    """Run post-backup cleanup for plugins."""
    for plugin in plugins:
        slug = plugin.get("slug", "")
        cfg = plugin.get("config", {})
        func = globals().get(f"cleanup_plugin_{slug}")
        if func:
            try:
                cleanup_result = func(cfg, plugin_results.get(slug, {}))
                if config and job_id:
                    if cfg.get("cleanup_after", True):
                        dump_dir = plugin_results.get(slug, {}).get("dump_dir", "")
                        if dump_dir:
                            log_to_server(config, job_id, f"Plugin cleanup: removed dump files from {dump_dir}")
                    if slug == "shell_hook" and cleanup_result:
                        log_to_server(config, job_id, f"Shell hook post-script: {cleanup_result}")
            except Exception as e:
                logger.warning(f"Plugin cleanup for {slug} failed: {e}")
                if config and job_id:
                    log_to_server(config, job_id, f"Plugin cleanup for {slug} failed: {e}", "warning")


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
            f"--host={host}",
            f"--port={port}",
            f"--user={user}",
            f"--password={password}",
            "-e", "SHOW DATABASES;",
            "-s", "--skip-column-names",
        ]
        result = subprocess.run(list_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if result.returncode != 0:
            raise Exception(f"Failed to list databases: {result.stderr.decode('utf-8', errors='replace').strip()}")
        if isinstance(exclude, str):
            exclude = [x.strip() for x in exclude.split(",")]
        databases = [
            db.strip() for db in result.stdout.decode("utf-8", errors="replace").strip().split("\n")
            if db.strip() and db.strip() not in exclude
        ]
    elif isinstance(databases, str):
        databases = [d.strip() for d in databases.split(",") if d.strip()]

    base_cmd = ["mysqldump", f"--host={host}", f"--port={port}", f"--user={user}", f"--password={password}"]
    if extra_options:
        base_cmd.extend(extra_options.split())

    dump_files = []

    if per_database:
        for db in databases:
            filename = f"{db}.sql.gz" if compress else f"{db}.sql"
            dump_path = os.path.join(dump_dir, filename)
            logger.info(f"Dumping database {db} to {dump_path}")

            cmd = base_cmd + [db]
            if compress:
                dump_proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                with open(dump_path, "wb") as f:
                    gzip_proc = subprocess.Popen(["gzip"], stdin=dump_proc.stdout, stdout=f, stderr=subprocess.PIPE)
                dump_proc.stdout.close()
                gzip_proc.wait()
                dump_proc.wait()
                if dump_proc.returncode != 0:
                    stderr = dump_proc.stderr.read().decode() if dump_proc.stderr else ""
                    raise Exception(f"mysqldump failed for {db}: {stderr}")
            else:
                with open(dump_path, "w") as f:
                    r = subprocess.run(cmd, stdout=f, stderr=subprocess.PIPE)
                    if r.returncode != 0:
                        raise Exception(f"mysqldump failed for {db}: {r.stderr.decode('utf-8', errors='replace')}")

            dump_files.append(dump_path)
    else:
        filename = "all_databases.sql.gz" if compress else "all_databases.sql"
        dump_path = os.path.join(dump_dir, filename)
        logger.info(f"Dumping all databases to {dump_path}")

        cmd = base_cmd + ["--all-databases"]
        if compress:
            dump_proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            with open(dump_path, "wb") as f:
                gzip_proc = subprocess.Popen(["gzip"], stdin=dump_proc.stdout, stdout=f, stderr=subprocess.PIPE)
            dump_proc.stdout.close()
            gzip_proc.wait()
            dump_proc.wait()
            if dump_proc.returncode != 0:
                stderr = dump_proc.stderr.read().decode() if dump_proc.stderr else ""
                raise Exception(f"mysqldump failed: {stderr}")
        else:
            with open(dump_path, "w") as f:
                r = subprocess.run(cmd, stdout=f, stderr=subprocess.PIPE)
                if r.returncode != 0:
                    raise Exception(f"mysqldump failed: {r.stderr.decode('utf-8', errors='replace')}")

        dump_files.append(dump_path)

    logger.info(f"MySQL dump complete: {len(dump_files)} file(s) in {dump_dir}")
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
    logger.info(f"Cleaning up MySQL dumps in {dump_dir}")
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

    cmd = ["mysql", f"--host={host}", f"--port={port}", f"--user={user}",
           f"--password={password}", "-e", "SELECT 1;", "-s", "--skip-column-names"]
    result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
    if result.returncode != 0:
        raise Exception(f"Connection failed: {result.stderr.decode('utf-8', errors='replace').strip()}")

    # Test SHOW DATABASES for permissions
    cmd2 = ["mysql", f"--host={host}", f"--port={port}", f"--user={user}",
            f"--password={password}", "-e", "SHOW DATABASES;", "-s", "--skip-column-names"]
    result2 = subprocess.run(cmd2, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
    dbs = result2.stdout.decode("utf-8", errors="replace").strip().split("\n")
    dbs = [d for d in dbs if d]
    return f"Connection successful. Found {len(dbs)} database(s): {', '.join(dbs[:10])}"


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
        list_cmd = ["psql", f"-h", host, "-p", port, "-U", user, "-l", "-t", "-A"]
        result = subprocess.run(list_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env)
        if result.returncode != 0:
            raise Exception(f"Failed to list databases: {result.stderr.decode('utf-8', errors='replace').strip()}")
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
        filename = f"{db}.sql.gz" if compress else f"{db}.sql"
        dump_path = os.path.join(dump_dir, filename)
        logger.info(f"Dumping PostgreSQL database {db} to {dump_path}")

        cmd = ["pg_dump", "-h", host, "-p", port, "-U", user]
        if extra_options:
            cmd.extend(extra_options.split())
        cmd.append(db)

        if compress:
            dump_proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env)
            with open(dump_path, "wb") as f:
                gzip_proc = subprocess.Popen(["gzip"], stdin=dump_proc.stdout, stdout=f, stderr=subprocess.PIPE)
            dump_proc.stdout.close()
            gzip_proc.wait()
            dump_proc.wait()
            if dump_proc.returncode != 0:
                stderr = dump_proc.stderr.read().decode() if dump_proc.stderr else ""
                raise Exception(f"pg_dump failed for {db}: {stderr}")
        else:
            with open(dump_path, "w") as f:
                r = subprocess.run(cmd, stdout=f, stderr=subprocess.PIPE, env=pg_env)
                if r.returncode != 0:
                    raise Exception(f"pg_dump failed for {db}: {r.stderr.decode('utf-8', errors='replace')}")

        dump_files.append(dump_path)

    logger.info(f"PostgreSQL dump complete: {len(dump_files)} file(s) in {dump_dir}")
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
    logger.info(f"Cleaning up PostgreSQL dumps in {dump_dir}")
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
        raise Exception(f"Connection failed: {result.stderr.decode('utf-8', errors='replace').strip()}")

    # List databases
    cmd2 = ["psql", "-h", host, "-p", port, "-U", user, "-l", "-t", "-A"]
    result2 = subprocess.run(cmd2, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env, timeout=15)
    dbs = []
    for line in result2.stdout.decode("utf-8", errors="replace").strip().split("\n"):
        parts = line.split("|")
        if parts and parts[0].strip():
            dbs.append(parts[0].strip())
    return f"Connection successful. Found {len(dbs)} database(s): {', '.join(dbs[:10])}"


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
        msg = f"Pre-script not found: {pre_script}"
        if abort_on_failure:
            raise Exception(msg)
        logger.warning(msg)
        return result

    if not os.access(pre_script, os.X_OK):
        msg = f"Pre-script not executable: {pre_script}"
        if abort_on_failure:
            raise Exception(msg)
        logger.warning(msg)
        return result

    logger.info(f"Shell hook: running pre-script {pre_script}")
    try:
        proc = subprocess.run(
            [pre_script],
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=timeout,
            text=True,
        )
        output = proc.stdout[:10240] if proc.stdout else ""
        result["pre_output"] = output
        result["pre_exit_code"] = proc.returncode

        if proc.returncode != 0:
            msg = f"Pre-script exited with code {proc.returncode}: {output}"
            if abort_on_failure:
                raise Exception(msg)
            logger.warning(msg)
        else:
            logger.info(f"Pre-script completed successfully (exit 0)")
    except subprocess.TimeoutExpired:
        msg = f"Pre-script timed out after {timeout}s: {pre_script}"
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
        logger.warning(f"Post-script not found: {post_script}")
        return f"{post_script} not found"

    if not os.access(post_script, os.X_OK):
        logger.warning(f"Post-script not executable: {post_script}")
        return f"{post_script} not executable"

    logger.info(f"Shell hook: running post-script {post_script}")
    try:
        proc = subprocess.run(
            [post_script],
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=timeout,
            text=True,
        )
        output = proc.stdout[:10240] if proc.stdout else ""
        if proc.returncode != 0:
            logger.warning(f"Post-script exited with code {proc.returncode}: {output}")
            return f"{post_script} exited {proc.returncode}: {output[:500]}"
        else:
            logger.info(f"Post-script completed successfully (exit 0)")
            return f"{post_script} completed (exit 0)" + (f": {output[:500]}" if output.strip() else "")
    except subprocess.TimeoutExpired:
        logger.warning(f"Post-script timed out after {timeout}s: {post_script}")
        return f"{post_script} timed out after {timeout}s"


def test_plugin_shell_hook(config):
    """Test that configured shell hook scripts exist and are executable."""
    pre_script = config.get("pre_script", "").strip()
    post_script = config.get("post_script", "").strip()
    results = []

    if not pre_script and not post_script:
        raise Exception("No scripts configured. Set at least a pre-script or post-script path.")

    for label, path in [("Pre-script", pre_script), ("Post-script", post_script)]:
        if not path:
            results.append(f"{label}: not configured (skipped)")
            continue
        if not os.path.isfile(path):
            raise Exception(f"{label} not found: {path}")
        if not os.access(path, os.X_OK):
            raise Exception(f"{label} not executable: {path} — run: chmod +x {path}")
        results.append(f"{label}: {path} ✓")

    return " | ".join(results)


def execute_restore_pg(config, task):
    """Restore PostgreSQL databases from a borg archive."""
    job_id = task.get("job_id")
    command = task.get("command", [])
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
    remote_key_path = "/tmp/bbs-remote-ssh-key"
    if remote_ssh_key:
        try:
            normalized_key = remote_ssh_key.replace("\r\n", "\n").replace("\r", "\n").rstrip() + "\n"
            with open(remote_key_path, "w") as kf:
                kf.write(normalized_key)
            os.chmod(remote_key_path, 0o600)
            logger.info("Wrote temporary SSH key for remote repo")
        except Exception as e:
            logger.error(f"Failed to write remote SSH key: {e}")
            api_request(config, "/api/agent/status", method="POST", data={
                "job_id": job_id, "result": "failed",
                "error_log": f"Failed to write remote SSH key: {e}",
            })
            return

    pg_env = os.environ.copy()
    pg_env["PGPASSWORD"] = password

    # Step 1: Extract dump files from borg archive
    logger.info(f"Job #{job_id}: Extracting PostgreSQL dumps from archive")
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
                "error_log": f"borg extract failed: {proc.stderr.decode('utf-8', errors='replace')[:5000]}",
            })
            return
    except Exception as e:
        api_request(config, "/api/agent/status", method="POST", data={
            "job_id": job_id, "result": "failed",
            "error_log": f"borg extract error: {e}",
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
        target_db = db_entry.get("target_name", f"{db_name}_copy") if mode == "rename" else db_name

        # Find the dump file
        if compress:
            dump_file = os.path.join(dump_dir, f"{db_name}.sql.gz")
        else:
            dump_file = os.path.join(dump_dir, f"{db_name}.sql")

        if not os.path.exists(dump_file):
            errors.append(f"{db_name}: dump file not found at {dump_file}")
            continue

        logger.info(f"Job #{job_id}: Importing {db_name} as {target_db} ({i+1}/{total})")

        # Report progress
        api_request(config, "/api/agent/progress", method="POST", data={
            "job_id": job_id,
            "files_processed": i,
            "files_total": total,
            "output_log": f"Importing {db_name} as {target_db}...",
        })

        try:
            psql_base = ["psql", "-h", host, "-p", port, "-U", user]

            # Create target database if renaming
            if mode == "rename":
                create_cmd = psql_base + ["-c", f'CREATE DATABASE "{target_db}";', "postgres"]
                r = subprocess.run(create_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env, timeout=30)
                if r.returncode != 0:
                    stderr = r.stderr.decode('utf-8', errors='replace')
                    if "already exists" not in stderr:
                        errors.append(f"{db_name}: failed to create {target_db}: {stderr}")
                        continue

            # Import the dump
            import_cmd = psql_base + ["-d", target_db]

            if compress:
                gunzip = subprocess.Popen(["gunzip", "-c", dump_file], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                psql_proc = subprocess.Popen(import_cmd, stdin=gunzip.stdout, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env)
                gunzip.stdout.close()
                psql_proc.wait()
                gunzip.wait()
                if psql_proc.returncode != 0:
                    stderr = psql_proc.stderr.read().decode("utf-8", errors="replace")
                    errors.append(f"{db_name}: import failed: {stderr[:500]}")
                    continue
            else:
                with open(dump_file, "r") as f:
                    r = subprocess.run(import_cmd, stdin=f, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env, timeout=3600)
                    if r.returncode != 0:
                        errors.append(f"{db_name}: import failed: {r.stderr.decode('utf-8', errors='replace')[:500]}")
                        continue

            imported.append(f"{db_name} → {target_db}")

        except Exception as e:
            errors.append(f"{db_name}: {e}")

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
        status_data["output_log"] = f"Imported: {', '.join(imported)}"

    api_request(config, "/api/agent/status", method="POST", data=status_data)


def execute_restore_mysql(config, task):
    """Restore MySQL databases from a borg archive."""
    job_id = task.get("job_id")
    command = task.get("command", [])
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
    remote_key_path = "/tmp/bbs-remote-ssh-key"
    if remote_ssh_key:
        try:
            normalized_key = remote_ssh_key.replace("\r\n", "\n").replace("\r", "\n").rstrip() + "\n"
            with open(remote_key_path, "w") as kf:
                kf.write(normalized_key)
            os.chmod(remote_key_path, 0o600)
            logger.info("Wrote temporary SSH key for remote repo")
        except Exception as e:
            logger.error(f"Failed to write remote SSH key: {e}")
            api_request(config, "/api/agent/status", method="POST", data={
                "job_id": job_id, "result": "failed",
                "error_log": f"Failed to write remote SSH key: {e}",
            })
            return

    # Step 1: Extract dump files from borg archive
    logger.info(f"Job #{job_id}: Extracting MySQL dumps from archive")
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
                "error_log": f"borg extract failed: {proc.stderr.decode('utf-8', errors='replace')[:5000]}",
            })
            return
    except Exception as e:
        api_request(config, "/api/agent/status", method="POST", data={
            "job_id": job_id, "result": "failed",
            "error_log": f"borg extract error: {e}",
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
        target_db = db_entry.get("target_name", f"{db_name}_copy") if mode == "rename" else db_name

        # Find the dump file
        if per_database:
            if compress:
                dump_file = os.path.join(dump_dir, f"{db_name}.sql.gz")
            else:
                dump_file = os.path.join(dump_dir, f"{db_name}.sql")
        else:
            dump_file = os.path.join(dump_dir, "all_databases.sql.gz" if compress else "all_databases.sql")

        if not os.path.exists(dump_file):
            errors.append(f"{db_name}: dump file not found at {dump_file}")
            continue

        logger.info(f"Job #{job_id}: Importing {db_name} as {target_db} ({i+1}/{total})")

        # Report progress
        api_request(config, "/api/agent/progress", method="POST", data={
            "job_id": job_id,
            "files_processed": i,
            "files_total": total,
            "output_log": f"Importing {db_name} as {target_db}...",
        })

        try:
            mysql_base = ["mysql", f"--host={host}", f"--port={port}", f"--user={user}", f"--password={password}"]

            # Create target database if renaming
            if mode == "rename":
                create_cmd = mysql_base + ["-e", f"CREATE DATABASE IF NOT EXISTS `{target_db}`;"]
                r = subprocess.run(create_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30)
                if r.returncode != 0:
                    errors.append(f"{db_name}: failed to create {target_db}: {r.stderr.decode('utf-8', errors='replace')}")
                    continue

            # Import the dump
            import_cmd = mysql_base + [target_db]

            if per_database:
                if compress:
                    # gunzip | mysql
                    gunzip = subprocess.Popen(["gunzip", "-c", dump_file], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    mysql_proc = subprocess.Popen(import_cmd, stdin=gunzip.stdout, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    gunzip.stdout.close()
                    mysql_proc.wait()
                    gunzip.wait()
                    if mysql_proc.returncode != 0:
                        stderr = mysql_proc.stderr.read().decode("utf-8", errors="replace")
                        errors.append(f"{db_name}: import failed: {stderr[:500]}")
                        continue
                else:
                    with open(dump_file, "r") as f:
                        r = subprocess.run(import_cmd, stdin=f, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=3600)
                        if r.returncode != 0:
                            errors.append(f"{db_name}: import failed: {r.stderr.decode('utf-8', errors='replace')[:500]}")
                            continue
            else:
                # all_databases dump — import without specifying target db (uses embedded USE statements)
                import_cmd = mysql_base  # no db name
                if compress:
                    gunzip = subprocess.Popen(["gunzip", "-c", dump_file], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    mysql_proc = subprocess.Popen(import_cmd, stdin=gunzip.stdout, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    gunzip.stdout.close()
                    mysql_proc.wait()
                    gunzip.wait()
                    if mysql_proc.returncode != 0:
                        stderr = mysql_proc.stderr.read().decode("utf-8", errors="replace")
                        errors.append(f"all_databases: import failed: {stderr[:500]}")
                else:
                    with open(dump_file, "r") as f:
                        r = subprocess.run(import_cmd, stdin=f, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=3600)
                        if r.returncode != 0:
                            errors.append(f"all_databases: import failed: {r.stderr.decode('utf-8', errors='replace')[:500]}")
                # Only import once for all_databases mode
                imported.append(f"all databases (from {dump_file})")
                break

            imported.append(f"{db_name} → {target_db}")

        except Exception as e:
            errors.append(f"{db_name}: {e}")

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
        status_data["output_log"] = f"Imported: {', '.join(imported)}"

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

    logger.info(f"Executing {task_type} job #{job_id}: {' '.join(command)}")

    # Handle plugin test
    if task_type == "plugin_test":
        plugin_data = task.get("plugin", {})
        slug = plugin_data.get("slug", "")
        cfg = plugin_data.get("config", {})
        test_func = globals().get(f"test_plugin_{slug}")
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
                "error_log": f"No test handler for plugin: {slug}",
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

    # Execute pre-backup plugins
    plugin_results = {}
    if task_type == "backup" and plugins:
        try:
            logger.info(f"Running {len(plugins)} pre-backup plugin(s)")
            plugin_results = execute_plugins(plugins, config, job_id)
        except Exception as e:
            logger.error(f"Pre-backup plugin failed: {e}")
            api_request(config, "/api/agent/status", method="POST", data={
                "job_id": job_id,
                "result": "failed",
                "error_log": f"Pre-backup plugin failed: {e}",
            })
            return

    # Pre-count files for progress
    files_total = 0
    if task_type == "backup" and directories:
        files_total = count_files(directories)
        logger.info(f"Pre-counted {files_total} files to backup")

    # Report initial progress
    api_request(
        config,
        "/api/agent/progress",
        method="POST",
        data={
            "job_id": job_id,
            "files_total": files_total,
            "files_processed": 0,
        },
    )

    # Build environment
    env = os.environ.copy()
    env.update(env_vars)

    # Always allow relocated repos - common after S3 restore or copying repositories
    # This prevents "repository was previously located at X" interactive prompts
    env["BORG_RELOCATED_REPO_ACCESS_IS_OK"] = "yes"
    env["BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK"] = "yes"

    # Execute borg command
    files_processed = 0
    original_size = 0
    deduplicated_size = 0
    error_output = ""
    last_progress_time = time.time()
    catalog_entries = []  # Collect file entries for catalog

    # For restore tasks, create and use the target directory
    if cwd:
        os.makedirs(cwd, exist_ok=True)

    # Write temporary SSH key for remote SSH repos (key provided by server in task payload)
    remote_ssh_key = task.get("remote_ssh_key")
    remote_key_path = "/tmp/bbs-remote-ssh-key"
    if remote_ssh_key:
        try:
            # Normalize line endings (Windows \r\n -> Unix \n) and ensure trailing newline
            normalized_key = remote_ssh_key.replace("\r\n", "\n").replace("\r", "\n").rstrip() + "\n"
            with open(remote_key_path, "w") as kf:
                kf.write(normalized_key)
            os.chmod(remote_key_path, 0o600)
            logger.info("Wrote temporary SSH key for remote repo")
        except Exception as e:
            logger.error(f"Failed to write remote SSH key: {e}")
            api_request(config, "/api/agent/status", method="POST", data={
                "job_id": job_id, "result": "failed",
                "error_log": f"Failed to write remote SSH key: {e}",
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
                        api_request(
                            config,
                            "/api/agent/progress",
                            method="POST",
                            data={
                                "job_id": job_id,
                                "files_total": files_total,
                                "files_processed": files_processed,
                                "bytes_processed": original_size,
                            },
                        )
                        last_progress_time = now

                elif msg_type == "file_status" and task_type == "backup":
                    # Collect file entries for catalog
                    fpath = entry.get("path", "")
                    fsize = 0
                    fmtime = None
                    if fpath:
                        try:
                            st = os.stat("/" + fpath if not fpath.startswith("/") else fpath)
                            fsize = st.st_size
                            fmtime = datetime.datetime.fromtimestamp(st.st_mtime).strftime("%Y-%m-%d %H:%M:%S")
                        except OSError:
                            pass
                    catalog_entries.append({
                        "path": fpath,
                        "status": entry.get("status", "U")[0].upper(),
                        "size": fsize,
                        "mtime": fmtime,
                    })

                elif msg_type == "log_message":
                    log_level = entry.get("levelname", "INFO")
                    message = entry.get("message", "")
                    if log_level in ("WARNING", "ERROR", "CRITICAL"):
                        error_output += message + "\n"
                        logger.warning(f"borg: {message}")

            except json.JSONDecodeError:
                # Non-JSON output, might be regular progress text
                if "Error" in line or "error" in line:
                    error_output += line + "\n"
                logger.debug(f"borg: {line}")

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
            except (json.JSONDecodeError, KeyError):
                pass

        if proc.returncode == 0:
            result = "completed"
            logger.info(
                f"Job #{job_id} completed: {files_processed} files, "
                f"{original_size} bytes original, {deduplicated_size} bytes dedup"
            )
        elif proc.returncode == 1:
            # borg returns 1 for warnings (still successful)
            result = "completed"
            logger.warning(f"Job #{job_id} completed with warnings")
        else:
            result = "failed"
            logger.error(
                f"Job #{job_id} failed with return code {proc.returncode}"
            )
            if not error_output:
                error_output = f"borg exited with code {proc.returncode}"

    except subprocess.TimeoutExpired:
        proc.kill()
        result = "failed"
        error_output = "Task timed out after 24 hours"
        logger.error(f"Job #{job_id} timed out")
    except FileNotFoundError:
        result = "failed"
        error_output = "borg command not found"
        logger.error(f"Job #{job_id}: borg not found")
    except Exception as e:
        result = "failed"
        error_output = str(e)
        logger.error(f"Job #{job_id} error: {e}")

    # Report final status
    status_data = {
        "job_id": job_id,
        "result": result,
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

    status_response = api_request(config, "/api/agent/status", method="POST", data=status_data)

    # Run post-backup plugin cleanup
    if result == "completed" and task_type == "backup" and plugins and plugin_results:
        cleanup_plugins(plugins, plugin_results, config, job_id)

    # Send file catalog after successful backup
    if result == "completed" and task_type == "backup" and catalog_entries and status_response:
        archive_id = status_response.get("archive_id")
        if archive_id:
            upload_catalog(config, archive_id, catalog_entries)

    # Clean up temporary SSH key for remote repos
    if remote_ssh_key and os.path.exists(remote_key_path):
        try:
            os.unlink(remote_key_path)
            logger.info("Cleaned up temporary SSH key")
        except Exception as e:
            logger.warning(f"Failed to clean up temporary SSH key: {e}")


def upload_catalog(config, archive_id, entries):
    """Upload file catalog entries to the server in batches."""
    batch_size = 1000
    total = len(entries)
    uploaded = 0

    logger.info(f"Uploading catalog: {total} file entries for archive #{archive_id}")

    for i in range(0, total, batch_size):
        batch = entries[i : i + batch_size]
        is_last = (i + batch_size) >= total
        result = api_request(
            config,
            "/api/agent/catalog",
            method="POST",
            data={"archive_id": archive_id, "files": batch, "done": is_last},
        )
        if result and result.get("status") == "ok":
            uploaded += result.get("inserted", 0)
        else:
            logger.error(f"Catalog upload failed at batch {i // batch_size + 1}")
            break

    logger.info(f"Catalog upload complete: {uploaded}/{total} entries")


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
                logger.debug(f"Heartbeat failed: {e}")

        # Sleep in small increments so we can exit promptly
        for _ in range(heartbeat_interval):
            if not running:
                break
            time.sleep(1)


def main():
    global running, task_running

    setup_logging()
    logger.info(f"BBS Agent v{AGENT_VERSION} starting")

    signal.signal(signal.SIGTERM, signal_handler)
    signal.signal(signal.SIGINT, signal_handler)

    config = load_config()

    # Register with server
    if not register(config):
        logger.error("Failed to register, retrying in 30s...")
        time.sleep(30)
        if not register(config):
            logger.error("Registration failed after retry, exiting")
            sys.exit(1)

    logger.info(
        f"Polling {config['server_url']} every {config['poll_interval']}s"
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
                # Connection error — server might be down
                logger.warning("Failed to poll server, will retry")

        except Exception as e:
            logger.error(f"Poll loop error: {e}")

        # Wait for next poll
        for _ in range(config["poll_interval"]):
            if not running:
                break
            time.sleep(1)

    logger.info("Agent stopped")


if __name__ == "__main__":
    main()
