<?php

namespace BBS\Controllers;

use BBS\Core\Controller;

class QueueController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $inProgress = $this->db->fetchAll("
            SELECT bj.*, a.name as agent_name, r.name as repo_name
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            WHERE bj.status IN ('queued', 'sent', 'running')
            ORDER BY bj.queued_at ASC
        ");

        $completed = $this->db->fetchAll("
            SELECT bj.*, a.name as agent_name, r.name as repo_name
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            WHERE bj.status IN ('completed', 'failed')
            ORDER BY bj.completed_at DESC
            LIMIT 25
        ");

        // Queue stats
        $queuedCount = (int) ($this->db->fetchOne("SELECT COUNT(*) AS cnt FROM backup_jobs WHERE status IN ('queued', 'sent')")['cnt'] ?? 0);
        $runningCount = (int) ($this->db->fetchOne("SELECT COUNT(*) AS cnt FROM backup_jobs WHERE status = 'running'")['cnt'] ?? 0);
        $completed24h = (int) ($this->db->fetchOne("SELECT COUNT(*) AS cnt FROM backup_jobs WHERE status = 'completed' AND completed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")['cnt'] ?? 0);
        $failed24h = (int) ($this->db->fetchOne("SELECT COUNT(*) AS cnt FROM backup_jobs WHERE status = 'failed' AND completed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")['cnt'] ?? 0);
        $avgDuration = $this->db->fetchOne("SELECT ROUND(AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at))) AS avg_sec FROM backup_jobs WHERE status = 'completed' AND completed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) AND started_at IS NOT NULL");
        $avgSec = (int) ($avgDuration['avg_sec'] ?? 0);

        $this->view('queue/index', [
            'pageTitle' => 'Queue',
            'inProgress' => $inProgress,
            'completed' => $completed,
            'queuedCount' => $queuedCount,
            'runningCount' => $runningCount,
            'completed24h' => $completed24h,
            'failed24h' => $failed24h,
            'avgSec' => $avgSec,
        ]);
    }

    public function indexJson(): void
    {
        $this->requireAuth();

        $inProgress = $this->db->fetchAll("
            SELECT bj.*, a.name as agent_name, r.name as repo_name
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            WHERE bj.status IN ('queued', 'sent', 'running')
            ORDER BY bj.queued_at ASC
        ");

        $completed = $this->db->fetchAll("
            SELECT bj.*, a.name as agent_name, r.name as repo_name
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            WHERE bj.status IN ('completed', 'failed')
            ORDER BY bj.completed_at DESC
            LIMIT 25
        ");

        $this->json([
            'inProgress' => $inProgress,
            'completed' => $completed,
        ]);
    }

    public function detail(int $id): void
    {
        $this->requireAuth();

        $job = $this->db->fetchOne("
            SELECT bj.*, a.name as agent_name, a.id as agent_id,
                   a.status as agent_status, a.last_heartbeat,
                   r.name as repo_name, bp.name as plan_name,
                   bp.directories, bp.excludes, bp.advanced_options
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
            WHERE bj.id = ?
        ", [$id]);

        if (!$job) {
            $this->flash('danger', 'Job not found.');
            $this->redirect('/queue');
        }

        // Get log entries for this job
        $logs = $this->db->fetchAll("
            SELECT * FROM server_log
            WHERE backup_job_id = ?
            ORDER BY created_at ASC
        ", [$id]);

        // Queue context: active count, max queue, position
        $activeCount = $this->db->count('backup_jobs', "status IN ('sent', 'running')");
        $maxQueue = (int) ($this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'max_queue'")['value'] ?? 4);
        $queuePosition = null;
        if ($job['status'] === 'queued') {
            $pos = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM backup_jobs WHERE status = 'queued' AND queued_at <= ?", [$job['queued_at']]);
            $queuePosition = (int) $pos['cnt'];
        }
        $pollInterval = (int) ($this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'agent_poll_interval'")['value'] ?? 30);

        $this->view('queue/detail', [
            'pageTitle' => 'Job #' . $id,
            'job' => $job,
            'logs' => $logs,
            'activeCount' => $activeCount,
            'maxQueue' => $maxQueue,
            'queuePosition' => $queuePosition,
            'pollInterval' => $pollInterval,
        ]);
    }

    public function detailJson(int $id): void
    {
        $this->requireAuth();

        $job = $this->db->fetchOne("
            SELECT bj.*, a.name as agent_name, a.id as agent_id,
                   a.status as agent_status, a.last_heartbeat,
                   r.name as repo_name, bp.name as plan_name,
                   bp.directories, bp.excludes, bp.advanced_options
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
            WHERE bj.id = ?
        ", [$id]);

        if (!$job) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not found']);
            return;
        }

        $logs = $this->db->fetchAll("
            SELECT * FROM server_log
            WHERE backup_job_id = ?
            ORDER BY created_at ASC
        ", [$id]);

        $activeCount = $this->db->count('backup_jobs', "status IN ('sent', 'running')");
        $maxQueue = (int) ($this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'max_queue'")['value'] ?? 4);
        $queuePosition = null;
        if ($job['status'] === 'queued') {
            $pos = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM backup_jobs WHERE status = 'queued' AND queued_at <= ?", [$job['queued_at']]);
            $queuePosition = (int) $pos['cnt'];
        }

        header('Content-Type: application/json');
        echo json_encode([
            'job' => $job,
            'logs' => $logs,
            'activeCount' => $activeCount,
            'maxQueue' => $maxQueue,
            'queuePosition' => $queuePosition,
        ]);
    }

    public function cancel(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $job = $this->db->fetchOne("SELECT * FROM backup_jobs WHERE id = ?", [$id]);
        if (!$job || !in_array($job['status'], ['queued', 'sent'])) {
            $this->flash('danger', 'Job cannot be cancelled.');
            $this->redirect('/queue');
        }

        $this->db->update('backup_jobs', [
            'status' => 'failed',
            'error_log' => 'Cancelled by user',
            'completed_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);

        $this->db->insert('server_log', [
            'agent_id' => $job['agent_id'],
            'backup_job_id' => $id,
            'level' => 'warning',
            'message' => "Job #{$id} cancelled by user",
        ]);

        $this->flash('success', "Job #{$id} cancelled.");
        $this->redirect('/queue');
    }

    public function retry(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $job = $this->db->fetchOne("SELECT * FROM backup_jobs WHERE id = ? AND status = 'failed'", [$id]);
        if (!$job) {
            $this->flash('danger', 'Job cannot be retried.');
            $this->redirect('/queue');
        }

        // Create a new queued job based on the failed one
        $newJobId = $this->db->insert('backup_jobs', [
            'agent_id' => $job['agent_id'],
            'backup_plan_id' => $job['backup_plan_id'],
            'repository_id' => $job['repository_id'],
            'task_type' => $job['task_type'],
            'status' => 'queued',
            'queued_at' => date('Y-m-d H:i:s'),
            'restore_archive_id' => $job['restore_archive_id'],
            'restore_paths' => $job['restore_paths'],
            'restore_destination' => $job['restore_destination'],
            'restore_databases' => $job['restore_databases'],
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $job['agent_id'],
            'backup_job_id' => $newJobId,
            'level' => 'info',
            'message' => "Job #{$newJobId} queued (retry of #{$id})",
        ]);

        $this->flash('success', "Job #{$id} retried as #{$newJobId}.");
        $this->redirect('/queue');
    }
}
