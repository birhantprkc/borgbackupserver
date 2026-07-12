<?php

namespace BBS\Services;

/**
 * Sends Wake-on-LAN magic packets to sleeping clients (#326).
 *
 * A magic packet is 6 bytes of 0xFF followed by the target's MAC address
 * repeated 16 times, sent as a UDP broadcast (conventionally port 9).
 * Only works when the BBS server is on the same network as the client.
 * Uses PHP's stream wrappers (with the so_broadcast socket option) so no
 * sockets extension is required.
 */
class WakeOnLanService
{
    /**
     * Normalize a MAC address to aa:bb:cc:dd:ee:ff. Accepts :, - or .
     * separators, or 12 bare hex digits. Returns null if invalid.
     */
    public static function normalizeMac(string $mac): ?string
    {
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $mac));
        if (strlen($hex) !== 12) {
            return null;
        }
        return implode(':', str_split($hex, 2));
    }

    /**
     * Guess the LAN's directed broadcast address from a client IP,
     * assuming a /24 (192.168.1.50 -> 192.168.1.255). Editable in the
     * client settings when the network is subnetted differently.
     */
    public static function defaultBroadcast(?string $ip): ?string
    {
        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        $parts = explode('.', $ip);
        $parts[3] = '255';
        return implode('.', $parts);
    }

    /**
     * Send a burst of magic packets. Returns true if at least one packet
     * was handed to the network stack.
     */
    public static function send(string $mac, string $broadcast, int $port = 9, int $count = 3): bool
    {
        $mac = self::normalizeMac($mac);
        if ($mac === null || empty($broadcast)) {
            return false;
        }

        $macBytes = '';
        foreach (explode(':', $mac) as $octet) {
            $macBytes .= chr((int) hexdec($octet));
        }
        $packet = str_repeat("\xff", 6) . str_repeat($macBytes, 16);

        $ctx = stream_context_create(['socket' => ['so_broadcast' => true]]);
        $sock = @stream_socket_client(
            "udp://{$broadcast}:{$port}",
            $errno,
            $errstr,
            2,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if (!$sock) {
            return false;
        }

        $sent = false;
        for ($i = 0; $i < $count; $i++) {
            if (@fwrite($sock, $packet) === strlen($packet)) {
                $sent = true;
            }
        }
        fclose($sock);
        return $sent;
    }
}
