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
            SELECT bj.*, a.name as agent_name
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            WHERE bj.status = 'completed' {$jobScope}
            ORDER BY bj.completed_at DESC
            LIMIT 10
        ", $jobParams);

        $activeJobs = $this->db->fetchAll("
            SELECT bj.*, a.name as agent_name
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            WHERE bj.status IN ('running', 'sent') {$jobScope}
            ORDER BY bj.started_at DESC
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
        $now = new \DateTime();
        for ($i = 23; $i >= 0; $i--) {
            $hourDt = clone $now;
            $hourDt->modify("-{$i} hours");
            $hourKey = $hourDt->format('Y-m-d H:00');
            $label = $hourDt->format('ga');
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
            'recentLogs' => $recentLogs,
            'chartData' => $chartData,
        ];

        if ($isAdmin) {
            $cache = Cache::getInstance();
            $result['cpuLoad'] = $cache->remember('server_cpu', 10, fn() => ServerStats::getCpuLoad());
            $result['memory'] = $cache->remember('server_mem', 10, fn() => ServerStats::getMemory());
            $result['partitions'] = $cache->remember('server_parts', 30, fn() => ServerStats::getPartitions());
        }

        return $result;
    }
}
