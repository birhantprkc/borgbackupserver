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

$stale = $db->query(
    "UPDATE agents SET status = 'offline'
     WHERE status = 'online'
       AND last_heartbeat IS NOT NULL
       AND last_heartbeat < DATE_SUB(NOW(), INTERVAL ? SECOND)",
    [$threshold]
);

if ($stale->rowCount() > 0) {
    echo date('Y-m-d H:i:s') . " Marked {$stale->rowCount()} agent(s) offline (no heartbeat in {$threshold}s)\n";

    // Notify for each agent that just went offline
    $notificationService = new NotificationService();
    $offlineAgents = $db->fetchAll(
        "SELECT id, name FROM agents WHERE status = 'offline' AND last_heartbeat IS NOT NULL AND last_heartbeat < DATE_SUB(NOW(), INTERVAL ? SECOND)",
        [$threshold]
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
        'storage_location_id' => $sj['storage_location_id'] ?? null,
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
    $db->update('backup_jobs', [
        'status' => 'running',
        'started_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$sj['id']]);

    echo date('Y-m-d H:i:s') . " Executing server-side: job #{$sj['id']} ({$sj['task_type']})\n";

    // Build command
    if ($sj['task_type'] === 'prune') {
        $cmd = \BBS\Services\BorgCommandBuilder::buildPruneCommand($plan, $localRepo);
    } else {
        // compact
        $cmd = ['borg', 'compact', $localPath];
    }

    // Build env (server-side, no BORG_RSH needed)
    $env = \BBS\Services\BorgCommandBuilder::buildEnv($localRepo, false);

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
        'duration_seconds' => max(0, strtotime($now) - strtotime($sj['started_at'] ?? $now)),
        'error_log' => $errorOutput ?: null,
    ], 'id = ?', [$sj['id']]);

    $level = $result === 'completed' ? 'info' : 'error';
    $db->insert('server_log', [
        'agent_id' => $sj['agent_id'],
        'backup_job_id' => $sj['id'],
        'level' => $level,
        'message' => "Server-side {$sj['task_type']} job #{$sj['id']} {$result}" . ($errorOutput ? ": $errorOutput" : ''),
    ]);

    echo date('Y-m-d H:i:s') . " Server-side {$sj['task_type']} job #{$sj['id']}: {$result}\n";
}

// Step 5: Check storage locations for low disk space
$notificationService = $notificationService ?? new NotificationService();
$thresholdSetting = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_alert_threshold'");
$storageThreshold = (int) ($thresholdSetting['value'] ?? 90);

$storageLocations = $db->fetchAll("SELECT * FROM storage_locations");
foreach ($storageLocations as $loc) {
    $path = $loc['path'];
    if (!is_dir($path)) continue;

    $total = @disk_total_space($path);
    $free = @disk_free_space($path);
    if ($total === false || $free === false || $total == 0) continue;

    $usagePercent = round((($total - $free) / $total) * 100, 1);

    if ($usagePercent >= $storageThreshold) {
        $notificationService->notify('storage_low', null, $loc['id'], "Storage \"{$loc['label']}\" is at {$usagePercent}% capacity ({$path})", 'warning');
    } else {
        $notificationService->resolve('storage_low', null, $loc['id']);
    }
}

// Step 6: Cleanup old resolved notifications
$notificationService->cleanup();

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
