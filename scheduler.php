#!/usr/bin/env php
<?php
/**
 * Scheduler CLI - Run via cron every minute:
 *   * * * * * php /path/to/borgbackupserver/scheduler.php
 */

require_once __DIR__ . '/vendor/autoload.php';

use BBS\Core\Config;
use BBS\Services\SchedulerService;
use BBS\Services\QueueManager;
use BBS\Services\NotificationService;
use BBS\Services\UpdateService;

Config::load();

$db = \BBS\Core\Database::getInstance();

// Step 1: Mark agents offline if no heartbeat in 3x poll interval
$pollInterval = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'agent_poll_interval'");
$threshold = ((int)($pollInterval['value'] ?? 30)) * 3;

$now = date('Y-m-d H:i:s');
$cutoff = date('Y-m-d H:i:s', time() - $threshold);

$stale = $db->query(
    "UPDATE agents SET status = 'offline'
     WHERE status = 'online'
       AND last_heartbeat IS NOT NULL
       AND last_heartbeat < ?",
    [$cutoff]
);

if ($stale->rowCount() > 0) {
    echo date('Y-m-d H:i:s') . " Marked {$stale->rowCount()} agent(s) offline (no heartbeat in {$threshold}s)\n";

    // Notify for each agent that just went offline
    $notificationService = new NotificationService();
    $offlineAgents = $db->fetchAll(
        "SELECT id, name FROM agents WHERE status = 'offline' AND last_heartbeat IS NOT NULL AND last_heartbeat < ?",
        [$cutoff]
    );
    foreach ($offlineAgents as $offAgent) {
        $notificationService->notify('agent_offline', $offAgent['id'], null, "Client \"{$offAgent['name']}\" is offline (no heartbeat in {$threshold}s)", 'critical');
    }
}

// Step 2: Fail jobs for agents that are offline (queued, sent, or running)
$staleJobs = $db->fetchAll("
    SELECT bj.id, bj.agent_id, bj.task_type, bj.backup_plan_id, bj.status, a.name as agent_name
    FROM backup_jobs bj
    JOIN agents a ON a.id = bj.agent_id
    WHERE bj.status IN ('queued', 'sent', 'running')
      AND a.status = 'offline'
");

foreach ($staleJobs as $sj) {
    $db->update('backup_jobs', [
        'status' => 'failed',
        'completed_at' => date('Y-m-d H:i:s'),
        'error_log' => "Agent went offline — no heartbeat in {$threshold}s",
    ], 'id = ?', [$sj['id']]);

    $db->insert('server_log', [
        'agent_id' => $sj['agent_id'],
        'backup_job_id' => $sj['id'],
        'level' => 'error',
        'message' => "Job #{$sj['id']} ({$sj['task_type']}) failed — agent \"{$sj['agent_name']}\" went offline",
    ]);

    // Fire backup_failed notification if it was a backup
    if ($sj['task_type'] === 'backup' && $sj['backup_plan_id']) {
        $notificationService = $notificationService ?? new NotificationService();
        $planRow = $db->fetchOne("SELECT name FROM backup_plans WHERE id = ?", [$sj['backup_plan_id']]);
        $planName = $planRow['name'] ?? '';
        $notificationService->notify(
            'backup_failed',
            $sj['agent_id'],
            (int)$sj['backup_plan_id'],
            "Backup failed for plan \"{$planName}\" on client \"{$sj['agent_name']}\" — agent went offline",
            'critical'
        );
    }

    echo date('Y-m-d H:i:s') . " Failed: job #{$sj['id']} ({$sj['task_type']}) — agent \"{$sj['agent_name']}\" offline\n";
}

// Step 3: Check schedules and create queued jobs
$scheduler = new SchedulerService();
$created = $scheduler->run();

foreach ($created as $job) {
    echo date('Y-m-d H:i:s') . " Queued: {$job['plan']} (job #{$job['job_id']}, agent #{$job['agent_id']})\n";
}

// Step 4: Process queue - promote queued jobs to sent
$queueManager = new QueueManager();
$promoted = $queueManager->processQueue();

foreach ($promoted as $job) {
    echo date('Y-m-d H:i:s') . " Sent: job #{$job['id']} ({$job['task_type']}) to agent #{$job['agent_id']}\n";
}

// Step 4b: Execute server-side jobs (prune/compact) locally
$serverJobs = $queueManager->getServerSideJobs();
foreach ($serverJobs as $sj) {
    $repo = [
        'path' => $sj['repo_path'],
        'encryption' => $sj['encryption'],
        'passphrase_encrypted' => $sj['passphrase_encrypted'],
        'agent_id' => $sj['repo_agent_id'] ?? $sj['agent_id'],
        'name' => $sj['repo_name'],
    ];

    // Use local path for server-side execution
    $localPath = \BBS\Services\BorgCommandBuilder::getLocalRepoPath($repo);
    $localRepo = array_merge($repo, ['path' => $localPath]);

    $plan = [
        'prune_minutes' => $sj['prune_minutes'] ?? 0,
        'prune_hours' => $sj['prune_hours'] ?? 0,
        'prune_days' => $sj['prune_days'] ?? 7,
        'prune_weeks' => $sj['prune_weeks'] ?? 4,
        'prune_months' => $sj['prune_months'] ?? 6,
        'prune_years' => $sj['prune_years'] ?? 0,
    ];

    // Mark as running
    $startedAt = date('Y-m-d H:i:s');
    $db->update('backup_jobs', [
        'status' => 'running',
        'started_at' => $startedAt,
    ], 'id = ?', [$sj['id']]);

    echo date('Y-m-d H:i:s') . " Executing server-side: job #{$sj['id']} ({$sj['task_type']})\n";

    // S3 sync — uses rclone, not borg
    if ($sj['task_type'] === 's3_sync') {
        $pluginManager = $pluginManager ?? new \BBS\Services\PluginManager();

        // Resolve plugin config — from job's plugin_config_id or plan plugins
        $config = [];
        if (!empty($sj['plugin_config_id'])) {
            $namedConfig = $pluginManager->getPluginConfig((int) $sj['plugin_config_id']);
            if ($namedConfig) {
                $config = json_decode($namedConfig['config'], true) ?: [];
            }
        }

        $s3Service = new \BBS\Services\S3SyncService();
        $creds = $s3Service->resolveCredentials($config);

        $s3Repo = $db->fetchOne("SELECT * FROM repositories WHERE id = ?", [$sj['repository_id']]);
        $s3Agent = $db->fetchOne("SELECT * FROM agents WHERE id = ?", [$sj['agent_id']]);

        if (!$s3Repo || !$s3Agent) {
            $s3Result = 'failed';
            $s3Error = 'Repository or agent not found';
        } else {
            $runAsUser = $sj['ssh_unix_user'] ?? null;
            $syncResult = $s3Service->syncRepository($s3Repo, $s3Agent, $creds, $runAsUser);
            $s3Result = $syncResult['success'] ? 'completed' : 'failed';
            $s3Output = $syncResult['output'] ?? '';
            $s3Error = $syncResult['success'] ? null : $s3Output;
        }

        $now = date('Y-m-d H:i:s');
        $db->update('backup_jobs', [
            'status' => $s3Result,
            'completed_at' => $now,
            'duration_seconds' => max(0, strtotime($now) - strtotime($startedAt)),
            'error_log' => $s3Error,
        ], 'id = ?', [$sj['id']]);

        $logMessage = $s3Result === 'completed'
            ? 'S3 sync completed' . (!empty($s3Output) ? ": {$s3Output}" : '')
            : 'S3 sync failed: ' . $s3Error;
        $db->insert('server_log', [
            'agent_id' => $sj['agent_id'],
            'backup_job_id' => $sj['id'],
            'level' => $s3Result === 'completed' ? 'info' : 'error',
            'message' => $logMessage,
        ]);

        echo date('Y-m-d H:i:s') . " S3 sync job #{$sj['id']} {$s3Result}\n";
        continue;
    }

    // S3 restore — uses rclone to download from S3
    if ($sj['task_type'] === 's3_restore') {
        $pluginManager = $pluginManager ?? new \BBS\Services\PluginManager();

        // Resolve plugin config — from job's plugin_config_id
        $config = [];
        if (!empty($sj['plugin_config_id'])) {
            $namedConfig = $pluginManager->getPluginConfig((int) $sj['plugin_config_id']);
            if ($namedConfig) {
                $config = json_decode($namedConfig['config'], true) ?: [];
            }
        }

        $s3Service = new \BBS\Services\S3SyncService();
        $creds = $s3Service->resolveCredentials($config);

        $s3Repo = $db->fetchOne("SELECT * FROM repositories WHERE id = ?", [$sj['repository_id']]);
        $s3Agent = $db->fetchOne("SELECT * FROM agents WHERE id = ?", [$sj['agent_id']]);

        if (!$s3Repo || !$s3Agent) {
            $s3Result = 'failed';
            $s3Error = 'Repository or agent not found';
        } else {
            $runAsUser = $sj['ssh_unix_user'] ?? null;
            $restoreResult = $s3Service->restoreRepository($s3Repo, $s3Agent, $creds, $runAsUser);
            $s3Result = $restoreResult['success'] ? 'completed' : 'failed';
            $s3Output = $restoreResult['output'] ?? '';
            $s3Error = $restoreResult['success'] ? null : $s3Output;
        }

        $now = date('Y-m-d H:i:s');
        $db->update('backup_jobs', [
            'status' => $s3Result,
            'completed_at' => $now,
            'duration_seconds' => max(0, strtotime($now) - strtotime($startedAt)),
            'error_log' => $s3Error,
        ], 'id = ?', [$sj['id']]);

        $logMessage = $s3Result === 'completed'
            ? 'S3 restore completed' . (!empty($s3Output) ? ": {$s3Output}" : '')
            : 'S3 restore failed: ' . $s3Error;
        $db->insert('server_log', [
            'agent_id' => $sj['agent_id'],
            'backup_job_id' => $sj['id'],
            'level' => $s3Result === 'completed' ? 'info' : 'error',
            'message' => $logMessage,
        ]);

        echo date('Y-m-d H:i:s') . " S3 restore job #{$sj['id']} {$s3Result}\n";

        // Auto-queue catalog_sync after successful S3 restore
        if ($s3Result === 'completed' && $sj['repository_id']) {
            $db->insert('backup_jobs', [
                'agent_id' => $sj['agent_id'],
                'repository_id' => $sj['repository_id'],
                'task_type' => 'catalog_sync',
                'status' => 'queued',
            ]);
            $db->insert('server_log', [
                'agent_id' => $sj['agent_id'],
                'level' => 'info',
                'message' => "Catalog sync queued for repository after S3 restore",
            ]);
            echo date('Y-m-d H:i:s') . " Queued catalog_sync for repo #{$sj['repository_id']} after S3 restore\n";
        }
        continue;
    }

    // Catalog sync — runs borg list to rebuild archives table
    if ($sj['task_type'] === 'catalog_sync') {
        $csRepo = $db->fetchOne("SELECT * FROM repositories WHERE id = ?", [$sj['repository_id']]);
        if (!$csRepo) {
            $db->update('backup_jobs', [
                'status' => 'failed',
                'completed_at' => date('Y-m-d H:i:s'),
                'error_log' => 'Repository not found',
            ], 'id = ?', [$sj['id']]);
            echo date('Y-m-d H:i:s') . " Catalog sync job #{$sj['id']} failed: repository not found\n";
            continue;
        }

        $csLocalPath = \BBS\Services\BorgCommandBuilder::getLocalRepoPath($csRepo);
        $passphrase = null;
        if (!empty($csRepo['passphrase_encrypted'])) {
            try {
                $passphrase = \BBS\Services\Encryption::decrypt($csRepo['passphrase_encrypted']);
            } catch (\Exception $e) {
                // May already be plaintext or missing
            }
        }

        // Build environment for borg list
        $csEnv = [];
        if ($passphrase) {
            $csEnv['BORG_PASSPHRASE'] = $passphrase;
        }
        $csEnv['BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK'] = 'yes';

        // Run borg list --json to get all archives
        $csCmd = ['borg', 'list', '--json', $csLocalPath];

        // Run as the repo's unix user (same pattern as prune/compact)
        $runAsUser = $sj['ssh_unix_user'] ?? null;
        if ($runAsUser) {
            // Dedicated cache dir for this user
            $userCache = "/var/bbs/cache/{$runAsUser}";
            if (!is_dir($userCache)) {
                mkdir($userCache, 0700, true);
                chown($userCache, $runAsUser);
            }
            $csEnv['BORG_BASE_DIR'] = $userCache;
            $csEnv['HOME'] = $userCache;

            // Prepend env vars into the command so they survive sudo's env reset
            $envPrefix = [];
            foreach ($csEnv as $k => $v) {
                $envPrefix[] = $k . '=' . $v;
            }
            array_unshift($csCmd, 'sudo', '-u', $runAsUser, 'env', ...$envPrefix);
        } else {
            $csEnv['BORG_BASE_DIR'] = '/tmp/bbs-borg-www-data';
            $csEnv['HOME'] = '/tmp/bbs-borg-www-data';
        }

        $csEnvStrings = [];
        foreach ($csEnv as $k => $v) {
            $csEnvStrings[$k] = $v;
        }

        $csProc = proc_open($csCmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $csPipes, null, array_merge($_SERVER, $csEnvStrings));

        $csOutput = '';
        $csError = '';
        $csExitCode = -1;
        if (is_resource($csProc)) {
            fclose($csPipes[0]);
            $csOutput = stream_get_contents($csPipes[1]);
            $csError = stream_get_contents($csPipes[2]);
            fclose($csPipes[1]);
            fclose($csPipes[2]);
            $csExitCode = proc_close($csProc);
        }

        $csNow = date('Y-m-d H:i:s');
        if ($csExitCode === 0) {
            $csData = json_decode($csOutput, true);
            $archives = $csData['archives'] ?? [];

            // Clear existing archives for this repo and rebuild
            $db->delete('archives', 'repository_id = ?', [$csRepo['id']]);

            $archiveCount = 0;
            $totalSize = 0;
            foreach ($archives as $ar) {
                // borg list --json gives: name, start, id
                // We need to run borg info for full stats, but that's expensive
                // For now, insert basic info - size will be 0 until next backup reports stats
                $db->insert('archives', [
                    'repository_id' => $csRepo['id'],
                    'archive_name' => $ar['name'] ?? 'unknown',
                    'borg_archive_id' => $ar['id'] ?? null,
                    'created_at' => isset($ar['start']) ? date('Y-m-d H:i:s', strtotime($ar['start'])) : $csNow,
                    'original_size' => 0,
                    'compressed_size' => 0,
                    'deduplicated_size' => 0,
                ]);
                $archiveCount++;
            }

            // Update repo stats
            $db->update('repositories', [
                'archive_count' => $archiveCount,
            ], 'id = ?', [$csRepo['id']]);

            $db->update('backup_jobs', [
                'status' => 'completed',
                'completed_at' => $csNow,
                'duration_seconds' => max(0, strtotime($csNow) - strtotime($startedAt)),
            ], 'id = ?', [$sj['id']]);

            $db->insert('server_log', [
                'agent_id' => $sj['agent_id'],
                'backup_job_id' => $sj['id'],
                'level' => 'info',
                'message' => "Catalog sync completed: {$archiveCount} archives found",
            ]);
            echo date('Y-m-d H:i:s') . " Catalog sync job #{$sj['id']} completed: {$archiveCount} archives\n";
        } else {
            $db->update('backup_jobs', [
                'status' => 'failed',
                'completed_at' => $csNow,
                'duration_seconds' => max(0, strtotime($csNow) - strtotime($startedAt)),
                'error_log' => $csError ?: "borg list failed with exit code {$csExitCode}",
            ], 'id = ?', [$sj['id']]);

            $db->insert('server_log', [
                'agent_id' => $sj['agent_id'],
                'backup_job_id' => $sj['id'],
                'level' => 'error',
                'message' => "Catalog sync failed: " . ($csError ?: "exit code {$csExitCode}"),
            ]);
            echo date('Y-m-d H:i:s') . " Catalog sync job #{$sj['id']} failed\n";
        }
        continue;
    }

    // Build command
    if ($sj['task_type'] === 'prune') {
        $archivePrefix = $sj['backup_plan_id'] ? 'plan' . $sj['backup_plan_id'] : null;
        $cmd = \BBS\Services\BorgCommandBuilder::buildPruneCommand($plan, $localRepo, $archivePrefix);
    } elseif ($sj['task_type'] === 'compact') {
        $cmd = ['borg', 'compact', $localPath];
    } elseif ($sj['task_type'] === 'repo_check') {
        $cmd = ['borg', 'check', '--verbose', $localPath];
    } elseif ($sj['task_type'] === 'repo_repair') {
        $cmd = ['borg', 'check', '--repair', $localPath];
    } elseif ($sj['task_type'] === 'break_lock') {
        $cmd = ['borg', 'break-lock', $localPath];
    } else {
        // Unknown task type
        $db->update('backup_jobs', [
            'status' => 'failed',
            'completed_at' => date('Y-m-d H:i:s'),
            'error_log' => "Unknown server-side task type: {$sj['task_type']}",
        ], 'id = ?', [$sj['id']]);
        echo date('Y-m-d H:i:s') . " Unknown task type: {$sj['task_type']} for job #{$sj['id']}\n";
        continue;
    }

    // Build env (server-side, no BORG_RSH needed)
    $env = \BBS\Services\BorgCommandBuilder::buildEnv($localRepo, false);

    // Run as the repo's unix user to preserve file ownership
    $runAsUser = $sj['ssh_unix_user'] ?? null;
    if ($runAsUser) {
        // Dedicated cache dir — separate from storage and /tmp
        $userCache = "/var/bbs/cache/{$runAsUser}";
        if (!is_dir($userCache)) {
            mkdir($userCache, 0700, true);
            chown($userCache, $runAsUser);
        }
        $env['BORG_BASE_DIR'] = $userCache;
        $env['HOME'] = $userCache;

        // Prepend env vars into the command so they survive sudo's env reset
        $envPrefix = [];
        foreach ($env as $k => $v) {
            $envPrefix[] = $k . '=' . $v;
        }
        array_unshift($cmd, 'sudo', '-u', $runAsUser, 'env', ...$envPrefix);
    }

    // Log the borg command (without env vars that may contain passphrases)
    $logCmd = array_filter($cmd, fn($part) => !str_starts_with($part, 'BORG_PASSPHRASE='));
    $cmdStr = implode(' ', array_map('escapeshellarg', array_values($logCmd)));
    $db->insert('server_log', [
        'agent_id' => $sj['agent_id'],
        'backup_job_id' => $sj['id'],
        'level' => 'info',
        'message' => ucfirst($sj['task_type']) . " command: {$cmdStr}",
    ]);

    // Execute
    $envStrings = [];
    foreach ($env as $k => $v) {
        $envStrings[$k] = $v;
    }

    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $desc, $pipes, null, array_merge($_SERVER, $envStrings));

    $result = 'failed';
    $errorOutput = '';

    if (is_resource($proc)) {
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode <= 1) {
            $result = 'completed';
        } else {
            $errorOutput = $stderr ?: "Exit code $exitCode";
        }
    } else {
        $errorOutput = 'Failed to execute borg command';
    }

    $now = date('Y-m-d H:i:s');
    $db->update('backup_jobs', [
        'status' => $result,
        'completed_at' => $now,
        'duration_seconds' => max(0, strtotime($now) - strtotime($startedAt)),
        'error_log' => $errorOutput ?: null,
    ], 'id = ?', [$sj['id']]);

    $level = $result === 'completed' ? 'info' : 'error';
    $db->insert('server_log', [
        'agent_id' => $sj['agent_id'],
        'backup_job_id' => $sj['id'],
        'level' => $level,
        'message' => "Server-side {$sj['task_type']} job #{$sj['id']} {$result}" . ($errorOutput ? ": $errorOutput" : ''),
    ]);

    // Log borg prune/compact output for visibility
    if ($result === 'completed' && !empty($stdout)) {
        // Truncate to a reasonable size for the log
        $trimmedOutput = mb_substr(trim($stdout), 0, 2000);
        if ($trimmedOutput) {
            $db->insert('server_log', [
                'agent_id' => $sj['agent_id'],
                'backup_job_id' => $sj['id'],
                'level' => 'info',
                'message' => ucfirst($sj['task_type']) . " output: " . $trimmedOutput,
            ]);
        }
    }

    echo date('Y-m-d H:i:s') . " Server-side {$sj['task_type']} job #{$sj['id']}: {$result}\n";

    // After successful prune, sync archives table with actual repo contents
    if ($result === 'completed' && $sj['task_type'] === 'prune') {
        $listCmd = \BBS\Services\BorgCommandBuilder::buildListCommand($localRepo);
        if ($runAsUser) {
            // Prepend env vars into the command so they survive sudo's env reset
            $envPrefix = [];
            foreach ($env as $k => $v) {
                $envPrefix[] = $k . '=' . $v;
            }
            array_unshift($listCmd, 'sudo', '-u', $runAsUser, 'env', ...$envPrefix);
        }
        $listProc = proc_open($listCmd, $desc, $listPipes, null, array_merge($_SERVER, $envStrings));

        if (is_resource($listProc)) {
            fclose($listPipes[0]);
            $listOut = stream_get_contents($listPipes[1]);
            fclose($listPipes[1]);
            fclose($listPipes[2]);
            $listExit = proc_close($listProc);

            if ($listExit === 0 && $listOut) {
                $listData = json_decode($listOut, true);
                $borgArchives = [];
                if (!empty($listData['archives'])) {
                    foreach ($listData['archives'] as $a) {
                        $borgArchives[] = $a['name'];
                    }
                }

                // Get DB archives for this repo
                $repoId = $sj['repository_id'];
                $dbArchives = $db->fetchAll(
                    "SELECT id, archive_name FROM archives WHERE repository_id = ?", [$repoId]
                );

                $removed = 0;
                $removedNames = [];
                foreach ($dbArchives as $dbA) {
                    if (!in_array($dbA['archive_name'], $borgArchives, true)) {
                        $db->delete('archives', 'id = ?', [$dbA['id']]);
                        $removedNames[] = $dbA['archive_name'];
                        $removed++;
                    }
                }

                if ($removed > 0) {
                    $nameList = implode(', ', array_slice($removedNames, 0, 20));
                    if (count($removedNames) > 20) {
                        $nameList .= ' (and ' . (count($removedNames) - 20) . ' more)';
                    }
                    $db->insert('server_log', [
                        'agent_id' => $sj['agent_id'],
                        'backup_job_id' => $sj['id'],
                        'level' => 'info',
                        'message' => "Removed {$removed} pruned recovery point(s) from database — " . count($borgArchives) . " remaining: {$nameList}",
                    ]);
                    echo date('Y-m-d H:i:s') . " Removed {$removed} pruned archive(s) from DB for repo #{$repoId}\n";
                } else {
                    $db->insert('server_log', [
                        'agent_id' => $sj['agent_id'],
                        'backup_job_id' => $sj['id'],
                        'level' => 'info',
                        'message' => "Prune completed — all " . count($borgArchives) . " recovery point(s) retained, none removed",
                    ]);
                }
            }
        }
    }

    // Auto-queue S3 sync after successful prune (if plan has s3_sync plugin)
    if ($result === 'completed' && $sj['task_type'] === 'prune' && !empty($sj['backup_plan_id'])) {
        $pluginManager = $pluginManager ?? new \BBS\Services\PluginManager();
        $planPlugins = $pluginManager->getPlanPlugins((int) $sj['backup_plan_id']);

        foreach ($planPlugins as $pp) {
            if ($pp['slug'] !== 's3_sync' || !$pp['enabled']) {
                continue;
            }

            $s3JobId = $db->insert('backup_jobs', [
                'backup_plan_id' => $sj['backup_plan_id'],
                'agent_id' => $sj['agent_id'],
                'repository_id' => $sj['repository_id'],
                'task_type' => 's3_sync',
                'plugin_config_id' => $pp['plugin_config_id'] ?: null,
                'status' => 'queued',
            ]);

            $db->insert('server_log', [
                'agent_id' => $sj['agent_id'],
                'backup_job_id' => $s3JobId,
                'level' => 'info',
                'message' => "S3 sync queued (job #{$s3JobId}) after prune job #{$sj['id']}",
            ]);

            echo date('Y-m-d H:i:s') . " Queued: S3 sync job #{$s3JobId} after prune #{$sj['id']}\n";
        }
    }
}

// Step 5: Update repository sizes from actual disk usage (every 5 minutes)
if ((int) date('i') % 5 === 0) {
    $repos = $db->fetchAll("SELECT id, path, agent_id, name FROM repositories");
    foreach ($repos as $repo) {
        $localPath = \BBS\Services\BorgCommandBuilder::getLocalRepoPath($repo);
        if (!empty($localPath)) {
            // Use SSH helper to get size (runs as root, can read all repos)
            $output = [];
            exec('sudo /usr/local/bin/bbs-ssh-helper get-size ' . escapeshellarg($localPath) . ' 2>/dev/null', $output);
            if (!empty($output[0]) && is_numeric($output[0])) {
                $sizeBytes = (int) $output[0];
                $db->update('repositories', ['size_bytes' => $sizeBytes], 'id = ?', [$repo['id']]);
            }
        }
    }
}

// Step 6: Check storage for low disk space
$notificationService = $notificationService ?? new NotificationService();
$thresholdSetting = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_alert_threshold'");
$storageThreshold = (int) ($thresholdSetting['value'] ?? 90);

$storagePathSetting = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
$storagePath = $storagePathSetting['value'] ?? '';
if (!empty($storagePath) && is_dir($storagePath)) {
    $total = @disk_total_space($storagePath);
    $free = @disk_free_space($storagePath);
    if ($total !== false && $free !== false && $total > 0) {
        $usagePercent = round((($total - $free) / $total) * 100, 1);
        if ($usagePercent >= $storageThreshold) {
            $notificationService->notify('storage_low', null, null, "Storage is at {$usagePercent}% capacity ({$storagePath})", 'warning');
        } else {
            $notificationService->resolve('storage_low', null, null);
        }
    }
}

// Step 6: Cleanup old resolved notifications and server logs
$notificationService->cleanup();

// Purge server_log entries older than 30 days
$purged = $db->delete('server_log', 'created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
if ($purged > 0) {
    echo date('Y-m-d H:i:s') . " Purged {$purged} server log entries older than 30 days\n";
}

// Step 7: Check for updates (hourly)
$lastCheck = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'last_update_check'");
$lastCheckTime = $lastCheck['value'] ?? null;
if (!$lastCheckTime || strtotime($lastCheckTime) < time() - 3600) {
    $updateService = new UpdateService();
    $result = $updateService->checkForUpdate();
    if (isset($result['update_available']) && $result['update_available']) {
        echo date('Y-m-d H:i:s') . " Update available: v{$result['version']} (current: v{$result['current']})\n";
    }
}

// Step 8: Sync available borg versions from GitHub (daily)
$lastBorgCheck = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'last_borg_version_check'");
$lastBorgCheckTime = $lastBorgCheck['value'] ?? null;
if (!$lastBorgCheckTime || strtotime($lastBorgCheckTime) < time() - 86400) {
    $borgVersionService = new \BBS\Services\BorgVersionService();
    $syncResult = $borgVersionService->syncVersionsFromGitHub();
    if (isset($syncResult['added'])) {
        echo date('Y-m-d H:i:s') . " Borg version sync: {$syncResult['added']} new versions added\n";
    } elseif (isset($syncResult['error'])) {
        echo date('Y-m-d H:i:s') . " Borg version sync failed: {$syncResult['error']}\n";
    }
}

// Step 9: Clean up old backup jobs (daily, keep 30 days)
$lastJobCleanup = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'last_job_cleanup'");
$lastJobCleanupTime = $lastJobCleanup['value'] ?? null;
if (!$lastJobCleanupTime || strtotime($lastJobCleanupTime) < time() - 86400) {
    $cutoffDate = date('Y-m-d H:i:s', time() - 30 * 86400);

    // Delete related server_log entries first
    $db->query(
        "DELETE FROM server_log WHERE backup_job_id IN (
            SELECT id FROM backup_jobs
            WHERE status IN ('completed', 'failed', 'cancelled')
              AND COALESCE(completed_at, queued_at) < ?
        )",
        [$cutoffDate]
    );

    // Delete old completed/failed/cancelled jobs
    $deleted = $db->query(
        "DELETE FROM backup_jobs
         WHERE status IN ('completed', 'failed', 'cancelled')
           AND COALESCE(completed_at, queued_at) < ?",
        [$cutoffDate]
    );

    $count = $deleted->rowCount();
    if ($count > 0) {
        echo date('Y-m-d H:i:s') . " Job cleanup: removed {$count} jobs older than 30 days\n";
    }

    $db->query(
        "INSERT INTO settings (`key`, `value`) VALUES ('last_job_cleanup', ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [date('Y-m-d H:i:s')]
    );
}

// Step 10: Daily BBS self-backup
$lastSelfBackup = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'last_self_backup'");
$lastSelfBackupTime = $lastSelfBackup['value'] ?? null;
if (!$lastSelfBackupTime || strtotime($lastSelfBackupTime) < time() - 86400) {
    $backupScript = __DIR__ . '/bin/bbs-backup';
    if (is_file($backupScript)) {
        $output = shell_exec("sudo $backupScript 2>&1");
        if (str_contains($output ?? '', 'OK')) {
            echo date('Y-m-d H:i:s') . " Self-backup completed\n";
        } else {
            echo date('Y-m-d H:i:s') . " Self-backup failed: " . trim($output ?? '') . "\n";
        }
    }

    $db->query(
        "INSERT INTO settings (`key`, `value`) VALUES ('last_self_backup', ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [date('Y-m-d H:i:s')]
    );

    // Sync server backups to S3 if enabled
    $syncEnabled = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 's3_sync_server_backups'");
    if (($syncEnabled['value'] ?? '0') === '1') {
        $s3Service = new \BBS\Services\S3SyncService();
        $creds = $s3Service->resolveCredentials(['credential_source' => 'global']);

        if (!empty($creds['bucket']) && $s3Service->isRcloneInstalled()) {
            $backupDir = '/var/bbs/backups';
            $prefix = trim($creds['path_prefix'], '/');
            $remotePath = $prefix ? "{$prefix}/_server-backups" : '_server-backups';
            $remote = "S3:{$creds['bucket']}/{$remotePath}/";

            $env = $s3Service->buildRcloneEnv($creds);
            $envArgs = [];
            foreach ($env as $k => $v) {
                $envArgs[] = escapeshellarg("$k=$v");
            }
            $envStr = implode(' ', $envArgs);

            // rclone sync mirrors the local dir to S3 (keeps 7, removes older from S3)
            $cmd = "env $envStr rclone sync " . escapeshellarg($backupDir) . " " . escapeshellarg($remote)
                 . " --include 'bbs-backup-*.tar.gz' --transfers 2 -v 2>&1";
            $syncOutput = shell_exec($cmd);

            if (str_contains($syncOutput ?? '', 'ERROR')) {
                echo date('Y-m-d H:i:s') . " Server backup S3 sync failed: " . trim($syncOutput) . "\n";
            } else {
                echo date('Y-m-d H:i:s') . " Server backups synced to S3\n";
            }
        }
    }
}

// Step 11: Weekly auto-compact of all repositories (Saturday night at 2 AM)
// Jobs are queued sequentially and processed one at a time by the scheduler
$dayOfWeek = (int) date('w'); // 0=Sunday, 6=Saturday
$hourOfDay = (int) date('G'); // 0-23

// Check if it's Saturday (6) and within the 2 AM hour
if ($dayOfWeek === 6 && $hourOfDay === 2) {
    $lastAutoCompact = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'last_auto_compact'");
    $lastAutoCompactTime = $lastAutoCompact['value'] ?? null;

    // Only run once per week (check if last run was more than 6 days ago)
    if (!$lastAutoCompactTime || strtotime($lastAutoCompactTime) < time() - (6 * 86400)) {
        // Get all repositories
        $repos = $db->fetchAll("SELECT r.id, r.name, r.agent_id FROM repositories r");
        $queued = 0;

        foreach ($repos as $repo) {
            // Check if there's already a pending compact job for this repo
            $existing = $db->fetchOne(
                "SELECT id FROM backup_jobs WHERE repository_id = ? AND task_type = 'compact' AND status IN ('queued', 'sent', 'running')",
                [$repo['id']]
            );
            if ($existing) {
                continue;
            }

            // Queue compact job
            $jobId = $db->insert('backup_jobs', [
                'agent_id' => $repo['agent_id'],
                'repository_id' => $repo['id'],
                'task_type' => 'compact',
                'status' => 'queued',
            ]);

            $db->insert('server_log', [
                'agent_id' => $repo['agent_id'],
                'backup_job_id' => $jobId,
                'level' => 'info',
                'message' => "Weekly auto-compact job #{$jobId} queued for repository \"{$repo['name']}\"",
            ]);

            $queued++;
        }

        if ($queued > 0) {
            echo date('Y-m-d H:i:s') . " Weekly auto-compact: queued {$queued} compact job(s)\n";
        }

        $db->query(
            "INSERT INTO settings (`key`, `value`) VALUES ('last_auto_compact', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [date('Y-m-d H:i:s')]
        );
    }
}

// Step 12: Daily auto-update of borg (if enabled, at 3 AM)
if ($hourOfDay === 3) {
    $borgService = new \BBS\Services\BorgVersionService();
    if ($borgService->isAutoUpdateEnabled()) {
        $lastBorgAutoUpdate = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'last_borg_auto_update'");
        $lastBorgAutoUpdateTime = $lastBorgAutoUpdate['value'] ?? null;

        // Only run once per day
        if (!$lastBorgAutoUpdateTime || strtotime($lastBorgAutoUpdateTime) < time() - 82800) {
            $mode = $borgService->getUpdateMode();
            $queued = 0;
            $skipped = 0;

            // Update server first
            $serverResult = $borgService->updateServerBorgByMode();
            if ($serverResult['success']) {
                echo date('Y-m-d H:i:s') . " Auto-update: server borg updated to v{$serverResult['version']}\n";
            }

            // Queue updates for agents
            $agents = $borgService->getAllAgentVersions();
            $pending = $db->fetchAll(
                "SELECT agent_id FROM backup_jobs WHERE task_type = 'update_borg' AND status IN ('queued', 'sent', 'running')"
            );
            $pendingIds = array_column($pending, 'agent_id');

            foreach ($agents as $agent) {
                if (in_array($agent['id'], $pendingIds)) {
                    continue;
                }

                // In server mode, skip incompatible agents
                if ($mode === 'server') {
                    $version = $borgService->getServerVersion();
                    if (!$borgService->isAgentCompatibleWithServerVersion($agent, $version)) {
                        $skipped++;
                        continue;
                    }
                }

                $jobId = $db->insert('backup_jobs', [
                    'agent_id' => $agent['id'],
                    'task_type' => 'update_borg',
                    'status' => 'queued',
                ]);
                $db->insert('server_log', [
                    'agent_id' => $agent['id'],
                    'backup_job_id' => $jobId,
                    'level' => 'info',
                    'message' => "Auto-update borg queued ({$mode} mode)",
                ]);
                $queued++;
            }

            if ($queued > 0 || $skipped > 0) {
                echo date('Y-m-d H:i:s') . " Auto-update: queued {$queued} borg update(s), skipped {$skipped} incompatible\n";
            }

            $db->query(
                "INSERT INTO settings (`key`, `value`) VALUES ('last_borg_auto_update', ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [date('Y-m-d H:i:s')]
            );
        }
    }
}
