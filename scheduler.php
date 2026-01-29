#!/usr/bin/env php
<?php
/**
 * Scheduler CLI - Run via cron every minute:
 *   * * * * * php /path/to/borgbackupserver/scheduler.php
 *
 * 1. Checks all enabled schedules for due jobs and creates them as 'queued'
 * 2. Processes the queue, promoting 'queued' jobs to 'sent' (up to max_queue)
 */

require_once __DIR__ . '/vendor/autoload.php';

use BBS\Core\Config;
use BBS\Services\SchedulerService;
use BBS\Services\QueueManager;

Config::load();

// Step 1: Check schedules and create queued jobs
$scheduler = new SchedulerService();
$created = $scheduler->run();

foreach ($created as $job) {
    echo date('Y-m-d H:i:s') . " Queued: {$job['plan']} (job #{$job['job_id']}, agent #{$job['agent_id']})\n";
}

// Step 2: Process queue - promote queued jobs to sent
$queueManager = new QueueManager();
$promoted = $queueManager->processQueue();

foreach ($promoted as $job) {
    echo date('Y-m-d H:i:s') . " Sent: job #{$job['id']} ({$job['task_type']}) to agent #{$job['agent_id']}\n";
}

if (empty($created) && empty($promoted)) {
    // Silent when nothing to do (cron-friendly)
}

// Step 3: Mark agents offline if no heartbeat in 3x poll interval
$db = \BBS\Core\Database::getInstance();
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
}
