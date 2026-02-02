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
                        <th>Agents</th>
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
                        <td><?= $user['agent_count'] ?></td>
                        <td class="small"><?= \BBS\Core\TimeHelper::format($user['created_at'], 'M j, Y') ?></td>
                        <td>
                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                            <form method="POST" action="/users/<?= $user['id'] ?>/delete" class="d-inline" data-confirm="Delete this user?" data-confirm-danger>
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
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
