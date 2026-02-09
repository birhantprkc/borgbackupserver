<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\UpdateService;

class UpgradeController extends Controller
{
    public function index(): void
    {
        $this->requireAdmin();

        $service = new UpdateService();
        $status = $service->getUpgradeStatus();
        $release = $service->getLatestRelease();

        // If not upgrading and no recent result, redirect to settings
        if (!$status['in_progress'] && empty($status['result'])) {
            $this->redirect('/settings?tab=updates');
        }

        $this->authView('upgrade/index', [
            'pageTitle' => 'System Upgrade',
            'authColClass' => 'col-md-8',
            'status' => $status,
            'release' => $release,
        ]);
    }

    public function statusJson(): void
    {
        $this->requireAdmin();

        $service = new UpdateService();
        $this->json($service->getUpgradeStatus());
    }

    public function dismiss(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $service = new UpdateService();
        $service->clearUpgrade();
        $this->redirect('/settings?tab=updates');
    }
}
