<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\NotificationService;

class NotificationController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $service = new NotificationService();
        $notifications = $service->getAll();

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
        $service->markRead($id);

        $this->redirect('/notifications');
    }

    public function markAllRead(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $service = new NotificationService();
        $service->markAllRead();

        $this->flash('success', 'All notifications marked as read.');
        $this->redirect('/notifications');
    }
}
