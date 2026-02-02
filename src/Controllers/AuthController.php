<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\Mailer;

class AuthController extends Controller
{
    public function loginForm(): void
    {
        if (!empty($_SESSION['user_id'])) {
            $this->redirect('/');
        }

        $flash = $this->getFlash();
        $this->authView('auth/login', ['flash' => $flash]);
    }

    public function login(): void
    {
        // Rate limit: 5 attempts per 5 minutes
        if (!$this->checkRateLimit('login', 5, 300)) {
            $this->flash('danger', 'Too many login attempts. Please wait a few minutes.');
            $this->redirect('/login');
        }

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $this->flash('danger', 'Username and password are required.');
            $this->redirect('/login');
        }

        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE username = ?",
            [$username]
        );

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->flash('danger', 'Invalid username or password.');
            $this->redirect('/login');
        }

        // Clear rate limit on success and regenerate session ID
        $this->resetRateLimit('login');
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['timezone'] = $user['timezone'] ?? 'America/New_York';
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();

        $this->redirect('/');
    }

    public function logout(): void
    {
        session_destroy();
        $this->redirect('/login');
    }

    public function forgotPasswordForm(): void
    {
        $flash = $this->getFlash();
        $this->authView('auth/forgot-password', ['flash' => $flash]);
    }

    public function forgotPassword(): void
    {
        $this->verifyCsrf();

        if (!$this->checkRateLimit('forgot_password', 3, 900)) {
            $this->flash('danger', 'Too many requests. Please wait before trying again.');
            $this->redirect('/forgot-password');
        }

        $email = trim($_POST['email'] ?? '');

        if (empty($email)) {
            $this->flash('danger', 'Please enter your email address.');
            $this->redirect('/forgot-password');
        }

        // Always show the same message to prevent email enumeration
        $successMsg = 'If an account exists with that email, a password reset link has been sent.';

        $user = $this->db->fetchOne("SELECT id, email FROM users WHERE email = ?", [$email]);

        if ($user) {
            // Clean up expired tokens
            $this->db->query("DELETE FROM password_resets WHERE expires_at < NOW()");

            // Delete existing tokens for this user
            $this->db->delete('password_resets', 'user_id = ?', [$user['id']]);

            // Generate token
            $token = bin2hex(random_bytes(32));
            $this->db->insert('password_resets', [
                'user_id' => $user['id'],
                'token' => $token,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour')),
            ]);

            // Build reset URL
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $resetUrl = "{$scheme}://{$host}/reset-password/{$token}";

            // Send email
            $subject = 'Password Reset — Borg Backup Server';
            $body = "<p>A password reset was requested for your account.</p>"
                . "<p><a href=\"{$resetUrl}\" style=\"display:inline-block;padding:10px 20px;background:#198754;color:#fff;text-decoration:none;border-radius:4px;\">Reset Password</a></p>"
                . "<p>Or copy this link: {$resetUrl}</p>"
                . "<p>This link expires in 1 hour. If you did not request this, you can safely ignore this email.</p>";

            (new Mailer())->send($user['email'], $subject, $body);
        }

        $this->flash('success', $successMsg);
        $this->redirect('/forgot-password');
    }

    public function resetPasswordForm(string $token): void
    {
        // Clean up expired tokens
        $this->db->query("DELETE FROM password_resets WHERE expires_at < NOW()");

        $reset = $this->db->fetchOne(
            "SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW()",
            [$token]
        );

        if (!$reset) {
            $this->flash('danger', 'This password reset link is invalid or has expired.');
            $this->redirect('/login');
        }

        $flash = $this->getFlash();
        $this->authView('auth/reset-password', ['flash' => $flash, 'token' => $token]);
    }

    public function resetPassword(): void
    {
        $this->verifyCsrf();

        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        $reset = $this->db->fetchOne(
            "SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW()",
            [$token]
        );

        if (!$reset) {
            $this->flash('danger', 'This password reset link is invalid or has expired.');
            $this->redirect('/login');
        }

        if (strlen($password) < 6) {
            $this->flash('danger', 'Password must be at least 6 characters.');
            $this->redirect("/reset-password/{$token}");
        }

        if ($password !== $confirm) {
            $this->flash('danger', 'Passwords do not match.');
            $this->redirect("/reset-password/{$token}");
        }

        // Update password
        $this->db->update('users', [
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        ], 'id = ?', [$reset['user_id']]);

        // Delete all tokens for this user
        $this->db->delete('password_resets', 'user_id = ?', [$reset['user_id']]);

        $this->flash('success', 'Your password has been reset. Please log in.');
        $this->redirect('/login');
    }
}
