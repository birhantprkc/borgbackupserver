<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\NotificationService;

class NotificationController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $userId = $_SESSION['user_id'];
        $service = new NotificationService();
        $notifications = $service->getAll(50, 0, $userId);

        $this->view('notifications/index', [
            'pageTitle' => 'Notifications',
            'notifications' => $notifications,
        ]);
    }

    public function markRead(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $service = new NotificationService();
        $service->markRead($id, $_SESSION['user_id'] ?? null);

        $this->redirect('/notifications');
    }

    public function markAllRead(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $userId = $_SESSION['user_id'];
        $service = new NotificationService();
        $service->markAllRead($userId);

        $this->flash('success', 'All notifications marked as read.');
        $this->redirect('/notifications');
    }
}
