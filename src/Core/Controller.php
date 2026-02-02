<?php

namespace BBS\Core;

class Controller
{
    protected Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    protected function view(string $template, array $data = []): void
    {
        extract($data);
        $viewPath = dirname(__DIR__) . '/Views/';
        require $viewPath . 'layouts/app.php';
    }

    protected function authView(string $template, array $data = []): void
    {
        extract($data);
        $viewPath = dirname(__DIR__) . '/Views/';
        require $viewPath . 'layouts/auth.php';
    }

    protected function redirect(string $url): void
    {
        header("Location: {$url}");
        exit;
    }

    protected function json(mixed $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    protected function requireAuth(): void
    {
        if (empty($_SESSION['user_id'])) {
            $this->redirect('/login');
        }

        // Session timeout (configurable, default 8 hours of inactivity)
        $timeoutSetting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'session_timeout_hours'");
        $timeoutHours = (int) ($timeoutSetting['value'] ?? 8);
        if ($timeoutHours < 1) $timeoutHours = 1;
        $timeout = $timeoutHours * 3600;
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
            session_destroy();
            session_start();
            $this->flash('warning', 'Session expired. Please log in again.');
            $this->redirect('/login');
        }
        $_SESSION['last_activity'] = time();

        // Force 2FA: redirect users without 2FA to profile setup
        $currentUri = $_SERVER['REQUEST_URI'] ?? '';
        if (!str_starts_with($currentUri, '/profile') && !str_starts_with($currentUri, '/logout')) {
            $force2fa = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'force_2fa'");
            if ($force2fa && $force2fa['value'] === '1') {
                $user = $this->db->fetchOne("SELECT totp_enabled FROM users WHERE id = ?", [$_SESSION['user_id']]);
                if ($user && $user['totp_enabled'] == 0) {
                    $this->flash('warning', 'Two-factor authentication is required. Please set it up now.');
                    $this->redirect('/profile?tab=2fa');
                }
            }
        }
    }

    protected function requireAdmin(): void
    {
        $this->requireAuth();
        if (($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo 'Access denied';
            exit;
        }
    }

    protected function currentUser(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role' => $_SESSION['user_role'],
        ];
    }

    protected function isAdmin(): bool
    {
        return ($_SESSION['user_role'] ?? '') === 'admin';
    }

    protected function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    protected function verifyCsrf(): void
    {
        $token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($this->csrfToken(), $token)) {
            http_response_code(403);
            echo 'Invalid CSRF token';
            exit;
        }
    }

    /**
     * Check rate limit. Returns true if allowed, false if rate-limited.
     */
    protected function checkRateLimit(string $endpoint, int $maxAttempts = 10, int $windowSeconds = 300): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Clean old entries
        $this->db->query(
            "DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$windowSeconds]
        );

        $row = $this->db->fetchOne(
            "SELECT * FROM rate_limits WHERE ip_address = ? AND endpoint = ? AND window_start > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$ip, $endpoint, $windowSeconds]
        );

        if ($row) {
            if ($row['attempts'] >= $maxAttempts) {
                return false;
            }
            $this->db->query(
                "UPDATE rate_limits SET attempts = attempts + 1 WHERE id = ?",
                [$row['id']]
            );
        } else {
            $this->db->insert('rate_limits', [
                'ip_address' => $ip,
                'endpoint' => $endpoint,
            ]);
        }

        return true;
    }

    /**
     * Reset rate limit on successful action (e.g. after login).
     */
    protected function resetRateLimit(string $endpoint): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $this->db->delete('rate_limits', 'ip_address = ? AND endpoint = ?', [$ip, $endpoint]);
    }

    protected function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    protected function getFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $flash;
    }
}
