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
                <i class="bi bi-bar-chart me-1"></i> Backups (24h)
            </div>
            <div class="card-body">
                <canvas id="backupsChart" height="160"></canvas>
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
                <?php
                    $cpuColor = $cpuLoad['percent'] > 80 ? '#dc3545' : ($cpuLoad['percent'] > 50 ? '#ffc107' : '#198754');
                    $memColor = $memory['percent'] > 85 ? '#dc3545' : ($memory['percent'] > 60 ? '#ffc107' : '#0dcaf0');
                    $arcLen = 212.06; // 270° arc on r=45: 2*π*45*0.75
                    $circum = 282.74;
                    $cpuDash = $arcLen * $cpuLoad['percent'] / 100;
                    $memDash = $arcLen * $memory['percent'] / 100;
                ?>
                <div class="d-flex justify-content-around">
                    <div class="text-center" style="width:120px;">
                        <svg viewBox="0 0 120 95" style="width:100%;height:auto;">
                            <circle cx="60" cy="55" r="45" fill="none" stroke="#e9ecef" stroke-width="8"
                                stroke-dasharray="<?= $arcLen ?> <?= $circum ?>" stroke-linecap="round"
                                transform="rotate(135 60 55)"/>
                            <circle cx="60" cy="55" r="45" fill="none" stroke="<?= $cpuColor ?>" stroke-width="8"
                                id="cpu-arc" stroke-dasharray="<?= $cpuDash ?> <?= $circum ?>" stroke-linecap="round"
                                transform="rotate(135 60 55)" style="transition: stroke-dasharray .5s ease, stroke .5s ease;"/>
                            <text x="60" y="48" text-anchor="middle" font-size="18" font-weight="bold" fill="#333" id="cpu-pct"><?= $cpuLoad['percent'] ?>%</text>
                            <text x="60" y="62" text-anchor="middle" font-size="8" fill="#888" id="cpu-detail"><?= $cpuLoad['1min'] ?> / <?= $cpuLoad['cores'] ?> cores</text>
                        </svg>
                        <div class="text-muted" style="font-size:.75rem;margin-top:-8px;">CPU</div>
                    </div>
                    <div class="text-center" style="width:120px;">
                        <svg viewBox="0 0 120 95" style="width:100%;height:auto;">
                            <circle cx="60" cy="55" r="45" fill="none" stroke="#e9ecef" stroke-width="8"
                                stroke-dasharray="<?= $arcLen ?> <?= $circum ?>" stroke-linecap="round"
                                transform="rotate(135 60 55)"/>
                            <circle cx="60" cy="55" r="45" fill="none" stroke="<?= $memColor ?>" stroke-width="8"
                                id="mem-arc" stroke-dasharray="<?= $memDash ?> <?= $circum ?>" stroke-linecap="round"
                                transform="rotate(135 60 55)" style="transition: stroke-dasharray .5s ease, stroke .5s ease;"/>
                            <text x="60" y="48" text-anchor="middle" font-size="18" font-weight="bold" fill="#333" id="mem-pct"><?= $memory['percent'] ?>%</text>
                            <text x="60" y="62" text-anchor="middle" font-size="8" fill="#888" id="mem-detail"><?= \BBS\Services\ServerStats::formatBytes($memory['used']) ?> / <?= \BBS\Services\ServerStats::formatBytes($memory['total']) ?></text>
                        </svg>
                        <div class="text-muted" style="font-size:.75rem;margin-top:-8px;">Memory</div>
                    </div>
                </div>
                <?php if (!empty($mysqlStorage) && $mysqlStorage['disk_total'] > 0): ?>
                <hr class="my-2">
                <div class="small text-muted mb-1">MySQL Partition</div>
                <?php
                    $ms = $mysqlStorage;
                    $dbPct = round($ms['db_bytes'] / $ms['disk_total'] * 100, 1);
                    $usedPct = round($ms['disk_used'] / $ms['disk_total'] * 100, 1);
                    $freePct = round($ms['disk_free'] / $ms['disk_total'] * 100, 1);
                ?>
                <div class="rounded overflow-hidden d-flex" id="mysql-bar" style="height:22px;background:#e9ecef;font-size:.65rem;">
                    <div style="width:<?= $dbPct ?>%;background:#0d6efd;color:#fff;overflow:hidden;white-space:nowrap;padding:0 4px;line-height:22px;"
                         title="MySQL Data: <?= \BBS\Services\ServerStats::formatBytes($ms['db_bytes']) ?>">
                        DB <?= \BBS\Services\ServerStats::formatBytes($ms['db_bytes']) ?>
                    </div>
                    <div style="width:<?= max($usedPct - $dbPct, 0) ?>%;background:#6c757d;color:#fff;overflow:hidden;white-space:nowrap;padding:0 4px;line-height:22px;"
                         title="Other used: <?= \BBS\Services\ServerStats::formatBytes($ms['disk_used'] - $ms['db_bytes']) ?>">
                        Other
                    </div>
                    <div style="width:<?= $freePct ?>%;background:#e9ecef;color:#666;overflow:hidden;white-space:nowrap;padding:0 4px;line-height:22px;"
                         title="Free: <?= \BBS\Services\ServerStats::formatBytes($ms['disk_free']) ?>">
                        <?= \BBS\Services\ServerStats::formatBytes($ms['disk_free']) ?> free
                    </div>
                </div>
                <div class="d-flex justify-content-between mt-1" style="font-size:.6rem;color:#999;">
                    <span>Total: <?= \BBS\Services\ServerStats::formatBytes($ms['disk_total']) ?></span>
                    <span id="mysql-free-text"><?= $freePct ?>% free</span>
                </div>
                <?php endif; ?>
                <?php if (!empty($mysqlStats)): ?>
                <hr class="my-2">
                <div class="small text-muted mb-2">Database Records</div>
                <div class="row g-2 text-center" style="font-size:.7rem;">
                    <div class="col-4">
                        <div class="rounded py-1" style="background:#f0f4ff;">
                            <div class="fw-bold text-primary" style="font-size:1rem;" id="stat-total-rows"><?= number_format($mysqlStats['total_rows']) ?></div>
                            <div class="text-muted">Total Rows</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="rounded py-1" style="background:#f0faf0;">
                            <div class="fw-bold text-success" style="font-size:1rem;" id="stat-archives"><?= number_format($mysqlStats['archives']) ?></div>
                            <div class="text-muted">Archives</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="rounded py-1" style="background:#fff8f0;">
                            <div class="fw-bold" style="font-size:1rem;color:#e67e22;" id="stat-catalog"><?= number_format($mysqlStats['catalog_files']) ?></div>
                            <div class="text-muted">Catalog</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="rounded py-1" style="background:#f5f0ff;">
                            <div class="fw-bold text-purple" style="font-size:1rem;color:#6f42c1;" id="stat-paths"><?= number_format($mysqlStats['unique_paths']) ?></div>
                            <div class="text-muted">File Paths</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="rounded py-1" style="background:#f0faff;">
                            <div class="fw-bold text-info" style="font-size:1rem;" id="stat-completed-jobs"><?= number_format($mysqlStats['completed_jobs']) ?></div>
                            <div class="text-muted">Jobs Run</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="rounded py-1" style="background:#fef0f0;">
                            <div class="fw-bold text-danger" style="font-size:1rem;" id="stat-repos"><?= number_format($mysqlStats['repositories']) ?></div>
                            <div class="text-muted">Repos</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
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
                                <th style="min-width: 100px;">% Used</th>
                                <th class="d-th-md">Size</th>
                                <th class="d-th-md">Free</th>
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
                                <td class="d-table-cell-md"><?= $part['size'] ?></td>
                                <td class="d-table-cell-md"><?= $part['free'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!empty($storage)): ?>
                            <tr>
                                <td style="border-bottom: none;" class="pb-0"><i class="bi bi-archive text-success me-1"></i> <span class="fw-semibold">Storage</span></td>
                                <td style="border-bottom: none;" class="pb-0">
                                    <?php if ($storage['disk_percent'] !== null): ?>
                                    <div class="progress" style="height: 18px; background-color: #e9ecef;">
                                        <div class="progress-bar progress-bar-striped <?= $storage['disk_percent'] > 90 ? 'bg-danger' : ($storage['disk_percent'] > 70 ? 'bg-warning' : '') ?>"
                                             style="width: <?= $storage['disk_percent'] ?>%; background-color: <?= $storage['disk_percent'] <= 70 ? '#8faabe' : '' ?>;">
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td style="border-bottom: none;" class="pb-0 d-table-cell-md"><?= $storage['disk_total'] !== null ? \BBS\Services\ServerStats::formatBytes($storage['disk_total']) : '--' ?></td>
                                <td style="border-bottom: none;" class="pb-0 d-table-cell-md"><?= $storage['disk_free'] !== null ? \BBS\Services\ServerStats::formatBytes($storage['disk_free']) : 'N/A' ?></td>
                            </tr>
                            <tr>
                                <td colspan="4" class="pt-0 text-muted" style="font-size: 0.75em;"><?= htmlspecialchars($storage['path']) ?></td>
                            </tr>
                            <?php endif; ?>
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
                                <th class="d-th-md">Plan</th>
                                <th class="d-th-md">Repo</th>
                                <th>Progress</th>
                                <th class="d-th-md">Duration</th>
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
                                <td class="d-table-cell-md"><?= htmlspecialchars($job['plan_name'] ?? '--') ?></td>
                                <td class="d-table-cell-md"><?= htmlspecialchars($job['repo_name'] ?? '--') ?></td>
                                <td style="min-width: 100px;">
                                    <?php if ($job['status'] === 'queued'): ?>
                                        <span class="text-muted">Waiting</span>
                                    <?php elseif (($job['files_total'] ?? 0) > 0): ?>
                                        <?php $pct = round(($job['files_processed'] / $job['files_total']) * 100); ?>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width: <?= $pct ?>%">
                                                <?= $pct ?>%
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar progress-bar-striped progress-bar-animated bg-info" style="width: 100%">Preparing...</div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="d-table-cell-md text-nowrap"><?= $elapsed ?: '--' ?></td>
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
            <div class="card-body p-0" id="upcoming-backups">
                <?php if (empty($upcomingSchedules)): ?>
                    <div class="p-4 text-muted text-center">No scheduled backups</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Client</th>
                                <th>Plan</th>
                                <th class="d-th-md">Frequency</th>
                                <th>Next Run</th>
                                <th class="d-th-md">Countdown</th>
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
                                <td class="d-table-cell-md"><?= ucfirst($sched['frequency']) ?></td>
                                <td class="small"><?= \BBS\Core\TimeHelper::format($sched['next_run'], 'M j, g:i A') ?></td>
                                <td class="d-table-cell-md <?= $countdownClass ?>"><?= $countdown ?></td>
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
            <div class="card-body p-0" id="recent-jobs">
                <?php if (empty($recentJobs)): ?>
                    <div class="p-4 text-muted text-center">No completed jobs yet</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Client</th>
                                <th>Task</th>
                                <th class="d-th-md">Plan</th>
                                <th class="d-th-md">Repo</th>
                                <th>Completed</th>
                                <th class="d-th-md">Duration</th>
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
                                <td class="d-table-cell-md"><?= htmlspecialchars($job['plan_name'] ?? '--') ?></td>
                                <td class="d-table-cell-md"><?= htmlspecialchars($job['repo_name'] ?? '--') ?></td>
                                <td class="small"><?= \BBS\Core\TimeHelper::format($job['completed_at'], 'M j, g:i A') ?></td>
                                <td class="d-table-cell-md text-nowrap"><?= $durStr ?></td>
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
            <div class="card-body p-0" id="server-log">
                <?php if (empty($recentLogs)): ?>
                    <div class="p-4 text-muted text-center">No log entries</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>Time</th>
                                <th class="d-th-md">Client</th>
                                <th>Level</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentLogs as $log): ?>
                            <tr>
                                <td class="small"><?= \BBS\Core\TimeHelper::format($log['created_at'], 'M j, g:i A') ?></td>
                                <td class="d-table-cell-md"><?= htmlspecialchars($log['agent_name'] ?? '--') ?></td>
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

// Helper: escape HTML
function esc(str) { const d = document.createElement('div'); d.textContent = str ?? ''; return d.innerHTML; }

// Helper: format elapsed seconds
function fmtDur(s) {
    s = parseInt(s) || 0;
    if (s >= 3600) return Math.floor(s/3600) + 'h ' + Math.floor((s%3600)/60) + 'm';
    if (s >= 60) return Math.floor(s/60) + 'm ' + (s%60) + 's';
    return s > 0 ? s + 's' : '--';
}

// Helper: format time diff for countdowns
function fmtCountdown(diffSec) {
    if (diffSec < 0) return { text: 'Overdue', cls: 'text-danger fw-semibold' };
    if (diffSec < 3600) return { text: Math.floor(diffSec/60) + 'm', cls: 'text-warning fw-semibold' };
    if (diffSec < 86400) return { text: Math.floor(diffSec/3600) + 'h ' + Math.floor((diffSec%3600)/60) + 'm', cls: '' };
    return { text: Math.floor(diffSec/86400) + 'd ' + Math.floor((diffSec%86400)/3600) + 'h', cls: 'text-muted' };
}

// Helper: format date like "Jan 30, 4:15 PM"
function fmtDate(str) {
    if (!str) return '--';
    const d = new Date(str.replace(' ', 'T') + 'Z');
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ', ' +
           d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
}

function renderActiveJobs(jobs) {
    const el = document.getElementById('active-jobs');
    if (!jobs || !jobs.length) { el.innerHTML = '<div class="p-4 text-muted text-center">No active jobs</div>'; return; }
    const now = Math.floor(Date.now() / 1000);
    let html = '<div class="table-responsive"><table class="table table-hover mb-0"><thead class="table-light"><tr><th>Client</th><th>Task</th><th class="d-th-md">Plan</th><th class="d-th-md">Repo</th><th>Progress</th><th class="d-th-md">Duration</th><th>Status</th></tr></thead><tbody class="small">';
    jobs.forEach(j => {
        let elapsed = '--';
        if (j.started_at) { const e = now - Math.floor(new Date((j.started_at).replace(' ','T')+'Z').getTime()/1000); elapsed = fmtDur(e); }
        let progress = '';
        if (j.status === 'queued') { progress = '<span class="text-muted">Waiting</span>'; }
        else if ((j.files_total || 0) > 0) {
            const pct = Math.round((j.files_processed / j.files_total) * 100);
            progress = '<div class="progress" style="height:20px"><div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width:'+pct+'%">'+pct+'%</div></div>';
        } else { progress = '<div class="progress" style="height:20px"><div class="progress-bar progress-bar-striped progress-bar-animated bg-info" style="width:100%">Preparing...</div></div>'; }
        const badge = { running: 'info', sent: 'primary', queued: 'warning' }[j.status] || 'secondary';
        html += '<tr style="cursor:pointer" onclick="window.location=\'/queue/'+j.id+'\'"><td>'+esc(j.agent_name)+'</td><td>'+esc(j.task_type?.[0]?.toUpperCase()+j.task_type?.slice(1))+'</td><td class="d-table-cell-md">'+esc(j.plan_name||'--')+'</td><td class="d-table-cell-md">'+esc(j.repo_name||'--')+'</td><td style="min-width:100px">'+progress+'</td><td class="d-table-cell-md text-nowrap">'+elapsed+'</td><td><span class="badge bg-'+badge+'">'+esc(j.status)+'</span></td></tr>';
    });
    html += '</tbody></table></div>';
    el.innerHTML = html;
}

function renderUpcoming(schedules, csrfToken) {
    const el = document.getElementById('upcoming-backups');
    if (!schedules || !schedules.length) { el.innerHTML = '<div class="p-4 text-muted text-center">No scheduled backups</div>'; return; }
    const now = Math.floor(Date.now() / 1000);
    let html = '<div class="table-responsive"><table class="table table-hover mb-0"><thead class="table-light"><tr><th>Client</th><th>Plan</th><th class="d-th-md">Frequency</th><th>Next Run</th><th class="d-th-md">Countdown</th><th></th></tr></thead><tbody class="small">';
    schedules.forEach(s => {
        const nextTs = Math.floor(new Date((s.next_run).replace(' ','T')+'Z').getTime()/1000);
        const cd = fmtCountdown(nextTs - now);
        html += '<tr style="cursor:pointer" onclick="window.location=\'/clients/'+s.agent_id+'?tab=schedules\'"><td>'+esc(s.agent_name)+'</td><td>'+esc(s.plan_name)+'</td><td class="d-table-cell-md">'+esc(s.frequency?.[0]?.toUpperCase()+s.frequency?.slice(1))+'</td><td class="small">'+fmtDate(s.next_run)+'</td><td class="d-table-cell-md '+cd.cls+'">'+cd.text+'</td>';
        html += '<td class="text-nowrap" onclick="event.stopPropagation()"><form method="POST" action="/plans/'+s.plan_id+'/trigger" class="d-inline" onsubmit="return confirm(\'Run this backup now?\')"><input type="hidden" name="csrf_token" value="'+csrfToken+'"><button type="submit" class="btn btn-sm btn-outline-success py-0 px-2" title="Run now"><i class="bi bi-play-fill"></i></button></form></td>';
        html += '</tr>';
    });
    html += '</tbody></table></div>';
    el.innerHTML = html;
}

function renderRecentJobs(jobs) {
    const el = document.getElementById('recent-jobs');
    if (!jobs || !jobs.length) { el.innerHTML = '<div class="p-4 text-muted text-center">No completed jobs yet</div>'; return; }
    let html = '<div class="table-responsive"><table class="table table-hover mb-0"><thead class="table-light"><tr><th>Client</th><th>Task</th><th class="d-th-md">Plan</th><th class="d-th-md">Repo</th><th>Completed</th><th class="d-th-md">Duration</th><th>Status</th></tr></thead><tbody class="small">';
    jobs.forEach(j => {
        const badge = { completed: 'success', failed: 'danger', cancelled: 'secondary' }[j.status] || 'warning';
        html += '<tr style="cursor:pointer" onclick="window.location=\'/queue/'+j.id+'\'"><td>'+esc(j.agent_name)+'</td><td>'+esc(j.task_type?.[0]?.toUpperCase()+j.task_type?.slice(1))+'</td><td class="d-table-cell-md">'+esc(j.plan_name||'--')+'</td><td class="d-table-cell-md">'+esc(j.repo_name||'--')+'</td><td class="small">'+fmtDate(j.completed_at)+'</td><td class="d-table-cell-md text-nowrap">'+fmtDur(j.duration_seconds)+'</td><td><span class="badge bg-'+badge+'">'+esc(j.status)+'</span></td></tr>';
    });
    html += '</tbody></table></div>';
    el.innerHTML = html;
}

function renderLogs(logs) {
    const el = document.getElementById('server-log');
    if (!logs || !logs.length) { el.innerHTML = '<div class="p-4 text-muted text-center">No log entries</div>'; return; }
    let html = '<div class="table-responsive"><table class="table table-hover mb-0 small"><thead class="table-light"><tr><th>Time</th><th class="d-th-md">Client</th><th>Level</th><th>Message</th></tr></thead><tbody>';
    logs.forEach(l => {
        const badge = { error: 'danger', warning: 'warning' }[l.level] || 'info';
        html += '<tr><td class="small">'+fmtDate(l.created_at)+'</td><td class="d-table-cell-md">'+esc(l.agent_name||'--')+'</td><td><span class="badge bg-'+badge+'">'+esc(l.level)+'</span></td><td>'+esc(l.message)+'</td></tr>';
    });
    html += '</tbody></table></div>';
    el.innerHTML = html;
}

const csrfToken = '<?= $this->csrfToken() ?>';

// Auto-refresh every 8 seconds
setInterval(function() {
    fetch('/dashboard/json', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            // Stat cards
            document.getElementById('stat-agents').textContent = data.agentCount;
            document.getElementById('stat-online').textContent = data.onlineCount;
            document.getElementById('stat-running').textContent = data.runningJobs;
            document.getElementById('stat-queued').textContent = data.queuedJobs;
            document.getElementById('stat-errors').textContent = data.errorCount;

            <?php if ($isAdmin): ?>
            if (data.cpuLoad) {
                const p = data.cpuLoad.percent, arc = 212.06, circ = 282.74;
                document.getElementById('cpu-pct').textContent = p + '%';
                document.getElementById('cpu-detail').textContent = data.cpuLoad['1min'] + ' / ' + data.cpuLoad.cores + ' cores';
                const cpuArc = document.getElementById('cpu-arc');
                cpuArc.setAttribute('stroke-dasharray', (arc * p / 100) + ' ' + circ);
                cpuArc.setAttribute('stroke', p > 80 ? '#dc3545' : (p > 50 ? '#ffc107' : '#198754'));
            }
            if (data.memory) {
                const p = data.memory.percent, arc = 212.06, circ = 282.74;
                document.getElementById('mem-pct').textContent = p + '%';
                const memArc = document.getElementById('mem-arc');
                memArc.setAttribute('stroke-dasharray', (arc * p / 100) + ' ' + circ);
                memArc.setAttribute('stroke', p > 85 ? '#dc3545' : (p > 60 ? '#ffc107' : '#0dcaf0'));
            }
            if (data.mysqlStats) {
                const ms = data.mysqlStats;
                const fmt = n => n.toLocaleString();
                const map = {
                    'stat-total-rows': ms.total_rows, 'stat-archives': ms.archives,
                    'stat-catalog': ms.catalog_files, 'stat-paths': ms.unique_paths,
                    'stat-completed-jobs': ms.completed_jobs, 'stat-repos': ms.repositories
                };
                for (const [id, val] of Object.entries(map)) {
                    const el = document.getElementById(id);
                    if (el) el.textContent = fmt(val);
                }
            }
            if (data.mysqlStorage && data.mysqlStorage.disk_total > 0) {
                const ms = data.mysqlStorage, t = ms.disk_total;
                const dbPct = (ms.db_bytes / t * 100).toFixed(1);
                const usedPct = (ms.disk_used / t * 100).toFixed(1);
                const freePct = (ms.disk_free / t * 100).toFixed(1);
                const bar = document.getElementById('mysql-bar');
                if (bar) {
                    const c = bar.children;
                    c[0].style.width = dbPct + '%';
                    c[1].style.width = Math.max(usedPct - dbPct, 0) + '%';
                    c[2].style.width = freePct + '%';
                }
                const ft = document.getElementById('mysql-free-text');
                if (ft) ft.textContent = freePct + '% free';
            }
            <?php endif; ?>

            // Tables
            renderActiveJobs(data.activeJobs);
            renderUpcoming(data.upcomingSchedules, csrfToken);
            renderRecentJobs(data.recentJobs);
            renderLogs(data.recentLogs);
        })
        .catch(() => {});
}, 8000);
</script>
