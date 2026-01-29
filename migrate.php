#!/usr/bin/env php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use BBS\Core\Migrator;

$migrator = new Migrator();
$ran = $migrator->run();

if (empty($ran)) {
    echo "Nothing to migrate.\n";
} else {
    foreach ($ran as $file) {
        echo "Migrated: {$file}\n";
    }
    echo "Done.\n";
}
