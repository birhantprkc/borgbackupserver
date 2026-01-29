<?php
$tab = $_GET['tab'] ?? 'status';
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
                <div class="text-muted mt-1">
                    <?php if ($agent['hostname']): ?>
                        <i class="bi bi-hdd-network me-1"></i><?= htmlspecialchars($agent['hostname']) ?>
                        <?php if ($agent['ip_address'] ?? null): ?>
                            <span class="ms-2"><i class="bi bi-globe me-1"></i><?= htmlspecialchars($agent['ip_address']) ?></span>
                        <?php endif; ?>
                        <span class="ms-2">&middot;</span>
                    <?php endif; ?>
                    <?php if ($agent['os_info']): ?>
                        <span class="ms-1"><i class="bi bi-cpu me-1"></i><?= htmlspecialchars($agent['os_info']) ?></span>
                    <?php endif; ?>
                    <?php if ($agent['agent_version']): ?>
                        <span class="ms-2"><i class="bi bi-box me-1"></i>Agent v<?= htmlspecialchars($agent['agent_version']) ?></span>
                    <?php endif; ?>
                    <?php if ($agent['borg_version']): ?>
                        <span class="ms-2"><i class="bi bi-archive me-1"></i>Borg <?= htmlspecialchars($agent['borg_version']) ?></span>
                        <form method="POST" action="/clients/<?= $agent['id'] ?>/update-borg" class="d-inline ms-1">
                            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                            <button type="submit" class="btn btn-sm btn-outline-info border-0 py-0 px-1" title="Update Borg on this client" onclick="return confirm('Queue a borg update on this client?')">
                                <i class="bi bi-arrow-up-circle"></i>
                            </button>
                        </form>
                    <?php endif; ?>
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
            $diff = time() - strtotime($agent['last_heartbeat']);
            if ($diff < 60) $seenAgo = $diff . 's ago';
            elseif ($diff < 3600) $seenAgo = floor($diff / 60) . 'm ago';
            elseif ($diff < 86400) $seenAgo = floor($diff / 3600) . 'h ago';
            else $seenAgo = floor($diff / 86400) . 'd ago';
        } else {
            $seenAgo = 'Never';
        }
        $lastBackupLabel = $lastJob ? date('M j g:ia', strtotime($lastJob['completed_at'])) : '--';
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
<ul class="nav nav-pills client-tabs mb-0">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'status' ? 'active' : '' ?>" href="?tab=status">
            <i class="bi bi-activity me-1"></i> Status
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'repos' ? 'active' : '' ?>" href="?tab=repos">
            <i class="bi bi-archive me-1"></i> Repos
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'schedules' ? 'active' : '' ?>" href="?tab=schedules">
            <i class="bi bi-calendar-event me-1"></i> Schedules
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'restore' ? 'active' : '' ?>" href="?tab=restore">
            <i class="bi bi-arrow-counterclockwise me-1"></i> Restore
        </a>
    </li>
    <?php if ($this->isAdmin()): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'install' ? 'active' : '' ?>" href="?tab=install">
            <i class="bi bi-download me-1"></i> Install Agent
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link text-danger <?= $tab === 'delete' ? 'active' : '' ?>" href="?tab=delete">
            <i class="bi bi-trash me-1"></i> Delete
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
                            <small class="text-muted"><?= date('M j g:ia', strtotime($job['started_at'] ?? $job['queued_at'])) ?></small>
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
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Storage</th>
                            <th>Encryption</th>
                            <th>Size</th>
                            <th>Archives</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($repositories as $repo): ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars($repo['name']) ?> <small class="text-muted">(#<?= $repo['id'] ?>)</small></td>
                            <td class="small"><?= htmlspecialchars($repo['storage_label'] ?? '--') ?></td>
                            <td><code class="small"><?= htmlspecialchars($repo['encryption'] ?? '--') ?></code></td>
                            <td>
                                <?php
                                $s = $repo['size_bytes'];
                                echo $s >= 1073741824 ? round($s / 1073741824, 1) . ' GB' : ($s >= 1048576 ? round($s / 1048576, 1) . ' MB' : ($s > 0 ? round($s / 1024, 1) . ' KB' : '--'));
                                ?>
                            </td>
                            <td><?= $repo['archive_count'] ?></td>
                            <td class="text-end">
                                <form method="POST" action="/repositories/<?= $repo['id'] ?>/delete" class="d-inline" onsubmit="return confirm('Delete this repository?')">
                                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
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
                    <label class="col-md-3 col-form-label fw-semibold">Storage Location</label>
                    <div class="col-md-6">
                        <select class="form-select" name="storage_location_id">
                            <option value="">-- Select --</option>
                            <?php foreach ($storageLocations as $loc): ?>
                            <option value="<?= $loc['id'] ?>" <?= $loc['is_default'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($loc['label']) ?> (<?= htmlspecialchars($loc['path']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
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
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Plan</th>
                            <th>Frequency</th>
                            <th>Repository</th>
                            <th>Directories</th>
                            <th>Retention</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($plans as $plan): ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars($plan['name']) ?></td>
                            <td>
                                <?= ucfirst($plan['frequency'] ?? 'manual') ?>
                                <?php if ($plan['times']): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($plan['times']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($plan['repo_name'] ?? '--') ?></td>
                            <td><code class="small"><?= htmlspecialchars(str_replace("\n", ', ', $plan['directories'])) ?></code></td>
                            <td class="small text-muted text-nowrap">
                                <?php if ($plan['prune_hours']): ?><?= $plan['prune_hours'] ?>hr <?php endif; ?>
                                <?php if ($plan['prune_days']): ?><?= $plan['prune_days'] ?>d <?php endif; ?>
                                <?php if ($plan['prune_weeks']): ?><?= $plan['prune_weeks'] ?>w <?php endif; ?>
                                <?php if ($plan['prune_months']): ?><?= $plan['prune_months'] ?>mo <?php endif; ?>
                                <?php if ($plan['prune_years']): ?><?= $plan['prune_years'] ?>yr <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($plan['schedule_enabled'] ?? false): ?>
                                <span class="badge bg-success">Active</span>
                                <?php elseif (($plan['frequency'] ?? '') === 'manual'): ?>
                                <span class="badge bg-info">Manual</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">Paused</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <form method="POST" action="/plans/<?= $plan['id'] ?>/trigger" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Run Now"><i class="bi bi-play-fill"></i></button>
                                </form>
                                <?php if ($plan['schedule_id']): ?>
                                <form method="POST" action="/schedules/<?= $plan['schedule_id'] ?>/toggle" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                    <?php if ($plan['schedule_enabled'] ?? false): ?>
                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Pause"><i class="bi bi-pause-fill"></i></button>
                                    <?php else: ?>
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Resume"><i class="bi bi-play-fill"></i></button>
                                    <?php endif; ?>
                                </form>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#edit-plan-<?= $plan['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></button>
                                <form method="POST" action="/plans/<?= $plan['id'] ?>/delete" class="d-inline" onsubmit="return confirm('Delete this backup plan and its schedule?')">
                                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
                    <label class="col-md-3 col-form-label fw-semibold">Frequency</label>
                    <div class="col-md-6">
                        <select class="form-select" name="frequency" id="frequencySelect">
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

                <div class="row mb-3" id="timesRow">
                    <label class="col-md-3 col-form-label fw-semibold">Times</label>
                    <div class="col-md-6">
                        <input type="text" class="form-control" name="times" placeholder="01:00, 04:00, 16:00, 20:00" value="01:00">
                    </div>
                    <div class="col-md-3 form-text pt-2">Comma-separated, 24h format</div>
                </div>

                <div class="row mb-3 d-none" id="dayOfWeekRow">
                    <label class="col-md-3 col-form-label fw-semibold">Day of Week</label>
                    <div class="col-md-6">
                        <select class="form-select" name="day_of_week">
                            <option value="0">Sunday</option>
                            <option value="1" selected>Monday</option>
                            <option value="2">Tuesday</option>
                            <option value="3">Wednesday</option>
                            <option value="4">Thursday</option>
                            <option value="5">Friday</option>
                            <option value="6">Saturday</option>
                        </select>
                    </div>
                </div>

                <div class="row mb-3 d-none" id="dayOfMonthRow">
                    <label class="col-md-3 col-form-label fw-semibold">Day of Month</label>
                    <div class="col-md-6">
                        <input type="number" class="form-control" name="day_of_month" min="1" max="28" value="1">
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
    document.getElementById('frequencySelect').addEventListener('change', function() {
        const freq = this.value;
        document.getElementById('timesRow').classList.toggle('d-none', freq === 'manual' || freq.endsWith('min'));
        document.getElementById('dayOfWeekRow').classList.toggle('d-none', freq !== 'weekly');
        document.getElementById('dayOfMonthRow').classList.toggle('d-none', freq !== 'monthly');
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
                <?php foreach ($archives as $ar): ?>
                    <option value="<?= $ar['id'] ?>">
                        <?= htmlspecialchars($ar['archive_name']) ?> — <?= $ar['repo_name'] ?>
                        (<?= number_format($ar['file_count']) ?> files, <?= date('M j g:ia', strtotime($ar['created_at'])) ?>)
                    </option>
                <?php endforeach; ?>
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
            <p>Run this command on the endpoint to install the BBS agent:</p>
            <div class="bg-dark text-white p-3 rounded mb-3" style="font-family: monospace; font-size: 0.9rem; word-break: break-all;">
                curl -s https://<?= htmlspecialchars($serverHost) ?>/agent/install.sh | sudo bash -s -- --server https://<?= htmlspecialchars($serverHost) ?> --key <?= htmlspecialchars($agent['api_key']) ?>
            </div>

            <h6 class="mt-4">API Key</h6>
            <div class="input-group mb-3" style="max-width: 600px;">
                <input type="text" class="form-control font-monospace" value="<?= htmlspecialchars($agent['api_key']) ?>" readonly id="apiKeyInput">
                <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('apiKeyInput').value)">
                    <i class="bi bi-clipboard"></i> Copy
                </button>
            </div>
            <p class="text-muted small">This key authenticates the agent with this server. Keep it secure.</p>
        </div>
    </div>

<?php elseif ($tab === 'delete'): ?>
    <h5 class="mb-3 text-danger">Delete Client</h5>

    <div class="card border-0 shadow-sm border-danger">
        <div class="card-body">
            <p>This will permanently delete <strong><?= htmlspecialchars($agent['name']) ?></strong> and all associated repositories, schedules, backup plans, and job history.</p>
            <p class="text-danger fw-semibold">This action cannot be undone.</p>

            <form method="POST" action="/clients/<?= $agent['id'] ?>/delete" onsubmit="return confirm('Are you sure? This will delete all data for this client.')">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-trash me-1"></i> Delete Client
                </button>
                <a href="/clients/<?= $agent['id'] ?>" class="btn btn-outline-secondary ms-2">Cancel</a>
            </form>
        </div>
    </div>
<?php endif; ?>
</div><!-- /client-tab-content -->
