<?php

namespace BBS\Controllers\Api;

use BBS\Core\Controller;
use BBS\Services\QueueManager;
use BBS\Services\BorgCommandBuilder;
use BBS\Services\Mailer;
use BBS\Services\NotificationService;

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

        // Update heartbeat on every authenticated request
        $this->db->update('agents', [
            'last_heartbeat' => date('Y-m-d H:i:s'),
            'status' => 'online',
        ], 'id = ?', [$agent['id']]);

        // Auto-resolve agent_offline notification on heartbeat
        (new NotificationService())->resolve('agent_offline', $agent['id'], null);

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
        if (!empty($input['hostname']))      $data['hostname'] = substr($input['hostname'], 0, 255);
        if (!empty($input['ip_address']))     $data['ip_address'] = substr($input['ip_address'], 0, 45);
        if (!empty($input['os_info']))        $data['os_info'] = substr($input['os_info'], 0, 255);
        if (!empty($input['borg_version']))   $data['borg_version'] = substr($input['borg_version'], 0, 20);
        if (!empty($input['agent_version']))  $data['agent_version'] = substr($input['agent_version'], 0, 20);
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

        // First, run the queue manager to promote any queued jobs
        $queueManager = new QueueManager();
        $queueManager->processQueue();

        // Get tasks assigned to this agent
        $tasks = $queueManager->getTasksForAgent($agent['id']);

        $this->json([
            'status' => 'ok',
            'tasks' => $tasks,
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

        $data = ['status' => 'running'];
        if (isset($input['files_total']))     $data['files_total'] = (int) $input['files_total'];
        if (isset($input['files_processed'])) $data['files_processed'] = (int) $input['files_processed'];
        if (isset($input['bytes_total']))     $data['bytes_total'] = (int) $input['bytes_total'];
        if (isset($input['bytes_processed'])) $data['bytes_processed'] = (int) $input['bytes_processed'];

        if (empty($job['started_at'])) {
            $data['started_at'] = date('Y-m-d H:i:s');
        }

        $this->db->update('backup_jobs', $data, 'id = ?', [$jobId]);

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
        $result = $input['result'] ?? '';  // 'completed' or 'failed'

        if (!$jobId || !in_array($result, ['completed', 'failed'])) {
            $this->json(['error' => 'job_id and result (completed/failed) required'], 400);
        }

        $job = $this->db->fetchOne(
            "SELECT * FROM backup_jobs WHERE id = ? AND agent_id = ?",
            [$jobId, $agent['id']]
        );

        if (!$job) {
            $this->json(['error' => 'Job not found'], 404);
        }

        $now = date('Y-m-d H:i:s');
        $startedAt = $job['started_at'] ?? $now;
        $duration = strtotime($now) - strtotime($startedAt);

        $data = [
            'status' => $result,
            'completed_at' => $now,
            'duration_seconds' => max(0, $duration),
        ];

        if (isset($input['files_total']))     $data['files_total'] = (int) $input['files_total'];
        if (isset($input['files_processed'])) $data['files_processed'] = (int) $input['files_processed'];
        if (isset($input['bytes_total']))     $data['bytes_total'] = (int) $input['bytes_total'];
        if (isset($input['bytes_processed'])) $data['bytes_processed'] = (int) $input['bytes_processed'];
        if (!empty($input['error_log']))      $data['error_log'] = $input['error_log'];

        $this->db->update('backup_jobs', $data, 'id = ?', [$jobId]);

        // Log the result
        $level = $result === 'completed' ? 'info' : 'error';
        $message = $result === 'completed'
            ? "Backup completed: job #{$jobId}, {$data['files_total']} files, {$duration}s"
            : "Backup failed: job #{$jobId} — " . ($input['error_log'] ?? 'unknown error');

        $this->db->insert('server_log', [
            'agent_id' => $agent['id'],
            'backup_job_id' => $jobId,
            'level' => $level,
            'message' => $message,
        ]);

        // Notification system: backup failed / resolved
        $notificationService = new NotificationService();
        if ($result === 'failed' && $job['task_type'] === 'backup') {
            $planName = '';
            if ($job['backup_plan_id']) {
                $plan = $this->db->fetchOne("SELECT name FROM backup_plans WHERE id = ?", [$job['backup_plan_id']]);
                $planName = $plan['name'] ?? '';
            }
            $notificationService->notify(
                'backup_failed',
                $agent['id'],
                $job['backup_plan_id'] ? (int)$job['backup_plan_id'] : null,
                "Backup failed for plan \"{$planName}\" on client \"{$agent['name']}\" — " . ($input['error_log'] ?? 'unknown error'),
                'critical'
            );
        } elseif ($result === 'completed' && $job['task_type'] === 'backup' && $job['backup_plan_id']) {
            $notificationService->resolve('backup_failed', $agent['id'], (int)$job['backup_plan_id']);
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
        if ($result === 'completed' && $job['task_type'] === 'backup' && !empty($input['archive_name'])) {
            $this->db->insert('archives', [
                'repository_id' => $job['repository_id'],
                'backup_job_id' => $jobId,
                'archive_name' => $input['archive_name'],
                'file_count' => (int) ($input['files_total'] ?? 0),
                'original_size' => (int) ($input['original_size'] ?? 0),
                'deduplicated_size' => (int) ($input['deduplicated_size'] ?? 0),
            ]);

            // Update repo stats
            $this->db->query("
                UPDATE repositories SET
                    archive_count = (SELECT COUNT(*) FROM archives WHERE repository_id = ?),
                    size_bytes = COALESCE((SELECT SUM(deduplicated_size) FROM archives WHERE repository_id = ?), 0)
                WHERE id = ?
            ", [$job['repository_id'], $job['repository_id'], $job['repository_id']]);
        }

        // Return archive_id so agent can send catalog
        $archiveId = null;
        if ($result === 'completed' && $job['task_type'] === 'backup' && !empty($input['archive_name'])) {
            $archive = $this->db->fetchOne(
                "SELECT id FROM archives WHERE backup_job_id = ?",
                [$jobId]
            );
            $archiveId = $archive ? (int) $archive['id'] : null;
        }

        $this->json(['status' => 'ok', 'archive_id' => $archiveId]);
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

        $agentId = $agent['id'];

        // Step 1: Upsert unique paths into file_paths
        $pathPlaceholders = [];
        $pathValues = [];
        $paths = [];
        foreach ($files as $file) {
            $path = $file['path'] ?? '';
            if (empty($path) || isset($paths[$path])) continue;
            $paths[$path] = true;
            $pathPlaceholders[] = '(?, ?, ?)';
            $pathValues[] = $agentId;
            $pathValues[] = $path;
            $pathValues[] = basename($path);
        }

        if (!empty($pathPlaceholders)) {
            $sql = "INSERT IGNORE INTO file_paths (agent_id, path, file_name) VALUES "
                 . implode(', ', $pathPlaceholders);
            $this->db->query($sql, $pathValues);
        }

        // Step 2: Fetch IDs for all paths in this batch
        $pathKeys = array_keys($paths);
        $inPlaceholders = implode(',', array_fill(0, count($pathKeys), '?'));
        $rows = $this->db->fetchAll(
            "SELECT id, path FROM file_paths WHERE agent_id = ? AND path IN ({$inPlaceholders})",
            array_merge([$agentId], $pathKeys)
        );
        $pathIdMap = [];
        foreach ($rows as $row) {
            $pathIdMap[$row['path']] = $row['id'];
        }

        // Step 3: Insert into file_catalog junction table
        $catalogPlaceholders = [];
        $catalogValues = [];
        foreach ($files as $file) {
            $path = $file['path'] ?? '';
            if (empty($path) || !isset($pathIdMap[$path])) continue;

            $catalogPlaceholders[] = '(?, ?, ?, ?, ?)';
            $catalogValues[] = $archiveId;
            $catalogValues[] = $pathIdMap[$path];
            $catalogValues[] = (int) ($file['size'] ?? 0);
            $catalogValues[] = $file['status'] ?? 'U';
            $catalogValues[] = $file['mtime'] ?? null;
        }

        if (!empty($catalogPlaceholders)) {
            $sql = "INSERT INTO file_catalog (archive_id, file_path_id, file_size, status, mtime) VALUES "
                 . implode(', ', $catalogPlaceholders);
            $this->db->query($sql, $catalogValues);
        }

        $this->json(['status' => 'ok', 'inserted' => count($catalogPlaceholders)]);
    }

    /**
     * POST /api/agent/heartbeat
     * Simple health check — authentication already updates last_heartbeat.
     */
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
        if (!empty($input['os_info']))       $data['os_info'] = substr($input['os_info'], 0, 255);
        if (!empty($input['borg_version']))  $data['borg_version'] = substr($input['borg_version'], 0, 20);
        if (!empty($input['agent_version'])) $data['agent_version'] = substr($input['agent_version'], 0, 20);
        if (!empty($input['hostname']))      $data['hostname'] = substr($input['hostname'], 0, 255);
        if (!empty($input['ip_address']))    $data['ip_address'] = substr($input['ip_address'], 0, 45);

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

        // Return the server_host setting so the agent knows which host to SSH to
        $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");

        $this->json([
            'status' => 'ok',
            'ssh_private_key' => $privateKey,
            'ssh_unix_user' => $agent['ssh_unix_user'],
            'server_host' => $serverHost['value'] ?? '',
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
     * Parse JSON request body.
     */
    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
