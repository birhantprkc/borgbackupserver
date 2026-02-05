<?php

namespace BBS\Services;

use BBS\Core\Database;

class AppriseService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function isAppriseInstalled(): bool
    {
        exec('which apprise 2>/dev/null', $output, $code);
        return $code === 0;
    }

    /**
     * Get all enabled services that should receive notifications for a given event type.
     */
    public function getEnabledServicesForEvent(string $eventType): array
    {
        $services = $this->db->fetchAll(
            "SELECT * FROM notification_services WHERE enabled = 1"
        );

        // Filter by event type (check JSON field)
        return array_filter($services, function ($service) use ($eventType) {
            $events = json_decode($service['events'] ?? '{}', true) ?: [];
            return !empty($events[$eventType]);
        });
    }

    /**
     * Send notification to a specific service by ID.
     */
    public function sendToService(int $serviceId, string $title, string $body): bool
    {
        $service = $this->db->fetchOne("SELECT * FROM notification_services WHERE id = ?", [$serviceId]);
        if (!$service || !$service['enabled']) {
            return false;
        }

        if (!$this->isAppriseInstalled()) {
            return false;
        }

        try {
            $titleEscaped = escapeshellarg($title);
            $bodyEscaped = escapeshellarg($body);
            $urlEscaped = escapeshellarg($service['apprise_url']);

            $cmd = "apprise -t {$titleEscaped} -b {$bodyEscaped} {$urlEscaped} > /dev/null 2>&1 &";
            exec($cmd);

            // Update last_used_at
            $this->db->update('notification_services', ['last_used_at' => date('Y-m-d H:i:s')], 'id = ?', [$serviceId]);

            return true;
        } catch (\Exception $e) {
            error_log("Apprise error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification to all enabled services for a given event type.
     */
    public function sendForEvent(string $eventType, string $title, string $body): int
    {
        if (!$this->isAppriseInstalled()) {
            return 0;
        }

        $services = $this->getEnabledServicesForEvent($eventType);
        $sent = 0;

        foreach ($services as $service) {
            if ($this->sendToService($service['id'], $title, $body)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Check if any notification services are configured and enabled.
     */
    public function hasEnabledServices(): bool
    {
        $count = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM notification_services WHERE enabled = 1");
        return (int) ($count['cnt'] ?? 0) > 0;
    }

    // -------------------------------------------------------------------------
    // Legacy methods for backward compatibility with old settings-based config
    // These can be removed once all installs have migrated to the new table
    // -------------------------------------------------------------------------

    private ?array $legacyUrls = null;

    private function getLegacyUrls(): array
    {
        if ($this->legacyUrls === null) {
            $setting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'apprise_urls'");
            $urlText = $setting['value'] ?? '';
            $this->legacyUrls = array_filter(
                array_map('trim', explode("\n", $urlText)),
                fn($url) => !empty($url) && !str_starts_with($url, '#')
            );
        }
        return $this->legacyUrls;
    }

    /**
     * @deprecated Use hasEnabledServices() instead
     */
    public function isEnabled(): bool
    {
        // Check new table first
        if ($this->hasEnabledServices()) {
            return true;
        }
        // Fall back to legacy settings
        return !empty($this->getLegacyUrls()) && $this->isAppriseInstalled();
    }

    /**
     * @deprecated Use sendForEvent() instead
     */
    public function send(string $title, string $body): bool
    {
        if (!$this->isAppriseInstalled()) {
            return false;
        }

        // Try new table first
        if ($this->hasEnabledServices()) {
            // This is called from NotificationService which handles event filtering
            // so we can't use sendForEvent here - the caller should switch to the new API
            return false;
        }

        // Fall back to legacy settings
        $urls = $this->getLegacyUrls();
        if (empty($urls)) {
            return false;
        }

        try {
            $titleEscaped = escapeshellarg($title);
            $bodyEscaped = escapeshellarg($body);
            $urlArgs = implode(' ', array_map('escapeshellarg', $urls));

            $cmd = "apprise -t {$titleEscaped} -b {$bodyEscaped} {$urlArgs} > /dev/null 2>&1 &";
            exec($cmd);

            return true;
        } catch (\Exception $e) {
            error_log("Apprise error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @deprecated Use the new notification services UI for testing
     */
    public function test(): array
    {
        $urls = $this->getLegacyUrls();

        if (empty($urls)) {
            return ['success' => false, 'error' => 'No Apprise URLs configured.'];
        }

        if (!$this->isAppriseInstalled()) {
            return ['success' => false, 'error' => 'Apprise is not installed. Run: pip3 install apprise'];
        }

        try {
            $title = escapeshellarg('BBS Test Notification');
            $body = escapeshellarg('This is a test notification from Borg Backup Server. If you receive this, Apprise is configured correctly.');
            $urlArgs = implode(' ', array_map('escapeshellarg', $urls));

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
