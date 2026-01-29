<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Notifications</h4>
    <form method="POST" action="/notifications/read-all">
        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
        <button type="submit" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-check2-all me-1"></i> Mark All as Read
        </button>
    </form>
</div>

<?php if (empty($notifications)): ?>
    <div class="text-muted text-center py-5">No notifications.</div>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead class="table-light">
            <tr>
                <th style="width:40px"></th>
                <th>Message</th>
                <th>Client</th>
                <th>Severity</th>
                <th>Last Occurred</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($notifications as $n):
                $resolved = $n['resolved_at'] !== null;
                $unread = $n['read_at'] === null;
                $rowClass = $resolved ? 'text-muted text-decoration-line-through' : ($unread ? 'fw-semibold' : '');

                $iconMap = [
                    'backup_failed' => 'bi-x-circle-fill text-danger',
                    'agent_offline' => 'bi-wifi-off text-warning',
                    'storage_low' => 'bi-hdd text-warning',
                    'missed_schedule' => 'bi-clock-history text-warning',
                ];
                $icon = $iconMap[$n['type']] ?? 'bi-bell text-secondary';
            ?>
            <tr class="<?= $rowClass ?>">
                <td><i class="bi <?= $icon ?> fs-5"></i></td>
                <td>
                    <?= htmlspecialchars($n['message']) ?>
                    <?php if ($n['occurrence_count'] > 1): ?>
                        <span class="badge bg-secondary ms-1"><?= (int)$n['occurrence_count'] ?> occurrences</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($n['agent_name'] ?? '—') ?></td>
                <td>
                    <?php if ($n['severity'] === 'critical'): ?>
                        <span class="badge bg-danger">Critical</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark">Warning</span>
                    <?php endif; ?>
                </td>
                <td class="small"><?= htmlspecialchars($n['last_occurred_at']) ?></td>
                <td>
                    <?php if ($resolved): ?>
                        <span class="badge bg-success">Resolved</span>
                    <?php elseif (!$unread): ?>
                        <span class="badge bg-secondary">Read</span>
                    <?php else: ?>
                        <span class="badge bg-info text-dark">New</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($unread && !$resolved): ?>
                    <form method="POST" action="/notifications/<?= (int)$n['id'] ?>/read" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Mark as read">
                            <i class="bi bi-check2"></i>
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
