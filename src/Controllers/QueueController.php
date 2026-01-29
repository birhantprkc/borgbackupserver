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

        $this->view('queue/index', [
            'pageTitle' => 'Queue',
            'inProgress' => $inProgress,
            'completed' => $completed,
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
