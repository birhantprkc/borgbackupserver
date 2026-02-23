#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Borg Backup Server - Windows Agent Installer

.DESCRIPTION
    Installs the BBS agent as a Windows Service with zero dependencies.
    Downloads and installs borg, the agent launcher, and configures the service.

.PARAMETER Server
    The BBS server URL (e.g., https://backups.example.com)

.PARAMETER Key
    The agent API key from the BBS dashboard

.EXAMPLE
    .\install-windows.ps1 -Server https://backups.example.com -Key abc123
#>

param(
    [Parameter(Mandatory=$true)]
    [string]$Server,

    [Parameter(Mandatory=$true)]
    [string]$Key
)

$ErrorActionPreference = "Stop"

# Force TLS 1.2+ (PowerShell 5.1 defaults to TLS 1.0 which most servers reject)
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 -bor [Net.SecurityProtocolType]::Tls13

# -----------------------------------------------------------------------------
# Configuration
# -----------------------------------------------------------------------------
$ServiceName    = "BorgBackupAgent"
$ServiceDisplay = "Borg Backup Server Agent"
$BorgDir        = "$env:ProgramFiles\BorgBackup"
$AgentDir       = "$env:ProgramData\bbs-agent"
$ConfigPath     = "$AgentDir\config.ini"
$Server         = $Server.TrimEnd("/")

# -----------------------------------------------------------------------------
# Helpers
# -----------------------------------------------------------------------------
function Write-Step   { param($msg) Write-Host "  -> $msg" -ForegroundColor Cyan }
function Write-Ok     { param($msg) Write-Host "  [OK] $msg" -ForegroundColor Green }
function Write-Warn   { param($msg) Write-Host "  [!] $msg" -ForegroundColor Yellow }
function Write-Fail   { param($msg) Write-Host "  [X] $msg" -ForegroundColor Red }

# -----------------------------------------------------------------------------
# Banner
# -----------------------------------------------------------------------------
Write-Host ""
Write-Host "  ================================================================" -ForegroundColor Blue
Write-Host "    Borg Backup Server - Windows Agent Installer" -ForegroundColor Blue
Write-Host "  ================================================================" -ForegroundColor Blue
Write-Host ""

# -----------------------------------------------------------------------------
# Validate server connectivity
# -----------------------------------------------------------------------------
Write-Step "Checking server connectivity..."
try {
    $resp = Invoke-WebRequest -Uri "$Server/api/agent/tasks" `
        -Headers @{ "Authorization" = "Bearer $Key" } `
        -UseBasicParsing -TimeoutSec 10 -ErrorAction Stop
    Write-Ok "Server reachable"
} catch {
    Write-Fail "Cannot reach server at $Server"
    Write-Fail "Check the URL and API key, then try again."
    exit 1
}

# -----------------------------------------------------------------------------
# Stop existing service if upgrading
# -----------------------------------------------------------------------------
$existingSvc = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if ($existingSvc) {
    Write-Step "Stopping existing service..."
    Stop-Service -Name $ServiceName -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
    Write-Ok "Existing service stopped"
}

# -----------------------------------------------------------------------------
# Install Borg
# -----------------------------------------------------------------------------
Write-Step "Checking for Borg..."

$borgExe = "$BorgDir\borg\borg.exe"
if (Test-Path $borgExe) {
    $borgVer = & $borgExe --version 2>&1 | Select-Object -First 1
    Write-Ok "Already installed: $borgVer"
} else {
    Write-Step "Downloading Borg for Windows..."
    $borgZipUrl = "https://github.com/marcpope/borg-windows/releases/download/v1.4.4-windows-preview/borg-windows.zip"
    $borgZip = "$env:TEMP\borg-windows.zip"

    try {
        Invoke-WebRequest -Uri $borgZipUrl -OutFile $borgZip -UseBasicParsing
        Write-Ok "Downloaded borg-windows.zip"
    } catch {
        Write-Fail "Failed to download Borg: $_"
        exit 1
    }

    Write-Step "Installing Borg to $BorgDir..."
    New-Item -ItemType Directory -Path $BorgDir -Force | Out-Null
    Expand-Archive -Path $borgZip -DestinationPath $BorgDir -Force
    Remove-Item $borgZip -Force -ErrorAction SilentlyContinue

    if (Test-Path $borgExe) {
        $borgVer = & $borgExe --version 2>&1 | Select-Object -First 1
        Write-Ok "Installed: $borgVer"
    } else {
        Write-Fail "Borg installation failed - borg.exe not found at $borgExe"
        exit 1
    }

    # Add borg to system PATH
    Write-Step "Adding Borg to system PATH..."
    $borgBinDir = "$BorgDir\borg"
    $machinePath = [Environment]::GetEnvironmentVariable("Path", "Machine")
    if ($machinePath -notlike "*$borgBinDir*") {
        [Environment]::SetEnvironmentVariable("Path", "$machinePath;$borgBinDir", "Machine")
        $env:Path = "$env:Path;$borgBinDir"
        Write-Ok "Added $borgBinDir to system PATH"
    } else {
        Write-Ok "Already in PATH"
    }
}

# -----------------------------------------------------------------------------
# Download agent files
# -----------------------------------------------------------------------------
Write-Step "Creating agent directory..."
New-Item -ItemType Directory -Path $AgentDir -Force | Out-Null

Write-Step "Downloading agent launcher..."
try {
    Invoke-WebRequest -Uri "$Server/api/agent/download?file=bbs-agent.exe" `
        -OutFile "$AgentDir\bbs-agent.exe" -UseBasicParsing
    Write-Ok "Downloaded bbs-agent.exe"
} catch {
    Write-Fail "Failed to download agent launcher: $_"
    exit 1
}

Write-Step "Downloading agent script..."
try {
    Invoke-WebRequest -Uri "$Server/api/agent/download?file=bbs-agent.py" `
        -OutFile "$AgentDir\bbs-agent-run.py" -UseBasicParsing
    Write-Ok "Downloaded bbs-agent-run.py"
} catch {
    Write-Fail "Failed to download agent script: $_"
    exit 1
}

# -----------------------------------------------------------------------------
# Install Python embeddable (zero-dependency Python runtime for the agent)
# -----------------------------------------------------------------------------
$pythonDir = "$AgentDir\python"
$pythonExe = "$pythonDir\python.exe"

if (Test-Path $pythonExe) {
    $pyVer = & $pythonExe --version 2>&1 | Select-Object -First 1
    Write-Ok "Python already installed: $pyVer"
} else {
    Write-Step "Downloading Python embeddable..."
    $pyZipUrl = "https://www.python.org/ftp/python/3.11.4/python-3.11.4-embed-amd64.zip"
    $pyZip = "$env:TEMP\python-embed.zip"

    try {
        Invoke-WebRequest -Uri $pyZipUrl -OutFile $pyZip -UseBasicParsing
        Write-Ok "Downloaded Python embeddable"
    } catch {
        Write-Fail "Failed to download Python: $_"
        Write-Warn "Install Python 3.9+ manually and ensure it is in PATH"
        $pyZip = $null
    }

    if ($pyZip -and (Test-Path $pyZip)) {
        Write-Step "Installing Python to $pythonDir..."
        New-Item -ItemType Directory -Path $pythonDir -Force | Out-Null
        Expand-Archive -Path $pyZip -DestinationPath $pythonDir -Force
        Remove-Item $pyZip -Force -ErrorAction SilentlyContinue

        if (Test-Path $pythonExe) {
            $pyVer = & $pythonExe --version 2>&1 | Select-Object -First 1
            Write-Ok "Installed: $pyVer"
        } else {
            Write-Warn "Python extraction may have failed -agent will try system Python"
        }
    }
}

# -----------------------------------------------------------------------------
# Write config
# -----------------------------------------------------------------------------
Write-Step "Writing configuration..."
# Write config without BOM (Python's configparser rejects BOM)
$configText = "[server]`nurl = $Server`napi_key = $Key`n"
[System.IO.File]::WriteAllText($ConfigPath, $configText, (New-Object System.Text.UTF8Encoding $false))
Write-Ok "Config written to $ConfigPath"

# -----------------------------------------------------------------------------
# Download SSH key from server
# -----------------------------------------------------------------------------
Write-Step "Downloading SSH key..."
try {
    $sshKeyPath = "$AgentDir\ssh_key"
    Invoke-WebRequest -Uri "$Server/api/agent/ssh-key" `
        -Headers @{ "Authorization" = "Bearer $Key" } `
        -OutFile $sshKeyPath -UseBasicParsing
    if ((Get-Item $sshKeyPath).Length -gt 0) {
        Write-Ok "SSH key saved"
    } else {
        Write-Warn "SSH key not yet available (will be downloaded on first run)"
        Remove-Item $sshKeyPath -Force -ErrorAction SilentlyContinue
    }
} catch {
    Write-Warn "SSH key not yet available (will be downloaded on first run)"
}

# -----------------------------------------------------------------------------
# Install Windows Service
# -----------------------------------------------------------------------------
Write-Step "Installing Windows Service..."

$agentExe = "$AgentDir\bbs-agent.exe"

# Remove old service if it exists
if ($existingSvc) {
    & $agentExe remove 2>$null | Out-Null
    Start-Sleep -Seconds 2
}

# Install service using the exe's built-in win32service support
& $agentExe install 2>&1 | Out-Null

if ($LASTEXITCODE -ne 0) {
    Write-Fail "Failed to install service"
    exit 1
}

# Set to auto-start and configure recovery
sc.exe config $ServiceName start= auto | Out-Null
sc.exe failure $ServiceName reset= 86400 actions= restart/30000/restart/60000/restart/120000 | Out-Null

Write-Ok "Service '$ServiceName' installed"

# -----------------------------------------------------------------------------
# Start service
# -----------------------------------------------------------------------------
Write-Step "Starting service..."
try {
    Start-Service -Name $ServiceName
    Start-Sleep -Seconds 3
    $svc = Get-Service -Name $ServiceName
    if ($svc.Status -eq "Running") {
        Write-Ok "Service is running"
    } else {
        Write-Warn "Service status: $($svc.Status)"
        Write-Warn "Check logs at: $AgentDir\bbs-agent.log"
    }
} catch {
    Write-Warn "Could not start service: $_"
    Write-Warn "Try: Start-Service $ServiceName"
}

# -----------------------------------------------------------------------------
# Summary
# -----------------------------------------------------------------------------
Write-Host ""
Write-Host "  ================================================================" -ForegroundColor Green
Write-Host "    Installation Complete!" -ForegroundColor Green
Write-Host "  ================================================================" -ForegroundColor Green
Write-Host ""
Write-Host "  Borg:      $borgExe" -ForegroundColor White
Write-Host "  Agent:     $agentExe" -ForegroundColor White
Write-Host "  Config:    $ConfigPath" -ForegroundColor White
Write-Host "  Logs:      $AgentDir\bbs-agent.log" -ForegroundColor White
Write-Host "  Service:   $ServiceName" -ForegroundColor White
Write-Host ""
Write-Host "  Useful commands:" -ForegroundColor DarkGray
Write-Host "    sc query $ServiceName         # Check service status" -ForegroundColor DarkGray
Write-Host "    Stop-Service $ServiceName      # Stop agent" -ForegroundColor DarkGray
Write-Host "    Start-Service $ServiceName     # Start agent" -ForegroundColor DarkGray
Write-Host "    Restart-Service $ServiceName   # Restart agent" -ForegroundColor DarkGray
Write-Host "    Get-Content `"$AgentDir\bbs-agent.log`" -Tail 50  # View logs" -ForegroundColor DarkGray
Write-Host ""
Write-Host "  The agent should appear online in the BBS dashboard within 30 seconds." -ForegroundColor Cyan
Write-Host ""
