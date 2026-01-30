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
        <a class="nav-link <?= $activeTab === 'storage' ? 'active' : '' ?>" href="/settings?tab=storage">
            <i class="bi bi-hdd me-1"></i> Storage
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

<!-- Storage Tab -->
<?php if ($activeTab === 'storage'): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-hdd me-1"></i> Storage Locations
    </div>
    <div class="card-body">
        <?php if (empty($storageLocations)): ?>
            <p class="text-muted">No storage locations configured. Add one below.</p>
        <?php else: ?>
        <div class="table-responsive mb-3">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Label</th>
                        <th>Path</th>
                        <th>Max Size (GB)</th>
                        <th>Default</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($storageLocations as $loc): ?>
                    <tr>
                        <td><?= htmlspecialchars($loc['label']) ?></td>
                        <td><code><?= htmlspecialchars($loc['path']) ?></code></td>
                        <td><?= $loc['max_size_gb'] ? $loc['max_size_gb'] . ' GB' : 'Unlimited' ?></td>
                        <td><?= $loc['is_default'] ? '<span class="badge bg-success">Default</span>' : '' ?></td>
                        <td>
                            <form method="POST" action="/settings/storage/<?= $loc['id'] ?>/delete" class="d-inline" onsubmit="return confirm('Remove this storage location?')">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <h6 class="mt-3">Add Storage Location</h6>
        <form method="POST" action="/settings/storage/add">
            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Label</label>
                    <input type="text" class="form-control" name="label" required placeholder="e.g. Primary Storage">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Path</label>
                    <input type="text" class="form-control" name="path" required placeholder="/mnt/backups">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Max Size (GB)</label>
                    <input type="number" class="form-control" name="max_size_gb" placeholder="Unlimited">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_default" id="isDefault">
                        <label class="form-check-label" for="isDefault">Default</label>
                    </div>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-success w-100">Add</button>
                </div>
            </div>
        </form>
    </div>
</div>
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
                            <form method="POST" action="/settings/templates/<?= $tpl['id'] ?>/delete" class="d-inline" onsubmit="return confirm('Delete this template?')">
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
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-info-circle me-1"></i> Version Info
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="text-muted small">Current Version</div>
                        <div class="fs-4 fw-bold">v<?= htmlspecialchars($currentVersion) ?></div>
                    </div>
                    <div class="text-end">
                        <div class="text-muted small">Latest Release</div>
                        <?php if (!empty($latest['version'])): ?>
                            <div class="fs-4 fw-bold">
                                v<?= htmlspecialchars($latest['version']) ?>
                                <?php if ($hasUpdate): ?>
                                    <span class="badge bg-warning text-dark ms-1">Update Available</span>
                                <?php else: ?>
                                    <span class="badge bg-success ms-1">Up to Date</span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-muted">Not checked yet</div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($latest['checked_at'])): ?>
                    <div class="text-muted small mb-3">Last checked: <?= \BBS\Core\TimeHelper::format($latest['checked_at'], 'M j, Y g:i A') ?></div>
                <?php endif; ?>

                <div class="d-flex gap-2 flex-wrap">
                    <form method="POST" action="/settings/check-update">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="bi bi-arrow-clockwise me-1"></i> Check Now
                        </button>
                    </form>

                    <form method="POST" action="/settings/sync" onsubmit="return confirm('This will pull the latest code, update dependencies, fix all file and storage permissions, update the SSH helper, and run database migrations.\n\nProceed?')">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <button type="submit" class="btn btn-outline-secondary">
                            <i class="bi bi-wrench me-1"></i> Pull &amp; Fix Permissions
                        </button>
                    </form>

                    <?php if ($hasUpdate): ?>
                    <form method="POST" action="/settings/upgrade" onsubmit="return confirm('This will enable maintenance mode (pausing new backups), pull the latest code, update dependencies, and run migrations.\n\nRecommendation: Back up your database first.\n\nProceed with upgrade to v<?= htmlspecialchars($latest['version']) ?>?')">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-cloud-arrow-down me-1"></i> Upgrade to v<?= htmlspecialchars($latest['version']) ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
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
