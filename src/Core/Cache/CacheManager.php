<?php
namespace WalletUpPro\Core\Cache;


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class CacheManager
{
    private static $instance = null;
    private $prefix = 'walup_';
    private $defaultTTL = 3600;

    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get(string $key)
    {
        return wp_cache_get($this->prefix . $key);
    }

    public function set(string $key, $value, ?int $ttl = null): bool
    {
        return wp_cache_set(
            $this->prefix . $key,
            $value,
            '',
            $ttl ?? $this->defaultTTL
        );
    }

    public function delete(string $key): bool
    {
        return wp_cache_delete($this->prefix . $key);
    }

    public function clear(): bool
    {
        return wp_cache_flush();
    }
}