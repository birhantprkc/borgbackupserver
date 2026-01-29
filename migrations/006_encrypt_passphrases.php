<?php
/**
 * Migration: Encrypt existing plaintext passphrases.
 * This is a PHP migration (not SQL) — the migrator will need to handle .php files.
 * Run manually: php migrations/006_encrypt_passphrases.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

BBS\Core\Config::load();
$db = BBS\Core\Database::getInstance();

$repos = $db->fetchAll("SELECT id, passphrase_encrypted, encryption FROM repositories WHERE passphrase_encrypted IS NOT NULL");

$count = 0;
foreach ($repos as $repo) {
    $passphrase = $repo['passphrase_encrypted'];

    // Skip if already encrypted (base64 with nonce+tag is always > 40 chars)
    if (strlen($passphrase) > 40) {
        continue;
    }

    try {
        $encrypted = BBS\Services\Encryption::encrypt($passphrase);
        $db->update('repositories', ['passphrase_encrypted' => $encrypted], 'id = ?', [$repo['id']]);
        $count++;
        echo "Encrypted passphrase for repo #{$repo['id']}\n";
    } catch (Exception $e) {
        echo "Failed to encrypt repo #{$repo['id']}: {$e->getMessage()}\n";
    }
}

echo "Done. Encrypted {$count} passphrases.\n";
