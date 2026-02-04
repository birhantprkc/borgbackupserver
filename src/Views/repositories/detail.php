<?php
$sizeLabel = $totalSize >= 1073741824 ? round($totalSize / 1073741824, 1) . ' GB'
    : ($totalSize >= 1048576 ? round($totalSize / 1048576, 1) . ' MB'
    : ($totalSize >= 1024 ? round($totalSize / 1024, 1) . ' KB'
    : ($totalSize > 0 ? $totalSize . ' B' : '0')));
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="/clients" class="text-decoration-none">Clients</a></li>
        <li class="breadcrumb-item"><a href="/clients/<?= $agentId ?>" class="text-decoration-none"><?= htmlspecialchars($repo['agent_name']) ?></a></li>
        <li class="breadcrumb-item"><a href="/clients/<?= $agentId ?>?tab=repos" class="text-decoration-none">Repos</a></li>
        <li class="breadcrumb-item active"><?= htmlspecialchars($repo['name']) ?></li>
    </ol>
</nav>

<!-- Header -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h4 class="mb-1">
                    <i class="bi bi-archive text-primary me-2"></i><?= htmlspecialchars($repo['name']) ?>
                </h4>
                <div class="text-muted small">
                    <i class="bi bi-folder me-1"></i><?= htmlspecialchars($localPath) ?>
                </div>
            </div>
            <?php if ($activeJob): ?>
            <span class="badge bg-info"><i class="bi bi-hourglass-split me-1"></i>Active: <?= $activeJob['task_type'] ?></span>
            <?php endif; ?>
        </div>

        <!-- Stats Row -->
        <div class="row g-3 mt-3">
            <div class="col-6 col-md-3">
                <div class="d-flex align-items-center p-2 rounded bg-light">
                    <div class="stat-icon-sm bg-primary bg-opacity-10 text-primary rounded-2 p-2 me-2">
                        <i class="bi bi-hdd"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?= $sizeLabel ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;">Size</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="d-flex align-items-center p-2 rounded bg-light">
                    <div class="stat-icon-sm bg-info bg-opacity-10 text-info rounded-2 p-2 me-2">
                        <i class="bi bi-stack"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?= $archiveCount ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;">Archives</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="d-flex align-items-center p-2 rounded bg-light">
                    <div class="stat-icon-sm bg-success bg-opacity-10 text-success rounded-2 p-2 me-2">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?= $oldestArchive ? \BBS\Core\TimeHelper::format($oldestArchive, 'M j, Y') : '--' ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;">Oldest</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="d-flex align-items-center p-2 rounded bg-light">
                    <div class="stat-icon-sm bg-warning bg-opacity-10 text-warning rounded-2 p-2 me-2">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?= $newestArchive ? \BBS\Core\TimeHelper::ago($newestArchive) : '--' ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;">Latest</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Maintenance Actions -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-tools me-1"></i> Maintenance
            </div>
            <div class="card-body">
                <div class="d-flex flex-column gap-3">
                    <!-- Check -->
                    <div class="d-flex align-items-start gap-3 p-3 bg-light rounded">
                        <div class="text-primary" style="font-size: 1.5rem;">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">Check Repository</h6>
                            <p class="text-muted small mb-2">Verify repository integrity by checking all archives for corruption or data errors. Safe to run anytime.</p>
                            <form method="POST" action="/repositories/<?= $repo['id'] ?>/maintenance" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <input type="hidden" name="action" value="check">
                                <button type="submit" class="btn btn-sm btn-outline-primary" <?= $activeJob ? 'disabled' : '' ?>>
                                    <i class="bi bi-play-fill me-1"></i>Run Check
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Compact -->
                    <div class="d-flex align-items-start gap-3 p-3 bg-light rounded">
                        <div class="text-success" style="font-size: 1.5rem;">
                            <i class="bi bi-arrows-collapse"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">Compact Repository</h6>
                            <p class="text-muted small mb-2">Reclaim disk space by removing unused data chunks after pruning archives. Runs automatically every Saturday at 2 AM.</p>
                            <form method="POST" action="/repositories/<?= $repo['id'] ?>/maintenance" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <input type="hidden" name="action" value="compact">
                                <button type="submit" class="btn btn-sm btn-outline-success" <?= $activeJob ? 'disabled' : '' ?>>
                                    <i class="bi bi-play-fill me-1"></i>Run Compact
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Repair -->
                    <div class="d-flex align-items-start gap-3 p-3 bg-light rounded">
                        <div class="text-warning" style="font-size: 1.5rem;">
                            <i class="bi bi-bandaid"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">Repair Repository</h6>
                            <p class="text-muted small mb-2">Attempt to fix repository errors. <strong class="text-danger">Warning:</strong> May delete damaged data to restore consistency. Only use if Check reports errors.</p>
                            <form method="POST" action="/repositories/<?= $repo['id'] ?>/maintenance" class="d-inline" data-confirm="Run REPAIR on this repository?&#10;&#10;This may delete damaged data to restore consistency. Only proceed if Check reported errors." data-confirm-danger>
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <input type="hidden" name="action" value="repair">
                                <button type="submit" class="btn btn-sm btn-outline-warning" <?= $activeJob ? 'disabled' : '' ?>>
                                    <i class="bi bi-play-fill me-1"></i>Run Repair
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Break Lock -->
                    <div class="d-flex align-items-start gap-3 p-3 bg-light rounded">
                        <div class="text-danger" style="font-size: 1.5rem;">
                            <i class="bi bi-unlock"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">Break Lock</h6>
                            <p class="text-muted small mb-2">Forcibly remove stale locks from interrupted operations. <strong class="text-danger">Warning:</strong> Only use if you're certain no backup operations are currently running.</p>
                            <form method="POST" action="/repositories/<?= $repo['id'] ?>/maintenance" class="d-inline" data-confirm="BREAK LOCK on this repository?&#10;&#10;Only proceed if you're CERTAIN no backup operations are running." data-confirm-danger>
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <input type="hidden" name="action" value="break_lock">
                                <button type="submit" class="btn btn-sm btn-outline-danger" <?= $activeJob ? 'disabled' : '' ?>>
                                    <i class="bi bi-play-fill me-1"></i>Break Lock
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- S3 Offsite & Info -->
    <div class="col-lg-6">
        <?php if ($s3SyncInfo): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-cloud text-info me-1"></i> S3 Offsite Mirror</span>
                <form method="POST" action="/clients/<?= $agentId ?>/repo/<?= $repo['id'] ?>/s3-config/delete" class="d-inline" data-confirm="Disable S3 sync?&#10;&#10;The repository will no longer sync to S3 after backups. Data already in S3 will remain.">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-cloud-slash me-1"></i>Disable
                    </button>
                </form>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-start gap-3 p-3 bg-light rounded mb-3">
                    <div class="text-info" style="font-size: 1.5rem;">
                        <i class="bi bi-cloud-check"></i>
                    </div>
                    <div>
                        <h6 class="mb-1">Replicated to S3</h6>
                        <p class="text-muted small mb-0">
                            <?php if ($s3SyncInfo['config_name']): ?>
                            Config: <strong><?= htmlspecialchars($s3SyncInfo['config_name']) ?></strong><br>
                            <?php endif; ?>
                            Last sync: <strong><?= $s3SyncInfo['last_s3_sync'] ? \BBS\Core\TimeHelper::ago($s3SyncInfo['last_s3_sync']) : 'Never' ?></strong>
                        </p>
                    </div>
                </div>

                <div class="d-flex align-items-start gap-3 p-3 bg-light rounded">
                    <div class="text-primary" style="font-size: 1.5rem;">
                        <i class="bi bi-cloud-download"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="mb-1">Restore from S3</h6>
                        <p class="text-muted small mb-2">Download repository data from S3 back to the server. Use this to recover from local data loss or sync issues.</p>
                        <div class="d-flex gap-2 flex-wrap align-items-end">
                            <form method="POST" action="/clients/<?= $agentId ?>/repo/<?= $repo['id'] ?>/s3-restore" class="d-inline" data-confirm="Restore (replace) from S3?&#10;&#10;This will download the repository data from S3 and OVERWRITE local files.">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <input type="hidden" name="mode" value="replace">
                                <button type="submit" class="btn btn-sm btn-outline-primary" <?= $activeJob ? 'disabled' : '' ?>>
                                    <i class="bi bi-arrow-repeat me-1"></i>Restore (replace)
                                </button>
                            </form>
                            <form method="POST" action="/clients/<?= $agentId ?>/repo/<?= $repo['id'] ?>/s3-restore" class="d-inline" data-confirm="Restore (copy) from S3?&#10;&#10;This will create a NEW repository and download data from S3.">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <input type="hidden" name="mode" value="copy">
                                <div class="input-group input-group-sm" style="width: auto;">
                                    <input type="text" name="copy_name" class="form-control form-control-sm" placeholder="New repo name" value="<?= htmlspecialchars($repo['name']) ?>-copy" style="width: 140px;" required <?= $activeJob ? 'disabled' : '' ?>>
                                    <button type="submit" class="btn btn-outline-secondary" <?= $activeJob ? 'disabled' : '' ?>>
                                        <i class="bi bi-files me-1"></i>Restore (copy)
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif (!empty($s3PluginConfigs)): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-cloud text-muted me-1"></i> S3 Offsite Mirror
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">Enable S3 sync to automatically replicate this repository to S3 storage after each backup prune.</p>
                <form method="POST" action="/clients/<?= $agentId ?>/repo/<?= $repo['id'] ?>/s3-config">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <div class="row g-2 align-items-end">
                        <div class="col-auto">
                            <label class="form-label small">S3 Configuration</label>
                            <select name="plugin_config_id" class="form-select form-select-sm" required>
                                <?php foreach ($s3PluginConfigs as $cfg): ?>
                                <option value="<?= $cfg['id'] ?>"><?= htmlspecialchars($cfg['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-info">
                                <i class="bi bi-cloud-plus me-1"></i>Enable S3 Sync
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Repository Info -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-info-circle me-1"></i> Repository Info
            </div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted" style="width: 40%;">Encryption</td>
                        <td><code><?= htmlspecialchars($repo['encryption']) ?></code></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Borg Version (created)</td>
                        <td><?= $repo['borg_version_created'] ? htmlspecialchars($repo['borg_version_created']) : '<span class="text-muted">--</span>' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Borg Version (last used)</td>
                        <td><?= $repo['borg_version_last'] ? htmlspecialchars($repo['borg_version_last']) : '<span class="text-muted">--</span>' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Created</td>
                        <td><?= \BBS\Core\TimeHelper::format($repo['created_at'], 'M j, Y g:i A') ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Backup Plans</td>
                        <td><?= count($plans) ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Recent Jobs -->
<?php if (!empty($recentJobs)): ?>
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-clock-history me-1"></i> Recent Jobs
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm small mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Time</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Duration</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($recentJobs, 0, 10) as $job): ?>
                    <tr style="cursor: pointer;" onclick="window.location='/queue/<?= $job['id'] ?>'">
                        <td class="small" style="white-space: nowrap;">
                            <?= $job['completed_at'] ? \BBS\Core\TimeHelper::ago($job['completed_at']) : \BBS\Core\TimeHelper::ago($job['queued_at']) ?>
                        </td>
                        <td><?= $job['task_type'] ?></td>
                        <td>
                            <?php
                            $statusColor = match($job['status']) {
                                'completed' => 'success',
                                'failed' => 'danger',
                                'running' => 'info',
                                'sent' => 'primary',
                                'queued' => 'warning',
                                default => 'secondary',
                            };
                            ?>
                            <span class="badge bg-<?= $statusColor ?>"><?= $job['status'] ?></span>
                        </td>
                        <td>
                            <?php
                            $d = $job['duration_seconds'] ?? 0;
                            echo $d > 0 ? ($d >= 60 ? floor($d / 60) . 'm ' . ($d % 60) . 's' : $d . 's') : '--';
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Delete Repository -->
<?php if ($this->isAdmin()): ?>
<div class="card border-0 shadow-sm mt-4 border-danger">
    <div class="card-header bg-white fw-semibold text-danger">
        <i class="bi bi-exclamation-triangle me-1"></i> Danger Zone
    </div>
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h6 class="mb-1">Delete Repository</h6>
                <p class="text-muted small mb-0">Permanently delete this repository and all its data. This cannot be undone.</p>
            </div>
            <?php
            $deleteBlocked = count($plans) > 0 || $activeJob;
            $blockReason = count($plans) > 0
                ? "Delete the " . count($plans) . " backup plan(s) using this repo first"
                : "Wait for active jobs to finish first";
            ?>
            <?php if ($deleteBlocked): ?>
            <button class="btn btn-outline-danger" disabled title="<?= htmlspecialchars($blockReason) ?>">
                <i class="bi bi-trash me-1"></i>Delete
            </button>
            <?php else: ?>
            <form method="POST" action="/repositories/<?= $repo['id'] ?>/delete" id="deleteRepoForm">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                <?php if ($s3SyncInfo): ?>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="delete_from_s3" id="deleteFromS3" value="1">
                    <label class="form-check-label small" for="deleteFromS3">
                        <i class="bi bi-cloud text-info me-1"></i>Also delete from S3 offsite storage
                    </label>
                    <input type="hidden" name="plugin_config_id" value="<?= $s3SyncInfo['plugin_config_id'] ?>">
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-outline-danger">
                    <i class="bi bi-trash me-1"></i>Delete Repository
                </button>
            </form>
            <script>
            document.getElementById('deleteRepoForm').addEventListener('submit', function(e) {
                var deleteS3 = document.getElementById('deleteFromS3');
                var msg = 'PERMANENTLY delete repository "<?= htmlspecialchars($repo['name'], ENT_QUOTES) ?>", all its archives, and the data on disk?';
                if (deleteS3 && deleteS3.checked) {
                    msg += '\n\nThis will ALSO delete the offsite copy from S3!';
                }
                msg += '\n\nThis action is NOT reversible.';
                if (!confirm(msg)) {
                    e.preventDefault();
                }
            });
            </script>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
