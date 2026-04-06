<?php if (!empty($flash)): ?>
<div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (!empty($oidcEnabled)): ?>
<div class="card shadow-sm mb-3">
    <div class="card-body p-4 text-center">
        <a href="/login/oidc" class="btn btn-primary btn-lg w-100">
            <i class="bi bi-box-arrow-in-right me-2"></i><?= htmlspecialchars($oidcButtonLabel ?? 'Login with SSO') ?>
        </a>
    </div>
</div>
<div class="text-center text-muted small mb-3">— or sign in with username —</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body p-4">
        <h5 class="text-muted mb-4">Please login:</h5>
        <form method="POST" action="/login">
            <div class="mb-3">
                <label for="username" class="form-label fw-semibold">Username</label>
                <input type="text" class="form-control form-control" id="username" name="username" required autofocus>
            </div>
            <div class="mb-4">
                <label for="password" class="form-label fw-semibold">Password</label>
                <input type="password" class="form-control form-control" id="password" name="password" required>
            </div>
            <div class="d-flex justify-content-between align-items-center">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-lock-fill me-1"></i> Sign in
                </button>
                <a href="/forgot-password" class="text-muted small">Forgot password?</a>
            </div>
        </form>
    </div>
</div>
