<?php if (!empty($flash)): ?>
<div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body p-4">
        <h5 class="text-muted mb-4">Set a new password</h5>
        <form method="POST" action="/reset-password">
            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <div class="mb-3">
                <label for="password" class="form-label fw-semibold">New Password</label>
                <input type="password" class="form-control form-control" id="password" name="password" required autofocus minlength="6">
            </div>
            <div class="mb-4">
                <label for="password_confirm" class="form-label fw-semibold">Confirm Password</label>
                <input type="password" class="form-control form-control" id="password_confirm" name="password_confirm" required minlength="6">
            </div>
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check-lg me-1"></i> Reset Password
            </button>
        </form>
    </div>
</div>
