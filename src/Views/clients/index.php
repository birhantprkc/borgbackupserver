<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Backup Clients</h4>
    <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
    <a href="/clients/add" class="btn btn-success">
        <i class="bi bi-plus-circle me-1"></i><span class="d-none d-sm-inline"> Add Client</span>
    </a>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (!empty($agents)): ?>
        <div class="px-3 pt-3 pb-2">
            <div class="input-group" style="max-width: 320px;">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="clientSearch" class="form-control border-start-0 ps-0" placeholder="Search clients...">
            </div>
        </div>
        <?php endif; ?>

        <!-- Desktop table view -->
        <div class="table-responsive d-none d-md-block">
            <table class="table table-hover mb-0" id="clientsTable">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Agent Version</th>
                        <th>Restore Points</th>
                        <th>Schedules</th>
                        <th>Repos</th>
                        <th>Size</th>
                        <th>Owner</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($agents)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No clients configured. Click "Add Client" to get started.</td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($agents as $agent): ?>
                    <tr style="cursor:pointer" onclick="window.location='/clients/<?= $agent['id'] ?>'">
                        <td>
                            <i class="bi bi-pc-display me-2 text-muted"></i><strong><?= htmlspecialchars($agent['name']) ?></strong>
                            <?php if ($agent['hostname']): ?>
                                <br><small class="text-muted ps-4 ms-1"><?= htmlspecialchars($agent['hostname']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($agent['agent_version'] ?? '--') ?></td>
                        <td><?= number_format($agent['restore_points']) ?></td>
                        <td><?= $agent['schedule_count'] ?></td>
                        <td><?= $agent['repo_count'] ?></td>
                        <td>
                            <?php
                            $bytes = $agent['total_size'];
                            if ($bytes >= 1073741824) {
                                echo round($bytes / 1073741824, 1) . ' GB';
                            } elseif ($bytes >= 1048576) {
                                echo round($bytes / 1048576, 1) . ' MB';
                            } elseif ($bytes > 0) {
                                echo round($bytes / 1024, 1) . ' KB';
                            } else {
                                echo '--';
                            }
                            ?>
                        </td>
                        <td><?= htmlspecialchars($agent['owner_name'] ?? '--') ?></td>
                        <td>
                            <?php
                            $statusClass = match($agent['status']) {
                                'online' => 'success',
                                'offline' => 'secondary',
                                'error' => 'danger',
                                default => 'warning',
                            };
                            ?>
                            <span class="badge bg-<?= $statusClass ?>"><?= ucfirst($agent['status']) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile card/list view -->
        <div class="d-md-none">
            <?php if (empty($agents)): ?>
            <div class="text-center text-muted py-4">No clients configured. Click "Add Client" to get started.</div>
            <?php endif; ?>
            <div class="list-group list-group-flush" id="clientsList">
                <?php foreach ($agents as $agent):
                    $statusClass = match($agent['status']) {
                        'online' => 'success',
                        'offline' => 'secondary',
                        'error' => 'danger',
                        default => 'warning',
                    };
                    $bytes = $agent['total_size'];
                    $sizeStr = $bytes >= 1073741824 ? round($bytes / 1073741824, 1) . ' GB'
                        : ($bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : '--');
                ?>
                <a href="/clients/<?= $agent['id'] ?>" class="list-group-item list-group-item-action py-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-bold">
                                <i class="bi bi-pc-display me-1 text-muted"></i>
                                <?= htmlspecialchars($agent['name']) ?>
                            </div>
                            <?php if ($agent['hostname']): ?>
                            <small class="text-muted"><?= htmlspecialchars($agent['hostname']) ?></small>
                            <?php endif; ?>
                        </div>
                        <span class="badge bg-<?= $statusClass ?>"><?= ucfirst($agent['status']) ?></span>
                    </div>
                    <div class="d-flex gap-3 mt-2 small text-muted">
                        <span><i class="bi bi-stack me-1"></i><?= number_format($agent['restore_points']) ?> pts</span>
                        <span><i class="bi bi-hdd me-1"></i><?= $sizeStr ?></span>
                        <span><i class="bi bi-archive me-1"></i><?= $agent['repo_count'] ?> repos</span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($agents)): ?>
<script>
document.getElementById('clientSearch').addEventListener('input', function() {
    const filter = this.value.toLowerCase();
    // Filter desktop table
    document.querySelectorAll('#clientsTable tbody tr').forEach(function(row) {
        row.style.display = row.textContent.toLowerCase().includes(filter) ? '' : 'none';
    });
    // Filter mobile list
    document.querySelectorAll('#clientsList .list-group-item').forEach(function(item) {
        item.style.display = item.textContent.toLowerCase().includes(filter) ? '' : 'none';
    });
});
</script>
<?php endif; ?>
