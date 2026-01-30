<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Backup Clients</h4>
    <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
    <a href="/clients/add" class="btn btn-success">
        <i class="bi bi-plus-circle me-1"></i> Add Client
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
        <div class="table-responsive">
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
    </div>
</div>

<?php if (!empty($agents)): ?>
<script>
document.getElementById('clientSearch').addEventListener('input', function() {
    const filter = this.value.toLowerCase();
    const rows = document.querySelectorAll('#clientsTable tbody tr');
    rows.forEach(function(row) {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(filter) ? '' : 'none';
    });
});
</script>
<?php endif; ?>
