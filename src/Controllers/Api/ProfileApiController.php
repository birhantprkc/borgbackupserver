<?php

namespace BBS\Controllers\Api;

use BBS\Core\Controller;
use BBS\Services\TwoFactorService;
use BBS\Services\ReportService;
use BBS\Services\PermissionService;
use BBS\Services\Mailer;
use BBS\Services\Encryption;

/**
 * Token-authenticated equivalents of the web My Profile page.
 *
 * ProfileController does all of this already, but as session-authenticated,
 * CSRF-guarded form posts that answer with a redirect and a flash message.
 * These are the same actions with JSON in and JSON out; the business logic
 * still lives in TwoFactorService / ReportService.
 */
class ProfileApiController extends Controller
{
    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '', true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The caller's user row, or a 401 if the account vanished under the token.
     */
    private function apiUser(array $ctx): array
    {
        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [(int) $ctx['id']]);
        if (!$user) {
            $this->json(['error' => 'User no longer exists'], 401);
        }
        return $user;
    }

    /**
     * The `user` object every profile response returns. Mirrors the web
     * Account tab: username and role are read-only, password_hash and
     * totp_secret never leave the server.
     */
    private function userPayload(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'all_clients' => (bool) $user['all_clients'],
            'timezone' => $user['timezone'] ?: 'America/New_York',
            'time_format' => $user['time_format'] ?: '12h',
            'theme' => $user['theme'] ?: 'dark',
            'auth_provider' => $user['auth_provider'] ?: 'local',
            'created_at' => $user['created_at'],
        ];
    }

    private function storageAlertsPayload(array $user): array
    {
        return [
            'enabled' => ($user['storage_alert_mode'] ?: 'percent') !== 'disabled',
        ];
    }

    private function reportPreferencesPayload(array $user): array
    {
        return [
            'enabled' => (bool) $user['daily_report_email'],
            'hour' => (int) $user['daily_report_hour'],
            'frequency' => $user['report_frequency'] ?: 'daily',
            'day' => (int) $user['report_day'],
        ];
    }

    /**
     * Password-backed actions are refused outright for SSO accounts —
     * they have no usable password_hash to verify against.
     */
    private function verifyPassword(array $user, string $password): void
    {
        if (($user['auth_provider'] ?? 'local') !== 'local') {
            $this->json(['error' => 'This account signs in with SSO and has no password'], 400);
        }
        if ($password === '' || !password_verify($password, $user['password_hash'] ?? '')) {
            $this->json(['error' => 'Incorrect password'], 401);
        }
    }

    // ── Account ─────────────────────────────────────────────────────

    /**
     * GET /api/v1/profile — everything the four tabs need in one round trip.
     */
    public function show(): void
    {
        $ctx = $this->requireApiAuth();
        $user = $this->apiUser($ctx);

        $twoFactor = new TwoFactorService();
        $enabled = $twoFactor->isEnabled((int) $user['id']);

        $this->json([
            'user' => $this->userPayload($user),
            'storage_alerts' => $this->storageAlertsPayload($user),
            'two_factor' => [
                'enabled' => $enabled,
                'enabled_at' => $enabled ? $user['totp_enabled_at'] : null,
                'recovery_codes_remaining' => $enabled
                    ? $twoFactor->getRemainingRecoveryCodeCount((int) $user['id'])
                    : 0,
            ],
            'reports' => $this->reportPreferencesPayload($user) + [
                'smtp_enabled' => (new Mailer())->isEnabled(),
            ],
        ]);
    }

    /**
     * PATCH /api/v1/profile — partial update of the Account tab.
     */
    public function update(): void
    {
        $ctx = $this->requireApiAuth();
        $user = $this->apiUser($ctx);
        $input = $this->getJsonInput();
        $userId = (int) $user['id'];

        $updates = [];

        if (array_key_exists('email', $input)) {
            $email = trim((string) $input['email']);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->json(['error' => 'A valid email address is required.'], 422);
            }
            if ($email !== $user['email']) {
                $existing = $this->db->fetchOne(
                    "SELECT id FROM users WHERE email = ? AND id != ?",
                    [$email, $userId]
                );
                if ($existing) {
                    $this->json(['error' => 'That email is already in use.'], 422);
                }
                $updates['email'] = $email;
            }
        }

        if (array_key_exists('time_format', $input)) {
            $tf = (string) $input['time_format'];
            if (!in_array($tf, ['12h', '24h'], true)) {
                $this->json(['error' => 'time_format must be 12h or 24h'], 422);
            }
            $updates['time_format'] = $tf;
        }

        if (array_key_exists('theme', $input)) {
            $theme = (string) $input['theme'];
            if (!in_array($theme, ['dark', 'light'], true)) {
                $this->json(['error' => 'theme must be dark or light'], 422);
            }
            $updates['theme'] = $theme;
        }

        // The profile timezone is a DISPLAY preference only. A schedule keeps
        // its own declared timezone and its own next_run, so changing this
        // never moves when a backup actually fires — it only changes the wall
        // clock the app prints it against (see schedulesDay()).
        if (array_key_exists('timezone', $input)) {
            $tz = trim((string) $input['timezone']);
            if (!in_array($tz, timezone_identifiers_list(), true)) {
                $this->json(['error' => 'Unknown timezone identifier'], 422);
            }
            if ($tz !== $user['timezone']) {
                $updates['timezone'] = $tz;
            }
        }

        if (!empty($updates)) {
            $this->db->update('users', $updates, 'id = ?', [$userId]);
        }

        $this->json(['user' => $this->userPayload($this->apiUser($ctx))]);
    }

    /**
     * GET /api/v1/profile/timezones — the picker's options.
     *
     * The app's runtime ships no timezone database, so it can neither
     * enumerate zones nor work out what offset one is on right now.
     * Both come from here.
     */
    public function timezones(): void
    {
        $this->requireApiAuth();

        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $zones = [];
        foreach (timezone_identifiers_list() as $id) {
            $offsetSeconds = (new \DateTimeZone($id))->getOffset($now);
            $offsetMinutes = intdiv($offsetSeconds, 60);

            $sign = $offsetMinutes < 0 ? '-' : '+';
            $abs = abs($offsetMinutes);
            $hours = intdiv($abs, 60);
            $mins = $abs % 60;
            $label = 'GMT' . $sign . $hours . ($mins ? ':' . str_pad((string) $mins, 2, '0', STR_PAD_LEFT) : '');

            $parts = explode('/', $id);
            $zones[] = [
                'id' => $id,
                // "America/Argentina/Buenos_Aires" reads as "Buenos Aires"
                'label' => str_replace('_', ' ', end($parts)),
                'region' => $parts[0],
                'offset_minutes' => $offsetMinutes,
                'offset_label' => $label,
            ];
        }

        $this->json(['timezones' => $zones, 'detected' => null]);
    }

    /**
     * POST /api/v1/profile/password
     */
    public function changePassword(): void
    {
        $ctx = $this->requireApiAuth();
        $user = $this->apiUser($ctx);
        $input = $this->getJsonInput();

        $this->verifyPassword($user, (string) ($input['current_password'] ?? ''));

        $new = (string) ($input['new_password'] ?? '');
        if (strlen($new) < 6) {
            $this->json(['error' => 'Password must be at least 6 characters.'], 422);
        }

        $this->db->update('users', [
            'password_hash' => password_hash($new, PASSWORD_BCRYPT),
        ], 'id = ?', [(int) $user['id']]);

        // Mobile tokens are long-lived, so a password change retires the other
        // phones that hold one. The calling token survives, so the app that
        // just changed the password is not signed out of itself.
        $others = $this->db->fetchAll(
            "SELECT id FROM api_tokens WHERE user_id = ? AND kind = 'mobile' AND id != ?",
            [(int) $user['id'], (int) $ctx['token_id']]
        );
        if (!empty($others)) {
            $this->db->delete(
                'api_tokens',
                "user_id = ? AND kind = 'mobile' AND id != ?",
                [(int) $user['id'], (int) $ctx['token_id']]
            );
        }

        $this->json(['status' => 'ok', 'other_sessions_revoked' => count($others)]);
    }

    /**
     * PUT /api/v1/profile/storage-alerts — {"enabled": true|false}.
     *
     * A mute switch only. The threshold itself is server-wide
     * (`storage_alert_threshold` under /api/v1/settings), so the alert and the
     * health endpoint report the same disk the same way.
     */
    public function storageAlerts(): void
    {
        $ctx = $this->requireApiAuth();
        $user = $this->apiUser($ctx);
        $input = $this->getJsonInput();

        if (!array_key_exists('enabled', $input)) {
            $this->json(['error' => 'enabled is required'], 422);
        }
        $enabled = filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN);

        $this->db->update('users', [
            'storage_alert_mode' => $enabled ? 'percent' : 'disabled',
        ], 'id = ?', [(int) $user['id']]);

        $this->json(['storage_alerts' => ['enabled' => $enabled]]);
    }

    // ── Two-factor ──────────────────────────────────────────────────

    /**
     * POST /api/v1/profile/2fa/setup
     *
     * Returns the otpauth URI rather than the web's rendered SVG so the app
     * can draw its own QR and offer the key for hand-entry. The pending
     * secret is held server-side (auth_challenges, kind='2fa_setup') in place
     * of the web flow's $_SESSION['2fa_setup_secret'], so enable() never has
     * to trust a secret posted back by the client.
     */
    public function twoFactorSetup(): void
    {
        $ctx = $this->requireApiAuth();
        $user = $this->apiUser($ctx);
        $userId = (int) $user['id'];

        $twoFactor = new TwoFactorService();
        if ($twoFactor->isEnabled($userId)) {
            $this->json(['error' => '2FA is already enabled on your account.'], 409);
        }

        $secret = $twoFactor->generateSecret();

        $this->db->delete('auth_challenges', "expires_at < NOW()", []);
        $this->db->delete('auth_challenges', "kind = '2fa_setup' AND user_id = ?", [$userId]);
        $this->db->insert('auth_challenges', [
            'kind' => '2fa_setup',
            // Nothing redeems this by value — the caller is the token — but the
            // column is UNIQUE and NOT NULL, so give it a random one.
            'challenge_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'user_id' => $userId,
            'payload' => Encryption::encrypt($secret),
            'expires_at' => date('Y-m-d H:i:s', time() + 600),
        ]);

        $totp = \OTPHP\TOTP::createFromSecret($secret);
        $totp->setLabel($user['username']);
        $totp->setIssuer('Borg Backup Server');

        $this->json([
            'secret' => $secret,
            'otpauth_uri' => $totp->getProvisioningUri(),
            'expires_in' => 600,
        ]);
    }

    /**
     * POST /api/v1/profile/2fa/enable — body {"code": "123456"}.
     *
     * A `secret` in the body is ignored: the server verifies against its own
     * pending record.
     */
    public function twoFactorEnable(): void
    {
        $ctx = $this->requireApiAuth();
        $user = $this->apiUser($ctx);
        $userId = (int) $user['id'];
        $input = $this->getJsonInput();

        $twoFactor = new TwoFactorService();
        if ($twoFactor->isEnabled($userId)) {
            $this->json(['error' => '2FA is already enabled on your account.'], 409);
        }

        $code = trim((string) ($input['code'] ?? ''));
        if ($code === '') {
            $this->json(['error' => 'Enter the code from your authenticator app.'], 422);
        }

        $this->db->delete('auth_challenges', "expires_at < NOW()", []);
        $pending = $this->db->fetchOne(
            "SELECT * FROM auth_challenges
             WHERE kind = '2fa_setup' AND user_id = ? AND expires_at >= NOW()
             ORDER BY id DESC LIMIT 1",
            [$userId]
        );
        if (!$pending) {
            $this->json(['error' => '2FA setup expired. Start over.'], 410);
        }

        try {
            $secret = Encryption::decrypt($pending['payload']);
        } catch (\Exception $e) {
            $this->db->delete('auth_challenges', 'id = ?', [$pending['id']]);
            $this->json(['error' => '2FA setup expired. Start over.'], 410);
        }

        if (!$twoFactor->verifyTotp($secret, $code)) {
            $this->json(['error' => 'Invalid code. Please try again.'], 401);
        }

        $twoFactor->enableTotp($userId, $secret);
        $codes = $twoFactor->generateRecoveryCodes($userId);
        $this->db->delete('auth_challenges', 'id = ?', [$pending['id']]);

        // The one response in this API whose contents cannot be fetched again.
        $this->json(['recovery_codes' => $codes]);
    }

    /**
     * POST /api/v1/profile/2fa/disable — body {"password": "…"}.
     */
    public function twoFactorDisable(): void
    {
        $ctx = $this->requireApiAuth();
        $user = $this->apiUser($ctx);
        $input = $this->getJsonInput();

        $this->verifyPassword($user, (string) ($input['password'] ?? ''));

        (new TwoFactorService())->disableTotp((int) $user['id']);
        $this->db->delete('auth_challenges', "kind = '2fa_setup' AND user_id = ?", [(int) $user['id']]);

        $this->json(['status' => 'ok']);
    }

    /**
     * POST /api/v1/profile/2fa/recovery-codes — body {"password": "…"}.
     *
     * The web page regenerates without re-asking; here the password is
     * required so an unlocked phone can't rotate someone's codes.
     */
    public function twoFactorRecoveryCodes(): void
    {
        $ctx = $this->requireApiAuth();
        $user = $this->apiUser($ctx);
        $input = $this->getJsonInput();

        $twoFactor = new TwoFactorService();
        if (!$twoFactor->isEnabled((int) $user['id'])) {
            $this->json(['error' => '2FA is not enabled.'], 409);
        }

        $this->verifyPassword($user, (string) ($input['password'] ?? ''));

        $this->json(['recovery_codes' => $twoFactor->generateRecoveryCodes((int) $user['id'])]);
    }

    // ── Reports ─────────────────────────────────────────────────────

    /**
     * GET /api/v1/profile/reports
     */
    public function reports(): void
    {
        $ctx = $this->requireApiAuth();
        $user = $this->apiUser($ctx);

        $service = new ReportService();
        $rows = $service->getRecentReports();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
        }
        unset($r);

        $this->json([
            'preferences' => $this->reportPreferencesPayload($user),
            'smtp_enabled' => (new Mailer())->isEnabled(),
            'reports' => $rows,
        ]);
    }

    /**
     * PUT /api/v1/profile/reports/preferences
     */
    public function reportPreferences(): void
    {
        $ctx = $this->requireApiAuth();
        $user = $this->apiUser($ctx);
        $input = $this->getJsonInput();

        $frequency = (string) ($input['frequency'] ?? $user['report_frequency']);
        if (!in_array($frequency, ['daily', 'weekly'], true)) {
            $this->json(['error' => 'frequency must be daily or weekly'], 422);
        }

        $prefs = [
            'daily_report_email' => !empty($input['enabled']) ? 1 : 0,
            // hour is 0–23 in the user's own timezone, as on the web form
            'daily_report_hour' => max(0, min(23, (int) ($input['hour'] ?? $user['daily_report_hour']))),
            'report_frequency' => $frequency,
            // day is 0–6 with Sunday=0, and only means anything when weekly
            'report_day' => max(0, min(6, (int) ($input['day'] ?? $user['report_day']))),
        ];
        $this->db->update('users', $prefs, 'id = ?', [(int) $user['id']]);

        $this->json(['preferences' => $this->reportPreferencesPayload($this->apiUser($ctx))]);
    }

    /**
     * GET /api/v1/reports/{id} — the stored report data, scoped to the caller.
     *
     * Returns the `data` JSON rather than renderHtml() so the app can render
     * it natively, but applies exactly the same filtering renderHtml() does:
     * the stored blob covers every agent on the server, so handing it over raw
     * would leak other people's client names to a scoped user.
     */
    public function getReport(int $id): void
    {
        $ctx = $this->requireApiAuth();
        $userId = (int) $ctx['id'];

        $report = (new ReportService())->getReport($id);
        if (!$report) {
            $this->json(['error' => 'Report not found'], 404);
        }

        $this->json([
            'id' => (int) $report['id'],
            'report_date' => $report['report_date'],
            'created_at' => $report['created_at'],
            'data' => $this->scopeReportData($report['data'] ?? [], $userId),
        ]);
    }

    /**
     * POST /api/v1/reports/generate
     */
    public function generateReport(): void
    {
        $this->requireApiAuth();

        // true = bump created_at, matching the web's manual regenerate
        $report = (new ReportService())->generate(null, true);
        $this->json(['id' => (int) $report['id']]);
    }

    /**
     * POST /api/v1/reports/{id}/email — body {"email": "…"} (optional).
     */
    public function emailReport(int $id): void
    {
        $ctx = $this->requireApiAuth();
        $input = $this->getJsonInput();

        if (!(new Mailer())->isEnabled()) {
            $this->json(['error' => 'SMTP is not configured on this server'], 409);
        }

        $report = (new ReportService())->getReport($id);
        if (!$report) {
            $this->json(['error' => 'Report not found'], 404);
        }

        $to = trim((string) ($input['email'] ?? ''));
        if ($to !== '' && !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->json(['error' => 'A valid email address is required.'], 422);
        }

        // emailReport() renders through renderHtml($data, $userId), so the
        // recipient only ever sees the sending user's own agent scope.
        $sent = (new ReportService())->emailReport($id, (int) $ctx['id'], $to ?: null);
        if (!$sent) {
            $this->json(['error' => 'Failed to send report. Check SMTP settings.'], 502);
        }

        $this->json(['status' => 'ok']);
    }

    /**
     * Narrow a stored report to what this user is allowed to see, and
     * recompute the summary over what is left.
     */
    private function scopeReportData(array $data, int $userId): array
    {
        $perms = new PermissionService();
        $userRow = $this->db->fetchOne("SELECT role FROM users WHERE id = ?", [$userId]);
        $isAdmin = $userRow && $userRow['role'] === 'admin';
        $accessible = $perms->getAccessibleAgentIds($userId);

        $agents = array_values(array_filter(
            $data['agents'] ?? [],
            fn($a) => in_array($a['id'], $accessible)
        ));
        $errors = array_values(array_filter(
            $data['errors'] ?? [],
            fn($e) => empty($e['agent_id']) || in_array($e['agent_id'], $accessible)
        ));

        $completed = 0;
        $failed = 0;
        $online = 0;
        $offline = 0;
        foreach ($agents as $a) {
            $completed += (int) ($a['today_completed'] ?? 0);
            $failed += (int) ($a['today_failed'] ?? 0);
            if (($a['status'] ?? '') === 'online') $online++;
            else $offline++;
        }

        $data['agents'] = $agents;
        $data['errors'] = $errors;
        $data['summary'] = [
            'total_agents' => count($agents),
            'online' => $online,
            'offline' => $offline,
            'backups_completed' => $completed,
            'backups_failed' => $failed,
            // Server-wide dedup totals can't be re-derived per agent, so this
            // one is dropped rather than reported wrongly for a scoped user.
            'total_bytes_backed_up' => $isAdmin
                ? ($data['summary']['total_bytes_backed_up'] ?? 0)
                : null,
        ];

        // Whole-server storage is admin-only on the web report; same here.
        if (!$isAdmin) {
            unset($data['server'], $data['remote_storage']);
        }

        return $data;
    }
}
