<?php
$activeTab = $_GET['tab'] ?? 'general';
// Backwards compat: map old tab names to new consolidated tabs
if ($activeTab === 'remote') { $activeTab = 'storage'; $storageSection = 'remote'; }
elseif ($activeTab === 'offsite') { $activeTab = 'storage'; $storageSection = 's3'; }
if ($activeTab === 'storage') { $storageSection = $storageSection ?? ($_GET['section'] ?? 'overview'); }
if ($activeTab === 'borg') { $activeTab = 'updates'; $updatesSection = 'borg'; }
if ($activeTab === 'updates') { $updatesSection = $updatesSection ?? ($_GET['section'] ?? 'software'); }
?>

<!-- Tab Navigation -->
<?php
$updateService = new \BBS\Services\UpdateService();
$updateAvailable = $updateService->isUpdateAvailable();
?>
<ul class="nav nav-pills client-tabs mb-0 flex-wrap">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'general' ? 'active' : '' ?>" href="/settings?tab=general">
            <i class="bi bi-gear me-1"></i><span class="tab-label">General</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'notifications' ? 'active' : '' ?>" href="/settings?tab=notifications">
            <i class="bi bi-envelope me-1"></i><span class="tab-label"><span class="d-none d-sm-inline">Email Settings</span><span class="d-sm-none">Email</span></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'push' ? 'active' : '' ?>" href="/settings?tab=push">
            <i class="bi bi-megaphone me-1"></i><span class="tab-label"><span class="d-none d-sm-inline">Push Notifications</span><span class="d-sm-none">Push</span></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'templates' ? 'active' : '' ?>" href="/settings?tab=templates">
            <i class="bi bi-clipboard-check me-1"></i><span class="tab-label">Templates</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'storage' ? 'active' : '' ?>" href="/settings?tab=storage">
            <i class="bi bi-hdd-stack me-1"></i><span class="tab-label">Storage</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'updates' ? 'active' : '' ?>" href="/settings?tab=updates">
            <i class="bi bi-cloud-arrow-down me-1"></i><span class="tab-label">Updates</span>
            <?php if ($updateAvailable): ?>
                <span class="badge bg-warning text-dark ms-1">New</span>
            <?php endif; ?>
        </a>
    </li>
</ul>
<div class="client-tab-content border rounded-bottom p-4 mb-4 shadow-sm">

<!-- General Tab -->
<?php if ($activeTab === 'general'): ?>
<form method="POST" action="/settings">
    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
    <input type="hidden" name="_tab" value="general">

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-primary bg-opacity-10 fw-semibold">
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
                        <?php $currentUrl = \BBS\Core\Config::get('APP_URL', 'https://'); $sslEnabled = str_starts_with($currentUrl, 'https://'); ?>
                        <div class="input-group">
                            <select class="form-select" name="url_protocol" style="max-width: 110px;">
                                <option value="https" <?= $sslEnabled ? 'selected' : '' ?>>https://</option>
                                <option value="http" <?= !$sslEnabled ? 'selected' : '' ?>>http://</option>
                            </select>
                            <input type="text" class="form-control" name="server_host" value="<?= htmlspecialchars($settings['server_host'] ?? '') ?>">
                        </div>
                        <div class="form-text">The address agents use to reach this server. Use https:// for public servers, http:// for LAN/internal installs.
                            <?php if (!$sslEnabled): ?>
                                <br>To enable SSL, first obtain a certificate: <code>sudo certbot --apache -d <?= htmlspecialchars($settings['server_host'] ?? 'your-hostname') ?></code>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="row g-3">
                            <div class="col-7">
                                <label class="form-label fw-semibold">Storage Path</label>
                                <input type="text" class="form-control" name="storage_path" value="<?= htmlspecialchars($settings['storage_path'] ?? '') ?>" readonly>
                                <div class="form-text">Base directory for borg repositories. Currently <?= $storageUsagePercent ?? 0 ?>% used.</div>
                            </div>
                            <div class="col-5">
                                <label class="form-label fw-semibold">Alert at</label>
                                <div class="input-group">
                                    <input type="number" class="form-control text-center" name="storage_alert_threshold"
                                           value="<?= htmlspecialchars($settings['storage_alert_threshold'] ?? '90') ?>" min="50" max="99">
                                    <span class="input-group-text">%</span>
                                </div>
                                <div class="form-text">Send alert when storage exceeds this threshold.</div>
                            </div>
                        </div>
                        <div class="form-text mt-2">Want to backup to a remote store? <a href="/settings?tab=storage&section=remote">Configure Remote Storage</a></div>
                    </div>
                    <?php $sshPort = (int) ($settings['ssh_port'] ?? 22); if ($sshPort !== 22): ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">SSH Port</label>
                        <input type="text" class="form-control" value="<?= $sshPort ?>" readonly>
                        <div class="form-text"><i class="bi bi-info-circle me-1"></i>Agents connect via SSH on this non-standard port. Ensure client firewalls allow <strong>outbound TCP <?= $sshPort ?></strong>.</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-primary bg-opacity-10 fw-semibold">
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
                    <div class="mb-0">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="debug_mode" value="1"
                                   id="debugMode" <?= ($settings['debug_mode'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="debugMode">
                                Enable Debug Mode
                            </label>
                        </div>
                        <div class="form-text">
                            Shows detailed error pages with stack traces. Disable in production for security.
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-primary bg-opacity-10 fw-semibold">
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

<!-- Email Settings Tab -->
<?php if ($activeTab === 'notifications'): ?>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold">
                <i class="bi bi-envelope me-1"></i> SMTP Configuration
            </div>
            <div class="card-body">
                <form method="POST" action="/settings">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <input type="hidden" name="_tab" value="notifications">

                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">SMTP Host</label>
                            <input type="text" class="form-control" name="smtp_host" value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>" placeholder="smtp.example.com">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Port</label>
                            <input type="number" class="form-control" name="smtp_port" value="<?= htmlspecialchars($settings['smtp_port'] ?? '587') ?>">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">SMTP User</label>
                            <input type="text" class="form-control" name="smtp_user" value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">SMTP Password</label>
                            <input type="password" class="form-control" name="smtp_pass" value="<?= htmlspecialchars($settings['smtp_pass'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">From Address</label>
                        <input type="email" class="form-control" name="smtp_from" value="<?= htmlspecialchars($settings['smtp_from'] ?? '') ?>" placeholder="backups@example.com">
                    </div>

                    <div class="d-flex align-items-center gap-3">
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-check-lg me-1"></i> Save Email Settings
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="btnTestSmtp">
                            <i class="bi bi-envelope-check me-1"></i> Test SMTP
                        </button>
                        <span id="smtpTestResult" class="small"></span>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 bg-body-secondary">
            <div class="card-body">
                <h6 class="card-title"><i class="bi bi-lightbulb me-1 text-warning"></i> Tip</h6>
                <p class="card-text small text-muted mb-2">
                    Email settings are used for password resets, upgrade notices, and other system messages.
                </p>
                <p class="card-text small text-muted mb-0">
                    For backup alerts (failures, offline clients, storage warnings), configure
                    <a href="/settings?tab=push">Push Notifications</a> using Discord, Slack, Telegram, or 100+ other services.
                </p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>


<!-- Push Notifications Tab -->
<?php if ($activeTab === 'push'): ?>
<?php
// Event types grouped by category
$eventGroups = [
    'Backups' => [
        'backup_completed' => 'Backup Completed',
        'backup_failed' => 'Backup Failed',
    ],
    'Restores' => [
        'restore_completed' => 'Restore Completed',
        'restore_failed' => 'Restore Failed',
    ],
    'Clients' => [
        'agent_offline' => 'Client Offline',
        'agent_online' => 'Client Online',
    ],
    'Repositories' => [
        'repo_check_failed' => 'Check Failed',
        'repo_compact_done' => 'Compact Done',
    ],
    'Storage' => [
        'storage_low' => 'Storage Low',
        's3_sync_failed' => 'S3 Sync Failed',
        's3_sync_done' => 'S3 Sync Done',
    ],
    'Schedules' => [
        'missed_schedule' => 'Missed Schedule',
    ],
];
// Flatten for easy lookup
$eventTypes = [];
foreach ($eventGroups as $events) {
    $eventTypes = array_merge($eventTypes, $events);
}
// Colors by event type
$eventColors = [
    // Success events - green
    'backup_completed' => 'success',
    'restore_completed' => 'success',
    'agent_online' => 'success',
    'repo_compact_done' => 'success',
    's3_sync_done' => 'success',
    // Failure events - red
    'backup_failed' => 'danger',
    'restore_failed' => 'danger',
    'repo_check_failed' => 'danger',
    's3_sync_failed' => 'danger',
    // Warning events - orange/warning
    'agent_offline' => 'warning',
    'storage_low' => 'warning',
    'missed_schedule' => 'warning',
];
$notifServices = $this->db->fetchAll("SELECT * FROM notification_services ORDER BY name ASC");
foreach ($notifServices as &$ns) {
    $ns['events'] = json_decode($ns['events'] ?? '{}', true) ?: [];
}
unset($ns);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <p class="text-muted mb-0 small">Send alerts to Discord, Telegram, Slack, Pushover, and <a href="https://github.com/caronc/apprise/wiki#notification-services" target="_blank">100+ other services</a> using Apprise.</p>
    </div>
    <button class="btn btn-success" data-bs-toggle="collapse" data-bs-target="#addServiceForm">
        <i class="bi bi-plus-circle me-1"></i> Add Service
    </button>
</div>

<!-- Info banner with URL examples -->
<div class="alert alert-light border mb-4">
    <div class="d-flex align-items-start">
        <i class="bi bi-info-circle text-primary me-2 mt-1"></i>
        <div class="w-100">
            <a class="small text-decoration-none" data-bs-toggle="collapse" href="#urlExamples" role="button">
                <i class="bi bi-chevron-down me-1"></i>Show Service URL Examples
            </a>
            <div class="collapse mt-2" id="urlExamples">
                <div class="bg-body-secondary rounded p-3 font-monospace small">
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Discord:</strong> discord://webhook_id/webhook_token<br>
                            <strong>Telegram:</strong> tgram://bot_token/chat_id<br>
                            <strong>Slack:</strong> slack://tokenA/tokenB/tokenC<br>
                            <strong>Pushover:</strong> pover://user@token
                        </div>
                        <div class="col-md-6">
                            <strong>ntfy:</strong> ntfy://topic<br>
                            <strong>Gotify:</strong> gotify://hostname/token<br>
                            <strong>Email:</strong> mailto://user:pass@smtp.example.com<br>
                            <strong>Webhook:</strong> json://your-webhook-url
                        </div>
                    </div>
                    <div class="mt-2">
                        <a href="https://github.com/caronc/apprise/wiki#notification-services" target="_blank" class="text-decoration-none">
                            View all supported services <i class="bi bi-box-arrow-up-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Service Form (Collapse) -->
<div class="collapse mb-4" id="addServiceForm">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-primary bg-opacity-10 fw-semibold">
            <i class="bi bi-plus-circle me-1"></i> Add Notification Service
        </div>
        <div class="card-body">
            <form method="POST" action="/notification-services" id="addServiceFormEl">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Service Type</label>
                        <select class="form-select" id="addServiceType" name="service_type">
                            <option value="">-- Select a service --</option>
                            <option value="email">Email (SMTP)</option>
                            <option value="discord">Discord</option>
                            <option value="slack">Slack</option>
                            <option value="tgram">Telegram</option>
                            <option value="pover">Pushover</option>
                            <option value="ntfy">ntfy</option>
                            <option value="gotify">Gotify</option>
                            <option value="msteams">Microsoft Teams</option>
                            <option value="custom">Other / Custom URL</option>
                        </select>
                        <div class="form-text">Choose a service or select "Other" for custom URLs</div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Service Name</label>
                        <input type="text" class="form-control" name="name" id="addServiceName" placeholder="e.g., Discord Alerts" required>
                        <div class="form-text">A friendly name to identify this service</div>
                    </div>
                </div>

                <!-- Dynamic form fields container -->
                <div id="addServiceFields" class="mb-3" style="display:none;"></div>

                <!-- Raw URL field (shown for custom or as toggle) -->
                <div id="addUrlContainer" class="mb-3" style="display:none;">
                    <div class="d-flex align-items-center mb-2">
                        <label class="form-label fw-semibold mb-0">Apprise URL</label>
                        <button type="button" class="btn btn-sm btn-link text-decoration-none ms-auto" id="toggleAddUrlMode" style="display:none;">
                            <i class="bi bi-code-slash me-1"></i>Edit Raw URL
                        </button>
                    </div>
                    <input type="text" class="form-control font-monospace" name="apprise_url" id="addAppriseUrl"
                           placeholder="Enter your Apprise URL" required>
                    <div class="form-text" id="addUrlHelp">
                        See <a href="https://github.com/caronc/apprise/wiki#notification-services" target="_blank">
                        Apprise documentation</a> for URL formats
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Notify on:</label>
                    <div class="row">
                        <?php foreach ($eventGroups as $groupName => $events): ?>
                        <div class="col-lg-4 col-md-6 mb-3">
                            <div class="small text-muted fw-semibold mb-1"><?= htmlspecialchars($groupName) ?></div>
                            <?php foreach ($events as $event => $label): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="events[<?= $event ?>]"
                                       value="1" id="addEvent_<?= $event ?>"
                                       <?= str_contains($event, 'failed') || $event === 'agent_offline' || $event === 'storage_low' || $event === 'missed_schedule' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="addEvent_<?= $event ?>">
                                    <?= htmlspecialchars($label) ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text">Failure and warning events are selected by default. Enable success events if you want confirmation of successful operations.</div>
                </div>

                <div>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-plus-circle me-1"></i> Create Service
                    </button>
                    <button type="button" class="btn btn-outline-secondary ms-2" data-bs-toggle="collapse" data-bs-target="#addServiceForm">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Services Table -->
<?php if (!empty($notifServices)): ?>
<div class="card border-0 shadow-sm">
    <div class="table-responsive d-none d-md-block">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Service</th>
                    <th class="text-center" style="width: 100px;">Status</th>
                    <th>Events</th>
                    <th style="width: 150px;">Last Used</th>
                    <th class="text-end" style="width: 150px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($notifServices as $service): ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($service['name']) ?></div>
                        <div class="text-muted small font-monospace text-truncate" style="max-width: 300px;" title="<?= htmlspecialchars($service['apprise_url']) ?>">
                            <?= htmlspecialchars($service['apprise_url']) ?>
                        </div>
                    </td>
                    <td class="text-center">
                        <?php if ($service['enabled']): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                            <i class="bi bi-check-circle me-1"></i>Enabled
                        </span>
                        <?php else: ?>
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                            <i class="bi bi-pause-circle me-1"></i>Disabled
                        </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $enabledEvents = array_keys(array_filter($service['events']));
                        $maxShow = 3;
                        $shown = 0;
                        ?>
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($enabledEvents as $event): ?>
                                <?php if ($shown < $maxShow): ?>
                                <?php $color = $eventColors[$event] ?? 'secondary'; ?>
                                <span class="badge bg-<?= $color ?>-subtle text-<?= $color ?> border border-<?= $color ?>-subtle">
                                    <?= htmlspecialchars($eventTypes[$event] ?? ucfirst(str_replace('_', ' ', $event))) ?>
                                </span>
                                <?php $shown++; endif; ?>
                            <?php endforeach; ?>
                            <?php if (count($enabledEvents) > $maxShow): ?>
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" title="<?= htmlspecialchars(implode(', ', array_map(fn($e) => $eventTypes[$e] ?? $e, array_slice($enabledEvents, $maxShow)))) ?>">
                                +<?= count($enabledEvents) - $maxShow ?> more
                            </span>
                            <?php elseif (empty($enabledEvents)): ?>
                            <span class="text-muted small">No events selected</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($service['last_used_at']): ?>
                        <span class="small text-muted" title="<?= htmlspecialchars($service['last_used_at']) ?>">
                            <?= \BBS\Core\TimeHelper::format($service['last_used_at'], 'M j, Y') ?><br>
                            <?= \BBS\Core\TimeHelper::format($service['last_used_at'], 'g:i A') ?>
                        </span>
                        <?php else: ?>
                        <span class="text-muted small">Never</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <button class="btn btn-sm btn-outline-primary border-0" onclick="testPushService(<?= $service['id'] ?>, this)" title="Test">
                            <i class="bi bi-lightning"></i>
                        </button>
                        <form method="POST" action="/notification-services/<?= $service['id'] ?>/duplicate" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary border-0" title="Duplicate">
                                <i class="bi bi-copy"></i>
                            </button>
                        </form>
                        <button class="btn btn-sm btn-outline-secondary border-0" type="button"
                                data-bs-toggle="collapse" data-bs-target="#edit_<?= $service['id'] ?>" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST" action="/notification-services/<?= $service['id'] ?>/delete" class="d-inline"
                              data-confirm="Delete this notification service?" data-confirm-danger>
                            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger border-0" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <!-- Edit form (collapsed row) -->
                <tr class="collapse" id="edit_<?= $service['id'] ?>">
                    <td colspan="5" class="bg-body-secondary">
                        <form method="POST" action="/notification-services/<?= $service['id'] ?>/update" class="p-3">
                            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">

                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Service Name</label>
                                    <input type="text" class="form-control form-control-sm" name="name"
                                           value="<?= htmlspecialchars($service['name']) ?>" required>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label fw-semibold">Apprise URL</label>
                                    <input type="text" class="form-control form-control-sm font-monospace" name="apprise_url"
                                           value="<?= htmlspecialchars($service['apprise_url']) ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Notify on:</label>
                                <div class="row">
                                    <?php foreach ($eventGroups as $groupName => $events): ?>
                                    <div class="col-lg-4 col-md-6 mb-2">
                                        <div class="small text-muted fw-semibold mb-1"><?= htmlspecialchars($groupName) ?></div>
                                        <?php foreach ($events as $event => $label): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="events[<?= $event ?>]"
                                                   value="1" id="editEvent_<?= $service['id'] ?>_<?= $event ?>"
                                                   <?= ($service['events'][$event] ?? false) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="editEvent_<?= $service['id'] ?>_<?= $event ?>">
                                                <?= htmlspecialchars($label) ?>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <button type="submit" class="btn btn-sm btn-primary">
                                        <i class="bi bi-check-circle me-1"></i> Save Changes
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary ms-2"
                                            data-bs-toggle="collapse" data-bs-target="#edit_<?= $service['id'] ?>">
                                        Cancel
                                    </button>
                                </div>
                                <div>
                                    <form method="POST" action="/notification-services/<?= $service['id'] ?>/toggle" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-<?= $service['enabled'] ? 'warning' : 'success' ?>">
                                            <i class="bi bi-<?= $service['enabled'] ? 'pause-circle' : 'play-circle' ?> me-1"></i>
                                            <?= $service['enabled'] ? 'Disable' : 'Enable' ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <!-- Mobile card view -->
    <div class="d-md-none">
        <?php foreach ($notifServices as $service): ?>
        <div class="p-3 <?= $service !== end($notifServices) ? 'border-bottom' : '' ?>">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <span class="fw-semibold"><?= htmlspecialchars($service['name']) ?></span>
                    <?php if ($service['enabled']): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle ms-2">Enabled</span>
                    <?php else: ?>
                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle ms-2">Disabled</span>
                    <?php endif; ?>
                </div>
                <div class="text-nowrap">
                    <button class="btn btn-sm btn-outline-primary border-0" onclick="testPushService(<?= $service['id'] ?>, this)" title="Test">
                        <i class="bi bi-lightning"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary border-0" type="button"
                            data-bs-toggle="collapse" data-bs-target="#editMobile_<?= $service['id'] ?>" title="Edit">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <form method="POST" action="/notification-services/<?= $service['id'] ?>/delete" class="d-inline"
                          data-confirm="Delete this notification service?" data-confirm-danger>
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger border-0" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
            <?php
            $enabledEvents = array_keys(array_filter($service['events']));
            $maxShow = 3;
            $shown = 0;
            ?>
            <div class="d-flex flex-wrap gap-1 mb-1">
                <?php foreach ($enabledEvents as $event): ?>
                    <?php if ($shown < $maxShow): ?>
                    <?php $color = $eventColors[$event] ?? 'secondary'; ?>
                    <span class="badge bg-<?= $color ?>-subtle text-<?= $color ?> border border-<?= $color ?>-subtle">
                        <?= htmlspecialchars($eventTypes[$event] ?? ucfirst(str_replace('_', ' ', $event))) ?>
                    </span>
                    <?php $shown++; endif; ?>
                <?php endforeach; ?>
                <?php if (count($enabledEvents) > $maxShow): ?>
                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                    +<?= count($enabledEvents) - $maxShow ?> more
                </span>
                <?php elseif (empty($enabledEvents)): ?>
                <span class="text-muted small">No events selected</span>
                <?php endif; ?>
            </div>
            <?php if ($service['last_used_at']): ?>
            <div class="text-muted small"><i class="bi bi-clock me-1"></i><?= \BBS\Core\TimeHelper::format($service['last_used_at'], 'M j, Y g:i A') ?></div>
            <?php endif; ?>
            <!-- Mobile edit form -->
            <div class="collapse mt-3" id="editMobile_<?= $service['id'] ?>">
                <form method="POST" action="/notification-services/<?= $service['id'] ?>/update">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Service Name</label>
                        <input type="text" class="form-control form-control-sm" name="name"
                               value="<?= htmlspecialchars($service['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Apprise URL</label>
                        <input type="text" class="form-control form-control-sm font-monospace" name="apprise_url"
                               value="<?= htmlspecialchars($service['apprise_url']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notify on:</label>
                        <?php foreach ($eventGroups as $groupName => $events): ?>
                        <div class="small text-muted fw-semibold mb-1 mt-2"><?= htmlspecialchars($groupName) ?></div>
                        <?php foreach ($events as $event => $label): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="events[<?= $event ?>]"
                                   value="1" id="editMobileEvent_<?= $service['id'] ?>_<?= $event ?>"
                                   <?= ($service['events'][$event] ?? false) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="editMobileEvent_<?= $service['id'] ?>_<?= $event ?>">
                                <?= htmlspecialchars($label) ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex justify-content-between">
                        <div>
                            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-circle me-1"></i>Save</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary ms-2"
                                    data-bs-toggle="collapse" data-bs-target="#editMobile_<?= $service['id'] ?>">Cancel</button>
                        </div>
                        <form method="POST" action="/notification-services/<?= $service['id'] ?>/toggle" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                            <button type="submit" class="btn btn-sm btn-outline-<?= $service['enabled'] ? 'warning' : 'success' ?>">
                                <?= $service['enabled'] ? 'Disable' : 'Enable' ?>
                            </button>
                        </form>
                    </div>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm" id="noServicesCard">
    <div class="card-body p-5 text-center">
        <i class="bi bi-megaphone text-muted" style="font-size: 3rem;"></i>
        <h5 class="mt-3">No Notification Services</h5>
        <p class="text-muted mb-3">Add a notification service to receive alerts about backup failures, client status changes, and more.</p>
        <button class="btn btn-success" data-bs-toggle="collapse" data-bs-target="#addServiceForm">
            <i class="bi bi-plus-circle me-1"></i> Add Your First Service
        </button>
    </div>
</div>
<?php endif; ?>

<!-- Test Result Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1070;">
    <div id="pushTestToast" class="toast align-items-center border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="pushTestToastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
function testPushService(id, btn) {
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    fetch(`/notification-services/${id}/test`, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + encodeURIComponent(document.querySelector('input[name=csrf_token]').value)
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalHtml;

        const toast = document.getElementById('pushTestToast');
        const toastBody = document.getElementById('pushTestToastBody');

        toast.classList.remove('bg-success', 'bg-danger', 'text-white');

        if (data.success) {
            toast.classList.add('bg-success', 'text-white');
            toastBody.textContent = 'Test notification sent successfully!';
        } else {
            toast.classList.add('bg-danger', 'text-white');
            toastBody.textContent = 'Failed: ' + (data.error || 'Unknown error');
        }

        const bsToast = new bootstrap.Toast(toast, {delay: 4000});
        bsToast.show();
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        console.error(err);
    });
}

// Hide empty state card when add form is shown
(function() {
    const addForm = document.getElementById('addServiceForm');
    const emptyCard = document.getElementById('noServicesCard');
    if (addForm && emptyCard) {
        addForm.addEventListener('show.bs.collapse', function() {
            emptyCard.style.display = 'none';
        });
        addForm.addEventListener('hide.bs.collapse', function() {
            emptyCard.style.display = '';
        });
    }
})();

// Service schemas for form builder
const serviceSchemas = {
    email: {
        label: 'Email (SMTP)',
        fields: [
            { name: 'smtp_host', label: 'SMTP Server', type: 'text', required: true, placeholder: 'smtp.gmail.com', width: 'col-md-4' },
            { name: 'smtp_port', label: 'Port', type: 'number', placeholder: '587', width: 'col-md-2', default: '587' },
            { name: 'smtp_user', label: 'Username', type: 'text', required: true, placeholder: 'user@gmail.com', width: 'col-md-6' },
            { name: 'smtp_pass', label: 'Password', type: 'password', required: true, placeholder: 'App password', width: 'col-md-6' },
            { name: 'smtp_to', label: 'Send To', type: 'email', required: true, placeholder: 'recipient@example.com', width: 'col-md-6' },
            { name: 'smtp_from', label: 'From Address', type: 'email', placeholder: 'Same as username', width: 'col-md-6', help: 'Leave blank to use username' },
            { name: 'smtp_secure', label: 'Security', type: 'select', width: 'col-md-3', default: 'starttls', options: [
                { value: 'starttls', label: 'STARTTLS (587)' },
                { value: 'ssl', label: 'SSL/TLS (465)' },
                { value: 'none', label: 'None (25)' }
            ]}
        ],
        build: function(f) {
            const user = encodeURIComponent(f.smtp_user || '');
            const pass = encodeURIComponent(f.smtp_pass || '');
            const host = f.smtp_host || '';
            const port = f.smtp_port || '587';
            const to = encodeURIComponent(f.smtp_to || '');
            const from = encodeURIComponent(f.smtp_from || f.smtp_user || '');
            let mode = '';
            if (f.smtp_secure === 'ssl') mode = 'mailtos';
            else if (f.smtp_secure === 'none') mode = 'mailto';
            else mode = 'mailto'; // starttls is default
            return `${mode}://${user}:${pass}@${host}:${port}?to=${to}&from=${from}`;
        }
    },
    discord: {
        label: 'Discord',
        fields: [
            { name: 'webhook_id', label: 'Webhook ID', type: 'text', required: true, placeholder: '123456789012345678', width: 'col-md-4' },
            { name: 'webhook_token', label: 'Webhook Token', type: 'text', required: true, placeholder: 'abcdefg...', width: 'col-md-8' }
        ],
        help: 'Get these from Discord: Server Settings → Integrations → Webhooks → Copy Webhook URL, then extract the ID and token from: discord.com/api/webhooks/<strong>ID</strong>/<strong>TOKEN</strong>',
        build: function(f) {
            return `discord://${f.webhook_id || ''}/${f.webhook_token || ''}`;
        }
    },
    slack: {
        label: 'Slack',
        fields: [
            { name: 'token_a', label: 'Token A', type: 'text', required: true, placeholder: 'T1234567', width: 'col-md-4' },
            { name: 'token_b', label: 'Token B', type: 'text', required: true, placeholder: 'B1234567', width: 'col-md-4' },
            { name: 'token_c', label: 'Token C', type: 'text', required: true, placeholder: 'AbCdEf123...', width: 'col-md-4' },
            { name: 'channel', label: 'Channel (optional)', type: 'text', placeholder: '#alerts', width: 'col-md-4' }
        ],
        help: 'Get tokens from your Slack Incoming Webhook URL: hooks.slack.com/services/<strong>A</strong>/<strong>B</strong>/<strong>C</strong>',
        build: function(f) {
            let url = `slack://${f.token_a || ''}/${f.token_b || ''}/${f.token_c || ''}`;
            if (f.channel) url += `/${encodeURIComponent(f.channel.replace(/^#/, ''))}`;
            return url;
        }
    },
    tgram: {
        label: 'Telegram',
        fields: [
            { name: 'bot_token', label: 'Bot Token', type: 'text', required: true, placeholder: '123456789:ABCdefGHI...', width: 'col-md-6' },
            { name: 'chat_id', label: 'Chat ID', type: 'text', required: true, placeholder: '-1001234567890', width: 'col-md-6' }
        ],
        help: 'Create a bot via @BotFather, then get chat ID by messaging @userinfobot or from group info',
        build: function(f) {
            return `tgram://${f.bot_token || ''}/${f.chat_id || ''}`;
        }
    },
    pover: {
        label: 'Pushover',
        fields: [
            { name: 'user_key', label: 'User Key', type: 'text', required: true, placeholder: 'Your user key', width: 'col-md-6' },
            { name: 'api_token', label: 'API Token', type: 'text', required: true, placeholder: 'Your app API token', width: 'col-md-6' }
        ],
        help: 'Find these in your <a href="https://pushover.net/" target="_blank">Pushover dashboard</a>',
        build: function(f) {
            return `pover://${f.user_key || ''}@${f.api_token || ''}`;
        }
    },
    ntfy: {
        label: 'ntfy',
        fields: [
            { name: 'topic', label: 'Topic', type: 'text', required: true, placeholder: 'my-backup-alerts', width: 'col-md-6' },
            { name: 'server', label: 'Server (optional)', type: 'text', placeholder: 'ntfy.sh', width: 'col-md-6' }
        ],
        help: 'Subscribe to the topic in your ntfy app. Default server is ntfy.sh',
        build: function(f) {
            if (f.server && f.server !== 'ntfy.sh') {
                return `ntfy://${f.server}/${f.topic || ''}`;
            }
            return `ntfy://${f.topic || ''}`;
        }
    },
    gotify: {
        label: 'Gotify',
        fields: [
            { name: 'hostname', label: 'Server', type: 'text', required: true, placeholder: 'gotify.example.com', width: 'col-md-6' },
            { name: 'token', label: 'App Token', type: 'text', required: true, placeholder: 'AbCdEf123...', width: 'col-md-6' }
        ],
        build: function(f) {
            return `gotify://${f.hostname || ''}/${f.token || ''}`;
        }
    },
    msteams: {
        label: 'Microsoft Teams',
        fields: [
            { name: 'webhook_url', label: 'Webhook URL', type: 'text', required: true, placeholder: 'https://outlook.office.com/webhook/...', width: 'col-12' }
        ],
        help: 'Get the Incoming Webhook URL from your Teams channel connector settings',
        build: function(f) {
            // Parse webhook URL to extract tokens or use json:// format
            const url = f.webhook_url || '';
            if (url.startsWith('https://')) {
                return `msteams://${url.replace('https://', '')}`;
            }
            return url;
        }
    }
};

// Form builder function
function buildServiceForm(containerId, schema, prefix, existingValues) {
    const container = document.getElementById(containerId);
    if (!container || !schema) return;

    existingValues = existingValues || {};
    let html = '<div class="row g-3">';

    schema.fields.forEach(function(field) {
        const fieldId = prefix + '_' + field.name;
        const value = existingValues[field.name] || field.default || '';
        const required = field.required ? 'required' : '';
        const width = field.width || 'col-md-6';

        html += `<div class="${width}">`;
        html += `<label class="form-label small fw-semibold" for="${fieldId}">${field.label}${field.required ? ' <span class="text-danger">*</span>' : ''}</label>`;

        if (field.type === 'select') {
            html += `<select class="form-select form-select-sm" id="${fieldId}" name="${field.name}" ${required}>`;
            field.options.forEach(function(opt) {
                const selected = opt.value === value ? 'selected' : '';
                html += `<option value="${opt.value}" ${selected}>${opt.label}</option>`;
            });
            html += '</select>';
        } else {
            html += `<input type="${field.type}" class="form-control form-control-sm" id="${fieldId}" name="${field.name}" `;
            html += `placeholder="${field.placeholder || ''}" value="${value.replace(/"/g, '&quot;')}" ${required}>`;
        }

        html += '</div>';
    });

    html += '</div>';

    if (schema.help) {
        html += `<div class="form-text mt-2">${schema.help}</div>`;
    }

    container.innerHTML = html;
    container.style.display = 'block';

    // Add event listeners to rebuild URL on field change
    container.querySelectorAll('input, select').forEach(function(el) {
        el.addEventListener('input', function() {
            updateBuiltUrl(containerId, schema, prefix);
        });
        el.addEventListener('change', function() {
            updateBuiltUrl(containerId, schema, prefix);
        });
    });
}

// Update the hidden URL field based on form values
function updateBuiltUrl(containerId, schema, prefix) {
    const container = document.getElementById(containerId);
    const urlField = document.getElementById(prefix === 'add' ? 'addAppriseUrl' : 'editAppriseUrl');
    if (!container || !urlField || !schema.build) return;

    const values = {};
    container.querySelectorAll('input, select').forEach(function(el) {
        values[el.name] = el.value;
    });

    urlField.value = schema.build(values);
}

// Service type dropdown handler
(function() {
    const serviceType = document.getElementById('addServiceType');
    const fieldsContainer = document.getElementById('addServiceFields');
    const urlContainer = document.getElementById('addUrlContainer');
    const appriseUrl = document.getElementById('addAppriseUrl');
    const serviceName = document.getElementById('addServiceName');
    const toggleBtn = document.getElementById('toggleAddUrlMode');

    if (!serviceType) return;

    let rawUrlMode = false;

    serviceType.addEventListener('change', function() {
        const type = this.value;
        const schema = serviceSchemas[type];

        // Auto-fill service name
        if (serviceName) {
            const currentName = serviceName.value.trim();
            const defaultNames = Object.values(serviceSchemas).map(s => s.label);
            if (!currentName || defaultNames.includes(currentName)) {
                serviceName.value = schema ? schema.label : '';
            }
        }

        if (type === 'custom') {
            // Show raw URL field only
            fieldsContainer.style.display = 'none';
            fieldsContainer.innerHTML = '';
            urlContainer.style.display = 'block';
            appriseUrl.value = '';
            appriseUrl.readOnly = false;
            appriseUrl.required = true;
            if (toggleBtn) toggleBtn.style.display = 'none';
            rawUrlMode = true;
        } else if (schema) {
            // Show form builder
            buildServiceForm('addServiceFields', schema, 'add', {});
            urlContainer.style.display = 'none';
            appriseUrl.required = true;
            if (toggleBtn) toggleBtn.style.display = 'inline-block';
            rawUrlMode = false;

            // Initial URL build
            updateBuiltUrl('addServiceFields', schema, 'add');
        } else {
            // No selection
            fieldsContainer.style.display = 'none';
            fieldsContainer.innerHTML = '';
            urlContainer.style.display = 'none';
            if (toggleBtn) toggleBtn.style.display = 'none';
        }
    });

    // Toggle between form and raw URL mode
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            rawUrlMode = !rawUrlMode;
            if (rawUrlMode) {
                urlContainer.style.display = 'block';
                appriseUrl.readOnly = false;
                this.innerHTML = '<i class="bi bi-ui-checks me-1"></i>Use Form';
            } else {
                urlContainer.style.display = 'none';
                this.innerHTML = '<i class="bi bi-code-slash me-1"></i>Edit Raw URL';
                // Rebuild URL from form
                const type = serviceType.value;
                const schema = serviceSchemas[type];
                if (schema) {
                    updateBuiltUrl('addServiceFields', schema, 'add');
                }
            }
        });
    }

    // Before form submit, ensure URL is populated
    const form = document.getElementById('addServiceFormEl');
    if (form) {
        form.addEventListener('submit', function(e) {
            const type = serviceType.value;
            const schema = serviceSchemas[type];
            if (schema && !rawUrlMode) {
                updateBuiltUrl('addServiceFields', schema, 'add');
            }
            if (!appriseUrl.value.trim()) {
                e.preventDefault();
                alert('Please fill in the required fields.');
            }
        });
    }
})();
</script>
<?php endif; ?>


<!-- Templates Tab -->
<?php if ($activeTab === 'templates'): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-primary bg-opacity-10 fw-semibold">
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
<?php if ($activeTab === 'updates'): ?>

<!-- Updates Sub-Navigation -->
<ul class="nav storage-subnav mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $updatesSection === 'software' ? 'active' : '' ?>" href="/settings?tab=updates">
            <i class="bi bi-cloud-arrow-down me-1"></i> Software Updates
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $updatesSection === 'borg' ? 'active' : '' ?>" href="/settings?tab=updates&section=borg">
            <i class="bi bi-box-seam me-1"></i> Borg Clients
        </a>
    </li>
</ul>

<?php if ($updatesSection === 'borg'):
    $borgService = new \BBS\Services\BorgVersionService();
    $updateMode = $borgService->getUpdateMode();
    $serverVersion = $borgService->getServerVersion();
    $autoUpdate = $borgService->isAutoUpdateEnabled();
    // Use cached version for fast page load (AJAX will refresh it)
    $serverBorgVersion = $borgService->getServerBorgVersionCached();
    $lastBorgCheck = $borgService->getLastCheckTime();
    $serverVersions = $borgService->getServerVersions();
    $allAgents = $borgService->getAllAgentVersions();

    // Check compatibility for each agent with selected server version
    $agentCompatibility = [];
    if ($updateMode === 'server' && !empty($serverVersion)) {
        foreach ($allAgents as $agent) {
            $agentCompatibility[$agent['id']] = $borgService->isAgentCompatibleWithServerVersion($agent, $serverVersion);
        }
    }
?>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold">
                <i class="bi bi-box-seam me-1"></i> Borg Version Updater
            </div>
            <div class="card-body">
                <!-- Server borg version -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <i class="bi bi-server me-1"></i> Server Borg:
                        <span id="server-borg-version">
                        <?php if ($serverBorgVersion): ?>
                            <span class="badge bg-success">v<?= htmlspecialchars($serverBorgVersion) ?></span>
                            <span class="badge bg-body-secondary text-body border small"><?= $updateMode === 'server' ? 'Server' : 'Official' ?></span>
                        <?php else: ?>
                            <span class="badge bg-secondary"><i class="bi bi-hourglass-split"></i> checking...</span>
                        <?php endif; ?>
                        </span>
                    </div>
                    <form method="POST" action="/settings/borg/update-server">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-arrow-up-circle me-1"></i> Update Server
                        </button>
                    </form>
                </div>

                <form method="POST" action="/settings/borg/save">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">

                    <!-- Official Binaries option -->
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="radio" name="borg_update_mode" id="modeOfficial"
                               value="official" <?= $updateMode === 'official' ? 'checked' : '' ?>
                               onchange="document.getElementById('serverOptions').style.display='none'">
                        <label class="form-check-label fw-semibold" for="modeOfficial">
                            Use Official Binaries
                        </label>
                        <div class="form-text ms-4">
                            Download and install the most up-to-date and compatible Borg Version for each Agent & Server.
                            This may cause mis-matched Borg versions depending on client operating systems, but should
                            still work without issue.
                        </div>
                    </div>

                    <!-- Server Binaries option -->
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="radio" name="borg_update_mode" id="modeServer"
                               value="server" <?= $updateMode === 'server' ? 'checked' : '' ?>
                               onchange="document.getElementById('serverOptions').style.display='block'">
                        <label class="form-check-label fw-semibold" for="modeServer">
                            Use Server Binaries
                        </label>
                        <div class="form-text ms-4">
                            These newer binaries work with older operating systems that can't use the official ones.
                            Compiled and signed by BBS Authors. See <a href="https://github.com/borgbackup/borg/issues/9285" target="_blank">Borg Issue 9285</a>.
                        </div>
                    </div>

                    <!-- Server version selector (shown when server mode selected) -->
                    <div id="serverOptions" class="ms-4 mb-3" style="display: <?= $updateMode === 'server' ? 'block' : 'none' ?>">
                        <?php if (empty($serverVersions)): ?>
                            <div class="alert alert-info py-2 px-3 small">
                                <i class="bi bi-info-circle me-1"></i> No server-hosted binaries found in <code>/public/borg/</code>
                            </div>
                        <?php else: ?>
                            <label class="form-label small fw-semibold">Select Version</label>
                            <select name="borg_server_version" class="form-select form-select-sm" style="max-width: 200px;">
                                <?php foreach ($serverVersions as $v): ?>
                                <option value="<?= htmlspecialchars($v) ?>" <?= $v === $serverVersion ? 'selected' : '' ?>>
                                    v<?= htmlspecialchars($v) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <!-- Auto-update checkbox -->
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="borg_auto_update" id="autoUpdate"
                               value="1" <?= $autoUpdate ? 'checked' : '' ?>>
                        <label class="form-check-label" for="autoUpdate">
                            Enable auto-updates (check daily)
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check-lg me-1"></i> Save Settings
                    </button>
                </form>

                <!-- GitHub sync for official mode -->
                <?php if ($updateMode === 'official'): ?>
                <hr>
                <div class="d-flex justify-content-between align-items-center">
                    <div class="small text-muted">
                        <?php if (!empty($lastBorgCheck)): ?>
                            Last synced: <?= \BBS\Core\TimeHelper::format($lastBorgCheck, 'M j, Y g:i A') ?>
                        <?php else: ?>
                            GitHub versions not synced yet
                        <?php endif; ?>
                    </div>
                    <form method="POST" action="/settings/borg/sync">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise me-1"></i> Sync from GitHub
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-pc-display me-1"></i> Client Borg Versions</span>
                <?php if (!empty($allAgents)): ?>
                <form method="POST" action="/settings/borg/update-all"
                      data-confirm="Update server and queue borg updates for all compatible clients?">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <button type="submit" class="btn btn-sm btn-warning">
                        <i class="bi bi-arrow-up-circle me-1"></i> Update All
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($allAgents)): ?>
                    <p class="text-muted small mb-0">No agents connected yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                    <table class="table table-sm small mb-0" id="borg-clients-table">
                        <thead class="table-light">
                            <tr>
                                <th>Client</th>
                                <th>OS</th>
                                <th>glibc</th>
                                <th>Borg Version</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="borg-clients-tbody">
                    <?php foreach ($allAgents as $agent):
                        $borgVer = $agent['borg_version'] ?? 'unknown';
                        $installMethod = $agent['borg_install_method'] ?? 'unknown';
                        $borgSource = $agent['borg_source'] ?? 'unknown';
                        $isCompatible = $agentCompatibility[$agent['id']] ?? true;
                        $osInfo = $agent['os_info'] ?? '';
                        $glibcVer = $agent['glibc_version'] ?? '';
                        // Format glibc version: glibc217 -> 2.17
                        $glibcDisplay = '';
                        if ($glibcVer && preg_match('/^glibc(\d)(\d+)$/', $glibcVer, $m)) {
                            $glibcDisplay = $m[1] . '.' . $m[2];
                        } elseif ($glibcVer) {
                            $glibcDisplay = $glibcVer;
                        }
                        // Shorten os_info: "Rocky Linux 8.10 (Green Obsidian) x86_64" -> "Rocky Linux 8.10"
                        $osDisplay = $osInfo;
                        if ($osInfo && preg_match('/^(.+?)\s*\(/', $osInfo, $m)) {
                            $osDisplay = trim($m[1]);
                        } elseif ($osInfo) {
                            // Remove trailing architecture like "x86_64"
                            $osDisplay = preg_replace('/\s+(x86_64|aarch64|arm64|i686)$/i', '', $osInfo);
                        }
                    ?>
                            <tr>
                                <td>
                                    <i class="bi bi-pc-display me-1 text-muted"></i>
                                    <a href="/clients/<?= $agent['id'] ?>" class="text-decoration-none fw-semibold">
                                        <?= htmlspecialchars($agent['name']) ?>
                                    </a>
                                    <?php if ($updateMode === 'server' && !$isCompatible): ?>
                                        <span class="badge bg-danger ms-1" title="No compatible binary for glibc <?= htmlspecialchars($glibcDisplay ?: 'unknown') ?>">
                                            <i class="bi bi-exclamation-triangle"></i>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted"><?= htmlspecialchars($osDisplay ?: '-') ?></td>
                                <td class="text-muted"><?= htmlspecialchars($glibcDisplay ?: '-') ?></td>
                                <td>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($borgVer) ?></span>
                                    <span class="badge bg-body-secondary text-body border"><?= htmlspecialchars($installMethod) ?></span>
                                    <?php if ($borgSource !== 'unknown'): ?>
                                    <span class="badge bg-body-secondary text-body border"><?= ucfirst(htmlspecialchars($borgSource)) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <form method="POST" action="/settings/borg/update-agent/<?= $agent['id'] ?>" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-primary py-0 px-1" title="Update this client"
                                            <?= ($updateMode === 'server' && !$isCompatible) ? 'disabled' : '' ?>>
                                            <i class="bi bi-arrow-up-circle"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                    <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($updateMode === 'server' && !empty($serverVersions)): ?>
        <!-- Server-hosted binaries info -->
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold">
                <i class="bi bi-hdd me-1"></i> Available Server Binaries
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">
                    These binaries are compiled for older glibc versions to support a wider range of Linux distributions.
                </p>
                <?php
                $serverHostedBinaries = $borgService->getServerHostedBinaries();
                foreach ($serverHostedBinaries as $version => $binaries):
                ?>
                    <div class="mb-2">
                        <strong class="small">v<?= htmlspecialchars($version) ?></strong>
                    </div>
                    <?php foreach ($binaries as $bin): ?>
                    <div class="d-flex justify-content-between align-items-center small py-1 ps-3">
                        <span>
                            <i class="bi bi-file-earmark-binary me-1 text-muted"></i>
                            <?= htmlspecialchars($bin['filename']) ?>
                        </span>
                        <span class="badge bg-body-secondary text-body border">
                            glibc &ge; <?= htmlspecialchars(substr($bin['glibc'], 0, 1) . '.' . substr($bin['glibc'], 1)) ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    var csrfToken = <?= json_encode($this->csrfToken()) ?>;
    var updateMode = <?= json_encode($updateMode) ?>;

    function updateBorgStatus() {
        fetch('/api/borg-status')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                // Update server borg version
                var serverEl = document.getElementById('server-borg-version');
                if (serverEl && data.server_borg_version) {
                    var modeLabel = data.update_mode === 'server' ? 'Server' : 'Official';
                    serverEl.innerHTML = '<span class="badge bg-success">v' + data.server_borg_version.replace(/</g, '&lt;') + '</span> '
                        + '<span class="badge bg-body-secondary text-body border small">' + modeLabel + '</span>';
                } else if (serverEl && !data.server_borg_version) {
                    serverEl.innerHTML = '<span class="badge bg-danger">not installed</span>';
                }

                // Update client table
                var tbody = document.getElementById('borg-clients-tbody');
                if (tbody && data.agents) {
                    var html = '';
                    data.agents.forEach(function(agent) {
                        html += '<tr>';
                        html += '<td><i class="bi bi-pc-display me-1 text-muted"></i>';
                        html += '<a href="/clients/' + agent.id + '" class="text-decoration-none fw-semibold">' + agent.name.replace(/</g, '&lt;') + '</a>';
                        if (data.update_mode === 'server' && !agent.is_compatible) {
                            html += ' <span class="badge bg-danger ms-1" title="No compatible binary"><i class="bi bi-exclamation-triangle"></i></span>';
                        }
                        html += '</td>';
                        html += '<td class="text-muted">' + agent.os_display.replace(/</g, '&lt;') + '</td>';
                        html += '<td class="text-muted">' + agent.glibc_display.replace(/</g, '&lt;') + '</td>';
                        html += '<td>';
                        html += '<span class="badge bg-secondary">' + agent.borg_version.replace(/</g, '&lt;') + '</span> ';
                        html += '<span class="badge bg-body-secondary text-body border">' + agent.install_method.replace(/</g, '&lt;') + '</span>';
                        if (agent.borg_source !== 'unknown') {
                            html += ' <span class="badge bg-body-secondary text-body border">' + agent.borg_source.charAt(0).toUpperCase() + agent.borg_source.slice(1) + '</span>';
                        }
                        html += '</td>';
                        html += '<td class="text-end">';
                        html += '<form method="POST" action="/settings/borg/update-agent/' + agent.id + '" class="d-inline">';
                        html += '<input type="hidden" name="csrf_token" value="' + csrfToken + '">';
                        html += '<button type="submit" class="btn btn-sm btn-outline-primary py-0 px-1" title="Update this client"';
                        if (data.update_mode === 'server' && !agent.is_compatible) {
                            html += ' disabled';
                        }
                        html += '><i class="bi bi-arrow-up-circle"></i></button></form>';
                        html += '</td></tr>';
                    });
                    tbody.innerHTML = html;
                }
            })
            .catch(function() {});
    }

    // Initial fetch to get fresh server version (replaces "checking...")
    setTimeout(updateBorgStatus, 500);

    // Refresh every 30 seconds
    setInterval(updateBorgStatus, 30000);
})();
</script>
<?php endif; ?>

<?php endif; ?><!-- /updates tab -->

<!-- Storage Tab -->
<?php if ($activeTab === 'storage'): ?>
<?php
$_storagePath = $settings['storage_path'] ?? '/var/bbs';
$_storageTotalBytes = $storageTotalBytes ?? 0;
$_storageFreeBytes = $storageFreeBytes ?? 0;
$_storageUsedBytes = $_storageTotalBytes - $_storageFreeBytes;
$_localRepoCount = $localRepoCount ?? 0;
$_remoteRepoCount = $remoteRepoCount ?? 0;
$_s3Configured = !empty($settings['s3_endpoint']) && !empty($settings['s3_bucket']);
$_s3SyncServerBackups = ($settings['s3_sync_server_backups'] ?? '0') === '1';

// Helper to format bytes
function _formatBytes($bytes) {
    if ($bytes >= 1099511627776) return round($bytes / 1099511627776, 1) . ' TB';
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1024, 1) . ' KB';
}
?>

<!-- Storage Sub-Navigation -->
<ul class="nav storage-subnav mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $storageSection === 'overview' ? 'active' : '' ?>" href="/settings?tab=storage">
            <i class="bi bi-grid me-1"></i> Overview
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $storageSection === 's3' ? 'active' : '' ?>" href="/settings?tab=storage&section=s3">
            <i class="bi bi-bucket me-1"></i> S3 Sync
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $storageSection === 'remote' ? 'active' : '' ?>" href="/settings?tab=storage&section=remote">
            <i class="bi bi-hdd-network me-1"></i> Remote Storage
        </a>
    </li>
</ul>

<?php if ($storageSection === 'overview'): ?>
<!-- Storage Overview -->
<div class="row g-4">
    <!-- Local Storage Card -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold">
                <i class="bi bi-hdd me-1"></i> Local Storage
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-7">
                        <p class="small text-muted mb-2">Generally using local storage repos as your first line of defense in your backup strategy is going to give you the maximum benefit when you need to restore a lot of data quickly. Pair local storage with S3 Sync for bullet-proof backups.</p>
                    </div>
                    <div class="col-md-5">
                        <div class="mb-2">
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="text-muted">Disk Usage</span>
                                <span class="fw-semibold"><?= $storageUsagePercent ?>%</span>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar <?= $storageUsagePercent >= 90 ? 'bg-danger' : ($storageUsagePercent >= 75 ? 'bg-warning' : 'bg-success') ?>" style="width: <?= $storageUsagePercent ?>%"></div>
                            </div>
                        </div>
                        <div class="row g-1 small">
                            <div class="col-5 text-muted">Path</div>
                            <div class="col-7"><code class="small"><?= htmlspecialchars($_storagePath) ?></code></div>
                            <div class="col-5 text-muted">Disk</div>
                            <div class="col-7"><?= $_storageTotalBytes ? _formatBytes($_storageUsedBytes) . ' used / ' . _formatBytes($_storageFreeBytes) . ' free' : 'N/A' ?></div>
                            <div class="col-5 text-muted">Local Repos</div>
                            <div class="col-7"><span class="badge bg-primary"><?= $_localRepoCount ?></span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Remote Storage (SSH) Card -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold">
                <i class="bi bi-hdd-network me-1"></i> Remote Storage (SSH)
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-7">
                        <p class="small text-muted mb-2">Remote Storage via SSH offers an affordable and low-impact way of having backups that are offsite and secure. Requires less infrastructure and gives peace of mind knowing your backups are off-site. The borg client must be executable on the remote server. Setup wizards for BorgBase, Hetzner Storage Box, and rsync.net are available.</p>
                        <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                            <a href="/settings?tab=storage&section=remote" class="btn btn-sm <?= empty($remoteSshConfigs) ? 'btn-primary' : 'btn-outline-primary' ?> text-nowrap">
                                <?php if (empty($remoteSshConfigs)): ?>
                                <i class="bi bi-plus-lg me-1"></i> Add SSH Host
                                <?php else: ?>
                                <i class="bi bi-gear me-1"></i> Manage Hosts
                                <?php endif; ?>
                            </a>
                            <?php if (!empty($remoteSshConfigs)): ?>
                            <span class="small text-muted">
                                <span class="fw-semibold text-body"><?= count($remoteSshConfigs) ?></span> host<?= count($remoteSshConfigs) !== 1 ? 's' : '' ?>,
                                <span class="fw-semibold text-body"><?= $_remoteRepoCount ?></span> remote repo<?= $_remoteRepoCount !== 1 ? 's' : '' ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <?php if (empty($remoteSshConfigs)): ?>
                        <div class="text-center text-muted py-3">
                            <i class="bi bi-hdd-network d-block opacity-50" style="font-size: 2rem;"></i>
                            <p class="small mb-0 mt-2">No remote hosts configured yet.</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($remoteSshConfigs as $rsc): ?>
                        <div class="card border mb-2">
                            <div class="card-body py-2 px-3">
                                <div class="d-flex align-items-center gap-2" style="min-width: 0;">
                                    <div class="flex-shrink-0" style="font-size: 1.4rem;">
                                        <?php if (($rsc['provider'] ?? '') === 'borgbase'): ?>
                                        <img src="/images/borgbase.svg" alt="" style="width:24px;height:24px;border-radius:50%">
                                        <?php elseif (($rsc['provider'] ?? '') === 'hetzner'): ?>
                                        <img src="/images/hetzner-h.png" alt="" style="width:24px;height:24px;border-radius:50%">
                                        <?php else: ?>
                                        <i class="bi bi-server text-primary opacity-75"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-grow-1" style="min-width: 0;">
                                        <span class="fw-semibold small"><?= htmlspecialchars($rsc['name']) ?></span>
                                        <br><span class="text-muted small d-none d-md-inline text-truncate d-md-block" style="max-width: 100%;"><?= htmlspecialchars($rsc['remote_user']) ?>@<?= htmlspecialchars($rsc['remote_host']) ?><?= (int)$rsc['remote_port'] !== 22 ? ':' . (int)$rsc['remote_port'] : '' ?></span>
                                    </div>
                                    <span class="badge bg-success flex-shrink-0"><i class="bi bi-check-circle me-1"></i>Active</span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- S3 Offsite Sync (Global) Card -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold">
                <i class="bi bi-bucket me-1"></i> S3 Offsite Sync (Global)
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-7">
                        <p class="small text-muted mb-2">By combining the power and speed of local repositories, the S3 Sync feature keeps your repos and software database in a second, off-site location for maximum security and disaster recovery. Supports AWS S3, Backblaze B2, Wasabi, and any S3-compatible endpoint.</p>
                    </div>
                    <div class="col-md-5">
                        <?php if ($_s3Configured): ?>
                        <div class="row g-2 small">
                            <div class="col-5 text-muted">Status</div>
                            <div class="col-7"><span class="badge bg-success">Configured</span></div>
                            <div class="col-5 text-muted">Endpoint</div>
                            <div class="col-7"><?= htmlspecialchars($settings['s3_endpoint'] ?? '') ?></div>
                            <div class="col-5 text-muted">Bucket</div>
                            <div class="col-7"><?= htmlspecialchars($settings['s3_bucket'] ?? '') ?></div>
                            <?php if (!empty($settings['s3_region'])): ?>
                            <div class="col-5 text-muted">Region</div>
                            <div class="col-7"><?= htmlspecialchars($settings['s3_region']) ?></div>
                            <?php endif; ?>
                            <div class="col-5 text-muted">Server Sync</div>
                            <div class="col-7">
                                <?php if ($_s3SyncServerBackups): ?>
                                <span class="badge bg-success">Enabled</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">Disabled</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <a href="/settings?tab=storage&section=s3" class="btn btn-sm btn-outline-primary mt-3">
                            <i class="bi bi-gear me-1"></i> Configure S3
                        </a>
                        <?php else: ?>
                        <div class="text-center py-2">
                            <p class="text-muted small mb-2">S3 offsite sync is not configured yet.</p>
                            <a href="/settings?tab=storage&section=s3" class="btn btn-sm btn-primary">
                                <i class="bi bi-gear me-1"></i> Configure S3
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($storageSection === 'remote'): ?>
<!-- Remote Storage (SSH) Section -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-1">Remote Storage (SSH)</h5>
        <p class="text-muted small mb-0">Configure remote SSH hosts for offsite borg repositories (rsync.net, BorgBase, Hetzner Storage Box, etc.)</p>
    </div>
    <a href="/settings?tab=storage&section=wizard" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg me-1"></i> Add Host
    </a>
</div>

<?php if (empty($remoteSshConfigs)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-hdd-network display-4 mb-3 d-block opacity-50"></i>
        <p>No remote SSH storage hosts configured yet.</p>
        <p class="small">Add a remote host to create repositories on providers like rsync.net, BorgBase, or Hetzner Storage Box.</p>
    </div>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($remoteSshConfigs as $rsc): ?>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-primary bg-opacity-10 d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><?php
                    $provider = $rsc['provider'] ?? null;
                    if ($provider === 'borgbase') {
                        echo '<img src="/images/borgbase.svg" alt="" style="width:16px;height:16px;border-radius:50%;vertical-align:text-bottom" class="me-1"> ';
                    } elseif ($provider === 'hetzner') {
                        echo '<img src="/images/hetzner-h.png" alt="" style="width:16px;height:16px;border-radius:50%;vertical-align:text-bottom" class="me-1"> ';
                    } else {
                        echo '<i class="bi bi-hdd-network me-1"></i> ';
                    }
                ?><?= htmlspecialchars($rsc['name']) ?></span>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="testRemoteSsh(<?= $rsc['id'] ?>, this)"
                            title="Test Connection"><i class="bi bi-plug"></i></button>
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                            data-bs-toggle="modal" data-bs-target="#editRemoteSshModal<?= $rsc['id'] ?>"
                            title="Edit"><i class="bi bi-pencil"></i></button>
                    <button type="button" class="btn btn-outline-danger btn-sm"
                            onclick="deleteRemoteSsh(<?= $rsc['id'] ?>, '<?= htmlspecialchars(addslashes($rsc['name'])) ?>')"
                            title="Delete"><i class="bi bi-trash"></i></button>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-2 small">
                    <?php if (!empty($rsc['provider'])): ?>
                    <div class="col-4 text-muted">Provider</div>
                    <div class="col-8"><?php
                        $providerNames = ['borgbase' => 'BorgBase', 'hetzner' => 'Hetzner Storage Box', 'rsync.net' => 'rsync.net'];
                        echo htmlspecialchars($providerNames[$rsc['provider']] ?? ucfirst($rsc['provider']));
                    ?></div>
                    <?php endif; ?>
                    <div class="col-4 text-muted">Host</div>
                    <div class="col-8"><span class="text-info"><?= htmlspecialchars($rsc['remote_user']) ?>@<?= htmlspecialchars($rsc['remote_host']) ?><?= (int)$rsc['remote_port'] !== 22 ? ':' . (int)$rsc['remote_port'] : '' ?></span></div>
                    <div class="col-4 text-muted">Base Path</div>
                    <div class="col-8"><?= htmlspecialchars($rsc['remote_base_path']) ?></div>
                    <?php if (!empty($rsc['borg_remote_path'])): ?>
                    <div class="col-4 text-muted">Borg Binary</div>
                    <div class="col-8"><?= htmlspecialchars($rsc['borg_remote_path']) ?></div>
                    <?php endif; ?>
                    <div class="col-4 text-muted">SSH Key</div>
                    <div class="col-8"><span class="badge bg-success"><i class="bi bi-key me-1"></i>Configured</span></div>
                </div>
                <div id="remoteSshTestResult<?= $rsc['id'] ?>" class="mt-2"></div>
            </div>
        </div>
    </div>

    <!-- Edit Modal for this config -->
    <div class="modal fade" id="editRemoteSshModal<?= $rsc['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="/remote-ssh-configs/<?= $rsc['id'] ?>/update">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Remote SSH Host</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Name</label>
                            <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($rsc['name']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Provider Preset</label>
                            <select class="form-select" onchange="applyRemotePreset(this, this.closest('form'))">
                                <option value="">Custom</option>
                                <option value="rsync.net">rsync.net</option>
                                <option value="borgbase">BorgBase</option>
                                <option value="hetzner">Hetzner Storage Box</option>
                            </select>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-8">
                                <label class="form-label fw-semibold">Host</label>
                                <input type="text" class="form-control" name="remote_host" value="<?= htmlspecialchars($rsc['remote_host']) ?>" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label fw-semibold">Port</label>
                                <input type="number" class="form-control" name="remote_port" value="<?= (int)$rsc['remote_port'] ?>" min="1" max="65535">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Username</label>
                            <input type="text" class="form-control" name="remote_user" value="<?= htmlspecialchars($rsc['remote_user']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Base Path</label>
                            <input type="text" class="form-control" name="remote_base_path" value="<?= htmlspecialchars($rsc['remote_base_path']) ?>">
                            <div class="form-text">Base directory on the remote host. Use <code>./</code> for relative paths (rsync.net default).</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">SSH Private Key</label>
                            <textarea class="form-control font-monospace" name="ssh_private_key" rows="4" placeholder="Leave blank to keep existing key"></textarea>
                            <div class="form-text">Paste the private key (PEM format). Leave blank to keep the current key.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Remote Borg Path <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" class="form-control" name="borg_remote_path" value="<?= htmlspecialchars($rsc['borg_remote_path'] ?? '') ?>">
                            <div class="form-text">Custom borg binary on the remote host (e.g., <code>borg1</code> for rsync.net). Leave blank for default <code>borg</code>.</div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="append_repo_name" value="1" id="editAppendRepoName<?= $rsc['id'] ?>" <?= ($rsc['append_repo_name'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="editAppendRepoName<?= $rsc['id'] ?>">Append repository name to base path</label>
                            <div class="form-text">Uncheck for providers like BorgBase where each SSH user maps to a single fixed repo path.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<?php if ($storageSection === 's3'): ?>
<!-- S3 Sync Section -->
<form method="POST" action="/settings/offsite-storage">
    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-primary bg-opacity-10 fw-semibold">
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
                        <button type="submit" class="btn btn-sm btn-warning">
                            <i class="bi bi-check-lg me-1"></i> Save S3 Settings
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnTestS3">
                            <i class="bi bi-plug me-1"></i> Test Connection
                        </button>
                        <span id="s3TestResult" class="d-flex align-items-center ms-2 small"></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-primary bg-opacity-10 fw-semibold">
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
            result.textContent = 'Success';
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

<?php if ($storageSection === 'wizard'): ?>
<!-- Add Remote Storage Host Wizard -->
<a href="/settings?tab=storage&section=remote" class="text-decoration-none small">
    <i class="bi bi-arrow-left me-1"></i> Back to Remote Storage
</a>

<h5 class="mt-3 mb-1">Add Remote Storage Host</h5>
<p class="text-muted small mb-4">Choose your provider to get started, or use Custom for any SSH-accessible server.</p>

<div id="wizardProviders" class="row g-3 mb-4">
    <!-- BorgBase -->
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 text-center" style="cursor:pointer;background:rgba(200,170,50,0.10)" onclick="showWizardForm('borgbase')">
            <div class="card-body py-4">
                <img src="/images/borgbase.svg" alt="BorgBase" class="mb-3" style="width:48px;height:48px;border-radius:50%">
                <h6 class="mb-1">BorgBase</h6>
                <p class="text-muted small mb-2">Simple and Secure</p>
                <span class="btn btn-sm btn-dark">Setup</span>
            </div>
        </div>
    </div>
    <!-- Hetzner -->
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 text-center" style="cursor:pointer;background:rgba(220,50,50,0.08)" onclick="showWizardForm('hetzner')">
            <div class="card-body py-4">
                <img src="/images/hetzner-h.png" alt="Hetzner" class="mb-3" style="width:48px;height:48px;border-radius:50%">
                <h6 class="mb-1">Hetzner</h6>
                <p class="text-muted small mb-2">Affordable Storage Boxes</p>
                <span class="btn btn-sm btn-danger">Setup</span>
            </div>
        </div>
    </div>
    <!-- rsync.net -->
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 text-center" style="opacity:0.6">
            <div class="card-body py-4">
                <div class="rounded-circle bg-secondary bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width:48px;height:48px">
                    <i class="bi bi-hdd-rack fs-4 text-secondary"></i>
                </div>
                <h6 class="mb-1">rsync.net</h6>
                <p class="text-muted small mb-2">Cloud storage for borg</p>
                <span class="badge bg-secondary">Coming Soon</span>
            </div>
        </div>
    </div>
    <!-- Custom -->
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 text-center" style="cursor:pointer" data-bs-toggle="modal" data-bs-target="#addRemoteSshModal">
            <div class="card-body py-4">
                <div class="rounded-circle bg-secondary bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width:48px;height:48px">
                    <i class="bi bi-gear fs-4 text-secondary"></i>
                </div>
                <h6 class="mb-1">Custom</h6>
                <p class="text-muted small mb-2">Any SSH server with borg</p>
                <span class="btn btn-sm btn-outline-secondary">Setup</span>
            </div>
        </div>
    </div>
</div>

<!-- BorgBase Wizard Form -->
<div id="wizardBorgbase" style="display:none">
    <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold" style="background:rgba(38,50,56,0.08)">
            <img src="/images/borgbase.svg" alt="" style="width:18px;height:18px;border-radius:50%;vertical-align:text-bottom" class="me-1"> BorgBase Setup
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">Paste the SSH connection string from your <a href="https://www.borgbase.com" target="_blank">BorgBase</a> repository page, then paste your SSH private key below.</p>

            <form method="POST" action="/remote-ssh-configs/create" id="borgbaseWizardForm">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                <input type="hidden" name="provider" value="borgbase">
                <input type="hidden" name="borg_remote_path" value="">
                <!-- append_repo_name intentionally omitted = 0 for BorgBase -->

                <div class="mb-3">
                    <label class="form-label fw-semibold">Connection String</label>
                    <input type="text" class="form-control" id="bbConnString" placeholder="ssh://e1k7t00x@e1k7t00x.repo.borgbase.com/./repo">
                    <div class="form-text">Find this on your BorgBase repo page under "Repository".</div>
                </div>

                <!-- Parsed details -->
                <div id="bbParsedDetails" class="alert alert-light border small py-2 px-3 mb-3" style="display:none">
                    <div class="row g-2">
                        <div class="col-sm-6"><strong>Host:</strong> <span id="bbParsedHost"></span></div>
                        <div class="col-sm-6"><strong>User:</strong> <span id="bbParsedUser"></span></div>
                        <div class="col-sm-6"><strong>Port:</strong> <span id="bbParsedPort"></span></div>
                        <div class="col-sm-6"><strong>Path:</strong> <span id="bbParsedPath"></span></div>
                    </div>
                </div>

                <div id="bbParseError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none">
                    <i class="bi bi-exclamation-triangle me-1"></i> Could not parse connection string. Expected format: <code>ssh://user@host/path</code>
                </div>

                <!-- Hidden fields populated by JS -->
                <input type="hidden" name="remote_host" id="bbFieldHost">
                <input type="hidden" name="remote_port" id="bbFieldPort" value="22">
                <input type="hidden" name="remote_user" id="bbFieldUser">
                <input type="hidden" name="remote_base_path" id="bbFieldPath" value="./repo">

                <div class="mb-3">
                    <label class="form-label fw-semibold">SSH Private Key</label>
                    <textarea class="form-control font-monospace" name="ssh_private_key" id="bbSshKey" rows="4" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;..." required></textarea>
                    <div class="form-text">Paste the private key that matches the public key you added to BorgBase.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Name</label>
                    <input type="text" class="form-control" name="name" id="bbName" placeholder="e.g., BorgBase - my-repo" required>
                    <div class="form-text">A friendly name to identify this host in BBS.</div>
                </div>

                <div id="bbTestResult" style="display:none" class="mb-3"></div>

                <div class="d-flex gap-2 align-items-center">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="bbTestBtn" disabled onclick="testBorgbaseConnection()">
                        <i class="bi bi-plug me-1"></i> Test Connection
                    </button>
                    <button type="submit" class="btn btn-sm btn-primary" id="bbSubmitBtn" style="display:none">
                        <i class="bi bi-plus-lg me-1"></i> Add Host
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="hideWizardForm()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hetzner Storage Box Wizard Form -->
<div id="wizardHetzner" style="display:none">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-danger bg-opacity-10 fw-semibold">
            <img src="/images/hetzner-h.png" alt="" style="width:18px;height:18px;border-radius:50%;vertical-align:text-bottom" class="me-1"> Hetzner Storage Box Setup
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">Enter the connection details from your <a href="https://www.hetzner.com/storage/storage-box" target="_blank">Hetzner Storage Box</a> control panel.</p>

            <form method="POST" action="/remote-ssh-configs/create" id="hetznerWizardForm">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                <input type="hidden" name="provider" value="hetzner">
                <input type="hidden" name="remote_port" value="23">
                <input type="hidden" name="remote_base_path" value="./">
                <input type="hidden" name="append_repo_name" value="1">

                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Hostname</label>
                        <input type="text" class="form-control" id="hzHostname" name="remote_host" placeholder="uXXXXXX.your-storagebox.de" required>
                        <div class="form-text">Found in your Hetzner Robot panel.</div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Username</label>
                        <input type="text" class="form-control" id="hzUsername" name="remote_user" placeholder="uXXXXXX" required>
                        <div class="form-text">Your Storage Box username.</div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">SSH Private Key</label>
                    <textarea class="form-control font-monospace" name="ssh_private_key" id="hzSshKey" rows="4" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;..." required></textarea>
                    <div class="form-text">Paste the private key that matches the public key you added to your Storage Box.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Borg Version</label>
                    <select class="form-select" id="hzBorgVersion" name="borg_remote_path">
                        <option value="borg-1.4">borg 1.4 (Recommended)</option>
                        <option value="borg-1.2">borg 1.2</option>
                        <option value="borg-1.1">borg 1.1</option>
                    </select>
                    <div class="form-text">Hetzner provides multiple borg versions. This is passed as <code>--remote-path</code> in all borg commands.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Name</label>
                    <input type="text" class="form-control" name="name" id="hzName" placeholder="e.g., Hetzner - uXXXXXX" required>
                    <div class="form-text">A friendly name to identify this host in BBS.</div>
                </div>

                <!-- Parsed summary -->
                <div id="hzParsedDetails" class="alert alert-light border small py-2 px-3 mb-3" style="display:none">
                    <div class="row g-2">
                        <div class="col-sm-6"><strong>Host:</strong> <span id="hzParsedHost"></span></div>
                        <div class="col-sm-6"><strong>User:</strong> <span id="hzParsedUser"></span></div>
                        <div class="col-sm-6"><strong>Port:</strong> 23</div>
                        <div class="col-sm-6"><strong>Path:</strong> ./<em>&lt;repo-name&gt;</em></div>
                        <div class="col-sm-6"><strong>Borg:</strong> <span id="hzParsedBorg"></span></div>
                    </div>
                </div>

                <div id="hzTestResult" style="display:none" class="mb-3"></div>

                <div class="d-flex gap-2 align-items-center">
                    <button type="button" class="btn btn-sm btn-outline-danger" id="hzTestBtn" disabled onclick="testHetznerConnection()">
                        <i class="bi bi-plug me-1"></i> Test Connection
                    </button>
                    <button type="submit" class="btn btn-sm btn-danger" id="hzSubmitBtn" style="display:none">
                        <i class="bi bi-plus-lg me-1"></i> Add Host
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="hideWizardForm()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showWizardForm(provider) {
    document.getElementById('wizardProviders').style.display = 'none';
    document.getElementById('wizard' + provider.charAt(0).toUpperCase() + provider.slice(1)).style.display = 'block';
}
function hideWizardForm() {
    document.querySelectorAll('[id^="wizard"]').forEach(function(el) {
        if (el.id === 'wizardProviders') { el.style.display = ''; }
        else if (el.id.startsWith('wizard') && el.id !== 'wizardProviders') { el.style.display = 'none'; }
    });
    // Reset BorgBase form
    document.getElementById('borgbaseWizardForm').reset();
    bbTestPassed = false;
    document.getElementById('bbParsedDetails').style.display = 'none';
    document.getElementById('bbParseError').style.display = 'none';
    document.getElementById('bbTestBtn').disabled = true;
    document.getElementById('bbSubmitBtn').style.display = 'none';
    document.getElementById('bbTestResult').style.display = 'none';
    // Reset Hetzner form
    document.getElementById('hetznerWizardForm').reset();
    hzTestPassed = false;
    document.getElementById('hzParsedDetails').style.display = 'none';
    document.getElementById('hzTestBtn').disabled = true;
    document.getElementById('hzSubmitBtn').style.display = 'none';
    document.getElementById('hzTestResult').style.display = 'none';
    hzNameUserEdited = false;
}

// BorgBase connection string parser
document.getElementById('bbConnString').addEventListener('input', function() {
    bbTestPassed = false;
    var value = this.value.trim();
    var match = value.match(/^ssh:\/\/([^@]+)@([^:\/]+)(?::(\d+))?(\/.*)?$/);
    var detailsEl = document.getElementById('bbParsedDetails');
    var errorEl = document.getElementById('bbParseError');

    if (match) {
        var user = match[1];
        var host = match[2];
        var port = match[3] || '22';
        var path = match[4] || '/./repo';
        // Normalize path: /./repo -> ./repo
        if (path.startsWith('/./')) path = '.' + path.slice(2);
        else if (path.startsWith('/')) path = '.' + path;

        document.getElementById('bbParsedHost').textContent = host;
        document.getElementById('bbParsedUser').textContent = user;
        document.getElementById('bbParsedPort').textContent = port;
        document.getElementById('bbParsedPath').textContent = path;

        document.getElementById('bbFieldHost').value = host;
        document.getElementById('bbFieldPort').value = port;
        document.getElementById('bbFieldUser').value = user;
        document.getElementById('bbFieldPath').value = path;

        // Auto-fill name
        var nameField = document.getElementById('bbName');
        if (!nameField.dataset.userEdited) {
            nameField.value = 'BorgBase - ' + user;
        }

        detailsEl.style.display = 'block';
        errorEl.style.display = 'none';
        updateBbSubmit();
    } else if (value.length > 5) {
        detailsEl.style.display = 'none';
        errorEl.style.display = 'block';
        document.getElementById('bbFieldHost').value = '';
        document.getElementById('bbSubmitBtn').disabled = true;
    } else {
        detailsEl.style.display = 'none';
        errorEl.style.display = 'none';
        document.getElementById('bbSubmitBtn').disabled = true;
    }
});

// Track if user manually edited the name
document.getElementById('bbName').addEventListener('input', function() {
    this.dataset.userEdited = '1';
});

var bbTestPassed = false;

// Enable test button when connection string is parsed and key is provided
function updateBbSubmit() {
    var host = document.getElementById('bbFieldHost').value;
    var key = document.getElementById('bbSshKey').value.trim();
    var name = document.getElementById('bbName').value.trim();
    var canTest = !!(host && key);
    document.getElementById('bbTestBtn').disabled = !canTest;
    // If connection string or key changed, require re-test
    if (!bbTestPassed) {
        document.getElementById('bbSubmitBtn').style.display = 'none';
        document.getElementById('bbTestResult').style.display = 'none';
    } else {
        // Test passed — show/hide Add Host based on name
        document.getElementById('bbSubmitBtn').style.display = name ? '' : 'none';
    }
}
document.getElementById('bbSshKey').addEventListener('input', function() { bbTestPassed = false; updateBbSubmit(); });
document.getElementById('bbName').addEventListener('input', updateBbSubmit);

function testBorgbaseConnection() {
    var btn = document.getElementById('bbTestBtn');
    var resultDiv = document.getElementById('bbTestResult');
    var submitBtn = document.getElementById('bbSubmitBtn');
    var nameField = document.getElementById('bbName');

    bbTestPassed = false;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Testing...';
    resultDiv.style.display = 'none';
    submitBtn.style.display = 'none';

    var formData = new URLSearchParams();
    formData.append('csrf_token', document.querySelector('#borgbaseWizardForm [name=csrf_token]').value);
    formData.append('remote_host', document.getElementById('bbFieldHost').value);
    formData.append('remote_port', document.getElementById('bbFieldPort').value);
    formData.append('remote_user', document.getElementById('bbFieldUser').value);
    formData.append('ssh_private_key', document.getElementById('bbSshKey').value);
    formData.append('borg_remote_path', '');

    fetch('/remote-ssh-configs/test-new', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: formData.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        resultDiv.style.display = 'block';
        if (data.status === 'ok') {
            bbTestPassed = true;
            resultDiv.innerHTML = '<div class="alert alert-success small py-2 px-3 mb-0"><i class="bi bi-check-circle me-1"></i> Connected — ' + (data.version || 'borg detected').replace(/</g, '&lt;') + '</div>';
            if (nameField.value.trim()) {
                submitBtn.style.display = '';
            }
        } else {
            resultDiv.innerHTML = '<div class="alert alert-danger small py-2 px-3 mb-0"><i class="bi bi-x-circle me-1"></i> ' + (data.error || 'Connection failed').replace(/</g, '&lt;') + '</div>';
        }
    })
    .catch(function() {
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div class="alert alert-danger small py-2 px-3 mb-0"><i class="bi bi-x-circle me-1"></i> Request failed</div>';
    })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plug me-1"></i> Test Connection';
    });
}

// ---- Hetzner Storage Box wizard ----
var hzTestPassed = false;
var hzNameUserEdited = false;

function updateHzParsedDetails() {
    var host = document.getElementById('hzHostname').value.trim();
    var user = document.getElementById('hzUsername').value.trim();
    var borg = document.getElementById('hzBorgVersion').value;
    var detailsEl = document.getElementById('hzParsedDetails');

    if (host && user) {
        document.getElementById('hzParsedHost').textContent = host;
        document.getElementById('hzParsedUser').textContent = user;
        document.getElementById('hzParsedBorg').textContent = borg;
        detailsEl.style.display = 'block';
    } else {
        detailsEl.style.display = 'none';
    }
}

function updateHzSubmit() {
    var host = document.getElementById('hzHostname').value.trim();
    var user = document.getElementById('hzUsername').value.trim();
    var key = document.getElementById('hzSshKey').value.trim();
    var name = document.getElementById('hzName').value.trim();
    var canTest = !!(host && user && key);
    document.getElementById('hzTestBtn').disabled = !canTest;
    if (!hzTestPassed) {
        document.getElementById('hzSubmitBtn').style.display = 'none';
        document.getElementById('hzTestResult').style.display = 'none';
    } else {
        document.getElementById('hzSubmitBtn').style.display = name ? '' : 'none';
    }
}

// Auto-fill name from hostname
function updateHzName() {
    if (!hzNameUserEdited) {
        var user = document.getElementById('hzUsername').value.trim();
        if (user) {
            document.getElementById('hzName').value = 'Hetzner - ' + user;
        }
    }
}

document.getElementById('hzHostname').addEventListener('input', function() {
    hzTestPassed = false;
    updateHzParsedDetails();
    updateHzName();
    updateHzSubmit();
});
document.getElementById('hzUsername').addEventListener('input', function() {
    hzTestPassed = false;
    updateHzParsedDetails();
    updateHzName();
    updateHzSubmit();
});
document.getElementById('hzSshKey').addEventListener('input', function() {
    hzTestPassed = false;
    updateHzSubmit();
});
document.getElementById('hzBorgVersion').addEventListener('change', function() {
    hzTestPassed = false;
    updateHzParsedDetails();
    updateHzSubmit();
});
document.getElementById('hzName').addEventListener('input', function() {
    hzNameUserEdited = true;
    updateHzSubmit();
});

function testHetznerConnection() {
    var btn = document.getElementById('hzTestBtn');
    var resultDiv = document.getElementById('hzTestResult');
    var submitBtn = document.getElementById('hzSubmitBtn');
    var nameField = document.getElementById('hzName');

    hzTestPassed = false;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Testing...';
    resultDiv.style.display = 'none';
    submitBtn.style.display = 'none';

    var formData = new URLSearchParams();
    formData.append('csrf_token', document.querySelector('#hetznerWizardForm [name=csrf_token]').value);
    formData.append('remote_host', document.getElementById('hzHostname').value.trim());
    formData.append('remote_port', '23');
    formData.append('remote_user', document.getElementById('hzUsername').value.trim());
    formData.append('ssh_private_key', document.getElementById('hzSshKey').value);
    formData.append('borg_remote_path', document.getElementById('hzBorgVersion').value);

    fetch('/remote-ssh-configs/test-new', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: formData.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        resultDiv.style.display = 'block';
        if (data.status === 'ok') {
            hzTestPassed = true;
            resultDiv.innerHTML = '<div class="alert alert-success small py-2 px-3 mb-0"><i class="bi bi-check-circle me-1"></i> Connected — ' + (data.version || 'borg detected').replace(/</g, '&lt;') + '</div>';
            if (nameField.value.trim()) {
                submitBtn.style.display = '';
            }
        } else {
            resultDiv.innerHTML = '<div class="alert alert-danger small py-2 px-3 mb-0"><i class="bi bi-x-circle me-1"></i> ' + (data.error || 'Connection failed').replace(/</g, '&lt;') + '</div>';
        }
    })
    .catch(function() {
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div class="alert alert-danger small py-2 px-3 mb-0"><i class="bi bi-x-circle me-1"></i> Request failed</div>';
    })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plug me-1"></i> Test Connection';
    });
}
</script>
<?php endif; ?>

<!-- Add Remote SSH Host Modal (Custom) -->
<div class="modal fade" id="addRemoteSshModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="/remote-ssh-configs/create">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Add Remote SSH Host</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Name</label>
                        <input type="text" class="form-control" name="name" placeholder="e.g., rsync.net Production" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Provider Preset</label>
                        <select class="form-select" onchange="applyRemotePreset(this, this.closest('form'))">
                            <option value="">Custom</option>
                            <option value="rsync.net">rsync.net</option>
                            <option value="borgbase">BorgBase</option>
                            <option value="hetzner">Hetzner Storage Box</option>
                        </select>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-8">
                            <label class="form-label fw-semibold">Host</label>
                            <input type="text" class="form-control" name="remote_host" placeholder="ch-s010.rsync.net" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-semibold">Port</label>
                            <input type="number" class="form-control" name="remote_port" value="22" min="1" max="65535">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Username</label>
                        <input type="text" class="form-control" name="remote_user" placeholder="12345" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Base Path</label>
                        <input type="text" class="form-control" name="remote_base_path" value="./">
                        <div class="form-text">Base directory on the remote host. Use <code>./</code> for relative paths (rsync.net default).</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">SSH Private Key</label>
                        <textarea class="form-control font-monospace" name="ssh_private_key" rows="4" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;..." required></textarea>
                        <div class="form-text">Paste the private key (PEM format). The corresponding public key must be authorized on the remote host.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Remote Borg Path <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" class="form-control" name="borg_remote_path" placeholder="">
                        <div class="form-text">Custom borg binary on the remote host (e.g., <code>borg1</code> for rsync.net). Leave blank for default <code>borg</code>.</div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="append_repo_name" value="1" id="addAppendRepoName" checked>
                        <label class="form-check-label" for="addAppendRepoName">Append repository name to base path</label>
                        <div class="form-text">Uncheck for providers like BorgBase where each SSH user maps to a single fixed repo path.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Host</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?><!-- /storage tab -->

<!-- Updates Tab (Software Updates section) -->
<?php if ($activeTab === 'updates'): ?>

<?php if ($updatesSection === 'software'):
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
            <div class="card-header bg-primary bg-opacity-10 fw-semibold">
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
                        <span class="text-muted small ms-2">Checked <?= \BBS\Core\TimeHelper::ago($latest['checked_at']) ?></span>
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
            <div class="card-header bg-primary bg-opacity-10 fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-pc-display me-1"></i> BBS Client</span>
                <span class="badge bg-success" id="agent-bundled-ver">v<?= htmlspecialchars($bundledAgentVersion) ?></span>
            </div>
            <div class="card-body" id="agent-updates-body">
                <p class="text-muted small mb-3">The BBS Client receives commands from the server to initiate backups, perform restores, and update Borg software.</p>
                <?php if ($totalAgents === 0): ?>
                    <p class="text-muted small mb-0">No clients connected yet.</p>
                <?php elseif ($outdatedCount === 0): ?>
                    <div class="d-flex align-items-center small">
                        <span class="badge rounded-pill me-2" style="background-color: #e8f5e9; color: #2e7d32;"><i class="bi bi-check-circle me-1"></i>Up to date</span>
                        All <?= $totalAgents ?> client(s) running v<?= htmlspecialchars($bundledAgentVersion) ?>
                    </div>
                <?php else: ?>
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="small">
                            <span class="badge rounded-pill text-dark me-1" style="background-color: #fff3cd;"><?= $outdatedCount ?> outdated</span>
                            of <?= $totalAgents ?> client(s)
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
                        <span><i class="bi bi-pc-display me-1 text-muted"></i><?= htmlspecialchars($oa['name']) ?></span>
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
            <div class="card-header bg-primary bg-opacity-10 fw-semibold">
                <i class="bi bi-journal-text me-1"></i> Release Notes
                <?php if (!empty($latest['url'])): ?>
                    <a href="<?= htmlspecialchars($latest['url']) ?>" target="_blank" class="float-end small text-decoration-none">
                        View on GitHub <i class="bi bi-box-arrow-up-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            <div class="card-body small release-notes-md">
                <?php $parsedown = new \Parsedown(); $parsedown->setSafeMode(true); echo $parsedown->text($latest['notes']); ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<hr>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold">
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
    <div class="card-header bg-primary bg-opacity-10 fw-semibold">
        <i class="bi bi-terminal me-1"></i> Upgrade Log
    </div>
    <div class="card-body">
        <pre class="mb-0 bg-dark text-light p-3 rounded small" style="max-height: 400px; overflow-y: auto;"><?= htmlspecialchars(implode("\n", $upgradeResult['log'])) ?></pre>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php endif; ?>
</div><!-- /client-tab-content -->

<script>
// Remote SSH host management
function testRemoteSsh(id, btn) {
    var resultDiv = document.getElementById('remoteSshTestResult' + id);
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    resultDiv.innerHTML = '<div class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span> Testing connection...</div>';

    fetch('/remote-ssh-configs/' + id + '/test', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + encodeURIComponent(document.querySelector('[name=csrf_token]')?.value || '')
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'ok') {
            resultDiv.innerHTML = '<div class="alert alert-success alert-sm py-1 px-2 mb-0 small"><i class="bi bi-check-circle me-1"></i> Connected — ' + (data.version || 'borg detected').replace(/</g, '&lt;') + '</div>';
        } else {
            resultDiv.innerHTML = '<div class="alert alert-danger alert-sm py-1 px-2 mb-0 small"><i class="bi bi-x-circle me-1"></i> ' + (data.error || 'Connection failed').replace(/</g, '&lt;') + '</div>';
        }
    })
    .catch(function() {
        resultDiv.innerHTML = '<div class="alert alert-danger alert-sm py-1 px-2 mb-0 small">Request failed</div>';
    })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plug"></i>';
    });
}

function deleteRemoteSsh(id, name) {
    if (!confirm('Delete remote SSH host "' + name + '"?\n\nThis will fail if any repositories use this host.')) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '/remote-ssh-configs/' + id + '/delete';
    var csrf = document.createElement('input');
    csrf.type = 'hidden';
    csrf.name = 'csrf_token';
    csrf.value = document.querySelector('[name=csrf_token]')?.value || '';
    form.appendChild(csrf);
    document.body.appendChild(form);
    form.submit();
}

function applyRemotePreset(select, form) {
    var presets = {
        'rsync.net': { port: 22, base_path: './', borg_remote_path: 'borg1', append_repo_name: true },
        'borgbase': { port: 22, base_path: './repo', borg_remote_path: '', append_repo_name: false },
        'hetzner': { port: 23, base_path: './backups', borg_remote_path: '', append_repo_name: true }
    };
    var preset = presets[select.value];
    if (!preset) return;
    var portInput = form.querySelector('[name=remote_port]');
    var baseInput = form.querySelector('[name=remote_base_path]');
    var borgInput = form.querySelector('[name=borg_remote_path]');
    var appendInput = form.querySelector('[name=append_repo_name]');
    if (portInput) portInput.value = preset.port;
    if (baseInput) baseInput.value = preset.base_path;
    if (borgInput) borgInput.value = preset.borg_remote_path;
    if (appendInput) appendInput.checked = preset.append_repo_name;
}
</script>

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
                result.textContent = 'Success';
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
                var descHtml = '<p class="text-muted small mb-3">The BBS Client receives commands from the server to initiate backups, perform restores, and update Borg software.</p>';
                if (data.total === 0) {
                    html = descHtml + '<p class="text-muted small mb-0">No clients connected yet.</p>';
                } else if (data.outdated.length === 0) {
                    html = descHtml + '<div class="d-flex align-items-center small">'
                         + '<span class="badge rounded-pill me-2" style="background-color: #e8f5e9; color: #2e7d32;"><i class="bi bi-check-circle me-1"></i>Up to date</span>'
                         + 'All ' + data.total + ' client(s) running v' + data.bundled_version
                         + '</div>';
                } else {
                    html = descHtml + '<div class="d-flex align-items-center justify-content-between mb-3">'
                         + '<div class="small"><span class="badge rounded-pill text-dark me-1" style="background-color: #fff3cd;">' + data.outdated.length + ' outdated</span> of ' + data.total + ' client(s)</div>'
                         + '<form method="POST" action="/settings/upgrade-agents" data-confirm="Queue agent updates for ' + data.outdated.length + ' client(s)?">'
                         + '<input type="hidden" name="csrf_token" value="' + csrfToken + '">'
                         + '<button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-arrow-up-circle me-1"></i> Update All</button>'
                         + '</form></div>';
                    data.outdated.forEach(function(a) {
                        html += '<div class="d-flex justify-content-between align-items-center small py-1">'
                              + '<span><i class="bi bi-pc-display me-1 text-muted"></i>' + a.name.replace(/</g, '&lt;') + '</span>'
                              + '<span class="text-muted">v' + a.agent_version.replace(/</g, '&lt;') + '</span></div>';
                    });
                }
                container.innerHTML = html;
            })
            .catch(function() {});
    }, 10000);
})();
</script>
