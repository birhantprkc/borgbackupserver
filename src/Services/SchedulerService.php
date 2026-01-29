<?php

namespace BBS\Services;

use BBS\Core\Database;
use BBS\Services\NotificationService;

class SchedulerService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Check all enabled schedules and create queued jobs for any that are due.
     * Should be called periodically (e.g., every minute via cron).
     */
    public function run(): array
    {
        // Skip if maintenance mode is active
        $maintenance = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'maintenance_mode'");
        if (($maintenance['value'] ?? '0') === '1') {
            return [];
        }

        $now = date('Y-m-d H:i:s');

        // Find schedules that are due
        $notificationService = new NotificationService();

        $dueSchedules = $this->db->fetchAll("
            SELECT s.*, bp.agent_id, bp.repository_id, bp.name as plan_name
            FROM schedules s
            JOIN backup_plans bp ON bp.id = s.backup_plan_id
            JOIN agents a ON a.id = bp.agent_id
            WHERE s.enabled = 1
              AND s.next_run IS NOT NULL
              AND s.next_run <= ?
              AND bp.enabled = 1
              AND a.status IN ('online', 'setup')
        ", [$now]);

        $created = [];

        foreach ($dueSchedules as $schedule) {
            // Check if there's already a pending/running job for this plan
            $existing = $this->db->fetchOne("
                SELECT id FROM backup_jobs
                WHERE backup_plan_id = ?
                  AND status IN ('queued', 'sent', 'running')
            ", [$schedule['backup_plan_id']]);

            if ($existing) {
                // Skip — already has a job in progress
                continue;
            }

            // Create queued job
            $jobId = $this->db->insert('backup_jobs', [
                'backup_plan_id' => $schedule['backup_plan_id'],
                'agent_id' => $schedule['agent_id'],
                'repository_id' => $schedule['repository_id'],
                'task_type' => 'backup',
                'status' => 'queued',
            ]);

            // Log it
            $this->db->insert('server_log', [
                'agent_id' => $schedule['agent_id'],
                'backup_job_id' => $jobId,
                'level' => 'info',
                'message' => "Scheduled backup queued for plan \"{$schedule['plan_name']}\"",
            ]);

            // Calculate and set next_run
            $nextRun = $this->calculateNextRun($schedule);
            $this->db->update('schedules', [
                'last_run' => $now,
                'next_run' => $nextRun,
            ], 'id = ?', [$schedule['id']]);

            // Resolve any missed_schedule notification for this plan
            $notificationService->resolve('missed_schedule', $schedule['agent_id'], $schedule['backup_plan_id']);

            $created[] = [
                'job_id' => $jobId,
                'plan' => $schedule['plan_name'],
                'agent_id' => $schedule['agent_id'],
            ];
        }

        // Check for overdue schedules where agent is offline (missed_schedule)
        $overdueSchedules = $this->db->fetchAll("
            SELECT s.*, bp.agent_id, bp.name as plan_name, a.name as agent_name
            FROM schedules s
            JOIN backup_plans bp ON bp.id = s.backup_plan_id
            JOIN agents a ON a.id = bp.agent_id
            WHERE s.enabled = 1
              AND s.next_run IS NOT NULL
              AND s.next_run <= ?
              AND bp.enabled = 1
              AND a.status = 'offline'
        ", [$now]);

        foreach ($overdueSchedules as $sched) {
            $notificationService->notify(
                'missed_schedule',
                $sched['agent_id'],
                $sched['backup_plan_id'],
                "Missed schedule for plan \"{$sched['plan_name']}\" — client \"{$sched['agent_name']}\" is offline"
            );
        }

        return $created;
    }

    private function calculateNextRun(array $schedule): ?string
    {
        $now = new \DateTime();

        $intervals = [
            '10min' => 'PT10M',
            '15min' => 'PT15M',
            '30min' => 'PT30M',
            'hourly' => 'PT1H',
        ];

        if (isset($intervals[$schedule['frequency']])) {
            $now->add(new \DateInterval($intervals[$schedule['frequency']]));
            return $now->format('Y-m-d H:i:s');
        }

        $timeList = array_filter(array_map('trim', explode(',', $schedule['times'] ?? '')));

        if ($schedule['frequency'] === 'daily' && !empty($timeList)) {
            // Find the next time today, or first time tomorrow
            $today = new \DateTime('today');
            foreach ($timeList as $time) {
                $candidate = clone $today;
                $parts = explode(':', $time);
                $candidate->setTime((int)($parts[0] ?? 0), (int)($parts[1] ?? 0));
                if ($candidate > $now) {
                    return $candidate->format('Y-m-d H:i:s');
                }
            }
            // All times passed today, use first time tomorrow
            $tomorrow = new \DateTime('tomorrow');
            $parts = explode(':', $timeList[0]);
            $tomorrow->setTime((int)($parts[0] ?? 0), (int)($parts[1] ?? 0));
            return $tomorrow->format('Y-m-d H:i:s');
        }

        if ($schedule['frequency'] === 'weekly') {
            $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $dayName = $days[$schedule['day_of_week'] ?? 1] ?? 'Monday';
            $firstTime = $timeList[0] ?? '01:00';
            $next = new \DateTime("next {$dayName} {$firstTime}");
            return $next->format('Y-m-d H:i:s');
        }

        if ($schedule['frequency'] === 'monthly') {
            $dom = min($schedule['day_of_month'] ?? 1, 28);
            $firstTime = $timeList[0] ?? '01:00';
            $parts = explode(':', $firstTime);
            $next = new \DateTime();
            $next->modify('first day of next month');
            $next->setDate((int)$next->format('Y'), (int)$next->format('m'), $dom);
            $next->setTime((int)($parts[0] ?? 0), (int)($parts[1] ?? 0));
            if ($next <= $now) {
                $next->modify('+1 month');
            }
            return $next->format('Y-m-d H:i:s');
        }

        return null;
    }
}
