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
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body pb-2">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <div class="d-flex align-items-center gap-2">
                    <h3 class="mb-0">
                        <i class="bi bi-display me-2 text-primary"></i><?= htmlspecialchars($agent['name']) ?>
                    </h3>
                    <span class="badge bg-<?= $statusClass ?> fs-6"><?= ucfirst($agent['status']) ?></span>
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
            <div class="text-end text-muted small">
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
                <div class="d-flex align-items-center p-2 rounded border bg-light">
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
                <div class="d-flex align-items-center p-2 rounded border bg-light">
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
                <div class="d-flex align-items-center p-2 rounded border bg-light">
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
                <div class="d-flex align-items-center p-2 rounded border bg-light">
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
                <div class="d-flex align-items-center p-2 rounded border bg-light">
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
                <div class="d-flex align-items-center p-2 rounded border bg-light">
                    <div class="stat-icon-sm bg-<?= $statusClass ?> bg-opacity-10 text-<?= $statusClass ?> rounded-2 p-2 me-2">
                        <i class="bi bi-broadcast"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?= $seenAgo ?></div>
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
    if ($nextBackup && $nextBackup['next_run']) {
        $nextDiff = strtotime($nextBackup['next_run']) - time();
        if ($nextDiff < 0) $nextRunLabel = 'Overdue';
        elseif ($nextDiff < 3600) $nextRunLabel = floor($nextDiff / 60) . 'm';
        elseif ($nextDiff < 86400) $nextRunLabel = floor($nextDiff / 3600) . 'h ' . floor(($nextDiff % 3600) / 60) . 'm';
        else $nextRunLabel = floor($nextDiff / 86400) . 'd ' . floor(($nextDiff % 86400) / 3600) . 'h';
        $nextRunSub = htmlspecialchars($nextBackup['plan_name']);
    }
    $successColor = $successRate >= 90 ? 'success' : ($successRate >= 70 ? 'warning' : 'danger');
    ?>

    <!-- Row 1: Key Metrics -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="background-color: rgba(13,110,253,0.05);">
                <div class="card-body d-flex align-items-center position-relative">
                    <?php if ($nextBackup && $nextBackup['plan_id']): ?>
                    <form method="POST" action="/plans/<?= $nextBackup['plan_id'] ?>/trigger" class="position-absolute top-0 end-0 mt-2 me-2">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size: 0.75rem;" title="Run backup now">
                            <i class="bi bi-play-fill"></i> Run Now
                        </button>
                    </form>
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

    <?php if (!empty($repositories)): ?>
    <div class="row g-3 mb-4">
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
                <div class="card-body p-0">
                    <div class="repo-card-header text-white text-center p-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="text-start">
                                <h6 class="fw-bold mb-0 text-white"><?= htmlspecialchars($repo['name']) ?></h6>
                                <small class="opacity-75">(<?= $repo['archive_count'] ?>)</small>
                            </div>
                            <?php if ($deleteBlocked): ?>
                                <span data-bs-toggle="tooltip" title="<?= htmlspecialchars($blockReason) ?>">
                                    <button type="button" class="btn btn-sm text-white-50" disabled><i class="bi bi-trash"></i></button>
                                </span>
                            <?php else: ?>
                                <form method="POST" action="/repositories/<?= $repo['id'] ?>/delete" class="d-inline" onsubmit="return confirm('PERMANENTLY delete repository &quot;<?= htmlspecialchars($repo['name']) ?>&quot;, all its archives, and the data on disk?\n\nThis action is NOT reversible.')">
                                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                    <button type="submit" class="btn btn-sm text-white-50" title="Delete"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div class="my-2">
                            <i class="bi bi-download" style="font-size: 2.5rem; opacity: 0.8;"></i>
                        </div>
                    </div>
                    <div class="d-flex">
                        <div class="repo-stat-block flex-fill text-center p-2 border-end">
                            <div class="fw-bold fs-5"><?= $sizeLabel ?></div>
                            <div class="repo-stat-label">Repo Size</div>
                        </div>
                        <div class="repo-stat-block flex-fill text-center p-2">
                            <div class="fw-bold fs-5"><?= $repo['archive_count'] ?></div>
                            <div class="repo-stat-label">Recovery Points</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Create new repo -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-plus-circle me-1"></i> Create New Repo
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

    <!-- Existing Plans -->
    <?php if (!empty($plans)): ?>
    <div class="row g-3 mb-4">
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
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100 schedule-card">
                <div class="card-body p-3">
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
                        <div class="dropdown ms-2">
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
                    </div>
                </div>
                <div class="schedule-status-bar bg-<?= $statusColor ?>">
                    Current Status: <?= $statusLabel ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
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
                            <input type="hidden" name="advanced_options" class="edit-adv-hidden">
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
    <div id="create-plan-section">
    <?php if (empty($repositories)): ?>
    <div class="alert alert-warning">You need to <a href="?tab=repos">create a repository</a> before adding a backup schedule.</div>
    <?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-plus-circle me-1"></i> Create New Backup Plan
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
                        <input type="hidden" name="advanced_options" id="advancedOptionsHidden">
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

    // Build advanced_options from checkboxes on submit
    document.querySelector('form[action="/plans/create"]').addEventListener('submit', function() {
        const opts = [];
        const comp = document.getElementById('compressionType').value;
        if (document.getElementById('optCompression').checked && comp !== 'none') {
            opts.push('--compression ' + comp);
        }
        if (document.getElementById('optExcludeCaches').checked) opts.push('--exclude-caches');
        if (document.getElementById('optOneFs').checked) opts.push('--one-file-system');
        if (document.getElementById('optNoatime').checked) opts.push('--noatime');
        if (document.getElementById('optNumericIds').checked) opts.push('--numeric-ids');
        if (document.getElementById('optNoXattrs').checked) opts.push('--noxattrs');
        if (document.getElementById('optNoAcls').checked) opts.push('--noacls');
        document.getElementById('advancedOptionsHidden').value = opts.join(' ');
    });

    // Edit plan forms: build advanced_options from checkboxes on submit
    document.querySelectorAll('.edit-plan-form').forEach(form => {
        form.addEventListener('submit', function() {
            const opts = [];
            const panel = form.closest('.edit-plan-panel');
            const comp = panel.querySelector('.edit-comp-type').value;
            const checks = panel.querySelectorAll('.edit-borg-opt');
            // Compression is first checkbox
            if (checks[0] && checks[0].checked && comp !== 'none') opts.push('--compression ' + comp);
            if (checks[1] && checks[1].checked) opts.push('--exclude-caches');
            if (checks[2] && checks[2].checked) opts.push('--one-file-system');
            if (checks[3] && checks[3].checked) opts.push('--noatime');
            if (checks[4] && checks[4].checked) opts.push('--numeric-ids');
            if (checks[5] && checks[5].checked) opts.push('--noxattrs');
            if (checks[6] && checks[6].checked) opts.push('--noacls');
            panel.querySelector('.edit-adv-hidden').value = opts.join(' ');
        });
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
            document.getElementById('create-plan-section').style.display = '';
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

    <!-- Archive Selector & Options -->
    <div class="row mb-3">
        <div class="col-md-4">
            <label class="form-label fw-semibold">Select Archive</label>
            <select class="form-select" id="archive-select">
                <option value="">Choose an archive...</option>
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
        <div class="col-md-4">
            <label class="form-label fw-semibold">Search Files</label>
            <div class="input-group">
                <input type="text" class="form-control" id="catalog-search" placeholder="e.g. nginx.conf" disabled>
                <button class="btn btn-outline-secondary" type="button" id="catalog-search-btn" disabled>
                    <i class="bi bi-search"></i>
                </button>
            </div>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Restore Destination (optional)</label>
            <input type="text" class="form-control" id="restore-destination" placeholder="Leave blank for original paths">
        </div>
    </div>

    <!-- Tree + Search Results -->
    <div class="row">
        <!-- Tree Browser -->
        <div class="col-md-7">
            <div class="card border-0 shadow-sm" id="tree-container" style="display:none;">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-folder-fill text-warning me-1"></i> Browse Archive</span>
                    <span class="badge bg-secondary" id="tree-path">/</span>
                </div>
                <div class="card-body p-0" style="max-height: 550px; overflow-y: auto;">
                    <div id="tree-root" class="font-monospace small"></div>
                </div>
            </div>
            <div id="tree-loading" style="display:none;" class="text-center py-4">
                <div class="spinner-border text-primary" role="status"></div>
                <div class="text-muted mt-2">Loading file tree...</div>
            </div>
            <div id="tree-empty" style="display:none;" class="alert alert-warning">
                No file catalog available for this archive. The catalog is created when a backup completes.
            </div>
        </div>

        <!-- Selection Summary -->
        <div class="col-md-5">
            <div class="card border-0 shadow-sm" id="selection-panel" style="display:none;">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-check2-square me-1"></i>
                    Selected Paths (<span id="selected-count">0</span>)
                </div>
                <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                    <ul class="list-group list-group-flush" id="selected-list"></ul>
                    <div class="p-3 text-muted small text-center" id="no-selection">No files or directories selected</div>
                </div>
                <div class="card-footer bg-white d-flex gap-2">
                    <button class="btn btn-success flex-fill" id="restore-btn" disabled>
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Restore to Client
                    </button>
                    <button class="btn btn-primary flex-fill" id="download-btn" disabled>
                        <i class="bi bi-download me-1"></i> Download .tar.gz
                    </button>
                </div>
            </div>

            <!-- Search results -->
            <div class="card border-0 shadow-sm mt-3" id="search-results" style="display:none;">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-search me-1"></i> Search Results (<span id="search-count">0</span>)
                </div>
                <div class="card-body p-0" style="max-height: 300px; overflow-y: auto;">
                    <table class="table table-hover table-sm mb-0">
                        <tbody id="search-body"></tbody>
                    </table>
                </div>
                <div class="card-footer bg-white">
                    <button class="btn btn-sm btn-outline-secondary" id="search-prev" disabled>&laquo; Prev</button>
                    <span class="mx-2 small" id="search-page-info"></span>
                    <button class="btn btn-sm btn-outline-secondary" id="search-next" disabled>Next &raquo;</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden restore form -->
    <form id="restore-form" method="POST" action="/clients/<?= $agent['id'] ?>/restore" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
        <input type="hidden" name="archive_id" id="restore-archive-id">
        <input type="hidden" name="destination" id="restore-dest-field">
        <div id="restore-files-container"></div>
    </form>
    <!-- Hidden download form -->
    <form id="download-form" method="POST" action="/clients/<?= $agent['id'] ?>/download" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
        <input type="hidden" name="archive_id" id="download-archive-id">
        <div id="download-files-container"></div>
    </form>

    <style>
    .tree-item { padding: 3px 0; cursor: pointer; white-space: nowrap; }
    .tree-item:hover { background: #f8f9fa; }
    .tree-dir > .tree-label { font-weight: 600; }
    .tree-children { display: none; }
    .tree-children.open { display: block; }
    .tree-toggle { display: inline-block; width: 16px; text-align: center; color: #6c757d; }
    .tree-cb { margin-right: 4px; }
    .tree-icon { margin-right: 4px; }
    .tree-size { color: #6c757d; margin-left: 8px; }
    .tree-count { color: #6c757d; font-size: 0.8em; margin-left: 6px; }
    </style>

    <script>
    (function() {
        const agentId = <?= $agent['id'] ?>;
        const archiveSelect = document.getElementById('archive-select');
        const searchInput = document.getElementById('catalog-search');
        const searchBtn = document.getElementById('catalog-search-btn');
        const treeContainer = document.getElementById('tree-container');
        const treeLoading = document.getElementById('tree-loading');
        const treeEmpty = document.getElementById('tree-empty');
        const treeRoot = document.getElementById('tree-root');
        const selectionPanel = document.getElementById('selection-panel');
        const selectedList = document.getElementById('selected-list');
        const selectedCountEl = document.getElementById('selected-count');
        const noSelection = document.getElementById('no-selection');
        const restoreBtn = document.getElementById('restore-btn');
        const searchResults = document.getElementById('search-results');
        const searchBody = document.getElementById('search-body');
        const searchCount = document.getElementById('search-count');

        // Selected paths: can be directories (ending with /) or file paths
        let selectedPaths = new Set();

        function formatSize(bytes) {
            if (!bytes || bytes === 0) return '';
            const units = ['B', 'KB', 'MB', 'GB', 'TB'];
            let i = 0, size = bytes;
            while (size >= 1024 && i < units.length - 1) { size /= 1024; i++; }
            return size.toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
        }

        function esc(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

        function statusBadge(s) {
            const map = { A: ['success','N'], M: ['warning','M'], U: ['secondary','U'], E: ['danger','E'] };
            const [color, label] = map[s] || ['secondary', s];
            return '<span class="badge bg-' + color + '" style="font-size:0.7em;">' + label + '</span>';
        }

        // Check if a path is selected (directly or via parent directory)
        function isPathSelected(path) {
            if (selectedPaths.has(path)) return true;
            // Check if any parent directory is selected
            for (const sel of selectedPaths) {
                if (sel.endsWith('/') && path.startsWith(sel)) return true;
            }
            return false;
        }

        function updateSelectionUI() {
            const count = selectedPaths.size;
            selectedCountEl.textContent = count;
            restoreBtn.disabled = count === 0;
            document.getElementById('download-btn').disabled = count === 0;
            noSelection.style.display = count === 0 ? 'block' : 'none';

            selectedList.innerHTML = '';
            selectedPaths.forEach(path => {
                const li = document.createElement('li');
                li.className = 'list-group-item d-flex justify-content-between align-items-center py-1 px-3';
                const isDir = path.endsWith('/');
                li.innerHTML =
                    '<span class="small font-monospace"><i class="bi bi-' + (isDir ? 'folder-fill text-warning' : 'file-earmark') + ' me-1"></i>' + esc(path) + '</span>' +
                    '<button class="btn btn-sm btn-outline-danger border-0 p-0" data-remove="' + esc(path) + '"><i class="bi bi-x-lg"></i></button>';
                selectedList.appendChild(li);
            });
        }

        // Remove from selection
        selectedList.addEventListener('click', function(e) {
            const btn = e.target.closest('[data-remove]');
            if (btn) {
                const path = btn.dataset.remove;
                selectedPaths.delete(path);
                // Uncheck the corresponding checkbox in the tree
                const cb = treeRoot.querySelector('input[data-path="' + CSS.escape(path) + '"]');
                if (cb) cb.checked = false;
                updateSelectionUI();
            }
        });

        function togglePath(path, checked) {
            if (checked) {
                // If selecting a directory, remove any individual children already selected
                if (path.endsWith('/')) {
                    for (const sel of selectedPaths) {
                        if (sel.startsWith(path) && sel !== path) selectedPaths.delete(sel);
                    }
                }
                selectedPaths.add(path);
            } else {
                selectedPaths.delete(path);
            }
            updateSelectionUI();
        }

        function loadTreeNode(parentEl, path) {
            const archiveId = archiveSelect.value;
            if (!archiveId) return;

            const spinner = document.createElement('div');
            spinner.className = 'ps-4 py-1 text-muted small';
            spinner.innerHTML = '<div class="spinner-border spinner-border-sm me-1"></div> Loading...';
            parentEl.appendChild(spinner);

            fetch('/clients/' + agentId + '/catalog/' + archiveId + '/tree?path=' + encodeURIComponent(path), { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    spinner.remove();

                    if (data.dirs.length === 0 && data.files.length === 0) {
                        if (path === '/') {
                            treeContainer.style.display = 'none';
                            treeEmpty.style.display = 'block';
                        }
                        return;
                    }

                    // Render directories
                    data.dirs.forEach(d => {
                        const item = document.createElement('div');
                        item.className = 'tree-item tree-dir';

                        const isChecked = isPathSelected(d.path);

                        item.innerHTML =
                            '<span class="tree-toggle"><i class="bi bi-chevron-right"></i></span>' +
                            '<input type="checkbox" class="tree-cb" data-path="' + esc(d.path) + '" data-type="dir"' + (isChecked ? ' checked' : '') + '>' +
                            '<span class="tree-icon"><i class="bi bi-folder-fill text-warning"></i></span>' +
                            '<span class="tree-label">' + esc(d.name) + '</span>' +
                            '<span class="tree-count">(' + d.file_count.toLocaleString() + ' files, ' + formatSize(d.total_size) + ')</span>';

                        const children = document.createElement('div');
                        children.className = 'tree-children';
                        children.style.paddingLeft = '20px';

                        parentEl.appendChild(item);
                        parentEl.appendChild(children);

                        let loaded = false;

                        // Click to expand/collapse
                        item.querySelector('.tree-toggle, .tree-label').addEventListener('click', function(e) {
                            e.stopPropagation();
                            const isOpen = children.classList.contains('open');
                            if (isOpen) {
                                children.classList.remove('open');
                                item.querySelector('.tree-toggle i').className = 'bi bi-chevron-right';
                            } else {
                                children.classList.add('open');
                                item.querySelector('.tree-toggle i').className = 'bi bi-chevron-down';
                                if (!loaded) {
                                    loaded = true;
                                    loadTreeNode(children, d.path);
                                }
                            }
                        });

                        // Checkbox
                        item.querySelector('.tree-cb').addEventListener('change', function(e) {
                            e.stopPropagation();
                            togglePath(d.path, this.checked);
                            // If checking a dir, check all visible child checkboxes too
                            if (this.checked) {
                                children.querySelectorAll('.tree-cb').forEach(cb => cb.checked = true);
                            } else {
                                children.querySelectorAll('.tree-cb').forEach(cb => cb.checked = false);
                            }
                        });
                    });

                    // Render files
                    data.files.forEach(f => {
                        const item = document.createElement('div');
                        item.className = 'tree-item tree-file';

                        const isChecked = isPathSelected(f.file_path);

                        item.innerHTML =
                            '<span class="tree-toggle"></span>' +
                            '<input type="checkbox" class="tree-cb" data-path="' + esc(f.file_path) + '" data-type="file"' + (isChecked ? ' checked' : '') + '>' +
                            '<span class="tree-icon"><i class="bi bi-file-earmark"></i></span>' +
                            '<span class="tree-label">' + esc(f.file_name) + '</span>' +
                            ' ' + statusBadge(f.status) +
                            '<span class="tree-size">' + formatSize(f.file_size) + '</span>';

                        parentEl.appendChild(item);

                        item.querySelector('.tree-cb').addEventListener('change', function(e) {
                            e.stopPropagation();
                            togglePath(f.file_path, this.checked);
                        });
                    });
                })
                .catch(() => {
                    spinner.remove();
                    if (path === '/') {
                        treeContainer.style.display = 'none';
                        treeEmpty.style.display = 'block';
                    }
                });
        }

        // Archive selection
        archiveSelect.addEventListener('change', function() {
            selectedPaths.clear();
            updateSelectionUI();
            searchInput.disabled = !this.value;
            searchBtn.disabled = !this.value;
            searchResults.style.display = 'none';

            if (this.value) {
                treeEmpty.style.display = 'none';
                treeContainer.style.display = 'block';
                selectionPanel.style.display = 'block';
                treeRoot.innerHTML = '';
                loadTreeNode(treeRoot, '/');
            } else {
                treeContainer.style.display = 'none';
                treeEmpty.style.display = 'none';
                selectionPanel.style.display = 'none';
            }
        });

        // Search
        let searchPage = 1;
        function doSearch(page) {
            const archiveId = archiveSelect.value;
            const search = searchInput.value.trim();
            if (!archiveId || !search) return;

            searchPage = page || 1;
            fetch('/clients/' + agentId + '/catalog/' + archiveId + '?page=' + searchPage + '&search=' + encodeURIComponent(search), { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    searchResults.style.display = 'block';
                    searchCount.textContent = data.total.toLocaleString();
                    document.getElementById('search-page-info').textContent = 'Page ' + data.page + ' of ' + data.pages;
                    document.getElementById('search-prev').disabled = data.page <= 1;
                    document.getElementById('search-next').disabled = data.page >= data.pages;

                    searchBody.innerHTML = '';
                    data.files.forEach(f => {
                        const row = document.createElement('tr');
                        const isChecked = isPathSelected(f.file_path);
                        row.innerHTML =
                            '<td style="width:30px;"><input type="checkbox" class="search-cb" data-path="' + esc(f.file_path) + '"' + (isChecked ? ' checked' : '') + '></td>' +
                            '<td class="small font-monospace">' + esc(f.file_path) + '</td>' +
                            '<td class="small">' + formatSize(f.file_size) + '</td>';
                        searchBody.appendChild(row);
                    });
                });
        }

        searchBtn.addEventListener('click', () => doSearch(1));
        searchInput.addEventListener('keypress', e => { if (e.key === 'Enter') { e.preventDefault(); doSearch(1); } });
        document.getElementById('search-prev').addEventListener('click', () => doSearch(searchPage - 1));
        document.getElementById('search-next').addEventListener('click', () => doSearch(searchPage + 1));

        searchBody.addEventListener('change', function(e) {
            if (e.target.classList.contains('search-cb')) {
                togglePath(e.target.dataset.path, e.target.checked);
            }
        });

        function fillFormAndSubmit(formId, archiveFieldId, filesContainerId) {
            document.getElementById(archiveFieldId).value = archiveSelect.value;
            const container = document.getElementById(filesContainerId);
            container.innerHTML = '';
            selectedPaths.forEach(path => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'files[]';
                input.value = path;
                container.appendChild(input);
            });
            document.getElementById(formId).submit();
        }

        // Restore to client
        restoreBtn.addEventListener('click', function() {
            if (selectedPaths.size === 0) return;
            if (!confirm('Restore ' + selectedPaths.size + ' path(s) to the client? This may overwrite existing files.')) return;
            document.getElementById('restore-dest-field').value = document.getElementById('restore-destination').value;
            fillFormAndSubmit('restore-form', 'restore-archive-id', 'restore-files-container');
        });

        // Download as tar.gz
        const downloadBtn = document.getElementById('download-btn');
        downloadBtn.addEventListener('click', function() {
            if (selectedPaths.size === 0) return;
            if (!confirm('Download ' + selectedPaths.size + ' path(s) as a .tar.gz archive?')) return;
            fillFormAndSubmit('download-form', 'download-archive-id', 'download-files-container');
        });
    })();
    </script>

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
