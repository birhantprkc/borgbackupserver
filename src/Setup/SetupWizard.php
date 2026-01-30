<?php
/**
 * BBS Setup Wizard — self-contained installer that runs before the app is bootstrapped.
 * Activated when config/.env does not exist.
 *
 * This file handles its own session, routing, rendering, and DB operations
 * without depending on App, Config, Database, or Controller classes.
 */

session_start();

$basePath = dirname(__DIR__, 2);

// Safety: if .env already exists, redirect to the app
if (file_exists($basePath . '/config/.env')) {
    header('Location: /');
    exit;
}

// Handle form submissions and determine current step
$step = (int) ($_POST['step'] ?? $_GET['step'] ?? 1);
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case 2:
            // Database connection test
            $dbHost = trim($_POST['db_host'] ?? 'localhost');
            $dbName = trim($_POST['db_name'] ?? 'bbs');
            $dbUser = trim($_POST['db_user'] ?? 'root');
            $dbPass = $_POST['db_pass'] ?? '';

            if (empty($dbName) || empty($dbUser)) {
                $error = 'Database name and user are required.';
                break;
            }

            try {
                $pdo = new PDO(
                    "mysql:host={$dbHost};charset=utf8mb4",
                    $dbUser,
                    $dbPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );

                // Check if database exists, create if not
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$dbName}`");

                $_SESSION['setup'] = $_SESSION['setup'] ?? [];
                $_SESSION['setup']['db_host'] = $dbHost;
                $_SESSION['setup']['db_name'] = $dbName;
                $_SESSION['setup']['db_user'] = $dbUser;
                $_SESSION['setup']['db_pass'] = $dbPass;

                $step = 3;
            } catch (PDOException $e) {
                $error = 'Database connection failed: ' . $e->getMessage();
            }
            break;

        case 3:
            // Admin account
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $passwordConfirm = $_POST['password_confirm'] ?? '';

            if (empty($username) || empty($email) || empty($password)) {
                $error = 'All fields are required.';
                break;
            }
            if (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
                break;
            }
            if ($password !== $passwordConfirm) {
                $error = 'Passwords do not match.';
                break;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
                break;
            }

            $_SESSION['setup']['admin_username'] = $username;
            $_SESSION['setup']['admin_email'] = $email;
            $_SESSION['setup']['admin_password'] = $password;
            $step = 4;
            break;

        case 4:
            // Storage & server
            $storageLabel = trim($_POST['storage_label'] ?? 'Default');
            $storagePath = trim($_POST['storage_path'] ?? '');
            $serverHost = trim($_POST['server_host'] ?? '');

            if (empty($storagePath)) {
                $error = 'Storage path is required.';
                break;
            }
            if (empty($serverHost)) {
                $error = 'Server hostname is required for agent connections.';
                break;
            }

            $_SESSION['setup']['storage_label'] = $storageLabel;
            $_SESSION['setup']['storage_path'] = $storagePath;
            $_SESSION['setup']['server_host'] = $serverHost;
            $step = 5;
            break;

        case 5:
            // Execute installation
            $setup = $_SESSION['setup'] ?? [];
            if (empty($setup['db_host']) || empty($setup['admin_username'])) {
                $error = 'Session expired. Please start over.';
                $step = 1;
                break;
            }

            try {
                // 1. Generate APP_KEY
                $appKey = bin2hex(random_bytes(32));

                // 2. Connect to database
                $pdo = new PDO(
                    "mysql:host={$setup['db_host']};dbname={$setup['db_name']};charset=utf8mb4",
                    $setup['db_user'],
                    $setup['db_pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );

                // 3. Import schema.sql
                $schemaPath = $basePath . '/schema.sql';
                if (!file_exists($schemaPath)) {
                    throw new RuntimeException('schema.sql not found');
                }
                $schema = file_get_contents($schemaPath);

                // Remove the INSERT for default admin user — we'll create our own
                $schema = preg_replace(
                    "/INSERT INTO users.*?;\s*/s",
                    '',
                    $schema,
                    1 // Only remove the first INSERT INTO users
                );

                $pdo->exec($schema);

                // 4. Create admin user
                $passwordHash = password_hash($setup['admin_password'], PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
                $stmt->execute([$setup['admin_username'], $setup['admin_email'], $passwordHash]);

                // 5. Create storage location
                $stmt = $pdo->prepare("INSERT INTO storage_locations (label, path, is_default) VALUES (?, ?, 1)");
                $stmt->execute([$setup['storage_label'], $setup['storage_path']]);

                // 6. Set server_host in settings
                $stmt = $pdo->prepare("UPDATE settings SET `value` = ? WHERE `key` = 'server_host'");
                $stmt->execute([$setup['server_host']]);

                // 7. Create migrations table and mark all as executed
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS migrations (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        filename VARCHAR(255) NOT NULL UNIQUE,
                        executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )
                ");

                $migrationFiles = glob($basePath . '/migrations/*.sql');
                sort($migrationFiles);
                $stmt = $pdo->prepare("INSERT IGNORE INTO migrations (filename) VALUES (?)");
                foreach ($migrationFiles as $mf) {
                    $stmt->execute([basename($mf)]);
                }

                // 8. Write config/.env
                $envContent = <<<ENV
APP_NAME="Borg Backup Server"
APP_URL=https://{$setup['server_host']}
APP_ENV=production
APP_DEBUG=false

DB_HOST={$setup['db_host']}
DB_NAME={$setup['db_name']}
DB_USER={$setup['db_user']}
DB_PASS={$setup['db_pass']}

SESSION_LIFETIME=3600

APP_KEY={$appKey}
ENV;

                $configDir = $basePath . '/config';
                if (!is_dir($configDir)) {
                    mkdir($configDir, 0755, true);
                }
                file_put_contents($configDir . '/.env', $envContent);
                chmod($configDir . '/.env', 0600);

                // 9. Create storage directory if it doesn't exist
                $storagePath = $setup['storage_path'];
                if (!is_dir($storagePath)) {
                    @mkdir($storagePath, 0750, true);
                }

                // 10. Create borg cache directory
                if (!is_dir('/var/bbs/cache')) {
                    @mkdir('/var/bbs/cache', 0755, true);
                }
                if (!is_dir('/var/bbs/cache/www-data')) {
                    @mkdir('/var/bbs/cache/www-data', 0700, true);
                }

                // Clear setup session
                unset($_SESSION['setup']);

                $step = 6;
            } catch (Exception $e) {
                $error = 'Installation failed: ' . $e->getMessage();
                // Clean up .env if it was written but install failed
                @unlink($basePath . '/config/.env');
            }
            break;
    }
}

// Check system requirements for step 1
$requirements = [
    'php_version' => [
        'label' => 'PHP >= 8.1',
        'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'value' => PHP_VERSION,
    ],
    'pdo_mysql' => [
        'label' => 'PDO MySQL extension',
        'ok' => extension_loaded('pdo_mysql'),
        'value' => extension_loaded('pdo_mysql') ? 'Installed' : 'Missing',
    ],
    'mbstring' => [
        'label' => 'Mbstring extension',
        'ok' => extension_loaded('mbstring'),
        'value' => extension_loaded('mbstring') ? 'Installed' : 'Missing',
    ],
    'openssl' => [
        'label' => 'OpenSSL extension',
        'ok' => extension_loaded('openssl'),
        'value' => extension_loaded('openssl') ? 'Installed' : 'Missing',
    ],
    'config_writable' => [
        'label' => 'Config directory writable',
        'ok' => is_writable($basePath . '/config') || is_writable($basePath),
        'value' => is_writable($basePath . '/config') ? 'Writable' : 'Not writable',
    ],
];
$allRequirementsMet = !in_array(false, array_column($requirements, 'ok'));

// Check SSH helper for step 5 summary
$sshHelperInstalled = file_exists('/usr/local/bin/bbs-ssh-helper');

// Render the wizard view
$viewPath = $basePath . '/src/Views/';
require $viewPath . 'setup/wizard.php';
