<?php
use BBS\Services\ServerStats;
use BBS\Core\TimeHelper;

$isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';

// ---- Helpers ----
$compact = function (int $n): string {
    if ($n >= 1_000_000) return round($n / 1_000_000, 1) . 'M';
    if ($n >= 10_000)    return round($n / 1_000, 1) . 'K';
    return number_format($n);
};
$fmtUptime = function (?int $s): string {
    if ($s === null) return '—';
    $d = intdiv($s, 86400); $s %= 86400;
    $h = intdiv($s, 3600);  $s %= 3600;
    $m = intdiv($s, 60);
    if ($d > 0) return "{$d}d {$h}h";
    if ($h > 0) return "{$h}h {$m}m";
    return "{$m}m";
};

// Dedup savings % (original data vs actual disk footprint)
$dedupSavingsPct = $totalOriginalBytes > 0
    ? round((1 - $totalDiskBytes / $totalOriginalBytes) * 100, 1)
    : 0;

// Append "B" to df-style sizes ("100G" → "100GB")
$dfToGB = function (string $s): string {
    if (preg_match('/^[\d.]+[TGMK]$/', $s)) return $s . 'B';
    return $s;
};
?>

<style>
.v2 .metric-tile {
    border: 1px solid var(--bs-border-color);
    border-radius: 10px;
    padding: 14px 16px;
    transition: border-color 0.12s, transform 0.12s;
}
.v2 a.metric-tile:hover { transform: translateY(-1px); filter: brightness(1.1); }
.v2 .metric-tile .value { font-size: 1.75rem; font-weight: 700; line-height: 1.1; }
.v2 .metric-tile .label { font-size: 0.75rem; color: var(--bs-secondary-color); text-transform: uppercase; letter-spacing: 0.04em; }
.v2 .metric-tile .sub { font-size: 0.8rem; color: var(--bs-secondary-color); margin-top: 2px; }
/* Colored tile backgrounds matching the original dashboard */
.v2 .metric-tile.primary { background-color: rgba(13,110,253,0.05); border-color: rgba(13,110,253,0.2); }
.v2 .metric-tile.primary .value { color: var(--bs-primary); }
.v2 .metric-tile.success { background-color: rgba(25,135,84,0.05); border-color: rgba(25,135,84,0.2); }
.v2 .metric-tile.success .value { color: var(--bs-success); }
.v2 .metric-tile.warning { background-color: rgba(255,193,7,0.05); border-color: rgba(255,193,7,0.2); }
.v2 .metric-tile.warning .value { color: var(--bs-warning); }
.v2 .metric-tile.danger { background-color: rgba(220,53,69,0.05); border-color: rgba(220,53,69,0.2); }
.v2 .metric-tile.danger .value { color: var(--bs-danger); }
[data-bs-theme="dark"] .v2 .metric-tile.primary { background-color: rgba(13,110,253,0.08); }
[data-bs-theme="dark"] .v2 .metric-tile.success { background-color: rgba(25,135,84,0.08); }
[data-bs-theme="dark"] .v2 .metric-tile.warning { background-color: rgba(255,193,7,0.08); }
[data-bs-theme="dark"] .v2 .metric-tile.danger { background-color: rgba(220,53,69,0.08); }

.v2 .card-head-gradient {
    background: linear-gradient(135deg, #1e293b 0%, #243a6b 50%, #2b4d8c 100%);
    color: #fff;
    border-bottom: 1px solid rgba(0, 0, 0, 0.25);
}
.v2 .card-head-gradient .text-muted { color: rgba(255, 255, 255, 0.7) !important; }
.v2 .card-head-gradient i { color: #9ec5fe; }

.v2 .health-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
}
.v2 .health-row:last-child { margin-bottom: 0; }
.v2 .health-row .lbl { width: 64px; font-size: 0.78rem; color: var(--bs-secondary-color); font-weight: 600; }
.v2 .health-row .bar {
    flex: 1;
    height: 12px;
    background: var(--bs-tertiary-bg);
    border-radius: 6px;
    overflow: hidden;
    position: relative;
}
.v2 .health-row .fill {
    height: 100%;
    border-radius: 6px;
    transition: width 0.4s;
}
.v2 .health-row .val { font-size: 0.78rem; font-variant-numeric: tabular-nums; min-width: 80px; text-align: right; color: var(--bs-body-color); }

.v2 .storage-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 12px;
    max-width: 100%;
}
/* On large screens, fit exactly N columns (set via inline CSS var).
   Falls back to auto-fill below 1200px or when cards are > 5. */
@media (min-width: 1200px) {
    .v2 .storage-grid.exact-cols {
        grid-template-columns: repeat(var(--storage-cols), 1fr);
    }
    .v2 .storage-grid.single-col .storage-card { max-width: 400px; }
}
.v2 .storage-card {
    background: var(--bs-body-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 10px;
    padding: 12px 14px;
    transition: border-color 0.12s;
}
.v2 .storage-card:hover { border-color: var(--bs-primary); }
.v2 .storage-card .sc-head { display: flex; justify-content: space-between; align-items: start; gap: 8px; margin-bottom: 8px; }
.v2 .storage-card .sc-label { font-weight: 600; font-size: 0.9rem; word-break: break-word; }
.v2 .storage-card .sc-kind { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--bs-secondary-color); }
.v2 .storage-card .sc-kind.remote { color: #9ec5fe; }
.v2 .storage-card .sc-path { font-size: 0.72rem; color: var(--bs-secondary-color); font-family: ui-monospace, Menlo, Consolas, monospace; margin-bottom: 8px; word-break: break-all; }
.v2 .storage-card .sc-bar { height: 8px; background: var(--bs-tertiary-bg); border-radius: 4px; overflow: hidden; margin-bottom: 6px; }
.v2 .storage-card .sc-fill { height: 100%; border-radius: 4px; }
.v2 .storage-card .sc-numbers { display: flex; justify-content: space-between; font-size: 0.78rem; font-variant-numeric: tabular-nums; }
.v2 .storage-card .sc-footer { display: flex; justify-content: space-between; font-size: 0.72rem; color: var(--bs-secondary-color); margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--bs-border-color); }

.v2 .mini-stat { display: flex; justify-content: space-between; padding: 4px 0; font-size: 0.82rem; }
.v2 .mini-stat .k { color: var(--bs-secondary-color); }
.v2 .mini-stat .v { font-weight: 600; font-variant-numeric: tabular-nums; }

.v2 .table thead th {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--bs-secondary-color);
    font-weight: 600;
}
</style>

<div class="v2 container-fluid px-0">
    <!-- Header: title + server identity -->
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0"><i class="bi bi-speedometer2 me-2"></i>Dashboard <span class="badge bg-primary bg-opacity-25 text-primary ms-1" style="font-size:0.6rem;">V2 PREVIEW</span></h4>
            <div class="text-muted small mt-1">
                <span title="Server version"><i class="bi bi-box-seam me-1"></i>Borg Backup Server <?= htmlspecialchars($bbsVersion) ?></span>
                <span class="mx-2">·</span>
                <span title="Hostname"><i class="bi bi-hdd-network me-1"></i><?= htmlspecialchars($serverHost) ?></span>
                <span class="mx-2">·</span>
                <span title="OS"><i class="bi bi-terminal me-1"></i><?= htmlspecialchars($osName) ?></span>
                <span class="mx-2">·</span>
                <span title="Uptime"><i class="bi bi-clock-history me-1"></i><?= $fmtUptime($uptimeSec) ?></span>
            </div>
        </div>
    </div>

    <!-- Row 1: Hero tiles -->
    <div class="row g-3 mb-3">
        <div class="col-xl-3 col-md-6">
            <a href="/clients" class="text-decoration-none metric-tile primary d-block">
                <div class="label"><i class="bi bi-display me-1"></i>Clients</div>
                <div class="value"><?= $agentCount ?></div>
                <div class="sub"><span class="text-success fw-semibold"><?= $onlineCount ?></span> online · <?= max(0, $agentCount - $onlineCount) ?> offline</div>
            </a>
        </div>
        <div class="col-xl-3 col-md-6">
            <a href="/queue" class="text-decoration-none metric-tile success d-block">
                <div class="label"><i class="bi bi-arrow-repeat me-1"></i>Running</div>
                <div class="value"><?= $runningJobs ?></div>
                <div class="sub">active · <?= $queuedJobs ?> queued</div>
            </a>
        </div>
        <div class="col-xl-3 col-md-6">
            <a href="#recovery-points" class="text-decoration-none metric-tile warning d-block">
                <div class="label"><i class="bi bi-archive me-1"></i>Recovery Points</div>
                <div class="value"><?= $compact($totalArchiveCount) ?></div>
                <div class="sub"><?= ServerStats::formatBytes($totalOriginalBytes) ?> protected · <?= ServerStats::formatBytes($totalDiskBytes) ?> on disk</div>
            </a>
        </div>
        <div class="col-xl-3 col-md-6">
            <a href="/log?level=error" class="text-decoration-none metric-tile <?= $errorCount > 0 ? 'danger' : 'success' ?> d-block">
                <div class="label"><i class="bi bi-exclamation-circle me-1"></i>Errors (24h)</div>
                <div class="value"><?= $errorCount ?></div>
                <div class="sub">check logs</div>
            </a>
        </div>
    </div>

    <!-- Row 2: Activity chart | Backup summary | Server health (admin only) -->
    <?php
        $row2JobsCol = $isAdmin ? 'col-xl-5 col-lg-6' : 'col-lg-7';
        $row2SummaryCol = $isAdmin ? 'col-xl-4 col-lg-6' : 'col-lg-5';
    ?>
    <div class="row g-3 mb-3">
        <div class="<?= $row2JobsCol ?>">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header card-head-gradient fw-semibold">
                    <i class="bi bi-bar-chart me-2"></i>Jobs (Last 24h)
                </div>
                <div class="card-body py-2">
                    <div style="position: relative; height: 160px;">
                        <canvas id="jobsChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="<?= $row2SummaryCol ?>" id="recovery-points">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header card-head-gradient fw-semibold">
                    <i class="bi bi-shield-check me-2"></i>Backup Summary
                </div>
                <div class="card-body">
                    <div class="mini-stat"><span class="k"><i class="bi bi-archive me-1"></i>Recovery points</span><span class="v"><?= number_format($totalArchiveCount) ?></span></div>
                    <div class="mini-stat"><span class="k"><i class="bi bi-files me-1"></i>Original data</span><span class="v"><?= ServerStats::formatBytes($totalOriginalBytes) ?></span></div>
                    <div class="mini-stat"><span class="k"><i class="bi bi-hdd me-1"></i>On disk (deduped)</span><span class="v"><?= ServerStats::formatBytes($totalDiskBytes) ?></span></div>
                    <div class="mini-stat"><span class="k"><i class="bi bi-magic me-1"></i>Dedup savings</span><span class="v text-success"><?= $dedupSavingsPct ?>%</span></div>
                    <?php if ($lastBackup): ?>
                    <div class="mini-stat"><span class="k"><i class="bi bi-clock-history me-1"></i>Last backup</span><span class="v"><?= TimeHelper::ago($lastBackup['completed_at']) ?></span></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($isAdmin): ?>
        <div class="col-xl-3 col-lg-12">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header card-head-gradient fw-semibold">
                    <i class="bi bi-cpu me-2"></i>Server Health
                </div>
                <div class="card-body">
                    <?php
                        $cpuPct = $cpuLoad['percent'] ?? 0;
                        $memPct = $memory['percent'] ?? 0;
                        $cpuColor = $cpuPct > 80 ? '#dc3545' : ($cpuPct > 50 ? '#ffc107' : '#198754');
                        $memColor = $memPct > 85 ? '#dc3545' : ($memPct > 60 ? '#ffc107' : '#0dcaf0');
                    ?>
                    <div class="health-row">
                        <span class="lbl">CPU</span>
                        <div class="bar"><div class="fill" id="cpu-fill" style="width: <?= $cpuPct ?>%; background: <?= $cpuColor ?>;"></div></div>
                        <span class="val" id="cpu-val"><?= $cpuPct ?>% · <?= $cpuLoad['1min'] ?></span>
                    </div>
                    <div class="health-row">
                        <span class="lbl">Memory</span>
                        <div class="bar"><div class="fill" id="mem-fill" style="width: <?= $memPct ?>%; background: <?= $memColor ?>;"></div></div>
                        <span class="val" id="mem-val"><?= $memPct ?>% · <?= ServerStats::formatBytes($memory['used']) ?></span>
                    </div>
                    <?php if (!empty($partitions)): ?>
                    <?php foreach ($partitions as $part): ?>
                        <?php
                            $pPct = $part['percent'] ?? 0;
                            $pColor = $pPct > 90 ? '#dc3545' : ($pPct > 70 ? '#ffc107' : '#6c757d');
                        ?>
                    <div class="health-row">
                        <span class="lbl text-truncate" title="<?= htmlspecialchars($part['mount']) ?>"><?= htmlspecialchars($part['mount']) ?></span>
                        <div class="bar"><div class="fill" style="width: <?= $pPct ?>%; background: <?= $pColor ?>;"></div></div>
                        <span class="val"><?= $pPct ?>% · <?= $dfToGB($part['size']) ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Row 3: Storage Locations — admin only, infra detail -->
    <?php if ($isAdmin && !empty($storageLocations)): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header card-head-gradient fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-hdd-stack me-2"></i>Storage Locations</span>
            <span class="text-muted small"><?= count($storageLocations) ?> configured</span>
        </div>
        <div class="card-body">
            <?php
                $locCount = count($storageLocations);
                $gridClasses = 'storage-grid';
                if ($locCount <= 6) $gridClasses .= ' exact-cols';
                if ($locCount === 1) $gridClasses .= ' single-col';
            ?>
            <div class="<?= $gridClasses ?>" style="--storage-cols: <?= min($locCount, 6) ?>">
                <?php foreach ($storageLocations as $loc): ?>
                <?php
                    $pct = $loc['disk_percent'] ?? 0;
                    $fillColor = $pct >= 90 ? '#dc3545' : ($pct >= 75 ? '#ffc107' : '#0dcaf0');
                ?>
                <div class="storage-card">
                    <div class="sc-head">
                        <div>
                            <div class="sc-label"><?= htmlspecialchars($loc['label']) ?></div>
                            <div class="sc-kind <?= $loc['kind'] === 'remote' ? 'remote' : '' ?>">
                                <?= $loc['kind'] === 'remote' ? 'Remote SSH' : ($loc['is_default'] ? 'Default · Local' : 'Local') ?>
                            </div>
                        </div>
                        <?php if ($loc['disk_percent'] !== null): ?>
                        <span class="fw-bold" style="color: <?= $fillColor ?>;"><?= $pct ?>%</span>
                        <?php else: ?>
                        <span class="text-muted small">n/a</span>
                        <?php endif; ?>
                    </div>
                    <div class="sc-path"><?= htmlspecialchars($loc['path']) ?></div>
                    <?php if ($loc['disk_total']): ?>
                    <div class="sc-bar"><div class="sc-fill" style="width: <?= $pct ?>%; background: <?= $fillColor ?>;"></div></div>
                    <div class="sc-numbers">
                        <span><?= ServerStats::formatBytes((int) $loc['disk_used']) ?> used</span>
                        <span><?= ServerStats::formatBytes((int) $loc['disk_free']) ?> free</span>
                    </div>
                    <?php else: ?>
                    <div class="text-muted small fst-italic">Quota unavailable</div>
                    <?php endif; ?>
                    <div class="sc-footer">
                        <span><i class="bi bi-hdd me-1"></i><?= $loc['repo_count'] ?> repo<?= $loc['repo_count'] === 1 ? '' : 's' ?></span>
                        <span><?= ServerStats::formatBytes((int) $loc['repo_bytes']) ?> disk usage</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Row 4: Activity tables -->
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header card-head-gradient fw-semibold">
                    <i class="bi bi-lightning-charge me-2"></i>Active &amp; Queued
                </div>
                <div class="card-body p-0" id="active-jobs">
                    <?php if (empty($activeJobs)): ?>
                    <div class="p-4 text-muted text-center">No active jobs</div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 small">
                            <thead><tr><th>Client</th><th>Task</th><th class="d-th-md">Repo</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($activeJobs as $j): ?>
                                <?php
                                    $pct = ($j['files_total'] ?? 0) > 0 ? round(($j['files_processed'] / $j['files_total']) * 100) : null;
                                    $badgeClass = $j['status'] === 'queued' ? 'bg-warning text-dark' : 'bg-primary';
                                ?>
                                <tr style="cursor:pointer" onclick="window.location='/queue/<?= (int) $j['id'] ?>'">
                                    <td><?= htmlspecialchars($j['agent_name']) ?></td>
                                    <td><?= htmlspecialchars(ucfirst($j['task_type'])) ?></td>
                                    <td class="d-table-cell-md"><?= htmlspecialchars($j['repo_name'] ?? '--') ?></td>
                                    <td>
                                        <?php if ($pct !== null && $j['status'] === 'running'): ?>
                                        <div class="progress" style="height:18px;min-width:60px;"><div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width:<?= $pct ?>%"><?= $pct ?>%</div></div>
                                        <?php else: ?>
                                        <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars(ucfirst($j['status'])) ?></span>
                                        <?php endif; ?>
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
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header card-head-gradient fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-calendar-event me-2"></i>Upcoming Backups</span>
                    <a href="/schedules" class="small text-decoration-none" style="color: #9ec5fe;">View Schedule <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="card-body p-0" id="upcoming-backups">
                    <?php if (empty($upcomingSchedules)): ?>
                    <div class="p-4 text-muted text-center">No scheduled backups</div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 small">
                            <thead><tr><th>Client</th><th>Plan</th><th>Next Run</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach (array_slice($upcomingSchedules, 0, 6) as $s): ?>
                                <?php
                                    $nextTs = strtotime($s['next_run'] ?? '');
                                    $isOverdue = $nextTs && $nextTs < time();
                                ?>
                                <tr style="cursor:pointer" onclick="window.location='/clients/<?= (int) $s['agent_id'] ?>?tab=schedules'">
                                    <td><?= htmlspecialchars($s['agent_name']) ?></td>
                                    <td><?= htmlspecialchars($s['plan_name']) ?></td>
                                    <td class="<?= $isOverdue ? 'text-danger fw-semibold' : '' ?>">
                                        <?php if ($isOverdue): ?><i class="bi bi-exclamation-triangle me-1"></i><?php endif; ?>
                                        <?= TimeHelper::format($s['next_run'], 'M j, g:i A') ?>
                                    </td>
                                    <td class="text-nowrap" onclick="event.stopPropagation()">
                                        <form method="POST" action="/plans/<?= (int) $s['plan_id'] ?>/trigger" class="d-inline" data-confirm="Run <?= htmlspecialchars($s['agent_name']) ?> / <?= htmlspecialchars($s['plan_name']) ?> now?">
                                            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success py-0 px-2" title="Run now"><i class="bi bi-play-fill"></i></button>
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
    </div>

    <!-- Row 5: Recent completed jobs -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header card-head-gradient fw-semibold">
            <i class="bi bi-check2-circle me-2"></i>Recently Completed
        </div>
        <div class="card-body p-0" id="recent-jobs">
            <?php if (empty($recentJobs)): ?>
            <div class="p-4 text-muted text-center">No completed jobs yet</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 small">
                    <thead><tr><th>Client</th><th>Task</th><th class="d-th-md">Plan</th><th class="d-th-md">Repo</th><th>Completed</th><th class="d-th-md">Duration</th><th class="text-center">Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentJobs as $j): ?>
                        <?php
                            $statusIcon = $j['status'] === 'completed' ? 'bi-check-circle-fill text-success'
                                : ($j['status'] === 'failed' ? 'bi-x-circle-fill text-danger' : 'bi-slash-circle-fill text-secondary');
                        ?>
                        <tr style="cursor:pointer" onclick="window.location='/queue/<?= (int) $j['id'] ?>'">
                            <td><?= htmlspecialchars($j['agent_name']) ?></td>
                            <td><?= htmlspecialchars(ucfirst($j['task_type'])) ?></td>
                            <td class="d-table-cell-md"><?= htmlspecialchars($j['plan_name'] ?? '--') ?></td>
                            <td class="d-table-cell-md"><?= htmlspecialchars($j['repo_name'] ?? '--') ?></td>
                            <td><?= TimeHelper::ago($j['completed_at']) ?></td>
                            <td class="d-table-cell-md"><?= $j['duration_seconds'] ? gmdate('i\m s\s', (int) $j['duration_seconds']) : '--' ?></td>
                            <td class="text-center"><i class="bi <?= $statusIcon ?>"></i></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <!-- Row 6: System detail — MariaDB + File Catalog side by side.
         Each card is a two-column layout (stats left, chart right) that
         wraps to stacked rows on mobile. -->
    <div class="row g-3 mb-3">
        <?php if (!empty($mysqlStats)): ?>
        <?php
            $msStorage = $mysqlStorage ?? null;
            $dbBytes = $msStorage['db_bytes'] ?? 0;
            $msDiskTotal = $msStorage['disk_total'] ?? 0;
            $msDiskUsed = $msStorage['disk_used'] ?? 0;
            $msDiskFree = $msStorage['disk_free'] ?? 0;
            $msDbPct = $msDiskTotal > 0 ? round($dbBytes / $msDiskTotal * 100, 1) : 0;
            $msOtherPct = $msDiskTotal > 0 ? round(($msDiskUsed - $dbBytes) / $msDiskTotal * 100, 1) : 0;
            if ($msOtherPct < 0) $msOtherPct = 0;
            $msFreePct = $msDiskTotal > 0 ? round($msDiskFree / $msDiskTotal * 100, 1) : 0;
        ?>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header card-head-gradient fw-semibold">
                    <i class="bi bi-database me-2"></i>MariaDB
                </div>
                <?php
                    $msUptime = (int) ($mysqlStats['uptime'] ?? 0);
                    $msUptimeStr = $msUptime >= 86400
                        ? intdiv($msUptime, 86400) . 'd ' . intdiv($msUptime % 86400, 3600) . 'h'
                        : intdiv($msUptime, 3600) . 'h ' . intdiv($msUptime % 3600, 60) . 'm';
                ?>
                <div class="card-body py-3">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <div class="row g-0 text-center">
                                <div class="col-4 py-2">
                                    <div class="fw-bold" style="font-size:1.1rem;"><?= $mysqlStats['qps'] ?? 0 ?></div>
                                    <div class="text-muted" style="font-size:0.7rem;">QPS</div>
                                </div>
                                <div class="col-4 py-2">
                                    <div class="fw-bold" style="font-size:1.1rem;"><?= $mysqlStats['threads_connected'] ?? 0 ?></div>
                                    <div class="text-muted" style="font-size:0.7rem;">Connections</div>
                                </div>
                                <div class="col-4 py-2">
                                    <div class="fw-bold" style="font-size:1.1rem;"><?= $mysqlStats['hit_rate'] ?? 0 ?>%</div>
                                    <div class="text-muted" style="font-size:0.7rem;">Hit Rate</div>
                                </div>
                                <div class="col-4 py-2">
                                    <div class="fw-bold" style="font-size:1.1rem;"><?= $msUptimeStr ?></div>
                                    <div class="text-muted" style="font-size:0.7rem;">Uptime</div>
                                </div>
                                <div class="col-4 py-2">
                                    <div class="fw-bold" style="font-size:1.1rem;"><?= $mysqlStats['buffer_pool_used_pct'] ?? 0 ?>%</div>
                                    <div class="text-muted" style="font-size:0.7rem;">Buffer Pool</div>
                                </div>
                                <div class="col-4 py-2">
                                    <div class="fw-bold" style="font-size:1.1rem;"><?= $compact((int) ($mysqlStats['slow_queries'] ?? 0)) ?></div>
                                    <div class="text-muted" style="font-size:0.7rem;">Slow Queries</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 d-flex flex-column align-items-center justify-content-center">
                            <?php if ($msDiskTotal > 0): ?>
                            <canvas id="mariadbChart" width="120" height="120"></canvas>
                            <div class="text-center small mt-1">
                                <span style="color:#48bb78;">&#9632;</span> BBS <span class="text-muted">(<?= ServerStats::formatBytes($dbBytes) ?>)</span>
                                <span class="ms-2" style="color:#6c757d;">&#9632;</span> Other
                                <span class="ms-2" style="color:#2d3748;">&#9632;</span> Free
                            </div>
                            <?php else: ?>
                            <div class="text-muted small fst-italic">Disk info unavailable</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($clickhouseStats ?? null)): ?>
        <?php
            $chTopRepos = $clickhouseStats['top_repos'] ?? [];
            $chDiskBytes = (int) ($clickhouseStats['disk_bytes'] ?? 0);
        ?>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header card-head-gradient fw-semibold">
                    <i class="bi bi-list-columns-reverse me-2"></i>File Catalog (ClickHouse)
                </div>
                <div class="card-body py-2">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <div class="mini-stat"><span class="k">Catalog rows</span><span class="v"><?= $compact((int) ($clickhouseStats['total_rows'] ?? 0)) ?></span></div>
                            <div class="mini-stat"><span class="k">Index size</span><span class="v"><?= ServerStats::formatBytes($chDiskBytes) ?></span></div>
                            <div class="mini-stat"><span class="k">Compression</span><span class="v"><?= $clickhouseStats['compression_ratio'] ?? 0 ?>×</span></div>
                            <div class="mini-stat"><span class="k">Indexed clients</span><span class="v"><?= (int) ($clickhouseStats['agent_count'] ?? 0) ?></span></div>
                        </div>
                        <div class="col-sm-6">
                            <?php if (!empty($chTopRepos)): ?>
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div class="d-flex align-items-center gap-2">
                                    <canvas id="catalogPieChart" width="80" height="80" style="flex-shrink:0;"></canvas>
                                    <div class="small fw-semibold text-uppercase" style="font-size:0.7rem;letter-spacing:0.03em;color:var(--bs-secondary-color);"><i class="bi bi-trophy me-1"></i>Top Repositories</div>
                                </div>
                            </div>
                            <?php
                                $pieColors = ['#36a2eb','#ff6384','#ffce56','#4bc0c0','#9966ff','#6c757d'];
                            ?>
                            <?php foreach ($chTopRepos as $i => $repo): ?>
                            <div class="d-flex align-items-center justify-content-between" style="font-size:0.8rem;padding:2px 0;">
                                <span><span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:<?= $pieColors[$i % 6] ?>;margin-right:6px;"></span><?= htmlspecialchars($repo['name']) ?></span>
                                <span class="text-muted" style="font-variant-numeric:tabular-nums;"><?= $compact((int) $repo['rows']) ?> rows</span>
                            </div>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <div class="text-muted small fst-italic">No catalog data yet</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const chartData = <?= json_encode($chartData) ?>;
    const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    const tc = isDark ? '#8b929a' : '#6c757d';
    const gc = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.04)';
    new Chart(document.getElementById('jobsChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: chartData.map(d => d.label),
            datasets: [
                { label: 'Backups', data: chartData.map(d => d.backups), backgroundColor: 'rgba(54, 162, 235, 0.7)', borderRadius: 2 },
                { label: 'Restores', data: chartData.map(d => d.restores), backgroundColor: 'rgba(255, 159, 64, 0.7)', borderRadius: 2 },
                { label: 'S3 Sync', data: chartData.map(d => d.s3_sync), backgroundColor: 'rgba(75, 192, 192, 0.7)', borderRadius: 2 },
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 9 }, padding: 8, color: tc } } },
            scales: {
                y: { beginAtZero: true, stacked: true, ticks: { stepSize: 1, color: tc, font: { size: 10 } }, grid: { color: gc } },
                x: { stacked: true, ticks: { color: tc, font: { size: 9 }, maxRotation: 45, callback: function (v, i) { return i % 3 === 0 ? this.getLabelForValue(v) : ''; } }, grid: { display: false } },
            }
        }
    });

    // --- MariaDB donut: BBS database vs other vs free ---
    <?php if ($isAdmin && !empty($msStorage) && ($msStorage['disk_total'] ?? 0) > 0): ?>
    (function () {
        const el = document.getElementById('mariadbChart');
        if (!el) return;
        const fmtB = b => { b = Number(b); if (b >= 1099511627776) return (b/1099511627776).toFixed(1)+' TB'; if (b >= 1073741824) return (b/1073741824).toFixed(1)+' GB'; if (b >= 1048576) return (b/1048576).toFixed(1)+' MB'; return (b/1024).toFixed(0)+' KB'; };
        new Chart(el.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['BBS Database', 'Other Data', 'Free'],
                datasets: [{
                    data: [<?= $dbBytes ?>, <?= max(0, $msDiskUsed - $dbBytes) ?>, <?= $msDiskFree ?>],
                    backgroundColor: ['#48bb78', '#6c757d', isDark ? '#2d3748' : '#e2e8f0'],
                    borderWidth: 0,
                }]
            },
            options: {
                responsive: false,
                cutout: '60%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: { label: ctx => ctx.label + ': ' + fmtB(ctx.raw) }
                    }
                }
            }
        });
    })();
    <?php endif; ?>

    // --- ClickHouse pie: top clients by catalog disk usage ---
    <?php if ($isAdmin && !empty($chTopRepos)): ?>
    (function () {
        const el = document.getElementById('catalogPieChart');
        if (!el) return;
        const colors = ['#36a2eb','#ff6384','#ffce56','#4bc0c0','#9966ff','#6c757d'];
        const repos = <?= json_encode($chTopRepos) ?>;
        const diskTotal = <?= $chDiskBytes ?>;
        const top5Disk = repos.reduce((s, r) => s + Number(r.disk_bytes), 0);
        const otherDisk = Math.max(diskTotal - top5Disk, 0);
        const labels = repos.map(r => r.name);
        const data = repos.map(r => Number(r.disk_bytes));
        if (otherDisk > 0) { labels.push('Other'); data.push(otherDisk); }
        const fmtB = b => { b = Number(b); if (b >= 1099511627776) return (b/1099511627776).toFixed(1)+' TB'; if (b >= 1073741824) return (b/1073741824).toFixed(1)+' GB'; if (b >= 1048576) return (b/1048576).toFixed(1)+' MB'; return (b/1024).toFixed(0)+' KB'; };
        new Chart(el.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: colors.slice(0, data.length),
                    borderWidth: 0,
                }]
            },
            options: {
                responsive: false,
                cutout: '55%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: { label: ctx => ctx.label + ': ' + fmtB(ctx.raw) }
                    }
                }
            }
        });
        // Legend is rendered server-side as a list, no JS needed.
    })();
    <?php endif; ?>
});
</script>
