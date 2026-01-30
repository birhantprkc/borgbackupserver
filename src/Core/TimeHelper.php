<?php

namespace BBS\Core;

class TimeHelper
{
    /**
     * Format a UTC timestamp for display in the user's timezone.
     */
    public static function format(string $utcTimestamp, string $format = 'Y-m-d H:i:s'): string
    {
        $dt = new \DateTime($utcTimestamp, new \DateTimeZone('UTC'));
        $dt->setTimezone(new \DateTimeZone(self::userTz()));
        return $dt->format($format);
    }

    /**
     * Return a relative "ago" string from a UTC timestamp (e.g. "5m ago").
     */
    public static function ago(string $utcTimestamp): string
    {
        $then = new \DateTime($utcTimestamp, new \DateTimeZone('UTC'));
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $diff = $now->getTimestamp() - $then->getTimestamp();

        if ($diff < 0) {
            return 'just now';
        }
        if ($diff < 60) {
            return $diff . 's ago';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . 'm ago';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . 'h ago';
        }
        return floor($diff / 86400) . 'd ago';
    }

    /**
     * Get the current user's timezone from session, defaulting to UTC.
     */
    public static function userTz(): string
    {
        return $_SESSION['timezone'] ?? 'America/New_York';
    }
}
