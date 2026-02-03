<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0">Edit User: <?= htmlspecialchars($user['username']) ?></h5>
    <a href="/users" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Back to Users
    </a>
</div>

<form method="POST" action="/users/<?= $user['id'] ?>/edit">
    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">

    <!-- Basic Info -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0">
            <h6 class="mb-0"><i class="bi bi-person me-2"></i>Account Information</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Username</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" disabled>
                    <div class="form-text">Username cannot be changed</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Email</label>
                    <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Role</label>
                    <select class="form-select" name="role" id="roleSelect">
                        <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User</option>
                        <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">New Password</label>
                    <input type="password" class="form-control" name="password" placeholder="Leave blank to keep current">
                    <div class="form-text">Only fill this if you want to change the password</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Two-Factor Authentication</label>
                    <?php if ($user['totp_enabled']): ?>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-success"><i class="bi bi-shield-check me-1"></i>Enabled</span>
                        <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#reset2faModal">
                            <i class="bi bi-shield-x me-1"></i>Reset 2FA
                        </button>
                    </div>
                    <?php else: ?>
                    <div><span class="badge bg-secondary">Disabled</span></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Client Access (hidden for admins) -->
    <div class="card border-0 shadow-sm mb-4" id="clientAccessCard" style="<?= $user['role'] === 'admin' ? 'display:none' : '' ?>">
        <div class="card-header bg-white border-0">
            <h6 class="mb-0"><i class="bi bi-pc-display me-2"></i>Client Access</h6>
        </div>
        <div class="card-body">
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="all_clients" id="allClientsCheck" value="1" <?= $user['all_clients'] ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="allClientsCheck">
                    Access All Clients
                </label>
                <div class="form-text">User will have access to all current and future clients</div>
            </div>

            <div id="specificClientsDiv" style="<?= $user['all_clients'] ? 'display:none' : '' ?>">
                <label class="form-label fw-semibold">Assigned Clients</label>
                <?php if (empty($allAgents)): ?>
                <p class="text-muted">No clients available</p>
                <?php else: ?>
                <div class="row">
                    <?php foreach ($allAgents as $agent): ?>
                    <div class="col-md-4 col-lg-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="agents[]" value="<?= $agent['id'] ?>" id="agent_<?= $agent['id'] ?>"
                                <?= in_array($agent['id'], $userAgentIds) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="agent_<?= $agent['id'] ?>">
                                <?= htmlspecialchars($agent['name']) ?>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Permissions (hidden for admins) -->
    <div class="card border-0 shadow-sm mb-4" id="permissionsCard" style="<?= $user['role'] === 'admin' ? 'display:none' : '' ?>">
        <div class="card-header bg-white border-0">
            <h6 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Permissions</h6>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">Select which actions this user can perform. Permissions can apply to all assigned clients or specific ones.</p>

            <?php foreach ($allPermissions as $perm): ?>
            <?php $data = $permissionData[$perm]; ?>
            <div class="border rounded p-3 mb-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="form-check">
                        <input class="form-check-input perm-check" type="checkbox" name="perm_<?= $perm ?>" id="perm_<?= $perm ?>" value="1"
                            <?= $data['enabled'] ? 'checked' : '' ?> data-perm="<?= $perm ?>">
                        <label class="form-check-label fw-semibold" for="perm_<?= $perm ?>">
                            <?= htmlspecialchars($permissionLabels[$perm]) ?>
                        </label>
                    </div>
                </div>

                <div class="perm-scope-div ms-4" id="scope_<?= $perm ?>" style="<?= $data['enabled'] ? '' : 'display:none' ?>">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input scope-radio" type="radio" name="perm_scope_<?= $perm ?>" id="scope_global_<?= $perm ?>" value="global"
                            <?= $data['global'] || empty($data['agent_ids']) ? 'checked' : '' ?> data-perm="<?= $perm ?>">
                        <label class="form-check-label" for="scope_global_<?= $perm ?>">All assigned clients</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input scope-radio" type="radio" name="perm_scope_<?= $perm ?>" id="scope_specific_<?= $perm ?>" value="specific"
                            <?= !$data['global'] && !empty($data['agent_ids']) ? 'checked' : '' ?> data-perm="<?= $perm ?>">
                        <label class="form-check-label" for="scope_specific_<?= $perm ?>">Specific clients</label>
                    </div>

                    <div class="perm-agents-div mt-2" id="agents_<?= $perm ?>" style="<?= (!$data['global'] && !empty($data['agent_ids'])) ? '' : 'display:none' ?>">
                        <div class="row">
                            <?php foreach ($allAgents as $agent): ?>
                            <div class="col-md-4 col-lg-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="perm_agents_<?= $perm ?>[]" value="<?= $agent['id'] ?>"
                                        id="perm_agent_<?= $perm ?>_<?= $agent['id'] ?>"
                                        <?= in_array($agent['id'], $data['agent_ids']) ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="perm_agent_<?= $perm ?>_<?= $agent['id'] ?>">
                                        <?= htmlspecialchars($agent['name']) ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Actions -->
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i> Save Changes
        </button>
        <a href="/users" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>

<!-- Reset 2FA Modal -->
<?php if ($user['totp_enabled']): ?>
<div class="modal fade" id="reset2faModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reset Two-Factor Authentication</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to reset 2FA for <strong><?= htmlspecialchars($user['username']) ?></strong>?</p>
                <p class="text-muted small">This will disable their 2FA and delete all recovery codes. They will need to set up 2FA again.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="/users/<?= $user['id'] ?>/reset-2fa" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-shield-x me-1"></i> Reset 2FA
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const roleSelect = document.getElementById('roleSelect');
    const clientAccessCard = document.getElementById('clientAccessCard');
    const permissionsCard = document.getElementById('permissionsCard');
    const allClientsCheck = document.getElementById('allClientsCheck');
    const specificClientsDiv = document.getElementById('specificClientsDiv');

    // Toggle client access and permissions based on role
    roleSelect.addEventListener('change', function() {
        const isAdmin = this.value === 'admin';
        clientAccessCard.style.display = isAdmin ? 'none' : '';
        permissionsCard.style.display = isAdmin ? 'none' : '';
    });

    // Toggle specific clients based on all_clients checkbox
    allClientsCheck.addEventListener('change', function() {
        specificClientsDiv.style.display = this.checked ? 'none' : '';
    });

    // Toggle permission scope options
    document.querySelectorAll('.perm-check').forEach(function(check) {
        check.addEventListener('change', function() {
            const perm = this.dataset.perm;
            document.getElementById('scope_' + perm).style.display = this.checked ? '' : 'none';
        });
    });

    // Toggle specific agents for permission
    document.querySelectorAll('.scope-radio').forEach(function(radio) {
        radio.addEventListener('change', function() {
            const perm = this.dataset.perm;
            const isSpecific = this.value === 'specific';
            document.getElementById('agents_' + perm).style.display = isSpecific ? '' : 'none';
        });
    });
});
</script>
