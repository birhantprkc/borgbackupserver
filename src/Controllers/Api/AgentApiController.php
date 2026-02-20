<?php

namespace BBS\Controllers\Api;

use BBS\Core\Controller;
use BBS\Services\QueueManager;
use BBS\Services\BorgCommandBuilder;
use BBS\Services\Mailer;
use BBS\Services\NotificationService;
use BBS\Services\CatalogImporter;

class AgentApiController extends Controller
{
    /**
     * Authenticate the agent via Bearer token.
     * Returns the agent record or sends 401 and exits.
     */
    private function authenticateAgent(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = '';

        if (str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);
        }

        if (empty($token)) {
            $this->json(['error' => 'Missing authorization token'], 401);
        }

        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE api_key = ?", [$token]);

        if (!$agent) {
            // Rate limit failed API auth: 20 attempts per 5 minutes
            if (!$this->checkRateLimit('agent_api', 20, 300)) {
                $this->json(['error' => 'Too many failed attempts'], 429);
            }
            $this->json(['error' => 'Invalid API key'], 401);
        }

        // Update heartbeat on every authenticated request (use PHP date() to match app timezone)
        $this->db->query(
            "UPDATE agents SET last_heartbeat = ?, status = 'online' WHERE id = ?",
            [date('Y-m-d H:i:s'), $agent['id']]
        );

        // Auto-resolve agent_offline notification on heartbeat
        $notifService = new NotificationService();

        // Check if there was an unresolved agent_offline notification (means agent was offline)
        $wasOffline = $this->db->fetchOne(
            "SELECT id FROM notifications WHERE type = 'agent_offline' AND agent_id = ? AND resolved_at IS NULL",
            [$agent['id']]
        );

        $notifService->resolve('agent_offline', $agent['id'], null);

        // If agent was offline and is now back online, send agent_online notification
        if ($wasOffline) {
            $notifService->notify(
                'agent_online',
                $agent['id'],
                null,
                "Client \"{$agent['name']}\" is back online",
                'info'
            );
        }

        return $agent;
    }

    /**
     * POST /api/agent/register
     * One-time registration: agent sends its hostname, OS, borg version.
     */
    public function register(): void
    {
        $agent = $this->authenticateAgent();
        $input = $this->getJsonInput();

        $data = [];
        if (!empty($input['hostname']))             $data['hostname'] = substr($input['hostname'], 0, 255);
        if (!empty($input['ip_address']))           $data['ip_address'] = substr($input['ip_address'], 0, 45);
        if (!empty($input['os_info']))              $data['os_info'] = substr($input['os_info'], 0, 255);
        if (!empty($input['borg_version']))         $data['borg_version'] = substr($input['borg_version'], 0, 20);
        if (!empty($input['agent_version']))        $data['agent_version'] = substr($input['agent_version'], 0, 20);
        if (!empty($input['borg_install_method']))  $data['borg_install_method'] = substr($input['borg_install_method'], 0, 20);
        if (!empty($input['borg_binary_path']))     $data['borg_binary_path'] = substr($input['borg_binary_path'], 0, 255);
        if (!empty($input['glibc_version']))        $data['glibc_version'] = substr($input['glibc_version'], 0, 20);
        if (!empty($input['platform']))             $data['platform'] = substr($input['platform'], 0, 20);
        if (!empty($input['architecture']))         $data['architecture'] = substr($input['architecture'], 0, 20);
        $data['status'] = 'online';

        if (!empty($data)) {
            $this->db->update('agents', $data, 'id = ?', [$agent['id']]);
        }

        $this->db->insert('server_log', [
            'agent_id' => $agent['id'],
            'level' => 'info',
            'message' => "Agent registered: " . ($input['hostname'] ?? $agent['name']),
        ]);

        // Return server config the agent needs
        $pollInterval = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'agent_poll_interval'");

        $this->json([
            'status' => 'ok',
            'agent_id' => $agent['id'],
            'name' => $agent['name'],
            'poll_interval' => (int) ($pollInterval['value'] ?? 30),
        ]);
    }

    /**
     * GET /api/agent/tasks
     * Agent polls for pending tasks.
     */
    public function tasks(): void
    {
        $agent = $this->authenticateAgent();

        // Auto-queue borg update if agent is outdated and a target version is set
        $this->autoQueueBorgUpdate($agent);

        // First, run the queue manager to promote any queued jobs
        $queueManager = new QueueManager();
        $queueManager->processQueue();

        // Get tasks assigned to this agent
        $tasks = $queueManager->getTasksForAgent($agent['id']);

        $pollInterval = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'agent_poll_interval'");

        $this->json([
            'status' => 'ok',
            'tasks' => $tasks,
            'poll_interval' => (int) ($pollInterval['value'] ?? 30),
        ]);
    }

    /**
     * POST /api/agent/progress
     * Agent reports in-progress updates for a running job.
     */
    public function progress(): void
    {
        $agent = $this->authenticateAgent();
        $input = $this->getJsonInput();

        $jobId = (int) ($input['job_id'] ?? 0);
        if (!$jobId) {
            $this->json(['error' => 'job_id required'], 400);
        }

        // Verify job belongs to this agent
        $job = $this->db->fetchOne(
            "SELECT * FROM backup_jobs WHERE id = ? AND agent_id = ?",
            [$jobId, $agent['id']]
        );

        if (!$job) {
            $this->json(['error' => 'Job not found'], 404);
        }

        // Don't overwrite terminal statuses (completed/failed) with 'running'
        if (in_array($job['status'], ['completed', 'failed', 'cancelled'])) {
            $this->json(['status' => 'ok']);
        }

        $data = ['status' => 'running'];
        if (isset($input['files_total']))      $data['files_total'] = (int) $input['files_total'];
        if (isset($input['files_processed']))  $data['files_processed'] = (int) $input['files_processed'];
        if (isset($input['bytes_total']))      $data['bytes_total'] = (int) $input['bytes_total'];
        if (isset($input['bytes_processed']))  $data['bytes_processed'] = (int) $input['bytes_processed'];
        if (isset($input['status_message']))   $data['status_message'] = substr($input['status_message'], 0, 255);

        if (empty($job['started_at'])) {
            $data['started_at'] = date('Y-m-d H:i:s');

            // Log that the agent started working on this job
            $planName = '';
            if ($job['backup_plan_id']) {
                $plan = $this->db->fetchOne("SELECT name FROM backup_plans WHERE id = ?", [$job['backup_plan_id']]);
                $planName = $plan['name'] ?? '';
            }
            $this->db->insert('server_log', [
                'agent_id' => $agent['id'],
                'backup_job_id' => $jobId,
                'level' => 'info',
                'message' => "Agent started {$job['task_type']} job #{$jobId}" . ($planName ? " for plan \"{$planName}\"" : ''),
            ]);
        }

        $this->db->update('backup_jobs', $data, 'id = ?', [$jobId]);

        // Allow agent to send log messages (e.g. plugin activity)
        if (!empty($input['log_message'])) {
            $this->db->insert('server_log', [
                'agent_id' => $agent['id'],
                'backup_job_id' => $jobId,
                'level' => $input['log_level'] ?? 'info',
                'message' => substr($input['log_message'], 0, 2000),
            ]);
        }

        $this->json(['status' => 'ok']);
    }

    /**
     * POST /api/agent/status
     * Agent reports task completion or failure.
     */
    public function status(): void
    {
        $agent = $this->authenticateAgent();
        $input = $this->getJsonInput();

        $jobId = (int) ($input['job_id'] ?? 0);
        $result = $input['result'] ?? '';  // 'completed', 'failed', or 'cataloging'

        if (!$jobId || !in_array($result, ['completed', 'failed', 'cataloging'])) {
            $this->json(['error' => 'job_id and result (completed/failed/cataloging) required'], 400);
        }

        $job = $this->db->fetchOne(
            "SELECT * FROM backup_jobs WHERE id = ? AND agent_id = ?",
            [$jobId, $agent['id']]
        );

        if (!$job) {
            $this->json(['error' => 'Job not found'], 404);
        }

        $now = date('Y-m-d H:i:s');
        $startedAt = $job['started_at'] ?? $job['queued_at'] ?? $now;
        $duration = strtotime($now) - strtotime($startedAt);

        // "cataloging" means borg finished but agent is about to upload catalog —
        // keep job as "running" so it doesn't appear completed prematurely
        $isCataloging = ($result === 'cataloging');

        // Check if a catalog file exists that needs importing — if so, defer
        // marking the job as completed until after the import finishes
        $hasPendingCatalog = false;
        if ($result === 'completed' && $job['task_type'] === 'backup') {
            $sp = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
            if ($sp && !empty($sp['value'])) {
                $cp = rtrim($sp['value'], '/') . '/' . $agent['id']
                    . '/.catalog-logs/catalog-' . $jobId . '.jsonl';
                $hasPendingCatalog = file_exists($cp) && filesize($cp) > 0;
            }
        }

        $data = [
            'status' => ($isCataloging || $hasPendingCatalog) ? 'running' : $result,
        ];

        if ($hasPendingCatalog) {
            $data['status_message'] = 'Importing file catalog...';
        } elseif (!$isCataloging) {
            $data['completed_at'] = $now;
            $data['duration_seconds'] = max(0, $duration);
            $data['status_message'] = null;
        }

        // If the agent never reported "running", backfill started_at
        if (empty($job['started_at'])) {
            $data['started_at'] = $now;
        }

        if (isset($input['files_total']))     $data['files_total'] = (int) $input['files_total'];
        if (isset($input['files_processed'])) $data['files_processed'] = (int) $input['files_processed'];
        if (isset($input['bytes_total']))     $data['bytes_total'] = (int) $input['bytes_total'];
        if (isset($input['bytes_processed'])) $data['bytes_processed'] = (int) $input['bytes_processed'];
        if (!empty($input['error_log']))      $data['error_log'] = $input['error_log'];

        $this->db->update('backup_jobs', $data, 'id = ?', [$jobId]);

        $taskLabel = ucfirst(str_replace('_', ' ', $job['task_type']));

        // For "cataloging", create the archive and return archive_id but skip
        // notifications, prune, and completion logging
        if ($isCataloging && $job['task_type'] === 'backup' && !empty($input['archive_name'])) {
            $archiveData = [
                'repository_id' => $job['repository_id'],
                'backup_job_id' => $jobId,
                'archive_name' => $input['archive_name'],
                'file_count' => (int) ($input['files_total'] ?? 0),
                'original_size' => (int) ($input['original_size'] ?? 0),
                'deduplicated_size' => (int) ($input['deduplicated_size'] ?? 0),
            ];

            if (!empty($input['databases_backed_up'])) {
                $archiveData['databases_backed_up'] = json_encode($input['databases_backed_up']);
            }

            $this->db->insert('archives', $archiveData);

            $origSize = $this->formatBytesLog((int)($input['original_size'] ?? 0));
            $dedupSize = $this->formatBytesLog((int)($input['deduplicated_size'] ?? 0));
            $this->db->insert('server_log', [
                'agent_id' => $agent['id'],
                'backup_job_id' => $jobId,
                'level' => 'info',
                'message' => "Archive created: \"{$input['archive_name']}\" — {$origSize} original, {$dedupSize} deduplicated",
            ]);

            // Update repo stats + borg version
            $borgVer = !empty($agent['borg_version']) ? preg_replace('/^borg\s+/', '', $agent['borg_version']) : null;
            $this->db->query("
                UPDATE repositories SET
                    archive_count = (SELECT COUNT(*) FROM archives WHERE repository_id = ?),
                    size_bytes = COALESCE((SELECT SUM(deduplicated_size) FROM archives WHERE repository_id = ?), 0)
                    " . ($borgVer ? ", borg_version_last = ?" : "") . "
                WHERE id = ?
            ", $borgVer
                ? [$job['repository_id'], $job['repository_id'], $borgVer, $job['repository_id']]
                : [$job['repository_id'], $job['repository_id'], $job['repository_id']]
            );

            $this->db->insert('server_log', [
                'agent_id' => $agent['id'],
                'backup_job_id' => $jobId,
                'level' => 'info',
                'message' => "{$taskLabel} finished, uploading file catalog...",
            ]);

            $archive = $this->db->fetchOne(
                "SELECT id FROM archives WHERE backup_job_id = ?",
                [$jobId]
            );

            $this->json(['status' => 'ok', 'archive_id' => $archive ? (int) $archive['id'] : null]);
            return;
        }

        // For cataloging without archive data, just acknowledge
        if ($isCataloging) {
            $this->json(['status' => 'ok', 'archive_id' => null]);
            return;
        }

        // --- Normal completed/failed flow below ---

        // Log the result
        $level = $result === 'completed' ? 'info' : 'error';
        $message = $result === 'completed'
            ? "{$taskLabel} completed: job #{$jobId}" . (($data['files_total'] ?? 0) > 0 ? ", {$data['files_total']} files" : '') . ", {$duration}s"
            : "{$taskLabel} failed: job #{$jobId} — " . ($input['error_log'] ?? 'unknown error');

        $this->db->insert('server_log', [
            'agent_id' => $agent['id'],
            'backup_job_id' => $jobId,
            'level' => $level,
            'message' => $message,
        ]);

        // Log output from tasks like update_borg
        if (!empty($input['output_log'])) {
            $this->db->insert('server_log', [
                'agent_id' => $agent['id'],
                'backup_job_id' => $jobId,
                'level' => 'info',
                'message' => "{$taskLabel} output: " . substr($input['output_log'], 0, 2000),
            ]);
        }

        // Notification system: task-based notifications
        $notificationService = new NotificationService();
        $planName = '';
        if ($job['backup_plan_id']) {
            $plan = $this->db->fetchOne("SELECT name FROM backup_plans WHERE id = ?", [$job['backup_plan_id']]);
            $planName = $plan['name'] ?? '';
        }

        // Handle notifications by task type
        switch ($job['task_type']) {
            case 'backup':
                if ($result === 'failed') {
                    $notificationService->notify(
                        'backup_failed',
                        $agent['id'],
                        $job['backup_plan_id'] ? (int)$job['backup_plan_id'] : null,
                        "Backup failed for plan \"{$planName}\" on client \"{$agent['name']}\" — " . ($input['error_log'] ?? 'unknown error'),
                        'critical'
                    );
                } elseif ($result === 'completed' && $job['backup_plan_id']) {
                    $notificationService->resolve('backup_failed', $agent['id'], (int)$job['backup_plan_id']);
                    $notificationService->notify(
                        'backup_completed',
                        $agent['id'],
                        (int)$job['backup_plan_id'],
                        "Backup completed for plan \"{$planName}\" on client \"{$agent['name']}\"" .
                            (($data['files_total'] ?? 0) > 0 ? " — {$data['files_total']} files in {$duration}s" : ''),
                        'info'
                    );
                }
                break;

            case 'restore':
                if ($result === 'failed') {
                    $notificationService->notify(
                        'restore_failed',
                        $agent['id'],
                        $job['backup_plan_id'] ? (int)$job['backup_plan_id'] : null,
                        "Restore failed on client \"{$agent['name']}\"" . ($planName ? " for plan \"{$planName}\"" : '') .
                            " — " . ($input['error_log'] ?? 'unknown error'),
                        'critical'
                    );
                } elseif ($result === 'completed') {
                    $notificationService->notify(
                        'restore_completed',
                        $agent['id'],
                        $job['backup_plan_id'] ? (int)$job['backup_plan_id'] : null,
                        "Restore completed on client \"{$agent['name']}\"" . ($planName ? " for plan \"{$planName}\"" : ''),
                        'info'
                    );
                }
                break;

            case 'check':
                if ($result === 'failed') {
                    $notificationService->notify(
                        'repo_check_failed',
                        $agent['id'],
                        $job['repository_id'] ? (int)$job['repository_id'] : null,
                        "Repository check failed on client \"{$agent['name']}\"" . ($planName ? " for plan \"{$planName}\"" : '') .
                            " — " . ($input['error_log'] ?? 'unknown error'),
                        'critical'
                    );
                }
                break;

            case 'compact':
                if ($result === 'completed') {
                    $notificationService->notify(
                        'repo_compact_done',
                        $agent['id'],
                        $job['repository_id'] ? (int)$job['repository_id'] : null,
                        "Repository compact completed on client \"{$agent['name']}\"" . ($planName ? " for plan \"{$planName}\"" : ''),
                        'info'
                    );
                }
                break;
        }

        // Email notification on failure
        if ($result === 'failed') {
            try {
                $mailer = new Mailer();
                $mailer->notifyFailure($agent['name'], $jobId, $input['error_log'] ?? 'Unknown error');
            } catch (\Exception $e) {
                // Don't fail the status report if email fails
            }
        }

        // If completed and it was a backup, create an archive record
        // (skip if archive was already created during "cataloging" phase)
        if ($result === 'completed' && $job['task_type'] === 'backup' && !empty($input['archive_name'])) {
            $existingArchive = $this->db->fetchOne(
                "SELECT id FROM archives WHERE backup_job_id = ?",
                [$jobId]
            );
            if (!$existingArchive) {
                $archiveData = [
                    'repository_id' => $job['repository_id'],
                    'backup_job_id' => $jobId,
                    'archive_name' => $input['archive_name'],
                    'file_count' => (int) ($input['files_total'] ?? 0),
                    'original_size' => (int) ($input['original_size'] ?? 0),
                    'deduplicated_size' => (int) ($input['deduplicated_size'] ?? 0),
                ];

                if (!empty($input['databases_backed_up'])) {
                    $archiveData['databases_backed_up'] = json_encode($input['databases_backed_up']);
                }

                $this->db->insert('archives', $archiveData);

                // Log archive creation
                $origSize = $this->formatBytesLog((int)($input['original_size'] ?? 0));
                $dedupSize = $this->formatBytesLog((int)($input['deduplicated_size'] ?? 0));
                $this->db->insert('server_log', [
                    'agent_id' => $agent['id'],
                    'backup_job_id' => $jobId,
                    'level' => 'info',
                    'message' => "Archive created: \"{$input['archive_name']}\" — {$origSize} original, {$dedupSize} deduplicated",
                ]);

                // Update repo stats + borg version
                $borgVer2 = !empty($agent['borg_version']) ? preg_replace('/^borg\s+/', '', $agent['borg_version']) : null;
                $this->db->query("
                    UPDATE repositories SET
                        archive_count = (SELECT COUNT(*) FROM archives WHERE repository_id = ?),
                        size_bytes = COALESCE((SELECT SUM(deduplicated_size) FROM archives WHERE repository_id = ?), 0)
                        " . ($borgVer2 ? ", borg_version_last = ?" : "") . "
                    WHERE id = ?
                ", $borgVer2
                    ? [$job['repository_id'], $job['repository_id'], $borgVer2, $job['repository_id']]
                    : [$job['repository_id'], $job['repository_id'], $job['repository_id']]
                );

                $this->db->insert('server_log', [
                    'agent_id' => $agent['id'],
                    'backup_job_id' => $jobId,
                    'level' => 'info',
                    'message' => "Repository stats updated for job #{$jobId}",
                ]);
            }
        }

        // Build catalog import info for after response is sent
        $catalogImport = null;
        if ($result === 'completed' && $job['task_type'] === 'backup') {
            $storagePath = $this->db->fetchOne(
                "SELECT `value` FROM settings WHERE `key` = 'storage_path'"
            );
            if ($storagePath && !empty($storagePath['value'])) {
                $catalogPath = rtrim($storagePath['value'], '/') . '/' . $agent['id']
                             . '/.catalog-logs/catalog-' . $jobId . '.jsonl';
                if (file_exists($catalogPath)) {
                    $archive = $this->db->fetchOne(
                        "SELECT id FROM archives WHERE backup_job_id = ?",
                        [$jobId]
                    );
                    if ($archive) {
                        $catalogImport = [
                            'agent_id' => (int) $agent['id'],
                            'archive_id' => (int) $archive['id'],
                            'job_id' => $jobId,
                            'path' => $catalogPath,
                        ];
                    }
                }
            }
        }

        // Auto-prune is deferred until after catalog import (see below)
        $shouldAutoPrune = $result === 'completed' && $job['task_type'] === 'backup'
            && $job['backup_plan_id'] && $job['repository_id'];

        // Return archive_id so older agents can still send catalog after completion
        $archiveId = null;
        if ($result === 'completed' && $job['task_type'] === 'backup') {
            $archive = $this->db->fetchOne(
                "SELECT id FROM archives WHERE backup_job_id = ?",
                [$jobId]
            );
            $archiveId = $archive ? (int) $archive['id'] : null;
        }

        // Send response immediately so the agent isn't blocked
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'archive_id' => $archiveId]);

        // Flush response to agent, then continue processing in background
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Import catalog file after response is sent (can take minutes for large catalogs)
        if ($catalogImport) {
            set_time_limit(0);
            $fileSize = filesize($catalogImport['path']);
            $fileSizeLabel = $fileSize > 1048576
                ? round($fileSize / 1048576, 1) . ' MB'
                : round($fileSize / 1024, 1) . ' KB';
            $this->db->insert('server_log', [
                'agent_id' => $catalogImport['agent_id'],
                'backup_job_id' => $catalogImport['job_id'],
                'level' => 'info',
                'message' => "Importing file catalog ({$fileSizeLabel})...",
            ]);
            try {
                $startTime = microtime(true);
                $importer = new CatalogImporter();
                $count = $importer->processFile(
                    $this->db, $catalogImport['agent_id'], $catalogImport['archive_id'], $catalogImport['path'], $catalogImport['job_id']
                );
                $elapsed = round(microtime(true) - $startTime, 1);
                $this->db->insert('server_log', [
                    'agent_id' => $catalogImport['agent_id'],
                    'backup_job_id' => $catalogImport['job_id'],
                    'level' => 'info',
                    'message' => "File catalog imported: " . number_format($count) . " entries in {$elapsed}s",
                ]);
            } catch (\Exception $e) {
                $this->db->insert('server_log', [
                    'agent_id' => $catalogImport['agent_id'],
                    'backup_job_id' => $catalogImport['job_id'],
                    'level' => 'error',
                    'message' => "Catalog import failed: " . $e->getMessage(),
                ]);
            }
            @unlink($catalogImport['path']);

            // Now mark the job as completed (was kept as 'running' during import)
            $this->db->update('backup_jobs', [
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
                'duration_seconds' => max(0, strtotime(date('Y-m-d H:i:s')) - strtotime($startedAt)),
                'status_message' => null,
            ], 'id = ?', [$jobId]);
        }

        // Auto-queue prune AFTER catalog import is complete to avoid table lock contention
        if ($shouldAutoPrune) {
            $existingPrune = $this->db->fetchOne(
                "SELECT id FROM backup_jobs
                 WHERE backup_plan_id = ? AND task_type = 'prune' AND status IN ('queued', 'sent', 'running')
                 LIMIT 1",
                [$job['backup_plan_id']]
            );
            if (!$existingPrune) {
                $pruneJobId = $this->db->insert('backup_jobs', [
                    'backup_plan_id' => $job['backup_plan_id'],
                    'agent_id' => $job['agent_id'],
                    'repository_id' => $job['repository_id'],
                    'task_type' => 'prune',
                    'status' => 'queued',
                ]);
                $this->db->insert('server_log', [
                    'agent_id' => $agent['id'],
                    'backup_job_id' => $pruneJobId,
                    'level' => 'info',
                    'message' => "Auto-prune queued (job #{$pruneJobId}) after backup job #{$jobId}",
                ]);
            }
        }

        exit;
    }

    /**
     * POST /api/agent/catalog
     * Agent sends file catalog entries after a successful backup.
     * Accepts batches: { archive_id, files: [{ path, size, status, mtime }, ...] }
     */
    public function catalog(): void
    {
        $agent = $this->authenticateAgent();
        $input = $this->getJsonInput();

        $archiveId = (int) ($input['archive_id'] ?? 0);
        $files = $input['files'] ?? [];

        if (!$archiveId || empty($files)) {
            $this->json(['error' => 'archive_id and files[] required'], 400);
        }

        // Verify archive exists
        $archive = $this->db->fetchOne("SELECT id FROM archives WHERE id = ?", [$archiveId]);
        if (!$archive) {
            $this->json(['error' => 'Archive not found'], 404);
        }

        $agentId = (int) $agent['id'];
        $ch = \BBS\Core\ClickHouse::getInstance();

        // Build TSV and upload to ClickHouse
        $escape = fn(string $s) => str_replace(["\t", "\n", "\\"], ["\\t", "\\n", "\\\\"], $s);
        $tsvFile = sys_get_temp_dir() . "/catalog_api_{$agentId}_{$archiveId}_" . getmypid() . '.tsv';
        $fh = fopen($tsvFile, 'w');
        $batchSize = 0;

        if ($fh) {
            foreach ($files as $file) {
                $path = $file['path'] ?? '';
                if (empty($path)) continue;

                $status = substr($file['status'] ?? 'U', 0, 1);
                $mtime = $file['mtime'] ?? '\\N';
                fwrite($fh, "{$agentId}\t{$archiveId}\t{$escape($path)}\t{$escape(basename($path))}\t{$escape(dirname($path))}\t" . (int) ($file['size'] ?? 0) . "\t{$status}\t{$mtime}\n");
                $batchSize++;
            }
            fclose($fh);

            if ($batchSize > 0) {
                try {
                    $ch->insertTsv('file_catalog', $tsvFile, [
                        'agent_id', 'archive_id', 'path', 'file_name', 'parent_dir', 'file_size', 'status', 'mtime'
                    ]);
                } finally {
                    @unlink($tsvFile);
                }
            } else {
                @unlink($tsvFile);
            }
        }

        $isDone = !empty($input['done']);

        if ($isDone) {
            $archiveRow = $this->db->fetchOne("SELECT backup_job_id FROM archives WHERE id = ?", [$archiveId]);
            $logJobId = $archiveRow['backup_job_id'] ?? null;

            $totalRow = $ch->fetchOne(
                "SELECT count() as cnt FROM file_catalog WHERE agent_id = ? AND archive_id = ?",
                [$agentId, $archiveId]
            );
            $totalIndexed = (int) ($totalRow['cnt'] ?? 0);

            // Build catalog_dirs index from ClickHouse data
            $this->buildDirsFromCatalog($agentId, $archiveId);

            $this->db->insert('server_log', [
                'agent_id' => $agentId,
                'backup_job_id' => $logJobId,
                'level' => 'info',
                'message' => "File catalog indexed: {$totalIndexed} entries for archive #{$archiveId}",
            ]);
        }

        $this->json(['status' => 'ok', 'inserted' => $batchSize]);
    }

    /**
     * Build catalog_dirs index from file_catalog data in ClickHouse.
     */
    private function buildDirsFromCatalog(int $agentId, int $archiveId): void
    {
        $ch = \BBS\Core\ClickHouse::getInstance();

        // Remove old dir entries for this archive
        try {
            $ch->exec("ALTER TABLE catalog_dirs DELETE WHERE agent_id = {$agentId} AND archive_id = {$archiveId} SETTINGS mutations_sync = 1");
        } catch (\Exception $e) { /* ignore */ }

        // Get dir stats grouped by parent_dir from ClickHouse
        $dirRows = $ch->fetchAll("
            SELECT parent_dir, count() as file_count, sum(file_size) as total_size
            FROM file_catalog
            WHERE agent_id = ? AND archive_id = ? AND status != 'D'
            GROUP BY parent_dir
        ", [$agentId, $archiveId]);

        $allDirs = [];
        foreach ($dirRows as $d) {
            $dirPath = $d['parent_dir'];
            if ($dirPath === '' || $dirPath === '/') continue;

            if (!isset($allDirs[$dirPath])) {
                $allDirs[$dirPath] = [0, 0];
            }
            $allDirs[$dirPath][0] += (int) $d['file_count'];
            $allDirs[$dirPath][1] += (int) $d['total_size'];

            // Walk up ancestors
            $p = dirname($dirPath);
            while ($p !== '/' && $p !== '.' && !isset($allDirs[$p])) {
                $allDirs[$p] = [0, 0];
                $p = dirname($p);
            }
        }

        if (empty($allDirs)) return;

        // Write dirs TSV and upload to ClickHouse
        $escape = fn(string $s) => str_replace(["\t", "\n", "\\"], ["\\t", "\\n", "\\\\"], $s);
        $dirsTsv = sys_get_temp_dir() . "/catalog_dirs_api_{$agentId}_{$archiveId}_" . getmypid() . '.tsv';
        $fh = fopen($dirsTsv, 'w');
        if (!$fh) return;

        foreach ($allDirs as $dirPath => [$fc, $sz]) {
            $parent = dirname($dirPath);
            if ($parent === '.') $parent = '/';
            $name = basename($dirPath);
            fwrite($fh, "{$agentId}\t{$archiveId}\t{$escape($dirPath)}\t{$escape($parent)}\t{$escape($name)}\t{$fc}\t{$sz}\n");
        }
        fclose($fh);

        try {
            $ch->insertTsv('catalog_dirs', $dirsTsv, [
                'agent_id', 'archive_id', 'dir_path', 'parent_dir', 'name', 'file_count', 'total_size'
            ]);
        } catch (\Exception $e) { /* ignore */ }
        @unlink($dirsTsv);
    }

    public function heartbeat(): void
    {
        $agent = $this->authenticateAgent();
        $this->json([
            'status' => 'ok',
            'agent_id' => $agent['id'],
            'server_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * POST /api/agent/info
     * Agent reports system information (OS, borg version, disk usage).
     */
    public function info(): void
    {
        $agent = $this->authenticateAgent();
        $input = $this->getJsonInput();

        $data = [];
        if (!empty($input['os_info']))              $data['os_info'] = substr($input['os_info'], 0, 255);
        if (!empty($input['borg_version']))         $data['borg_version'] = substr($input['borg_version'], 0, 20);
        if (!empty($input['agent_version']))        $data['agent_version'] = substr($input['agent_version'], 0, 20);
        if (!empty($input['hostname']))             $data['hostname'] = substr($input['hostname'], 0, 255);
        if (!empty($input['ip_address']))           $data['ip_address'] = substr($input['ip_address'], 0, 45);
        if (!empty($input['borg_install_method']))  $data['borg_install_method'] = substr($input['borg_install_method'], 0, 20);
        if (!empty($input['borg_source']))          $data['borg_source'] = substr($input['borg_source'], 0, 20);
        if (!empty($input['borg_binary_path']))     $data['borg_binary_path'] = substr($input['borg_binary_path'], 0, 255);
        if (!empty($input['glibc_version']))        $data['glibc_version'] = substr($input['glibc_version'], 0, 20);
        if (!empty($input['platform']))             $data['platform'] = substr($input['platform'], 0, 20);
        if (!empty($input['architecture']))         $data['architecture'] = substr($input['architecture'], 0, 20);

        if (!empty($data)) {
            $this->db->update('agents', $data, 'id = ?', [$agent['id']]);
        }

        $this->json(['status' => 'ok']);
    }

    /**
     * GET /api/agent/ssh-key
     * Agent downloads its SSH private key for borg SSH access.
     */
    public function sshKey(): void
    {
        $agent = $this->authenticateAgent();

        if (empty($agent['ssh_private_key_encrypted'])) {
            $this->json(['error' => 'No SSH key provisioned for this agent'], 404);
        }

        try {
            $privateKey = \BBS\Services\Encryption::decrypt($agent['ssh_private_key_encrypted']);
        } catch (\Exception $e) {
            $this->json(['error' => 'Failed to decrypt SSH key'], 500);
        }

        // Return server_host and ssh_port settings so the agent knows how to connect
        $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
        $sshPort = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'ssh_port'");

        $this->json([
            'status' => 'ok',
            'ssh_private_key' => $privateKey,
            'ssh_unix_user' => $agent['ssh_unix_user'],
            'server_host' => $serverHost['value'] ?? '',
            'ssh_port' => (int) ($sshPort['value'] ?? 22),
        ]);
    }

    /**
     * Serve agent files (install.sh, bbs-agent.py, plist) from outside public/.
     */
    public function downloadFile(): void
    {
        $allowed = [
            'install.sh' => 'agent/install.sh',
            'bbs-agent.py' => 'agent/bbs-agent.py',
            'com.borgbackupserver.agent.plist' => 'agent/com.borgbackupserver.agent.plist',
            'uninstall.sh' => 'agent/uninstall.sh',
        ];

        $filename = $_GET['file'] ?? '';
        if (!isset($allowed[$filename])) {
            http_response_code(404);
            echo "Not found";
            exit;
        }

        $path = dirname(__DIR__, 3) . '/' . $allowed[$filename];
        if (!file_exists($path)) {
            http_response_code(404);
            echo "Not found";
            exit;
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        readfile($path);
        exit;
    }

    /**
     * Shorthand route: /get-agent → serves install.sh by default.
     */
    public function getAgent(): void
    {
        $_GET['file'] = $_GET['file'] ?? 'install.sh';
        $this->downloadFile();
    }

    /**
     * Parse JSON request body.
     */
    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function formatBytesLog(int $bytes): string
    {
        if ($bytes == 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
        return round($bytes, $i > 0 ? 1 : 0) . ' ' . $units[$i];
    }

    /**
     * Auto-queue a borg update job if the agent is outdated relative to the target version
     * and a compatible binary exists (GitHub or server-hosted fallback).
     */
    private function autoQueueBorgUpdate(array $agent): void
    {
        $borgService = new \BBS\Services\BorgVersionService();

        // Skip if auto-update is disabled
        if (!$borgService->isAutoUpdateEnabled()) {
            return;
        }

        // Skip if no platform info yet (agent hasn't registered fully)
        $platform = $agent['platform'] ?? null;
        $arch = $agent['architecture'] ?? null;
        if (!$platform || !$arch) {
            return;
        }

        // Don't queue if there's already a pending/running borg update
        $existing = $this->db->fetchOne(
            "SELECT id FROM backup_jobs WHERE agent_id = ? AND task_type = 'update_borg' AND status IN ('queued', 'sent', 'running')",
            [$agent['id']]
        );
        if ($existing) {
            return;
        }

        // Get best available version for this agent
        $best = $borgService->getBestVersionForAgent($agent);
        if (!$best) {
            return; // No compatible binary available
        }

        // Check if agent already has this version or newer (but < 2.0)
        $agentBorgVer = preg_replace('/^borg\s+/', '', $agent['borg_version'] ?? '');
        if (!empty($agentBorgVer) && version_compare($agentBorgVer, $best['version'], '>=')) {
            return; // Already up to date
        }

        // Queue the update
        $this->db->insert('backup_jobs', [
            'agent_id' => $agent['id'],
            'task_type' => 'update_borg',
            'status' => 'queued',
        ]);
    }
}
