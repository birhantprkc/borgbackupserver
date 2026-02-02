<?php $activeTab = $_GET['tab'] ?? 'general'; ?>

<!-- Tab Navigation -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'general' ? 'active' : '' ?>" href="/settings?tab=general">
            <i class="bi bi-gear me-1"></i> General
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'notifications' ? 'active' : '' ?>" href="/settings?tab=notifications">
            <i class="bi bi-bell me-1"></i> Notifications
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'templates' ? 'active' : '' ?>" href="/settings?tab=templates">
            <i class="bi bi-clipboard-check me-1"></i> Templates
        </a>
    </li>
    <li class="nav-item">
        <?php
        $updateService = new \BBS\Services\UpdateService();
        $updateAvailable = $updateService->isUpdateAvailable();
        ?>
        <a class="nav-link <?= $activeTab === 'updates' ? 'active' : '' ?>" href="/settings?tab=updates">
            <i class="bi bi-cloud-arrow-down me-1"></i> Updates
            <?php if ($updateAvailable): ?>
                <span class="badge bg-warning text-dark ms-1">New</span>
            <?php endif; ?>
        </a>
    </li>
</ul>

<!-- General Tab -->
<?php if ($activeTab === 'general'): ?>
<form method="POST" action="/settings">
    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
    <input type="hidden" name="_tab" value="general">

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-server me-1"></i> Server
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Max Concurrent Jobs</label>
                        <input type="number" class="form-control" name="max_queue" value="<?= htmlspecialchars($settings['max_queue'] ?? '4') ?>" min="1" max="20">
                        <div class="form-text">Maximum backup jobs running simultaneously.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Server Host / IP</label>
                        <input type="text" class="form-control" name="server_host" value="<?= htmlspecialchars($settings['server_host'] ?? '') ?>">
                        <div class="form-text">The address agents use to reach this server.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Storage Path</label>
                        <input type="text" class="form-control" name="storage_path" value="<?= htmlspecialchars($settings['storage_path'] ?? '') ?>" readonly>
                        <div class="form-text">Base directory for agent home directories and borg repositories. Set during installation.</div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <?php $currentUrl = \BBS\Core\Config::get('APP_URL', 'https://'); $sslEnabled = str_starts_with($currentUrl, 'https://'); ?>
                            <input class="form-check-input" type="checkbox" name="enable_ssl" value="1" id="enableSsl" <?= $sslEnabled ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="enableSsl">
                                Enable SSL (HTTPS)
                            </label>
                        </div>
                        <div class="form-text">Recommended for public servers. Uncheck for LAN/internal installs without a certificate.
                            <?php if (!$sslEnabled): ?>
                                To enable SSL, first obtain a certificate: <code>sudo certbot --apache -d <?= htmlspecialchars($settings['server_host'] ?? 'your-hostname') ?></code>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Agent Poll Interval (seconds)</label>
                        <input type="number" class="form-control" name="agent_poll_interval" value="<?= htmlspecialchars($settings['agent_poll_interval'] ?? '30') ?>" min="5" max="300">
                        <div class="form-text">How often agents check for new tasks.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Session Timeout (hours)</label>
                        <input type="number" class="form-control" name="session_timeout_hours" value="<?= htmlspecialchars($settings['session_timeout_hours'] ?? '8') ?>" min="1" max="720">
                        <div class="form-text">Log out after this many hours of inactivity.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-shield-lock me-1"></i> Security
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="force_2fa" value="1"
                                   id="force2fa" <?= ($settings['force_2fa'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="force2fa">
                                Require Two-Factor Authentication for All Users
                            </label>
                        </div>
                        <div class="form-text">
                            When enabled, users without 2FA will be redirected to set it up on their next page load.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <button type="submit" class="btn btn-warning">
            <i class="bi bi-check-lg me-1"></i> Save General Settings
        </button>
    </div>
</form>
<?php endif; ?>

<!-- Notifications Tab -->
<?php if ($activeTab === 'notifications'): ?>
<form method="POST" action="/settings">
    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
    <input type="hidden" name="_tab" value="notifications">

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-bell me-1"></i> Notification Settings
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notification Retention (days)</label>
                        <input type="number" class="form-control" name="notification_retention_days" value="<?= htmlspecialchars($settings['notification_retention_days'] ?? '30') ?>" min="1" max="365">
                        <div class="form-text">Resolved notifications older than this are automatically purged.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Storage Alert Threshold (%)</label>
                        <input type="number" class="form-control" name="storage_alert_threshold" value="<?= htmlspecialchars($settings['storage_alert_threshold'] ?? '90') ?>" min="50" max="99">
                        <div class="form-text">Alert when storage usage exceeds this percentage.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-envelope me-1"></i> Email Notifications
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">SMTP Host</label>
                        <input type="text" class="form-control" name="smtp_host" value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">SMTP Port</label>
                        <input type="number" class="form-control" name="smtp_port" value="<?= htmlspecialchars($settings['smtp_port'] ?? '587') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">SMTP User</label>
                        <input type="text" class="form-control" name="smtp_user" value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">SMTP Password</label>
                        <input type="password" class="form-control" name="smtp_pass" value="<?= htmlspecialchars($settings['smtp_pass'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">From Address</label>
                        <input type="email" class="form-control" name="smtp_from" value="<?= htmlspecialchars($settings['smtp_from'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnTestSmtp">
                            <i class="bi bi-envelope-check me-1"></i> Test SMTP Connection
                        </button>
                        <span id="smtpTestResult" class="ms-2 small"></span>
                    </div>
                    <hr>
                    <label class="form-label fw-semibold">Email me when:</label>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="email_on_backup_failed" value="1" id="emailBackupFailed" <?= ($settings['email_on_backup_failed'] ?? '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="emailBackupFailed">Backup fails</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="email_on_agent_offline" value="1" id="emailAgentOffline" <?= ($settings['email_on_agent_offline'] ?? '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="emailAgentOffline">Client goes offline</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="email_on_storage_low" value="1" id="emailStorageLow" <?= ($settings['email_on_storage_low'] ?? '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="emailStorageLow">Storage space is low</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="email_on_missed_schedule" value="1" id="emailMissedSchedule" <?= ($settings['email_on_missed_schedule'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="emailMissedSchedule">Scheduled backup is missed</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <button type="submit" class="btn btn-warning">
            <i class="bi bi-check-lg me-1"></i> Save Notification Settings
        </button>
    </div>
</form>
<?php endif; ?>


<!-- Templates Tab -->
<?php if ($activeTab === 'templates'): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-clipboard-check me-1"></i> Backup Templates
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">Templates pre-fill directories and excludes when creating backup plans. Select a template to auto-populate the form.</p>

        <?php if (!empty($templates)): ?>
        <div class="table-responsive mb-4">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Directories</th>
                        <th>Excludes</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates as $tpl): ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($tpl['name']) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($tpl['description'] ?? '') ?></td>
                        <td><code class="small"><?= htmlspecialchars(str_replace("\n", ', ', $tpl['directories'])) ?></code></td>
                        <td><code class="small"><?= htmlspecialchars(str_replace("\n", ', ', $tpl['excludes'] ?? '')) ?></code></td>
                        <td class="text-nowrap">
                            <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#edit-tpl-<?= $tpl['id'] ?>">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="/settings/templates/<?= $tpl['id'] ?>/delete" class="d-inline" data-confirm="Delete this template?" data-confirm-danger>
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <tr class="collapse" id="edit-tpl-<?= $tpl['id'] ?>">
                        <td colspan="5">
                            <form method="POST" action="/settings/templates/<?= $tpl['id'] ?>/edit" class="p-2">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <div class="row g-2">
                                    <div class="col-md-3">
                                        <input type="text" class="form-control form-control-sm" name="name" value="<?= htmlspecialchars($tpl['name']) ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control form-control-sm" name="description" value="<?= htmlspecialchars($tpl['description'] ?? '') ?>" placeholder="Description">
                                    </div>
                                    <div class="col-md-2">
                                        <textarea class="form-control form-control-sm" name="directories" rows="3" required placeholder="One per line"><?= htmlspecialchars($tpl['directories']) ?></textarea>
                                    </div>
                                    <div class="col-md-2">
                                        <textarea class="form-control form-control-sm" name="excludes" rows="3" placeholder="One per line"><?= htmlspecialchars($tpl['excludes'] ?? '') ?></textarea>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-start">
                                        <button type="submit" class="btn btn-sm btn-primary w-100">Save</button>
                                    </div>
                                </div>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <h6>Add Template</h6>
        <form method="POST" action="/settings/templates/add">
            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Name</label>
                    <input type="text" class="form-control" name="name" required placeholder="e.g. cPanel Server">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Description</label>
                    <input type="text" class="form-control" name="description" placeholder="Short description">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Directories</label>
                    <textarea class="form-control" name="directories" rows="3" required placeholder="/home&#10;/etc&#10;/var/www"></textarea>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Excludes</label>
                    <textarea class="form-control" name="excludes" rows="3" placeholder="*.tmp&#10;*.log"></textarea>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-success w-100">Add Template</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Updates Tab -->
<?php if ($activeTab === 'updates'):
    $updateSvc = new \BBS\Services\UpdateService();
    $currentVersion = $updateSvc->getCurrentVersion();
    $latest = $updateSvc->getLatestRelease();
    $hasUpdate = $updateSvc->isUpdateAvailable();
    $upgradeResult = $_SESSION['upgrade_result'] ?? null;
    unset($_SESSION['upgrade_result']);
?>
<?php
// Agent version check
$bundledAgentVersion = null;
$agentPyFile = dirname(__DIR__, 3) . '/agent/bbs-agent.py';
if (file_exists($agentPyFile)) {
    $fh = fopen($agentPyFile, 'r');
    if ($fh) {
        for ($i = 0; $i < 50 && ($ln = fgets($fh)) !== false; $i++) {
            if (preg_match('/^AGENT_VERSION\s*=\s*["\']([^"\']+)["\']/m', $ln, $mv)) {
                $bundledAgentVersion = $mv[1];
                break;
            }
        }
        fclose($fh);
    }
}
$allAgents = $bundledAgentVersion ? $this->db->fetchAll("SELECT id, name, agent_version FROM agents WHERE agent_version IS NOT NULL") : [];
$outdatedAgents = $bundledAgentVersion ? array_filter($allAgents, fn($a) => $a['agent_version'] !== $bundledAgentVersion) : [];
$totalAgents = count($allAgents);
$outdatedCount = count($outdatedAgents);
?>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                Borg Backup Server Version
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Installed</div>
                        <div class="fs-4 fw-bold">v<?= htmlspecialchars($currentVersion) ?></div>
                    </div>
                    <div class="text-end">
                        <div class="text-muted small">Latest Release</div>
                        <?php if (!empty($latest['version'])): ?>
                            <div class="fs-4 fw-bold">v<?= htmlspecialchars($latest['version']) ?></div>
                        <?php else: ?>
                            <div class="text-muted">Not checked yet</div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($latest['version'])): ?>
                <div class="mt-2 mb-3">
                    <?php if ($hasUpdate): ?>
                        <span class="badge rounded-pill text-dark" style="background-color: #fff3cd;"><i class="bi bi-arrow-up-circle me-1"></i>Update available</span>
                    <?php else: ?>
                        <span class="badge rounded-pill" style="background-color: #e8f5e9; color: #2e7d32;"><i class="bi bi-check-circle me-1"></i>Up to date</span>
                    <?php endif; ?>
                    <?php if (!empty($latest['checked_at'])): ?>
                        <span class="text-muted small ms-2">Checked <?= \BBS\Core\TimeHelper::format($latest['checked_at'], 'M j, Y g:i A') ?></span>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="mb-3"></div>
                <?php endif; ?>

                <div class="d-flex gap-2 flex-wrap align-items-start">
                    <form method="POST" action="/settings/check-update">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise me-1"></i> Check for Updates
                        </button>
                    </form>
                    <?php if ($hasUpdate): ?>
                    <form method="POST" action="/settings/upgrade" data-confirm="This will enable maintenance mode (pausing new backups), checkout release v<?= htmlspecialchars($latest['version']) ?>, update dependencies, and run migrations.&#10;&#10;Recommendation: Back up your database first.&#10;&#10;Proceed with upgrade?">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="bi bi-cloud-arrow-down me-1"></i> Upgrade to v<?= htmlspecialchars($latest['version']) ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div id="agent-updates-card">
        <?php if ($bundledAgentVersion): ?>
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-incognito me-1"></i> Agent Updates
                <span class="text-muted fw-normal small ms-2" id="agent-bundled-ver">v<?= htmlspecialchars($bundledAgentVersion) ?></span>
            </div>
            <div class="card-body" id="agent-updates-body">
                <?php if ($totalAgents === 0): ?>
                    <p class="text-muted small mb-0">No agents connected yet.</p>
                <?php elseif ($outdatedCount === 0): ?>
                    <div class="d-flex align-items-center small">
                        <span class="badge rounded-pill me-2" style="background-color: #e8f5e9; color: #2e7d32;"><i class="bi bi-check-circle me-1"></i>Up to date</span>
                        All <?= $totalAgents ?> agent(s) running v<?= htmlspecialchars($bundledAgentVersion) ?>
                    </div>
                <?php else: ?>
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="small">
                            <span class="badge rounded-pill text-dark me-1" style="background-color: #fff3cd;"><?= $outdatedCount ?> outdated</span>
                            of <?= $totalAgents ?> agent(s)
                        </div>
                        <form method="POST" action="/settings/upgrade-agents" data-confirm="Queue agent updates for <?= $outdatedCount ?> client(s)?">
                            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="bi bi-arrow-up-circle me-1"></i> Update All
                            </button>
                        </form>
                    </div>
                    <?php foreach ($outdatedAgents as $oa): ?>
                    <div class="d-flex justify-content-between align-items-center small py-1">
                        <span><i class="bi bi-incognito me-1 text-muted"></i><?= htmlspecialchars($oa['name']) ?></span>
                        <span class="text-muted">v<?= htmlspecialchars($oa['agent_version']) ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($latest['notes'])): ?>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-journal-text me-1"></i> Release Notes
                <?php if (!empty($latest['url'])): ?>
                    <a href="<?= htmlspecialchars($latest['url']) ?>" target="_blank" class="float-end small text-decoration-none">
                        View on GitHub <i class="bi bi-box-arrow-up-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <pre class="mb-0 small" style="white-space: pre-wrap;"><?= htmlspecialchars($latest['notes']) ?></pre>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<hr>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-git me-1"></i> Developer Sync
            </div>
            <div class="card-body">
                <div class="alert alert-secondary small py-2 px-3 mb-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>Developer Use Only:</strong> Syncs unpublished development code from the main branch. This may include incomplete features and untested changes. Only use if directed by a developer for troubleshooting purposes.
                </div>
                <form method="POST" action="/settings/sync" data-confirm="This pulls the latest code from the main branch, which may include unreleased or unstable changes.&#10;&#10;Use Upgrade instead for stable releases.&#10;&#10;Proceed?">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <button type="submit" class="btn btn-outline-secondary btn-sm" title="Pulls latest from main branch (may include unreleased changes)">
                        <i class="bi bi-git me-1"></i> Sync Dev Code
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($upgradeResult): ?>
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-terminal me-1"></i> Upgrade Log
    </div>
    <div class="card-body">
        <pre class="mb-0 bg-dark text-light p-3 rounded small" style="max-height: 400px; overflow-y: auto;"><?= htmlspecialchars(implode("\n", $upgradeResult['log'])) ?></pre>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
document.getElementById('btnTestSmtp')?.addEventListener('click', function() {
    var btn = this;
    var result = document.getElementById('smtpTestResult');
    btn.disabled = true;
    result.textContent = 'Testing...';
    result.className = 'ms-2 small text-muted';
    fetch('/settings/test-smtp', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'csrf_token=' + encodeURIComponent(document.querySelector('input[name=csrf_token]').value)})
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            if (data.success) {
                result.textContent = 'Connected and authenticated successfully!';
                result.className = 'ms-2 small text-success fw-semibold';
            } else {
                result.textContent = 'Failed: ' + data.error;
                result.className = 'ms-2 small text-danger fw-semibold';
            }
        })
        .catch(function() {
            btn.disabled = false;
            result.textContent = 'Request failed.';
            result.className = 'ms-2 small text-danger fw-semibold';
        });
});

// AJAX refresh for Agent Updates section
(function() {
    var container = document.getElementById('agent-updates-body');
    if (!container) return;
    var csrfToken = '<?= $this->csrfToken() ?>';
    setInterval(function() {
        // Only refresh if updates tab is active
        if (!document.getElementById('agent-updates-card') || document.getElementById('agent-updates-card').offsetParent === null) return;
        fetch('/api/agent-updates')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.bundled_version) return;
                var verEl = document.getElementById('agent-bundled-ver');
                if (verEl) verEl.textContent = 'v' + data.bundled_version;
                var html = '';
                if (data.total === 0) {
                    html = '<p class="text-muted small mb-0">No agents connected yet.</p>';
                } else if (data.outdated.length === 0) {
                    html = '<div class="d-flex align-items-center small">'
                         + '<span class="badge rounded-pill me-2" style="background-color: #e8f5e9; color: #2e7d32;"><i class="bi bi-check-circle me-1"></i>Up to date</span>'
                         + 'All ' + data.total + ' agent(s) running v' + data.bundled_version
                         + '</div>';
                } else {
                    html = '<div class="d-flex align-items-center justify-content-between mb-3">'
                         + '<div class="small"><span class="badge rounded-pill text-dark me-1" style="background-color: #fff3cd;">' + data.outdated.length + ' outdated</span> of ' + data.total + ' agent(s)</div>'
                         + '<form method="POST" action="/settings/upgrade-agents" data-confirm="Queue agent updates for ' + data.outdated.length + ' client(s)?">'
                         + '<input type="hidden" name="csrf_token" value="' + csrfToken + '">'
                         + '<button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-arrow-up-circle me-1"></i> Update All</button>'
                         + '</form></div>';
                    data.outdated.forEach(function(a) {
                        html += '<div class="d-flex justify-content-between align-items-center small py-1">'
                              + '<span><i class="bi bi-incognito me-1 text-muted"></i>' + a.name.replace(/</g, '&lt;') + '</span>'
                              + '<span class="text-muted">v' + a.agent_version.replace(/</g, '&lt;') + '</span></div>';
                    });
                }
                container.innerHTML = html;
            })
            .catch(function() {});
    }, 10000);
})();
</script>
