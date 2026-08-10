<?php
/**
 * Repair for 103_push_delivery.sql.
 *
 * 103 dropped push_tokens' unique key without first adding an index for the
 * user_id foreign key, so MySQL refused it (error 1553). The migrator records
 * a migration as executed even when it throws — which keeps a bad file from
 * blocking every later one, but also means the statements after the failure
 * never ran and never retry. 103 is corrected for fresh installs; this brings
 * along anyone who already ran the broken version.
 *
 * Every step checks before it acts, so this is safe on a database where 103
 * fully applied, partially applied, or never ran.
 */

$has = function (string $sql, array $params = []) use ($db): bool {
    return !empty($db->fetchAll($sql, $params));
};

// The index the foreign key needs, before touching the unique key.
if (!$has("SHOW INDEX FROM push_tokens WHERE Key_name = 'idx_push_user'")) {
    $db->query("ALTER TABLE push_tokens ADD KEY idx_push_user (user_id)");
}

// One row per device.
if ($has("SHOW INDEX FROM push_tokens WHERE Key_name = 'unique_user_device'")) {
    $db->query("ALTER TABLE push_tokens DROP INDEX unique_user_device");
}
if (!$has("SHOW INDEX FROM push_tokens WHERE Key_name = 'unique_device'")) {
    // Collapse any duplicate device rows first, keeping the most recent —
    // the unique key can't be added over them.
    $db->query(
        "DELETE p1 FROM push_tokens p1
         JOIN push_tokens p2 ON p1.device_id = p2.device_id AND p1.id < p2.id"
    );
    $db->query("ALTER TABLE push_tokens ADD UNIQUE KEY unique_device (device_id)");
}

// The outbound queue.
$db->query("
    CREATE TABLE IF NOT EXISTS push_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id VARCHAR(64) NOT NULL,
        user_id INT NOT NULL,
        event VARCHAR(50) NOT NULL,
        job_id INT NOT NULL DEFAULT 0,
        client_id INT NOT NULL DEFAULT 0,
        attempts INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_pending (device_id, event, job_id),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB
");

// The push throttle stamp.
if (!$has("SHOW COLUMNS FROM notifications LIKE 'last_pushed_at'")) {
    $db->query("ALTER TABLE notifications ADD COLUMN last_pushed_at DATETIME DEFAULT NULL");
}
