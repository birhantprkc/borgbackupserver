<h5 class="mb-4">My Profile</h5>

<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'account' ? 'active' : '' ?>" href="/profile?tab=account">
            <i class="bi bi-person me-1"></i> Account
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'password' ? 'active' : '' ?>" href="/profile?tab=password">
            <i class="bi bi-key me-1"></i> Password
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === '2fa' ? 'active' : '' ?>" href="/profile?tab=2fa">
            <i class="bi bi-shield-check me-1"></i> Two-Factor Auth
            <?php if ($twoFactorEnabled): ?>
                <span class="badge bg-success ms-1">On</span>
            <?php endif; ?>
        </a>
    </li>
</ul>

<?php if ($tab === 'account'): ?>
<!-- Account Tab -->
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-person me-1"></i> Account Information
            </div>
            <div class="card-body">
                <form method="POST" action="/profile">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <input type="hidden" name="_tab" value="account">

                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" disabled>
                        <div class="form-text">Username cannot be changed.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($user['email']) ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Timezone</label>
                        <select class="form-select" name="timezone">
                            <?php
                            $commonZones = [
                                'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
                                'America/Anchorage', 'Pacific/Honolulu', 'America/Phoenix',
                                'America/Toronto', 'America/Vancouver',
                                'Europe/London', 'Europe/Berlin', 'Europe/Paris', 'Europe/Amsterdam',
                                'Europe/Moscow', 'Asia/Tokyo', 'Asia/Shanghai', 'Asia/Kolkata',
                                'Australia/Sydney', 'Pacific/Auckland', 'UTC',
                            ];
                            $allZones = timezone_identifiers_list();
                            $userTz = $user['timezone'] ?? 'America/New_York';
                            ?>
                            <optgroup label="Common">
                                <?php foreach ($commonZones as $tz): ?>
                                <option value="<?= $tz ?>" <?= $userTz === $tz ? 'selected' : '' ?>><?= str_replace(['/', '_'], [' / ', ' '], $tz) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="All Timezones">
                                <?php foreach ($allZones as $tz): ?>
                                <option value="<?= $tz ?>" <?= $userTz === $tz ? 'selected' : '' ?>><?= $tz ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <input type="text" class="form-control" value="<?= ucfirst($user['role']) ?>" disabled>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Member Since</label>
                        <input type="text" class="form-control" value="<?= \BBS\Core\TimeHelper::format($user['created_at'], 'M j, Y') ?>" disabled>
                    </div>

                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php elseif ($tab === 'password'): ?>
<!-- Password Tab -->
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-key me-1"></i> Change Password
            </div>
            <div class="card-body">
                <form method="POST" action="/profile">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <input type="hidden" name="_tab" value="password">

                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" class="form-control" name="current_password" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" class="form-control" name="new_password" required minlength="6">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" name="confirm_password" required minlength="6">
                    </div>

                    <button type="submit" class="btn btn-warning">Change Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php elseif ($tab === '2fa'): ?>
<!-- Two-Factor Auth Tab -->
<div class="row justify-content-center">
    <div class="col-lg-8">

<?php if ($step === 'main'): ?>
    <?php if (!$twoFactorEnabled): ?>
        <!-- 2FA Disabled -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-shield-x me-1"></i> Two-Factor Authentication
            </div>
            <div class="card-body">
                <p class="mb-3">
                    Two-factor authentication adds an extra layer of security to your account.
                    When enabled, you'll need to enter a code from your authenticator app in addition
                    to your password when logging in.
                </p>
                <p class="text-muted small mb-4">
                    Compatible with Google Authenticator, Authy, 1Password, and other TOTP authenticator apps.
                </p>
                <form method="POST" action="/profile/2fa/setup">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-shield-check me-1"></i> Enable Two-Factor Authentication
                    </button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <!-- 2FA Enabled -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-shield-check me-1 text-success"></i> Two-Factor Authentication
            </div>
            <div class="card-body">
                <div class="alert alert-success mb-4">
                    <i class="bi bi-check-circle me-1"></i> Your account is protected with two-factor authentication.
                </div>

                <div class="mb-4">
                    <h6 class="fw-semibold">Recovery Codes</h6>
                    <p class="text-muted small">
                        <?= $remainingCodes ?> of 8 codes remaining.
                        <?php if ($remainingCodes <= 2): ?>
                        <span class="badge bg-warning text-dark">Low</span>
                        <?php endif; ?>
                    </p>
                    <form method="POST" action="/profile/2fa/regenerate-codes" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary"
                                onclick="return confirm('This will invalidate all existing recovery codes. Continue?')">
                            <i class="bi bi-arrow-clockwise me-1"></i> Regenerate Recovery Codes
                        </button>
                    </form>
                </div>

                <hr>

                <h6 class="text-danger mb-3">Disable Two-Factor Authentication</h6>
                <p class="text-muted small">Disabling 2FA will make your account less secure.</p>

                <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="collapse" data-bs-target="#disable2fa">
                    <i class="bi bi-shield-x me-1"></i> Disable 2FA
                </button>

                <div class="collapse mt-3" id="disable2fa">
                    <div class="card card-body bg-light">
                        <form method="POST" action="/profile/2fa/disable">
                            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Confirm Your Password</label>
                                <input type="password" class="form-control" name="password" required>
                            </div>
                            <button type="submit" class="btn btn-danger"
                                    onclick="return confirm('Are you sure you want to disable 2FA?')">
                                Disable 2FA
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php elseif ($step === 'verify' && $setupSecret): ?>
    <!-- Step 2: Scan QR and Verify -->
    <?php
    $twoFactorSvc = new \BBS\Services\TwoFactorService();
    $qrSvg = $twoFactorSvc->generateQrCode($user['username'], $setupSecret);
    ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-qr-code me-1"></i> Set Up Two-Factor Authentication
        </div>
        <div class="card-body">
            <p class="mb-3">
                Scan this QR code with your authenticator app, then enter the 6-digit code to verify.
            </p>

            <div class="text-center mb-4">
                <?= $qrSvg ?>
            </div>

            <div class="alert alert-light border small mb-4">
                <strong>Can't scan?</strong> Enter this secret manually:<br>
                <code class="user-select-all"><?= htmlspecialchars($setupSecret) ?></code>
            </div>

            <form method="POST" action="/profile/2fa/enable">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Verification Code</label>
                    <input
                        type="text"
                        class="form-control"
                        name="code"
                        placeholder="000000"
                        maxlength="6"
                        pattern="[0-9]{6}"
                        required
                        autofocus
                        autocomplete="one-time-code"
                        style="max-width: 200px;"
                    >
                </div>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-lg me-1"></i> Verify and Enable
                </button>
                <a href="/profile?tab=2fa" class="btn btn-outline-secondary ms-2">Cancel</a>
            </form>
        </div>
    </div>

<?php elseif ($step === 'codes' && $recoveryCodes): ?>
    <!-- Step 3: Recovery Codes -->
    <div class="card border-0 shadow-sm border-warning">
        <div class="card-header bg-warning text-dark fw-semibold">
            <i class="bi bi-exclamation-triangle me-1"></i> Save Your Recovery Codes
        </div>
        <div class="card-body">
            <div class="alert alert-warning">
                <strong>Important:</strong> Save these recovery codes in a safe place.
                Each code can only be used once. If you lose access to your authenticator app,
                these codes are your only way to log in.
            </div>

            <div class="bg-light p-3 mb-3 rounded font-monospace" id="recovery-codes">
                <?php foreach ($recoveryCodes as $code): ?>
                <div><?= htmlspecialchars($code) ?></div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="btn btn-primary btn-sm" onclick="copyRecoveryCodes()">
                <i class="bi bi-clipboard me-1"></i> Copy to Clipboard
            </button>
            <a href="/profile?tab=2fa" class="btn btn-success btn-sm ms-2">
                <i class="bi bi-check-lg me-1"></i> I've Saved My Codes
            </a>
        </div>
    </div>

    <script>
    function copyRecoveryCodes() {
        var codes = <?= json_encode($recoveryCodes) ?>;
        navigator.clipboard.writeText(codes.join('\n')).then(function() {
            alert('Recovery codes copied to clipboard.');
        });
    }
    </script>
    <?php unset($_SESSION['2fa_recovery_codes']); ?>

<?php else: ?>
    <script>window.location.href = '/profile?tab=2fa';</script>
<?php endif; ?>

    </div>
</div>
<?php endif; ?>
