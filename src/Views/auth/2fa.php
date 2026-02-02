<?php if (!empty($flash)): ?>
<div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body p-4">
        <h5 class="text-muted mb-3">Two-Factor Authentication</h5>
        <p class="text-muted small mb-4">
            Enter the 6-digit code from your authenticator app<?php if (!empty($username)): ?> to continue as <strong><?= htmlspecialchars($username) ?></strong><?php endif; ?>.
        </p>
        <form method="POST" action="/login/2fa">
            <div class="mb-3">
                <label for="code" class="form-label fw-semibold">Authentication Code</label>
                <input
                    type="text"
                    class="form-control form-control-lg text-center"
                    id="code"
                    name="code"
                    placeholder="000000"
                    maxlength="9"
                    required
                    autofocus
                    autocomplete="one-time-code"
                >
                <div class="form-text">Or enter a recovery code (XXXX-XXXX).</div>
            </div>
            <div class="d-flex justify-content-between align-items-center">
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="bi bi-shield-check me-1"></i> Verify
                </button>
                <a href="/login" class="text-muted small">Back to Login</a>
            </div>
        </form>
    </div>
</div>
