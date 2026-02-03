<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\Cache;
use BBS\Services\ServerStats;

class DashboardController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $data = $this->getDashboardData();
        $data['pageTitle'] = 'Dashboard';

        $this->view('dashboard/index', $data);
    }

    /**
     * GET /dashboard/json — AJAX endpoint for live refresh.
     */
    public function apiJson(): void
    {
        $this->requireAuth();
        $data = $this->getDashboardData();
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * GET /api/toasts — global live event toasts (polled every 8s).
     */
    public function toasts(): void
    {
        $this->requireAuth();
        $since = $_GET['since'] ?? date('Y-m-d H:i:s', strtotime('-10 seconds'));

        $isAdmin = $this->isAdmin();
        $userId = $_SESSION['user_id'] ?? 0;
        $jobScope = $isAdmin ? '' : 'AND a.user_id = ?';
        $jobParams = $isAdmin ? [$since, $since] : [$since, $since, $userId];

        $jobs = $this->db->fetchAll("
            SELECT bj.id, bj.status, bj.task_type, a.name as agent_name
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            WHERE (
                (bj.status = 'running' AND bj.started_at > ?) OR
                (bj.status IN ('completed', 'failed') AND bj.completed_at > ?)
            )
            {$jobScope}
            ORDER BY bj.id DESC LIMIT 10
        ", $jobParams);

        $errParams = $isAdmin ? [$since] : [$since, $userId];
        $errors = $this->db->fetchAll("
            SELECT sl.message, a.name as agent_name
            FROM server_log sl
            LEFT JOIN agents a ON a.id = sl.agent_id
            WHERE sl.level = 'error' AND sl.created_at > ?
            " . ($isAdmin ? '' : 'AND (a.user_id = ? OR sl.agent_id IS NULL)') . "
            ORDER BY sl.id DESC LIMIT 5
        ", $errParams);

        $toasts = [];
        foreach ($jobs as $job) {
            $label = match($job['task_type']) {
                'backup' => 'Backup',
                'restore', 'restore_mysql', 'restore_pg' => 'Restore',
                'update_agent' => 'Agent Update',
                'update_borg' => 'Borg Update',
                'plugin_test' => 'Plugin Test',
                'prune' => 'Prune',
                'compact' => 'Compact',
                default => ucfirst($job['task_type']),
            };
            if ($job['status'] === 'running') {
                $toasts[] = ['message' => "{$job['agent_name']}: {$label} started", 'type' => 'info'];
            } elseif ($job['status'] === 'completed') {
                $toasts[] = ['message' => "{$job['agent_name']}: {$label} completed", 'type' => 'success'];
            } elseif ($job['status'] === 'failed') {
                $toasts[] = ['message' => "{$job['agent_name']}: {$label} failed", 'type' => 'danger'];
            }
        }
        foreach ($errors as $err) {
            $name = $err['agent_name'] ?? 'System';
            $msg = strlen($err['message']) > 100 ? substr($err['message'], 0, 100) . '...' : $err['message'];
            $toasts[] = ['message' => "{$name}: {$msg}", 'type' => 'danger'];
        }

        $this->json(['toasts' => $toasts, 'server_time' => date('Y-m-d H:i:s')]);
    }

    private function getDashboardData(): array
    {
        // User-scoping: admins see all, users see only their agents
        $isAdmin = $this->isAdmin();
        $userId = $_SESSION['user_id'] ?? 0;

        if ($isAdmin) {
            $agentWhere = '1=1';
            $agentParams = [];
        } else {
            $agentWhere = 'user_id = ?';
            $agentParams = [$userId];
        }

        $agentCount = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM agents WHERE {$agentWhere}", $agentParams
        )['cnt'];
        $onlineCount = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM agents WHERE {$agentWhere} AND status = 'online'", $agentParams
        )['cnt'];

        // Job/log queries need agent join for scoping
        $jobScope = $isAdmin ? '' : 'AND a.user_id = ?';
        $jobParams = $isAdmin ? [] : [$userId];

        $runningJobs = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM backup_jobs bj JOIN agents a ON a.id = bj.agent_id WHERE bj.status IN ('running', 'sent') {$jobScope}", $jobParams
        )['cnt'];
        $queuedJobs = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM backup_jobs bj JOIN agents a ON a.id = bj.agent_id WHERE bj.status = 'queued' {$jobScope}", $jobParams
        )['cnt'];
        $errorCount = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM server_log sl LEFT JOIN agents a ON a.id = sl.agent_id WHERE sl.level = 'error' AND sl.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) " . ($isAdmin ? '' : 'AND (a.user_id = ? OR sl.agent_id IS NULL)'), $jobParams
        )['cnt'];

        $recentJobs = $this->db->fetchAll("
            SELECT bj.*, a.name as agent_name,
                   r.name as repo_name, bp.name as plan_name
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
            WHERE bj.status IN ('completed', 'failed', 'cancelled') {$jobScope}
            ORDER BY bj.completed_at DESC
            LIMIT 10
        ", $jobParams);

        $activeJobs = $this->db->fetchAll("
            SELECT bj.*, a.name as agent_name,
                   r.name as repo_name, bp.name as plan_name
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
            WHERE bj.status IN ('queued', 'running', 'sent') {$jobScope}
            ORDER BY bj.queued_at ASC
        ", $jobParams);

        $upcomingSchedules = $this->db->fetchAll("
            SELECT s.next_run, s.frequency, s.timezone,
                   bp.id as plan_id, bp.name as plan_name, a.name as agent_name, a.id as agent_id
            FROM schedules s
            JOIN backup_plans bp ON bp.id = s.backup_plan_id
            JOIN agents a ON a.id = bp.agent_id
            WHERE s.enabled = 1
              AND s.next_run IS NOT NULL
              AND bp.enabled = 1
              {$jobScope}
            ORDER BY s.next_run ASC
            LIMIT 5
        ", $jobParams);

        $recentLogs = $this->db->fetchAll("
            SELECT sl.*, a.name as agent_name
            FROM server_log sl
            LEFT JOIN agents a ON a.id = sl.agent_id
            WHERE 1=1 " . ($isAdmin ? '' : 'AND (a.user_id = ? OR sl.agent_id IS NULL)') . "
            ORDER BY sl.created_at DESC
            LIMIT 15
        ", $jobParams);

        // Backups completed per hour over last 24h
        $backupsChart = $this->db->fetchAll("
            SELECT DATE_FORMAT(bj.completed_at, '%Y-%m-%d %H:00') as hour,
                   COUNT(*) as count
            FROM backup_jobs bj
            " . ($isAdmin ? '' : 'JOIN agents a ON a.id = bj.agent_id') . "
            WHERE bj.status = 'completed'
              AND bj.completed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
              {$jobScope}
            GROUP BY hour
            ORDER BY hour
        ", $jobParams);

        // Fill in missing hours
        $chartData = [];
        $utcTz = new \DateTimeZone('UTC');
        $userTz = new \DateTimeZone($_SESSION['timezone'] ?? 'UTC');
        $now = new \DateTime('now', $utcTz);
        for ($i = 23; $i >= 0; $i--) {
            $hourDt = clone $now;
            $hourDt->modify("-{$i} hours");
            $hourKey = $hourDt->format('Y-m-d H:00'); // UTC key to match DB
            $localDt = clone $hourDt;
            $localDt->setTimezone($userTz);
            $label = $localDt->format('ga'); // User's timezone for display
            $count = 0;
            foreach ($backupsChart as $row) {
                if ($row['hour'] === $hourKey) {
                    $count = (int) $row['count'];
                    break;
                }
            }
            $chartData[] = ['label' => $label, 'count' => $count];
        }

        // Server stats (admin only)
        $result = [
            'isAdmin' => $isAdmin,
            'agentCount' => (int) $agentCount,
            'onlineCount' => (int) $onlineCount,
            'runningJobs' => (int) $runningJobs,
            'queuedJobs' => (int) $queuedJobs,
            'errorCount' => (int) $errorCount,
            'recentJobs' => $recentJobs,
            'activeJobs' => $activeJobs,
            'upcomingSchedules' => $upcomingSchedules,
            'recentLogs' => $recentLogs,
            'chartData' => $chartData,
        ];

        if ($isAdmin) {
            $cache = Cache::getInstance();
            $result['cpuLoad'] = $cache->remember('server_cpu', 10, fn() => ServerStats::getCpuLoad());
            $result['memory'] = $cache->remember('server_mem', 10, fn() => ServerStats::getMemory());
            $result['partitions'] = $cache->remember('server_parts', 30, fn() => ServerStats::getPartitions());
            $result['mysqlStorage'] = $cache->remember('mysql_storage', 30, fn() => ServerStats::getMysqlStorage());
            $result['mysqlStats'] = $cache->remember('mysql_stats', 8, fn() => ServerStats::getMysqlStats());

            // Storage disk usage
            $result['storage'] = $cache->remember('storage_info', 30, function() {
                $db = \BBS\Core\Database::getInstance();
                $setting = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
                $path = $setting['value'] ?? '';
                $repoStats = $db->fetchOne("SELECT COUNT(*) as repo_count, COALESCE(SUM(size_bytes), 0) as total_repo_bytes FROM repositories");
                $archiveStats = $db->fetchOne("SELECT COUNT(*) as total_archives, COALESCE(SUM(original_size), 0) as total_original, COALESCE(SUM(deduplicated_size), 0) as total_dedup, COALESCE(SUM(file_count), 0) as total_files FROM archives");
                $clientCount = $db->fetchOne("SELECT COUNT(*) as cnt FROM agents");

                $info = [
                    'path' => $path,
                    'repo_count' => (int) ($repoStats['repo_count'] ?? 0),
                    'total_repo_bytes' => (int) ($repoStats['total_repo_bytes'] ?? 0),
                    'total_archives' => (int) ($archiveStats['total_archives'] ?? 0),
                    'total_original' => (int) ($archiveStats['total_original'] ?? 0),
                    'total_dedup' => (int) ($archiveStats['total_dedup'] ?? 0),
                    'total_files' => (int) ($archiveStats['total_files'] ?? 0),
                    'dedup_savings' => 0,
                    'client_count' => (int) ($clientCount['cnt'] ?? 0),
                    'disk_total' => null,
                    'disk_free' => null,
                    'disk_percent' => null,
                ];
                if ($info['total_original'] > 0) {
                    $info['dedup_savings'] = round((1 - $info['total_dedup'] / $info['total_original']) * 100, 1);
                }
                if (!empty($path) && is_dir($path)) {
                    $total = @disk_total_space($path);
                    $free = @disk_free_space($path);
                    if ($total !== false && $free !== false && $total > 0) {
                        $info['disk_total'] = $total;
                        $info['disk_free'] = $free;
                        $info['disk_percent'] = round((($total - $free) / $total) * 100, 1);
                    }
                }
                return $info;
            });
        }

        return $result;
    }
}
