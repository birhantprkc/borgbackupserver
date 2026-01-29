#!/usr/bin/env python3
"""
Borg Backup Server Agent
Polls the BBS server for tasks, executes borg commands, reports progress/status.
"""

import json
import logging
import os
import platform
import signal
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.request
from configparser import ConfigParser
from pathlib import Path

AGENT_VERSION = "1.1.0"
CONFIG_PATH = "/etc/bbs-agent/config.ini"
LOG_PATH = "/var/log/bbs-agent.log"
SSH_KEY_PATH = "/etc/bbs-agent/ssh_key"

# Allow overrides for development
if os.environ.get("BBS_AGENT_CONFIG"):
    CONFIG_PATH = os.environ["BBS_AGENT_CONFIG"]
if os.environ.get("BBS_AGENT_LOG"):
    LOG_PATH = os.environ["BBS_AGENT_LOG"]

logger = logging.getLogger("bbs-agent")
running = True


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

    # Get borg version
    try:
        result = subprocess.run(
            ["borg", "--version"], capture_output=True, text=True, timeout=10
        )
        if result.returncode == 0:
            info["borg_version"] = result.stdout.strip().replace("borg ", "")
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

        # Store SSH config
        ssh_user = result.get("ssh_unix_user", "")
        server_host = result.get("server_host", "")
        if ssh_user:
            config["ssh_unix_user"] = ssh_user
            config["server_host"] = server_host
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
    """Update borg on this system using the OS package manager."""
    job_id = task.get("job_id")
    logger.info(f"Executing borg update job #{job_id}")

    # Report running
    api_request(config, "/api/agent/progress", method="POST", data={
        "job_id": job_id, "files_total": 0, "files_processed": 0,
    })

    error_output = ""
    result = "failed"

    try:
        # Detect package manager and build update command
        if os.path.exists("/usr/bin/apt-get"):
            cmd = ["apt-get", "install", "-y", "--only-upgrade", "borgbackup"]
            pre_cmd = ["apt-get", "update", "-qq"]
        elif os.path.exists("/usr/bin/dnf"):
            cmd = ["dnf", "upgrade", "-y", "borgbackup"]
            pre_cmd = None
        elif os.path.exists("/usr/bin/yum"):
            cmd = ["yum", "update", "-y", "borgbackup"]
            pre_cmd = None
        elif os.path.exists("/usr/bin/pacman"):
            cmd = ["pacman", "-Sy", "--noconfirm", "borg"]
            pre_cmd = None
        elif os.path.exists("/usr/local/bin/brew") or os.path.exists("/opt/homebrew/bin/brew"):
            cmd = ["brew", "upgrade", "borgbackup"]
            pre_cmd = None
        elif os.path.exists("/usr/bin/pip3"):
            cmd = ["pip3", "install", "--upgrade", "borgbackup"]
            pre_cmd = None
        else:
            error_output = "No supported package manager found"
            api_request(config, "/api/agent/status", method="POST", data={
                "job_id": job_id, "result": "failed",
                "error_log": error_output,
            })
            return

        # Run pre-command (e.g. apt update)
        if pre_cmd:
            logger.info(f"Running: {' '.join(pre_cmd)}")
            subprocess.run(pre_cmd, capture_output=True, text=True, timeout=120)

        # Run update
        logger.info(f"Running: {' '.join(cmd)}")
        proc = subprocess.run(cmd, capture_output=True, text=True, timeout=600)

        if proc.returncode == 0:
            result = "completed"
            logger.info(f"Borg update completed successfully")
        else:
            error_output = proc.stderr or proc.stdout or f"Exit code {proc.returncode}"
            logger.error(f"Borg update failed: {error_output}")

    except subprocess.TimeoutExpired:
        error_output = "Update command timed out"
        logger.error(error_output)
    except Exception as e:
        error_output = str(e)
        logger.error(f"Borg update error: {e}")

    # Report status
    status_data = {"job_id": job_id, "result": result}
    if error_output:
        status_data["error_log"] = error_output[:10000]
    api_request(config, "/api/agent/status", method="POST", data=status_data)

    # Re-report system info so borg_version gets updated
    if result == "completed":
        info = get_system_info()
        api_request(config, "/api/agent/info", method="POST", data=info)
        logger.info(f"Updated borg version: {info.get('borg_version', 'unknown')}")


def execute_plugins(plugins):
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
    return results


def cleanup_plugins(plugins, plugin_results):
    """Run post-backup cleanup for plugins."""
    for plugin in plugins:
        slug = plugin.get("slug", "")
        cfg = plugin.get("config", {})
        func = globals().get(f"cleanup_plugin_{slug}")
        if func:
            try:
                func(cfg, plugin_results.get(slug, {}))
            except Exception as e:
                logger.warning(f"Plugin cleanup for {slug} failed: {e}")


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
        result = subprocess.run(list_cmd, capture_output=True, text=True)
        if result.returncode != 0:
            raise Exception(f"Failed to list databases: {result.stderr.strip()}")
        if isinstance(exclude, str):
            exclude = [x.strip() for x in exclude.split(",")]
        databases = [
            db.strip() for db in result.stdout.strip().split("\n")
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
                    r = subprocess.run(cmd, stdout=f, stderr=subprocess.PIPE, text=True)
                    if r.returncode != 0:
                        raise Exception(f"mysqldump failed for {db}: {r.stderr}")

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
                r = subprocess.run(cmd, stdout=f, stderr=subprocess.PIPE, text=True)
                if r.returncode != 0:
                    raise Exception(f"mysqldump failed: {r.stderr}")

        dump_files.append(dump_path)

    logger.info(f"MySQL dump complete: {len(dump_files)} file(s) in {dump_dir}")
    return {"dump_files": dump_files, "dump_dir": dump_dir}


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


def execute_task(config, task):
    """Execute a borg task and report progress/status."""
    job_id = task.get("job_id")
    task_type = task.get("task")
    command = task.get("command", [])
    env_vars = task.get("env", {})
    archive_name = task.get("archive_name", "")
    directories = task.get("directories", "")
    plugins = task.get("plugins", [])

    logger.info(f"Executing {task_type} job #{job_id}: {' '.join(command)}")

    # Execute pre-backup plugins
    plugin_results = {}
    if task_type == "backup" and plugins:
        try:
            logger.info(f"Running {len(plugins)} pre-backup plugin(s)")
            plugin_results = execute_plugins(plugins)
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

    # Ensure BORG_RSH is set if SSH key exists and command targets an SSH repo
    if os.path.exists(SSH_KEY_PATH) and "BORG_RSH" not in env:
        # Check if any command arg looks like an SSH repo path
        for arg in command:
            if arg.startswith("ssh://"):
                env["BORG_RSH"] = f"ssh -i {SSH_KEY_PATH} -o StrictHostKeyChecking=accept-new -o BatchMode=yes"
                break

    # Execute borg command
    files_processed = 0
    original_size = 0
    deduplicated_size = 0
    error_output = ""
    last_progress_time = time.time()
    catalog_entries = []  # Collect file entries for catalog

    try:
        proc = subprocess.Popen(
            command,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=env,
            text=True,
        )

        # Read stderr for JSON log output (borg writes progress to stderr)
        for line in proc.stderr:
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
                    catalog_entries.append({
                        "path": entry.get("path", ""),
                        "status": entry.get("status", "U")[0].upper(),
                        "size": 0,  # borg doesn't include size in file_status
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
        stdout_output = proc.stdout.read()

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
    }

    if archive_name:
        status_data["archive_name"] = archive_name
    if error_output:
        status_data["error_log"] = error_output[:10000]  # Limit size

    status_response = api_request(config, "/api/agent/status", method="POST", data=status_data)

    # Run post-backup plugin cleanup
    if result == "completed" and task_type == "backup" and plugins and plugin_results:
        cleanup_plugins(plugins, plugin_results)

    # Send file catalog after successful backup
    if result == "completed" and task_type == "backup" and catalog_entries and status_response:
        archive_id = status_response.get("archive_id")
        if archive_id:
            upload_catalog(config, archive_id, catalog_entries)


def upload_catalog(config, archive_id, entries):
    """Upload file catalog entries to the server in batches."""
    batch_size = 1000
    total = len(entries)
    uploaded = 0

    logger.info(f"Uploading catalog: {total} file entries for archive #{archive_id}")

    for i in range(0, total, batch_size):
        batch = entries[i : i + batch_size]
        result = api_request(
            config,
            "/api/agent/catalog",
            method="POST",
            data={"archive_id": archive_id, "files": batch},
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


def main():
    global running

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

    while running:
        try:
            # Poll for tasks
            result = api_request(config, "/api/agent/tasks")

            if result and result.get("tasks"):
                for task in result["tasks"]:
                    if not running:
                        break
                    if task.get("task") == "update_borg":
                        execute_update_borg(config, task)
                    else:
                        execute_task(config, task)
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
