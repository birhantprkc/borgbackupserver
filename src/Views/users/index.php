<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0">User Management</h5>
    <button class="btn btn-sm btn-success" data-bs-toggle="collapse" data-bs-target="#addUserForm">
        <i class="bi bi-plus-circle me-1"></i> Add User
    </button>
</div>

<!-- Add User Form -->
<div class="collapse mb-4" id="addUserForm">
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="/users/add">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Username</label>
                        <input type="text" class="form-control" name="username" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Email</label>
                        <input type="email" class="form-control" name="email" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Password</label>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Role</label>
                        <select class="form-select" name="role">
                            <option value="user">User</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-sm btn-success w-100">Create</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Users List -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php foreach ($users as $user): ?>
        <div class="d-flex align-items-center justify-content-between p-3 border-bottom">
            <div class="flex-grow-1 min-width-0">
                <!-- Primary line: username, role badge, 2FA icon -->
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <strong><?= htmlspecialchars($user['username']) ?></strong>
                    <span class="badge bg-<?= $user['role'] === 'admin' ? 'primary' : 'secondary' ?>">
                        <?= ucfirst($user['role']) ?>
                    </span>
                    <?php if ($user['totp_enabled']): ?>
                    <span class="badge bg-success" title="2FA Enabled"><i class="bi bi-shield-check"></i></span>
                    <?php endif; ?>
                </div>
                <!-- Secondary line: email, access info -->
                <div class="text-muted small mt-1">
                    <?= htmlspecialchars($user['email']) ?>
                    <span class="mx-1">·</span>
                    <?php if ($user['role'] === 'admin'): ?>
                    <span>All access</span>
                    <?php elseif ($user['all_clients']): ?>
                    <span class="text-info">All clients</span>
                    <?php elseif ($user['agent_count'] > 0): ?>
                    <span><?= $user['agent_count'] ?> client<?= $user['agent_count'] != 1 ? 's' : '' ?></span>
                    <?php else: ?>
                    <span class="text-warning">No access</span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Actions -->
            <div class="d-flex gap-1 flex-shrink-0 ms-2">
                <a href="/users/<?= $user['id'] ?>/edit" class="btn btn-sm btn-outline-primary" title="Edit">
                    <i class="bi bi-pencil"></i>
                </a>
                <?php if ($user['totp_enabled']): ?>
                <form method="POST" action="/users/<?= $user['id'] ?>/reset-2fa" class="d-inline"
                      data-confirm="Reset 2FA for <?= htmlspecialchars($user['username']) ?>? They will need to set up 2FA again." data-confirm-danger>
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Reset 2FA">
                        <i class="bi bi-shield-x"></i>
                    </button>
                </form>
                <?php endif; ?>
                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                <form method="POST" action="/users/<?= $user['id'] ?>/delete" class="d-inline" data-confirm="Delete this user?" data-confirm-danger>
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                        <i class="bi bi-trash"></i>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
        <div class="p-4 text-center text-muted">
            No users found.
        </div>
        <?php endif; ?>
    </div>
</div>
