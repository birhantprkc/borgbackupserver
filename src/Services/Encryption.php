<?php

namespace BBS\Services;

use BBS\Core\Config;

class Encryption
{
    private const CIPHER = 'aes-256-gcm';

    /**
     * Encrypt a plaintext value.
     * Returns base64-encoded string containing: nonce + tag + ciphertext.
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::getKey();
        $nonce = random_bytes(12);

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }

        // Pack: nonce (12) + tag (16) + ciphertext
        return base64_encode($nonce . $tag . $ciphertext);
    }

    /**
     * Decrypt a value encrypted by encrypt().
     */
    public static function decrypt(string $encoded): string
    {
        $key = self::getKey();
        $data = base64_decode($encoded, true);

        if ($data === false || strlen($data) < 28) {
            throw new \RuntimeException('Invalid encrypted data');
        }

        $nonce = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $ciphertext = substr($data, 28);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed');
        }

        return $plaintext;
    }

    private static function getKey(): string
    {
        $appKey = Config::get('APP_KEY', '');
        if (empty($appKey)) {
            throw new \RuntimeException('APP_KEY not set in .env — run: php -r "echo bin2hex(random_bytes(32));"');
        }

        // Derive a 32-byte key from APP_KEY
        return hash('sha256', $appKey, true);
    }

    /**
     * Generate a random APP_KEY.
     */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }
}
