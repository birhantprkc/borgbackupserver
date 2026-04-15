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

    /**
     * POST /schedules/{id}/time — quick-edit endpoint for the calendar's
     * "Change Time" context menu. Accepts JSON body with times[] and
     * optionally day_of_week. Keeps all other schedule fields untouched.
     */
    public function updateTime(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $schedule = $this->getSchedule($id);
        if (!$schedule) {
            $this->json(['error' => 'Schedule not found'], 404);
        }
        $this->requirePermission(PermissionService::MANAGE_PLANS, $schedule['agent_id']);

        $body = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
        $times = $body['times'] ?? null;
        $dow   = array_key_exists('day_of_week', $body) ? $body['day_of_week'] : null;

        if (!is_array($times) || empty($times)) {
            $this->json(['error' => 'At least one time is required.'], 422);
        }

        // Validate HH:MM format, strip duplicates, sort
        $clean = [];
        foreach ($times as $t) {
            if (!is_string($t)) continue;
            if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', trim($t))) {
                $this->json(['error' => "Invalid time '" . htmlspecialchars($t) . "' — use HH:MM 24-hour format."], 422);
            }
            // Normalize to zero-padded HH:MM
            $parts = explode(':', trim($t));
            $clean[sprintf('%02d:%02d', (int) $parts[0], (int) $parts[1])] = true;
        }
        $clean = array_keys($clean);
        sort($clean);
        $timesStr = implode(',', $clean);

        $update = ['times' => $timesStr];
        if ($schedule['frequency'] === 'weekly' && $dow !== null) {
            $dowInt = (int) $dow;
            if ($dowInt < 0 || $dowInt > 6) {
                $this->json(['error' => 'day_of_week must be 0..6 (Sunday=0).'], 422);
            }
            $update['day_of_week'] = $dowInt;
        }

        // Recompute next_run to reflect the new times immediately
        $update['next_run'] = $this->calcNextRun(
            $schedule['frequency'],
            $timesStr,
            $update['day_of_week'] ?? $schedule['day_of_week'],
            $schedule['day_of_month'],
            $schedule['timezone'] ?: 'UTC'
        );

        $this->db->update('schedules', $update, 'id = ?', [$id]);

        $this->json([
            'ok' => true,
            'schedule' => array_merge($schedule, $update),
        ]);
    }

    /**
     * Next-run calculator — thin duplicate of AdminApiController's version so
     * we don't have to require a Bearer token here. Takes a comma-separated
     * times string and returns the next UTC run as Y-m-d H:i:s.
     */
    private function calcNextRun(string $frequency, string $times, $dayOfWeek, $dayOfMonth, string $timezone = 'UTC'): ?string
    {
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            $tz = new \DateTimeZone('UTC');
        }
        $utc = new \DateTimeZone('UTC');
        $nowLocal = new \DateTime('now', $tz);

        $timeList = array_filter(array_map('trim', explode(',', $times)));
        if (empty($timeList)) return null;

        if ($frequency === 'daily') {
            $today = new \DateTime('today', $tz);
            foreach ($timeList as $t) {
                $parts = explode(':', $t);
                $c = clone $today;
                $c->setTime((int) $parts[0], (int) $parts[1]);
                if ($c > $nowLocal) {
                    $c->setTimezone($utc);
                    return $c->format('Y-m-d H:i:s');
                }
            }
            $tomorrow = new \DateTime('tomorrow', $tz);
            $parts = explode(':', $timeList[0]);
            $tomorrow->setTime((int) $parts[0], (int) $parts[1]);
            $tomorrow->setTimezone($utc);
            return $tomorrow->format('Y-m-d H:i:s');
        }

        if ($frequency === 'weekly') {
            $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $dayName = $days[(int) ($dayOfWeek ?? 1)] ?? 'Monday';
            $firstTime = $timeList[0];
            $next = new \DateTime("next {$dayName} {$firstTime}", $tz);
            $next->setTimezone($utc);
            return $next->format('Y-m-d H:i:s');
        }

        return null; // Monthly/interval — caller can leave as-is
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
        $is24h  = \BBS\Core\TimeHelper::is24h();

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
                        'time_label' => $is24h ? $schedDate->format('H:i') : $schedDate->format('g:i A'),
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

        // Per-day histograms with 60-minute buckets. The day-view timeline
        // handles 30-minute resolution where it actually matters.
        $histBucketMin = 60;
        $histBucketCount = 1440 / $histBucketMin; // 24
        $histograms = [];
        for ($d = 0; $d < 7; $d++) {
            $histograms[$d] = [];
            for ($b = 0; $b < $histBucketCount; $b++) {
                $histograms[$d][$b] = ['total' => 0, 'schedules' => []];
            }
        }
        foreach ($blocks as $blk) {
            $d = $blk['day_idx'];
            $bIdx = (int) floor($blk['start_min'] / $histBucketMin);
            $histograms[$d][$bIdx]['total']++;
            $histograms[$d][$bIdx]['schedules'][] = [
                'schedule_id' => $blk['schedule_id'],
                'agent_id' => $blk['agent_id'],
                'agent_name' => $blk['agent_name'],
                'plan_name' => $blk['plan_name'],
                'time' => $blk['time_label'],
                'frequency' => $blk['frequency'],
            ];
        }
        // Compute global max across all days so the y-axis scale is consistent
        // when switching days (otherwise bars jump around visually).
        $histMax = 0;
        foreach ($histograms as $day) {
            foreach ($day as $bucket) {
                if ($bucket['total'] > $histMax) $histMax = $bucket['total'];
            }
        }

        // Raw schedule map for the "Change Time" modal. Keyed by schedule id.
        $scheduleMap = [];
        foreach ($schedules as $s) {
            $scheduleMap[(int) $s['id']] = [
                'id' => (int) $s['id'],
                'plan_name' => $s['plan_name'],
                'agent_name' => $s['agent_name'],
                'agent_id' => (int) $s['agent_id'],
                'frequency' => $s['frequency'],
                'times' => $s['times'],
                'day_of_week' => $s['day_of_week'] !== null ? (int) $s['day_of_week'] : null,
                'timezone' => $s['timezone'],
            ];
        }

        $this->view('schedules/week', [
            'pageTitle' => 'Schedules',
            'blocks' => $blocks,
            'histograms' => $histograms,
            'histBucketCount' => $histBucketCount,
            'histBucketMin' => $histBucketMin,
            'histMax' => $histMax,
            'is24h' => \BBS\Core\TimeHelper::is24h(),
            'scheduleMap' => $scheduleMap,
            'continuous' => $continuous,
            'otherSchedules' => $other,
            'shownAgents' => $shownAgents,
            'userTz' => $userTz,
            'csrfToken' => $this->csrfToken(),
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
