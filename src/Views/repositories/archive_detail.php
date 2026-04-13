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
    'C' => ['Metadata Changed', 'info'],
    'U' => ['Unchanged', 'secondary'],
    'D' => ['Directory', 'light'],
    'S' => ['Symlink', 'light'],
    'H' => ['Hardlink', 'light'],
    'X' => ['Excluded', 'light'],
    'B' => ['Block Device', 'light'],
    'F' => ['FIFO', 'light'],
    'E' => ['Empty', 'light'],
];

// Non-file entry types — exclude from file counts and size totals
// (symlinks report bogus sizes from os.stat following the target)
$nonFileStatuses = ['D', 'S', 'H', 'X', 'B', 'F', 'E'];

$durLabel = '--';
if ($archive['created_at'] && !empty($jobInfo['duration_seconds'])) {
    $d = (int) $jobInfo['duration_seconds'];
    $durLabel = $d >= 3600 ? floor($d / 3600) . 'h ' . floor(($d % 3600) / 60) . 'm'
        : ($d >= 60 ? floor($d / 60) . 'm ' . ($d % 60) . 's' : $d . 's');
}

// Separate file entries from non-file entries (dirs, symlinks, etc.)
$fileRows = [];
$otherRows = [];
$totalFiles = 0;
$totalSize = 0;
foreach ($statusBreakdown as $row) {
    if (in_array($row['status'], $nonFileStatuses)) {
        $otherRows[] = $row;
    } else {
        $fileRows[] = $row;
        $totalFiles += (int) $row['cnt'];
        $totalSize += (int) $row['total_size'];
    }
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
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-body fw-semibold">
                <i class="bi bi-bar-chart me-1"></i> File Changes
            </div>
            <div class="card-body">
                <?php if ($totalFiles > 0): ?>
                <!-- Progress bar visualization (files only, no dirs/symlinks) -->
                <div class="progress mb-3" style="height: 24px;">
                    <?php foreach ($fileRows as $row):
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
                        <?php foreach ($fileRows as $row):
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
                        <?php if (!empty($otherRows)): ?>
                        <tr><td colspan="3" class="text-muted pt-2" style="border:none;">Other Entries</td></tr>
                        <?php foreach ($otherRows as $row):
                            [$label, $color] = $statusLabels[$row['status']] ?? [$row['status'], 'secondary'];
                        ?>
                        <tr>
                            <td><span class="badge bg-<?= $color ?> text-dark"><?= $label ?></span></td>
                            <td class="text-end text-muted"><?= number_format($row['cnt']) ?></td>
                            <td class="text-end text-muted">--</td>
                        </tr>
                        <?php endforeach; ?>
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

    <div class="col-lg-8">
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
                                <td style="max-width: 400px; word-break: break-all;" title="<?= htmlspecialchars($f['path']) ?>">
                                    <span class="small"><?= htmlspecialchars($f['path']) ?></span>
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

<!-- File Browser -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-body fw-semibold d-flex justify-content-between align-items-center">
        <div>
            <i class="bi bi-files me-1"></i> File Browser
        </div>
        <div style="width: 280px;">
            <input type="text" class="form-control form-control-sm" id="fileBrowserSearch" placeholder="Search files...">
        </div>
    </div>
    <div class="card-body pb-0">
        <ul class="nav nav-tabs" id="fileBrowserTabs">
            <li class="nav-item"><a class="nav-link active" href="javascript:void(0)" data-status="">All</a></li>
            <li class="nav-item"><a class="nav-link" href="javascript:void(0)" data-status="A">Added <span class="badge bg-success" id="tab-count-A"></span></a></li>
            <li class="nav-item"><a class="nav-link" href="javascript:void(0)" data-status="M">Modified <span class="badge bg-warning" id="tab-count-M"></span></a></li>
            <?php if ($prevArchive): ?>
            <li class="nav-item"><a class="nav-link" href="javascript:void(0)" data-status="deleted">Deleted <span class="badge bg-danger" id="tab-count-deleted"><?= $deletedCount > 0 ? number_format($deletedCount) : '' ?></span></a></li>
            <?php endif; ?>
            <li class="nav-item"><a class="nav-link" href="javascript:void(0)" data-status="U">Unchanged <span class="badge bg-secondary" id="tab-count-U"></span></a></li>
        </ul>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover small mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Path</th>
                        <th class="text-end" style="width:100px;">Size</th>
                        <th style="width:80px;">Status</th>
                    </tr>
                </thead>
                <tbody id="fileBrowserBody">
                    <tr><td colspan="3" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-1"></span> Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
            <small class="text-muted" id="fileBrowserInfo">--</small>
            <div>
                <button class="btn btn-sm btn-outline-secondary" id="fileBrowserPrev" disabled>&laquo; Prev</button>
                <button class="btn btn-sm btn-outline-secondary" id="fileBrowserNext" disabled>Next &raquo;</button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var agentId = <?= $agentId ?>;
    var repoId = <?= $repo['id'] ?>;
    var archiveId = <?= $archiveId ?>;
    var prevArchiveId = <?= $prevArchive ? (int) $prevArchive['id'] : 'null' ?>;
    var currentStatus = '';
    var currentSearch = '';
    var currentPage = 1;
    var perPage = 50;
    var searchTimeout = null;

    var statusLabels = {
        'A': ['Added', 'success'],
        'M': ['Modified', 'warning'],
        'C': ['Metadata Changed', 'info'],
        'U': ['Unchanged', 'secondary'],
        'D': ['Directory', 'light'],
        'S': ['Symlink', 'light'],
        'H': ['Hardlink', 'light'],
        'deleted': ['Deleted', 'danger']
    };

    // Set tab counts from the status breakdown data
    <?php foreach ($statusBreakdown as $row): ?>
    <?php if (!in_array($row['status'], $nonFileStatuses)): ?>
    var el = document.getElementById('tab-count-<?= $row['status'] ?>');
    if (el) el.textContent = '<?= number_format($row['cnt']) ?>';
    <?php endif; ?>
    <?php endforeach; ?>

    function esc(s) { return s ? String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') : ''; }

    function fmtSize(b) {
        if (!b || b == 0) return '--';
        b = parseInt(b);
        if (b >= 1073741824) return (b / 1073741824).toFixed(1) + ' GB';
        if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
        if (b >= 1024) return (b / 1024).toFixed(1) + ' KB';
        return b + ' B';
    }

    function loadFiles() {
        var tbody = document.getElementById('fileBrowserBody');
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-1"></span> Loading...</td></tr>';

        var url = '/clients/' + agentId + '/repo/' + repoId + '/archive/' + archiveId + '/files'
            + '?page=' + currentPage + '&per_page=' + perPage;
        if (currentStatus) url += '&status=' + encodeURIComponent(currentStatus);
        if (currentSearch) url += '&search=' + encodeURIComponent(currentSearch);
        if (currentStatus === 'deleted' && prevArchiveId) url += '&prev_archive_id=' + prevArchiveId;

        fetch(url, { credentials: 'same-origin' })
            .then(function(r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function(data) {
                if (data.error) { throw new Error(data.error); }
                var html = '';
                if (data.files && data.files.length > 0) {
                    data.files.forEach(function(f) {
                        var st = statusLabels[f.status] || [f.status, 'secondary'];
                        html += '<tr>';
                        html += '<td style="word-break:break-all;">' + esc(f.path) + '</td>';
                        html += '<td class="text-end text-nowrap">' + fmtSize(f.file_size) + '</td>';
                        html += '<td><span class="badge bg-' + st[1] + '">' + st[0] + '</span></td>';
                        html += '</tr>';
                    });
                } else {
                    html = '<tr><td colspan="3" class="text-center text-muted py-4">No files found</td></tr>';
                }
                tbody.innerHTML = html;

                var total = data.total || 0;
                var pages = Math.ceil(total / perPage);
                document.getElementById('fileBrowserInfo').textContent =
                    'Showing ' + ((currentPage - 1) * perPage + 1) + '–' + Math.min(currentPage * perPage, total) + ' of ' + total.toLocaleString();
                document.getElementById('fileBrowserPrev').disabled = currentPage <= 1;
                document.getElementById('fileBrowserNext').disabled = currentPage >= pages;
            })
            .catch(function() {
                tbody.innerHTML = '<tr><td colspan="3" class="text-center text-danger py-4">Failed to load files</td></tr>';
            });
    }

    // Tab clicks
    document.getElementById('fileBrowserTabs').addEventListener('click', function(e) {
        var link = e.target.closest('a[data-status]');
        if (!link) return;
        e.preventDefault();
        document.querySelectorAll('#fileBrowserTabs .nav-link').forEach(function(a) { a.classList.remove('active'); });
        link.classList.add('active');
        currentStatus = link.dataset.status;
        currentPage = 1;
        loadFiles();
    });

    // Search
    document.getElementById('fileBrowserSearch').addEventListener('input', function() {
        clearTimeout(searchTimeout);
        var val = this.value;
        searchTimeout = setTimeout(function() {
            currentSearch = val;
            currentPage = 1;
            loadFiles();
        }, 300);
    });

    // Pagination
    document.getElementById('fileBrowserPrev').addEventListener('click', function() {
        if (currentPage > 1) { currentPage--; loadFiles(); }
    });
    document.getElementById('fileBrowserNext').addEventListener('click', function() {
        currentPage++; loadFiles();
    });

    // Initial load
    loadFiles();
})();
</script>

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
