<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0">Server Log</h5>
    <div>
        <a href="/log" class="btn btn-sm <?= empty($currentLevel) ? 'btn-primary' : 'btn-outline-secondary' ?>">All</a>
        <a href="/log?level=info" class="btn btn-sm <?= $currentLevel === 'info' ? 'btn-info' : 'btn-outline-secondary' ?>">Info</a>
        <a href="/log?level=warning" class="btn btn-sm <?= $currentLevel === 'warning' ? 'btn-warning' : 'btn-outline-secondary' ?>">Warning</a>
        <a href="/log?level=error" class="btn btn-sm <?= $currentLevel === 'error' ? 'btn-danger' : 'btn-outline-secondary' ?>">Error</a>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
        <div class="p-4 text-muted text-center">No log entries.</div>
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
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="text-nowrap"><?= $log['created_at'] ?></td>
                        <td><?= htmlspecialchars($log['agent_name'] ?? '--') ?></td>
                        <td>
                            <?php
                            $lc = match($log['level']) {
                                'error' => 'danger',
                                'warning' => 'warning',
                                default => 'info',
                            };
                            ?>
                            <span class="badge bg-<?= $lc ?>"><?= $log['level'] ?></span>
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
