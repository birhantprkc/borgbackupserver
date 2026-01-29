<?php

namespace BBS\Controllers;

use BBS\Core\Controller;

class ProfileController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);

        $this->view('profile/index', [
            'pageTitle' => 'Profile',
            'user' => $user,
        ]);
    }

    public function update(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $userId = $_SESSION['user_id'];
        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);

        $email = trim($_POST['email'] ?? '');
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Update timezone
        $timezone = trim($_POST['timezone'] ?? '');
        if ($timezone && in_array($timezone, timezone_identifiers_list()) && $timezone !== $user['timezone']) {
            $this->db->update('users', ['timezone' => $timezone], 'id = ?', [$userId]);
            $_SESSION['timezone'] = $timezone;
            date_default_timezone_set($timezone);
            $this->flash('success', 'Timezone updated.');
        }

        // Update email
        if ($email && $email !== $user['email']) {
            $existing = $this->db->fetchOne("SELECT id FROM users WHERE email = ? AND id != ?", [$email, $userId]);
            if ($existing) {
                $this->flash('danger', 'That email is already in use.');
                $this->redirect('/profile');
            }
            $this->db->update('users', ['email' => $email], 'id = ?', [$userId]);
            $this->flash('success', 'Email updated.');
        }

        // Update password
        if ($newPassword) {
            if (!password_verify($currentPassword, $user['password_hash'])) {
                $this->flash('danger', 'Current password is incorrect.');
                $this->redirect('/profile');
            }
            if ($newPassword !== $confirmPassword) {
                $this->flash('danger', 'New passwords do not match.');
                $this->redirect('/profile');
            }
            if (strlen($newPassword) < 6) {
                $this->flash('danger', 'Password must be at least 6 characters.');
                $this->redirect('/profile');
            }
            $this->db->update('users', [
                'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
            ], 'id = ?', [$userId]);
            $this->flash('success', 'Password updated.');
        }

        $this->redirect('/profile');
    }
}
