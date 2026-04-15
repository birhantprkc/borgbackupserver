<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <a href="/clients" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 metric-card-blue">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary rounded-3 p-3 me-3">
                        <i class="bi bi-display fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Clients</div>
                        <div class="fs-4 fw-bold" id="stat-agents"><?= $agentCount ?></div>
                        <div class="text-muted small"><span id="stat-online"><?= $onlineCount ?></span> online</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl-3 col-md-6">
        <a href="/queue" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 metric-card-success">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-success bg-opacity-10 text-success rounded-3 p-3 me-3">
                        <i class="bi bi-arrow-repeat fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Backups Running</div>
                        <div class="fs-4 fw-bold" id="stat-running"><?= $runningJobs ?></div>
                        <div class="text-muted small">active jobs</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl-3 col-md-6">
        <a href="/queue" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 metric-card-warning">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning rounded-3 p-3 me-3">
                        <i class="bi bi-hourglass-split fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Queue Waiting</div>
                        <div class="fs-4 fw-bold" id="stat-queued"><?= $queuedJobs ?></div>
                        <div class="text-muted small">pending jobs</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl-3 col-md-6">
        <a href="/log?level=error" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 metric-card-danger">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-danger bg-opacity-10 text-danger rounded-3 p-3 me-3">
                        <i class="bi bi-exclamation-circle fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Errors (24h)</div>
                        <div class="fs-4 fw-bold" id="stat-errors"><?= $errorCount ?></div>
                        <div class="text-muted small">check logs</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Row 2: Backups Chart | Server Stats | Server Partitions -->
<div class="row g-4 mb-4">
    <div class="<?= $isAdmin ? 'col-lg-4' : 'col-12' ?>">
        <div class="card border-0 card-no-outline shadow-sm h-100">
            <div class="card-header bg-body fw-semibold">
                <i class="bi bi-bar-chart me-1"></i> Jobs (24h)
            </div>
            <div class="card-body py-2 d-flex">
                <div style="position: relative; width: 100%; min-height: 200px;">
                    <canvas id="backupsChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <?php if ($isAdmin): ?>
    <div class="col-lg-3">
        <div class="card border-0 card-no-outline shadow-sm h-100">
            <div class="card-header bg-body fw-semibold">
                <i class="bi bi-cpu me-1"></i> Server Stats
            </div>
            <div class="card-body">
                <?php
                    $cpuColor = $cpuLoad['percent'] > 80 ? '#dc3545' : ($cpuLoad['percent'] > 50 ? '#ffc107' : '#198754');
                    $memColor = $memory['percent'] > 85 ? '#dc3545' : ($memory['percent'] > 60 ? '#ffc107' : '#0dcaf0');
                    $arcLen = 212.06;
                    $circum = 282.74;
                    $cpuDash = $arcLen * $cpuLoad['percent'] / 100;
                    $memDash = $arcLen * $memory['percent'] / 100;
                ?>
                <div class="d-flex justify-content-around">
                    <div class="text-center" style="width:120px;">
                        <svg viewBox="0 0 120 95" style="width:100%;height:auto;">
                            <circle cx="60" cy="55" r="45" fill="none" class="gauge-track" stroke-width="8"
                                stroke-dasharray="<?= $arcLen ?> <?= $circum ?>" stroke-linecap="round"
                                transform="rotate(135 60 55)"/>
                            <circle cx="60" cy="55" r="45" fill="none" stroke="<?= $cpuColor ?>" stroke-width="8"
                                id="cpu-arc" stroke-dasharray="<?= $cpuDash ?> <?= $circum ?>" stroke-linecap="round"
                                transform="rotate(135 60 55)" style="transition: stroke-dasharray .5s ease, stroke .5s ease;"/>
                            <text x="60" y="48" text-anchor="middle" font-size="18" font-weight="bold" class="gauge-pct" id="cpu-pct"><?= $cpuLoad['percent'] ?>%</text>
                            <text x="60" y="62" text-anchor="middle" font-size="8" class="gauge-detail" id="cpu-detail"><?= $cpuLoad['1min'] ?> / <?= $cpuLoad['cores'] ?> cores</text>
                        </svg>
                        <div class="text-muted" style="font-size:.75rem;margin-top:-8px;">CPU</div>
                    </div>
                    <div class="text-center" style="width:120px;">
                        <svg viewBox="0 0 120 95" style="width:100%;height:auto;">
                            <circle cx="60" cy="55" r="45" fill="none" class="gauge-track" stroke-width="8"
                                stroke-dasharray="<?= $arcLen ?> <?= $circum ?>" stroke-linecap="round"
                                transform="rotate(135 60 55)"/>
                            <circle cx="60" cy="55" r="45" fill="none" stroke="<?= $memColor ?>" stroke-width="8"
                                id="mem-arc" stroke-dasharray="<?= $memDash ?> <?= $circum ?>" stroke-linecap="round"
                                transform="rotate(135 60 55)" style="transition: stroke-dasharray .5s ease, stroke .5s ease;"/>
                            <text x="60" y="48" text-anchor="middle" font-size="18" font-weight="bold" class="gauge-pct" id="mem-pct"><?= $memory['percent'] ?>%</text>
                            <text x="60" y="62" text-anchor="middle" font-size="8" class="gauge-detail" id="mem-detail"><?= \BBS\Services\ServerStats::formatBytes($memory['used']) ?> / <?= \BBS\Services\ServerStats::formatBytes($memory['total']) ?></text>
                        </svg>
                        <div class="text-muted" style="font-size:.75rem;margin-top:-8px;">Memory</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 card-no-outline shadow-sm h-100">
            <div class="card-header bg-body fw-semibold">
                <i class="bi bi-hdd-stack me-1"></i> Server Partitions
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
                                    <div class="progress" style="height: 18px;">
                                        <div class="progress-bar progress-bar-striped <?= $part['percent'] > 90 ? 'bg-danger' : ($part['percent'] > 70 ? 'bg-warning' : '') ?>"
                                             style="width: <?= $part['percent'] ?>%; <?= $part['percent'] <= 70 ? 'background-color:#8faabe;' : '' ?>">
                                        </div>
                                    </div>
                                </td>
                                <td class="d-table-cell-md"><?= $part['size'] ?></td>
                                <td class="d-table-cell-md"><?= $part['free'] ?></td>
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

<?php if ($isAdmin): ?>
<?php
// Compact number formatter: 1234 → "1,234", 51200 → "51.2K", 1100000 → "1.1M"
$compactNum = function(int $n): string {
    if ($n >= 1000000) return round($n / 1000000, 1) . 'M';
    if ($n >= 10000) return round($n / 1000, 1) . 'K';
    return number_format($n);
};
$chStats = $clickhouseStats ?? null;
$pieColors = ['#36a2eb', '#ff6384', '#ffce56', '#4bc0c0', '#9966ff', '#6c757d'];
?>
<!-- Row 3: Storage Pool + MySQL Health (left) | ClickHouse Catalog (right) -->
<div class="row g-4 mb-4">
    <!-- Left column: Storage Pool + MySQL Health stacked -->
    <div class="col-lg-4">
        <?php if (!empty($storage) && $storage['disk_total'] !== null): ?>
        <?php
            $stUsed = $storage['disk_used'] ?? ($storage['disk_total'] - $storage['disk_free']);
            $stRepoPct = $storage['disk_total'] > 0 ? round($storage['total_repo_bytes'] / $storage['disk_total'] * 100, 1) : 0;
            $stOtherPct = $storage['disk_total'] > 0 ? round(($stUsed - $storage['total_repo_bytes']) / $storage['disk_total'] * 100, 1) : 0;
            if ($stOtherPct < 0) $stOtherPct = 0;
            $stFreePct = $storage['disk_total'] > 0 ? round($storage['disk_free'] / $storage['disk_total'] * 100, 1) : 0;
            $stUsedPct = round(100 - $stFreePct, 1);
            // SVG donut
            $r = 45; $c = 2 * M_PI * $r;
            $seg1 = $c * $stRepoPct / 100;
            $seg2 = $c * $stOtherPct / 100;
            $off2 = $seg1;
            // Dedup ratio
            $dedupSavings = $storage['dedup_savings'] ?? 0;
            $totalOrig = (int)($storage['total_original'] ?? 0);
            $totalDedup = (int)($storage['total_dedup'] ?? 0);
            $dedupRatio = ($totalDedup > 0 && $totalOrig > 0) ? round($totalOrig / $totalDedup, 1) : 0;
        ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-body d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-device-hdd me-1"></i> Storage Pool</span>
                <span class="text-muted" style="font-size:.7rem;"><?= htmlspecialchars($storage['path']) ?></span>
            </div>
            <div class="card-body px-3 py-2">
                <div class="d-flex align-items-center justify-content-between">
                    <!-- Col 1: Repositories / Recovery Points (hidden on smaller screens) -->
                    <div class="d-none d-xxl-block">
                        <div class="mb-2">
                            <div class="fw-semibold text-success" style="font-size:.6rem;">Repositories</div>
                            <div class="d-flex align-items-center">
                                <i class="bi bi-archive text-muted me-1" style="font-size:.75rem;"></i>
                                <span class="fw-bold" style="font-size:1rem;line-height:1;"><?= $storage['repo_count'] ?></span>
                            </div>
                        </div>
                        <div>
                            <div class="fw-semibold text-success" style="font-size:.6rem;">Recovery Points</div>
                            <div class="d-flex align-items-center">
                                <i class="bi bi-clock-history text-muted me-1" style="font-size:.75rem;"></i>
                                <span class="fw-bold" style="font-size:1rem;line-height:1;"><?= number_format($storage['total_archives'] ?? 0) ?></span>
                            </div>
                        </div>
                    </div>
                    <!-- Col 2: Protected Data / Dedup Savings -->
                    <div>
                        <div class="mb-2">
                            <div class="fw-semibold text-success" style="font-size:.6rem;">Protected Data</div>
                            <div class="d-flex align-items-center">
                                <i class="bi bi-shield-check text-muted me-1" style="font-size:.75rem;"></i>
                                <span class="fw-bold" style="font-size:1rem;line-height:1;"><?= \BBS\Services\ServerStats::formatBytes($totalOrig) ?></span>
                            </div>
                        </div>
                        <div>
                            <div class="fw-semibold text-success" style="font-size:.6rem;">Dedup Savings</div>
                            <div class="d-flex align-items-center">
                                <span class="fw-bold" style="font-size:1rem;line-height:1;"><?= $dedupSavings ?>%</span>
                                <?php if ($dedupRatio >= 1): ?>
                                <span class="text-muted ms-1" style="font-size:.55rem;">(<?= $dedupRatio == (int)$dedupRatio ? (int)$dedupRatio : $dedupRatio ?>:1)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <!-- Col 3: Donut + Legend -->
                    <div class="d-flex align-items-center">
                        <div style="width:80px;flex-shrink:0;margin-top:-20px;">
                            <svg viewBox="0 0 120 120" style="width:100%;height:auto;transform:rotate(-90deg);">
                                <circle cx="60" cy="60" r="<?= $r ?>" fill="none" class="donut-track" stroke-width="14"/>
                                <?php if ($stRepoPct > 0): ?>
                                <circle cx="60" cy="60" r="<?= $r ?>" fill="none" stroke="#48bb78" stroke-width="14"
                                    stroke-dasharray="<?= round($seg1, 2) ?> <?= round($c - $seg1, 2) ?>"
                                    stroke-dashoffset="0"/>
                                <?php endif; ?>
                                <?php if ($stOtherPct > 0): ?>
                                <circle cx="60" cy="60" r="<?= $r ?>" fill="none" stroke="#6c757d" stroke-width="14"
                                    stroke-dasharray="<?= round($seg2, 2) ?> <?= round($c - $seg2, 2) ?>"
                                    stroke-dashoffset="-<?= round($off2, 2) ?>"/>
                                <?php endif; ?>
                            </svg>
                            <div class="text-center" style="margin-top:-55px;position:relative;line-height:1.2;">
                                <div class="fw-bold" style="font-size:.8rem;"><?= $stUsedPct ?>%</div>
                                <div class="text-muted" style="font-size:.5rem;">used</div>
                            </div>
                        </div>
                        <div class="ms-2" style="font-size:.6rem;line-height:1.7;">
                            <div><span style="display:inline-block;width:7px;height:7px;border-radius:2px;background:#48bb78;margin-right:3px;"></span>Repos <?= \BBS\Services\ServerStats::formatBytes($storage['total_repo_bytes']) ?></div>
                            <?php if ($stOtherPct > 0): ?>
                            <div><span style="display:inline-block;width:7px;height:7px;border-radius:2px;background:#6c757d;margin-right:3px;"></span>Other <?= \BBS\Services\ServerStats::formatBytes($stUsed - $storage['total_repo_bytes']) ?></div>
                            <?php endif; ?>
                            <div><span class="donut-free-dot" style="display:inline-block;width:7px;height:7px;border-radius:2px;margin-right:3px;"></span>Free <?= \BBS\Services\ServerStats::formatBytes($storage['disk_free']) ?></div>
                            <div class="text-muted">Total: <?= \BBS\Services\ServerStats::formatBytes($storage['disk_total']) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php /* Remote storage dashboard widgets — disabled pending redesign
        <?php if (!empty($storage['remote_storage'])): ?>
        <?php foreach ($storage['remote_storage'] as $rs): ?>
        ...
        <?php endforeach; ?>
        <?php endif; ?>
        */ ?>

        <?php if (!empty($mysqlStats)): ?>
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-body fw-semibold py-2">
                <i class="bi bi-database me-1"></i> MySQL Health
            </div>
            <div class="card-body py-2">
                <div class="row g-2 text-center" style="font-size:.7rem;">
                    <div class="col-4">
                        <div class="fw-bold" style="font-size:.9rem;" id="stat-qps"><?= $mysqlStats['qps'] ?></div>
                        <div class="text-muted">QPS</div>
                    </div>
                    <div class="col-4">
                        <div class="fw-bold" style="font-size:.9rem;" id="stat-connections"><?= $mysqlStats['threads_connected'] ?></div>
                        <div class="text-muted">Connections</div>
                    </div>
                    <div class="col-4">
                        <div class="fw-bold" style="font-size:.9rem;" id="stat-hit-rate"><?= $mysqlStats['hit_rate'] ?>%</div>
                        <div class="text-muted">Hit Rate</div>
                    </div>
                    <div class="col-4">
                        <?php
                        $uptimeDays = floor($mysqlStats['uptime'] / 86400);
                        $uptimeHrs = floor(($mysqlStats['uptime'] % 86400) / 3600);
                        $uptimeStr = $uptimeDays > 0 ? "{$uptimeDays}d {$uptimeHrs}h" : "{$uptimeHrs}h";
                        ?>
                        <div class="fw-bold" style="font-size:.9rem;" id="stat-uptime"><?= $uptimeStr ?></div>
                        <div class="text-muted">Uptime</div>
                    </div>
                    <div class="col-4">
                        <div class="fw-bold" style="font-size:.9rem;" id="stat-bp-usage"><?= $mysqlStats['buffer_pool_used_pct'] ?>%</div>
                        <div class="text-muted">Buffer Pool</div>
                    </div>
                    <div class="col-4">
                        <div class="fw-bold" style="font-size:.9rem;" id="stat-slow"><?= number_format($mysqlStats['slow_queries']) ?></div>
                        <div class="text-muted">Slow Queries</div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right column: ClickHouse Catalog (spans full height) -->
    <?php if (!empty($mysqlStats)): ?>
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-body fw-semibold">
                <img src="/images/clickhouse.svg" alt="" style="height:1em;vertical-align:-.1em;" class="me-1"> ClickHouse Catalog
            </div>
            <div class="card-body py-3">
                <div class="d-flex h-100">
                    <!-- Left: Stats + Table -->
                    <div class="flex-grow-1" style="min-width:0;">
                        <!-- Row count + Recovery Points + Jobs -->
                        <div class="d-flex align-items-center mb-2">
                            <div class="me-auto">
                                <div class="fw-bold" style="font-size:2.2rem;line-height:1;color:#e67e22;"><span id="stat-catalog"><?= $compactNum($mysqlStats['catalog_files']) ?></span> <span style="font-size:1rem;color:#999;">Rows</span></div>
                            </div>
                            <div class="d-flex gap-4 text-center" style="font-size:.75rem;">
                                <div>
                                    <div class="fw-bold" style="font-size:1rem;" id="stat-archives"><?= $compactNum($mysqlStats['archives']) ?></div>
                                    <div class="text-muted">Recovery Points</div>
                                </div>
                                <div>
                                    <div class="fw-bold" style="font-size:1rem;" id="stat-completed-jobs"><?= $compactNum($mysqlStats['completed_jobs']) ?></div>
                                    <div class="text-muted">Jobs Run</div>
                                </div>
                            </div>
                        </div>
                        <?php if ($chStats): ?>
                        <!-- ClickHouse Stats Grid -->
                        <div class="border-top pt-2 mt-1" id="ch-stats-grid">
                            <div class="row g-1 text-center" style="font-size:.7rem;">
                                <div class="col-3">
                                    <div class="fw-bold" style="font-size:.85rem;" id="stat-ch-disk"><?= \BBS\Services\ServerStats::formatBytes($chStats['disk_bytes']) ?></div>
                                    <div class="text-muted">Disk Usage</div>
                                </div>
                                <div class="col-3">
                                    <div class="fw-bold" style="font-size:.85rem;" id="stat-ch-compression"><?= $chStats['compression_ratio'] ?>x</div>
                                    <div class="text-muted">Compress</div>
                                </div>
                                <div class="col-3">
                                    <div class="fw-bold" style="font-size:.85rem;" id="stat-ch-agents"><?= $chStats['agent_count'] ?></div>
                                    <div class="text-muted">Agents</div>
                                </div>
                                <div class="col-3">
                                    <div class="fw-bold" style="font-size:.85rem;" id="stat-ch-avg-archive"><?= $compactNum($chStats['avg_per_archive']) ?></div>
                                    <div class="text-muted">Avg/Archive</div>
                                </div>
                            </div>
                        </div>
                        <!-- Top Repositories Table -->
                        <?php if (!empty($chStats['top_repos'])): ?>
                        <div class="border-top pt-2 mt-2" id="ch-repos-section">
                            <div class="d-flex align-items-center mb-1">
                                <i class="bi bi-trophy me-1 text-muted" style="font-size:.7rem;"></i>
                                <span class="fw-semibold text-muted" style="font-size:.65rem;text-transform:uppercase;letter-spacing:.5px;">Top Repositories</span>
                            </div>
                            <table class="table table-sm mb-0" style="font-size:.7rem;" id="ch-top-repos">
                                <tbody>
                                    <?php foreach ($chStats['top_repos'] as $i => $repo): ?>
                                    <tr>
                                        <td class="border-0 py-0 ps-0"><span style="display:inline-block;width:7px;height:7px;border-radius:2px;background:<?= $pieColors[$i % 5] ?>;margin-right:4px;"></span><span class="fw-semibold"><?= htmlspecialchars($repo['name']) ?></span></td>
                                        <td class="border-0 py-0 text-end text-muted"><?= $compactNum($repo['rows']) ?> rows</td>
                                        <td class="border-0 py-0 text-end text-muted d-none d-xl-table-cell"><?= $repo['archives'] ?> archives</td>
                                        <td class="border-0 py-0 text-end text-muted"><?= \BBS\Services\ServerStats::formatBytes($repo['disk_bytes']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <!-- Right: Pie Chart -->
                    <?php if ($chStats && !empty($chStats['top_repos'])): ?>
                    <?php
                        $top5Disk = array_sum(array_column($chStats['top_repos'], 'disk_bytes'));
                        $otherDisk = max($chStats['disk_bytes'] - $top5Disk, 0);
                        $pieLabels = array_map(fn($r) => $r['name'], $chStats['top_repos']);
                        $pieData = array_map(fn($r) => $r['disk_bytes'], $chStats['top_repos']);
                        if ($otherDisk > 0) {
                            $pieLabels[] = 'Other';
                            $pieData[] = $otherDisk;
                        }
                    ?>
                    <div class="d-none d-md-flex flex-column align-items-center justify-content-center border-start ms-3 ps-3" style="flex:0 0 33%;max-width:33%;" id="ch-pie-wrap">
                        <div class="fw-semibold text-muted mb-2" style="font-size:.65rem;text-transform:uppercase;letter-spacing:.5px;">Top Repositories</div>
                        <canvas id="catalogPieChart" style="max-width:200px;max-height:200px;"></canvas>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Active Jobs -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-body fw-semibold">
                <i class="bi bi-play-circle me-1"></i> Active &amp; Queued Jobs
            </div>
            <div class="card-body p-0 dash-table" id="active-jobs">
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
                        <tbody class="small">
                            <?php foreach ($activeJobs as $job): ?>
                            <?php
                                $elapsed = '';
                                if ($job['started_at']) {
                                    $e = time() - strtotime($job['started_at'] . ' UTC');
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
            <div class="card-header bg-body fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-calendar-event me-1"></i> Upcoming Backups</span>
                <a href="/schedules" class="small text-decoration-none">
                    View Schedule <i class="bi bi-arrow-right"></i>
                </a>
            </div>
            <div class="card-body p-0 dash-table" id="upcoming-backups">
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
                        <tbody class="small">
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
                                <td><?= \BBS\Core\TimeHelper::format($sched['next_run'], 'M j, g:i A') ?></td>
                                <td class="d-table-cell-md <?= $countdownClass ?>"><?= $countdown ?></td>
                                <td class="text-nowrap" onclick="event.stopPropagation()">
                                    <form method="POST" action="/plans/<?= $sched['plan_id'] ?>/trigger" class="d-inline" data-confirm="Manually run <?= htmlspecialchars($sched['agent_name']) ?> / <?= htmlspecialchars($sched['plan_name']) ?> backup now?">
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
            <div class="card-header bg-body fw-semibold">
                <i class="bi bi-check-circle me-1"></i> Recently Completed
            </div>
            <div class="card-body p-0 dash-table" id="recent-jobs">
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
                        <tbody class="small">
                            <?php foreach ($recentJobs as $job): ?>
                            <?php
                                $d = $job['duration_seconds'] ?? 0;
                                $durStr = $d >= 3600 ? floor($d / 3600) . 'h ' . floor(($d % 3600) / 60) . 'm'
                                    : ($d >= 60 ? floor($d / 60) . 'm ' . ($d % 60) . 's' : ($d > 0 ? $d . 's' : '--'));
                                $sIcon = match($job['status']) {
                                    'completed' => '<i class="bi bi-check-circle-fill text-success"></i>',
                                    'failed' => '<i class="bi bi-x-circle-fill text-danger"></i>',
                                    'cancelled' => '<i class="bi bi-slash-circle-fill text-secondary"></i>',
                                    default => '<i class="bi bi-exclamation-triangle-fill text-warning"></i>',
                                };
                            ?>
                            <tr style="cursor: pointer;" onclick="window.location='/queue/<?= $job['id'] ?>'">
                                <td><?= htmlspecialchars($job['agent_name']) ?></td>
                                <td><?= ucfirst($job['task_type']) ?></td>
                                <td class="d-table-cell-md"><?= htmlspecialchars($job['plan_name'] ?? '--') ?></td>
                                <td class="d-table-cell-md"><?= htmlspecialchars($job['repo_name'] ?? '--') ?></td>
                                <td title="<?= \BBS\Core\TimeHelper::format($job['completed_at'], 'M j, Y g:i A') ?>"><?= \BBS\Core\TimeHelper::ago($job['completed_at']) ?></td>
                                <td class="d-table-cell-md text-nowrap"><?= $durStr ?></td>
                                <td class="text-center"><?= $sIcon ?></td>
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
// Jobs Chart (stacked bar)
const chartData = <?= json_encode($chartData) ?>;
const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
const chartTextColor = isDark ? '#8b929a' : '#6c757d';
const chartGridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.05)';
const ctx = document.getElementById('backupsChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: chartData.map(d => d.label),
        datasets: [
            {
                label: 'Backups',
                data: chartData.map(d => d.backups),
                backgroundColor: 'rgba(54, 162, 235, 0.7)',
                borderRadius: 2,
            },
            {
                label: 'Restores',
                data: chartData.map(d => d.restores),
                backgroundColor: 'rgba(255, 159, 64, 0.7)',
                borderRadius: 2,
            },
            {
                label: 'S3 Sync',
                data: chartData.map(d => d.s3_sync),
                backgroundColor: 'rgba(75, 192, 192, 0.7)',
                borderRadius: 2,
            },
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'bottom',
                labels: { boxWidth: 10, font: { size: 9 }, padding: 8, color: chartTextColor },
            },
        },
        scales: {
            y: {
                beginAtZero: true,
                stacked: true,
                ticks: { stepSize: 1, font: { size: 10 }, color: chartTextColor },
                grid: { color: chartGridColor },
            },
            x: {
                stacked: true,
                ticks: {
                    font: { size: 9 },
                    color: chartTextColor,
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

// Catalog Pie Chart
const pieColors = ['#36a2eb','#ff6384','#ffce56','#4bc0c0','#9966ff','#6c757d'];
const fmtBytes = b => { b = Number(b); if (b >= 1099511627776) return (b/1099511627776).toFixed(1)+' TB'; if (b >= 1073741824) return (b/1073741824).toFixed(1)+' GB'; if (b >= 1048576) return (b/1048576).toFixed(1)+' MB'; return (b/1024).toFixed(1)+' KB'; };
<?php if (!empty($chStats['top_repos'] ?? null)): ?>
const pieCanvas = document.getElementById('catalogPieChart');
let catalogPieChart = null;
if (pieCanvas) {
    catalogPieChart = new Chart(pieCanvas.getContext('2d'), {
        type: 'pie',
        data: {
            labels: <?= json_encode($pieLabels) ?>,
            datasets: [{
                data: <?= json_encode($pieData) ?>,
                backgroundColor: pieColors.slice(0, <?= count($pieData) ?>),
                borderWidth: isDark ? 0 : 1,
                borderColor: isDark ? 'transparent' : '#fff',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                            const pct = total > 0 ? (ctx.raw / total * 100).toFixed(1) : 0;
                            return ctx.label + ': ' + fmtBytes(ctx.raw) + ' (' + pct + '%)';
                        }
                    }
                }
            }
        }
    });
}
<?php endif; ?>

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

// Helper: format date like "Jan 30, 4:15 PM" or "Jan 30, 16:15"
// Uses BBS profile timezone, not browser timezone
function fmtDate(str) {
    if (!str) return '--';
    const d = new Date(str.replace(' ', 'T') + 'Z');
    const tz = window.BBS_TIMEZONE || 'UTC';
    const timeOpts = window.BBS_TIME_24H
        ? { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: tz }
        : { hour: 'numeric', minute: '2-digit', timeZone: tz };
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', timeZone: tz }) + ', ' +
           d.toLocaleTimeString('en-US', timeOpts);
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
        html += '<tr style="cursor:pointer" onclick="window.location=\'/clients/'+s.agent_id+'?tab=schedules\'"><td>'+esc(s.agent_name)+'</td><td>'+esc(s.plan_name)+'</td><td class="d-table-cell-md">'+esc(s.frequency?.[0]?.toUpperCase()+s.frequency?.slice(1))+'</td><td>'+fmtDate(s.next_run)+'</td><td class="d-table-cell-md '+cd.cls+'">'+cd.text+'</td>';
        html += '<td class="text-nowrap" onclick="event.stopPropagation()"><form method="POST" action="/plans/'+s.plan_id+'/trigger" class="d-inline" data-confirm="Manually run '+esc(s.agent_name)+' / '+esc(s.plan_name)+' backup now?"><input type="hidden" name="csrf_token" value="'+csrfToken+'"><button type="submit" class="btn btn-sm btn-outline-success py-0 px-2" title="Run now"><i class="bi bi-play-fill"></i></button></form></td>';
        html += '</tr>';
    });
    html += '</tbody></table></div>';
    el.innerHTML = html;
}

function timeAgo(str) {
    if (!str) return '';
    const then = new Date((str).replace(' ','T')+'Z').getTime();
    const diff = Math.floor((Date.now() - then) / 1000);
    if (diff < 0) return 'just now';
    if (diff < 60) return diff + 's ago';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
}

function statusIcon(status) {
    const map = {
        completed: '<i class="bi bi-check-circle-fill text-success"></i>',
        failed: '<i class="bi bi-x-circle-fill text-danger"></i>',
        cancelled: '<i class="bi bi-slash-circle-fill text-secondary"></i>'
    };
    return map[status] || '<i class="bi bi-exclamation-triangle-fill text-warning"></i>';
}

function renderRecentJobs(jobs) {
    const el = document.getElementById('recent-jobs');
    if (!jobs || !jobs.length) { el.innerHTML = '<div class="p-4 text-muted text-center">No completed jobs yet</div>'; return; }
    let html = '<div class="table-responsive"><table class="table table-hover mb-0"><thead class="table-light"><tr><th>Client</th><th>Task</th><th class="d-th-md">Plan</th><th class="d-th-md">Repo</th><th>Completed</th><th class="d-th-md">Duration</th><th>Status</th></tr></thead><tbody class="small">';
    jobs.forEach(j => {
        html += '<tr style="cursor:pointer" onclick="window.location=\'/queue/'+j.id+'\'"><td>'+esc(j.agent_name)+'</td><td>'+esc(j.task_type?.[0]?.toUpperCase()+j.task_type?.slice(1))+'</td><td class="d-table-cell-md">'+esc(j.plan_name||'--')+'</td><td class="d-table-cell-md">'+esc(j.repo_name||'--')+'</td><td title="'+esc(fmtDate(j.completed_at))+'">'+timeAgo(j.completed_at)+'</td><td class="d-table-cell-md text-nowrap">'+fmtDur(j.duration_seconds)+'</td><td class="text-center">'+statusIcon(j.status)+'</td></tr>';
    });
    html += '</tbody></table></div>';
    el.innerHTML = html;
}

function renderLogs(logs) {
    const el = document.getElementById('server-log');
    if (!logs || !logs.length) { el.innerHTML = '<div class="p-4 text-muted text-center">No log entries</div>'; return; }
    // Desktop table
    let html = '<div class="table-responsive d-none d-md-block"><table class="table table-hover mb-0 small"><thead class="table-light"><tr><th>Time</th><th>Client</th><th>Level</th><th>Message</th></tr></thead><tbody>';
    logs.forEach(l => {
        const badge = { error: 'danger', warning: 'warning' }[l.level] || 'info';
        html += '<tr><td class="text-nowrap">'+fmtDate(l.created_at)+'</td><td class="text-nowrap">'+esc(l.agent_name||'--')+'</td><td><span class="badge bg-'+badge+'">'+esc(l.level)+'</span></td><td>'+esc(l.message)+'</td></tr>';
    });
    html += '</tbody></table></div>';
    // Mobile list
    html += '<div class="d-md-none">';
    logs.forEach((l, i) => {
        const badge = { error: 'danger', warning: 'warning' }[l.level] || 'info';
        html += '<div class="px-3 py-2'+(i > 0 ? ' border-top' : '')+'">';
        html += '<div class="d-flex align-items-center gap-2 small"><span class="badge bg-'+badge+'">'+esc(l.level)+'</span><span class="text-muted">'+fmtDate(l.created_at)+'</span>';
        if (l.agent_name) html += '<span class="text-muted">&middot; '+esc(l.agent_name)+'</span>';
        html += '</div><div class="small mt-1" style="word-break:break-word;">'+esc(l.message)+'</div></div>';
    });
    html += '</div>';
    el.innerHTML = html;
}

const csrfToken = '<?= $this->csrfToken() ?>';

// Fast refresh every 8 seconds (queues, jobs, logs — no ClickHouse)
setInterval(function() {
    fetch('/dashboard/json', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            document.getElementById('stat-agents').textContent = data.agentCount;
            document.getElementById('stat-online').textContent = data.onlineCount;
            document.getElementById('stat-running').textContent = data.runningJobs;
            document.getElementById('stat-queued').textContent = data.queuedJobs;
            document.getElementById('stat-errors').textContent = data.errorCount;
            renderActiveJobs(data.activeJobs);
            renderUpcoming(data.upcomingSchedules, csrfToken);
            renderRecentJobs(data.recentJobs);
            renderLogs(data.recentLogs);
        })
        .catch(() => {});
}, 8000);

<?php if ($isAdmin): ?>
// Slow stats refresh every 60 seconds (ClickHouse, server health)
function updateSlowStats(data) {
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
        const fmt = n => { n = Number(n); if (n >= 1000000) return (n/1000000).toFixed(1)+'M'; if (n >= 10000) return (n/1000).toFixed(1)+'K'; return n.toLocaleString(); };
        const map = {
            'stat-catalog': ms.catalog_files, 'stat-archives': ms.archives,
            'stat-completed-jobs': ms.completed_jobs
        };
        for (const [id, val] of Object.entries(map)) {
            const el = document.getElementById(id);
            if (el) el.textContent = fmt(val);
        }
        const qpsEl = document.getElementById('stat-qps');
        if (qpsEl) qpsEl.textContent = ms.qps;
        const connEl = document.getElementById('stat-connections');
        if (connEl) connEl.textContent = ms.threads_connected;
        const hitEl = document.getElementById('stat-hit-rate');
        if (hitEl) hitEl.textContent = ms.hit_rate + '%';
        const upEl = document.getElementById('stat-uptime');
        if (upEl) {
            const u = Number(ms.uptime);
            const d = Math.floor(u / 86400), h = Math.floor((u % 86400) / 3600);
            upEl.textContent = d > 0 ? d + 'd ' + h + 'h' : h + 'h';
        }
        const bpEl = document.getElementById('stat-bp-usage');
        if (bpEl) bpEl.textContent = ms.buffer_pool_used_pct + '%';
        const slowEl = document.getElementById('stat-slow');
        if (slowEl) slowEl.textContent = Number(ms.slow_queries).toLocaleString();
    }
    if (data.clickhouseStats) {
        const ch = data.clickhouseStats;
        const fmt = n => { n = Number(n); if (n >= 1000000) return (n/1000000).toFixed(1)+'M'; if (n >= 10000) return (n/1000).toFixed(1)+'K'; return n.toLocaleString(); };
        const fmtB = b => { b = Number(b); if (b >= 1099511627776) return (b/1099511627776).toFixed(1)+'TB'; if (b >= 1073741824) return (b/1073741824).toFixed(1)+'GB'; if (b >= 1048576) return (b/1048576).toFixed(1)+'MB'; return (b/1024).toFixed(1)+'KB'; };
        const chMap = {
            'stat-ch-disk': fmtB(ch.disk_bytes),
            'stat-ch-compression': ch.compression_ratio + 'x',
            'stat-ch-agents': ch.agent_count,
            'stat-ch-avg-archive': fmt(ch.avg_per_archive)
        };
        for (const [id, val] of Object.entries(chMap)) {
            const el = document.getElementById(id);
            if (el) el.textContent = val;
        }
        const repoTable = document.getElementById('ch-top-repos');
        if (repoTable && ch.top_repos) {
            let html = '<tbody>';
            ch.top_repos.forEach((r, i) => {
                html += '<tr><td class="border-0 py-0 ps-0"><span style="display:inline-block;width:7px;height:7px;border-radius:2px;background:'+pieColors[i%5]+';margin-right:4px;"></span><span class="fw-semibold">'+esc(r.name)+'</span></td><td class="border-0 py-0 text-end text-muted">'+fmt(r.rows)+' rows</td><td class="border-0 py-0 text-end text-muted d-none d-xl-table-cell">'+r.archives+' archives</td><td class="border-0 py-0 text-end text-muted">'+fmtB(r.disk_bytes)+'</td></tr>';
            });
            html += '</tbody>';
            repoTable.innerHTML = html;
        }
        if (typeof catalogPieChart !== 'undefined' && catalogPieChart && ch.top_repos) {
            const top5Disk = ch.top_repos.reduce((s, r) => s + Number(r.disk_bytes), 0);
            const otherDisk = Math.max(Number(ch.disk_bytes) - top5Disk, 0);
            const labels = ch.top_repos.map(r => r.name);
            const vals = ch.top_repos.map(r => Number(r.disk_bytes));
            if (otherDisk > 0) { labels.push('Other'); vals.push(otherDisk); }
            catalogPieChart.data.labels = labels;
            catalogPieChart.data.datasets[0].data = vals;
            catalogPieChart.data.datasets[0].backgroundColor = pieColors.slice(0, vals.length);
            catalogPieChart.update('none');
        }
    }
}
function fetchSlowStats() {
    fetch('/dashboard/stats-json', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(updateSlowStats)
        .catch(() => {});
}
// Background refresh on load, then every 60s
setTimeout(fetchSlowStats, 500);
setInterval(fetchSlowStats, 60000);
<?php endif; ?>
</script>
