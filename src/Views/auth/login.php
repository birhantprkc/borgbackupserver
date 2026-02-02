<?php if (!empty($flash)): ?>
<div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body p-4">
        <h5 class="text-muted mb-4">Please login:</h5>
        <form method="POST" action="/login">
            <div class="mb-3">
                <label for="username" class="form-label fw-semibold">Username</label>
                <input type="text" class="form-control form-control-lg" id="username" name="username" required autofocus>
            </div>
            <div class="mb-4">
                <label for="password" class="form-label fw-semibold">Password</label>
                <input type="password" class="form-control form-control-lg" id="password" name="password" required>
            </div>
            <div class="d-flex justify-content-between align-items-center">
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="bi bi-lock-fill me-1"></i> Sign in
                </button>
                <a href="/forgot-password" class="text-muted small">Forgot password?</a>
            </div>
        </form>
    </div>
</div>
