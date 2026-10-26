<?php
namespace WalletUpPro\Core\Security;

use WalletUpPro\Core\BaseSingleton;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class RateLimiter extends BaseSingleton
{
    /** @var array Rate limit configurations */
    private $limits = [];
    
    /** @var array In-memory cache for rate limit data */
    private $cache = [];

    protected function init(): void
    {
        $this->loadCacheData();
    }

    private function loadCacheData(): void
    {
        $cached_data = wp_cache_get('wallet_up_rate_limits', 'wallet_up_security');
        if ($cached_data) {
            $this->cache = $cached_data;
        }
        
        if (empty($this->cache)) {
            $this->cache = get_transient('wallet_up_rate_limits') ?: [];
        }
    }

    private function saveCacheData(): void
    {
        wp_cache_set('wallet_up_rate_limits', $this->cache, 'wallet_up_security', 300); // 5 minutes
        
        set_transient('wallet_up_rate_limits', $this->cache, 3600); // 1 hour
    }

    public function addLimit(string $action, int $requests, int $period): void
    {
        $this->limits[$action] = [
            'requests' => $requests,
            'period' => $period
        ];
    }

    public function checkLimit(string $key, int $max_requests, int $window_seconds): bool
    {
        $current_time = time();
        $window_start = $current_time - $window_seconds;
        
        if (!isset($this->cache[$key])) {
            $this->cache[$key] = [];
        }
        
        $this->cache[$key] = array_filter($this->cache[$key], function($timestamp) use ($window_start) {
            return $timestamp > $window_start;
        });
        
        if (count($this->cache[$key]) >= $max_requests) {
            return false;
        }
        
        $this->cache[$key][] = $current_time;
        
        $this->saveCacheData();
        
        return true;
    }

    public function getRemainingRequests(string $key, int $max_requests, int $window_seconds): int
    {
        $current_time = time();
        $window_start = $current_time - $window_seconds;
        
        if (!isset($this->cache[$key])) {
            return $max_requests;
        }
        
        $this->cache[$key] = array_filter($this->cache[$key], function($timestamp) use ($window_start) {
            return $timestamp > $window_start;
        });
        
        $used_requests = count($this->cache[$key]);
        return max(0, $max_requests - $used_requests);
    }

    public function getResetTime(string $key, int $window_seconds): int
    {
        if (!isset($this->cache[$key]) || empty($this->cache[$key])) {
            return time();
        }
        
        $oldest_timestamp = min($this->cache[$key]);
        return $oldest_timestamp + $window_seconds;
    }

    public function check(string $action, string $identifier): bool
    {
        if (!isset($this->limits[$action])) {
            return true; // No limit configured
        }

        $limit = $this->limits[$action];
        $key = "wallet_up_rate_{$action}_{$identifier}";

        return $this->checkLimit($key, $limit['requests'], $limit['period']);
    }

    public function resetLimit(string $key): void
    {
        unset($this->cache[$key]);
        $this->saveCacheData();
    }

    public function getRateLimitData(): array
    {
        return [
            'limits' => $this->limits,
            'cache_size' => count($this->cache),
            'cache_keys' => array_keys($this->cache)
        ];
    }

    public function cleanup(): void
    {
        $current_time = time();
        $max_age = 3600; // 1 hour
        
        foreach ($this->cache as $key => $timestamps) {
            if (is_array($timestamps)) {
                $this->cache[$key] = array_filter($timestamps, function($timestamp) use ($current_time, $max_age) {
                    return ($current_time - $timestamp) < $max_age;
                });
                
                if (empty($this->cache[$key])) {
                    unset($this->cache[$key]);
                }
            }
        }
        
        $this->saveCacheData();
    }

    public function getMultipleStatus(array $keys, int $max_requests, int $window_seconds): array
    {
        $status = [];
        
        foreach ($keys as $key) {
            $remaining = $this->getRemainingRequests($key, $max_requests, $window_seconds);
            $reset_time = $this->getResetTime($key, $window_seconds);
            
            $status[$key] = [
                'limit' => $max_requests,
                'remaining' => $remaining,
                'reset_time' => $reset_time,
                'reset_time_formatted' => gmdate('c', $reset_time)
            ];
        }
        
        return $status;
    }

    public function blockIP(string $ip, int $duration = 3600): void
    {
        $key = "blocked_ip_{$ip}";
        $this->cache[$key] = [time() + $duration];
        $this->saveCacheData();
    }

    public function isIPBlocked(string $ip): bool
    {
        $key = "blocked_ip_{$ip}";
        
        if (!isset($this->cache[$key])) {
            return false;
        }
        
        $block_until = $this->cache[$key][0] ?? 0;
        
        if (time() > $block_until) {
            unset($this->cache[$key]);
            $this->saveCacheData();
            return false;
        }
        
        return true;
    }

    public function getBlockedIPs(): array
    {
        $blocked = [];
        $current_time = time();
        
        foreach ($this->cache as $key => $data) {
            if (strpos($key, 'blocked_ip_') === 0) {
                $ip = substr($key, 11); // Remove 'blocked_ip_' prefix
                $block_until = $data[0] ?? 0;
                
                if ($current_time < $block_until) {
                    $blocked[$ip] = [
                        'blocked_until' => $block_until,
                        'remaining_seconds' => $block_until - $current_time
                    ];
                }
            }
        }
        
        return $blocked;
    }

    public function incrementCounter(string $action, string $identifier = ''): int
    {
        $key = "counter_{$action}" . ($identifier ? "_{$identifier}" : '');
        
        if (!isset($this->cache[$key])) {
            $this->cache[$key] = 0;
        }
        
        $this->cache[$key]++;
        $this->saveCacheData();
        
        return $this->cache[$key];
    }

    public function getCounter(string $action, string $identifier = ''): int
    {
        $key = "counter_{$action}" . ($identifier ? "_{$identifier}" : '');
        return $this->cache[$key] ?? 0;
    }

    public function resetCounter(string $action, string $identifier = ''): void
    {
        $key = "counter_{$action}" . ($identifier ? "_{$identifier}" : '');
        unset($this->cache[$key]);
        $this->saveCacheData();
    }
}