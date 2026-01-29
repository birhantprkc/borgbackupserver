<h5 class="mb-4">My Profile</h5>

<div class="row g-4">
    <!-- Account Info -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-person me-1"></i> Account Information
            </div>
            <div class="card-body">
                <form method="POST" action="/profile">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

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
                        <input type="text" class="form-control" value="<?= date('M j, Y', strtotime($user['created_at'])) ?>" disabled>
                    </div>

                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Password -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-key me-1"></i> Change Password
            </div>
            <div class="card-body">
                <form method="POST" action="/profile">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

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
