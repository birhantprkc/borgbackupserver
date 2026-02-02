<?php

namespace BBS\Services;

use BBS\Core\Database;

class BorgVersionService
{
    private Database $db;

    // Legacy 1.2.x asset name → platform metadata mapping
    private const LEGACY_ASSET_MAP = [
        'borg-linuxold64'   => ['platform' => 'linux', 'architecture' => 'x86_64', 'glibc_version' => 'glibc231'],
        'borg-linuxnew64'   => ['platform' => 'linux', 'architecture' => 'x86_64', 'glibc_version' => 'glibc235'],
        'borg-linuxnewer64' => ['platform' => 'linux', 'architecture' => 'x86_64', 'glibc_version' => 'glibc238'],
        'borg-macos64'      => ['platform' => 'macos', 'architecture' => 'x86_64', 'glibc_version' => null],
        'borg-freebsd64'    => ['platform' => 'freebsd', 'architecture' => 'x86_64', 'glibc_version' => null],
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Fetch borg releases from GitHub and store in database.
     * Returns summary of what was synced.
     */
    public function syncVersionsFromGitHub(): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: BorgBackupServer/1.0\r\n",
                'timeout' => 15,
            ],
        ]);

        // Fetch all releases (paginated — get up to 100)
        $url = 'https://api.github.com/repos/borgbackup/borg/releases?per_page=100';
        $json = @file_get_contents($url, false, $ctx);

        if ($json === false) {
            return ['error' => 'Could not reach GitHub API'];
        }

        $releases = json_decode($json, true);
        if (!is_array($releases)) {
            return ['error' => 'Invalid response from GitHub API'];
        }

        $added = 0;
        $skipped = 0;

        foreach ($releases as $release) {
            $tag = $release['tag_name'] ?? '';
            $version = ltrim($tag, 'v');

            // Skip pre-releases, betas, release candidates
            if ($release['prerelease'] ?? false) {
                $skipped++;
                continue;
            }
            if (preg_match('/(alpha|beta|rc|dev)/i', $version)) {
                $skipped++;
                continue;
            }

            // Skip if already stored
            $existing = $this->db->fetchOne(
                "SELECT id FROM borg_versions WHERE version = ?",
                [$version]
            );
            if ($existing) {
                continue;
            }

            // Extract release date
            $releaseDate = substr($release['published_at'] ?? $release['created_at'] ?? date('Y-m-d'), 0, 10);

            // Insert version
            $versionId = $this->db->insert('borg_versions', [
                'version' => $version,
                'release_tag' => $tag,
                'release_date' => $releaseDate,
                'is_prerelease' => 0,
                'release_notes' => $release['body'] ?? '',
            ]);

            // Process assets
            $assets = $release['assets'] ?? [];
            foreach ($assets as $asset) {
                $name = $asset['name'] ?? '';
                // Skip .tgz, .asc, .tar.gz, README files
                if (preg_match('/\.(tgz|asc|tar\.gz|txt)$/', $name)) {
                    continue;
                }

                $meta = $this->parseAssetMetadata($name);
                if ($meta === null) {
                    continue;
                }

                $this->db->query(
                    "INSERT IGNORE INTO borg_version_assets
                     (borg_version_id, platform, architecture, glibc_version, asset_name, download_url, file_size)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        $versionId,
                        $meta['platform'],
                        $meta['architecture'],
                        $meta['glibc_version'],
                        $name,
                        $asset['browser_download_url'] ?? '',
                        $asset['size'] ?? null,
                    ]
                );
            }

            $added++;
        }

        $this->setSetting('last_borg_version_check', date('Y-m-d H:i:s'));

        return [
            'added' => $added,
            'skipped' => $skipped,
            'total' => count($releases),
        ];
    }

    /**
     * Parse a borg binary asset filename into platform metadata.
     * Handles both 1.2.x (legacy) and 1.4.x (modern) naming conventions.
     */
    public function parseAssetMetadata(string $assetName): ?array
    {
        // Check legacy map first (1.2.x)
        if (isset(self::LEGACY_ASSET_MAP[$assetName])) {
            return self::LEGACY_ASSET_MAP[$assetName];
        }

        // 1.4.x Linux with arch: borg-linux-glibc235-x86_64[-gh]
        if (preg_match('/^borg-linux-(glibc\d+)-([a-z0-9_]+?)(-gh)?$/', $assetName, $m)) {
            return [
                'platform' => 'linux',
                'architecture' => $m[2],
                'glibc_version' => $m[1],
            ];
        }

        // 1.4.0 early Linux (no arch): borg-linux-glibc228 — assume x86_64
        if (preg_match('/^borg-linux-(glibc\d+)$/', $assetName, $m)) {
            return [
                'platform' => 'linux',
                'architecture' => 'x86_64',
                'glibc_version' => $m[1],
            ];
        }

        // 1.4.x macOS with arch: borg-macos-14-arm64[-gh]
        if (preg_match('/^borg-macos-\d+-([a-z0-9_]+?)(-gh)?$/', $assetName, $m)) {
            return [
                'platform' => 'macos',
                'architecture' => $m[1],
                'glibc_version' => null,
            ];
        }

        // 1.4.0 early macOS (no arch): borg-macos1012 — assume x86_64
        if (preg_match('/^borg-macos\d+$/', $assetName)) {
            return [
                'platform' => 'macos',
                'architecture' => 'x86_64',
                'glibc_version' => null,
            ];
        }

        // 1.4.x FreeBSD with arch: borg-freebsd-14-x86_64[-gh]
        if (preg_match('/^borg-freebsd-\d+-([a-z0-9_]+?)(-gh)?$/', $assetName, $m)) {
            return [
                'platform' => 'freebsd',
                'architecture' => $m[1],
                'glibc_version' => null,
            ];
        }

        // 1.4.0 early FreeBSD (no arch): borg-freebsd14 — assume x86_64
        if (preg_match('/^borg-freebsd\d+$/', $assetName)) {
            return [
                'platform' => 'freebsd',
                'architecture' => 'x86_64',
                'glibc_version' => null,
            ];
        }

        return null;
    }

    /**
     * Find the best matching binary asset for a given agent platform.
     * For Linux: picks the highest glibc version that is <= agent's glibc.
     */
    public function getAssetForPlatform(string $version, string $platform, string $arch, ?string $agentGlibc): ?array
    {
        $borgVersion = $this->db->fetchOne(
            "SELECT id FROM borg_versions WHERE version = ?",
            [$version]
        );
        if (!$borgVersion) {
            return null;
        }

        if ($platform === 'linux' && $agentGlibc !== null) {
            // Get all Linux assets for this version and arch, ordered by glibc descending
            $assets = $this->db->fetchAll(
                "SELECT * FROM borg_version_assets
                 WHERE borg_version_id = ? AND platform = 'linux' AND architecture = ?
                 ORDER BY glibc_version DESC",
                [$borgVersion['id'], $arch]
            );

            // Pick highest glibc that is <= agent's glibc
            foreach ($assets as $asset) {
                if ($asset['glibc_version'] !== null && $asset['glibc_version'] <= $agentGlibc) {
                    return $asset;
                }
            }

            // If no match, return the lowest glibc available (best compatibility)
            return !empty($assets) ? end($assets) : null;
        }

        // For macOS/FreeBSD: match platform + arch
        $asset = $this->db->fetchOne(
            "SELECT * FROM borg_version_assets
             WHERE borg_version_id = ? AND platform = ? AND architecture = ?
             LIMIT 1",
            [$borgVersion['id'], $platform, $arch]
        );

        return $asset ?: null;
    }

    /**
     * Get all stored borg versions, newest first.
     */
    public function getStoredVersions(): array
    {
        return $this->db->fetchAll(
            "SELECT v.*, COUNT(a.id) as asset_count
             FROM borg_versions v
             LEFT JOIN borg_version_assets a ON a.borg_version_id = v.id
             WHERE v.is_prerelease = 0
             GROUP BY v.id
             ORDER BY v.release_date DESC, v.version DESC"
        );
    }

    /**
     * Get assets for a specific version.
     */
    public function getVersionAssets(string $version): array
    {
        return $this->db->fetchAll(
            "SELECT a.* FROM borg_version_assets a
             JOIN borg_versions v ON v.id = a.borg_version_id
             WHERE v.version = ?
             ORDER BY a.platform, a.architecture",
            [$version]
        );
    }

    public function getTargetVersion(): string
    {
        return $this->getSetting('target_borg_version', '');
    }

    public function setTargetVersion(string $version): void
    {
        $this->setSetting('target_borg_version', $version);
    }

    public function getLastCheckTime(): string
    {
        return $this->getSetting('last_borg_version_check', '');
    }

    public function isFallbackToPipEnabled(): bool
    {
        return $this->getSetting('fallback_to_pip', '1') === '1';
    }

    /**
     * Get agents that are not at the target version.
     */
    public function getOutdatedAgents(string $targetVersion): array
    {
        if (empty($targetVersion)) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT id, name, hostname, borg_version, borg_install_method, status
             FROM agents
             WHERE (borg_version IS NULL OR borg_version != ?)
             AND status != 'setup'
             ORDER BY name",
            [$targetVersion]
        );
    }

    /**
     * Get all agents with borg version info.
     */
    public function getAllAgentVersions(): array
    {
        return $this->db->fetchAll(
            "SELECT id, name, hostname, borg_version, borg_install_method, borg_binary_path, status
             FROM agents
             WHERE status != 'setup'
             ORDER BY name"
        );
    }

    /**
     * Check if setting a target version would be a downgrade for any agent.
     */
    public function getAgentsAboveVersion(string $version): array
    {
        $agents = $this->db->fetchAll(
            "SELECT id, name, borg_version FROM agents
             WHERE borg_version IS NOT NULL AND status != 'setup'"
        );

        $above = [];
        foreach ($agents as $agent) {
            $agentVer = preg_replace('/^borg\s+/', '', $agent['borg_version']);
            if (version_compare($agentVer, $version, '>')) {
                $above[] = $agent;
            }
        }

        return $above;
    }

    private function getSetting(string $key, string $default = ''): string
    {
        $row = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
        return $row['value'] ?? $default;
    }

    private function setSetting(string $key, string $value): void
    {
        $existing = $this->db->fetchOne("SELECT `key` FROM settings WHERE `key` = ?", [$key]);
        if ($existing) {
            $this->db->update('settings', ['value' => $value], "`key` = ?", [$key]);
        } else {
            $this->db->query("INSERT INTO settings (`key`, `value`) VALUES (?, ?)", [$key, $value]);
        }
    }
}
