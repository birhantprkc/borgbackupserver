<?php

namespace BBS\Controllers;

use BBS\Core\Controller;

class UserController extends Controller
{
    public function index(): void
    {
        $this->requireAdmin();

        $users = $this->db->fetchAll("
            SELECT u.*,
                   (SELECT COUNT(*) FROM agents a WHERE a.user_id = u.id) as agent_count
            FROM users u
            ORDER BY u.id
        ");

        $this->view('users/index', [
            'pageTitle' => 'Users',
            'users' => $users,
        ]);
    }

    public function store(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'user';

        if (empty($username) || empty($email) || empty($password)) {
            $this->flash('danger', 'All fields are required.');
            $this->redirect('/users');
        }

        $existing = $this->db->fetchOne("SELECT id FROM users WHERE username = ? OR email = ?", [$username, $email]);
        if ($existing) {
            $this->flash('danger', 'Username or email already exists.');
            $this->redirect('/users');
        }

        $this->db->insert('users', [
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'role' => in_array($role, ['admin', 'user']) ? $role : 'user',
        ]);

        $this->flash('success', "User \"{$username}\" created.");
        $this->redirect('/users');
    }

    public function update(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $data = [];
        if (!empty($_POST['email'])) {
            $data['email'] = trim($_POST['email']);
        }
        if (!empty($_POST['role']) && in_array($_POST['role'], ['admin', 'user'])) {
            $data['role'] = $_POST['role'];
        }
        if (!empty($_POST['password'])) {
            $data['password_hash'] = password_hash($_POST['password'], PASSWORD_BCRYPT);
        }

        if (!empty($data)) {
            $this->db->update('users', $data, 'id = ?', [$id]);
        }

        $this->flash('success', 'User updated.');
        $this->redirect('/users');
    }

    public function delete(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        if ($id == $_SESSION['user_id']) {
            $this->flash('danger', 'You cannot delete your own account.');
            $this->redirect('/users');
        }

        $this->db->delete('users', 'id = ?', [$id]);
        $this->flash('success', 'User deleted.');
        $this->redirect('/users');
    }
}
