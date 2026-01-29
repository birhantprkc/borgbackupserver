<?php

namespace BBS\Services;

class Cache
{
    private static ?Cache $instance = null;
    private ?\Memcached $mc = null;
    private bool $available = false;

    private function __construct()
    {
        if (!extension_loaded('memcached')) {
            return;
        }

        try {
            $this->mc = new \Memcached();
            $host = \BBS\Core\Config::get('MEMCACHED_HOST', '127.0.0.1');
            $port = (int) \BBS\Core\Config::get('MEMCACHED_PORT', '11211');
            $this->mc->addServer($host, $port);

            // Test connection
            $this->mc->get('_ping');
            if ($this->mc->getResultCode() !== \Memcached::RES_NOTFOUND
                && $this->mc->getResultCode() !== \Memcached::RES_SUCCESS) {
                $this->mc = null;
                return;
            }

            $this->available = true;
        } catch (\Exception $e) {
            $this->mc = null;
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function get(string $key): mixed
    {
        if (!$this->available) return null;
        $value = $this->mc->get($key);
        return $this->mc->getResultCode() === \Memcached::RES_SUCCESS ? $value : null;
    }

    public function set(string $key, mixed $value, int $ttl = 60): bool
    {
        if (!$this->available) return false;
        return $this->mc->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        if (!$this->available) return false;
        return $this->mc->delete($key);
    }

    public function flush(): bool
    {
        if (!$this->available) return false;
        return $this->mc->flush();
    }

    /**
     * Get or set: returns cached value, or calls $callback and caches result.
     */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }
}
