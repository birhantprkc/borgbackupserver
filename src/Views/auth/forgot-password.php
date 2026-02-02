<?php if (!empty($flash)): ?>
<div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body p-4">
        <h5 class="text-muted mb-2">Forgot your password?</h5>
        <p class="text-muted small mb-4">Enter your email address and we'll send you a reset link.</p>
        <form method="POST" action="/forgot-password">
            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
            <div class="mb-4">
                <label for="email" class="form-label fw-semibold">Email Address</label>
                <input type="email" class="form-control form-control-lg" id="email" name="email" required autofocus>
            </div>
            <div class="d-flex justify-content-between align-items-center">
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="bi bi-envelope me-1"></i> Send Reset Link
                </button>
                <a href="/login" class="text-muted small">Back to login</a>
            </div>
        </form>
    </div>
</div>
