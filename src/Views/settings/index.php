<?php $activeTab = $_GET['tab'] ?? 'general'; ?>

<!-- Tab Navigation -->
<ul class="nav nav-pills client-tabs mb-0 flex-wrap">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'general' ? 'active' : '' ?>" href="/settings?tab=general">
            <i class="bi bi-gear me-1"></i><span class="tab-label">General</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'notifications' ? 'active' : '' ?>" href="/settings?tab=notifications">
            <i class="bi bi-bell me-1"></i><span class="tab-label">Notifications</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'templates' ? 'active' : '' ?>" href="/settings?tab=templates">
            <i class="bi bi-clipboard-check me-1"></i><span class="tab-label">Templates</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'borg-versions' ? 'active' : '' ?>" href="/settings?tab=borg-versions">
            <i class="bi bi-box-seam me-1"></i><span class="tab-label">Borg</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'offsite' ? 'active' : '' ?>" href="/settings?tab=offsite">
            <i class="bi bi-cloud-arrow-up me-1"></i><span class="tab-label">Offsite</span>
        </a>
    </li>
    <li class="nav-item">
        <?php
        $updateService = new \BBS\Services\UpdateService();
        $updateAvailable = $updateService->isUpdateAvailable();
        ?>
        <a class="nav-link <?= $activeTab === 'updates' ? 'active' : '' ?>" href="/settings?tab=updates">
            <i class="bi bi-cloud-arrow-down me-1"></i><span class="tab-label">Updates</span>
            <?php if ($updateAvailable): ?>
                <span class="badge bg-warning text-dark ms-1">New</span>
            <?php endif; ?>
        </a>
    </li>
</ul>
<div class="client-tab-content border rounded-bottom bg-white p-4 mb-4 shadow-sm">

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
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Session Timeout (hours)</label>
                        <input type="number" class="form-control" name="session_timeout_hours" value="<?= htmlspecialchars($settings['session_timeout_hours'] ?? '8') ?>" min="1" max="720">
                        <div class="form-text">Log out after this many hours of inactivity.</div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-incognito me-1"></i> Agent
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Agent Poll Interval (seconds)</label>
                        <input type="number" class="form-control" name="agent_poll_interval" value="<?= htmlspecialchars($settings['agent_poll_interval'] ?? '30') ?>" min="5" max="300">
                        <div class="form-text">How often agents check for new tasks.</div>
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

<!-- Borg Versions Tab -->
<?php if ($activeTab === 'borg-versions'):
    $borgService = new \BBS\Services\BorgVersionService();
    $borgVersions = $borgService->getStoredVersions();
    $targetBorgVersion = $borgService->getTargetVersion();
    $lastBorgCheck = $borgService->getLastCheckTime();
    $allBorgAgents = $borgService->getAllAgentVersions();
    $outdatedBorgAgents = !empty($targetBorgVersion) ? $borgService->getOutdatedAgents($targetBorgVersion) : [];
    $aboveAgents = !empty($targetBorgVersion) ? $borgService->getAgentsAboveVersion($targetBorgVersion) : [];
    $serverBorgVersion = $borgService->getServerBorgVersion();
    $serverBorgMatch = !empty($targetBorgVersion) && $serverBorgVersion === $targetBorgVersion;
    $serverHostedBinaries = $borgService->getServerHostedBinaries();

    // Compute max compatible borg version per agent (includes fallback binaries)
    $agentMaxVersions = [];
    $agentUseFallback = [];
    foreach ($allBorgAgents as $ba) {
        $maxVer = $borgService->getMaxCompatibleVersion($ba);
        $agentMaxVersions[$ba['id']] = $maxVer;
        if (!empty($targetBorgVersion)) {
            $platform = $ba['platform'] ?? null;
            $arch = $ba['architecture'] ?? null;
            $glibc = $ba['glibc_version'] ?? null;
            $githubAsset = ($platform && $arch) ? $borgService->getAssetForPlatform($targetBorgVersion, $platform, $arch, $glibc) : null;
            $agentUseFallback[$ba['id']] = !$githubAsset && $borgService->hasFallbackBinary($targetBorgVersion, $platform ?? '', $arch ?? '', $glibc);
        }
    }
?>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-box-seam me-1"></i> Borg Version Management
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <span class="small">
                            <i class="bi bi-server me-1"></i> Server borg:
                            <?php if ($serverBorgVersion): ?>
                                <?php if (!empty($targetBorgVersion)): ?>
                                    <span class="badge <?= $serverBorgMatch ? 'bg-success' : 'bg-warning text-dark' ?>">v<?= htmlspecialchars($serverBorgVersion) ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">v<?= htmlspecialchars($serverBorgVersion) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-danger">not installed</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <?php if (!empty($lastBorgCheck)): ?>
                            <span class="text-muted small">Last synced: <?= \BBS\Core\TimeHelper::format($lastBorgCheck, 'M j, Y g:i A') ?></span>
                        <?php else: ?>
                            <span class="text-muted small">Not synced yet</span>
                        <?php endif; ?>
                    </div>
                    <form method="POST" action="/settings/borg-versions/sync">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise me-1"></i> Sync from GitHub
                        </button>
                    </form>
                </div>

                <?php if (empty($borgVersions)): ?>
                    <div class="alert alert-info py-2 px-3 small mb-0">
                        <i class="bi bi-info-circle me-1"></i> No versions synced yet. Click "Sync from GitHub" to fetch available borg releases.
                    </div>
                <?php else: ?>
                    <form method="POST" action="/settings/borg-versions/set-target">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">

                        <?php if (!empty($aboveAgents)): ?>
                        <div class="alert alert-warning py-2 px-3 small mb-3">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            <strong>Downgrade warning:</strong> <?= count($aboveAgents) ?> agent(s) are running a newer borg version than the selected target.
                        </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Target Borg Version</label>
                            <select name="version" class="form-select">
                                <option value="">-- No target set --</option>
                                <?php foreach ($borgVersions as $v): ?>
                                <option value="<?= htmlspecialchars($v['version']) ?>"
                                    <?= $v['version'] === $targetBorgVersion ? 'selected' : '' ?>>
                                    v<?= htmlspecialchars($v['version']) ?>
                                    (<?= htmlspecialchars($v['release_date']) ?>)
                                    — <?= (int)$v['asset_count'] ?> binaries
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                All agents will be updated to this version when you trigger updates.
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-check-lg me-1"></i> Set Target Version
                        </button>
                    </form>

                    <div class="alert alert-light border py-2 px-3 small mt-3 mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>How updates work:</strong> Agents are first matched against official GitHub release binaries.
                        If no compatible binary is found (e.g., the client's glibc is too old), the server will use a
                        <strong>server-hosted fallback binary</strong> if one is available below.
                        All borg 1.x versions share the same repository format, so clients can safely run different 1.x versions.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($serverHostedBinaries)): ?>
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-hdd me-1"></i> Server-Hosted Binaries
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">
                    The author of Borg Backup Server compiles and cryptographically signs custom borg backup versions that work with older operating systems. These versions will be used as a fall-back if the agent is unable to update using official packages.
                </p>
                <?php foreach ($serverHostedBinaries as $version => $binaries): ?>
                    <?php foreach ($binaries as $bin): ?>
                    <div class="d-flex justify-content-between align-items-center small py-1">
                        <span>
                            <i class="bi bi-file-earmark-binary me-1 text-muted"></i>
                            <?= htmlspecialchars($bin['filename']) ?>
                        </span>
                        <span>
                            <span class="badge bg-secondary me-1">v<?= htmlspecialchars($version) ?></span>
                            <span class="badge bg-light text-dark border"><?= htmlspecialchars($bin['platform']) ?>/<?= htmlspecialchars($bin['arch']) ?></span>
                            <span class="badge bg-light text-dark border">glibc &ge; <?= htmlspecialchars(substr($bin['glibc'], 0, 1) . '.' . substr($bin['glibc'], 1)) ?></span>
                        </span>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-pc-display me-1"></i> Client Borg Versions</span>
                <?php if (!empty($targetBorgVersion) && count($outdatedBorgAgents) > 0): ?>
                <form method="POST" action="/settings/borg-versions/update-all"
                      data-confirm="Queue borg updates for <?= count($outdatedBorgAgents) ?> client(s) to v<?= htmlspecialchars($targetBorgVersion) ?>?">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <button type="submit" class="btn btn-sm btn-warning">
                        <i class="bi bi-arrow-up-circle me-1"></i> Update All (<?= count($outdatedBorgAgents) ?>)
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($allBorgAgents)): ?>
                    <p class="text-muted small mb-0">No agents connected yet.</p>
                <?php elseif (empty($targetBorgVersion)): ?>
                    <p class="text-muted small mb-0">Set a target version first to see which clients need updates.</p>
                    <hr>
                    <?php foreach ($allBorgAgents as $ba): ?>
                    <div class="d-flex justify-content-between align-items-center small py-1">
                        <span>
                            <i class="bi bi-pc-display me-1 text-muted"></i>
                            <a href="/clients/<?= $ba['id'] ?>" class="text-decoration-none"><?= htmlspecialchars($ba['name']) ?></a>
                        </span>
                        <span class="badge bg-secondary"><?= htmlspecialchars($ba['borg_version'] ?? 'unknown') ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php elseif (count($outdatedBorgAgents) === 0): ?>
                    <div class="d-flex align-items-center small">
                        <span class="badge rounded-pill me-2" style="background-color: #e8f5e9; color: #2e7d32;">
                            <i class="bi bi-check-circle me-1"></i>All matched
                        </span>
                        All <?= count($allBorgAgents) ?> client(s) at v<?= htmlspecialchars($targetBorgVersion) ?>
                    </div>
                <?php else: ?>
                    <div class="mb-2 small text-muted">
                        Target: <strong>v<?= htmlspecialchars($targetBorgVersion) ?></strong>
                    </div>
                    <?php foreach ($allBorgAgents as $ba):
                        $borgVer = $ba['borg_version'] ?? 'unknown';
                        $cleanVer = preg_replace('/^borg\s+/', '', $borgVer);
                        $isMatch = ($cleanVer === $targetBorgVersion);
                        $maxVer = $agentMaxVersions[$ba['id']] ?? null;
                        $cantRunTarget = !$isMatch && $maxVer !== null && version_compare($maxVer, $targetBorgVersion, '<');
                        $usesFallback = $agentUseFallback[$ba['id']] ?? false;
                        $badgeClass = $isMatch ? 'bg-success' : ($cantRunTarget ? 'bg-danger' : 'bg-warning text-dark');
                    ?>
                    <div class="d-flex justify-content-between align-items-center small py-1">
                        <span>
                            <i class="bi bi-pc-display me-1 text-muted"></i>
                            <a href="/clients/<?= $ba['id'] ?>" class="text-decoration-none"><?= htmlspecialchars($ba['name']) ?></a>
                            <?php if ($cantRunTarget): ?>
                                <span class="text-danger ms-1" title="glibc <?= htmlspecialchars($ba['glibc_version'] ?? 'unknown') ?>">
                                    <i class="bi bi-exclamation-triangle-fill"></i> no compatible binary
                                </span>
                            <?php elseif ($usesFallback && !$isMatch): ?>
                                <span class="text-info ms-1" title="Will use server-hosted binary (glibc <?= htmlspecialchars($ba['glibc_version'] ?? 'unknown') ?>)">
                                    <i class="bi bi-hdd me-1"></i>server binary
                                </span>
                            <?php endif; ?>
                        </span>
                        <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($borgVer) ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Offsite Storage Tab -->
<?php if ($activeTab === 'offsite'): ?>
<form method="POST" action="/settings/offsite-storage">
    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-cloud-arrow-up me-1"></i> Global S3 Settings
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">Configure S3-compatible storage for offsite repository sync. These credentials can be shared by all backup plans using the S3 Sync plugin with "Use Global S3 Settings".</p>

                    <?php
                    $s3Service = new \BBS\Services\S3SyncService();
                    $rcloneInstalled = $s3Service->isRcloneInstalled();
                    ?>
                    <?php if (!$rcloneInstalled): ?>
                    <div class="alert alert-warning py-2 px-3 small mb-3">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <strong>rclone not installed.</strong> S3 sync requires rclone. Install with: <code>apt install rclone</code>
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">S3 Endpoint URL</label>
                        <input type="text" class="form-control" name="s3_endpoint" value="<?= htmlspecialchars($settings['s3_endpoint'] ?? '') ?>" placeholder="e.g. s3.amazonaws.com">
                        <div class="form-text">The S3 API endpoint for your provider and region. Check your provider's documentation for the correct URL.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Region</label>
                        <input type="text" class="form-control" name="s3_region" value="<?= htmlspecialchars($settings['s3_region'] ?? '') ?>" placeholder="us-east-1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Bucket Name</label>
                        <input type="text" class="form-control" name="s3_bucket" value="<?= htmlspecialchars($settings['s3_bucket'] ?? '') ?>" placeholder="my-backup-bucket">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Access Key ID</label>
                        <input type="text" class="form-control" name="s3_access_key" id="s3_access_key" value="" autocomplete="new-password" placeholder="<?= !empty($settings['s3_access_key']) ? '(unchanged if empty)' : '' ?>">
                        <?php if (!empty($settings['s3_access_key'])): ?>
                            <div class="form-text">A value is saved. Leave empty to keep it unchanged.</div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Secret Access Key</label>
                        <input type="text" class="form-control" name="s3_secret_key" id="s3_secret_key" value="" autocomplete="new-password" placeholder="<?= !empty($settings['s3_secret_key']) ? '(unchanged if empty)' : '' ?>">
                        <?php if (!empty($settings['s3_secret_key'])): ?>
                            <div class="form-text">A value is saved. Leave empty to keep it unchanged.</div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Path Prefix</label>
                        <input type="text" class="form-control" name="s3_path_prefix" value="<?= htmlspecialchars($settings['s3_path_prefix'] ?? '') ?>" placeholder="Optional subfolder in bucket">
                        <div class="form-text">Repos sync to: <code>bucket/prefix/agent-name/repo-name/</code></div>
                    </div>

                    <hr>
                    <div class="form-check mb-3">
                        <input type="hidden" name="s3_sync_server_backups" value="0">
                        <input class="form-check-input" type="checkbox" name="s3_sync_server_backups" id="s3_sync_server_backups" value="1" <?= ($settings['s3_sync_server_backups'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="s3_sync_server_backups">
                            Sync server backups to off-site storage daily
                        </label>
                        <div class="form-text">Uploads the 7 most recent server backups from <code>/var/bbs/backups/</code> and removes older ones from S3.</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-check-lg me-1"></i> Save S3 Settings
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="btnTestS3">
                            <i class="bi bi-plug me-1"></i> Test Connection
                        </button>
                        <span id="s3TestResult" class="d-flex align-items-center ms-2 small"></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-info-circle me-1"></i> How It Works
                </div>
                <div class="card-body">
                    <ol class="small mb-0">
                        <li class="mb-2">Configure your S3 credentials here (or use custom credentials per-config on the Plugins tab).</li>
                        <li class="mb-2">Enable the <strong>S3 Offsite Sync</strong> plugin on your client's Plugins tab.</li>
                        <li class="mb-2">Create a named config (e.g. "Production S3") and choose "Use Global S3 Settings" or enter custom credentials.</li>
                        <li class="mb-2">Attach the config to a backup plan.</li>
                        <li class="mb-2">After each prune/compact cycle, the server automatically syncs the borg repository to S3 using <code>rclone sync</code>.</li>
                        <li class="mb-2">Only changed segments are uploaded — borg's append-only data format makes this naturally efficient.</li>
                    </ol>
                    <hr>
                    <div class="small text-muted">
                        <strong>Supported providers:</strong> AWS S3, Backblaze B2, Wasabi, MinIO, DigitalOcean Spaces, and any S3-compatible endpoint.
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
document.getElementById('btnTestS3')?.addEventListener('click', function() {
    var btn = this;
    var result = document.getElementById('s3TestResult');
    btn.disabled = true;
    result.textContent = 'Testing...';
    result.className = 'd-flex align-items-center ms-2 small text-muted';
    fetch('/settings/offsite-storage/test', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + encodeURIComponent(document.querySelector('input[name=csrf_token]').value)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        if (data.success) {
            result.textContent = 'Connection successful!';
            result.className = 'd-flex align-items-center ms-2 small text-success fw-semibold';
        } else {
            result.textContent = 'Failed: ' + data.error;
            result.className = 'd-flex align-items-center ms-2 small text-danger fw-semibold';
        }
    })
    .catch(function() {
        btn.disabled = false;
        result.textContent = 'Request failed.';
        result.className = 'd-flex align-items-center ms-2 small text-danger fw-semibold';
    });
});
</script>
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
</div><!-- /client-tab-content -->

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
