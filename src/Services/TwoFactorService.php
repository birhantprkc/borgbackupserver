<?php

namespace BBS\Services;

use BBS\Core\Database;
use OTPHP\TOTP;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class TwoFactorService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function generateSecret(): string
    {
        $totp = TOTP::generate();
        return $totp->getSecret();
    }

    public function generateQrCode(string $username, string $secret): string
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($username);
        $totp->setIssuer('Borg Backup Server');

        $renderer = new ImageRenderer(
            new RendererStyle(256),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);

        return $writer->writeString($totp->getProvisioningUri());
    }

    public function verifyTotp(string $secret, string $code): bool
    {
        $totp = TOTP::createFromSecret($secret);
        return $totp->verify($code, null, 1);
    }

    public function enableTotp(int $userId, string $secret): void
    {
        $encrypted = Encryption::encrypt($secret);
        $this->db->update('users', [
            'totp_secret' => $encrypted,
            'totp_enabled' => 1,
            'totp_enabled_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$userId]);
    }

    public function disableTotp(int $userId): void
    {
        $this->db->update('users', [
            'totp_secret' => null,
            'totp_enabled' => 0,
            'totp_enabled_at' => null,
        ], 'id = ?', [$userId]);
        $this->db->delete('recovery_codes', 'user_id = ?', [$userId]);
    }

    public function getUserSecret(int $userId): ?string
    {
        $user = $this->db->fetchOne(
            "SELECT totp_secret FROM users WHERE id = ? AND totp_enabled = 1",
            [$userId]
        );
        if (!$user || !$user['totp_secret']) {
            return null;
        }
        return Encryption::decrypt($user['totp_secret']);
    }

    public function generateRecoveryCodes(int $userId): array
    {
        $this->db->delete('recovery_codes', 'user_id = ?', [$userId]);

        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 4) . '-' . substr(bin2hex(random_bytes(4)), 0, 4));
            $codes[] = $code;
            $this->db->insert('recovery_codes', [
                'user_id' => $userId,
                'code_hash' => password_hash($code, PASSWORD_BCRYPT),
            ]);
        }

        return $codes;
    }

    public function verifyRecoveryCode(int $userId, string $code): bool
    {
        $allCodes = $this->db->fetchAll(
            "SELECT id, code_hash FROM recovery_codes WHERE user_id = ? AND used_at IS NULL",
            [$userId]
        );

        foreach ($allCodes as $row) {
            if (password_verify($code, $row['code_hash'])) {
                $this->db->update('recovery_codes', [
                    'used_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$row['id']]);
                return true;
            }
        }

        return false;
    }

    public function getRemainingRecoveryCodeCount(int $userId): int
    {
        return $this->db->count('recovery_codes', 'user_id = ? AND used_at IS NULL', [$userId]);
    }

    public function isEnabled(int $userId): bool
    {
        $user = $this->db->fetchOne("SELECT totp_enabled FROM users WHERE id = ?", [$userId]);
        return $user && $user['totp_enabled'] == 1;
    }
}
