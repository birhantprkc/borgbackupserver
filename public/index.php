<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Setup wizard: if .env doesn't exist, run the installer
if (!file_exists(dirname(__DIR__) . '/config/.env')) {
    require_once dirname(__DIR__) . '/src/Setup/SetupWizard.php';
    exit;
}

// Load environment before checking debug mode
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__) . '/config');
$dotenv->load();

// Check if debug mode is enabled and register Whoops error handler
try {
    $pdo = new PDO(
        'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';charset=utf8mb4',
        $_ENV['DB_USER'],
        $_ENV['DB_PASS']
    );
    $stmt = $pdo->query("SELECT `value` FROM settings WHERE `key` = 'debug_mode'");
    $debugMode = $stmt->fetchColumn() === '1';

    if ($debugMode) {
        $whoops = new \Whoops\Run;
        $whoops->pushHandler(new \Whoops\Handler\PrettyPageHandler);
        $whoops->register();
    }
} catch (Exception $e) {
    // If we can't connect to DB, skip debug mode check
}

use BBS\Core\App;

$app = new App();
$app->run();
