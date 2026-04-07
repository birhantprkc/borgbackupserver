<?php

namespace BBS\Services;

use BBS\Core\Database;
use Jumbojett\OpenIDConnectClient;

class OidcService
{
    private Database $db;
    private array $settings = [];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->loadSettings();
    }

    private function loadSettings(): void
    {
        $rows = $this->db->fetchAll("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'oidc_%'");
        foreach ($rows as $row) {
            $this->settings[$row['key']] = $row['value'];
        }
    }

    public function isEnabled(): bool
    {
        return ($this->settings['oidc_enabled'] ?? '0') === '1'
            && !empty($this->settings['oidc_provider_url'])
            && !empty($this->settings['oidc_client_id']);
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    public function getButtonLabel(): string
    {
        return $this->settings['oidc_button_label'] ?? 'Login with SSO';
    }

    /**
     * Build an OIDC client instance.
     */
    private function buildClient(string $redirectUri): OpenIDConnectClient
    {
        $providerUrl = rtrim($this->settings['oidc_provider_url'] ?? '', '/');
        $clientId = $this->settings['oidc_client_id'] ?? '';
        $clientSecret = $this->settings['oidc_client_secret'] ?? '';

        // Decrypt client secret
        if (!empty($clientSecret)) {
            try {
                $clientSecret = Encryption::decrypt($clientSecret);
            } catch (\Exception $e) {
                // May already be plaintext
            }
        }

        $oidc = new OpenIDConnectClient($providerUrl, $clientId, $clientSecret);
        $oidc->setRedirectURL($redirectUri);

        // Set scopes (jumbojett library expects an array)
        $scopes = $this->settings['oidc_scopes'] ?? 'openid email profile';
        $scopeList = array_filter(array_map('trim', explode(' ', $scopes)));
        if (!empty($scopeList)) {
            $oidc->addScope($scopeList);
        }

        // Allow insecure for development (auto-detect based on provider URL)
        if (str_starts_with($providerUrl, 'http://')) {
            $oidc->setVerifyHost(false);
            $oidc->setVerifyPeer(false);
        }

        return $oidc;
    }

    /**
     * Redirect the user to the OIDC provider for authentication.
     */
    public function redirectToProvider(string $redirectUri): void
    {
        $oidc = $this->buildClient($redirectUri);
        $oidc->authenticate();
        // authenticate() redirects and exits — should not reach here
    }

    /**
     * Handle the OIDC callback: exchange code for tokens, find/create user.
     *
     * @return array ['user' => array|null, 'status' => 'active'|'pending'|'denied', 'message' => string]
     */
    public function handleCallback(string $redirectUri): array
    {
        $oidc = $this->buildClient($redirectUri);
        $oidc->authenticate();

        // Extract claims
        $email = $oidc->requestUserInfo('email');
        if (empty($email)) {
            throw new \RuntimeException('OIDC provider did not return an email address. Ensure the "email" scope is configured.');
        }

        $preferredUsername = $oidc->requestUserInfo('preferred_username');
        $name = $oidc->requestUserInfo('name');

        return $this->findOrCreateUser($email, $preferredUsername ?: $name ?: '');
    }

    /**
     * Find an existing user by email, or create one based on the new-user policy.
     */
    private function findOrCreateUser(string $email, string $displayName): array
    {
        $user = $this->db->fetchOne("SELECT * FROM users WHERE email = ?", [$email]);

        if ($user) {
            // Existing user found
            if ($user['oidc_status'] === 'pending') {
                return ['user' => null, 'status' => 'pending', 'message' => 'Your account is pending administrator approval.'];
            }

            // Update auth_provider to oidc on first SSO login
            if ($user['auth_provider'] !== 'oidc') {
                $this->db->update('users', ['auth_provider' => 'oidc'], 'id = ?', [$user['id']]);
                $user['auth_provider'] = 'oidc';
            }

            return ['user' => $user, 'status' => 'active', 'message' => ''];
        }

        // No existing user — apply new-user policy
        $policy = $this->settings['oidc_new_user_policy'] ?? 'deny';

        if ($policy === 'deny') {
            return ['user' => null, 'status' => 'denied', 'message' => 'No account found with this email address. Contact your administrator.'];
        }

        // Generate a username from the display name or email
        $username = $this->generateUsername($displayName, $email);

        // Random unusable password hash
        $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);

        $status = ($policy === 'pending') ? 'pending' : 'active';

        $userId = $this->db->insert('users', [
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => 'user',
            'auth_provider' => 'oidc',
            'oidc_status' => $status,
        ]);

        // Copy permissions from template user if policy is 'copy'
        if ($policy === 'copy') {
            $templateUserId = (int) ($this->settings['oidc_template_user_id'] ?? 0);
            if ($templateUserId > 0) {
                $this->copyPermissionsFromTemplate($userId, $templateUserId);
            }
        }

        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => "New OIDC user created: {$username} ({$email})" . ($status === 'pending' ? ' — pending approval' : ''),
        ]);

        if ($status === 'pending') {
            return ['user' => null, 'status' => 'pending', 'message' => 'Your account has been created and is pending administrator approval.'];
        }

        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
        return ['user' => $user, 'status' => 'active', 'message' => ''];
    }

    /**
     * Generate a unique username from display name or email.
     */
    private function generateUsername(string $displayName, string $email): string
    {
        // Try preferred_username / display name first
        $base = $displayName;
        if (empty($base)) {
            // Fall back to email local part
            $base = strstr($email, '@', true) ?: 'user';
        }

        // Sanitize: lowercase, alphanumeric + underscores only
        $base = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $base));
        $base = preg_replace('/_+/', '_', $base);
        $base = trim($base, '_');
        if (empty($base)) $base = 'user';

        // Truncate to fit VARCHAR(50) with room for suffix
        $base = substr($base, 0, 45);

        // Check for collisions
        $username = $base;
        $suffix = 1;
        while ($this->db->fetchOne("SELECT id FROM users WHERE username = ?", [$username])) {
            $username = $base . '_' . $suffix;
            $suffix++;
        }

        return $username;
    }

    /**
     * Copy agent assignments and permissions from a template user to a new user.
     */
    private function copyPermissionsFromTemplate(int $newUserId, int $templateUserId): void
    {
        $template = $this->db->fetchOne("SELECT all_clients FROM users WHERE id = ?", [$templateUserId]);
        if (!$template) return;

        // Copy all_clients flag
        if ($template['all_clients']) {
            $this->db->update('users', ['all_clients' => 1], 'id = ?', [$newUserId]);
        }

        // Copy agent assignments
        $agents = $this->db->fetchAll("SELECT agent_id FROM user_agents WHERE user_id = ?", [$templateUserId]);
        foreach ($agents as $a) {
            $this->db->insert('user_agents', [
                'user_id' => $newUserId,
                'agent_id' => $a['agent_id'],
            ]);
        }

        // Copy permissions
        $perms = $this->db->fetchAll("SELECT permission, agent_id FROM user_permissions WHERE user_id = ?", [$templateUserId]);
        foreach ($perms as $p) {
            $this->db->insert('user_permissions', [
                'user_id' => $newUserId,
                'permission' => $p['permission'],
                'agent_id' => $p['agent_id'],
            ]);
        }
    }

    /**
     * Get the OIDC end_session_endpoint for logout redirect.
     */
    public function getLogoutUrl(string $redirectUri): ?string
    {
        if (($this->settings['oidc_logout_enabled'] ?? '0') !== '1') {
            return null;
        }

        $providerUrl = rtrim($this->settings['oidc_provider_url'] ?? '', '/');
        if (empty($providerUrl)) return null;

        // Fetch discovery document
        $discoveryUrl = $providerUrl . '/.well-known/openid-configuration';
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $json = @file_get_contents($discoveryUrl, false, $ctx);
        if (!$json) return null;

        $config = json_decode($json, true);
        $endSessionEndpoint = $config['end_session_endpoint'] ?? null;
        if (!$endSessionEndpoint) return null;

        return $endSessionEndpoint . '?post_logout_redirect_uri=' . urlencode($redirectUri);
    }
}
