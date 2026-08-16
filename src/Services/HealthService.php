<?php

namespace BBS\Services;

use BBS\Core\Database;

/**
 * Health checks for external monitoring.
 *
 * Answers "is this server doing its job", which is not the same as "is the web
 * app up". A BBS server can serve pages perfectly while the scheduler is dead
 * and nothing has been backed up for a day — so the checks cover the parts
 * that actually fail quietly.
 *
 * Three states, and the overall status is the worst of them:
 *
 *   ok       — nothing to do
 *   warning  — needs attention, backups are still running
 *   critical — backups are not happening, or are about to stop
 *
 * Callers map that onto HTTP: 200 for ok and warning, 503 for critical, so a
 * monitor that only understands status codes still pages on the right things.
 */
class HealthService
{
    /** The scheduler runs every minute; these are how late is too late. */
    private const SCHEDULER_WARN_SECONDS = 300;      // 5 minutes
    private const SCHEDULER_CRITICAL_SECONDS = 900;  // 15 minutes

    /** Storage fullness, overridable per install. */
    private const STORAGE_WARN_PERCENT = 90;
    private const STORAGE_CRITICAL_PERCENT = 95;

    public const OK = 'ok';
    public const WARNING = 'warning';
    public const CRITICAL = 'critical';

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    private function setting(string $key, ?string $default = null): ?string
    {
        $row = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
        return ($row['value'] ?? '') !== '' ? $row['value'] : $default;
    }

    /** Worst of a set of statuses. */
    private static function worst(array $statuses): string
    {
        if (in_array(self::CRITICAL, $statuses, true)) return self::CRITICAL;
        if (in_array(self::WARNING, $statuses, true))  return self::WARNING;
        return self::OK;
    }

    /**
     * Cheap liveness check for an unauthenticated endpoint.
     *
     * Deliberately only answers "is the application able to serve and reach
     * its database". No detail: this is reachable without credentials, and it
     * may be polled every few seconds, so it must neither disclose anything
     * nor cost anything.
     */
    public function liveness(): array
    {
        try {
            $this->db->fetchOne("SELECT 1 AS ok");
            return ['status' => self::OK];
        } catch (\Exception $e) {
            return ['status' => self::CRITICAL];
        }
    }

    /**
     * The full picture, for an authenticated caller.
     */
    public function check(): array
    {
        $checks = [];
        $checks['database']    = $this->checkDatabase();
        $checks['scheduler']   = $this->checkScheduler();
        $checks['storage']     = $this->checkStorage();
        $checks['catalog']     = $this->checkCatalog();
        $checks['clients']     = $this->checkClients();
        $checks['backups']     = $this->checkBackups();
        $checks['maintenance'] = $this->checkMaintenance();

        $overall = self::worst(array_column($checks, 'status'));

        $problems = [];
        foreach ($checks as $name => $c) {
            if ($c['status'] !== self::OK) {
                $problems[] = $name . ': ' . $c['message'];
            }
        }

        return [
            'status' => $overall,
            'version' => trim(@file_get_contents(dirname(__DIR__, 2) . '/VERSION') ?: '') ?: null,
            'checked_at' => date('c'),
            'problems' => $problems,
            'checks' => $checks,
        ];
    }

    private function checkDatabase(): array
    {
        try {
            $this->db->fetchOne("SELECT 1 AS ok");
            return ['status' => self::OK, 'message' => 'Reachable'];
        } catch (\Exception $e) {
            return ['status' => self::CRITICAL, 'message' => 'Not reachable'];
        }
    }

    /**
     * The check that matters most. Agents keep backing up on their own poll,
     * so a dead scheduler looks like a working system right up until the
     * queue fills with server-side work that never runs.
     */
    private function checkScheduler(): array
    {
        $last = $this->setting('scheduler_last_run');
        if (!$last) {
            return [
                'status' => self::CRITICAL,
                'message' => 'Has never run — check that the scheduler cron job is installed and enabled',
                'last_run' => null,
                'seconds_ago' => null,
            ];
        }

        $age = time() - strtotime($last);
        $status = self::OK;
        $message = 'Running';
        if ($age >= self::SCHEDULER_CRITICAL_SECONDS) {
            $status = self::CRITICAL;
            $message = sprintf(
                'Last ran %d minutes ago — scheduled backups, prunes and catalog work are not being queued',
                round($age / 60)
            );
        } elseif ($age >= self::SCHEDULER_WARN_SECONDS) {
            $status = self::WARNING;
            $message = sprintf('Last ran %d minutes ago (expected every minute)', round($age / 60));
        }

        return [
            'status' => $status,
            'message' => $message,
            'last_run' => $last,
            'seconds_ago' => $age,
        ];
    }

    /**
     * Local storage locations and remote SSH targets, from the figures the
     * scheduler already collects for the low-storage alerts.
     */
    private function checkStorage(): array
    {
        // The same number the low-storage alerts use (Settings → General).
        // Health and the notification used to disagree — health warned at a
        // hardcoded 85 while the server setting said 90 — which made the
        // endpoint look wrong to anyone who had set the field.
        $warnAt = (int) $this->setting('storage_alert_threshold', (string) self::STORAGE_WARN_PERCENT);
        if ($warnAt < 1 || $warnAt > 100) {
            $warnAt = self::STORAGE_WARN_PERCENT;
        }
        // Critical stays above the warning even when the warning is set high,
        // so a threshold of 97 doesn't report every location as critical.
        $critAt = max(self::STORAGE_CRITICAL_PERCENT, min(100, $warnAt + 1));

        $locations = [];
        $statuses = [];

        $rows = $this->db->fetchAll("SELECT label, path FROM storage_locations ORDER BY id");
        if (empty($rows)) {
            $path = $this->setting('storage_path');
            if ($path) {
                $rows = [['label' => 'Default', 'path' => $path]];
            }
        }

        foreach ($rows as $sl) {
            $path = $sl['path'] ?? '';
            if ($path === '' || !is_dir($path)) {
                $locations[] = [
                    'name' => $sl['label'] ?: $path,
                    'status' => self::WARNING,
                    'message' => 'Path is not accessible',
                ];
                $statuses[] = self::WARNING;
                continue;
            }
            $total = @disk_total_space($path);
            $free = @disk_free_space($path);
            if ($total === false || $free === false || $total <= 0) {
                continue;
            }
            $locations[] = $this->storageEntry($sl['label'] ?: $path, (int) $total, (int) $free, $warnAt, $critAt, $statuses);
        }

        foreach ($this->db->fetchAll(
            "SELECT name, disk_total_bytes, disk_free_bytes FROM remote_ssh_configs
             WHERE disk_total_bytes IS NOT NULL AND disk_total_bytes > 0"
        ) as $rc) {
            $locations[] = $this->storageEntry(
                $rc['name'], (int) $rc['disk_total_bytes'], (int) $rc['disk_free_bytes'], $warnAt, $critAt, $statuses
            );
        }

        if (empty($locations)) {
            return ['status' => self::OK, 'message' => 'No storage locations configured', 'locations' => []];
        }

        $status = self::worst($statuses);
        $bad = array_values(array_filter($locations, fn($l) => ($l['status'] ?? self::OK) !== self::OK));
        $message = $status === self::OK
            ? sprintf('%d location(s) with space available', count($locations))
            : implode('; ', array_map(fn($l) => $l['name'] . ' ' . $l['message'], $bad));

        return ['status' => $status, 'message' => $message, 'locations' => $locations];
    }

    private function storageEntry(string $name, int $total, int $free, int $warnAt, int $critAt, array &$statuses): array
    {
        $usedPercent = round((($total - $free) / $total) * 100, 1);
        $status = self::OK;
        $message = 'OK';
        if ($usedPercent >= $critAt) {
            $status = self::CRITICAL;
            $message = sprintf('is %.1f%% full — backups will start failing', $usedPercent);
        } elseif ($usedPercent >= $warnAt) {
            $status = self::WARNING;
            $message = sprintf('is %.1f%% full', $usedPercent);
        }
        $statuses[] = $status;

        return [
            'name' => $name,
            'status' => $status,
            'message' => $message,
            'used_percent' => $usedPercent,
            'total_bytes' => $total,
            'free_bytes' => $free,
        ];
    }

    /** ClickHouse backs file browsing and restore; backups run without it. */
    private function checkCatalog(): array
    {
        try {
            $available = \BBS\Core\ClickHouse::getInstance()->isAvailable();
        } catch (\Exception $e) {
            $available = false;
        }
        return $available
            ? ['status' => self::OK, 'message' => 'Reachable']
            : ['status' => self::WARNING,
               'message' => 'Catalog engine not reachable — browsing and file-level restore are unavailable, backups are unaffected'];
    }

    private function checkClients(): array
    {
        $counts = ['online' => 0, 'offline' => 0, 'error' => 0, 'setup' => 0];
        foreach ($this->db->fetchAll("SELECT status, COUNT(*) c FROM agents GROUP BY status") as $r) {
            $counts[$r['status']] = (int) $r['c'];
        }
        $total = array_sum($counts);

        // Clients still being set up have never checked in, so they are not a
        // fault. Ones that went offline after working are.
        $status = self::OK;
        $message = sprintf('%d of %d online', $counts['online'], $total);
        if ($counts['error'] > 0) {
            $status = self::WARNING;
            $message = sprintf('%d client(s) reporting an error', $counts['error']);
        } elseif ($counts['offline'] > 0) {
            $status = self::WARNING;
            $message = sprintf('%d of %d client(s) offline', $counts['offline'], $total);
        }

        return ['status' => $status, 'message' => $message, 'counts' => $counts];
    }

    /**
     * Are backups actually happening?
     *
     * This used to warn whenever any backup had failed in the last 24 hours,
     * which made the endpoint unusable for monitoring: a laptop switched off
     * for the weekend fails its scheduled runs, and that is a laptop, not a
     * fault (#409). Retry attempts could not stand in for a threshold either —
     * capped at ten they cover about eight hours, and raising the cap only
     * stacks retries on top of the next day's scheduled runs.
     *
     * So the question is per client and per profile: has this machine gone
     * longer than its kind is allowed to go without a successful backup?
     * Fourteen days for laptops, a day for a database server.
     */
    private function checkBackups(): array
    {
        $profiles = new \BBS\Services\ClientProfileService();
        $globalOverdue = max(1, $profiles->globalFailureSetting('backup_overdue_hours'));

        // Only clients that are supposed to be backing up. One with no enabled
        // plan is not overdue, it is unconfigured, and saying otherwise would
        // put every half-set-up client into the monitoring signal.
        $rows = $this->db->fetchAll("
            SELECT a.id, a.name,
                   COALESCE(cp.backup_overdue_hours, {$globalOverdue}) AS overdue_hours,
                   (SELECT MAX(bj.completed_at) FROM backup_jobs bj
                     WHERE bj.agent_id = a.id AND bj.task_type = 'backup'
                       AND bj.status = 'completed') AS last_success,
                   a.created_at
            FROM agents a
            LEFT JOIN client_profiles cp ON cp.id = a.client_profile_id
            WHERE EXISTS (
                SELECT 1 FROM backup_plans bp WHERE bp.agent_id = a.id AND bp.enabled = 1
            )
        ");

        $overdue = [];
        foreach ($rows as $r) {
            $hours = max(1, (int) $r['overdue_hours']);
            // Never backed up: measure from when the client was added, so a
            // machine that has never worked shows up rather than hiding behind
            // a null.
            $since = $r['last_success'] ?: $r['created_at'];
            if (!$since) {
                continue;
            }
            $ageHours = (time() - strtotime($since)) / 3600;
            if ($ageHours > $hours) {
                $overdue[] = [
                    // The id so a client of this API can link the row to the
                    // client it names — the obvious next tap, and it was in
                    // hand here all along.
                    'client_id' => (int) $r['id'],
                    'client' => $r['name'],
                    'last_success' => $r['last_success'],
                    'hours_since' => (int) round($ageHours),
                    'allowed_hours' => $hours,
                ];
            }
        }

        $stallMinutes = max(1, (int) $this->setting('stall_timeout_minutes', '120'));
        // Interpolated, not bound: MySQL will not accept a placeholder for an
        // INTERVAL quantity. Cast to int first so it is safe to inline.
        $stalled = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) c FROM backup_jobs
             WHERE status IN ('sent', 'running')
               AND COALESCE(last_progress_at, started_at, queued_at) < DATE_SUB(NOW(), INTERVAL {$stallMinutes} MINUTE)"
        )['c'] ?? 0);

        $running = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) c FROM backup_jobs WHERE status IN ('queued', 'sent', 'running')"
        )['c'] ?? 0);

        // Reported for context, never for status: a failure inside a client's
        // allowance is exactly what the allowance is for.
        $failed = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) c FROM backup_jobs
             WHERE status = 'failed' AND completed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        )['c'] ?? 0);

        $status = self::OK;
        $message = 'Every client has backed up within its allowance';
        if (!empty($overdue)) {
            $status = self::WARNING;
            $names = array_map(
                fn($o) => sprintf('%s (%dh, allows %dh)', $o['client'], $o['hours_since'], $o['allowed_hours']),
                array_slice($overdue, 0, 5)
            );
            $message = sprintf('%d client(s) overdue: %s', count($overdue), implode(', ', $names));
            if (count($overdue) > 5) {
                $message .= sprintf(' and %d more', count($overdue) - 5);
            }
        } elseif ($stalled > 0) {
            $status = self::WARNING;
            $message = sprintf('%d job(s) with no progress for over %d minutes', $stalled, $stallMinutes);
        }

        return [
            'status' => $status,
            'message' => $message,
            'overdue_clients' => count($overdue),
            'overdue' => $overdue,
            'default_overdue_hours' => $globalOverdue,
            'failed_24h' => $failed,
            'stalled' => $stalled,
            'in_queue' => $running,
        ];
    }

    /** Maintenance mode stops new backups being scheduled — easy to leave on. */
    private function checkMaintenance(): array
    {
        $on = $this->setting('maintenance_mode', '0') === '1';
        return $on
            ? ['status' => self::WARNING, 'message' => 'Maintenance mode is on — no new backups are being scheduled', 'enabled' => true]
            : ['status' => self::OK, 'message' => 'Off', 'enabled' => false];
    }
}
