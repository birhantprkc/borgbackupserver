<?php

namespace BBS\Controllers\Api;

use BBS\Core\Controller;
use BBS\Services\CertificateService;

/**
 * The TLS certificate, over the API.
 *
 * Renewal runs on a timer and is usually invisible until it stops working, at
 * which point the first sign is a browser warning. These endpoints let a
 * monitoring system see the expiry date and act on it without shelling into
 * the server.
 */
class SslApiController extends Controller
{
    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * GET /api/v1/ssl
     *
     * `days_remaining` is the field to alert on. It is null when no
     * certificate is installed, which is not a fault — an install behind a
     * proxy that terminates TLS elsewhere has none and needs none.
     */
    public function show(): void
    {
        $this->requireApiToken();
        $status = (new CertificateService())->status();
        unset($status['raw']);

        // The contact address could be written but not read. It is an account
        // setting rather than a property of the certificate, so it is merged
        // here rather than pushed into CertificateService::status().
        $status['email'] = $this->db->fetchOne(
            "SELECT `value` FROM settings WHERE `key` = 'ssl_contact_email'"
        )['value'] ?? '';
        // TLS terminated elsewhere: the local certificate is not the one
        // clients see, and the server does not warn about it expiring.
        $status['external'] = ($this->db->fetchOne(
            "SELECT `value` FROM settings WHERE `key` = 'certificate_external'"
        )['value'] ?? '0') === '1';

        $this->json($status);
    }

    /**
     * PUT /api/v1/ssl/external — {"external": true}
     *
     * Marks this server's certificate as not the one clients see, which
     * turns the daily expiry check off and clears any warning it raised.
     */
    public function setExternal(): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();
        if (!array_key_exists('external', $input) || !is_bool($input['external'])) {
            $this->json(['error' => 'external must be true or false'], 422);
        }
        $this->db->query(
            "INSERT INTO settings (`key`, `value`) VALUES ('certificate_external', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$input['external'] ? '1' : '0']
        );
        if ($input['external']) {
            $notifications = new \BBS\Services\NotificationService();
            foreach ([0, 1, 2, 3, 7, 14, 29] as $threshold) {
                $notifications->resolve('certificate_expiring', null, $threshold);
            }
        }
        $this->db->query("DELETE FROM settings WHERE `key` = 'certificate_checked_on'");
        $this->json(['status' => 'ok', 'external' => (bool) $input['external']]);
    }

    /**
     * POST /api/v1/ssl/renew — {"force": false}
     *
     * certbot declines while more than 30 days remain and says so; that reply
     * is passed back rather than reported as a failure, because it is the
     * correct answer. `force` overrides it, and is rate-limited by Let's
     * Encrypt rather than by us.
     */
    public function renew(): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();
        $force = filter_var($input['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $svc = new CertificateService();
        $result = $svc->renew(null, $force);

        $this->db->insert('server_log', [
            'level' => $result['success'] ? 'info' : 'warning',
            'message' => 'Certificate renewal requested via API — '
                . ($result['success'] ? 'completed' : 'failed'),
        ]);

        $status = $svc->status();
        unset($status['raw']);

        $this->json([
            'status' => $result['success'] ? 'ok' : 'error',
            'output' => $result['output'],
            'certificate' => $status,
        ], $result['success'] ? 200 : 422);
    }

    /**
     * PUT /api/v1/ssl/email — {"email": "you@example.com"}
     *
     * The address Let's Encrypt sends expiry warnings to. An empty string
     * clears it, which is the same as certbot's
     * --register-unsafely-without-email.
     */
    public function setEmail(): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();

        if (!array_key_exists('email', $input)) {
            $this->json(['error' => 'email is required (empty string clears the contact address)'], 422);
        }

        $email = trim((string) $input['email']);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->json(['error' => 'email is not a valid address'], 422);
        }

        $result = (new CertificateService())->setContactEmail($email);
        if (!$result['success']) {
            $this->json(['error' => $result['output']], 422);
        }

        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => $email === ''
                ? 'Certificate contact address cleared via API'
                : "Certificate contact address set to {$email} via API",
        ]);

        $this->json(['status' => 'ok', 'email' => $email]);
    }
}
