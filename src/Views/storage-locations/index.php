<?php
use BBS\Services\ServerStats;

function formatStorageBytes(int $bytes): string {
    return ServerStats::formatBytes($bytes);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0"><i class="bi bi-hdd-stack me-2"></i>Storage Locations</h5>
    <button class="btn btn-sm btn-success" data-bs-toggle="collapse" data-bs-target="#addLocationForm">
        <i class="bi bi-plus-circle me-1"></i> Add Location
    </button>
</div>

<!-- Add Location Form -->
<div class="collapse mb-4" id="addLocationForm">
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="/storage-locations">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Label</label>
                        <input type="text" class="form-control" name="label" placeholder="e.g. Secondary Disk" required>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold">Path</label>
                        <input type="text" class="form-control" name="path" placeholder="/mnt/storage2" required>
                        <div class="form-text">Absolute path to the storage directory. Must exist and be writable.</div>
                    </div>
                    <div class="col-md-2 d-flex align-items-center pt-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_default" id="newIsDefault">
                            <label class="form-check-label" for="newIsDefault">Default</label>
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-sm btn-success w-100">Create</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Storage Locations -->
<?php if (empty($locations)): ?>
<div class="alert alert-info">No storage locations configured. Add one to get started.</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($locations as $loc): ?>
    <div class="col-xl-4 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h6 class="mb-1">
                            <?= htmlspecialchars($loc['label']) ?>
                            <?php if ($loc['is_default']): ?>
                            <span class="badge bg-primary ms-1">Default</span>
                            <?php endif; ?>
                        </h6>
                        <code class="small text-muted"><?= htmlspecialchars($loc['path']) ?></code>
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown">
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="#" data-bs-toggle="collapse"
                                   data-bs-target="#editLoc<?= $loc['id'] ?>"
                                   onclick="event.preventDefault();">
                                    <i class="bi bi-pencil me-1"></i> Edit
                                </a>
                            </li>
                            <?php if (!$loc['is_default'] && $loc['repo_count'] === 0): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="/storage-locations/<?= $loc['id'] ?>/delete"
                                      onsubmit="return confirm('Delete storage location \'<?= htmlspecialchars($loc['label'], ENT_QUOTES) ?>\'?')">
                                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                    <button type="submit" class="dropdown-item text-danger">
                                        <i class="bi bi-trash me-1"></i> Delete
                                    </button>
                                </form>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <!-- Disk Usage -->
                <?php if ($loc['disk_total'] > 0): ?>
                <div class="mb-2">
                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span><?= formatStorageBytes($loc['disk_used']) ?> used</span>
                        <span><?= formatStorageBytes($loc['disk_free']) ?> free</span>
                    </div>
                    <div class="progress" style="height: 6px;">
                        <?php
                        $pct = $loc['disk_percent'];
                        $barColor = $pct >= 90 ? 'danger' : ($pct >= 75 ? 'warning' : 'success');
                        ?>
                        <div class="progress-bar bg-<?= $barColor ?>" style="width: <?= $pct ?>%"></div>
                    </div>
                    <div class="text-muted small mt-1">
                        <?= formatStorageBytes($loc['disk_total']) ?> total &middot; <?= $pct ?>% used
                    </div>
                </div>
                <?php else: ?>
                <div class="text-muted small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Path not accessible</div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="d-flex gap-3 small text-muted">
                    <span><i class="bi bi-archive me-1"></i><?= $loc['repo_count'] ?> repo<?= $loc['repo_count'] !== 1 ? 's' : '' ?></span>
                    <span><i class="bi bi-database me-1"></i><?= formatStorageBytes($loc['total_size']) ?></span>
                </div>
            </div>

            <!-- Inline Edit Form (collapsed) -->
            <div class="collapse" id="editLoc<?= $loc['id'] ?>">
                <div class="card-footer bg-light">
                    <form method="POST" action="/storage-locations/<?= $loc['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <input type="text" class="form-control form-control-sm" name="label"
                                       value="<?= htmlspecialchars($loc['label']) ?>" required>
                            </div>
                            <div class="col-md-4">
                                <input type="text" class="form-control form-control-sm" name="path"
                                       value="<?= htmlspecialchars($loc['path']) ?>" required
                                       <?= $loc['repo_count'] > 0 ? 'readonly title="Cannot change path while repos exist"' : '' ?>>
                            </div>
                            <div class="col-md-2 d-flex align-items-center">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_default"
                                           id="editDefault<?= $loc['id'] ?>"
                                           <?= $loc['is_default'] ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="editDefault<?= $loc['id'] ?>">Default</label>
                                </div>
                            </div>
                            <div class="col-md-2 d-flex align-items-center">
                                <button type="submit" class="btn btn-sm btn-primary w-100">Save</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Remote Storage (SSH) -->
<div class="d-flex justify-content-between align-items-center mb-3 mt-5">
    <h5 class="mb-0"><i class="bi bi-hdd-network me-2"></i>Remote Storage (SSH)</h5>
    <a href="/settings?tab=storage&section=wizard" class="btn btn-sm btn-success">
        <i class="bi bi-plus-circle me-1"></i> Add SSH Host
    </a>
</div>

<?php if (empty($remoteSshConfigs)): ?>
<div class="alert alert-info">No remote SSH hosts configured. <a href="/settings?tab=storage&section=wizard">Add one</a> to get started.</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($remoteSshConfigs as $rsc): ?>
    <div class="col-xl-4 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-start gap-2 mb-2">
                    <div class="flex-shrink-0 mt-1" style="font-size: 1.4rem;">
                        <?php if (($rsc['provider'] ?? '') === 'borgbase'): ?>
                        <img src="/images/borgbase.svg" alt="" style="width:24px;height:24px;border-radius:50%">
                        <?php elseif (($rsc['provider'] ?? '') === 'hetzner'): ?>
                        <img src="/images/hetzner-h.png" alt="" style="width:24px;height:24px;border-radius:50%">
                        <?php elseif (($rsc['provider'] ?? '') === 'rsyncnet'): ?>
                        <img src="/images/rsyncnet-logo.png" alt="" style="width:24px;height:24px;border-radius:50%">
                        <?php else: ?>
                        <i class="bi bi-server text-primary opacity-75"></i>
                        <?php endif; ?>
                    </div>
                    <div class="flex-grow-1" style="min-width: 0;">
                        <h6 class="mb-0"><?= htmlspecialchars($rsc['name']) ?></h6>
                        <code class="small text-muted text-truncate d-block" style="max-width: 100%;"><?= htmlspecialchars($rsc['remote_user']) ?>@<?= htmlspecialchars($rsc['remote_host']) ?><?= (int)$rsc['remote_port'] !== 22 ? ':' . (int)$rsc['remote_port'] : '' ?></code>
                    </div>
                    <a href="/settings?tab=storage&section=remote" class="btn btn-sm btn-outline-secondary" title="Manage">
                        <i class="bi bi-gear"></i>
                    </a>
                </div>
                <div class="d-flex gap-3 small text-muted">
                    <span><i class="bi bi-archive me-1"></i><?= $rsc['repo_count'] ?> repo<?= $rsc['repo_count'] !== 1 ? 's' : '' ?></span>
                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Active</span>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- S3 Offsite Sync -->
<?php
$s3Configured = !empty($settings['s3_endpoint']) && !empty($settings['s3_bucket']);
$s3SyncServerBackups = ($settings['s3_sync_server_backups'] ?? '0') === '1';
?>
<div class="d-flex justify-content-between align-items-center mb-3 mt-5">
    <h5 class="mb-0"><i class="bi bi-bucket me-2"></i>S3 Offsite Sync</h5>
    <a href="/settings?tab=storage&section=s3" class="btn btn-sm <?= $s3Configured ? 'btn-outline-primary' : 'btn-success' ?>">
        <i class="bi bi-gear me-1"></i> <?= $s3Configured ? 'Manage' : 'Configure' ?>
    </a>
</div>

<?php if (!$s3Configured): ?>
<div class="alert alert-info">S3 offsite sync is not configured. <a href="/settings?tab=storage&section=s3">Configure it</a> to replicate local repos to S3-compatible storage.</div>
<?php else: ?>
<div class="row g-3">
    <div class="col-xl-4 col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="mb-2"><i class="bi bi-cloud-arrow-up me-1 text-primary"></i> Global S3</h6>
                <div class="row g-1 small mb-2">
                    <div class="col-5 text-muted">Endpoint</div>
                    <div class="col-7 text-truncate"><?= htmlspecialchars($settings['s3_endpoint'] ?? '') ?></div>
                    <div class="col-5 text-muted">Bucket</div>
                    <div class="col-7"><?= htmlspecialchars($settings['s3_bucket'] ?? '') ?></div>
                    <?php if (!empty($settings['s3_region'])): ?>
                    <div class="col-5 text-muted">Region</div>
                    <div class="col-7"><?= htmlspecialchars($settings['s3_region']) ?></div>
                    <?php endif; ?>
                    <div class="col-5 text-muted">Server Sync</div>
                    <div class="col-7">
                        <?php if ($s3SyncServerBackups): ?>
                        <span class="badge bg-success">Enabled</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">Disabled</span>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Configured</span>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
