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

    /**
     * Weekly grid view — shows all enabled schedules laid out on a Mon–Sun
     * calendar with block heights proportional to the plan's median historical
     * backup duration.
     */
    public function week(): void
    {
        $this->requireAuth();

        [$agentWhere, $agentParams] = $this->getAgentWhereClause('a');

        $schedules = $this->db->fetchAll("
            SELECT s.id, s.backup_plan_id, s.frequency, s.times, s.day_of_week,
                   s.day_of_month, s.timezone, s.next_run,
                   bp.name AS plan_name, a.id AS agent_id, a.name AS agent_name
            FROM schedules s
            JOIN backup_plans bp ON bp.id = s.backup_plan_id
            JOIN agents a ON a.id = bp.agent_id
            WHERE s.enabled = 1 AND bp.enabled = 1 AND {$agentWhere}
            ORDER BY a.name, bp.name
        ", $agentParams);

        // Median-duration estimate per plan: last 10 successful backup jobs.
        $planIds = array_unique(array_map(fn($s) => (int) $s['backup_plan_id'], $schedules));
        $durations = [];
        if (!empty($planIds)) {
            $placeholders = implode(',', array_fill(0, count($planIds), '?'));
            $rows = $this->db->fetchAll("
                SELECT backup_plan_id, duration_seconds
                FROM backup_jobs
                WHERE backup_plan_id IN ({$placeholders})
                  AND status = 'completed' AND task_type = 'backup'
                  AND duration_seconds IS NOT NULL AND duration_seconds > 0
                ORDER BY completed_at DESC
            ", $planIds);
            $byPlan = [];
            foreach ($rows as $r) {
                $pid = (int) $r['backup_plan_id'];
                if (!isset($byPlan[$pid])) $byPlan[$pid] = [];
                if (count($byPlan[$pid]) < 10) {
                    $byPlan[$pid][] = (int) $r['duration_seconds'];
                }
            }
            foreach ($byPlan as $pid => $ds) {
                sort($ds);
                $durations[$pid] = $ds[(int) (count($ds) / 2)];
            }
        }

        // User timezone for displaying schedules in the viewer's local time
        $userTz = $_SESSION['timezone'] ?? 'America/New_York';

        // Expand each schedule into concrete blocks for each day-of-week (0=Mon..6=Sun) it fires on
        $intervalFreqs = ['10min', '15min', '30min', 'hourly'];
        $blocks = [];
        $continuous = []; // For interval-based schedules — too dense for the grid
        $other = [];     // Monthly and anything we can't lay out

        foreach ($schedules as $s) {
            $planId = (int) $s['backup_plan_id'];
            $durSec = $durations[$planId] ?? 15 * 60; // 15min default if no history
            $durMin = max(15, (int) round($durSec / 60));
            $estimated = !isset($durations[$planId]);

            if (in_array($s['frequency'], $intervalFreqs, true)) {
                $continuous[] = [
                    'schedule' => $s,
                    'interval_label' => $s['frequency'],
                ];
                continue;
            }

            if ($s['frequency'] === 'monthly') {
                $other[] = $s;
                continue;
            }

            // For daily/weekly we respect the schedule's declared timezone when
            // interpreting the "times" strings (they are local to that tz), then
            // render the result in the viewer's timezone.
            $schedTz = new \DateTimeZone($s['timezone'] ?: 'UTC');
            $viewerTz = new \DateTimeZone($userTz);

            $timeList = array_filter(array_map('trim', explode(',', $s['times'] ?? '')));
            if (empty($timeList)) continue;

            // dayIdx 0=Mon..6=Sun — schema stores day_of_week as 0=Sunday per PHP's `w` format.
            $daysToShow = [];
            if ($s['frequency'] === 'daily') {
                $daysToShow = [0, 1, 2, 3, 4, 5, 6];
            } elseif ($s['frequency'] === 'weekly') {
                $dowSun0 = (int) ($s['day_of_week'] ?? 1); // 0=Sun
                $daysToShow = [($dowSun0 + 6) % 7]; // convert to 0=Mon
            }

            // Pick a reference Monday to generate concrete DateTime objects. We
            // use the start of the CURRENT week in the viewer's tz — it doesn't
            // matter which week because we only care about day-of-week + h:m.
            $ref = new \DateTime('monday this week', $viewerTz);
            $ref->setTime(0, 0, 0);

            foreach ($daysToShow as $dayIdx) {
                foreach ($timeList as $t) {
                    $parts = explode(':', $t);
                    $h = (int) ($parts[0] ?? 0);
                    $m = (int) ($parts[1] ?? 0);

                    // Build the concrete time in the schedule's tz, then convert to viewer tz
                    $schedDate = clone $ref;
                    $schedDate->setTimezone($schedTz);
                    $schedDate->modify("+{$dayIdx} days");
                    $schedDate->setTime($h, $m);
                    $schedDate->setTimezone($viewerTz);

                    // Now derive grid position in viewer tz
                    $viewerDayIdx = ((int) $schedDate->format('N')) - 1; // 1..7 → 0..6 (Mon=0)
                    $startMin = ((int) $schedDate->format('G')) * 60 + (int) $schedDate->format('i');

                    $blocks[] = [
                        'schedule_id' => (int) $s['id'],
                        'plan_id' => $planId,
                        'plan_name' => $s['plan_name'],
                        'agent_id' => (int) $s['agent_id'],
                        'agent_name' => $s['agent_name'],
                        'day_idx' => $viewerDayIdx,
                        'start_min' => $startMin,
                        'duration_min' => $durMin,
                        'estimated' => $estimated,
                        'frequency' => $s['frequency'],
                        'time_label' => $schedDate->format('g:i A'),
                    ];
                }
            }
        }

        // Collect agents shown in the grid for the filter dropdown
        $shownAgents = [];
        foreach ($blocks as $b) {
            $shownAgents[$b['agent_id']] = $b['agent_name'];
        }
        foreach ($continuous as $c) {
            $shownAgents[(int) $c['schedule']['agent_id']] = $c['schedule']['agent_name'];
        }
        foreach ($other as $o) {
            $shownAgents[(int) $o['agent_id']] = $o['agent_name'];
        }

        $this->view('schedules/week', [
            'pageTitle' => 'Schedules',
            'blocks' => $blocks,
            'continuous' => $continuous,
            'otherSchedules' => $other,
            'shownAgents' => $shownAgents,
            'userTz' => $userTz,
        ]);
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
