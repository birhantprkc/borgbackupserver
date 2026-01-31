<?php
$tab = $_GET['tab'] ?? 'status';

// Detect server's agent version from the bundled bbs-agent.py
$serverAgentVersion = null;
$agentFile = dirname(__DIR__, 3) . '/agent/bbs-agent.py';
if (file_exists($agentFile)) {
    $handle = fopen($agentFile, 'r');
    if ($handle) {
        // Only read first 50 lines to find AGENT_VERSION
        for ($i = 0; $i < 50 && ($line = fgets($handle)) !== false; $i++) {
            if (preg_match('/^AGENT_VERSION\s*=\s*["\']([^"\']+)["\']/m', $line, $m)) {
                $serverAgentVersion = $m[1];
                break;
            }
        }
        fclose($handle);
    }
}
$agentNeedsUpdate = $serverAgentVersion && $agent['agent_version'] && $agent['agent_version'] !== $serverAgentVersion;
?>

<!-- Client Header -->
<?php
$statusClass = match($agent['status']) {
    'online' => 'success',
    'offline' => 'secondary',
    'error' => 'danger',
    default => 'warning',
};
$sizeDisplay = $totalSize >= 1073741824 ? round($totalSize / 1073741824, 1) . ' GB'
    : ($totalSize >= 1048576 ? round($totalSize / 1048576, 1) . ' MB' : '0');
?>
<div class="card border-0 shadow-sm mb-4 card-no-outline">
    <div class="card-body pb-2">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <div class="d-flex align-items-center gap-2">
                    <h3 class="mb-0">
                        <i class="bi bi-display me-2 text-primary"></i><?= htmlspecialchars($agent['name']) ?>
                    </h3>
                    <span class="badge bg-<?= $statusClass ?> fs-6" id="agent-status-badge"><?= ucfirst($agent['status']) ?></span>
                    <button class="btn btn-sm btn-outline-secondary border-0" data-bs-toggle="collapse" data-bs-target="#edit-client" title="Edit client">
                        <i class="bi bi-pencil"></i>
                    </button>
                </div>
                <div class="text-muted mt-1 client-header-info d-flex flex-wrap gap-1">
                    <?php if ($agent['hostname']): ?>
                        <span><i class="bi bi-hdd-network me-1"></i><?= htmlspecialchars($agent['hostname']) ?></span>
                        <?php if ($agent['ip_address'] ?? null): ?>
                            <span class="d-none d-sm-inline ms-1"><i class="bi bi-globe me-1"></i><?= htmlspecialchars($agent['ip_address']) ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($agent['os_info']): ?>
                        <span class="d-none d-md-inline"><i class="bi bi-cpu me-1"></i><?= htmlspecialchars($agent['os_info']) ?></span>
                    <?php endif; ?>
                    <?php if ($agent['owner_name']): ?>
                        <span class="d-md-none"><i class="bi bi-person me-1"></i><?= htmlspecialchars($agent['owner_name']) ?></span>
                    <?php endif; ?>
                    <span>
                    <?php if ($agent['agent_version']): ?>
                        <?php if ($agentNeedsUpdate): ?>
                            <form method="POST" action="/clients/<?= $agent['id'] ?>/update-agent" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <button type="submit" class="btn btn-link text-warning p-0 text-decoration-none" style="font-size: inherit;" title="Update agent to v<?= htmlspecialchars($serverAgentVersion) ?>" onclick="return confirm('Queue an agent update to v<?= htmlspecialchars($serverAgentVersion) ?>?')">
                                    <i class="bi bi-box me-1"></i>Agent v<?= htmlspecialchars($agent['agent_version']) ?> <i class="bi bi-arrow-up-circle-fill"></i>
                                </button>
                            </form>
                        <?php else: ?>
                            <i class="bi bi-box me-1"></i>Agent v<?= htmlspecialchars($agent['agent_version']) ?>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($agent['borg_version']): ?>
                        <span class="ms-2">
                            <form method="POST" action="/clients/<?= $agent['id'] ?>/update-borg" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <button type="submit" class="btn btn-link text-muted p-0 text-decoration-none" style="font-size: inherit;" title="Update Borg on this client" onclick="return confirm('Queue a borg update on this client?')">
                                    <i class="bi bi-archive me-1"></i>Borg <?= htmlspecialchars($agent['borg_version']) ?>
                                </button>
                            </form>
                        </span>
                    <?php endif; ?>
                    </span>
                </div>
            </div>
            <?php if ($agent['owner_name']): ?>
            <div class="text-end text-muted small d-none d-md-block">
                <i class="bi bi-person me-1"></i>Owner: <strong><?= htmlspecialchars($agent['owner_name']) ?></strong>
            </div>
            <?php endif; ?>
        </div>

        <!-- Stats row — icon cards -->
        <?php
        if ($agent['last_heartbeat']) {
            $seenAgo = \BBS\Core\TimeHelper::ago($agent['last_heartbeat']);
        } else {
            $seenAgo = 'Never';
        }
        $lastBackupLabel = $lastJob ? \BBS\Core\TimeHelper::format($lastJob['completed_at'], 'M j g:ia') : '--';
        $lastBackupIcon = $lastJob ? ($lastJob['status'] === 'completed' ? 'check-circle-fill' : 'x-circle-fill') : 'dash-circle';
        $lastBackupColor = $lastJob ? ($lastJob['status'] === 'completed' ? 'success' : 'danger') : 'secondary';
        ?>
        <div class="row g-2 border-top pt-3">
            <div class="col-6 col-sm-4 col-lg-2">
                <div class="d-flex align-items-center p-2 rounded bg-light">
                    <div class="stat-icon-sm bg-primary bg-opacity-10 text-primary rounded-2 p-2 me-2">
                        <i class="bi bi-archive"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?= count($repositories) ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;">Repos</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-sm-4 col-lg-2">
                <div class="d-flex align-items-center p-2 rounded bg-light">
                    <div class="stat-icon-sm bg-info bg-opacity-10 text-info rounded-2 p-2 me-2">
                        <i class="bi bi-stack"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?= $totalArchives ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;">Archives</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-sm-4 col-lg-2">
                <div class="d-flex align-items-center p-2 rounded bg-light">
                    <div class="stat-icon-sm bg-success bg-opacity-10 text-success rounded-2 p-2 me-2">
                        <i class="bi bi-hdd"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?= $sizeDisplay ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;">Size</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-sm-4 col-lg-2">
                <div class="d-flex align-items-center p-2 rounded bg-light">
                    <div class="stat-icon-sm bg-warning bg-opacity-10 text-warning rounded-2 p-2 me-2">
                        <i class="bi bi-clipboard-check"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?= count($plans) ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;">Plans</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-sm-4 col-lg-2">
                <div class="d-flex align-items-center p-2 rounded bg-light">
                    <div class="stat-icon-sm bg-<?= $lastBackupColor ?> bg-opacity-10 text-<?= $lastBackupColor ?> rounded-2 p-2 me-2">
                        <i class="bi bi-<?= $lastBackupIcon ?>"></i>
                    </div>
                    <div>
                        <div class="fw-bold" style="font-size: 0.85rem;"><?= $lastBackupLabel ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;">Last Backup</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-sm-4 col-lg-2">
                <div class="d-flex align-items-center p-2 rounded bg-light" id="agent-last-seen-card">
                    <div class="stat-icon-sm bg-<?= $statusClass ?> bg-opacity-10 text-<?= $statusClass ?> rounded-2 p-2 me-2" id="agent-last-seen-icon">
                        <i class="bi bi-broadcast"></i>
                    </div>
                    <div>
                        <div class="fw-bold" id="agent-last-seen"><?= $seenAgo ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;">Last Seen</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Collapsible edit form -->
    <div class="collapse" id="edit-client">
        <div class="card-body border-top bg-light">
            <form method="POST" action="/clients/<?= $agent['id'] ?>/edit" class="row g-3 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                <div class="col-md-4">
                    <label class="form-label fw-semibold small">Client Name</label>
                    <input type="text" class="form-control form-control-sm" name="name" value="<?= htmlspecialchars($agent['name']) ?>" required>
                </div>
                <?php if ($this->isAdmin() && !empty($users)): ?>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small">Owner</label>
                    <select name="user_id" class="form-select form-select-sm">
                        <option value="">No owner</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= ($agent['user_id'] ?? '') == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary">Save</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#edit-client">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Sub-tabs -->
<ul class="nav nav-pills client-tabs mb-0 flex-wrap">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'status' ? 'active' : '' ?>" href="?tab=status">
            <i class="bi bi-activity me-1"></i><span class="tab-label">Status</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'repos' ? 'active' : '' ?>" href="?tab=repos">
            <i class="bi bi-archive me-1"></i><span class="tab-label">Repos</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'schedules' ? 'active' : '' ?>" href="?tab=schedules">
            <i class="bi bi-calendar-event me-1"></i><span class="tab-label">Schedule</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'restore' ? 'active' : '' ?>" href="?tab=restore">
            <i class="bi bi-arrow-counterclockwise me-1"></i><span class="tab-label">Restore</span>
        </a>
    </li>
    <?php if ($this->isAdmin()): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'install' ? 'active' : '' ?>" href="?tab=install">
            <i class="bi bi-download me-1"></i><span class="tab-label">Install</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link text-danger <?= $tab === 'delete' ? 'active' : '' ?>" href="?tab=delete">
            <i class="bi bi-trash me-1"></i><span class="tab-label">Delete</span>
        </a>
    </li>
    <?php endif; ?>
</ul>
<div class="client-tab-content border rounded-bottom bg-white p-4 mb-4 shadow-sm">
<!-- Tab Content -->
<?php if ($tab === 'status'): ?>
    <?php
    // Compute status metrics
    $avgDuration = (int) ($jobStats['avg_duration'] ?? 0);
    $avgDurLabel = $avgDuration >= 60 ? floor($avgDuration / 60) . 'm ' . ($avgDuration % 60) . 's' : $avgDuration . 's';
    $successRate = ($jobStats['total'] ?? 0) > 0
        ? round(($jobStats['completed'] / $jobStats['total']) * 100)
        : 0;
    $nextRunLabel = '--';
    $nextRunSub = 'No schedule';
    // Count paused schedules
    $pausedCount = 0;
    $totalSchedules = 0;
    foreach ($plans as $p) {
        if ($p['schedule_id'] ?? null) {
            $totalSchedules++;
            if (!($p['schedule_enabled'] ?? false)) $pausedCount++;
        }
    }
    if ($nextBackup && $nextBackup['next_run']) {
        $nextDiff = strtotime($nextBackup['next_run']) - time();
        if ($nextDiff < 0) $nextRunLabel = 'Overdue';
        elseif ($nextDiff < 3600) $nextRunLabel = floor($nextDiff / 60) . 'm';
        elseif ($nextDiff < 86400) $nextRunLabel = floor($nextDiff / 3600) . 'h ' . floor(($nextDiff % 3600) / 60) . 'm';
        else $nextRunLabel = floor($nextDiff / 86400) . 'd ' . floor(($nextDiff % 86400) / 3600) . 'h';
        $nextRunSub = htmlspecialchars($nextBackup['plan_name']);
    } elseif ($pausedCount > 0) {
        $nextRunLabel = 'No Jobs';
        $nextRunSub = $pausedCount . ' Paused Schedule' . ($pausedCount > 1 ? 's' : '');
    }
    $successColor = $successRate >= 90 ? 'success' : ($successRate >= 70 ? 'warning' : 'danger');
    ?>

    <!-- Row 1: Key Metrics -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="background-color: rgba(13,110,253,0.05);">
                <div class="card-body d-flex align-items-center position-relative">
                    <?php if ($nextBackup && $nextBackup['plan_id']): ?>
                    <div class="dropdown position-absolute top-0 end-0 mt-2 me-2">
                        <button class="btn btn-sm btn-light border-0" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <form method="POST" action="/plans/<?= $nextBackup['plan_id'] ?>/trigger">
                                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                    <button type="submit" class="dropdown-item"><i class="bi bi-play-fill text-success me-2"></i>Run Now</button>
                                </form>
                            </li>
                            <?php if ($nextBackup['schedule_id'] ?? null): ?>
                            <li>
                                <form method="POST" action="/schedules/<?= $nextBackup['schedule_id'] ?>/toggle">
                                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                    <button type="submit" class="dropdown-item"><i class="bi bi-pause-fill text-warning me-2"></i>Pause</button>
                                </form>
                            </li>
                            <?php endif; ?>
                            <li><a class="dropdown-item" href="?tab=schedules"><i class="bi bi-pencil text-primary me-2"></i>Edit</a></li>
                        </ul>
                    </div>
                    <?php elseif ($pausedCount > 0): ?>
                    <div class="position-absolute top-0 end-0 mt-2 me-2">
                        <a href="?tab=schedules" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;">
                            <i class="bi bi-pencil me-1"></i>Manage
                        </a>
                    </div>
                    <?php endif; ?>
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary rounded-3 p-3 me-3">
                        <i class="bi bi-clock fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Next Backup</div>
                        <div class="fs-4 fw-bold"><?= $nextRunLabel ?></div>
                        <div class="text-muted small"><?= $nextRunSub ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="background-color: rgba(13,202,240,0.05);">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-info bg-opacity-10 text-info rounded-3 p-3 me-3">
                        <i class="bi bi-stopwatch fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Avg Duration</div>
                        <div class="fs-4 fw-bold"><?= $avgDurLabel ?></div>
                        <div class="text-muted small">last 30 jobs</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="background-color: rgba(<?= $successColor === 'success' ? '25,135,84' : ($successColor === 'warning' ? '255,193,7' : '220,53,69') ?>,0.05);">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-<?= $successColor ?> bg-opacity-10 text-<?= $successColor ?> rounded-3 p-3 me-3">
                        <i class="bi bi-check2-all fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Success Rate</div>
                        <div class="fs-4 fw-bold"><?= $successRate ?>%</div>
                        <div class="text-muted small"><?= $jobStats['completed'] ?? 0 ?>/<?= $jobStats['total'] ?? 0 ?> jobs</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <?php $errColor = $recentErrors > 0 ? 'danger' : 'success'; ?>
            <div class="card border-0 shadow-sm h-100" style="background-color: rgba(<?= $errColor === 'danger' ? '220,53,69' : '25,135,84' ?>,0.05);">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-<?= $errColor ?> bg-opacity-10 text-<?= $errColor ?> rounded-3 p-3 me-3">
                        <i class="bi bi-exclamation-triangle fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Errors (7d)</div>
                        <div class="fs-4 fw-bold"><?= $recentErrors ?></div>
                        <div class="text-muted small">failed jobs</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 2: Charts -->
    <div class="row g-4 mb-4">
        <!-- Backup Duration Chart -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-bar-chart me-1"></i> Backup Duration (Last 30)
                </div>
                <div class="card-body">
                    <?php if (empty($durationChart)): ?>
                        <div class="text-muted text-center py-5">No backup data yet</div>
                    <?php else: ?>
                        <div style="position: relative; height: 220px;">
                            <canvas id="durationChart"></canvas>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- Storage by Repository -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-pie-chart me-1"></i> Storage by Repository
                </div>
                <div class="card-body">
                    <?php if (empty($repositories)): ?>
                        <div class="text-muted text-center py-5">No repositories yet</div>
                    <?php else: ?>
                        <div style="position: relative; height: 180px;">
                            <canvas id="storageChart"></canvas>
                        </div>
                        <div class="mt-3">
                            <?php foreach ($repositories as $repo): ?>
                            <div class="d-flex justify-content-between small mb-1">
                                <span><i class="bi bi-circle-fill me-1" style="font-size: 0.5rem; vertical-align: middle;"></i> <?= htmlspecialchars($repo['name']) ?></span>
                                <span class="fw-semibold">
                                    <?php
                                    $s = $repo['size_bytes'];
                                    echo $s >= 1073741824 ? round($s / 1073741824, 1) . ' GB' : ($s >= 1048576 ? round($s / 1048576, 1) . ' MB' : '0');
                                    ?>
                                    <span class="text-muted">(<?= $repo['archive_count'] ?> archives)</span>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 3: Recent Activity Timeline -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-clock-history me-1"></i> Recent Activity
        </div>
        <div class="card-body p-0">
            <?php if (empty($recentJobs)): ?>
            <div class="p-4 text-muted text-center">No activity yet.</div>
            <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach (array_slice($recentJobs, 0, 10) as $job): ?>
                <?php
                $jIcon = match($job['status']) {
                    'completed' => 'check-circle-fill text-success',
                    'failed' => 'x-circle-fill text-danger',
                    'running' => 'arrow-repeat text-info',
                    'queued','sent' => 'hourglass-split text-warning',
                    default => 'dash-circle text-secondary',
                };
                $d = $job['duration_seconds'] ?? 0;
                $durStr = $d >= 60 ? floor($d / 60) . 'm ' . ($d % 60) . 's' : ($d > 0 ? $d . 's' : '--');
                ?>
                <div class="list-group-item d-flex align-items-center py-2 px-3">
                    <i class="bi bi-<?= $jIcon ?> fs-5 me-3"></i>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between">
                            <span class="fw-semibold"><?= ucfirst($job['task_type']) ?></span>
                            <small class="text-muted"><?= \BBS\Core\TimeHelper::format($job['started_at'] ?? $job['queued_at'], 'M j g:ia') ?></small>
                        </div>
                        <div class="small text-muted">
                            <?= htmlspecialchars($job['repo_name'] ?? '') ?>
                            <?php if ($job['files_total']): ?>
                                &middot; <?= number_format($job['files_total']) ?> files
                            <?php endif; ?>
                            &middot; <?= $durStr ?>
                            <?php if ($job['status'] === 'failed' && $job['error_log']): ?>
                                <span class="text-danger ms-1" title="<?= htmlspecialchars(substr($job['error_log'], 0, 200)) ?>">
                                    &middot; <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars(substr($job['error_log'], 0, 80)) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($durationChart)): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script>
    // Duration Chart
    const durData = <?= json_encode($durationChart) ?>;
    new Chart(document.getElementById('durationChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: durData.map(d => d.label),
            datasets: [{
                label: 'Duration (seconds)',
                data: durData.map(d => d.duration_seconds),
                backgroundColor: durData.map(d => d.status === 'completed' ? 'rgba(25, 135, 84, 0.7)' : 'rgba(220, 53, 69, 0.7)'),
                borderColor: durData.map(d => d.status === 'completed' ? 'rgb(25, 135, 84)' : 'rgb(220, 53, 69)'),
                borderWidth: 1,
                borderRadius: 3,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        font: { size: 10 },
                        callback: function(v) {
                            return v >= 60 ? Math.floor(v/60) + 'm' : v + 's';
                        }
                    },
                    grid: { color: 'rgba(0,0,0,0.05)' },
                },
                x: {
                    ticks: {
                        font: { size: 9 },
                        maxRotation: 45,
                        callback: function(val, index) {
                            return index % Math.ceil(durData.length / 10) === 0 ? this.getLabelForValue(val) : '';
                        }
                    },
                    grid: { display: false },
                }
            }
        }
    });

    <?php if (!empty($repositories)): ?>
    // Storage Chart
    const repoData = <?= json_encode(array_map(fn($r) => ['name' => $r['name'], 'size' => $r['size_bytes']], $repositories)) ?>;
    const colors = ['#0d6efd', '#198754', '#ffc107', '#0dcaf0', '#6f42c1', '#fd7e14', '#d63384', '#20c997'];
    new Chart(document.getElementById('storageChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: repoData.map(r => r.name),
            datasets: [{
                data: repoData.map(r => r.size),
                backgroundColor: repoData.map((_, i) => colors[i % colors.length]),
                borderWidth: 2,
                borderColor: '#fff',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            const bytes = ctx.parsed;
                            if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
                            if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
                            return bytes + ' B';
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>
    </script>
    <?php endif; ?>

<?php elseif ($tab === 'repos'): ?>
    <h5 class="mb-3">Repositories</h5>

    <script>
    function showCreateRepo() {
        var grid = document.getElementById('repo-cards-grid');
        var solo = document.getElementById('add-repo-card-solo');
        var create = document.getElementById('create-repo-section');
        if (grid) grid.style.display = 'none';
        if (solo) solo.style.display = 'none';
        if (create) create.style.display = '';
    }
    function hideCreateRepo() {
        var grid = document.getElementById('repo-cards-grid');
        var solo = document.getElementById('add-repo-card-solo');
        var create = document.getElementById('create-repo-section');
        if (grid) grid.style.display = '';
        if (solo) solo.style.display = '';
        if (create) create.style.display = 'none';
    }
    </script>

    <?php if (!empty($repositories)): ?>
    <div id="repo-cards-grid" class="row g-3 mb-4">
        <?php foreach ($repositories as $repo):
            $s = $repo['size_bytes'];
            $sizeLabel = $s >= 1073741824 ? round($s / 1073741824, 1) . ' GB' : ($s >= 1048576 ? round($s / 1048576, 1) . ' MB' : ($s > 0 ? round($s / 1024, 1) . ' KB' : '--'));
            $repoPlanCount = 0;
            foreach ($plans as $p) { if (($p['repository_id'] ?? 0) == $repo['id']) $repoPlanCount++; }
            $repoActiveJobs = 0;
            foreach ($recentJobs as $j) { if (($j['repository_id'] ?? 0) == $repo['id'] && in_array($j['status'], ['queued', 'sent', 'running'])) $repoActiveJobs++; }
            $deleteBlocked = $repoPlanCount > 0 || $repoActiveJobs > 0;
            $blockReason = $repoPlanCount > 0
                ? "Delete the {$repoPlanCount} backup plan(s) using this repo first"
                : "Wait for {$repoActiveJobs} active job(s) to finish first";
        ?>
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100 repo-card">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center">
                        <div class="schedule-icon-wrap me-3 repo-icon-wrap">
                            <i class="bi bi-archive"></i>
                            <span class="schedule-id"><?= $repo['archive_count'] ?></span>
                        </div>
                        <div class="flex-grow-1 min-width-0">
                            <h6 class="mb-1 fw-bold"><?= htmlspecialchars($repo['name']) ?></h6>
                            <div class="small text-muted">
                                <i class="bi bi-hdd me-1"></i><?= $sizeLabel ?>
                            </div>
                            <div class="small text-muted">
                                <i class="bi bi-stack me-1"></i><?= $repo['archive_count'] ?> recovery points
                            </div>
                        </div>
                        <div class="ms-2">
                            <?php if ($deleteBlocked): ?>
                                <span data-bs-toggle="tooltip" title="<?= htmlspecialchars($blockReason) ?>">
                                    <button type="button" class="btn btn-sm btn-light border-0 text-muted" disabled><i class="bi bi-trash"></i></button>
                                </span>
                            <?php else: ?>
                                <form method="POST" action="/repositories/<?= $repo['id'] ?>/delete" class="d-inline" onsubmit="return confirm('PERMANENTLY delete repository &quot;<?= htmlspecialchars($repo['name']) ?>&quot;, all its archives, and the data on disk?\n\nThis action is NOT reversible.')">
                                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                    <button type="submit" class="btn btn-sm btn-light border-0 text-muted" title="Delete"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="repo-status-bar">
                    <?= $sizeLabel ?> &middot; <?= $repo['archive_count'] ?> archives
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100" style="cursor:pointer;border:2px dashed #ccc !important;background:#fafafa;" onclick="showCreateRepo()">
                <div class="card-body d-flex flex-column align-items-center justify-content-center text-muted p-4">
                    <i class="bi bi-plus-circle" style="font-size:2rem;"></i>
                    <div class="mt-2 fw-semibold">Add Repository</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (empty($repositories)): ?>
    <div id="add-repo-card-solo" style="cursor:pointer;" onclick="showCreateRepo()">
        <div class="card border-0 shadow-sm mb-4" style="border:2px dashed #ccc !important;background:#fafafa;">
            <div class="card-body d-flex flex-column align-items-center justify-content-center text-muted p-4">
                <i class="bi bi-plus-circle" style="font-size:2rem;"></i>
                <div class="mt-2 fw-semibold">Add Repository</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Create new repo -->
    <div id="create-repo-section" style="display:none;">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-plus-circle me-1"></i> Create New Repository</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="hideCreateRepo()"><i class="bi bi-arrow-left me-1"></i>Back</button>
        </div>
        <div class="card-body">
            <form method="POST" action="/repositories/create">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                <input type="hidden" name="agent_id" value="<?= $agent['id'] ?>">

                <div class="row mb-3">
                    <label class="col-md-3 col-form-label fw-semibold">Description</label>
                    <div class="col-md-6">
                        <input type="text" class="form-control" name="name" required maxlength="20" placeholder="RepoName">
                    </div>
                    <div class="col-md-3 form-text pt-2">Descriptive name for the repo. (Max 20 characters)</div>
                </div>

                <div class="row mb-3">
                    <label class="col-md-3 col-form-label fw-semibold">Encryption</label>
                    <div class="col-md-6">
                        <select class="form-select" name="encryption" id="encryptionSelect">
                            <option value="repokey-blake2">repokey-blake2 (Recommended)</option>
                            <option value="authenticated-blake2">authenticated-blake2 (Faster)</option>
                            <option value="repokey">repokey (AES-256)</option>
                            <option value="none">none</option>
                        </select>
                    </div>
                    <div class="col-md-3 form-text pt-2">
                        <a href="https://borgbackup.readthedocs.io/en/stable/usage/init.html" target="_blank">
                            <i class="bi bi-question-circle"></i> Borg encryption docs
                        </a>
                    </div>
                </div>

                <div class="row mb-3" id="passphraseRow">
                    <label class="col-md-3 col-form-label fw-semibold">Repo Password</label>
                    <div class="col-md-6">
                        <input type="text" class="form-control" name="passphrase" id="passphraseInput" value="<?php
                            $seg = [];
                            for ($i = 0; $i < 5; $i++) $seg[] = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
                            echo implode('-', $seg);
                        ?>">
                    </div>
                    <div class="col-md-3 form-text pt-2">Auto-generated. Stored encrypted on the server.</div>
                </div>

                <div class="row">
                    <div class="col-md-6 offset-md-3">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-plus-circle me-1"></i> Create Repo
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    </div>

    <script>
    document.getElementById('encryptionSelect').addEventListener('change', function() {
        document.getElementById('passphraseRow').style.display = this.value === 'none' ? 'none' : 'flex';
    });
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
    </script>

<?php elseif ($tab === 'schedules'): ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Backup Schedules</h5>
        <?php
        $enabledPluginsList = array_filter($agentPlugins, fn($p) => !empty($p['agent_enabled']));
        ?>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#managePluginsModal">
            <i class="bi bi-plug me-1"></i> Plugins<?php if (!empty($enabledPluginsList)): ?> <span class="badge bg-success"><?= count($enabledPluginsList) ?></span><?php endif; ?>
        </button>
    </div>

    <script>
    function showCreatePlan() {
        var grid = document.getElementById('schedule-cards-grid');
        var solo = document.getElementById('add-plan-card-solo');
        var create = document.getElementById('create-plan-section');
        if (grid) grid.style.display = 'none';
        if (solo) solo.style.display = 'none';
        if (create) create.style.display = '';
        // Collapse any open edit panels
        document.querySelectorAll('.edit-plan-panel.show').forEach(function(p) {
            bootstrap.Collapse.getOrCreateInstance(p).hide();
        });
    }
    function hideCreatePlan() {
        var grid = document.getElementById('schedule-cards-grid');
        var solo = document.getElementById('add-plan-card-solo');
        var create = document.getElementById('create-plan-section');
        if (grid) grid.style.display = '';
        if (solo) solo.style.display = '';
        if (create) create.style.display = 'none';
    }
    </script>

    <!-- Existing Plans -->
    <?php if (!empty($plans)): ?>
    <div id="schedule-cards-grid" class="row g-3 mb-4">
        <?php foreach ($plans as $plan):
            $freq = $plan['frequency'] ?? 'manual';
            $isActive = $plan['schedule_enabled'] ?? false;
            $isManual = $freq === 'manual';
            // Build schedule summary
            $schedSummary = ucfirst(str_replace(['10min','15min','30min'], ['Every 10 min','Every 15 min','Every 30 min'], $freq));
            if ($plan['times'] && in_array($freq, ['daily','weekly','monthly'])) {
                $schedSummary .= ' @ ' . htmlspecialchars($plan['times']);
            }
            if ($freq === 'weekly' && isset($plan['day_of_week'])) {
                $dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                $schedSummary = ($dayNames[$plan['day_of_week']] ?? '') . 's @ ' . htmlspecialchars($plan['times'] ?? '00:00');
            }
            if ($freq === 'monthly' && isset($plan['day_of_month'])) {
                $dom = $plan['day_of_month'];
                $domLabel = $dom === 'last' ? 'Last day' : $dom;
                $schedSummary = 'Monthly on ' . $domLabel . ' @ ' . htmlspecialchars($plan['times'] ?? '00:00');
            }
            $statusColor = $isActive ? 'success' : ($isManual ? 'info' : 'secondary');
            $statusLabel = $isActive ? 'Active' : ($isManual ? 'Manual' : 'Paused');
        ?>
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100 schedule-card">
                <div class="card-body p-3 position-relative">
                    <div class="dropdown position-absolute" style="top:8px;right:8px;z-index:10;">
                        <button class="btn btn-sm btn-light border-0" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <form method="POST" action="/plans/<?= $plan['id'] ?>/trigger">
                                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                    <button type="submit" class="dropdown-item"><i class="bi bi-play-fill text-success me-2"></i>Run Now</button>
                                </form>
                            </li>
                            <?php if ($plan['schedule_id']): ?>
                            <li>
                                <form method="POST" action="/schedules/<?= $plan['schedule_id'] ?>/toggle">
                                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                    <?php if ($isActive): ?>
                                    <button type="submit" class="dropdown-item"><i class="bi bi-pause-fill text-warning me-2"></i>Pause</button>
                                    <?php else: ?>
                                    <button type="submit" class="dropdown-item"><i class="bi bi-play-fill text-secondary me-2"></i>Resume</button>
                                    <?php endif; ?>
                                </form>
                            </li>
                            <?php endif; ?>
                            <li><button class="dropdown-item" type="button" data-bs-toggle="collapse" data-bs-target="#edit-plan-<?= $plan['id'] ?>"><i class="bi bi-pencil text-primary me-2"></i>Edit</button></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="/plans/<?= $plan['id'] ?>/delete" onsubmit="return confirm('Delete this backup plan and its schedule?')">
                                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                    <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Delete</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                    <div class="d-flex align-items-center">
                        <div class="schedule-icon-wrap me-3 <?= $isActive ? 'schedule-active' : ($isManual ? 'schedule-manual' : 'schedule-paused') ?>">
                            <i class="bi bi-calendar-event"></i>
                            <span class="schedule-id">#<?= $plan['id'] ?></span>
                        </div>
                        <div class="flex-grow-1 min-width-0">
                            <h6 class="mb-1 fw-bold"><?= htmlspecialchars($plan['name']) ?></h6>
                            <div class="small text-muted">
                                <i class="bi bi-clock me-1"></i><?= $schedSummary ?>
                            </div>
                            <div class="small text-muted">
                                <i class="bi bi-archive me-1"></i><?= htmlspecialchars($plan['repo_name'] ?? '--') ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="schedule-status-bar bg-<?= $statusColor ?>">
                    Current Status: <?= $statusLabel ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (!empty($repositories)): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100 schedule-card" id="add-plan-card" style="cursor:pointer;border:2px dashed #ccc !important;background:#fafafa;" onclick="showCreatePlan()">
                <div class="card-body d-flex flex-column align-items-center justify-content-center text-muted p-4">
                    <i class="bi bi-plus-circle" style="font-size:2rem;"></i>
                    <div class="mt-2 fw-semibold">Add Backup Plan</div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (empty($plans) && !empty($repositories)): ?>
    <div id="add-plan-card-solo" style="cursor:pointer;" onclick="showCreatePlan()">
        <div class="card border-0 shadow-sm mb-4" style="border:2px dashed #ccc !important;background:#fafafa;">
            <div class="card-body d-flex flex-column align-items-center justify-content-center text-muted p-4">
                <i class="bi bi-plus-circle" style="font-size:2rem;"></i>
                <div class="mt-2 fw-semibold">Add Backup Plan</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Edit Plan Forms (full layout, one per plan) -->
    <?php foreach ($plans as $plan): ?>
    <?php
    $editOpts = $plan['advanced_options'] ?? '';
    $editHasCompression = str_contains($editOpts, '--compression');
    $editCompType = 'lz4';
    if (preg_match('/--compression\s+(\S+)/', $editOpts, $m)) $editCompType = $m[1];
    ?>
    <div class="collapse edit-plan-panel" id="edit-plan-<?= $plan['id'] ?>">
        <div class="card border-0 shadow-sm mb-4 border-primary">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span><i class="bi bi-pencil me-1"></i> Edit: <?= htmlspecialchars($plan['name']) ?></span>
                <button type="button" class="btn-close" data-bs-toggle="collapse" data-bs-target="#edit-plan-<?= $plan['id'] ?>"></button>
            </div>
            <div class="card-body">
                <form method="POST" action="/plans/<?= $plan['id'] ?>/edit" class="edit-plan-form">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">

                    <div class="row mb-3">
                        <label class="col-md-3 col-form-label fw-semibold">Plan Name</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($plan['name']) ?>" required>
                        </div>
                    </div>

                    <?php
                    $editFreq = $plan['frequency'] ?? 'daily';
                    $editTimes = $plan['times'] ?? '';
                    $editDow = $plan['day_of_week'] ?? 1;
                    $editDom = $plan['day_of_month'] ?? '1';
                    // Parse times to get selected hours and minute offset
                    $editTimeList = array_filter(array_map('trim', explode(',', $editTimes)));
                    $editMinuteOffset = 0;
                    $editSelectedHours = [];
                    foreach ($editTimeList as $t) {
                        $tp = explode(':', $t);
                        $editSelectedHours[] = (int)$tp[0];
                        $editMinuteOffset = (int)($tp[1] ?? 0);
                    }
                    ?>
                    <div class="row mb-3">
                        <label class="col-md-3 col-form-label fw-semibold"><i class="bi bi-clock me-1"></i> Frequency</label>
                        <div class="col-md-6">
                            <select class="form-select schedule-frequency" name="frequency">
                                <option value="manual" <?= $editFreq === 'manual' ? 'selected' : '' ?>>Manually (On Demand)</option>
                                <option value="10min" <?= $editFreq === '10min' ? 'selected' : '' ?>>Every 10 Minutes</option>
                                <option value="15min" <?= $editFreq === '15min' ? 'selected' : '' ?>>Every 15 Minutes</option>
                                <option value="30min" <?= $editFreq === '30min' ? 'selected' : '' ?>>Every 30 Minutes</option>
                                <option value="hourly" <?= $editFreq === 'hourly' ? 'selected' : '' ?>>Every Hour</option>
                                <option value="daily" <?= $editFreq === 'daily' ? 'selected' : '' ?>>Every Day</option>
                                <option value="weekly" <?= $editFreq === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                <option value="monthly" <?= $editFreq === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3 schedule-hourly-row <?= $editFreq !== 'hourly' ? 'd-none' : '' ?>">
                        <label class="col-md-3 col-form-label fw-semibold"><i class="bi bi-clock-history me-1"></i> Minute Offset</label>
                        <div class="col-md-6">
                            <div class="input-group" style="max-width:260px">
                                <span class="input-group-text">@</span>
                                <input type="number" class="form-control schedule-minute-offset" min="0" max="59" value="<?= $editMinuteOffset ?>">
                                <span class="input-group-text">min past the hour</span>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3 schedule-daily-row <?= $editFreq !== 'daily' ? 'd-none' : '' ?>">
                        <label class="col-md-3 col-form-label fw-semibold"><i class="bi bi-grid-3x3 me-1"></i> Run Hours</label>
                        <div class="col-md-9">
                            <div class="mb-2 d-flex align-items-center hour-picker-row">
                                <span class="text-muted fw-semibold me-2" style="display:inline-block;width:30px;">AM</span>
                                <div class="btn-group btn-group-sm">
                                    <?php for ($h = 0; $h < 12; $h++): $label = $h === 0 ? '12' : str_pad($h, 2, '0', STR_PAD_LEFT); ?>
                                    <button type="button" class="btn <?= in_array($h, $editSelectedHours) ? 'btn-primary active' : 'btn-outline-primary' ?> hour-btn" data-hour="<?= $h ?>"><?= $label ?></button>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <div class="mb-2 d-flex align-items-center hour-picker-row">
                                <span class="text-muted fw-semibold me-2" style="display:inline-block;width:30px;">PM</span>
                                <div class="btn-group btn-group-sm">
                                    <?php for ($h = 12; $h < 24; $h++): $label = $h === 12 ? '12' : str_pad($h - 12, 2, '0', STR_PAD_LEFT); ?>
                                    <button type="button" class="btn <?= in_array($h, $editSelectedHours) ? 'btn-primary active' : 'btn-outline-primary' ?> hour-btn" data-hour="<?= $h ?>"><?= $label ?></button>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <div class="input-group mt-2" style="max-width:260px">
                                <span class="input-group-text">@</span>
                                <input type="number" class="form-control schedule-minute-offset" min="0" max="59" value="<?= $editMinuteOffset ?>">
                                <span class="input-group-text">min past the hour</span>
                            </div>
                            <input type="hidden" name="times" class="schedule-times-hidden" value="<?= htmlspecialchars($editTimes) ?>">
                        </div>
                    </div>

                    <div class="row mb-3 schedule-weekly-row <?= $editFreq !== 'weekly' ? 'd-none' : '' ?>">
                        <label class="col-md-3 col-form-label fw-semibold"><i class="bi bi-calendar-week me-1"></i> Day & Time</label>
                        <div class="col-md-9">
                            <div class="btn-group btn-group-sm mb-2 schedule-day-btns">
                                <?php $dayLabels = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']; ?>
                                <?php foreach ($dayLabels as $di => $dl): ?>
                                <button type="button" class="btn <?= (int)$editDow === $di ? 'btn-primary active' : 'btn-outline-primary' ?> day-btn" data-day="<?= $di ?>"><?= $dl ?></button>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="day_of_week" class="schedule-dow-hidden" value="<?= (int)$editDow ?>">
                            <div class="input-group" style="max-width:220px">
                                <span class="input-group-text">@ time of day</span>
                                <input type="time" class="form-control schedule-time-input" value="<?= htmlspecialchars($editTimes ?: '00:00') ?>">
                            </div>
                            <input type="hidden" name="times" class="schedule-weekly-times-hidden" value="<?= htmlspecialchars($editTimes) ?>">
                        </div>
                    </div>

                    <div class="row mb-3 schedule-monthly-row <?= $editFreq !== 'monthly' ? 'd-none' : '' ?>">
                        <label class="col-md-3 col-form-label fw-semibold"><i class="bi bi-calendar-event me-1"></i> Day & Time</label>
                        <div class="col-md-9">
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <select class="form-select form-select-sm schedule-dom-select" name="day_of_month" style="max-width:180px">
                                    <option value="1" <?= $editDom === '1' ? 'selected' : '' ?>>1st of Month</option>
                                    <option value="7" <?= $editDom === '7' ? 'selected' : '' ?>>7th</option>
                                    <option value="15" <?= $editDom === '15' ? 'selected' : '' ?>>15th</option>
                                    <option value="21" <?= $editDom === '21' ? 'selected' : '' ?>>21st</option>
                                    <option value="1,15" <?= $editDom === '1,15' ? 'selected' : '' ?>>1st & 15th</option>
                                    <option value="8,23" <?= $editDom === '8,23' ? 'selected' : '' ?>>8th & 23rd</option>
                                    <option value="last" <?= $editDom === 'last' ? 'selected' : '' ?>>Last Day of Month</option>
                                </select>
                                <div class="input-group" style="max-width:220px">
                                    <span class="input-group-text">@ time of day</span>
                                    <input type="time" class="form-control schedule-time-input" value="<?= htmlspecialchars($editTimes ?: '00:00') ?>">
                                </div>
                            </div>
                            <input type="hidden" name="times" class="schedule-monthly-times-hidden" value="<?= htmlspecialchars($editTimes) ?>">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <label class="col-md-3 col-form-label fw-semibold">Repository</label>
                        <div class="col-md-6">
                            <select class="form-select" name="repository_id">
                                <?php foreach ($repositories as $repo): ?>
                                <option value="<?= $repo['id'] ?>" <?= $repo['id'] == $plan['repository_id'] ? 'selected' : '' ?>><?= htmlspecialchars($repo['name']) ?> (#<?= $repo['id'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <?php if (!empty($templates)): ?>
                    <div class="row mb-3">
                        <label class="col-md-3 col-form-label fw-semibold">Template</label>
                        <div class="col-md-6">
                            <select class="form-select edit-template-select">
                                <option value="">None (keep current configuration)</option>
                                <?php foreach ($templates as $tpl): ?>
                                <option value="<?= $tpl['id'] ?>"><?= htmlspecialchars($tpl['name']) ?> — <?= htmlspecialchars($tpl['description'] ?? '') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-text pt-2">Overwrites directories and excludes</div>
                    </div>
                    <?php endif; ?>

                    <div class="row mb-3">
                        <label class="col-md-3 col-form-label fw-semibold">Backup Directories</label>
                        <div class="col-md-6">
                            <textarea class="form-control edit-directories" name="directories" rows="3" required><?= htmlspecialchars($plan['directories']) ?></textarea>
                        </div>
                        <div class="col-md-3 form-text pt-2">One directory per line</div>
                    </div>

                    <div class="row mb-3">
                        <label class="col-md-3 col-form-label fw-semibold">Exclude Patterns</label>
                        <div class="col-md-6">
                            <textarea class="form-control edit-excludes" name="excludes" rows="3"><?= htmlspecialchars($plan['excludes'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-3 form-text pt-2">One pattern per line</div>
                    </div>

                    <div class="row mb-3">
                        <label class="col-md-3 col-form-label fw-semibold">Options</label>
                        <div class="col-md-6">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input edit-borg-opt" type="checkbox" id="editOptComp<?= $plan['id'] ?>" <?= $editHasCompression ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="editOptComp<?= $plan['id'] ?>">Compression</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input edit-borg-opt" type="checkbox" id="editOptCache<?= $plan['id'] ?>" <?= str_contains($editOpts, '--exclude-caches') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="editOptCache<?= $plan['id'] ?>">Exclude caches</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input edit-borg-opt" type="checkbox" id="editOptOneFs<?= $plan['id'] ?>" <?= str_contains($editOpts, '--one-file-system') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="editOptOneFs<?= $plan['id'] ?>">One file system</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input edit-borg-opt" type="checkbox" id="editOptNoatime<?= $plan['id'] ?>" <?= str_contains($editOpts, '--noatime') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="editOptNoatime<?= $plan['id'] ?>">No atime</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input edit-borg-opt" type="checkbox" id="editOptNumId<?= $plan['id'] ?>" <?= str_contains($editOpts, '--numeric-ids') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="editOptNumId<?= $plan['id'] ?>">Numeric owner IDs</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input edit-borg-opt" type="checkbox" id="editOptNoXattr<?= $plan['id'] ?>" <?= str_contains($editOpts, '--noxattrs') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="editOptNoXattr<?= $plan['id'] ?>">Skip xattrs</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input edit-borg-opt" type="checkbox" id="editOptNoAcl<?= $plan['id'] ?>" <?= str_contains($editOpts, '--noacls') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="editOptNoAcl<?= $plan['id'] ?>">Skip ACLs</label>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-2">
                                <label class="form-label small text-muted">Compression type</label>
                                <select class="form-select form-select-sm edit-comp-type" style="max-width: 200px;">
                                    <option value="lz4" <?= $editCompType === 'lz4' ? 'selected' : '' ?>>lz4 (fast)</option>
                                    <option value="zstd" <?= $editCompType === 'zstd' ? 'selected' : '' ?>>zstd (balanced)</option>
                                    <option value="zstd,3" <?= $editCompType === 'zstd,3' ? 'selected' : '' ?>>zstd,3 (better ratio)</option>
                                    <option value="zlib" <?= $editCompType === 'zlib' ? 'selected' : '' ?>>zlib (compatible)</option>
                                    <option value="none" <?= !$editHasCompression ? 'selected' : '' ?>>None</option>
                                </select>
                            </div>
                            <?php
                            // Extract custom options (not managed by checkboxes)
                            $managedFlags = ['--compression\s+\S+', '--exclude-caches', '--one-file-system', '--noatime', '--numeric-ids', '--noxattrs', '--noacls'];
                            $customOpts = $editOpts;
                            foreach ($managedFlags as $flag) {
                                $customOpts = preg_replace('/' . $flag . '/', '', $customOpts);
                            }
                            $customOpts = trim(preg_replace('/\s+/', ' ', $customOpts));
                            ?>
                            <div class="mt-2">
                                <label class="form-label small text-muted">Advanced Borg Options</label>
                                <input type="text" class="form-control form-control-sm font-monospace edit-adv-field" name="advanced_options"
                                       value="<?= htmlspecialchars($customOpts) ?>"
                                       placeholder="e.g. --pattern +home/*/docs/** --pattern -home/**">
                                <div class="form-text">Checkboxes above add/remove flags here. You can also type custom borg options.</div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <label class="col-md-3 col-form-label fw-semibold">Prune Retention</label>
                        <div class="col-md-9">
                            <div class="row g-2">
                                <div class="col"><label class="form-label small text-muted">Minutes</label><input type="number" class="form-control" name="prune_minutes" value="<?= $plan['prune_minutes'] ?>" min="0"></div>
                                <div class="col"><label class="form-label small text-muted">Hours</label><input type="number" class="form-control" name="prune_hours" value="<?= $plan['prune_hours'] ?>" min="0"></div>
                                <div class="col"><label class="form-label small text-muted">Days</label><input type="number" class="form-control" name="prune_days" value="<?= $plan['prune_days'] ?>" min="0"></div>
                                <div class="col"><label class="form-label small text-muted">Weeks</label><input type="number" class="form-control" name="prune_weeks" value="<?= $plan['prune_weeks'] ?>" min="0"></div>
                                <div class="col"><label class="form-label small text-muted">Months</label><input type="number" class="form-control" name="prune_months" value="<?= $plan['prune_months'] ?>" min="0"></div>
                                <div class="col"><label class="form-label small text-muted">Years</label><input type="number" class="form-control" name="prune_years" value="<?= $plan['prune_years'] ?>" min="0"></div>
                            </div>
                            <div class="form-text">How many archives to keep for each time period.</div>
                        </div>
                    </div>

                    <!-- Plugins (edit) -->
                    <?php if (!empty($enabledPluginsList)):
                        $existingPlanPlugins = $pluginManager->getPlanPlugins($plan['id']);
                        $existingByPluginId = [];
                        foreach ($existingPlanPlugins as $epp) {
                            $existingByPluginId[$epp['plugin_id']] = json_decode($epp['config'], true) ?: [];
                        }
                    ?>
                    <div class="row mb-3">
                        <label class="col-md-3 col-form-label fw-semibold">Plugins</label>
                        <div class="col-md-9">
                            <?php foreach ($enabledPluginsList as $plugin):
                                $schema = $pluginManager->getPluginSchema($plugin['slug']);
                                $existingConfig = $existingByPluginId[$plugin['id']] ?? [];
                                $isActive = !empty($existingConfig);
                            ?>
                            <div class="border rounded mb-2">
                                <div class="p-2 d-flex align-items-center">
                                    <div class="form-check mb-0 me-2">
                                        <input class="form-check-input" type="checkbox"
                                               name="plugin_enabled[<?= $plugin['id'] ?>]" value="1"
                                               id="editPluginEnable<?= $plan['id'] ?>_<?= $plugin['id'] ?>"
                                               data-bs-toggle="collapse" data-bs-target="#editPlugin<?= $plan['id'] ?>_<?= $plugin['id'] ?>"
                                               <?= $isActive ? 'checked' : '' ?>>
                                    </div>
                                    <label class="form-check-label fw-semibold" for="editPluginEnable<?= $plan['id'] ?>_<?= $plugin['id'] ?>">
                                        <?= htmlspecialchars($plugin['name']) ?>
                                    </label>
                                </div>
                                <div class="collapse <?= $isActive ? 'show' : '' ?>" id="editPlugin<?= $plan['id'] ?>_<?= $plugin['id'] ?>">
                                    <div class="p-3 pt-0">
                                        <?php foreach ($schema as $field => $def):
                                            $val = $existingConfig[$field] ?? $def['default'] ?? '';
                                            if (is_array($val)) $val = implode(', ', $val);
                                            if ($def['type'] === 'password' && $isActive && empty($existingConfig[$field])) $val = '';
                                            $fieldName = "plugin_config[{$plugin['id']}][{$field}]";
                                        ?>
                                        <div class="mb-2">
                                            <?php if ($def['type'] === 'checkbox'): ?>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="<?= $fieldName ?>" value="1"
                                                           id="editPF<?= $plan['id'] ?>_<?= $plugin['id'] ?>_<?= $field ?>"
                                                           <?= $val ? 'checked' : '' ?>>
                                                    <label class="form-check-label small" for="editPF<?= $plan['id'] ?>_<?= $plugin['id'] ?>_<?= $field ?>">
                                                        <?= htmlspecialchars($def['label']) ?>
                                                    </label>
                                                </div>
                                            <?php else: ?>
                                                <label class="form-label small fw-semibold mb-1"><?= htmlspecialchars($def['label']) ?></label>
                                                <input type="<?= $def['type'] === 'password' ? 'password' : ($def['type'] === 'number' ? 'number' : 'text') ?>"
                                                       class="form-control form-control-sm" name="<?= $fieldName ?>"
                                                       value="<?= htmlspecialchars($val) ?>"
                                                       <?= $def['type'] === 'password' && $isActive ? 'placeholder="(unchanged if empty)"' : '' ?>>
                                            <?php endif; ?>
                                            <?php if (!empty($def['help'])): ?>
                                                <div class="form-text small"><?= htmlspecialchars($def['help']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6 offset-md-3">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Save Changes</button>
                            <button type="button" class="btn btn-outline-secondary ms-2" data-bs-toggle="collapse" data-bs-target="#edit-plan-<?= $plan['id'] ?>">Cancel</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Create New Schedule -->
    <div id="create-plan-section" style="display:none;">
    <?php if (empty($repositories)): ?>
    <div class="alert alert-warning">You need to <a href="?tab=repos">create a repository</a> before adding a backup schedule.</div>
    <?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-plus-circle me-1"></i> Create New Backup Plan</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="hideCreatePlan()"><i class="bi bi-arrow-left me-1"></i>Back</button>
        </div>
        <div class="card-body">
            <form method="POST" action="/plans/create">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                <input type="hidden" name="agent_id" value="<?= $agent['id'] ?>">

                <div class="row mb-3">
                    <label class="col-md-3 col-form-label fw-semibold">Plan Name</label>
                    <div class="col-md-6">
                        <input type="text" class="form-control" name="name" required placeholder="e.g. Daily Backup">
                    </div>
                </div>

                <div class="row mb-3">
                    <label class="col-md-3 col-form-label fw-semibold"><i class="bi bi-clock me-1"></i> Frequency</label>
                    <div class="col-md-6">
                        <select class="form-select schedule-frequency" name="frequency">
                            <option value="manual">Manually (On Demand)</option>
                            <option value="10min">Every 10 Minutes</option>
                            <option value="15min">Every 15 Minutes</option>
                            <option value="30min">Every 30 Minutes</option>
                            <option value="hourly">Every Hour</option>
                            <option value="daily" selected>Every Day</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>
                </div>

                <div class="row mb-3 schedule-hourly-row d-none">
                    <label class="col-md-3 col-form-label fw-semibold"><i class="bi bi-clock-history me-1"></i> Minute Offset</label>
                    <div class="col-md-6">
                        <div class="input-group" style="max-width:260px">
                            <span class="input-group-text">@</span>
                            <input type="number" class="form-control schedule-minute-offset" min="0" max="59" value="0">
                            <span class="input-group-text">min past the hour</span>
                        </div>
                    </div>
                </div>

                <div class="row mb-3 schedule-daily-row">
                    <label class="col-md-3 col-form-label fw-semibold"><i class="bi bi-grid-3x3 me-1"></i> Run Hours</label>
                    <div class="col-md-9">
                        <div class="mb-2 d-flex align-items-center hour-picker-row">
                            <span class="text-muted fw-semibold me-2" style="display:inline-block;width:30px;">AM</span>
                            <div class="btn-group btn-group-sm">
                                <?php for ($h = 0; $h < 12; $h++): $label = $h === 0 ? '12' : str_pad($h, 2, '0', STR_PAD_LEFT); ?>
                                <button type="button" class="btn <?= $h === 1 ? 'btn-primary active' : 'btn-outline-primary' ?> hour-btn" data-hour="<?= $h ?>"><?= $label ?></button>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <div class="mb-2 d-flex align-items-center hour-picker-row">
                            <span class="text-muted fw-semibold me-2" style="display:inline-block;width:30px;">PM</span>
                            <div class="btn-group btn-group-sm">
                                <?php for ($h = 12; $h < 24; $h++): $label = $h === 12 ? '12' : str_pad($h - 12, 2, '0', STR_PAD_LEFT); ?>
                                <button type="button" class="btn btn-outline-primary hour-btn" data-hour="<?= $h ?>"><?= $label ?></button>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <div class="input-group mt-2" style="max-width:260px">
                            <span class="input-group-text">@</span>
                            <input type="number" class="form-control schedule-minute-offset" min="0" max="59" value="0">
                            <span class="input-group-text">min past the hour</span>
                        </div>
                        <input type="hidden" name="times" class="schedule-times-hidden" value="01:00">
                    </div>
                </div>

                <div class="row mb-3 schedule-weekly-row d-none">
                    <label class="col-md-3 col-form-label fw-semibold"><i class="bi bi-calendar-week me-1"></i> Day & Time</label>
                    <div class="col-md-9">
                        <div class="btn-group btn-group-sm mb-2 schedule-day-btns">
                            <button type="button" class="btn btn-outline-primary day-btn" data-day="0">Sun</button>
                            <button type="button" class="btn btn-primary active day-btn" data-day="1">Mon</button>
                            <button type="button" class="btn btn-outline-primary day-btn" data-day="2">Tue</button>
                            <button type="button" class="btn btn-outline-primary day-btn" data-day="3">Wed</button>
                            <button type="button" class="btn btn-outline-primary day-btn" data-day="4">Thu</button>
                            <button type="button" class="btn btn-outline-primary day-btn" data-day="5">Fri</button>
                            <button type="button" class="btn btn-outline-primary day-btn" data-day="6">Sat</button>
                        </div>
                        <input type="hidden" name="day_of_week" class="schedule-dow-hidden" value="1">
                        <div class="input-group" style="max-width:220px">
                            <span class="input-group-text">@ time of day</span>
                            <input type="time" class="form-control schedule-time-input" value="00:00">
                        </div>
                        <input type="hidden" name="times" class="schedule-weekly-times-hidden" value="00:00">
                    </div>
                </div>

                <div class="row mb-3 schedule-monthly-row d-none">
                    <label class="col-md-3 col-form-label fw-semibold"><i class="bi bi-calendar-event me-1"></i> Day & Time</label>
                    <div class="col-md-9">
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <select class="form-select form-select-sm schedule-dom-select" name="day_of_month" style="max-width:180px">
                                <option value="1" selected>1st of Month</option>
                                <option value="7">7th</option>
                                <option value="15">15th</option>
                                <option value="21">21st</option>
                                <option value="1,15">1st & 15th</option>
                                <option value="8,23">8th & 23rd</option>
                                <option value="last">Last Day of Month</option>
                            </select>
                            <div class="input-group" style="max-width:220px">
                                <span class="input-group-text">@ time of day</span>
                                <input type="time" class="form-control schedule-time-input" value="00:00">
                            </div>
                        </div>
                        <input type="hidden" name="times" class="schedule-monthly-times-hidden" value="00:00">
                    </div>
                </div>

                <div class="row mb-3">
                    <label class="col-md-3 col-form-label fw-semibold">Repository</label>
                    <div class="col-md-6">
                        <select class="form-select" name="repository_id" required>
                            <?php foreach ($repositories as $repo): ?>
                            <option value="<?= $repo['id'] ?>"><?= htmlspecialchars($repo['name']) ?> (#<?= $repo['id'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Template selector -->
                <?php if (!empty($templates)): ?>
                <div class="row mb-3">
                    <label class="col-md-3 col-form-label fw-semibold">Template</label>
                    <div class="col-md-6">
                        <select class="form-select" id="templateSelect">
                            <option value="">None (manual configuration)</option>
                            <?php foreach ($templates as $tpl): ?>
                            <option value="<?= $tpl['id'] ?>"><?= htmlspecialchars($tpl['name']) ?> — <?= htmlspecialchars($tpl['description'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 form-text pt-2">Pre-fills directories and excludes</div>
                </div>
                <?php endif; ?>

                <div class="row mb-3">
                    <label class="col-md-3 col-form-label fw-semibold">Backup Directories</label>
                    <div class="col-md-6">
                        <textarea class="form-control" name="directories" id="directoriesInput" rows="3" required placeholder="/home&#10;/etc&#10;/var/www"></textarea>
                        <div class="mt-2">
                            <span class="text-muted small me-1">Quick add:</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary dir-btn" data-dir="/home">/home</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary dir-btn" data-dir="/etc">/etc</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary dir-btn" data-dir="/var">/var</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary dir-btn" data-dir="/opt">/opt</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary dir-btn" data-dir="/srv">/srv</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary dir-btn" data-dir="/usr/local">/usr/local</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary dir-btn" data-dir="/var/www">/var/www</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary dir-btn" data-dir="/var/lib/mysql">/var/lib/mysql</button>
                        </div>
                    </div>
                    <div class="col-md-3 form-text pt-2">One directory per line</div>
                </div>

                <div class="row mb-3">
                    <label class="col-md-3 col-form-label fw-semibold">Exclude Patterns</label>
                    <div class="col-md-6">
                        <textarea class="form-control" name="excludes" id="excludesInput" rows="3" placeholder="*.tmp&#10;*.log&#10;*.cache&#10;/home/*/tmp"></textarea>
                    </div>
                    <div class="col-md-3 form-text pt-2">One pattern per line. Borg glob patterns.</div>
                </div>

                <div class="row mb-3">
                    <label class="col-md-3 col-form-label fw-semibold">Options</label>
                    <div class="col-md-6">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input borg-opt" type="checkbox" name="opt_compression" id="optCompression" value="1" checked>
                                    <label class="form-check-label" for="optCompression">Compression (lz4)</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input borg-opt" type="checkbox" name="opt_exclude_caches" id="optExcludeCaches" value="1" checked>
                                    <label class="form-check-label" for="optExcludeCaches">Exclude caches</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input borg-opt" type="checkbox" name="opt_one_file_system" id="optOneFs" value="1">
                                    <label class="form-check-label" for="optOneFs">One file system</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input borg-opt" type="checkbox" name="opt_noatime" id="optNoatime" value="1" checked>
                                    <label class="form-check-label" for="optNoatime">No atime (faster)</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input borg-opt" type="checkbox" name="opt_numeric_ids" id="optNumericIds" value="1">
                                    <label class="form-check-label" for="optNumericIds">Numeric owner IDs</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input borg-opt" type="checkbox" name="opt_no_xattrs" id="optNoXattrs" value="1">
                                    <label class="form-check-label" for="optNoXattrs">Skip xattrs</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input borg-opt" type="checkbox" name="opt_no_acls" id="optNoAcls" value="1">
                                    <label class="form-check-label" for="optNoAcls">Skip ACLs</label>
                                </div>
                            </div>
                        </div>
                        <div class="mt-2">
                            <label class="form-label small text-muted">Compression type</label>
                            <select class="form-select form-select-sm" name="compression_type" id="compressionType" style="max-width: 200px;">
                                <option value="lz4" selected>lz4 (fast)</option>
                                <option value="zstd">zstd (balanced)</option>
                                <option value="zstd,3">zstd,3 (better ratio)</option>
                                <option value="zlib">zlib (compatible)</option>
                                <option value="none">None</option>
                            </select>
                        </div>
                        <div class="mt-2">
                            <label class="form-label small text-muted">Advanced Borg Options</label>
                            <input type="text" class="form-control form-control-sm font-monospace" name="advanced_options" id="advancedOptionsField"
                                   placeholder="e.g. --pattern +home/*/docs/** --pattern -home/**">
                            <div class="form-text">Checkboxes above add/remove flags here. You can also type custom borg options.</div>
                        </div>
                    </div>
                </div>

                <div class="row mb-3">
                    <label class="col-md-3 col-form-label fw-semibold">Prune Retention</label>
                    <div class="col-md-9">
                        <div class="row g-2">
                            <div class="col">
                                <label class="form-label small text-muted">Minutes</label>
                                <input type="number" class="form-control" name="prune_minutes" value="0" min="0">
                            </div>
                            <div class="col">
                                <label class="form-label small text-muted">Hours</label>
                                <input type="number" class="form-control" name="prune_hours" value="24" min="0">
                            </div>
                            <div class="col">
                                <label class="form-label small text-muted">Days</label>
                                <input type="number" class="form-control" name="prune_days" value="7" min="0">
                            </div>
                            <div class="col">
                                <label class="form-label small text-muted">Weeks</label>
                                <input type="number" class="form-control" name="prune_weeks" value="4" min="0">
                            </div>
                            <div class="col">
                                <label class="form-label small text-muted">Months</label>
                                <input type="number" class="form-control" name="prune_months" value="6" min="0">
                            </div>
                            <div class="col">
                                <label class="form-label small text-muted">Years</label>
                                <input type="number" class="form-control" name="prune_years" value="0" min="0">
                            </div>
                        </div>
                        <div class="form-text">How many archives to keep for each time period.</div>
                    </div>
                </div>

                <!-- Plugins -->
                <?php if (!empty($enabledPluginsList)): ?>
                <div class="row mb-3">
                    <label class="col-md-3 col-form-label fw-semibold">Plugins</label>
                    <div class="col-md-9">
                        <div class="accordion accordion-flush" id="pluginAccordionCreate">
                            <?php foreach ($enabledPluginsList as $plugin):
                                $schema = $pluginManager->getPluginSchema($plugin['slug']);
                                $helpSql = $pluginManager->getPluginHelp($plugin['slug']);
                            ?>
                            <div class="border rounded mb-2">
                                <div class="p-2 d-flex align-items-center">
                                    <div class="form-check mb-0 me-2">
                                        <input class="form-check-input plugin-toggle" type="checkbox"
                                               name="plugin_enabled[<?= $plugin['id'] ?>]" value="1"
                                               id="createPluginEnable<?= $plugin['id'] ?>"
                                               data-bs-toggle="collapse" data-bs-target="#createPlugin<?= $plugin['id'] ?>">
                                    </div>
                                    <label class="form-check-label fw-semibold" for="createPluginEnable<?= $plugin['id'] ?>">
                                        <?= htmlspecialchars($plugin['name']) ?>
                                    </label>
                                    <small class="text-muted ms-2"><?= htmlspecialchars($plugin['description']) ?></small>
                                </div>
                                <div class="collapse" id="createPlugin<?= $plugin['id'] ?>">
                                    <div class="p-3 pt-0">
                                        <?php foreach ($schema as $field => $def):
                                            $default = $def['default'] ?? '';
                                            $fieldVal = is_array($default) ? implode(', ', $default) : $default;
                                            $fieldName = "plugin_config[{$plugin['id']}][{$field}]";
                                        ?>
                                        <div class="mb-2">
                                            <?php if ($def['type'] === 'checkbox'): ?>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="<?= $fieldName ?>" value="1"
                                                           id="createPF<?= $plugin['id'] ?>_<?= $field ?>"
                                                           <?= $default ? 'checked' : '' ?>>
                                                    <label class="form-check-label small" for="createPF<?= $plugin['id'] ?>_<?= $field ?>">
                                                        <?= htmlspecialchars($def['label']) ?>
                                                    </label>
                                                </div>
                                            <?php else: ?>
                                                <label class="form-label small fw-semibold mb-1"><?= htmlspecialchars($def['label']) ?>
                                                    <?php if ($def['required'] ?? false): ?><span class="text-danger">*</span><?php endif; ?>
                                                </label>
                                                <input type="<?= $def['type'] === 'password' ? 'password' : ($def['type'] === 'number' ? 'number' : 'text') ?>"
                                                       class="form-control form-control-sm" name="<?= $fieldName ?>"
                                                       value="<?= htmlspecialchars($fieldVal) ?>"
                                                       <?= ($def['required'] ?? false) ? '' : '' ?>>
                                            <?php endif; ?>
                                            <?php if (!empty($def['help'])): ?>
                                                <div class="form-text small"><?= htmlspecialchars($def['help']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php if ($helpSql): ?>
                                        <div class="alert alert-info small mt-2 mb-0">
                                            <strong>Setup hint:</strong>
                                            <pre class="mb-0 mt-1" style="font-size: 0.8rem;"><?= htmlspecialchars($helpSql) ?></pre>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-6 offset-md-3">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-plus-circle me-1"></i> Create Backup Plan
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
    // Schedule picker logic (works for both create and edit forms)
    function initSchedulePicker(container) {
        const freqSelect = container.querySelector('.schedule-frequency');
        if (!freqSelect) return;

        function updateVisibility() {
            const freq = freqSelect.value;
            container.querySelector('.schedule-hourly-row')?.classList.toggle('d-none', freq !== 'hourly');
            container.querySelector('.schedule-daily-row')?.classList.toggle('d-none', freq !== 'daily');
            container.querySelector('.schedule-weekly-row')?.classList.toggle('d-none', freq !== 'weekly');
            container.querySelector('.schedule-monthly-row')?.classList.toggle('d-none', freq !== 'monthly');
        }

        freqSelect.addEventListener('change', updateVisibility);
        updateVisibility();

        // Hour toggle buttons (daily)
        function syncDailyTimes() {
            const dailyRow = container.querySelector('.schedule-daily-row');
            if (!dailyRow) return;
            const minute = String(dailyRow.querySelector('.schedule-minute-offset')?.value || 0).padStart(2, '0');
            const hours = [...dailyRow.querySelectorAll('.hour-btn.active')]
                .map(b => parseInt(b.dataset.hour))
                .sort((a, b) => a - b)
                .map(h => String(h).padStart(2, '0') + ':' + minute);
            const hidden = dailyRow.querySelector('.schedule-times-hidden');
            if (hidden) hidden.value = hours.join(', ');
        }

        container.querySelectorAll('.schedule-daily-row .hour-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                this.classList.toggle('active');
                this.classList.toggle('btn-primary');
                this.classList.toggle('btn-outline-primary');
                syncDailyTimes();
            });
        });

        const dailyMinute = container.querySelector('.schedule-daily-row .schedule-minute-offset');
        if (dailyMinute) dailyMinute.addEventListener('change', syncDailyTimes);

        // Hourly: sync minute to times hidden (stored as "00:MM")
        const hourlyRow = container.querySelector('.schedule-hourly-row');
        if (hourlyRow) {
            const hourlyMinute = hourlyRow.querySelector('.schedule-minute-offset');
            // Hourly uses the form-level times hidden or we add one
            let hourlyTimesHidden = container.querySelector('input[name="times"].schedule-hourly-times-hidden');
            if (!hourlyTimesHidden) {
                hourlyTimesHidden = document.createElement('input');
                hourlyTimesHidden.type = 'hidden';
                hourlyTimesHidden.name = 'times';
                hourlyTimesHidden.className = 'schedule-hourly-times-hidden';
                hourlyRow.appendChild(hourlyTimesHidden);
            }
            function syncHourly() {
                hourlyTimesHidden.value = '00:' + String(hourlyMinute.value || 0).padStart(2, '0');
            }
            if (hourlyMinute) hourlyMinute.addEventListener('change', syncHourly);
            syncHourly();
        }

        // Day-of-week toggle (single select)
        container.querySelectorAll('.schedule-day-btns .day-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                this.parentElement.querySelectorAll('.btn').forEach(b => {
                    b.classList.remove('active', 'btn-primary');
                    b.classList.add('btn-outline-primary');
                });
                this.classList.add('active', 'btn-primary');
                this.classList.remove('btn-outline-primary');
                const hidden = container.querySelector('.schedule-dow-hidden');
                if (hidden) hidden.value = this.dataset.day;
            });
        });

        // Weekly/monthly time input sync
        container.querySelectorAll('.schedule-weekly-row .schedule-time-input').forEach(input => {
            function sync() {
                const hidden = container.querySelector('.schedule-weekly-times-hidden');
                if (hidden) hidden.value = input.value;
            }
            input.addEventListener('change', sync);
            sync();
        });
        container.querySelectorAll('.schedule-monthly-row .schedule-time-input').forEach(input => {
            function sync() {
                const hidden = container.querySelector('.schedule-monthly-times-hidden');
                if (hidden) hidden.value = input.value;
            }
            input.addEventListener('change', sync);
            sync();
        });

        // On form submit, ensure the correct "times" hidden is active
        const form = container.closest('form') || container.querySelector('form');
        if (form) {
            form.addEventListener('submit', function() {
                const freq = freqSelect.value;
                // Disable all schedule times hiddens, then enable only the active one
                container.querySelectorAll('.schedule-times-hidden, .schedule-weekly-times-hidden, .schedule-monthly-times-hidden, .schedule-hourly-times-hidden').forEach(h => h.disabled = true);
                if (freq === 'daily') {
                    syncDailyTimes();
                    const h = container.querySelector('.schedule-times-hidden');
                    if (h) h.disabled = false;
                } else if (freq === 'weekly') {
                    const h = container.querySelector('.schedule-weekly-times-hidden');
                    if (h) h.disabled = false;
                } else if (freq === 'monthly') {
                    const h = container.querySelector('.schedule-monthly-times-hidden');
                    if (h) h.disabled = false;
                } else if (freq === 'hourly') {
                    const h = container.querySelector('.schedule-hourly-times-hidden');
                    if (h) h.disabled = false;
                }
                // Disable day_of_week/day_of_month when not relevant
                const dowHidden = container.querySelector('.schedule-dow-hidden');
                if (dowHidden) dowHidden.disabled = freq !== 'weekly';
                const domSelect = container.querySelector('.schedule-dom-select');
                if (domSelect) domSelect.disabled = freq !== 'monthly';
            });
        }
    }

    // Init all schedule pickers (create form + any visible edit forms)
    document.querySelectorAll('form').forEach(form => {
        if (form.querySelector('.schedule-frequency')) {
            initSchedulePicker(form);
        }
    });
    // Also init edit panels (they are inside collapse divs, not directly in a form with .schedule-frequency)
    document.querySelectorAll('.edit-plan-panel').forEach(panel => {
        const form = panel.querySelector('form');
        if (form && form.querySelector('.schedule-frequency')) {
            initSchedulePicker(form);
        }
    });

    // Quick-pick directory buttons
    document.querySelectorAll('.dir-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const ta = document.getElementById('directoriesInput');
            const dirs = ta.value.trim().split('\n').filter(d => d.trim());
            const dir = this.dataset.dir;
            if (!dirs.includes(dir)) {
                dirs.push(dir);
                ta.value = dirs.join('\n');
            }
        });
    });

    // Template selector
    const tplSelect = document.getElementById('templateSelect');
    if (tplSelect) {
        tplSelect.addEventListener('change', function() {
            if (!this.value) return;
            fetch('/api/templates/' + this.value, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(tpl => {
                    document.getElementById('directoriesInput').value = (tpl.directories || '').replace(/\\n/g, '\n');
                    document.getElementById('excludesInput').value = (tpl.excludes || '').replace(/\\n/g, '\n');
                });
        });
    }

    // Managed borg flags (checkbox-controlled)
    const managedFlags = ['--compression\\s+\\S+', '--exclude-caches', '--one-file-system', '--noatime', '--numeric-ids', '--noxattrs', '--noacls'];
    function stripManagedFlags(val) {
        managedFlags.forEach(f => { val = val.replace(new RegExp(f, 'g'), ''); });
        return val.replace(/\s+/g, ' ').trim();
    }
    function buildCheckboxOpts(compChecked, compType, cacheChecked, oneFsChecked, noatimeChecked, numIdChecked, noXattrChecked, noAclChecked) {
        const opts = [];
        if (compChecked && compType !== 'none') opts.push('--compression ' + compType);
        if (cacheChecked) opts.push('--exclude-caches');
        if (oneFsChecked) opts.push('--one-file-system');
        if (noatimeChecked) opts.push('--noatime');
        if (numIdChecked) opts.push('--numeric-ids');
        if (noXattrChecked) opts.push('--noxattrs');
        if (noAclChecked) opts.push('--noacls');
        return opts.join(' ');
    }

    // Create form: sync checkboxes into the visible field
    const createForm = document.querySelector('form[action="/plans/create"]');
    if (createForm) {
        const advField = document.getElementById('advancedOptionsField');

        function syncCreateField() {
            const custom = stripManagedFlags(advField.value);
            const checkbox = buildCheckboxOpts(
                document.getElementById('optCompression').checked,
                document.getElementById('compressionType').value,
                document.getElementById('optExcludeCaches').checked,
                document.getElementById('optOneFs').checked,
                document.getElementById('optNoatime').checked,
                document.getElementById('optNumericIds').checked,
                document.getElementById('optNoXattrs').checked,
                document.getElementById('optNoAcls').checked
            );
            advField.value = [checkbox, custom].filter(Boolean).join(' ');
        }

        createForm.querySelectorAll('.borg-opt').forEach(cb => cb.addEventListener('change', syncCreateField));
        document.getElementById('compressionType').addEventListener('change', syncCreateField);
        // Initialize on load
        syncCreateField();

        createForm.addEventListener('submit', syncCreateField);
    }

    // Edit plan forms: sync checkboxes into the visible field
    document.querySelectorAll('.edit-plan-form').forEach(form => {
        const panel = form.closest('.edit-plan-panel');
        const advField = panel.querySelector('.edit-adv-field');
        const checks = panel.querySelectorAll('.edit-borg-opt');
        const compSelect = panel.querySelector('.edit-comp-type');

        function syncEditField() {
            const custom = stripManagedFlags(advField.value);
            const checkbox = buildCheckboxOpts(
                checks[0] && checks[0].checked,
                compSelect.value,
                checks[1] && checks[1].checked,
                checks[2] && checks[2].checked,
                checks[3] && checks[3].checked,
                checks[4] && checks[4].checked,
                checks[5] && checks[5].checked,
                checks[6] && checks[6].checked
            );
            advField.value = [checkbox, custom].filter(Boolean).join(' ');
        }

        checks.forEach(cb => cb.addEventListener('change', syncEditField));
        compSelect.addEventListener('change', syncEditField);
        // Initialize on load
        syncEditField();

        form.addEventListener('submit', syncEditField);
    });

    // Edit form template selectors
    document.querySelectorAll('.edit-template-select').forEach(sel => {
        sel.addEventListener('change', function() {
            if (!this.value) return;
            const panel = this.closest('.edit-plan-panel');
            fetch('/api/templates/' + this.value, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(tpl => {
                    panel.querySelector('.edit-directories').value = (tpl.directories || '').replace(/\\n/g, '\n');
                    panel.querySelector('.edit-excludes').value = (tpl.excludes || '').replace(/\\n/g, '\n');
                });
        });
    });

    // Show/hide create section when edit panel is toggled
    document.querySelectorAll('.edit-plan-panel').forEach(panel => {
        panel.addEventListener('shown.bs.collapse', function() {
            document.getElementById('create-plan-section').style.display = 'none';
        });
        panel.addEventListener('hidden.bs.collapse', function() {
            document.getElementById('create-plan-section').style.display = 'none';
        });
    });
    </script>
    </div><!-- /create-plan-section -->
    <?php endif; ?>

    <!-- Manage Plugins Modal -->
    <div class="modal fade" id="managePluginsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plug me-1"></i> Plugins for <?= htmlspecialchars($agent['name']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="/clients/<?= $agent['id'] ?>/plugins">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <div class="modal-body">
                        <p class="text-muted small">Enable plugins to add pre-backup tasks (e.g. database dumps) for this client. Once enabled, you can configure them per backup plan.</p>
                        <?php if (empty($allPlugins)): ?>
                            <p class="text-muted">No plugins available.</p>
                        <?php else: ?>
                            <?php foreach ($allPlugins as $plugin): ?>
                            <div class="form-check mb-3">
                                <input type="checkbox" class="form-check-input" name="plugins[]"
                                       value="<?= $plugin['id'] ?>" id="managePlugin<?= $plugin['id'] ?>"
                                       <?php foreach ($agentPlugins as $ap): if ($ap['id'] == $plugin['id'] && $ap['agent_enabled']): ?>checked<?php endif; endforeach; ?>>
                                <label class="form-check-label" for="managePlugin<?= $plugin['id'] ?>">
                                    <strong><?= htmlspecialchars($plugin['name']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($plugin['description']) ?></small>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php elseif ($tab === 'restore'): ?>
    <h5 class="mb-3">Restore Files</h5>

    <?php if (empty($archives)): ?>
        <div class="alert alert-info">No archives available yet. Run a backup first.</div>
    <?php else: ?>

    <!-- Control Bar -->
    <div class="restore-control-bar mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label fw-semibold mb-1 small">Archive</label>
                <select class="form-select" id="archive-select">
                    <option value="">Choose a restore point...</option>
                    <?php
                    $currentRepo = null;
                    foreach ($archives as $ar):
                        if ($ar['repo_name'] !== $currentRepo):
                            if ($currentRepo !== null) echo '</optgroup>';
                            $currentRepo = $ar['repo_name'];
                            echo '<optgroup label="' . htmlspecialchars($currentRepo) . '">';
                        endif;
                    ?>
                        <option value="<?= $ar['id'] ?>">
                            <?= \BBS\Core\TimeHelper::format($ar['created_at'], 'l, M j, Y \a\t g:i A') ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if ($currentRepo !== null) echo '</optgroup>'; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label fw-semibold mb-1 small">Search</label>
                <div class="input-group">
                    <input type="text" class="form-control" id="restore-search" placeholder="e.g. nginx.conf" disabled>
                    <button class="btn btn-outline-secondary" type="button" id="restore-search-btn" disabled>
                        <i class="bi bi-search"></i>
                    </button>
                    <button class="btn btn-outline-secondary dropdown-toggle" type="button" id="search-mode-btn" data-bs-toggle="dropdown">
                        <i class="bi bi-funnel"></i> This Archive
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" id="search-mode-menu">
                        <li><a class="dropdown-item" href="#" data-mode="current"><i class="bi bi-archive me-1"></i> Search This Archive</a></li>
                        <li><a class="dropdown-item" href="#" data-mode="all"><i class="bi bi-clock-history me-1"></i> Search All Archives (File History)</a></li>
                    </ul>
                </div>
            </div>
            <div class="col-md-2">
                <button class="restore-back-btn w-100" id="back-to-browse" style="display:none;">
                    <i class="bi bi-arrow-left me-1"></i> Back to Browse
                </button>
            </div>
        </div>
    </div>

    <!-- Two-Panel Layout -->
    <div class="row g-3">
        <!-- LEFT: Browse / Search / History -->
        <div class="col-lg-7">
            <!-- Browse Panel -->
            <div id="browse-panel">
                <div class="restore-panel-header">
                    <i class="bi bi-folder2-open me-1"></i> Browse Archive
                </div>
                <div class="restore-panel-body font-monospace small" id="tree-root"></div>
            </div>

            <!-- Search Results Panel (hidden) -->
            <div id="search-panel" style="display:none;">
                <div class="restore-panel-header d-flex justify-content-between">
                    <span><i class="bi bi-search me-1"></i> Search Results (<span id="search-count">0</span>)</span>
                    <span>
                        <button class="btn btn-sm btn-outline-light py-0 px-1" id="search-prev" disabled>&laquo;</button>
                        <span class="mx-1 small" id="search-page-info"></span>
                        <button class="btn btn-sm btn-outline-light py-0 px-1" id="search-next" disabled>&raquo;</button>
                    </span>
                </div>
                <div class="restore-panel-body font-monospace small" id="search-results-body"></div>
            </div>

            <!-- File History Panel (hidden) -->
            <div id="history-panel" style="display:none;">
                <div class="restore-panel-header d-flex justify-content-between">
                    <span><i class="bi bi-clock-history me-1"></i> File History (<span id="history-count">0</span> files)</span>
                    <span>
                        <button class="btn btn-sm btn-outline-light py-0 px-1" id="history-prev" disabled>&laquo;</button>
                        <span class="mx-1 small" id="history-page-info"></span>
                        <button class="btn btn-sm btn-outline-light py-0 px-1" id="history-next" disabled>&raquo;</button>
                    </span>
                </div>
                <div class="restore-panel-body" id="history-results-body"></div>
            </div>
        </div>

        <!-- RIGHT: Selection + Actions -->
        <div class="col-lg-5">
            <div class="restore-panel-header" style="background: linear-gradient(135deg, #4a90d9 0%, #357abd 100%);">
                <i class="bi bi-check2-square me-1"></i> Files to Restore (<span id="selected-count">0</span>)
            </div>
            <div class="restore-panel-body" style="height: 340px;">
                <div id="selected-list"></div>
                <div class="p-3 text-muted small text-center" id="no-selection">
                    <i class="bi bi-arrow-left-circle d-block mb-2" style="font-size:2rem;opacity:0.3;"></i>
                    Browse or search, then check files to add them here
                </div>
            </div>
            <div class="restore-actions">
                <div class="mb-2">
                    <label class="form-label small fw-semibold mb-1">Destination (optional)</label>
                    <input type="text" class="form-control form-control-sm" id="restore-destination" placeholder="Leave blank for original paths">
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-success flex-fill" id="restore-btn" disabled>
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Restore to Client
                    </button>
                    <button class="btn btn-primary flex-fill" id="download-btn" disabled>
                        <i class="bi bi-download me-1"></i> Download .tar.gz
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden forms -->
    <form id="restore-form" method="POST" action="/clients/<?= $agent['id'] ?>/restore" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
        <input type="hidden" name="archive_id" id="restore-archive-id">
        <input type="hidden" name="destination" id="restore-dest-field">
        <div id="restore-files-container"></div>
    </form>
    <form id="download-form" method="POST" action="/clients/<?= $agent['id'] ?>/download" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
        <input type="hidden" name="archive_id" id="download-archive-id">
        <div id="download-files-container"></div>
    </form>

    <script>window.RESTORE_AGENT_ID = <?= $agent['id'] ?>;</script>
    <?php
    if (!isset($scripts)) $scripts = [];
    $scripts[] = '/js/restore.js?v=' . filemtime(__DIR__ . '/../../../public/js/restore.js');
    ?>

    <?php endif; ?>

<?php elseif ($tab === 'install'): ?>
    <h5 class="mb-3">Install Agent</h5>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <?php $appUrl = rtrim(\BBS\Core\Config::get('APP_URL', 'https://' . $serverHost), '/'); ?>
            <?php $installCmd = 'curl -s ' . $appUrl . '/get-agent | sudo bash -s -- --server ' . $appUrl . ' --key ' . $agent['api_key']; ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <p class="mb-0">Run this command on the endpoint to install the BBS agent:</p>
                <button class="btn btn-sm btn-outline-secondary" type="button"
                        onclick="navigator.clipboard.writeText(document.getElementById('installCmd').textContent.trim()); this.innerHTML='<i class=\'bi bi-check\'></i> Copied'; setTimeout(() => this.innerHTML='<i class=\'bi bi-clipboard\'></i> Copy', 2000)">
                    <i class="bi bi-clipboard"></i> Copy
                </button>
            </div>
            <div class="bg-dark text-white p-3 rounded mb-3" style="font-family: monospace; font-size: 0.9rem; word-break: break-all;" id="installCmd">
                <?= htmlspecialchars($installCmd) ?>
            </div>
        </div>
    </div>

    <div id="agent-online-alert" class="alert alert-success d-flex align-items-center mt-3" style="display: none !important;">
        <i class="bi bi-heart-pulse-fill fs-4 me-3 text-success"></i>
        <div>
            <strong>Agent is Online!</strong><br>
            <span class="text-muted">Received heartbeat from agent. Your client is connected and ready.</span>
        </div>
    </div>

<?php elseif ($tab === 'delete'): ?>
    <h5 class="mb-3">Delete Client : <?= htmlspecialchars($agent['name']) ?></h5>

    <p class="text-muted">You have selected to delete a client from Borg Backup Server. When you delete a client, all backup data will be deleted including schedules and repositories. The client machine will be un-affected.</p>

    <div class="delete-warning-box">
        <div class="d-flex align-items-start mb-3">
            <i class="bi bi-exclamation-triangle-fill text-danger fs-3 me-3"></i>
            <div>
                <h5 class="fw-bold text-danger mb-2">WARNING: ALL CLIENT DATA WILL BE DESTROYED!</h5>
                <p class="mb-3">There will be no way to recover your data. Are you sure you wish to continue?</p>
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="confirmDeleteCheck" onchange="document.getElementById('deleteBtn').disabled = !this.checked;">
                    <label class="form-check-label fw-semibold" for="confirmDeleteCheck">Yes. I understand.</label>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mt-3">
        <a href="/clients/<?= $agent['id'] ?>" class="btn btn-outline-secondary">Cancel</a>
        <form method="POST" action="/clients/<?= $agent['id'] ?>/delete" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
            <button type="submit" class="btn btn-danger" id="deleteBtn" disabled>
                <i class="bi bi-trash me-1"></i> DELETE CLIENT
            </button>
        </form>
    </div>
<?php endif; ?>
</div><!-- /client-tab-content -->

<script>
// Hide content below header when edit panel is open
(function() {
    const editPanel = document.getElementById('edit-client');
    const belowHeader = document.querySelectorAll('.card.border-0.shadow-sm.mb-4 ~ *');
    if (editPanel) {
        editPanel.addEventListener('shown.bs.collapse', function() {
            belowHeader.forEach(el => el.style.display = 'none');
        });
        editPanel.addEventListener('hidden.bs.collapse', function() {
            belowHeader.forEach(el => el.style.display = '');
        });
    }
})();

// Auto-refresh agent status
(function() {
    const agentId = <?= (int) $agent['id'] ?>;
    const initialStatus = '<?= $agent['status'] ?>';
    let previousStatus = initialStatus;
    const statusMap = { online: 'success', offline: 'secondary', error: 'danger', setup: 'warning' };

    function pollStatus() {
        fetch('/clients/' + agentId + '/json', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                // Update badge
                const badge = document.getElementById('agent-status-badge');
                if (badge) {
                    const cls = statusMap[data.status] || 'warning';
                    badge.className = 'badge bg-' + cls + ' fs-6';
                    badge.textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1);
                }

                // Update last seen
                const seen = document.getElementById('agent-last-seen');
                if (seen) seen.textContent = data.seen_ago;

                const icon = document.getElementById('agent-last-seen-icon');
                if (icon) {
                    const cls = statusMap[data.status] || 'warning';
                    icon.className = 'stat-icon-sm bg-' + cls + ' bg-opacity-10 text-' + cls + ' rounded-2 p-2 me-2';
                }

                // Show heartbeat alert on install tab when agent comes online
                const alert = document.getElementById('agent-online-alert');
                if (alert && data.status === 'online' && previousStatus !== 'online') {
                    alert.style.cssText = '';
                    alert.classList.remove('d-none');
                }

                previousStatus = data.status;
            })
            .catch(() => {});
    }

    setInterval(pollStatus, 10000);
})();
</script>
