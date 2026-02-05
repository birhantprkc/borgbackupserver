<?php

namespace BBS\Services;

use BBS\Core\Database;

class NotificationService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function notify(string $type, ?int $agentId, ?int $referenceId, string $message, string $severity = 'warning'): void
    {
        // Look for existing unresolved notification with same grouping key
        $params = [$type];
        $agentClause = $agentId !== null ? 'agent_id = ?' : 'agent_id IS NULL';
        if ($agentId !== null) $params[] = $agentId;
        $refClause = $referenceId !== null ? 'reference_id = ?' : 'reference_id IS NULL';
        if ($referenceId !== null) $params[] = $referenceId;

        $existing = $this->db->fetchOne(
            "SELECT id FROM notifications WHERE type = ? AND {$agentClause} AND {$refClause} AND resolved_at IS NULL",
            $params
        );

        $isNew = false;

        if ($existing) {
            $this->db->query(
                "UPDATE notifications SET occurrence_count = occurrence_count + 1, last_occurred_at = NOW(), message = ?, severity = ?, read_at = NULL WHERE id = ?",
                [$message, $severity, $existing['id']]
            );
        } else {
            $this->db->insert('notifications', [
                'type' => $type,
                'agent_id' => $agentId,
                'reference_id' => $referenceId,
                'severity' => $severity,
                'message' => $message,
            ]);
            $isNew = true;
        }

        // Send notifications on first occurrence only (avoid spamming on repeats)
        if ($isNew) {
            $this->sendEmailIfEnabled($type, $message);
            $this->sendAppriseIfEnabled($type, $message);
        }
    }

    private function sendEmailIfEnabled(string $type, string $message): void
    {
        $settingKey = 'email_on_' . $type;
        $setting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$settingKey]);

        if (($setting['value'] ?? '0') !== '1') {
            return;
        }

        try {
            $mailer = new Mailer();
            if (!$mailer->isEnabled()) return;

            $labels = [
                'backup_failed' => 'Backup Failed',
                'agent_offline' => 'Client Offline',
                'storage_low' => 'Storage Low',
                'missed_schedule' => 'Missed Schedule',
            ];
            $subject = '[BBS] ' . ($labels[$type] ?? ucfirst($type));

            $body = $message . "\n\n"
                  . "Time: " . date('Y-m-d H:i:s') . "\n"
                  . "-- Borg Backup Server";

            $admins = $this->db->fetchAll("SELECT email FROM users WHERE role = 'admin' AND email != ''");
            foreach ($admins as $admin) {
                $mailer->send($admin['email'], $subject, $body);
            }
        } catch (\Exception $e) {
            // Don't let email failures break notification flow
        }
    }

    private function sendAppriseIfEnabled(string $type, string $message): void
    {
        try {
            $apprise = new AppriseService();

            $labels = [
                'backup_failed' => 'Backup Failed',
                'agent_offline' => 'Client Offline',
                'storage_low' => 'Storage Low',
                'missed_schedule' => 'Missed Schedule',
            ];
            $title = '[BBS] ' . ($labels[$type] ?? ucfirst($type));

            // Use the new per-service event filtering
            $apprise->sendForEvent($type, $title, $message);
        } catch (\Exception $e) {
            // Don't let Apprise failures break notification flow
        }
    }

    public function resolve(string $type, ?int $agentId, ?int $referenceId): void
    {
        $params = [$type];
        $agentClause = $agentId !== null ? 'agent_id = ?' : 'agent_id IS NULL';
        if ($agentId !== null) $params[] = $agentId;
        $refClause = $referenceId !== null ? 'reference_id = ?' : 'reference_id IS NULL';
        if ($referenceId !== null) $params[] = $referenceId;

        $this->db->query(
            "UPDATE notifications SET resolved_at = NOW() WHERE type = ? AND {$agentClause} AND {$refClause} AND resolved_at IS NULL",
            $params
        );
    }

    public function markRead(int $id): void
    {
        $this->db->update('notifications', ['read_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
    }

    public function markAllRead(): void
    {
        $this->db->query("UPDATE notifications SET read_at = NOW() WHERE read_at IS NULL");
    }

    public function unreadCount(): int
    {
        $row = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM notifications WHERE read_at IS NULL AND resolved_at IS NULL");
        return (int) $row['cnt'];
    }

    public function getAll(int $limit = 50, int $offset = 0): array
    {
        return $this->db->fetchAll("
            SELECT n.*, a.name as agent_name
            FROM notifications n
            LEFT JOIN agents a ON a.id = n.agent_id
            ORDER BY
                CASE WHEN n.resolved_at IS NULL THEN 0 ELSE 1 END,
                n.last_occurred_at DESC
            LIMIT ? OFFSET ?
        ", [$limit, $offset]);
    }

    public function cleanup(): void
    {
        $setting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'notification_retention_days'");
        $days = (int) ($setting['value'] ?? 30);

        $this->db->query(
            "DELETE FROM notifications WHERE resolved_at IS NOT NULL AND resolved_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
    }
}
