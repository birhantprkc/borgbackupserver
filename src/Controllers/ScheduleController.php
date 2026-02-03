<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\PermissionService;

class ScheduleController extends Controller
{
    public function toggle(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $schedule = $this->getSchedule($id);
        if (!$schedule) {
            $this->flash('danger', 'Schedule not found.');
            $this->redirect('/clients');
        }

        // Require manage_plans permission to toggle schedules
        $this->requirePermission(PermissionService::MANAGE_PLANS, $schedule['agent_id']);

        $newEnabled = $schedule['enabled'] ? 0 : 1;
        $this->db->update('schedules', ['enabled' => $newEnabled], 'id = ?', [$id]);

        $status = $newEnabled ? 'enabled' : 'disabled';
        $this->flash('success', "Schedule {$status}.");
        $this->redirect("/clients/{$schedule['agent_id']}?tab=schedules");
    }

    public function delete(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $schedule = $this->getSchedule($id);
        if (!$schedule) {
            $this->flash('danger', 'Schedule not found.');
            $this->redirect('/clients');
        }

        // Require manage_plans permission to delete schedules
        $this->requirePermission(PermissionService::MANAGE_PLANS, $schedule['agent_id']);

        $this->db->delete('schedules', 'id = ?', [$id]);
        $this->flash('success', 'Schedule deleted.');
        $this->redirect("/clients/{$schedule['agent_id']}?tab=schedules");
    }

    private function getSchedule(int $id): ?array
    {
        $schedule = $this->db->fetchOne("
            SELECT s.*, bp.agent_id
            FROM schedules s
            JOIN backup_plans bp ON bp.id = s.backup_plan_id
            JOIN agents a ON a.id = bp.agent_id
            WHERE s.id = ?
        ", [$id]);

        if (!$schedule) return null;
        if (!$this->canAccessAgent($schedule['agent_id'])) return null;

        return $schedule;
    }
}
