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
        $data = array_merge($data, $this->getSlowStats());
        $data['pageTitle'] = 'Dashboard';

        $this->view('dashboard/index', $data);
    }

    /**
     * GET /dashboard/json — AJAX endpoint for live refresh (fast, no ClickHouse).
     */
    public function apiJson(): void
    {
        $this->requireAuth();
        $this->json($this->getDashboardData());
    }

    /**
     * GET /dashboard/stats-json — slow stats (ClickHouse, server health), polled every 60s.
     */
    public function apiStatsJson(): void
    {
        $this->requireAuth();
        $this->json($this->getSlowStats());
    }

    /**
     * GET /api/toasts — global live event toasts (polled every 8s).
     */
    public function toasts(): void
    {
        $this->requireAuth();
        $since = $_GET['since'] ?? date('Y-m-d H:i:s', strtotime('-10 seconds'));

        [$agentWhere, $agentParams] = $this->getAgentWhereClause('a');
        $jobScope = $agentWhere === '1=1' ? '' : "AND {$agentWhere}";
        $jobParams = array_merge([$since, $since], $agentParams);

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

        $errQuery = "
            SELECT sl.message, a.name as agent_name
            FROM server_log sl
            LEFT JOIN agents a ON a.id = sl.agent_id
            WHERE sl.level = 'error' AND sl.created_at > ?
        ";
        $errParams = [$since];
        if ($agentWhere !== '1=1') {
            $errQuery .= " AND ({$agentWhere} OR sl.agent_id IS NULL)";
            $errParams = array_merge($errParams, $agentParams);
        }
        $errQuery .= " ORDER BY sl.id DESC LIMIT 5";
        $errors = $this->db->fetchAll($errQuery, $errParams);

        $toasts = [];
        foreach ($jobs as $job) {
            $label = match($job['task_type']) {
                'backup' => 'Backup',
                'restore', 'restore_mysql', 'restore_pg', 'restore_mongo' => 'Restore',
                'update_agent' => 'Agent Update',
                'update_borg' => 'Borg Update',
                'plugin_test' => 'Plugin Test',
                'prune' => 'Prune',
                'compact' => 'Compact',
                'catalog_rebuild' => 'Catalog Rebuild',
                'catalog_rebuild_full' => 'Catalog Rebuild (Full)',
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
        // User-scoping: admins see all, users see only their accessible agents
        $isAdmin = $this->isAdmin();
        $userId = $_SESSION['user_id'] ?? 0;

        // Use the new permission system for agent scoping
        [$agentWhere, $agentParams] = $this->getAgentWhereClause();

        // Note: agentWhere expects the table to be aliased as the parameter passed to getAgentWhereClause
        // For agents table queries, we alias it as 'a' and use the same where clause
        $agentCount = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM agents a WHERE {$agentWhere}", $agentParams
        )['cnt'];
        $onlineCount = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM agents a WHERE {$agentWhere} AND a.status = 'online'", $agentParams
        )['cnt'];

        // Job/log queries need agent join for scoping - reuse the same where clause
        $jobScope = $agentWhere === '1=1' ? '' : "AND {$agentWhere}";
        $jobParams = $agentParams;

        $runningJobs = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM backup_jobs bj JOIN agents a ON a.id = bj.agent_id WHERE bj.status IN ('running', 'sent') {$jobScope}", $jobParams
        )['cnt'];
        $queuedJobs = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM backup_jobs bj JOIN agents a ON a.id = bj.agent_id WHERE bj.status = 'queued' {$jobScope}", $jobParams
        )['cnt'];
        $errorCountQuery = "SELECT COUNT(*) as cnt FROM server_log sl LEFT JOIN agents a ON a.id = sl.agent_id WHERE sl.level = 'error' AND sl.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        if ($agentWhere !== '1=1') {
            $errorCountQuery .= " AND ({$agentWhere} OR sl.agent_id IS NULL)";
        }
        $errorCount = $this->db->fetchOne($errorCountQuery, $jobParams)['cnt'];

        $recentJobs = $this->db->fetchAll("
            SELECT bj.*, SUBSTRING(bj.error_log, 1, 255) as error_log, a.name as agent_name,
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
            SELECT bj.*, SUBSTRING(bj.error_log, 1, 255) as error_log, a.name as agent_name,
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

        // Jobs completed per hour over last 24h, segmented by category
        $jobsChart = $this->db->fetchAll("
            SELECT DATE_FORMAT(bj.completed_at, '%Y-%m-%d %H:00') as hour,
                   bj.task_type,
                   COUNT(*) as count
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            WHERE bj.status = 'completed'
              AND bj.completed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
              {$jobScope}
            GROUP BY hour, bj.task_type
            ORDER BY hour
        ", $jobParams);

        // Group task types into 3 categories
        $categoryMap = [
            'backup' => 'backups',
            'restore' => 'restores', 'restore_mysql' => 'restores', 'restore_pg' => 'restores', 'restore_mongo' => 'restores',
            's3_sync' => 's3_sync',
        ];
        // Index by hour+category
        $hourCounts = [];
        foreach ($jobsChart as $row) {
            $cat = $categoryMap[$row['task_type']] ?? null;
            if ($cat === null) continue;
            $hourCounts[$row['hour']][$cat] = ($hourCounts[$row['hour']][$cat] ?? 0) + (int) $row['count'];
        }

        // Fill in missing hours
        $chartData = [];
        $utcTz = new \DateTimeZone('UTC');
        $userTz = new \DateTimeZone($_SESSION['timezone'] ?? 'UTC');
        $now = new \DateTime('now', $utcTz);
        for ($i = 23; $i >= 0; $i--) {
            $hourDt = clone $now;
            $hourDt->modify("-{$i} hours");
            $hourKey = $hourDt->format('Y-m-d H:00');
            $localDt = clone $hourDt;
            $localDt->setTimezone($userTz);
            $label = $localDt->format('ga');
            $counts = $hourCounts[$hourKey] ?? [];
            $chartData[] = [
                'label' => $label,
                'backups' => $counts['backups'] ?? 0,
                'restores' => $counts['restores'] ?? 0,
                's3_sync' => $counts['s3_sync'] ?? 0,
            ];
        }

        return [
            'isAdmin' => $isAdmin,
            'agentCount' => (int) $agentCount,
            'onlineCount' => (int) $onlineCount,
            'runningJobs' => (int) $runningJobs,
            'queuedJobs' => (int) $queuedJobs,
            'errorCount' => (int) $errorCount,
            'recentJobs' => $recentJobs,
            'activeJobs' => $activeJobs,
            'upcomingSchedules' => $upcomingSchedules,
            'chartData' => $chartData,
        ];
    }

    /**
     * Slow stats: ClickHouse, server health, storage.
     * Cached for 60s. When $cacheOnly is true, returns whatever is in cache (for page load).
     */
    private function getSlowStats(): array
    {
        if (!$this->isAdmin()) {
            return [];
        }

        $cache = Cache::getInstance();

        return [
            'cpuLoad' => $cache->remember('server_cpu', 60, fn() => ServerStats::getCpuLoad()),
            'memory' => $cache->remember('server_mem', 60, fn() => ServerStats::getMemory()),
            'partitions' => $cache->remember('server_parts', 60, fn() => ServerStats::getPartitions()),
            'mysqlStats' => $cache->remember('mysql_stats', 60, fn() => ServerStats::getMysqlStats()),
            'clickhouseStats' => $cache->remember('ch_stats', 60, fn() => ServerStats::getClickHouseStats()),
            'storage' => $cache->remember('storage_info', 60, $this->getStorageCallback()),
        ];
    }

    private function getStorageCallback(): \Closure
    {
        return function() {
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
                'disk_used' => null,
                'disk_free' => null,
                'disk_percent' => null,
            ];
            if ($info['total_original'] > 0) {
                $info['dedup_savings'] = round((1 - $info['total_dedup'] / $info['total_original']) * 100, 1);
            }
            if (!empty($path)) {
                $diskUsage = \BBS\Services\ServerStats::getDiskUsage($path);
                if ($diskUsage) {
                    $info['disk_total'] = $diskUsage['total'];
                    $info['disk_used'] = $diskUsage['used'];
                    $info['disk_free'] = $diskUsage['free'];
                    $info['disk_percent'] = $diskUsage['percent'];
                }
            }

            // Remote SSH storage
            $remoteConfigs = $db->fetchAll("SELECT id, name, provider, remote_host, remote_user, disk_total_bytes, disk_used_bytes, disk_free_bytes, disk_checked_at FROM remote_ssh_configs ORDER BY name");
            $remoteStorage = [];
            foreach ($remoteConfigs as $rc) {
                $entry = [
                    'id' => (int) $rc['id'],
                    'name' => $rc['name'],
                    'provider' => $rc['provider'],
                    'host' => $rc['remote_user'] . '@' . $rc['remote_host'],
                    'disk_total' => $rc['disk_total_bytes'] !== null ? (int) $rc['disk_total_bytes'] : null,
                    'disk_used' => $rc['disk_used_bytes'] !== null ? (int) $rc['disk_used_bytes'] : null,
                    'disk_free' => $rc['disk_free_bytes'] !== null ? (int) $rc['disk_free_bytes'] : null,
                    'disk_percent' => null,
                    'checked_at' => $rc['disk_checked_at'],
                ];
                if ($entry['disk_total'] && $entry['disk_total'] > 0) {
                    $entry['disk_percent'] = round(($entry['disk_used'] / $entry['disk_total']) * 100, 1);
                }
                $remoteStorage[] = $entry;
            }
            $info['remote_storage'] = $remoteStorage;

            return $info;
        };
    }
}
