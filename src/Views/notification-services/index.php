<?php
$eventColors = [
    'backup_failed' => 'danger',
    'agent_offline' => 'warning',
    'storage_low' => 'info',
    'missed_schedule' => 'secondary',
];
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-1">Notification Services</h4>
        <p class="text-muted mb-0 small">Configure notification services for backup and restore alerts</p>
    </div>
    <button class="btn btn-success" data-bs-toggle="collapse" data-bs-target="#addServiceForm">
        <i class="bi bi-plus-circle me-1"></i> Add Service
    </button>
</div>

<!-- Info banner -->
<div class="alert alert-light border mb-4">
    <div class="d-flex align-items-start">
        <i class="bi bi-info-circle text-primary me-2 mt-1"></i>
        <div>
            <span>Get notified about backup failures, restore completions, and scheduled job issues via 100+ services including Email, Slack, Discord, Telegram, Pushover, and more.</span>
            <a class="d-block mt-1 small" data-bs-toggle="collapse" href="#urlExamples" role="button">
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
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

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
<?php if (!empty($services)): ?>
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
                <?php foreach ($services as $service): ?>
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
                        <button class="btn btn-sm btn-outline-primary border-0" onclick="testService(<?= $service['id'] ?>, this)" title="Test">
                            <i class="bi bi-lightning"></i>
                        </button>
                        <form method="POST" action="/notification-services/<?= $service['id'] ?>/duplicate" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
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
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
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
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

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
                                    <form method="POST" action="/notification-services/<?= $service['id'] ?>/toggle" class="d-inline toggle-form">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
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
    <div id="testToast" class="toast align-items-center border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="testToastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
function testService(id, btn) {
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

        const toast = document.getElementById('testToast');
        const toastBody = document.getElementById('testToastBody');

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

// Handle toggle form submission with page reload
document.querySelectorAll('.toggle-form').forEach(form => {
    form.addEventListener('submit', function(e) {
        // Let it submit normally and reload
    });
});
</script>
