<?php

namespace BBS\Services;

use BBS\Core\Database;

class AppriseService
{
    private Database $db;
    private array $urls;
    private bool $enabled;

    public function __construct()
    {
        $this->db = Database::getInstance();

        $setting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'apprise_urls'");
        $urlText = $setting['value'] ?? '';

        $this->urls = array_filter(
            array_map('trim', explode("\n", $urlText)),
            fn($url) => !empty($url) && !str_starts_with($url, '#')
        );

        $this->enabled = !empty($this->urls) && $this->isAppriseInstalled();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isAppriseInstalled(): bool
    {
        exec('which apprise 2>/dev/null', $output, $code);
        return $code === 0;
    }

    /**
     * Send notification via Apprise CLI (runs in background).
     */
    public function send(string $title, string $body): bool
    {
        if (!$this->enabled) {
            return false;
        }

        try {
            $titleEscaped = escapeshellarg($title);
            $bodyEscaped = escapeshellarg($body);
            $urlArgs = implode(' ', array_map('escapeshellarg', $this->urls));

            $cmd = "apprise -t {$titleEscaped} -b {$bodyEscaped} {$urlArgs} > /dev/null 2>&1 &";
            exec($cmd);

            return true;
        } catch (\Exception $e) {
            error_log("Apprise error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Test Apprise by sending a test notification (synchronous).
     */
    public function test(): array
    {
        if (empty($this->urls)) {
            return ['success' => false, 'error' => 'No Apprise URLs configured.'];
        }

        if (!$this->isAppriseInstalled()) {
            return ['success' => false, 'error' => 'Apprise is not installed. Run: pip3 install apprise'];
        }

        try {
            $title = escapeshellarg('BBS Test Notification');
            $body = escapeshellarg('This is a test notification from Borg Backup Server. If you receive this, Apprise is configured correctly.');
            $urlArgs = implode(' ', array_map('escapeshellarg', $this->urls));

            $cmd = "apprise -t {$title} -b {$body} {$urlArgs} 2>&1";
            exec($cmd, $output, $code);

            if ($code === 0) {
                return ['success' => true];
            }

            return ['success' => false, 'error' => implode("\n", $output) ?: 'Apprise command failed.'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
