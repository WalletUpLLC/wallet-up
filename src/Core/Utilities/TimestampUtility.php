<?php
namespace WalletUpPro\Core\Utilities;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class TimestampUtility
{
    public static function getCurrentUTCTimestamp(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
    
    public static function toUTCTimestamp($timestamp = null): string
    {
        if (is_null($timestamp)) {
            return self::getCurrentUTCTimestamp();
        }
        
        if (is_string($timestamp) && trim($timestamp) === '') {
            return self::getCurrentUTCTimestamp();
        }
        
        if ($timestamp === false || $timestamp === 0 || $timestamp === '0') {
            return self::getCurrentUTCTimestamp();
        }
        
        if ($timestamp instanceof \DateTime) {
            $date = clone $timestamp;
            $date->setTimezone(new \DateTimeZone('UTC'));
            return $date->format('Y-m-d H:i:s');
        }
        
        if (is_numeric($timestamp)) {
            $ts = (int)$timestamp;
            if ($ts > 0 && $ts < 4102444800) {
                return gmdate('Y-m-d H:i:s', $ts);
            }
        }
        
        if (is_string($timestamp)) {
            $timestamp = trim($timestamp);
            
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timestamp)) {
                try {
                    $date = new \DateTime($timestamp, new \DateTimeZone('UTC'));
                    return $date->format('Y-m-d H:i:s');
                } catch (\Exception $e) {
                }
            }
            
            if (strpos($timestamp, 'T') !== false || 
                strpos($timestamp, 'Z') !== false || 
                preg_match('/[+-]\d{2}:?\d{2}$/', $timestamp)) {
                try {
                    $date = new \DateTime($timestamp);
                    $date->setTimezone(new \DateTimeZone('UTC'));
                    return $date->format('Y-m-d H:i:s');
                } catch (\Exception $e) {
                }
            }
            
            if (preg_match('/\d{4}-\d{2}-\d{2}\s+\d{1,2}:\d{2}(?:\s*[AP]M)?/i', $timestamp)) {
                try {
                    $date = new \DateTime($timestamp);
                    $date->setTimezone(new \DateTimeZone('UTC'));
                    return $date->format('Y-m-d H:i:s');
                } catch (\Exception $e) {
                }
            }
            
            if (preg_match('/\b[A-Z]{3,4}\b/', $timestamp)) {
                try {
                    $date = new \DateTime($timestamp);
                    $date->setTimezone(new \DateTimeZone('UTC'));
                    return $date->format('Y-m-d H:i:s');
                } catch (\Exception $e) {
                }
            }
            
            try {
                $date = new \DateTime($timestamp);
                $date->setTimezone(new \DateTimeZone('UTC'));
                return $date->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                if (function_exists('error_log')) {
                }
            }
        }
        
        return self::getCurrentUTCTimestamp();
    }
    
    public static function getFutureUTCTimestamp(int $secondsFromNow): string
    {
        return gmdate('Y-m-d H:i:s', time() + $secondsFromNow);
    }
    
    /**
     * Format a timestamp for display based on user preferences
     * Enhanced to handle more edge cases
     * 
     * @param mixed $timestamp MySQL timestamp from database or any parseable format
     * @param string $format Format type ('mysql', 'human', 'relative', 'iso8601', 'iso', 'filename')
     * @param int|null $userId User ID for timezone preference (null for current user)
     * @return string Formatted timestamp
     */
    public static function formatTimestamp($timestamp, string $format = 'human', ?int $userId = null): string
    {
        if (empty($timestamp) || $timestamp === '0000-00-00 00:00:00') {
            return $format === 'relative' ? 'never' : 'N/A';
        }
        
        $timezone = self::getUserTimezone($userId);
        
        try {
            if ($timestamp instanceof \DateTime) {
                $date = clone $timestamp;
            } else {
                $date = new \DateTime($timestamp, new \DateTimeZone('UTC'));
            }
            
            $date->setTimezone(new \DateTimeZone($timezone));
            
            switch ($format) {
                case 'mysql':
                    return $date->format('Y-m-d H:i:s');
                    
                case 'human':
                    $dateFormat = get_option('date_format', 'Y-m-d');
                    $timeFormat = get_option('time_format', 'H:i:s');
                    return $date->format($dateFormat . ' ' . $timeFormat);
                    
                case 'relative':
                    return self::getRelativeTimeString($date);
                    
                case 'iso8601':
                case 'iso':
                    $date->setTimezone(new \DateTimeZone('UTC'));
                    return $date->format('Y-m-d\TH:i:s\Z');
                    
                case 'dayjs':
                    return $date->format('Y-m-d\TH:i:s.vP');
                    
                case 'filename':
                    return $date->format('Y-m-d_H-i-s');
                    
                default:
                    return $date->format($format);
            }
        } catch (\Exception $e) {
            if (function_exists('error_log')) {
            }
            
            if (is_string($timestamp) && preg_match('/\d{4}-\d{2}-\d{2}/', $timestamp)) {
                return $timestamp;
            }
            
            return $format === 'relative' ? 'unknown' : 'Invalid date';
        }
    }
    
    public static function getUserTimezone(?int $userId = null): string
    {
        if (is_null($userId) && function_exists('get_current_user_id')) {
            $userId = get_current_user_id();
        }
        
        if ($userId && function_exists('get_user_meta')) {
            $userTimezone = get_user_meta($userId, 'wallet_up_timezone', true);
            if (!empty($userTimezone) && self::isValidTimezone($userTimezone)) {
                return $userTimezone;
            }
        }
        
        if (function_exists('get_option')) {
            $siteTimezone = get_option('timezone_string');
            if (!empty($siteTimezone) && self::isValidTimezone($siteTimezone)) {
                return $siteTimezone;
            }
            
            $gmtOffset = get_option('gmt_offset');
            if ($gmtOffset !== false && is_numeric($gmtOffset)) {
                $hours = intval($gmtOffset);
                $minutes = abs(($gmtOffset - $hours) * 60);
                $sign = $gmtOffset >= 0 ? '+' : '-';
                return sprintf('Etc/GMT%s%d', $sign === '+' ? '-' : '+', abs($hours));
            }
        }
        
        return 'UTC';
    }
    
    private static function isValidTimezone(string $timezone): bool
    {
        try {
            new \DateTimeZone($timezone);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Get relative time string (e.g., "2 hours ago" or "in 3 days")
     * Enhanced with more natural language
     * 
     * @param \DateTime $date DateTime object
     * @return string Relative time string
     */
    private static function getRelativeTimeString(\DateTime $date): string
    {
        $now = new \DateTime('now', $date->getTimezone());
        $diff = $now->getTimestamp() - $date->getTimestamp();
        
        if (abs($diff) < 10) {
            return 'just now';
        }
        
        if ($diff < 0) {
            $diff = abs($diff);
            
            if ($diff < 60) {
                return 'in a few seconds';
            }
            
            if ($diff < 3600) {
                $minutes = round($diff / 60);
                return 'in ' . $minutes . ' minute' . ($minutes == 1 ? '' : 's');
            }
            
            if ($diff < 86400) {
                $hours = round($diff / 3600);
                return 'in ' . $hours . ' hour' . ($hours == 1 ? '' : 's');
            }
            
            if ($diff < 172800) { // Less than 2 days
                return 'tomorrow';
            }
            
            if ($diff < 604800) {
                $days = round($diff / 86400);
                return 'in ' . $days . ' day' . ($days == 1 ? '' : 's');
            }
            
            if ($diff < 2592000) {
                $weeks = round($diff / 604800);
                return 'in ' . $weeks . ' week' . ($weeks == 1 ? '' : 's');
            }
            
            if ($diff < 31536000) {
                $months = round($diff / 2592000);
                return 'in ' . $months . ' month' . ($months == 1 ? '' : 's');
            }
            
            $years = round($diff / 31536000);
            return 'in ' . $years . ' year' . ($years == 1 ? '' : 's');
        }
        
        if ($diff < 60) {
            return 'a few seconds ago';
        }
        
        if ($diff < 3600) {
            $minutes = round($diff / 60);
            return $minutes . ' minute' . ($minutes == 1 ? '' : 's') . ' ago';
        }
        
        if ($diff < 86400) {
            $hours = round($diff / 3600);
            return $hours . ' hour' . ($hours == 1 ? '' : 's') . ' ago';
        }
        
        if ($diff < 172800) { // Less than 2 days
            return 'yesterday';
        }
        
        if ($diff < 604800) {
            $days = round($diff / 86400);
            return $days . ' day' . ($days == 1 ? '' : 's') . ' ago';
        }
        
        if ($diff < 2592000) {
            $weeks = round($diff / 604800);
            return $weeks . ' week' . ($weeks == 1 ? '' : 's') . ' ago';
        }
        
        if ($diff < 31536000) {
            $months = round($diff / 2592000);
            return $months . ' month' . ($months == 1 ? '' : 's') . ' ago';
        }
        
        $years = round($diff / 31536000);
        return $years . ' year' . ($years == 1 ? '' : 's') . ' ago';
    }
    

    /**
     * Validates if a string is a valid timestamp
     * 
     * @param mixed $timestamp Timestamp to validate
     * @return bool True if valid, false otherwise
     */
    public static function isValidTimestamp($timestamp): bool
    {
        if (is_null($timestamp) || $timestamp === '') {
            return false;
        }
        
        if (is_numeric($timestamp)) {
            return true;
        }
        
        try {
            $date = new \DateTime($timestamp);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Convert a timestamp to ISO 8601 format compatible with Day.js
     * 
     * @param mixed $timestamp Timestamp to convert
     * @return string ISO 8601 formatted timestamp
     */
    public static function toISO8601($timestamp = null): string
    {
        if (is_null($timestamp)) {
            $timestamp = time();
        }
        
        if (is_numeric($timestamp)) {
            $date = new \DateTime('@' . $timestamp);
            $date->setTimezone(new \DateTimeZone('UTC'));
        } else {
            try {
                $date = new \DateTime($timestamp);
                $date->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Exception $e) {
                $date = new \DateTime();
                $date->setTimezone(new \DateTimeZone('UTC'));
            }
        }
        
        return $date->format('Y-m-d\TH:i:s\Z');
    }
    
    /**
     * Gets a timestamp in the format specifically designed for Day.js
     * 
     * @param mixed $timestamp The timestamp to format
     * @param string $timezone Timezone to use (defaults to UTC)
     * @return string Formatted timestamp for Day.js
     */
    public static function formatForDayJs($timestamp = null, string $timezone = 'UTC'): string
    {
        if (is_null($timestamp)) {
            $timestamp = time();
        }
        
        try {
            if (is_numeric($timestamp)) {
                $date = new \DateTime('@' . $timestamp);
                $date->setTimezone(new \DateTimeZone($timezone));
            } else {
                $date = new \DateTime($timestamp, new \DateTimeZone('UTC'));
                $date->setTimezone(new \DateTimeZone($timezone));
            }
            
            return $date->format('Y-m-d\TH:i:s.vP');
        } catch (\Exception $e) {
            $date = new \DateTime('now', new \DateTimeZone($timezone));
            return $date->format('Y-m-d\TH:i:s.vP');
        }
    }
    
    public static function addTimezoneMetadata(array $response): array
    {
        $response['_timezone'] = [
            'server' => date_default_timezone_get(),
            'storage' => 'UTC',
            'display' => self::getUserTimezone()
        ];
        
        return $response;
    }
}