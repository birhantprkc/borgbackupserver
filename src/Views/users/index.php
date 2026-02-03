<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0">User Management</h5>
    <button class="btn btn-success" data-bs-toggle="collapse" data-bs-target="#addUserForm">
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
                        <button type="submit" class="btn btn-success w-100">Create</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Users Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Access</th>
                        <th>2FA</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <td>
                            <span class="badge bg-<?= $user['role'] === 'admin' ? 'primary' : 'secondary' ?>">
                                <?= ucfirst($user['role']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($user['role'] === 'admin'): ?>
                            <span class="text-muted small">All (admin)</span>
                            <?php elseif ($user['all_clients']): ?>
                            <span class="badge bg-info">All Clients</span>
                            <?php elseif ($user['agent_count'] > 0): ?>
                            <span class="badge bg-light text-dark"><?= $user['agent_count'] ?> client<?= $user['agent_count'] != 1 ? 's' : '' ?></span>
                            <?php else: ?>
                            <span class="text-muted small">None</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($user['totp_enabled']): ?>
                            <span class="badge bg-success"><i class="bi bi-shield-check"></i></span>
                            <?php else: ?>
                            <span class="badge bg-secondary"><i class="bi bi-shield"></i></span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= \BBS\Core\TimeHelper::format($user['created_at'], 'M j, Y') ?></td>
                        <td>
                            <a href="/users/<?= $user['id'] ?>/edit" class="btn btn-sm btn-outline-primary me-1" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php if ($user['totp_enabled']): ?>
                            <form method="POST" action="/users/<?= $user['id'] ?>/reset-2fa" class="d-inline"
                                  data-confirm="Reset 2FA for <?= htmlspecialchars($user['username']) ?>? They will need to set up 2FA again." data-confirm-danger>
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <button type="submit" class="btn btn-sm btn-outline-warning me-1" title="Reset 2FA">
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
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
