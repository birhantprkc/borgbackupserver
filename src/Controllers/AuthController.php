<?php

namespace BBS\Controllers;

use BBS\Core\Controller;

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
}
