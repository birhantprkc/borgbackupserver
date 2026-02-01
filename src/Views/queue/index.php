<h5 class="mb-3">In Progress</h5>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-0">
        <?php if (empty($inProgress)): ?>
        <div class="p-4 text-muted text-center">No jobs in progress.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Task</th>
                        <th class="d-th-md">Files</th>
                        <th>Progress</th>
                        <th class="d-th-md">Repo</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inProgress as $job): ?>
                    <tr style="cursor: pointer;" onclick="window.location='/queue/<?= $job['id'] ?>'">
                        <td class="small"><?= \BBS\Core\TimeHelper::format($job['queued_at'], 'M j, g:i A') ?></td>
                        <td><?= htmlspecialchars($job['agent_name']) ?></td>
                        <td><?= $job['task_type'] ?></td>
                        <td class="d-table-cell-md"><?= number_format($job['files_total'] ?? 0) ?></td>
                        <td>
                            <?php if (($job['files_total'] ?? 0) > 0 && $job['status'] === 'running'): ?>
                                <?php $pct = round(($job['files_processed'] / $job['files_total']) * 100); ?>
                                <div class="progress" style="height: 18px; min-width: 60px;">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width: <?= $pct ?>%">
                                        <?= $pct ?>%
                                    </div>
                                </div>
                            <?php else: ?>
                                <?= number_format($job['files_processed'] ?? 0) ?>
                            <?php endif; ?>
                        </td>
                        <td class="d-table-cell-md"><?= htmlspecialchars($job['repo_name'] ?? '--') ?></td>
                        <td>
                            <?php
                            $sc = match($job['status']) {
                                'running' => 'info',
                                'sent' => 'primary',
                                default => 'warning',
                            };
                            ?>
                            <span class="badge bg-<?= $sc ?>"><?= $job['status'] ?></span>
                        </td>
                        <td class="text-nowrap" onclick="event.stopPropagation()">
                            <a href="/queue/<?= $job['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View Details"><i class="bi bi-eye"></i></a>
                            <?php if (in_array($job['status'], ['queued', 'sent'])): ?>
                            <form method="POST" action="/queue/<?= $job['id'] ?>/cancel" class="d-inline"
                                  onsubmit="return confirm('Cancel this job?')">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Cancel">
                                    <i class="bi bi-x-circle"></i>
                                </button>
                            </form>
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

<h5 class="mb-3">Recently Completed</h5>
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($completed)): ?>
        <div class="p-4 text-muted text-center">No completed jobs yet.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Task</th>
                        <th class="d-th-md">Files</th>
                        <th class="d-th-md">Repo</th>
                        <th class="d-th-md">Duration</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($completed as $job): ?>
                    <tr style="cursor: pointer;" onclick="window.location='/queue/<?= $job['id'] ?>'">
                        <td class="small"><?= \BBS\Core\TimeHelper::format($job['completed_at'], 'M j, g:i A') ?></td>
                        <td><?= htmlspecialchars($job['agent_name']) ?></td>
                        <td><?= $job['task_type'] ?></td>
                        <td class="d-table-cell-md"><?= number_format($job['files_total'] ?? 0) ?></td>
                        <td class="d-table-cell-md"><?= htmlspecialchars($job['repo_name'] ?? '--') ?></td>
                        <td class="d-table-cell-md">
                            <?php
                            $d = $job['duration_seconds'] ?? 0;
                            echo $d >= 60 ? floor($d / 60) . 'm ' . ($d % 60) . 's' : $d . 's';
                            ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= $job['status'] === 'completed' ? 'success' : 'danger' ?>">
                                <?= $job['status'] ?>
                            </span>
                            <?php if ($job['status'] === 'failed' && !empty($job['error_log'])): ?>
                                <i class="bi bi-info-circle text-danger ms-1" data-bs-toggle="tooltip" title="<?= htmlspecialchars(substr($job['error_log'], 0, 200)) ?>"></i>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap" onclick="event.stopPropagation()">
                            <a href="/queue/<?= $job['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View Details"><i class="bi bi-eye"></i></a>
                            <?php if ($job['status'] === 'failed'): ?>
                            <form method="POST" action="/queue/<?= $job['id'] ?>/retry" class="d-inline"
                                  onsubmit="return confirm('Retry this job?')">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <button class="btn btn-sm btn-outline-warning" title="Retry">
                                    <i class="bi bi-arrow-repeat"></i>
                                </button>
                            </form>
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

<script>
// Enable tooltips for error messages
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));

// AJAX auto-refresh
(function() {
    const csrfToken = '<?= $this->csrfToken() ?>';

    function esc(s) { return s ? String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') : ''; }

    function formatDate(d) {
        if (!d) return '';
        const dt = new Date(d.replace(' ', 'T'));
        return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) +
               ', ' + dt.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }

    function formatDuration(s) {
        s = parseInt(s) || 0;
        return s >= 60 ? Math.floor(s / 60) + 'm ' + (s % 60) + 's' : s + 's';
    }

    function statusBadge(status) {
        const colors = { running: 'info', sent: 'primary', queued: 'warning', completed: 'success', failed: 'danger' };
        return '<span class="badge bg-' + (colors[status] || 'secondary') + '">' + status + '</span>';
    }

    function buildInProgressRow(job) {
        let progress = String(Number(job.files_processed || 0).toLocaleString());
        if ((job.files_total || 0) > 0 && job.status === 'running') {
            const pct = Math.round((job.files_processed / job.files_total) * 100);
            progress = '<div class="progress" style="height:18px;min-width:60px;">' +
                '<div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width:' + pct + '%">' + pct + '%</div></div>';
        }

        let actions = '<a href="/queue/' + job.id + '" class="btn btn-sm btn-outline-secondary" title="View Details"><i class="bi bi-eye"></i></a>';
        if (job.status === 'queued' || job.status === 'sent') {
            actions += ' <form method="POST" action="/queue/' + job.id + '/cancel" class="d-inline" onsubmit="return confirm(\'Cancel this job?\')">' +
                '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                '<button class="btn btn-sm btn-outline-danger" title="Cancel"><i class="bi bi-x-circle"></i></button></form>';
        }

        return '<tr style="cursor:pointer;" onclick="window.location=\'/queue/' + job.id + '\'">' +
            '<td class="small">' + formatDate(job.queued_at) + '</td>' +
            '<td>' + esc(job.agent_name) + '</td>' +
            '<td>' + esc(job.task_type) + '</td>' +
            '<td class="d-table-cell-md">' + Number(job.files_total || 0).toLocaleString() + '</td>' +
            '<td>' + progress + '</td>' +
            '<td class="d-table-cell-md">' + esc(job.repo_name || '--') + '</td>' +
            '<td>' + statusBadge(job.status) + '</td>' +
            '<td class="text-nowrap" onclick="event.stopPropagation()">' + actions + '</td></tr>';
    }

    function buildCompletedRow(job) {
        let statusHtml = statusBadge(job.status);
        if (job.status === 'failed' && job.error_log) {
            statusHtml += ' <i class="bi bi-info-circle text-danger ms-1" data-bs-toggle="tooltip" title="' + esc(String(job.error_log).substring(0, 200)) + '"></i>';
        }

        let actions = '<a href="/queue/' + job.id + '" class="btn btn-sm btn-outline-secondary" title="View Details"><i class="bi bi-eye"></i></a>';
        if (job.status === 'failed') {
            actions += ' <form method="POST" action="/queue/' + job.id + '/retry" class="d-inline" onsubmit="return confirm(\'Retry this job?\')">' +
                '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                '<button class="btn btn-sm btn-outline-warning" title="Retry"><i class="bi bi-arrow-repeat"></i></button></form>';
        }

        return '<tr style="cursor:pointer;" onclick="window.location=\'/queue/' + job.id + '\'">' +
            '<td class="small">' + formatDate(job.completed_at) + '</td>' +
            '<td>' + esc(job.agent_name) + '</td>' +
            '<td>' + esc(job.task_type) + '</td>' +
            '<td class="d-table-cell-md">' + Number(job.files_total || 0).toLocaleString() + '</td>' +
            '<td class="d-table-cell-md">' + esc(job.repo_name || '--') + '</td>' +
            '<td class="d-table-cell-md">' + formatDuration(job.duration_seconds) + '</td>' +
            '<td>' + statusHtml + '</td>' +
            '<td class="text-nowrap" onclick="event.stopPropagation()">' + actions + '</td></tr>';
    }

    function refreshQueue() {
        fetch('/queue/json', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                // Update In Progress section
                const ipCard = document.querySelectorAll('.card-body')[0];
                if (data.inProgress.length === 0) {
                    ipCard.innerHTML = '<div class="p-4 text-muted text-center">No jobs in progress.</div>';
                } else {
                    let html = '<div class="table-responsive"><table class="table table-hover mb-0"><thead class="table-light"><tr>' +
                        '<th>Date</th><th>Client</th><th>Task</th><th class="d-th-md">Files</th><th>Progress</th><th class="d-th-md">Repo</th><th>Status</th><th></th>' +
                        '</tr></thead><tbody>';
                    data.inProgress.forEach(j => html += buildInProgressRow(j));
                    html += '</tbody></table></div>';
                    ipCard.innerHTML = html;
                }

                // Update Completed section
                const cCard = document.querySelectorAll('.card-body')[1];
                if (data.completed.length === 0) {
                    cCard.innerHTML = '<div class="p-4 text-muted text-center">No completed jobs yet.</div>';
                } else {
                    let html = '<div class="table-responsive"><table class="table table-hover mb-0"><thead class="table-light"><tr>' +
                        '<th>Date</th><th>Client</th><th>Task</th><th class="d-th-md">Files</th><th class="d-th-md">Repo</th><th class="d-th-md">Duration</th><th>Status</th><th></th>' +
                        '</tr></thead><tbody>';
                    data.completed.forEach(j => html += buildCompletedRow(j));
                    html += '</tbody></table></div>';
                    cCard.innerHTML = html;
                }

                // Re-init tooltips
                document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
            })
            .catch(() => {});
    }

    setInterval(refreshQueue, 10000);
})();
</script>
