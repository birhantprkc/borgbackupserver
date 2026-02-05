<?php

namespace BBS\Services;

class ServerStats
{
    /**
     * Get CPU load averages (1, 5, 15 min).
     */
    public static function getCpuLoad(): array
    {
        $load = sys_getloadavg();
        if ($load === false) {
            return ['1min' => 0, '5min' => 0, '15min' => 0, 'cores' => 1, 'percent' => 0];
        }

        $cores = self::getCpuCores();
        $percent = round(($load[0] / max($cores, 1)) * 100, 1);

        return [
            '1min' => round($load[0], 2),
            '5min' => round($load[1], 2),
            '15min' => round($load[2], 2),
            'cores' => $cores,
            'percent' => min($percent, 100),
        ];
    }

    /**
     * Get number of CPU cores.
     */
    private static function getCpuCores(): int
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $result = trim(shell_exec('sysctl -n hw.ncpu 2>/dev/null') ?? '');
        } else {
            $result = trim(shell_exec('nproc 2>/dev/null') ?? '');
        }
        return max((int) $result, 1);
    }

    /**
     * Get memory usage.
     */
    public static function getMemory(): array
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return self::getMemoryMac();
        }
        return self::getMemoryLinux();
    }

    private static function getMemoryLinux(): array
    {
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo === false) {
            return ['total' => 0, 'used' => 0, 'free' => 0, 'percent' => 0];
        }

        $values = [];
        foreach (explode("\n", $meminfo) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s+kB/', $line, $m)) {
                $values[$m[1]] = (int) $m[2] * 1024; // Convert to bytes
            }
        }

        $total = $values['MemTotal'] ?? 0;
        $available = $values['MemAvailable'] ?? ($values['MemFree'] ?? 0);
        $used = $total - $available;

        return [
            'total' => $total,
            'used' => $used,
            'free' => $available,
            'percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
        ];
    }

    private static function getMemoryMac(): array
    {
        $total = (int) trim(shell_exec('sysctl -n hw.memsize 2>/dev/null') ?? '0');
        $pageSize = (int) trim(shell_exec('sysctl -n hw.pagesize 2>/dev/null') ?? '4096');

        // Parse vm_stat for page counts
        $vmstat = shell_exec('vm_stat 2>/dev/null') ?? '';
        $pages = [];
        foreach (explode("\n", $vmstat) as $line) {
            if (preg_match('/^(.+?):\s+(\d+)/', $line, $m)) {
                $pages[trim($m[1])] = (int) $m[2];
            }
        }

        $free = ($pages['Pages free'] ?? 0) * $pageSize;
        $inactive = ($pages['Pages inactive'] ?? 0) * $pageSize;
        $available = $free + $inactive;
        $used = $total - $available;

        return [
            'total' => $total,
            'used' => max($used, 0),
            'free' => $available,
            'percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Get disk partition usage.
     */
    public static function getPartitions(): array
    {
        $partitions = [];

        if (PHP_OS_FAMILY === 'Darwin') {
            // macOS: standard df output
            $output = shell_exec('df -h / 2>/dev/null') ?? '';
            $useExtendedFormat = false;
        } else {
            // Linux: try extended format first, fallback to standard
            $output = shell_exec('df -h --output=source,fstype,size,used,avail,pcent,target -x tmpfs -x devtmpfs -x squashfs 2>/dev/null') ?? '';
            $useExtendedFormat = !empty(trim($output));
            if (!$useExtendedFormat) {
                $output = shell_exec('df -h -x tmpfs -x devtmpfs 2>/dev/null') ?? '';
            }
        }

        $lines = explode("\n", trim($output));
        array_shift($lines); // Remove header

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 6) continue;

            // Skip non-physical filesystems
            $device = $parts[0];
            if (str_starts_with($device, 'tmpfs') || str_starts_with($device, 'devtmpfs')) {
                continue;
            }

            $mount = end($parts);

            // Skip bind mounts to files (not directories) - common in Docker
            if (!is_dir($mount)) {
                continue;
            }

            if ($useExtendedFormat && count($parts) >= 7) {
                // Extended format: source, fstype, size, used, avail, pcent, target
                // Indices:         0       1       2     3     4      5      6
                $pct = (int) str_replace('%', '', $parts[5] ?? '0');
                $partitions[] = [
                    'mount' => $mount,
                    'size' => $parts[2] ?? '--',
                    'used' => $parts[3] ?? '--',
                    'free' => $parts[4] ?? '--',
                    'percent' => $pct,
                ];
            } else {
                // Standard df -h output: Filesystem, Size, Used, Avail, Use%, Mounted on
                // Indices:                0           1     2     3      4     5
                $pct = (int) str_replace('%', '', $parts[4] ?? '0');
                $partitions[] = [
                    'mount' => $mount,
                    'size' => $parts[1] ?? '--',
                    'used' => $parts[2] ?? '--',
                    'free' => $parts[3] ?? '--',
                    'percent' => $pct,
                ];
            }
        }

        // Ensure /var/bbs is represented if it exists (df deduplicates by device,
        // so in Docker with XFS project quotas /var/bbs may be hidden behind
        // another mount from the same device like /entrypoint.sh)
        $hasVarBbs = false;
        foreach ($partitions as $p) {
            if ($p['mount'] === '/var/bbs') {
                $hasVarBbs = true;
                break;
            }
        }
        if (!$hasVarBbs && is_dir('/var/bbs')) {
            $total = @disk_total_space('/var/bbs');
            $free = @disk_free_space('/var/bbs');
            if ($total && $total > 0) {
                $used = $total - $free;
                $pct = (int) round(($used / $total) * 100);
                $partitions[] = [
                    'mount' => '/var/bbs',
                    'size' => self::formatDfSize($total),
                    'used' => self::formatDfSize($used),
                    'free' => self::formatDfSize($free),
                    'percent' => $pct,
                ];
            }
        }

        return $partitions;
    }

    private static function formatDfSize(float $bytes): string
    {
        if ($bytes >= 1099511627776) return round($bytes / 1099511627776, 1) . 'T';
        if ($bytes >= 1073741824) return round($bytes / 1073741824) . 'G';
        if ($bytes >= 1048576) return round($bytes / 1048576) . 'M';
        return round($bytes / 1024) . 'K';
    }

    /**
     * Get MySQL row counts and interesting aggregate stats.
     */
    public static function getMysqlStats(): array
    {
        $db = \BBS\Core\Database::getInstance();

        $catalogFiles = $db->fetchOne("SELECT COUNT(*) AS cnt FROM file_paths");
        $archives = $db->fetchOne("SELECT COUNT(*) AS cnt FROM archives");
        $jobs = $db->fetchOne("SELECT COALESCE(MAX(id), 0) AS cnt FROM backup_jobs");

        // MySQL performance stats from SHOW GLOBAL STATUS
        $statusVars = [];
        $rows = $db->fetchAll("SHOW GLOBAL STATUS WHERE Variable_name IN (
            'Uptime', 'Questions', 'Threads_connected', 'Threads_running',
            'Innodb_buffer_pool_read_requests', 'Innodb_buffer_pool_reads',
            'Innodb_buffer_pool_pages_total', 'Innodb_buffer_pool_pages_free',
            'Bytes_received', 'Bytes_sent', 'Slow_queries'
        )");
        foreach ($rows as $r) {
            $statusVars[$r['Variable_name']] = (int) $r['Value'];
        }

        // Buffer pool size from variables
        $bpVar = $db->fetchOne("SHOW VARIABLES LIKE 'innodb_buffer_pool_size'");
        $bufferPoolSize = (int) ($bpVar['Value'] ?? 0);

        $uptime = $statusVars['Uptime'] ?? 1;
        $questions = $statusVars['Questions'] ?? 0;
        $readRequests = $statusVars['Innodb_buffer_pool_read_requests'] ?? 0;
        $diskReads = $statusVars['Innodb_buffer_pool_reads'] ?? 0;
        $pagesTotal = $statusVars['Innodb_buffer_pool_pages_total'] ?? 1;
        $pagesFree = $statusVars['Innodb_buffer_pool_pages_free'] ?? 0;

        // Calculate real-time QPS from delta between polls
        $cache = Cache::getInstance();
        $prevSnapshot = $cache->get('mysql_qps_snapshot');
        if ($prevSnapshot && isset($prevSnapshot['questions'], $prevSnapshot['uptime'])) {
            $dQuestions = $questions - $prevSnapshot['questions'];
            $dUptime = $uptime - $prevSnapshot['uptime'];
            $qps = $dUptime > 0 ? round($dQuestions / $dUptime, 1) : 0;
        } else {
            // First call: fall back to lifetime average
            $qps = round($questions / max($uptime, 1), 1);
        }
        $cache->set('mysql_qps_snapshot', ['questions' => $questions, 'uptime' => $uptime], 300);
        $hitRate = $readRequests > 0
            ? round((1 - $diskReads / $readRequests) * 100, 2)
            : 100.0;
        $bufferPoolUsedPct = round((($pagesTotal - $pagesFree) / max($pagesTotal, 1)) * 100, 1);

        return [
            'catalog_files' => (int) ($catalogFiles['cnt'] ?? 0),
            'archives' => (int) ($archives['cnt'] ?? 0),
            'completed_jobs' => (int) ($jobs['cnt'] ?? 0),
            'qps' => $qps,
            'threads_connected' => $statusVars['Threads_connected'] ?? 0,
            'threads_running' => $statusVars['Threads_running'] ?? 0,
            'buffer_pool_size' => $bufferPoolSize,
            'buffer_pool_used_pct' => $bufferPoolUsedPct,
            'hit_rate' => $hitRate,
            'uptime' => $uptime,
            'slow_queries' => $statusVars['Slow_queries'] ?? 0,
        ];
    }

    /**
     * Get MySQL database size and the free space on its data partition.
     */
    public static function getMysqlStorage(): array
    {
        $db = \BBS\Core\Database::getInstance();

        // Total size of all tables in the current database
        $row = $db->fetchOne("
            SELECT SUM(data_length + index_length) AS db_bytes
            FROM information_schema.TABLES
            WHERE table_schema = DATABASE()
        ");
        $dbBytes = (int) ($row['db_bytes'] ?? 0);

        // Find MySQL data directory and get partition free space
        $dataDirRow = $db->fetchOne("SHOW VARIABLES LIKE 'datadir'");
        $dataDir = $dataDirRow['Value'] ?? '/var/lib/mysql';

        $diskTotal = 0;
        $diskFree = 0;
        if (is_dir($dataDir)) {
            $diskTotal = (int) @disk_total_space($dataDir);
            $diskFree = (int) @disk_free_space($dataDir);
        }

        return [
            'db_bytes' => $dbBytes,
            'disk_total' => $diskTotal,
            'disk_free' => $diskFree,
            'disk_used' => $diskTotal - $diskFree,
        ];
    }

    /**
     * Format bytes to human readable.
     */
    public static function formatBytes(int $bytes, int $precision = 1): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return round($size, $precision) . $units[$i];
    }
}
