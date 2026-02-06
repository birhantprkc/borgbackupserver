<?php $activeTab = $_GET['tab'] ?? 'general'; ?>

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
        <a class="nav-link <?= $activeTab === 'offsite' ? 'active' : '' ?>" href="/settings?tab=offsite">
            <i class="bi bi-bucket me-1"></i><span class="tab-label"><span class="d-none d-sm-inline">S3 Backups</span><span class="d-sm-none">S3</span></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'updates' ? 'active' : '' ?>" href="/settings?tab=updates">
            <i class="bi bi-cloud-arrow-down me-1"></i><span class="tab-label">Update</span>
            <?php if ($updateAvailable): ?>
                <span class="badge bg-warning text-dark ms-1">New</span>
            <?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'borg' ? 'active' : '' ?>" href="/settings?tab=borg">
            <i class="bi bi-box-seam me-1"></i><span class="tab-label"><span class="d-none d-sm-inline">Borg Version</span><span class="d-sm-none">Borg</span></span>
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
                <div class="card-header bg-body fw-semibold">
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
                        <label class="form-label fw-semibold">Storage Path</label>
                        <div class="row g-2">
                            <div class="col">
                                <input type="text" class="form-control" name="storage_path" value="<?= htmlspecialchars($settings['storage_path'] ?? '') ?>" readonly>
                            </div>
                            <div class="col-auto">
                                <div class="input-group" style="width: 140px;">
                                    <span class="input-group-text small">Alert at</span>
                                    <input type="number" class="form-control text-center" name="storage_alert_threshold"
                                           value="<?= htmlspecialchars($settings['storage_alert_threshold'] ?? '90') ?>" min="50" max="99" style="width: 60px;">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                        </div>
                        <div class="form-text">Base directory for borg repositories. Currently <?= $storageUsagePercent ?? 0 ?>% used.</div>
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
                <div class="card-header bg-body fw-semibold">
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
                <div class="card-header bg-body fw-semibold">
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
            <div class="card-header bg-body fw-semibold">
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
$eventTypes = [
    'backup_failed' => 'Backup Failed',
    'agent_offline' => 'Client Offline',
    'storage_low' => 'Storage Low',
    'missed_schedule' => 'Missed Schedule',
];
$eventColors = [
    'backup_failed' => 'danger',
    'agent_offline' => 'warning',
    'storage_low' => 'info',
    'missed_schedule' => 'secondary',
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
        <div class="card-header bg-body fw-semibold">
            <i class="bi bi-plus-circle me-1"></i> Add Notification Service
        </div>
        <div class="card-body">
            <form method="POST" action="/notification-services">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Service Name</label>
                        <input type="text" class="form-control" name="name" placeholder="e.g., Discord Alerts" required>
                        <div class="form-text">A friendly name to identify this service</div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Apprise URL</label>
                        <input type="text" class="form-control font-monospace" name="apprise_url"
                               placeholder="discord://webhook_id/webhook_token" required>
                        <div class="form-text">
                            See <a href="https://github.com/caronc/apprise/wiki#notification-services" target="_blank">
                            Apprise documentation</a> for URL formats
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Notify on:</label>
                    <div class="row">
                        <?php foreach ($eventTypes as $event => $label): ?>
                        <div class="col-md-3 col-6 mb-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="events[<?= $event ?>]"
                                       value="1" id="addEvent_<?= $event ?>" checked>
                                <label class="form-check-label" for="addEvent_<?= $event ?>">
                                    <?= htmlspecialchars($label) ?>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
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
    <div class="table-responsive">
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
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($service['events'] as $event => $enabled): ?>
                                <?php if ($enabled): ?>
                                <?php $color = $eventColors[$event] ?? 'secondary'; ?>
                                <span class="badge bg-<?= $color ?>-subtle text-<?= $color ?> border border-<?= $color ?>-subtle">
                                    <?= htmlspecialchars($eventTypes[$event] ?? ucfirst(str_replace('_', ' ', $event))) ?>
                                </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (empty(array_filter($service['events']))): ?>
                            <span class="text-muted small">No events selected</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($service['last_used_at']): ?>
                        <span class="small text-muted" title="<?= htmlspecialchars($service['last_used_at']) ?>">
                            <?= date('M j, Y', strtotime($service['last_used_at'])) ?><br>
                            <?= date('g:i A', strtotime($service['last_used_at'])) ?>
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
                                    <?php foreach ($eventTypes as $event => $label): ?>
                                    <div class="col-md-3 col-6 mb-2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="events[<?= $event ?>]"
                                                   value="1" id="editEvent_<?= $service['id'] ?>_<?= $event ?>"
                                                   <?= ($service['events'][$event] ?? false) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="editEvent_<?= $service['id'] ?>_<?= $event ?>">
                                                <?= htmlspecialchars($label) ?>
                                            </label>
                                        </div>
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
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
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
</script>
<?php endif; ?>


<!-- Templates Tab -->
<?php if ($activeTab === 'templates'): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-body fw-semibold">
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
<?php if ($activeTab === 'borg'):
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
            <div class="card-header bg-body fw-semibold">
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
            <div class="card-header bg-body fw-semibold d-flex justify-content-between align-items-center">
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
            <div class="card-header bg-body fw-semibold">
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

<!-- Offsite Storage Tab -->
<?php if ($activeTab === 'offsite'): ?>
<form method="POST" action="/settings/offsite-storage">
    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-body fw-semibold">
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
                <div class="card-header bg-body fw-semibold">
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
            <div class="card-header bg-body fw-semibold">
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
            <div class="card-header bg-body fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-pc-display me-1"></i> BBS Client</span>
                <span class="badge bg-primary" id="agent-bundled-ver">v<?= htmlspecialchars($bundledAgentVersion) ?></span>
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
            <div class="card-header bg-body fw-semibold">
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
            <div class="card-header bg-body fw-semibold">
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
    <div class="card-header bg-body fw-semibold">
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
