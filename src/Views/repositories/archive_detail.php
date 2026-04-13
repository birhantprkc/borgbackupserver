<?php
function fmtSize($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

$statusLabels = [
    'A' => ['Added', 'success'],
    'M' => ['Modified', 'warning'],
    'C' => ['Changed', 'info'],
    'U' => ['Unchanged', 'secondary'],
    'E' => ['Empty', 'light'],
];

$durLabel = '--';
if ($archive['created_at'] && !empty($jobInfo['duration_seconds'])) {
    $d = (int) $jobInfo['duration_seconds'];
    $durLabel = $d >= 3600 ? floor($d / 3600) . 'h ' . floor(($d % 3600) / 60) . 'm'
        : ($d >= 60 ? floor($d / 60) . 'm ' . ($d % 60) . 's' : $d . 's');
}

// Calculate totals from status breakdown
$totalFiles = 0;
$totalSize = 0;
$addedFiles = 0;
$modifiedFiles = 0;
$unchangedFiles = 0;
foreach ($statusBreakdown as $row) {
    if ($row['status'] === 'E') continue;
    $totalFiles += (int) $row['cnt'];
    $totalSize += (int) $row['total_size'];
    if ($row['status'] === 'A') $addedFiles = (int) $row['cnt'];
    if (in_array($row['status'], ['M', 'C'])) $modifiedFiles += (int) $row['cnt'];
    if ($row['status'] === 'U') $unchangedFiles = (int) $row['cnt'];
}
?>

<!-- Breadcrumb -->
<div class="d-flex align-items-center mb-4">
    <a href="/clients/<?= $agentId ?>" class="btn btn-sm btn-outline-secondary me-2"><i class="bi bi-arrow-left"></i></a>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="/clients/<?= $agentId ?>"><?= htmlspecialchars($agent['name']) ?></a></li>
            <li class="breadcrumb-item"><a href="/clients/<?= $agentId ?>/repo/<?= $repo['id'] ?>"><?= htmlspecialchars($repo['name']) ?></a></li>
            <li class="breadcrumb-item active"><?= $planName ? htmlspecialchars($planName) : htmlspecialchars($archive['archive_name']) ?></li>
        </ol>
    </nav>
</div>

<!-- Header -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h5 class="mb-1">
                    <i class="bi bi-archive me-2 text-primary"></i>
                    <?php if ($planName): ?>
                        <?= htmlspecialchars($planName) ?>
                    <?php endif; ?>
                </h5>
                <div class="text-muted small">
                    <code><?= htmlspecialchars($archive['archive_name']) ?></code>
                </div>
            </div>
            <div class="text-end text-muted small">
                <div><i class="bi bi-calendar me-1"></i><?= \BBS\Core\TimeHelper::format($archive['created_at'], 'M j, Y g:i A') ?></div>
                <div><i class="bi bi-clock me-1"></i>Duration: <?= $durLabel ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Summary cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div class="text-muted small">Files</div>
                <div class="fs-4 fw-bold"><?= number_format($archive['file_count'] ?: $totalFiles) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div class="text-muted small">Original Size</div>
                <div class="fs-4 fw-bold"><?= fmtSize($archive['original_size']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div class="text-muted small">Dedup Size</div>
                <div class="fs-4 fw-bold"><?= fmtSize($archive['deduplicated_size']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div class="text-muted small">Dedup Savings</div>
                <div class="fs-4 fw-bold">
                    <?php
                    $savings = $archive['original_size'] > 0
                        ? round((1 - $archive['deduplicated_size'] / $archive['original_size']) * 100, 1)
                        : 0;
                    ?>
                    <?= $savings ?>%
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($clickhouseAvailable && !empty($statusBreakdown)): ?>
<!-- File Changes -->
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-body fw-semibold">
                <i class="bi bi-bar-chart me-1"></i> File Changes
            </div>
            <div class="card-body">
                <?php if ($totalFiles > 0): ?>
                <!-- Progress bar visualization -->
                <div class="progress mb-3" style="height: 24px;">
                    <?php foreach ($statusBreakdown as $row):
                        if ($row['status'] === 'E') continue;
                        $pct = round(((int) $row['cnt'] / $totalFiles) * 100, 1);
                        if ($pct < 0.5) continue;
                        [$label, $color] = $statusLabels[$row['status']] ?? [$row['status'], 'secondary'];
                    ?>
                    <div class="progress-bar bg-<?= $color ?>" style="width: <?= $pct ?>%" title="<?= $label ?>: <?= number_format($row['cnt']) ?> files"><?php if ($pct > 5): ?><?= $label ?><?php endif; ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <table class="table table-sm small mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Status</th>
                            <th class="text-end">Files</th>
                            <th class="text-end">Size</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($statusBreakdown as $row):
                            if ($row['status'] === 'E') continue;
                            [$label, $color] = $statusLabels[$row['status']] ?? [$row['status'], 'secondary'];
                        ?>
                        <tr>
                            <td><span class="badge bg-<?= $color ?>"><?= $label ?></span></td>
                            <td class="text-end"><?= number_format($row['cnt']) ?></td>
                            <td class="text-end"><?= fmtSize($row['total_size']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if ($deletedCount > 0): ?>
                        <tr>
                            <td><span class="badge bg-danger">Deleted</span></td>
                            <td class="text-end"><?= number_format($deletedCount) ?></td>
                            <td class="text-end"><?= fmtSize($deletedSize) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <td class="fw-semibold">Total</td>
                            <td class="text-end fw-semibold"><?= number_format($totalFiles) ?></td>
                            <td class="text-end fw-semibold"><?= fmtSize($totalSize) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-body fw-semibold">
                <i class="bi bi-file-earmark-arrow-up me-1"></i> Largest Files
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm small mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>File</th>
                                <th class="text-end">Size</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($largestFiles as $f):
                                [$label, $color] = $statusLabels[$f['status']] ?? [$f['status'], 'secondary'];
                            ?>
                            <tr>
                                <td class="text-truncate" style="max-width: 300px;" title="<?= htmlspecialchars($f['path']) ?>">
                                    <?= htmlspecialchars($f['file_name']) ?>
                                </td>
                                <td class="text-end text-nowrap"><?= fmtSize($f['file_size']) ?></td>
                                <td><span class="badge bg-<?= $color ?>"><?= $label ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($largestFiles)): ?>
                            <tr><td colspan="3" class="text-muted text-center py-3">No file data available</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($deletedCount > 0 && !empty($deletedFiles)): ?>
<!-- Deleted Files -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-body fw-semibold">
        <i class="bi bi-trash me-1 text-danger"></i> Deleted Files
        <span class="text-muted fw-normal ms-2"><?= number_format($deletedCount) ?> files (<?= fmtSize($deletedSize) ?>) removed since previous backup</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm small mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Path</th>
                        <th class="text-end">Size</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deletedFiles as $f): ?>
                    <tr>
                        <td class="text-truncate" style="max-width: 500px;" title="<?= htmlspecialchars($f['path']) ?>">
                            <?= htmlspecialchars($f['path']) ?>
                        </td>
                        <td class="text-end text-nowrap"><?= fmtSize($f['file_size']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ($deletedCount > 50): ?>
                    <tr><td colspan="2" class="text-muted text-center py-2">Showing top 50 of <?= number_format($deletedCount) ?> deleted files</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php elseif (!$clickhouseAvailable): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i> ClickHouse is not available. Backup file statistics require ClickHouse to be installed and running.
</div>
<?php else: ?>
<div class="alert alert-secondary">
    <i class="bi bi-info-circle me-1"></i> No file catalog data available for this archive. Run a catalog rebuild from the repository page to index file data.
</div>
<?php endif; ?>

<?php if (!empty($jobInfo['directories'])): ?>
<!-- Backup Directories -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-body fw-semibold">
        <i class="bi bi-folder me-1"></i> Backup Directories
    </div>
    <div class="card-body">
        <code class="small"><?= nl2br(htmlspecialchars($jobInfo['directories'])) ?></code>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($archive['databases_backed_up'])): ?>
<!-- Database Backups -->
<?php $dbInfo = json_decode($archive['databases_backed_up'], true); ?>
<?php if ($dbInfo && !empty($dbInfo['databases'])): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-body fw-semibold">
        <i class="bi bi-database me-1 text-info"></i> Database Backups
    </div>
    <div class="card-body">
        <?php foreach ($dbInfo['databases'] as $db): ?>
        <span class="badge bg-info me-1 mb-1"><?= htmlspecialchars($db) ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>
