<?php

namespace BBS\Controllers\Api;

use BBS\Core\Controller;
use BBS\Services\Encryption;
use BBS\Services\UpdateService;
use BBS\Services\AppriseService;
use BBS\Services\Mailer;
use BBS\Services\SshKeyManager;

/**
 * Token-authenticated settings API (#bbsapp) — see docs/API.md for the shapes.
 *
 * Settings are server-wide, so everything here is admin-only, matching
 * SettingsController::requireAdmin(). The web controller writes the whole
 * settings form in one post with an allow-list; these endpoints split that by
 * section so a client can save one screen without round-tripping keys it never
 * displayed.
 *
 * Secret material is write-only throughout: send a value to set it, omit it to
 * leave the stored one alone, and never read it back.
 */
class SettingsApiController extends Controller
{
    /**
     * Section definitions: setting key => type.
     *
     * The settings table stores everything as a string, so the type drives both
     * the cast on the way out and the normalisation on the way in — otherwise
     * every client reimplements the same "0"-is-truthy coercion and they
     * disagree about it.
     */
    private const SECTIONS = [
        'general' => [
            'max_queue' => 'int',
            'agent_poll_interval' => 'int',
            'stall_timeout_minutes' => 'int',
            'agent_offline_notify_minutes' => 'int',
            'auto_retry_failed_backups' => 'bool',
            'auto_retry_max_attempts' => 'int',
            'auto_update_agents' => 'bool',
            'auto_compact_day' => 'int',
            'auto_compact_hour' => 'int',
            'self_backup_enabled' => 'bool',
            'self_backup_catalogs' => 'bool',
            'self_backup_retention' => 'int',
            'maintenance_mode' => 'bool',
            'debug_mode' => 'bool',
            'default_theme' => 'str',
            'server_host' => 'str',
            'ssh_port' => 'int',
            'session_timeout_hours' => 'int',
            'notification_retention_days' => 'int',
            'force_2fa' => 'bool',
            'telemetry_opt_out' => 'bool',
        ],
        'email' => [
            'smtp_host' => 'str',
            'smtp_port' => 'int',
            'smtp_user' => 'str',
            'smtp_secure' => 'str',
            'smtp_from' => 'str',
            'inapp_notify_success_events' => 'bool',
            'email_on_backup_failed' => 'bool',
            'email_on_backup_warning' => 'bool',
            'email_on_agent_offline' => 'bool',
            'email_on_storage_low' => 'bool',
            'email_on_missed_schedule' => 'bool',
        ],
        'auth' => [
            'oidc_enabled' => 'bool',
            'oidc_provider_url' => 'str',
            'oidc_client_id' => 'str',
            'oidc_redirect_url' => 'str',
            'oidc_scopes' => 'str',
            'oidc_button_label' => 'str',
            'oidc_new_user_policy' => 'str',
            'oidc_template_user_id' => 'int_or_null',
            'oidc_logout_enabled' => 'bool',
        ],
        'branding' => [
            'branding_login_theme' => 'str',
        ],
    ];

    /** Written only when non-empty, stored encrypted, never returned. */
    private const SECRET_KEYS = [
        'email' => ['smtp_pass'],
        'auth' => ['oidc_client_secret'],
    ];

    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '', true);
        return is_array($decoded) ? $decoded : [];
    }

    private function allSettings(): array
    {
        $out = [];
        foreach ($this->db->fetchAll("SELECT `key`, `value` FROM settings") as $row) {
            $out[$row['key']] = $row['value'];
        }
        return $out;
    }

    private function saveSetting(string $key, string $value): void
    {
        $existing = $this->db->fetchOne("SELECT `key` FROM settings WHERE `key` = ?", [$key]);
        if ($existing) {
            $this->db->update('settings', ['value' => $value], "`key` = ?", [$key]);
        } else {
            $this->db->insert('settings', ['key' => $key, 'value' => $value]);
        }
    }

    private function castOut(?string $raw, string $type, string $key): mixed
    {
        if ($type === 'bool') {
            return ($raw ?? '0') === '1';
        }
        if ($type === 'int') {
            return (int) ($raw ?? 0);
        }
        if ($type === 'int_or_null') {
            return ($raw === null || $raw === '') ? null : (int) $raw;
        }
        return $raw ?? '';
    }

    private function castIn(mixed $value, string $type): ?string
    {
        if ($type === 'bool') {
            return !empty($value) && $value !== '0' ? '1' : '0';
        }
        if ($type === 'int') {
            return (string) (int) $value;
        }
        if ($type === 'int_or_null') {
            return ($value === null || $value === '') ? '' : (string) (int) $value;
        }
        return trim((string) $value);
    }

    /**
     * One section, typed, with secrets replaced by *_set booleans.
     */
    private function sectionPayload(string $section, array $settings): array
    {
        $out = [];
        foreach (self::SECTIONS[$section] as $key => $type) {
            // branding_login_theme is exposed as login_theme, matching the doc
            $outKey = $key === 'branding_login_theme' ? 'login_theme' : $key;
            $out[$outKey] = $this->castOut($settings[$key] ?? null, $type, $key);
        }

        foreach (self::SECRET_KEYS[$section] ?? [] as $secret) {
            $out[$secret . '_set'] = !empty($settings[$secret]);
        }

        if ($section === 'general') {
            // Derived from APP_URL rather than stored, exactly as the web form
            // reads it — there is no url_protocol row in the settings table.
            $out['url_protocol'] = str_starts_with(
                \BBS\Core\Config::get('APP_URL', 'https://'), 'https://'
            ) ? 'https' : 'http';
        }

        if ($section === 'branding') {
            // Only the app icon has a serving route; the other assets are
            // reported as presence flags. Uploads stay in the web UI.
            $out['icon_url'] = !empty($settings['branding_app_icon']) ? '/branding/icon/180' : null;
            $out['app_icon_url'] = null;
            $out['login_logo_url'] = null;
            $out['navbar_icon_set'] = !empty($settings['branding_icon']);
            $out['login_logo_set'] = !empty($settings['branding_login_logo']);
        }

        return $out;
    }

    /**
     * GET /api/v1/settings — every section in one call.
     */
    public function show(): void
    {
        $this->requireApiAdmin();
        $settings = $this->allSettings();

        $out = [];
        foreach (array_keys(self::SECTIONS) as $section) {
            $out[$section] = $this->sectionPayload($section, $settings);
        }
        $this->json($out);
    }

    /**
     * PATCH /api/v1/settings/{section} — partial save of one section.
     *
     * Only keys present in the body are written; absent keys are left alone.
     * The app shows one section at a time, so treating an absent key as "clear
     * this" would wipe settings the screen never displayed.
     */
    public function updateSection(string $section): void
    {
        $this->requireApiAdmin();

        if (!isset(self::SECTIONS[$section])) {
            $this->json(['error' => 'Unknown settings section'], 404);
        }

        $input = $this->getJsonInput();
        $fields = self::SECTIONS[$section];
        $sideEffects = [];

        $oldServerHost = null;
        if ($section === 'general' && array_key_exists('server_host', $input)) {
            $row = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
            $oldServerHost = $row['value'] ?? '';
        }

        foreach ($fields as $key => $type) {
            $inKey = $key === 'branding_login_theme' ? 'login_theme' : $key;
            if (!array_key_exists($inKey, $input)) {
                continue;
            }
            $this->saveSetting($key, $this->castIn($input[$inKey], $type));
        }

        // Secrets: set when a non-empty value arrives, otherwise untouched.
        // A blank field from a client means "keep what is stored", never "clear".
        foreach (self::SECRET_KEYS[$section] ?? [] as $secret) {
            if (!empty($input[$secret])) {
                $this->saveSetting($secret, Encryption::encrypt((string) $input[$secret]));
            }
        }

        if ($section === 'general' && $oldServerHost !== null) {
            $newHost = trim((string) $input['server_host']);

            // Repo paths bake the host in at creation time, so changing it has
            // to rewrite every agent without a per-client override (#367). A
            // PATCH that skipped this would leave every repo path pointing at
            // the old host.
            if ($newHost !== $oldServerHost) {
                $agents = $this->db->fetchAll(
                    "SELECT id FROM agents WHERE server_host_override IS NULL OR server_host_override = ''"
                );
                $updated = 0;
                foreach ($agents as $a) {
                    $updated += SshKeyManager::rewriteAgentRepoHosts($this->db, (int) $a['id'], $newHost);
                }
                if ($updated > 0) {
                    $this->db->insert('server_log', [
                        'level' => 'info',
                        'message' => "Server host changed — updated {$updated} repository path(s)",
                    ]);
                }
                $sideEffects['repo_paths_rewritten'] = $updated;
            }

            // Keep APP_URL in step with the host and protocol
            $protocol = (($input['url_protocol'] ?? null) === 'http') ? 'http' : (
                str_starts_with(\BBS\Core\Config::get('APP_URL', 'https://'), 'https://') ? 'https' : 'http'
            );
            if (isset($input['url_protocol'])) {
                $protocol = $input['url_protocol'] === 'http' ? 'http' : 'https';
            }
            $envPath = dirname(__DIR__, 3) . '/config/.env';
            if (file_exists($envPath) && is_writable($envPath)) {
                $env = file_get_contents($envPath);
                $env = preg_replace('/^APP_URL=.*$/m', 'APP_URL=' . $protocol . '://' . $newHost, $env);
                file_put_contents($envPath, $env);
            }
        }

        $response = [$section => $this->sectionPayload($section, $this->allSettings())];
        if (!empty($sideEffects)) {
            $response['side_effects'] = $sideEffects;
        }
        $this->json($response);
    }

    /**
     * POST /api/v1/settings/email/test — send a test message through the
     * saved SMTP settings. The one thing on the email screen worth having on
     * a phone: it's how you find out the settings are wrong before a backup
     * fails at 3am.
     */
    public function testEmail(): void
    {
        $ctx = $this->requireApiAdmin();
        $input = $this->getJsonInput();

        $to = trim((string) ($input['to'] ?? ''));
        if ($to === '') {
            $user = $this->db->fetchOne("SELECT email FROM users WHERE id = ?", [(int) $ctx['id']]);
            $to = $user['email'] ?? '';
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->json(['error' => 'A valid recipient address is required.'], 422);
        }

        $mailer = new Mailer();
        if (!$mailer->isEnabled()) {
            $this->json(['error' => 'SMTP is not configured on this server'], 409);
        }

        $sent = $mailer->send(
            $to,
            'BBS test message',
            '<p>This is a test message from Borg Backup Server. If you received it, your SMTP settings work.</p>'
        );

        if (!$sent) {
            $this->json(['error' => 'Failed to send. Check the SMTP settings.'], 502);
        }
        $this->json(['status' => 'ok']);
    }

    // ── Notification services (Apprise targets) ─────────────────────

    /**
     * The canonical event list. Shipped with the response rather than
     * hardcoded client-side: it has grown twice already, and a client with a
     * stale copy silently drops the new events from its editor.
     */
    private const EVENT_TYPES = [
        'backup_completed' => 'Backup Completed',
        'backup_warning' => 'Backup Completed with Warnings',
        'backup_failed' => 'Backup Failed',
        'restore_completed' => 'Restore Completed',
        'restore_failed' => 'Restore Failed',
        'agent_offline' => 'Client Offline',
        'agent_online' => 'Client Online',
        'repo_check_failed' => 'Check Failed',
        'repo_compact_done' => 'Compact Done',
        'storage_low' => 'Storage Low',
        's3_sync_failed' => 'S3 Sync Failed',
        's3_sync_done' => 'S3 Sync Done',
        'missed_schedule' => 'Missed Schedule',
    ];

    /**
     * An Apprise URL embeds a webhook credential, so it is never returned.
     * The hint keeps the scheme and a few identifying characters so the user
     * can tell two Slack targets apart before replacing one wholesale.
     */
    private function urlHint(string $url): string
    {
        if (!preg_match('#^(\w+)://(.*)$#', $url, $m)) {
            return '(hidden)';
        }
        $rest = $m[2];
        $tail = strlen($rest) > 5 ? substr($rest, -5) : '';
        return $m[1] . '://…' . $tail;
    }

    private function servicePayload(array $row): array
    {
        $events = json_decode($row['events'] ?? '{}', true) ?: [];
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'service_type' => $row['service_type'],
            'enabled' => (bool) $row['enabled'],
            'url_hint' => $this->urlHint($row['apprise_url'] ?? ''),
            'events' => (object) $events,
            'last_used_at' => $row['last_used_at'],
            'created_at' => $row['created_at'],
        ];
    }

    private function detectServiceType(string $url): string
    {
        return preg_match('/^(\w+):\/\//', $url, $m) ? strtolower($m[1]) : 'unknown';
    }

    /** Normalise a posted events map to the known keys only. */
    private function normaliseEvents(mixed $events, array $existing = []): array
    {
        $out = [];
        foreach (array_keys(self::EVENT_TYPES) as $event) {
            if (is_array($events) && array_key_exists($event, $events)) {
                $out[$event] = !empty($events[$event]);
            } else {
                $out[$event] = !empty($existing[$event]);
            }
        }
        return $out;
    }

    public function listNotificationServices(): void
    {
        $this->requireApiAdmin();
        $rows = $this->db->fetchAll("SELECT * FROM notification_services ORDER BY name");
        $this->json([
            'services' => array_map(fn($r) => $this->servicePayload($r), $rows),
            'event_types' => self::EVENT_TYPES,
        ]);
    }

    public function createNotificationService(): void
    {
        $this->requireApiAdmin();
        $input = $this->getJsonInput();

        $name = trim((string) ($input['name'] ?? ''));
        $url = trim((string) ($input['apprise_url'] ?? ''));
        if ($name === '' || $url === '') {
            $this->json(['error' => 'name and apprise_url are required'], 422);
        }

        $id = $this->db->insert('notification_services', [
            'name' => $name,
            'service_type' => $this->detectServiceType($url),
            'apprise_url' => $url,
            'enabled' => array_key_exists('enabled', $input) ? (!empty($input['enabled']) ? 1 : 0) : 1,
            'events' => json_encode($this->normaliseEvents($input['events'] ?? null)),
        ]);

        $row = $this->db->fetchOne("SELECT * FROM notification_services WHERE id = ?", [$id]);
        $this->json(['service' => $this->servicePayload($row)], 201);
    }

    public function updateNotificationService(int $id): void
    {
        $this->requireApiAdmin();
        $input = $this->getJsonInput();

        $row = $this->db->fetchOne("SELECT * FROM notification_services WHERE id = ?", [$id]);
        if (!$row) {
            $this->json(['error' => 'Service not found'], 404);
        }

        $data = [];
        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name === '') {
                $this->json(['error' => 'name cannot be empty'], 422);
            }
            $data['name'] = $name;
        }
        // An empty apprise_url means "keep the stored one", never "clear it"
        if (!empty($input['apprise_url'])) {
            $url = trim((string) $input['apprise_url']);
            $data['apprise_url'] = $url;
            $data['service_type'] = $this->detectServiceType($url);
        }
        if (array_key_exists('enabled', $input)) {
            $data['enabled'] = !empty($input['enabled']) ? 1 : 0;
        }
        if (array_key_exists('events', $input)) {
            $existing = json_decode($row['events'] ?? '{}', true) ?: [];
            $data['events'] = json_encode($this->normaliseEvents($input['events'], $existing));
        }

        if (!empty($data)) {
            $this->db->update('notification_services', $data, 'id = ?', [$id]);
        }

        $this->json(['service' => $this->servicePayload(
            $this->db->fetchOne("SELECT * FROM notification_services WHERE id = ?", [$id])
        )]);
    }

    public function deleteNotificationService(int $id): void
    {
        $this->requireApiAdmin();
        if (!$this->db->fetchOne("SELECT id FROM notification_services WHERE id = ?", [$id])) {
            $this->json(['error' => 'Service not found'], 404);
        }
        $this->db->delete('notification_services', 'id = ?', [$id]);
        http_response_code(204);
        exit;
    }

    public function testNotificationService(int $id): void
    {
        $this->requireApiAdmin();

        $service = $this->db->fetchOne("SELECT * FROM notification_services WHERE id = ?", [$id]);
        if (!$service) {
            $this->json(['error' => 'Service not found'], 404);
        }

        $apprise = new AppriseService();
        if (!$apprise->isAppriseInstalled()) {
            $this->json(['error' => 'Apprise is not installed on the server.'], 409);
        }

        $cmd = 'apprise -t ' . escapeshellarg('BBS Test Notification')
             . ' -b ' . escapeshellarg('This is a test notification from Borg Backup Server. If you receive this, the service is configured correctly.')
             . ' ' . escapeshellarg($service['apprise_url']) . ' 2>&1';
        exec($cmd, $output, $code);

        if ($code !== 0) {
            $this->json(['error' => implode("\n", $output) ?: 'Apprise command failed.'], 502);
        }

        $this->db->update('notification_services', ['last_used_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        $this->json(['status' => 'ok']);
    }

    // ── Backup templates ────────────────────────────────────────────

    private function templatePayload(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'directories' => $row['directories'],
            'excludes' => $row['excludes'],
            'advanced_options' => $row['advanced_options'],
            'usage_count' => isset($row['usage_count']) ? (int) $row['usage_count'] : 0,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    public function listTemplates(): void
    {
        $this->requireApiAdmin();
        // usage_count: plans whose directories match the template, the only
        // link that exists — templates are copied into a plan, not referenced.
        $rows = $this->db->fetchAll("
            SELECT t.*,
                   (SELECT COUNT(*) FROM backup_plans bp WHERE bp.directories = t.directories) AS usage_count
            FROM backup_templates t ORDER BY t.name");
        $this->json(['templates' => array_map(fn($r) => $this->templatePayload($r), $rows)]);
    }

    public function createTemplate(): void
    {
        $this->requireApiAdmin();
        $input = $this->getJsonInput();

        $name = trim((string) ($input['name'] ?? ''));
        $directories = trim((string) ($input['directories'] ?? ''));
        if ($name === '' || $directories === '') {
            $this->json(['error' => 'name and directories are required'], 422);
        }

        $id = $this->db->insert('backup_templates', [
            'name' => $name,
            'description' => trim((string) ($input['description'] ?? '')) ?: null,
            'directories' => $directories,
            'excludes' => trim((string) ($input['excludes'] ?? '')) ?: null,
            'advanced_options' => trim((string) ($input['advanced_options'] ?? '')) ?: null,
        ]);

        $this->json(['template' => $this->templatePayload(
            $this->db->fetchOne("SELECT * FROM backup_templates WHERE id = ?", [$id])
        )], 201);
    }

    public function updateTemplate(int $id): void
    {
        $this->requireApiAdmin();
        $input = $this->getJsonInput();

        if (!$this->db->fetchOne("SELECT id FROM backup_templates WHERE id = ?", [$id])) {
            $this->json(['error' => 'Template not found'], 404);
        }

        $data = [];
        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name === '') {
                $this->json(['error' => 'name cannot be empty'], 422);
            }
            $data['name'] = $name;
        }
        if (array_key_exists('directories', $input)) {
            $dirs = trim((string) $input['directories']);
            if ($dirs === '') {
                $this->json(['error' => 'directories cannot be empty'], 422);
            }
            $data['directories'] = $dirs;
        }
        foreach (['description', 'excludes', 'advanced_options'] as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = trim((string) $input[$field]) ?: null;
            }
        }

        if (!empty($data)) {
            $this->db->update('backup_templates', $data, 'id = ?', [$id]);
        }

        $this->json(['template' => $this->templatePayload(
            $this->db->fetchOne("SELECT * FROM backup_templates WHERE id = ?", [$id])
        )]);
    }

    public function deleteTemplate(int $id): void
    {
        $this->requireApiAdmin();
        if (!$this->db->fetchOne("SELECT id FROM backup_templates WHERE id = ?", [$id])) {
            $this->json(['error' => 'Template not found'], 404);
        }
        $this->db->delete('backup_templates', 'id = ?', [$id]);
        http_response_code(204);
        exit;
    }

    // ── API tokens ──────────────────────────────────────────────────

    public function listTokens(): void
    {
        $ctx = $this->requireApiAdmin();
        $rows = $this->db->fetchAll("
            SELECT t.id, t.name, t.kind, t.can_read_secrets, t.user_id, u.username,
                   t.created_at, t.last_used_at, t.last_seen_ip, t.expires_at, t.device_name
            FROM api_tokens t JOIN users u ON u.id = t.user_id
            ORDER BY t.created_at DESC");

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'name' => $r['name'],
                'kind' => $r['kind'],
                'can_read_secrets' => (bool) $r['can_read_secrets'],
                'user_id' => (int) $r['user_id'],
                'username' => $r['username'],
                'created_at' => $r['created_at'],
                'last_used_at' => $r['last_used_at'],
                'last_seen_ip' => $r['last_seen_ip'],
                'expires_at' => $r['expires_at'],
                'device_name' => $r['device_name'],
                // The caller cannot otherwise tell which bbs_tok_… row is the
                // session it is holding, and revoking it signs them out.
                'is_current' => (int) $r['id'] === (int) $ctx['token_id'],
            ];
        }
        $this->json(['tokens' => $out]);
    }

    public function createToken(): void
    {
        $ctx = $this->requireApiAdmin();
        $input = $this->getJsonInput();

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $this->json(['error' => 'name is required'], 422);
        }
        if ($this->db->fetchOne("SELECT id FROM api_tokens WHERE name = ?", [$name])) {
            $this->json(['error' => "A token named \"{$name}\" already exists."], 409);
        }

        // A mobile token must never be able to mint itself a secrets-reading
        // token — that would walk straight around the restriction that keeps
        // repository passphrases and S3 credentials off the phone.
        $canReadSecrets = !empty($input['can_read_secrets']);
        if ($canReadSecrets && ($ctx['token_kind'] ?? 'user') === 'mobile') {
            $this->json([
                'error' => 'A mobile session cannot create a token that reads secrets.',
            ], 403);
        }

        $token = 'bbs_tok_' . bin2hex(random_bytes(24));
        $id = $this->db->insert('api_tokens', [
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'user_id' => (int) $ctx['id'],
            'can_read_secrets' => $canReadSecrets ? 1 : 0,
        ]);

        // Returned once, here, and never again — same contract as the 2FA
        // recovery codes.
        $this->json(['id' => (int) $id, 'token' => $token], 201);
    }

    public function deleteToken(int $id): void
    {
        $this->requireApiAdmin();

        $row = $this->db->fetchOne("SELECT kind FROM api_tokens WHERE id = ?", [$id]);
        if (!$row) {
            $this->json(['error' => 'Token not found'], 404);
        }
        // Same guard the web UI has: the hosted platform's own token is not
        // revocable from a customer-facing surface.
        if (($row['kind'] ?? 'user') === 'platform') {
            $this->json(['error' => 'This token is managed by the hosted platform.'], 403);
        }

        $this->db->delete('api_tokens', 'id = ?', [$id]);
        http_response_code(204);
        exit;
    }

    // ── Updates ─────────────────────────────────────────────────────

    private function updatesPayload(UpdateService $svc): array
    {
        $latest = $svc->getLatestRelease();
        return [
            'server' => [
                'current_version' => $svc->getCurrentVersion(),
                'latest_version' => $latest['version'] ?: null,
                'update_available' => $svc->isUpdateAvailable(),
                'release_notes' => $latest['notes'] ?: null,
                'release_url' => $latest['url'] ?: null,
                'include_prereleases' => $svc->getIncludePrereleases(),
                'checked_at' => $latest['checked_at'] ?: null,
            ],
            'agents' => [
                'bundled_version' => $svc->getBundledAgentVersion(),
                'outdated' => array_map(fn($a) => [
                    'id' => (int) $a['id'],
                    'name' => $a['name'],
                    'agent_version' => $a['agent_version'],
                ], $svc->getOutdatedAgents()),
            ],
        ];
    }

    public function updates(): void
    {
        $this->requireApiAdmin();
        $this->json($this->updatesPayload(new UpdateService()));
    }

    public function checkUpdates(): void
    {
        $this->requireApiAdmin();
        $svc = new UpdateService();
        $svc->checkForUpdate();
        $this->json($this->updatesPayload($svc));
    }

    /**
     * Queue an agent upgrade, skipping agents that already have one pending.
     * Returns the number queued so the caller can report it.
     */
    private function queueAgentUpgrades(array $agents, string $bundledVersion, string $note): int
    {
        $pending = array_column($this->db->fetchAll(
            "SELECT agent_id FROM backup_jobs
             WHERE task_type = 'update_agent' AND status IN ('queued', 'sent', 'running')"
        ), 'agent_id');

        $queued = 0;
        foreach ($agents as $agent) {
            if (in_array($agent['id'], $pending)) {
                continue;
            }
            $jobId = $this->db->insert('backup_jobs', [
                'agent_id' => $agent['id'],
                'task_type' => 'update_agent',
                'status' => 'queued',
            ]);
            $this->db->insert('server_log', [
                'agent_id' => $agent['id'],
                'backup_job_id' => $jobId,
                'level' => 'info',
                'message' => "Agent update queued ({$note}) to v{$bundledVersion}",
            ]);
            $queued++;
        }
        return $queued;
    }

    public function upgradeAgent(int $id): void
    {
        $this->requireApiAdmin();

        $svc = new UpdateService();
        $bundled = $svc->getBundledAgentVersion();
        if (!$bundled) {
            $this->json(['error' => 'Could not determine the bundled agent version.'], 500);
        }

        $agent = $this->db->fetchOne("SELECT id, name FROM agents WHERE id = ?", [$id]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $queued = $this->queueAgentUpgrades([$agent], $bundled, 'API');
        $this->json([
            'status' => 'ok',
            'queued' => $queued,
            'already_pending' => $queued === 0,
        ]);
    }

    public function upgradeAgents(): void
    {
        $this->requireApiAdmin();
        $input = $this->getJsonInput();

        $svc = new UpdateService();
        $bundled = $svc->getBundledAgentVersion();
        if (!$bundled) {
            $this->json(['error' => 'Could not determine the bundled agent version.'], 500);
        }

        $outdated = $svc->getOutdatedAgents();
        if (!empty($input['client_ids']) && is_array($input['client_ids'])) {
            $wanted = array_map('intval', $input['client_ids']);
            $outdated = array_values(array_filter($outdated, fn($a) => in_array((int) $a['id'], $wanted, true)));
        }

        $queued = $this->queueAgentUpgrades($outdated, $bundled, 'bulk');
        $this->json([
            'status' => 'ok',
            'queued' => $queued,
            'outdated' => count($outdated),
            'bundled_version' => $bundled,
        ]);
    }
}
