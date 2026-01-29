<?php
$statusClass = match($job['status']) {
    'completed' => 'success',
    'failed', 'cancelled' => 'danger',
    'running' => 'info',
    'sent' => 'primary',
    default => 'warning',
};

$d = $job['duration_seconds'] ?? 0;
$durLabel = $d >= 3600 ? floor($d / 3600) . 'h ' . floor(($d % 3600) / 60) . 'm'
    : ($d >= 60 ? floor($d / 60) . 'm ' . ($d % 60) . 's' : ($d > 0 ? $d . 's' : '--'));

$pct = 0;
if (($job['files_total'] ?? 0) > 0 && $job['files_processed'] > 0) {
    $pct = round(($job['files_processed'] / $job['files_total']) * 100);
}
$bytesPct = 0;
if (($job['bytes_total'] ?? 0) > 0 && $job['bytes_processed'] > 0) {
    $bytesPct = round(($job['bytes_processed'] / $job['bytes_total']) * 100);
}

function formatBytes($bytes) {
    if (!$bytes || $bytes == 0) return '--';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
    return round($bytes, $i > 0 ? 1 : 0) . ' ' . $units[$i];
}

$isActive = in_array($job['status'], ['queued', 'sent', 'running']);
?>

<div class="d-flex align-items-center mb-4">
    <a href="/queue" class="btn btn-sm btn-outline-secondary me-3"><i class="bi bi-arrow-left"></i> Queue</a>
    <h4 class="mb-0">
        Job #<?= $job['id'] ?>
        <span class="badge bg-<?= $statusClass ?> fs-6 ms-2"><?= ucfirst($job['status']) ?></span>
    </h4>
</div>

<!-- Progress Bar (for active jobs) -->
<?php if ($isActive): ?>
<div class="card border-0 shadow-sm mb-4" style="background-color: #2c3e50;">
    <div class="card-body py-3">
        <?php if ($job['status'] === 'running' && $pct > 0): ?>
            <div class="text-white fw-semibold mb-1">Backing up... <?= $pct ?>%</div>
            <div class="progress mb-1" style="height: 22px; background-color: rgba(255,255,255,0.15);">
                <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                     style="width: <?= $pct ?>%; background-color: #5b9bd5;" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
                    <?= number_format($job['files_processed']) ?> / <?= number_format($job['files_total']) ?> files
                </div>
            </div>
            <div class="text-white-50 small">
                <?= formatBytes($job['bytes_processed']) ?> of <?= formatBytes($job['bytes_total']) ?> processed
            </div>
        <?php elseif ($job['status'] === 'running'): ?>
            <div class="text-white fw-semibold mb-1">Backup in progress...</div>
            <div class="progress mb-1" style="height: 22px; background-color: rgba(255,255,255,0.15);">
                <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                     style="width: 100%; background-color: #5b9bd5;">
                    Running
                </div>
            </div>
            <div class="text-white-50 small">Waiting for progress data from agent</div>
        <?php elseif ($job['status'] === 'sent'): ?>
            <div class="text-white fw-semibold mb-1">Waiting for Agent</div>
            <div class="progress mb-1" style="height: 22px; background-color: rgba(255,255,255,0.15);">
                <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                     style="width: 100%; background-color: #5b9bd5;">
                    Sent to agent
                </div>
            </div>
            <div class="text-white-50 small">
                <?php if ($job['agent_status'] === 'online'): ?>
                    <i class="bi bi-check-circle-fill text-success me-1"></i>
                    Agent "<strong><?= htmlspecialchars($job['agent_name']) ?></strong>" is online — waiting for next poll (every <?= $pollInterval ?>s)
                <?php elseif ($job['agent_status'] === 'offline'): ?>
                    <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>
                    Agent "<strong><?= htmlspecialchars($job['agent_name']) ?></strong>" is offline — job will be failed by scheduler if agent doesn't reconnect
                <?php else: ?>
                    <i class="bi bi-clock text-info me-1"></i>
                    Agent "<strong><?= htmlspecialchars($job['agent_name']) ?></strong>" status: <?= $job['agent_status'] ?> — waiting for agent to pick up task
                <?php endif; ?>
                <?php if ($job['last_heartbeat']): ?>
                    <br>Last heartbeat: <?= date('M j, g:i:s A', strtotime($job['last_heartbeat'])) ?>
                <?php else: ?>
                    <br>No heartbeat received yet — agent may not be installed
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php
            $queueFull = $activeCount >= $maxQueue;
            $agentOffline = $job['agent_status'] === 'offline';
            ?>
            <div class="text-white fw-semibold mb-1">Queued<?= $queuePosition ? " — Position #{$queuePosition}" : '' ?></div>
            <div class="progress mb-1" style="height: 22px; background-color: rgba(255,255,255,0.15);">
                <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                     style="width: 100%; background-color: #e67e22;">
                    Waiting
                </div>
            </div>
            <div class="text-white-50 small">
                <?php if ($agentOffline && $queueFull): ?>
                    <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>
                    Agent "<strong><?= htmlspecialchars($job['agent_name']) ?></strong>" is offline AND queue is full (<?= $activeCount ?>/<?= $maxQueue ?> slots used)
                <?php elseif ($agentOffline): ?>
                    <i class="bi bi-wifi-off text-warning me-1"></i>
                    Waiting for agent "<strong><?= htmlspecialchars($job['agent_name']) ?></strong>" to come online
                    <?php if ($job['last_heartbeat']): ?>
                        — last seen <?= date('M j, g:i:s A', strtotime($job['last_heartbeat'])) ?>
                    <?php else: ?>
                        — never connected
                    <?php endif; ?>
                <?php elseif ($queueFull): ?>
                    <i class="bi bi-hourglass-split text-info me-1"></i>
                    Queue full — <?= $activeCount ?>/<?= $maxQueue ?> concurrent job slots in use. This job will start when a slot opens.
                <?php else: ?>
                    <i class="bi bi-clock text-info me-1"></i>
                    Waiting to be promoted to active — agent is <?= $job['agent_status'] ?>, <?= $activeCount ?>/<?= $maxQueue ?> slots used
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php elseif ($job['status'] === 'completed' && ($job['files_total'] ?? 0) > 0): ?>
<div class="card border-0 shadow-sm mb-4" style="background-color: #d4edda;">
    <div class="card-body py-3">
        <div class="fw-semibold text-success mb-1">Completed</div>
        <div class="progress mb-1" style="height: 22px;">
            <div class="progress-bar bg-success" role="progressbar" style="width: 100%;">
                <?= number_format($job['files_total']) ?> files processed
            </div>
        </div>
        <div class="text-muted small"><?= formatBytes($job['bytes_total']) ?> total &middot; <?= $durLabel ?></div>
    </div>
</div>
<?php elseif ($job['status'] === 'failed'): ?>
<div class="card border-0 shadow-sm mb-4" style="background-color: #f8d7da;">
    <div class="card-body py-3">
        <div class="fw-semibold text-danger mb-1">Failed</div>
        <div class="progress mb-1" style="height: 22px;">
            <div class="progress-bar bg-danger" role="progressbar" style="width: <?= max($pct, 100) ?>%;">
                Failed
            </div>
        </div>
        <?php if ($job['error_log']): ?>
        <div class="text-danger small mt-1"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars(substr($job['error_log'], 0, 200)) ?></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Job Details -->
<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-info-circle me-1"></i> Job Details
            </div>
            <div class="card-body p-0">
                <table class="table table-borderless mb-0">
                    <tbody>
                        <tr>
                            <td class="text-muted fw-semibold ps-3" style="width: 160px;">Client</td>
                            <td><a href="/clients/<?= $job['agent_id'] ?>"><?= htmlspecialchars($job['agent_name']) ?></a></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Task Type</td>
                            <td><i class="bi bi-<?= match($job['task_type']) {
                                'backup' => 'archive',
                                'prune' => 'scissors',
                                'restore' => 'arrow-counterclockwise',
                                'check' => 'shield-check',
                                'compact' => 'arrows-collapse',
                                'update_borg' => 'arrow-up-circle',
                                default => 'gear',
                            } ?> me-1"></i><?= ucfirst($job['task_type']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Repository</td>
                            <td><?= htmlspecialchars($job['repo_name'] ?? '--') ?></td>
                        </tr>
                        <?php if ($job['plan_name']): ?>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Backup Plan</td>
                            <td><?= htmlspecialchars($job['plan_name']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Queued At</td>
                            <td><?= $job['queued_at'] ? date('M j, Y g:i:s A', strtotime($job['queued_at'])) : '--' ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Started At</td>
                            <td><?= $job['started_at'] ? date('M j, Y g:i:s A', strtotime($job['started_at'])) : '--' ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Completed At</td>
                            <td><?= $job['completed_at'] ? date('M j, Y g:i:s A', strtotime($job['completed_at'])) : '--' ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Duration</td>
                            <td><?= $durLabel ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-bar-chart me-1"></i> Stats
            </div>
            <div class="card-body p-0">
                <table class="table table-borderless mb-0">
                    <tbody>
                        <tr>
                            <td class="text-muted fw-semibold ps-3" style="width: 160px;">Files Total</td>
                            <td><?= $job['files_total'] ? number_format($job['files_total']) : '--' ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Files Processed</td>
                            <td><?= $job['files_processed'] ? number_format($job['files_processed']) : '--' ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Bytes Total</td>
                            <td><?= formatBytes($job['bytes_total']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Bytes Processed</td>
                            <td><?= formatBytes($job['bytes_processed']) ?></td>
                        </tr>
                        <?php if ($job['directories']): ?>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Directories</td>
                            <td><code class="small"><?= htmlspecialchars(str_replace("\n", ', ', $job['directories'])) ?></code></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($job['advanced_options']): ?>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Borg Options</td>
                            <td><code class="small"><?= htmlspecialchars($job['advanced_options']) ?></code></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Error Log (if failed) -->
<?php if ($job['status'] === 'failed' && $job['error_log']): ?>
<div class="card border-0 shadow-sm mb-4 border-danger">
    <div class="card-header bg-white fw-semibold text-danger">
        <i class="bi bi-exclamation-triangle me-1"></i> Error Log
    </div>
    <div class="card-body">
        <pre class="mb-0 small text-danger" style="white-space: pre-wrap;"><?= htmlspecialchars($job['error_log']) ?></pre>
    </div>
</div>
<?php endif; ?>

<!-- Server Log -->
<?php if (!empty($logs)): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-journal-text me-1"></i> Activity Log
    </div>
    <div class="card-body p-0">
        <div class="list-group list-group-flush">
            <?php foreach ($logs as $log): ?>
            <?php
            $logIcon = match($log['level']) {
                'error' => 'x-circle-fill text-danger',
                'warning' => 'exclamation-triangle-fill text-warning',
                'info' => 'info-circle-fill text-info',
                default => 'circle text-secondary',
            };
            ?>
            <div class="list-group-item d-flex align-items-start py-2 px-3">
                <i class="bi bi-<?= $logIcon ?> me-2 mt-1"></i>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between">
                        <span class="small"><?= htmlspecialchars($log['message']) ?></span>
                        <small class="text-muted ms-3 text-nowrap"><?= date('M j g:i:s A', strtotime($log['created_at'])) ?></small>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Actions -->
<div class="d-flex gap-2">
    <?php if (in_array($job['status'], ['queued', 'sent'])): ?>
    <form method="POST" action="/queue/<?= $job['id'] ?>/cancel" onsubmit="return confirm('Cancel this job?')">
        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
        <button class="btn btn-danger"><i class="bi bi-x-circle me-1"></i> Cancel Job</button>
    </form>
    <?php endif; ?>
    <?php if ($job['status'] === 'failed'): ?>
    <form method="POST" action="/queue/<?= $job['id'] ?>/retry" onsubmit="return confirm('Retry this job?')">
        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
        <button class="btn btn-warning"><i class="bi bi-arrow-repeat me-1"></i> Retry Job</button>
    </form>
    <?php endif; ?>
    <?php if ($isActive): ?>
    <div class="text-muted small align-self-center ms-2">
        Job Stalled? Cancel and retry, or check the agent status on the <a href="/clients/<?= $job['agent_id'] ?>">client page</a>.
    </div>
    <?php endif; ?>
</div>

<?php if ($isActive): ?>
<script>
// Auto-refresh for active jobs
setTimeout(function() { location.reload(); }, 10000);
</script>
<?php endif; ?>
