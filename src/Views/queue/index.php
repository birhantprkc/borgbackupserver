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
                        <th>Files</th>
                        <th>Processed</th>
                        <th>Repo</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inProgress as $job): ?>
                    <tr>
                        <td class="small"><?= $job['queued_at'] ?></td>
                        <td><?= htmlspecialchars($job['agent_name']) ?></td>
                        <td><?= $job['task_type'] ?></td>
                        <td><?= number_format($job['files_total'] ?? 0) ?></td>
                        <td>
                            <?php if (($job['files_total'] ?? 0) > 0 && $job['status'] === 'running'): ?>
                                <?php $pct = round(($job['files_processed'] / $job['files_total']) * 100); ?>
                                <div class="progress" style="height: 18px; min-width: 80px;">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width: <?= $pct ?>%">
                                        <?= $pct ?>%
                                    </div>
                                </div>
                            <?php else: ?>
                                <?= number_format($job['files_processed'] ?? 0) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($job['repo_name'] ?? '--') ?></td>
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
                        <td>
                            <?php if (in_array($job['status'], ['queued', 'sent'])): ?>
                            <form method="POST" action="/queue/<?= $job['id'] ?>/cancel" class="d-inline"
                                  onsubmit="return confirm('Cancel this job?')">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
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
                        <th>Files</th>
                        <th>Repo</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($completed as $job): ?>
                    <tr>
                        <td class="small"><?= $job['completed_at'] ?></td>
                        <td><?= htmlspecialchars($job['agent_name']) ?></td>
                        <td><?= $job['task_type'] ?></td>
                        <td><?= number_format($job['files_total'] ?? 0) ?></td>
                        <td><?= htmlspecialchars($job['repo_name'] ?? '--') ?></td>
                        <td>
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
                        <td>
                            <?php if ($job['status'] === 'failed'): ?>
                            <form method="POST" action="/queue/<?= $job['id'] ?>/retry" class="d-inline"
                                  onsubmit="return confirm('Retry this job?')">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
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
</script>
