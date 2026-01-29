<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\PluginManager;

class BackupPlanController extends Controller
{
    public function store(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $repositoryId = (int) ($_POST['repository_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $directories = trim($_POST['directories'] ?? '');
        $excludes = trim($_POST['excludes'] ?? '');
        $advancedOptions = trim($_POST['advanced_options'] ?? '');
        $frequency = $_POST['frequency'] ?? 'daily';
        $times = trim($_POST['times'] ?? '');
        $dayOfWeek = !empty($_POST['day_of_week']) ? (int) $_POST['day_of_week'] : null;
        $dayOfMonth = !empty($_POST['day_of_month']) ? (int) $_POST['day_of_month'] : null;

        $pruneMinutes = (int) ($_POST['prune_minutes'] ?? 0);
        $pruneHours = (int) ($_POST['prune_hours'] ?? 0);
        $pruneDays = (int) ($_POST['prune_days'] ?? 7);
        $pruneWeeks = (int) ($_POST['prune_weeks'] ?? 4);
        $pruneMonths = (int) ($_POST['prune_months'] ?? 6);
        $pruneYears = (int) ($_POST['prune_years'] ?? 0);

        if (empty($name) || empty($agentId) || empty($repositoryId) || empty($directories)) {
            $this->flash('danger', 'Name, repository, and directories are required.');
            $this->redirect("/clients/{$agentId}?tab=schedules");
        }

        // Verify agent access
        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$agentId]);
        if (!$agent || (!$this->isAdmin() && $agent['user_id'] != $_SESSION['user_id'])) {
            $this->flash('danger', 'Access denied.');
            $this->redirect('/clients');
        }

        // Verify repository belongs to agent
        $repo = $this->db->fetchOne("SELECT * FROM repositories WHERE id = ? AND agent_id = ?", [$repositoryId, $agentId]);
        if (!$repo) {
            $this->flash('danger', 'Repository not found for this client.');
            $this->redirect("/clients/{$agentId}?tab=schedules");
        }

        // Create backup plan
        $planId = $this->db->insert('backup_plans', [
            'agent_id' => $agentId,
            'repository_id' => $repositoryId,
            'name' => $name,
            'directories' => $directories,
            'excludes' => $excludes ?: null,
            'advanced_options' => $advancedOptions ?: null,
            'prune_minutes' => $pruneMinutes,
            'prune_hours' => $pruneHours,
            'prune_days' => $pruneDays,
            'prune_weeks' => $pruneWeeks,
            'prune_months' => $pruneMonths,
            'prune_years' => $pruneYears,
        ]);

        // Create associated schedule
        $nextRun = $this->calculateNextRun($frequency, $times, $dayOfWeek, $dayOfMonth);

        $this->db->insert('schedules', [
            'backup_plan_id' => $planId,
            'frequency' => $frequency,
            'times' => $times ?: null,
            'day_of_week' => $dayOfWeek,
            'day_of_month' => $dayOfMonth,
            'enabled' => $frequency === 'manual' ? 0 : 1,
            'next_run' => $nextRun,
        ]);

        // Save plugin configurations
        $this->savePluginConfigs($planId);

        $this->flash('success', "Backup plan \"{$name}\" created with schedule.");
        $this->redirect("/clients/{$agentId}?tab=schedules");
    }

    public function update(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $plan = $this->getPlan($id);
        if (!$plan) {
            $this->flash('danger', 'Backup plan not found.');
            $this->redirect('/clients');
        }

        $data = [];
        if (isset($_POST['name'])) $data['name'] = trim($_POST['name']);
        if (isset($_POST['directories'])) $data['directories'] = trim($_POST['directories']);
        if (isset($_POST['excludes'])) $data['excludes'] = trim($_POST['excludes']) ?: null;
        if (isset($_POST['advanced_options'])) $data['advanced_options'] = trim($_POST['advanced_options']) ?: null;
        if (isset($_POST['repository_id'])) $data['repository_id'] = (int) $_POST['repository_id'];
        if (isset($_POST['prune_minutes'])) $data['prune_minutes'] = (int) $_POST['prune_minutes'];
        if (isset($_POST['prune_hours'])) $data['prune_hours'] = (int) $_POST['prune_hours'];
        if (isset($_POST['prune_days'])) $data['prune_days'] = (int) $_POST['prune_days'];
        if (isset($_POST['prune_weeks'])) $data['prune_weeks'] = (int) $_POST['prune_weeks'];
        if (isset($_POST['prune_months'])) $data['prune_months'] = (int) $_POST['prune_months'];
        if (isset($_POST['prune_years'])) $data['prune_years'] = (int) $_POST['prune_years'];

        if (!empty($data)) {
            $this->db->update('backup_plans', $data, 'id = ?', [$id]);
        }

        // Update plugin configurations
        $this->savePluginConfigs($id);

        $this->flash('success', 'Backup plan updated.');
        $this->redirect("/clients/{$plan['agent_id']}?tab=schedules");
    }

    public function delete(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $plan = $this->getPlan($id);
        if (!$plan) {
            $this->flash('danger', 'Backup plan not found.');
            $this->redirect('/clients');
        }

        $agentId = $plan['agent_id'];
        $this->db->delete('backup_plans', 'id = ?', [$id]);
        $this->flash('success', "Backup plan \"{$plan['name']}\" deleted.");
        $this->redirect("/clients/{$agentId}?tab=schedules");
    }

    public function trigger(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $plan = $this->getPlan($id);
        if (!$plan) {
            $this->flash('danger', 'Backup plan not found.');
            $this->redirect('/clients');
        }

        // Create a queued backup job
        $this->db->insert('backup_jobs', [
            'backup_plan_id' => $id,
            'agent_id' => $plan['agent_id'],
            'repository_id' => $plan['repository_id'],
            'task_type' => 'backup',
            'status' => 'queued',
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $plan['agent_id'],
            'level' => 'info',
            'message' => "Manual backup triggered for plan \"{$plan['name']}\"",
        ]);

        $this->flash('success', "Backup job queued for plan \"{$plan['name']}\".");
        $this->redirect("/clients/{$plan['agent_id']}?tab=schedules");
    }

    private function getPlan(int $id): ?array
    {
        $plan = $this->db->fetchOne("
            SELECT bp.*, a.user_id
            FROM backup_plans bp
            JOIN agents a ON a.id = bp.agent_id
            WHERE bp.id = ?
        ", [$id]);

        if (!$plan) return null;
        if (!$this->isAdmin() && $plan['user_id'] != $_SESSION['user_id']) return null;

        return $plan;
    }

    private function savePluginConfigs(int $planId): void
    {
        $enabledPlugins = array_keys($_POST['plugin_enabled'] ?? []);
        if (empty($enabledPlugins)) {
            // Clear any existing plugin configs
            $this->db->query("DELETE FROM backup_plan_plugins WHERE backup_plan_id = ?", [$planId]);
            return;
        }

        $pluginConfigs = $_POST['plugin_config'] ?? [];
        $pluginManager = new PluginManager();
        $pluginManager->savePlanPlugins($planId, $enabledPlugins, $pluginConfigs);
    }

    private function calculateNextRun(string $frequency, string $times, ?int $dayOfWeek, ?int $dayOfMonth): ?string
    {
        if ($frequency === 'manual') {
            return null;
        }

        $now = new \DateTime();

        // For interval-based frequencies, next run is now + interval
        $intervals = [
            '10min' => 'PT10M',
            '15min' => 'PT15M',
            '30min' => 'PT30M',
            'hourly' => 'PT1H',
        ];

        if (isset($intervals[$frequency])) {
            $now->add(new \DateInterval($intervals[$frequency]));
            return $now->format('Y-m-d H:i:s');
        }

        // For daily/weekly/monthly, use the first time in the times list
        $timeList = array_filter(array_map('trim', explode(',', $times)));
        $firstTime = !empty($timeList) ? $timeList[0] : '01:00';

        if ($frequency === 'daily') {
            $next = new \DateTime("today {$firstTime}");
            if ($next <= $now) {
                $next->modify('+1 day');
            }
            return $next->format('Y-m-d H:i:s');
        }

        if ($frequency === 'weekly' && $dayOfWeek !== null) {
            $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $next = new \DateTime("next {$days[$dayOfWeek]} {$firstTime}");
            return $next->format('Y-m-d H:i:s');
        }

        if ($frequency === 'monthly' && $dayOfMonth !== null) {
            $next = new \DateTime();
            $next->setDate((int)$next->format('Y'), (int)$next->format('m'), min($dayOfMonth, 28));
            $next->setTime(...array_map('intval', explode(':', $firstTime)));
            if ($next <= $now) {
                $next->modify('+1 month');
            }
            return $next->format('Y-m-d H:i:s');
        }

        // Fallback: 1 hour from now
        $now->add(new \DateInterval('PT1H'));
        return $now->format('Y-m-d H:i:s');
    }
}
