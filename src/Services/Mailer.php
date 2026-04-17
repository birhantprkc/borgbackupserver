<?php

namespace BBS\Services;

use BBS\Core\Database;

class Mailer
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;
    private bool $enabled;

    public function __construct()
    {
        $db = Database::getInstance();
        $settings = [];
        $rows = $db->fetchAll("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'smtp_%'");
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        $this->host = $settings['smtp_host'] ?? '';
        $this->port = (int) ($settings['smtp_port'] ?? 587);
        $this->username = $settings['smtp_user'] ?? '';
        // SMTP password is stored encrypted. Legacy plaintext values auto-upgrade:
        // if decrypt() throws, we treat the raw value as plaintext and re-save
        // it encrypted for next time.
        $rawPass = $settings['smtp_pass'] ?? '';
        $this->password = '';
        if ($rawPass !== '') {
            try {
                $this->password = Encryption::decrypt($rawPass);
            } catch (\Throwable $e) {
                $this->password = $rawPass;
                try {
                    $db->update('settings',
                        ['value' => Encryption::encrypt($rawPass)],
                        "`key` = ?", ['smtp_pass']);
                } catch (\Throwable $e2) { /* non-fatal */ }
            }
        }
        $this->fromEmail = $settings['smtp_from'] ?? $settings['smtp_from_email'] ?? '';
        $this->fromName = $settings['smtp_from_name'] ?? 'Borg Backup Server';
        $this->enabled = !empty($this->host) && !empty($this->fromEmail);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Send an email using SMTP with STARTTLS.
     */
    public function send(string $to, string $subject, string $body): bool
    {
        if (!$this->enabled) {
            return false;
        }

        try {
            $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 10);
            if (!$socket) {
                error_log("SMTP connect failed: {$errstr} ({$errno})");
                return false;
            }

            $this->readResponse($socket);
            $this->sendCommand($socket, "EHLO " . gethostname());

            // STARTTLS if port 587
            if ($this->port === 587) {
                $this->sendCommand($socket, "STARTTLS");
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                $this->sendCommand($socket, "EHLO " . gethostname());
            }

            // Auth
            if ($this->username) {
                $this->sendCommand($socket, "AUTH LOGIN");
                $this->sendCommand($socket, base64_encode($this->username));
                $this->sendCommand($socket, base64_encode($this->password));
            }

            $this->sendCommand($socket, "MAIL FROM:<{$this->fromEmail}>");
            $this->sendCommand($socket, "RCPT TO:<{$to}>");
            $this->sendCommand($socket, "DATA");

            $contentType = (stripos($body, '<') !== false && stripos($body, '>') !== false)
                ? 'text/html' : 'text/plain';

            $headers = "From: {$this->fromName} <{$this->fromEmail}>\r\n"
                     . "To: {$to}\r\n"
                     . "Subject: {$subject}\r\n"
                     . "MIME-Version: 1.0\r\n"
                     . "Content-Type: {$contentType}; charset=UTF-8\r\n"
                     . "Date: " . date('r') . "\r\n";

            fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
            $this->readResponse($socket);

            $this->sendCommand($socket, "QUIT");
            fclose($socket);

            return true;
        } catch (\Exception $e) {
            error_log("SMTP error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a backup failure notification.
     */
    public function notifyFailure(string $agentName, int $jobId, string $error): void
    {
        if (!$this->enabled) return;

        $db = Database::getInstance();

        // Get admin emails
        $admins = $db->fetchAll("SELECT email FROM users WHERE role = 'admin' AND email != ''");

        $subject = "[BBS] Backup Failed: {$agentName} (Job #{$jobId})";
        $body = "A backup job has failed.\n\n"
              . "Client: {$agentName}\n"
              . "Job ID: #{$jobId}\n"
              . "Time: " . date('Y-m-d H:i:s') . "\n\n"
              . "Error:\n{$error}\n\n"
              . "-- Borg Backup Server";

        foreach ($admins as $admin) {
            $this->send($admin['email'], $subject, $body);
        }
    }

    private function sendCommand($socket, string $command): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->readResponse($socket);
    }

    private function readResponse($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $response;
    }
}
