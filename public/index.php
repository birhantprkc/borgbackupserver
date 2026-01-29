<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Setup wizard: if .env doesn't exist, run the installer
if (!file_exists(dirname(__DIR__) . '/config/.env')) {
    require_once dirname(__DIR__) . '/src/Setup/SetupWizard.php';
    exit;
}

use BBS\Core\App;

$app = new App();
$app->run();
