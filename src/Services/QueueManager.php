<?php

namespace BBS\Services;

use BBS\Core\Database;

class QueueManager
{
    private Database $db;
    private int $maxQueue;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $setting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'max_queue'");
        $this->maxQueue = (int) ($setting['value'] ?? 4);
    }

    /**
     * Process the queue: assign queued jobs to agents (up to max_queue limit).
     * Prune/compact jobs are marked for server-side execution (not sent to agents).
     * Returns the jobs that were promoted to 'sent' status.
     */
    public function processQueue(): array
    {
        // Skip if maintenance mode is active
        $maintenance = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'maintenance_mode'");
        if (($maintenance['value'] ?? '0') === '1') {
            return [];
        }

        // Count currently active jobs (sent + running)
        $activeCount = $this->db->count('backup_jobs', "status IN ('sent', 'running')");

        $slotsAvailable = $this->maxQueue - $activeCount;
        if ($slotsAvailable <= 0) {
            return [];
        }

        // Get repos that already have an active job (borg can't run concurrent ops on same repo)
        $busyRepos = $this->db->fetchAll(
            "SELECT DISTINCT repository_id FROM backup_jobs
             WHERE status IN ('sent', 'running') AND repository_id IS NOT NULL"
        );
        $busyRepoIds = array_column($busyRepos, 'repository_id');

        // Get backup plans that already have a sent/running job (skip duplicates of the same plan)
        // Note: only check sent/running, not queued — we iterate queued jobs below and
        // track newly promoted plans to catch duplicates within the same batch
        $busyPlans = $this->db->fetchAll(
            "SELECT DISTINCT backup_plan_id FROM backup_jobs
             WHERE status IN ('sent', 'running')
               AND backup_plan_id IS NOT NULL
               AND task_type = 'backup'"
        );
        $busyPlanIds = array_column($busyPlans, 'backup_plan_id');

        // Get queued jobs ordered by queued_at (FIFO)
        // No LIMIT — we may skip busy-repo jobs and need to see more candidates
        $queuedJobs = $this->db->fetchAll("
            SELECT bj.*, bj.plugin_config_id, bp.directories, bp.excludes, bp.advanced_options,
                   bp.prune_minutes, bp.prune_hours, bp.prune_days,
                   bp.prune_weeks, bp.prune_months, bp.prune_years,
                   r.path as repo_path, r.encryption, r.passphrase_encrypted, r.name as repo_name,
                   r.agent_id as repo_agent_id
            FROM backup_jobs bj
            LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            WHERE bj.status = 'queued'
            ORDER BY bj.queued_at ASC
        ");

        $promoted = [];
        $promotedCount = 0;

        foreach ($queuedJobs as $job) {
            if ($promotedCount >= $slotsAvailable) {
                break;
            }

            // Skip duplicate backup for the same plan (no point running the same backup twice)
            if ($job['task_type'] === 'backup' && $job['backup_plan_id']
                && in_array($job['backup_plan_id'], $busyPlanIds)) {
                $this->db->update('backup_jobs', [
                    'status' => 'failed',
                    'completed_at' => date('Y-m-d H:i:s'),
                    'error_log' => 'Skipped — a backup for this plan is already queued or running',
                ], 'id = ?', [$job['id']]);
                $this->db->insert('server_log', [
                    'agent_id' => $job['agent_id'],
                    'backup_job_id' => $job['id'],
                    'level' => 'info',
                    'message' => "Backup job #{$job['id']} skipped — plan already has an active backup",
                ]);
                continue;
            }

            // Hold if repo already has an active job (borg can't run concurrent ops)
            // Different plans wait their turn; same-plan duplicates were already skipped above
            if ($job['repository_id'] && in_array($job['repository_id'], $busyRepoIds)) {
                continue;
            }
            // Build the task payload
            $repo = [
                'path' => $job['repo_path'],
                'encryption' => $job['encryption'],
                'passphrase_encrypted' => $job['passphrase_encrypted'],
                'agent_id' => $job['repo_agent_id'] ?? $job['agent_id'],
                'name' => $job['repo_name'],
            ];

            $plan = [
                'directories' => $job['directories'] ?? '',
                'excludes' => $job['excludes'] ?? '',
                'advanced_options' => $job['advanced_options'] ?? '',
                'prune_minutes' => $job['prune_minutes'] ?? 0,
                'prune_hours' => $job['prune_hours'] ?? 0,
                'prune_days' => $job['prune_days'] ?? 7,
                'prune_weeks' => $job['prune_weeks'] ?? 4,
                'prune_months' => $job['prune_months'] ?? 6,
                'prune_years' => $job['prune_years'] ?? 0,
            ];

            $taskPayload = null;

            if ($job['task_type'] === 'backup') {
                $prefix = $job['backup_plan_id'] ? 'plan' . $job['backup_plan_id'] : ($job['repo_name'] ?? 'backup');
                $archiveName = BorgCommandBuilder::generateArchiveName($prefix);
                $cmd = BorgCommandBuilder::buildCreateCommand($plan, $repo, $archiveName);
                $env = BorgCommandBuilder::buildEnv($repo);
                // Build plugin payload if any plugins are configured
                $pluginManager = new PluginManager();
                $plugins = $pluginManager->buildPluginPayload($job['backup_plan_id'], $job['agent_id']);

                $extra = [
                    'job_id' => $job['id'],
                    'archive_name' => $archiveName,
                    'directories' => $plan['directories'],
                ];
                if (!empty($plugins)) {
                    $extra['plugins'] = $plugins;
                }

                $taskPayload = BorgCommandBuilder::toTaskPayload('backup', $cmd, $env, $extra);

                // Log the borg command being sent to the agent
                $cmdStr = implode(' ', array_map('escapeshellarg', $cmd));
                $this->db->insert('server_log', [
                    'agent_id' => $job['agent_id'],
                    'backup_job_id' => $job['id'],
                    'level' => 'info',
                    'message' => "Backup command: {$cmdStr}",
                ]);
            } elseif ($job['task_type'] === 'prune' || $job['task_type'] === 'compact') {
                // Prune/compact run server-side — mark as sent, scheduler will execute them
                $taskPayload = ['task' => $job['task_type'], 'server_side' => true, 'job_id' => $job['id']];
            } elseif ($job['task_type'] === 'restore') {
                $taskPayload = $this->buildRestorePayload($job);
            } elseif ($job['task_type'] === 'update_borg') {
                $taskPayload = ['task' => 'update_borg', 'job_id' => $job['id']];
            } elseif ($job['task_type'] === 'update_agent') {
                $taskPayload = ['task' => 'update_agent', 'job_id' => $job['id']];
            } elseif ($job['task_type'] === 'plugin_test') {
                $pluginManager = new PluginManager();
                $testPayload = $pluginManager->buildTestPayload((int) $job['plugin_config_id']);
                $taskPayload = [
                    'task' => 'plugin_test',
                    'job_id' => $job['id'],
                    'plugin' => $testPayload,
                ];
            }

            if ($taskPayload) {
                // Store the payload so the agent API can serve it
                $this->db->update('backup_jobs', [
                    'status' => 'sent',
                ], 'id = ?', [$job['id']]);

                $destination = ($taskPayload['server_side'] ?? false) ? 'server' : 'agent';
                $this->db->insert('server_log', [
                    'agent_id' => $job['agent_id'],
                    'backup_job_id' => $job['id'],
                    'level' => 'info',
                    'message' => "Job #{$job['id']} ({$job['task_type']}) sent to {$destination} queue",
                ]);

                $promoted[] = $job;
                $promotedCount++;

                // Mark this repo and plan as busy for remaining iterations
                if ($job['repository_id']) {
                    $busyRepoIds[] = $job['repository_id'];
                }
                if ($job['backup_plan_id'] && $job['task_type'] === 'backup') {
                    $busyPlanIds[] = $job['backup_plan_id'];
                }
            }
        }

        return $promoted;
    }

    /**
     * Get pending tasks for a specific agent.
     * Called by the Agent API when the agent polls for work.
     * Excludes prune/compact tasks (those run server-side).
     */
    public function getTasksForAgent(int $agentId): array
    {
        $jobs = $this->db->fetchAll("
            SELECT bj.*, bj.plugin_config_id, bp.directories, bp.excludes, bp.advanced_options,
                   bp.prune_minutes, bp.prune_hours, bp.prune_days,
                   bp.prune_weeks, bp.prune_months, bp.prune_years,
                   r.path as repo_path, r.encryption, r.passphrase_encrypted, r.name as repo_name
            FROM backup_jobs bj
            LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            WHERE bj.agent_id = ?
              AND bj.status = 'sent'
              AND bj.task_type NOT IN ('prune', 'compact')
            ORDER BY bj.queued_at ASC
        ", [$agentId]);

        $tasks = [];
        foreach ($jobs as $job) {
            $repo = [
                'path' => $job['repo_path'],
                'encryption' => $job['encryption'],
                'passphrase_encrypted' => $job['passphrase_encrypted'],
            ];

            $plan = [
                'directories' => $job['directories'] ?? '',
                'excludes' => $job['excludes'] ?? '',
                'advanced_options' => $job['advanced_options'] ?? '',
                'prune_minutes' => $job['prune_minutes'] ?? 0,
                'prune_hours' => $job['prune_hours'] ?? 0,
                'prune_days' => $job['prune_days'] ?? 7,
                'prune_weeks' => $job['prune_weeks'] ?? 4,
                'prune_months' => $job['prune_months'] ?? 6,
                'prune_years' => $job['prune_years'] ?? 0,
            ];

            if ($job['task_type'] === 'backup') {
                $prefix = $job['backup_plan_id'] ? 'plan' . $job['backup_plan_id'] : ($job['repo_name'] ?? 'backup');
                $archiveName = BorgCommandBuilder::generateArchiveName($prefix);
                $cmd = BorgCommandBuilder::buildCreateCommand($plan, $repo, $archiveName);
                $env = BorgCommandBuilder::buildEnv($repo);
                $tasks[] = BorgCommandBuilder::toTaskPayload('backup', $cmd, $env, [
                    'job_id' => $job['id'],
                    'archive_name' => $archiveName,
                    'directories' => $plan['directories'],
                ]);
            } elseif ($job['task_type'] === 'restore') {
                $payload = $this->buildRestorePayload($job);
                if ($payload) {
                    $tasks[] = $payload;
                }
            } elseif ($job['task_type'] === 'update_borg') {
                $tasks[] = ['task' => 'update_borg', 'job_id' => $job['id']];
            } elseif ($job['task_type'] === 'update_agent') {
                $tasks[] = ['task' => 'update_agent', 'job_id' => $job['id']];
            } elseif ($job['task_type'] === 'plugin_test') {
                $pluginManager = new PluginManager();
                $testPayload = $pluginManager->buildTestPayload((int) $job['plugin_config_id']);
                $tasks[] = [
                    'task' => 'plugin_test',
                    'job_id' => $job['id'],
                    'plugin' => $testPayload,
                ];
            }
        }

        return $tasks;
    }

    /**
     * Get server-side jobs (prune/compact) that are in 'sent' status.
     * Called by the scheduler to execute locally on the server.
     */
    public function getServerSideJobs(): array
    {
        return $this->db->fetchAll("
            SELECT bj.*, bp.directories, bp.excludes, bp.advanced_options,
                   bp.prune_minutes, bp.prune_hours, bp.prune_days,
                   bp.prune_weeks, bp.prune_months, bp.prune_years,
                   r.path as repo_path, r.encryption, r.passphrase_encrypted,
                   r.name as repo_name, r.agent_id as repo_agent_id,
                   a.ssh_unix_user
            FROM backup_jobs bj
            LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            LEFT JOIN agents a ON a.id = bj.agent_id
            WHERE bj.status = 'sent'
              AND bj.task_type IN ('prune', 'compact')
            ORDER BY bj.queued_at ASC
        ");
    }

    /**
     * Build a restore task payload from a job record.
     */
    private function buildRestorePayload(array $job): ?array
    {
        $archiveId = $job['restore_archive_id'] ?? null;
        if (!$archiveId) return null;

        $archive = $this->db->fetchOne("
            SELECT ar.archive_name, r.path as repo_path, r.passphrase_encrypted
            FROM archives ar
            JOIN repositories r ON r.id = ar.repository_id
            WHERE ar.id = ?
        ", [$archiveId]);

        if (!$archive) return null;

        $paths = json_decode($job['restore_paths'] ?? '[]', true) ?: [];
        $destination = $job['restore_destination'] ?? null;

        $repo = ['path' => $archive['repo_path'], 'passphrase_encrypted' => $archive['passphrase_encrypted']];
        $cmd = BorgCommandBuilder::buildExtractCommand($repo, $archive['archive_name'], $paths);
        $env = BorgCommandBuilder::buildEnv($repo);

        $extra = ['job_id' => $job['id']];
        if ($destination) {
            $extra['cwd'] = $destination;
        }

        return BorgCommandBuilder::toTaskPayload('restore', $cmd, $env, $extra);
    }
}
