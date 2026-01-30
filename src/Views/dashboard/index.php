<!-- Stat Cards -->
<div class="row g-4 mb-4">
    <div class="col-xl-3 col-md-6">
        <a href="/clients" class="text-decoration-none">
            <div class="card stat-card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary rounded-3 p-3 me-3">
                        <i class="bi bi-display fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Clients</div>
                        <div class="fs-2 fw-bold text-dark" id="stat-agents"><?= $agentCount ?></div>
                        <div class="text-muted small"><span id="stat-online"><?= $onlineCount ?></span> online</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl-3 col-md-6">
        <a href="/queue" class="text-decoration-none">
            <div class="card stat-card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-success bg-opacity-10 text-success rounded-3 p-3 me-3">
                        <i class="bi bi-arrow-repeat fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Backups Running</div>
                        <div class="fs-2 fw-bold text-dark" id="stat-running"><?= $runningJobs ?></div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl-3 col-md-6">
        <a href="/queue" class="text-decoration-none">
            <div class="card stat-card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning rounded-3 p-3 me-3">
                        <i class="bi bi-hourglass-split fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Queue Waiting</div>
                        <div class="fs-2 fw-bold text-dark" id="stat-queued"><?= $queuedJobs ?></div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl-3 col-md-6">
        <a href="/log?level=error" class="text-decoration-none">
            <div class="card stat-card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-danger bg-opacity-10 text-danger rounded-3 p-3 me-3">
                        <i class="bi bi-exclamation-circle fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Errors (24h)</div>
                        <div class="fs-2 fw-bold text-dark" id="stat-errors"><?= $errorCount ?></div>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Row 2: Chart + Server Stats -->
<div class="row g-4 mb-4">
    <!-- Backups Chart -->
    <div class="<?= $isAdmin ? 'col-lg-5' : 'col-12' ?>">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-bar-chart me-1"></i> Backups Completed (24h)
            </div>
            <div class="card-body">
                <canvas id="backupsChart" height="180"></canvas>
            </div>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <!-- Server Stats -->
    <div class="col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-cpu me-1"></i> Server Stats
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <div class="d-flex justify-content-between small mb-1">
                        <span>CPU Load</span>
                        <span id="cpu-text"><?= $cpuLoad['percent'] ?>%</span>
                    </div>
                    <div class="progress" style="height: 22px;">
                        <div class="progress-bar <?= $cpuLoad['percent'] > 80 ? 'bg-danger' : ($cpuLoad['percent'] > 50 ? 'bg-warning' : 'bg-success') ?>"
                             id="cpu-bar" style="width: <?= $cpuLoad['percent'] ?>%">
                            <?= $cpuLoad['1min'] ?> / <?= $cpuLoad['cores'] ?> cores
                        </div>
                    </div>
                </div>

                <div>
                    <div class="d-flex justify-content-between small mb-1">
                        <span>Memory</span>
                        <span id="mem-text"><?= $memory['percent'] ?>%</span>
                    </div>
                    <div class="progress" style="height: 22px;">
                        <div class="progress-bar <?= $memory['percent'] > 85 ? 'bg-danger' : ($memory['percent'] > 60 ? 'bg-warning' : 'bg-info') ?>"
                             id="mem-bar" style="width: <?= $memory['percent'] ?>%">
                            <?= \BBS\Services\ServerStats::formatBytes($memory['used']) ?> / <?= \BBS\Services\ServerStats::formatBytes($memory['total']) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Storage -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-hdd-stack me-1"></i> Storage
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>Partition</th>
                                <th style="min-width: 140px;">% Used</th>
                                <th>Size</th>
                                <th>Free</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($partitions as $part): ?>
                            <tr>
                                <td><i class="bi bi-device-hdd text-muted me-1"></i> <?= htmlspecialchars($part['mount']) ?></td>
                                <td>
                                    <div class="progress" style="height: 18px; background-color: #e9ecef;">
                                        <div class="progress-bar progress-bar-striped <?= $part['percent'] > 90 ? 'bg-danger' : ($part['percent'] > 70 ? 'bg-warning' : '') ?>"
                                             style="width: <?= $part['percent'] ?>%; background-color: <?= $part['percent'] <= 70 ? '#8faabe' : '' ?>;">
                                        </div>
                                    </div>
                                </td>
                                <td><?= $part['size'] ?></td>
                                <td><?= $part['free'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php foreach ($storageLocations as $loc): ?>
                            <tr>
                                <td style="border-bottom: none;" class="pb-0"><i class="bi bi-archive text-success me-1"></i> <span class="fw-semibold"><?= htmlspecialchars($loc['label']) ?></span></td>
                                <td style="border-bottom: none;" class="pb-0">
                                    <?php if ($loc['disk_percent'] !== null): ?>
                                    <div class="progress" style="height: 18px; background-color: #e9ecef;">
                                        <div class="progress-bar progress-bar-striped <?= $loc['disk_percent'] > 90 ? 'bg-danger' : ($loc['disk_percent'] > 70 ? 'bg-warning' : '') ?>"
                                             style="width: <?= $loc['disk_percent'] ?>%; background-color: <?= $loc['disk_percent'] <= 70 ? '#8faabe' : '' ?>;">
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td style="border-bottom: none;" class="pb-0"><?= $loc['disk_total'] !== null ? \BBS\Services\ServerStats::formatBytes($loc['disk_total']) : '--' ?></td>
                                <td style="border-bottom: none;" class="pb-0"><?= $loc['disk_free'] !== null ? \BBS\Services\ServerStats::formatBytes($loc['disk_free']) : 'N/A' ?></td>
                            </tr>
                            <tr>
                                <td colspan="4" class="pt-0 text-muted" style="font-size: 0.75em;"><?= htmlspecialchars($loc['path']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="row g-4">
    <!-- Active Jobs -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-play-circle me-1"></i> Active &amp; Queued Jobs
            </div>
            <div class="card-body p-0" id="active-jobs">
                <?php if (empty($activeJobs)): ?>
                    <div class="p-4 text-muted text-center">No active jobs</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Client</th>
                                <th>Task</th>
                                <th>Plan</th>
                                <th>Repo</th>
                                <th>Progress</th>
                                <th>Duration</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeJobs as $job): ?>
                            <?php
                                $elapsed = '';
                                if ($job['started_at']) {
                                    $e = time() - strtotime($job['started_at']);
                                    $elapsed = $e >= 3600 ? floor($e / 3600) . 'h ' . floor(($e % 3600) / 60) . 'm'
                                        : ($e >= 60 ? floor($e / 60) . 'm ' . ($e % 60) . 's' : $e . 's');
                                }
                            ?>
                            <tr style="cursor: pointer;" onclick="window.location='/queue/<?= $job['id'] ?>'">
                                <td><?= htmlspecialchars($job['agent_name']) ?></td>
                                <td><?= ucfirst($job['task_type']) ?></td>
                                <td><?= htmlspecialchars($job['plan_name'] ?? '--') ?></td>
                                <td><?= htmlspecialchars($job['repo_name'] ?? '--') ?></td>
                                <td style="min-width: 140px;">
                                    <?php if ($job['status'] === 'queued'): ?>
                                        <span class="text-muted">Waiting</span>
                                    <?php elseif (($job['files_total'] ?? 0) > 0): ?>
                                        <?php $pct = round(($job['files_processed'] / $job['files_total']) * 100); ?>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width: <?= $pct ?>%">
                                                <?= $pct ?>% (<?= number_format($job['files_processed']) ?>/<?= number_format($job['files_total']) ?>)
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar progress-bar-striped progress-bar-animated bg-info" style="width: 100%">Preparing...</div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-nowrap"><?= $elapsed ?: '--' ?></td>
                                <?php
                                $jobBadge = match($job['status']) {
                                    'running' => 'info',
                                    'sent' => 'primary',
                                    'queued' => 'warning',
                                    default => 'secondary',
                                };
                                ?>
                                <td><span class="badge bg-<?= $jobBadge ?>"><?= $job['status'] ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Upcoming Backups -->
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-calendar-event me-1"></i> Upcoming Backups
            </div>
            <div class="card-body p-0">
                <?php if (empty($upcomingSchedules)): ?>
                    <div class="p-4 text-muted text-center">No scheduled backups</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Client</th>
                                <th>Plan</th>
                                <th>Frequency</th>
                                <th>Next Run</th>
                                <th>Countdown</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcomingSchedules as $sched): ?>
                            <?php
                                $nextDiff = strtotime($sched['next_run']) - time();
                                if ($nextDiff < 0) {
                                    $countdown = 'Overdue';
                                    $countdownClass = 'text-danger fw-semibold';
                                } elseif ($nextDiff < 3600) {
                                    $countdown = floor($nextDiff / 60) . 'm';
                                    $countdownClass = 'text-warning fw-semibold';
                                } elseif ($nextDiff < 86400) {
                                    $countdown = floor($nextDiff / 3600) . 'h ' . floor(($nextDiff % 3600) / 60) . 'm';
                                    $countdownClass = '';
                                } else {
                                    $countdown = floor($nextDiff / 86400) . 'd ' . floor(($nextDiff % 86400) / 3600) . 'h';
                                    $countdownClass = 'text-muted';
                                }
                            ?>
                            <tr style="cursor: pointer;" onclick="window.location='/clients/<?= $sched['agent_id'] ?>?tab=schedules'">
                                <td><?= htmlspecialchars($sched['agent_name']) ?></td>
                                <td><?= htmlspecialchars($sched['plan_name']) ?></td>
                                <td><?= ucfirst($sched['frequency']) ?></td>
                                <td class="small text-nowrap"><?= \BBS\Core\TimeHelper::format($sched['next_run'], 'M j, g:i A') ?></td>
                                <td class="<?= $countdownClass ?>"><?= $countdown ?></td>
                                <td class="text-nowrap" onclick="event.stopPropagation()">
                                    <form method="POST" action="/plans/<?= $sched['plan_id'] ?>/trigger" class="d-inline" onsubmit="return confirm('Run this backup now?')">
                                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success py-0 px-2" title="Run now">
                                            <i class="bi bi-play-fill"></i>
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
    </div>

    <!-- Recently Completed -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-check-circle me-1"></i> Recently Completed
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentJobs)): ?>
                    <div class="p-4 text-muted text-center">No completed jobs yet</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Client</th>
                                <th>Task</th>
                                <th>Plan</th>
                                <th>Repo</th>
                                <th>Completed</th>
                                <th>Duration</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentJobs as $job): ?>
                            <?php
                                $d = $job['duration_seconds'] ?? 0;
                                $durStr = $d >= 3600 ? floor($d / 3600) . 'h ' . floor(($d % 3600) / 60) . 'm'
                                    : ($d >= 60 ? floor($d / 60) . 'm ' . ($d % 60) . 's' : ($d > 0 ? $d . 's' : '--'));
                                $sBadge = match($job['status']) {
                                    'completed' => 'success',
                                    'failed' => 'danger',
                                    'cancelled' => 'secondary',
                                    default => 'warning',
                                };
                            ?>
                            <tr style="cursor: pointer;" onclick="window.location='/queue/<?= $job['id'] ?>'">
                                <td><?= htmlspecialchars($job['agent_name']) ?></td>
                                <td><?= ucfirst($job['task_type']) ?></td>
                                <td><?= htmlspecialchars($job['plan_name'] ?? '--') ?></td>
                                <td><?= htmlspecialchars($job['repo_name'] ?? '--') ?></td>
                                <td class="small text-nowrap"><?= \BBS\Core\TimeHelper::format($job['completed_at'], 'M j, g:i A') ?></td>
                                <td class="text-nowrap"><?= $durStr ?></td>
                                <td><span class="badge bg-<?= $sBadge ?>"><?= $job['status'] ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Server Log -->
<div class="row g-4 mt-0">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span><i class="bi bi-journal-text me-1"></i> Server Log</span>
                <a href="/log" class="text-decoration-none small">View all</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentLogs)): ?>
                    <div class="p-4 text-muted text-center">No log entries</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>Time</th>
                                <th>Client</th>
                                <th>Level</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentLogs as $log): ?>
                            <tr>
                                <td class="text-nowrap"><?= \BBS\Core\TimeHelper::format($log['created_at'], 'M j, g:i A') ?></td>
                                <td><?= htmlspecialchars($log['agent_name'] ?? '--') ?></td>
                                <td>
                                    <?php
                                    $levelClass = match($log['level']) {
                                        'error' => 'danger',
                                        'warning' => 'warning',
                                        default => 'info',
                                    };
                                    ?>
                                    <span class="badge bg-<?= $levelClass ?>"><?= $log['level'] ?></span>
                                </td>
                                <td><?= htmlspecialchars($log['message']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
// Backups Chart
const chartData = <?= json_encode($chartData) ?>;
const ctx = document.getElementById('backupsChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: chartData.map(d => d.label),
        datasets: [{
            label: 'Backups',
            data: chartData.map(d => d.count),
            backgroundColor: 'rgba(54, 162, 235, 0.7)',
            borderColor: 'rgba(54, 162, 235, 1)',
            borderWidth: 1,
            borderRadius: 3,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { stepSize: 1, font: { size: 10 } },
                grid: { color: 'rgba(0,0,0,0.05)' },
            },
            x: {
                ticks: {
                    font: { size: 9 },
                    maxRotation: 45,
                    callback: function(val, index) {
                        return index % 3 === 0 ? this.getLabelForValue(val) : '';
                    }
                },
                grid: { display: false },
            }
        }
    }
});

// Auto-refresh stat cards every 8 seconds
setInterval(function() {
    fetch('/dashboard/json', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            document.getElementById('stat-agents').textContent = data.agentCount;
            document.getElementById('stat-online').textContent = data.onlineCount;
            document.getElementById('stat-running').textContent = data.runningJobs;
            document.getElementById('stat-queued').textContent = data.queuedJobs;
            document.getElementById('stat-errors').textContent = data.errorCount;

            <?php if ($isAdmin): ?>
            // Update CPU
            if (data.cpuLoad) {
                document.getElementById('cpu-text').textContent = data.cpuLoad.percent + '%';
                const cpuBar = document.getElementById('cpu-bar');
                cpuBar.style.width = data.cpuLoad.percent + '%';
                cpuBar.textContent = data.cpuLoad['1min'] + ' / ' + data.cpuLoad.cores + ' cores';
            }

            // Update Memory
            if (data.memory) {
                document.getElementById('mem-text').textContent = data.memory.percent + '%';
                const memBar = document.getElementById('mem-bar');
                memBar.style.width = data.memory.percent + '%';
            }
            <?php endif; ?>
        })
        .catch(() => {});
}, 8000);
</script>
