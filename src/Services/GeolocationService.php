<?php
namespace WalletUpPro\Services;

if (!defined('ABSPATH')) {
    exit;
}

use WalletUpPro\Services\GeoIP\BundledGeoIPService;

class GeolocationService
{
    private static $instance = null;
    private $cache = [];
    private $cacheExpiry = 3600; // 1 hour cache
    private $bundledGeoIP = null;
    
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct()
    {
        $this->bundledGeoIP = new BundledGeoIPService();
    }
    
    /**
     * Enhanced geolocation that maintains backward compatibility
     * Returns array with detailed location data or just country code for compatibility
     * 
     * @param string $ip IP address to geolocate
     * @param bool $detailed Whether to return detailed location data (default: false for compatibility)
     * @return string|array|null Country code (string) for compatibility, or detailed array if requested
     */
    public function getLocationData($ip, $detailed = false)
    {
        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }
        
        $cacheKey = 'walup_geo_' . md5($ip . ($detailed ? '_detailed' : ''));
        $cached = $this->getCachedResult($cacheKey);
        if ($cached !== null) {
            if (!$detailed && is_array($cached)) {
                return isset($cached['country_code']) ? $cached['country_code'] : null;
            }
            return $cached;
        }
        
        $locationData = null;
        
        if ($this->bundledGeoIP) {
            $locationData = $this->bundledGeoIP->getLocation($ip);
        }
        
        if (!$locationData && extension_loaded('geoip')) {
            $locationData = $this->getLocationFromPHPGeoIP($ip);
        }
        
        if (!$locationData && function_exists('geoip_country_code_by_name')) {
            $countryCode = geoip_country_code_by_name($ip);
            if ($countryCode) {
                $locationData = [
                    'country_code' => $countryCode,
                    'country' => $this->getCountryName($countryCode),
                    'confidence' => 85,
                    'source' => 'geoip'
                ];
            }
        }
        
        if (!$locationData) {
            $locationData = $this->getLocationFromBuiltinRanges($ip);
        }
        
        if (!$locationData) {
            $locationData = $this->getLocationFromFreeAPIs($ip);
        }
        
        if ($locationData) {
            $this->setCachedResult($cacheKey, $locationData);
        }
        
        if (!$detailed) {
            if (is_array($locationData) && isset($locationData['country_code'])) {
                return $locationData['country_code'];
            }
            return null;
        }
        
        return $locationData;
    }
    
    public function getDetailedLocation($ip): ?array
    {
        return $this->getLocationData($ip, true);
    }
    
    private function getLocationFromBuiltinRanges($ip): ?array
    {
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return null;
        }
        
        $ranges = [
            ['start' => ip2long('3.0.0.0'), 'end' => ip2long('3.255.255.255'), 'country_code' => 'US', 'country' => 'United States'],
            ['start' => ip2long('4.0.0.0'), 'end' => ip2long('4.255.255.255'), 'country_code' => 'US', 'country' => 'United States'],
            ['start' => ip2long('8.8.8.0'), 'end' => ip2long('8.8.8.255'), 'country_code' => 'US', 'country' => 'United States'],
            ['start' => ip2long('23.0.0.0'), 'end' => ip2long('23.255.255.255'), 'country_code' => 'US', 'country' => 'United States'],
            
            ['start' => ip2long('24.0.0.0'), 'end' => ip2long('24.255.255.255'), 'country_code' => 'CA', 'country' => 'Canada'],
            ['start' => ip2long('99.224.0.0'), 'end' => ip2long('99.255.255.255'), 'country_code' => 'CA', 'country' => 'Canada'],
            
            ['start' => ip2long('25.0.0.0'), 'end' => ip2long('25.255.255.255'), 'country_code' => 'GB', 'country' => 'United Kingdom'],
            ['start' => ip2long('81.0.0.0'), 'end' => ip2long('81.255.255.255'), 'country_code' => 'GB', 'country' => 'United Kingdom'],
            
            ['start' => ip2long('46.0.0.0'), 'end' => ip2long('46.255.255.255'), 'country_code' => 'DE', 'country' => 'Germany'],
            ['start' => ip2long('78.0.0.0'), 'end' => ip2long('78.255.255.255'), 'country_code' => 'DE', 'country' => 'Germany'],
            
            ['start' => ip2long('80.10.0.0'), 'end' => ip2long('80.15.255.255'), 'country_code' => 'FR', 'country' => 'France'],
            ['start' => ip2long('82.64.0.0'), 'end' => ip2long('82.79.255.255'), 'country_code' => 'FR', 'country' => 'France'],
            
            ['start' => ip2long('1.128.0.0'), 'end' => ip2long('1.159.255.255'), 'country_code' => 'AU', 'country' => 'Australia'],
            ['start' => ip2long('14.0.0.0'), 'end' => ip2long('14.255.255.255'), 'country_code' => 'AU', 'country' => 'Australia'],
            
            ['start' => ip2long('1.0.0.0'), 'end' => ip2long('1.127.255.255'), 'country_code' => 'JP', 'country' => 'Japan'],
            ['start' => ip2long('27.0.0.0'), 'end' => ip2long('27.255.255.255'), 'country_code' => 'JP', 'country' => 'Japan'],
        ];
        
        foreach ($ranges as $range) {
            if ($ipLong >= $range['start'] && $ipLong <= $range['end']) {
                return [
                    'country_code' => $range['country_code'],
                    'country' => $range['country'],
                    'confidence' => 70,
                    'source' => 'builtin_ranges'
                ];
            }
        }
        
        return null;
    }
    
    private function getLocationFromFreeAPIs($ip): ?array
    {
        if (!$this->canMakeAPICall()) {
            return null;
        }
        
        $result = $this->tryIPAPI($ip);
        if ($result) {
            $this->recordAPICall();
            return $result;
        }
        
        return null;
    }
    
    private function tryIPAPI($ip): ?array
    {
        $url = "http://ip-api.com/json/{$ip}?fields=status,country,countryCode,region,regionName,city,timezone,isp";
        
        $response = wp_remote_get($url, [
            'timeout' => 3,
            'user-agent' => 'WalletUpPro-Plugin/1.0'
        ]);
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || $data['status'] !== 'success') {
            return null;
        }
        
        return [
            'country_code' => $data['countryCode'] ?? null,
            'country' => $data['country'] ?? null,
            'region' => $data['regionName'] ?? null,
            'region_code' => $data['region'] ?? null,
            'city' => $data['city'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'isp' => $data['isp'] ?? null,
            'confidence' => 80,
            'source' => 'ip-api'
        ];
    }
    
    private function canMakeAPICall(): bool
    {
        $apiCalls = get_transient('walup_api_calls_count') ?: 0;
        return $apiCalls < 100; // Max 100 API calls per hour
    }
    
    private function recordAPICall(): void
    {
        $apiCalls = get_transient('walup_api_calls_count') ?: 0;
        set_transient('walup_api_calls_count', $apiCalls + 1, 3600);
    }
    
    private function getCachedResult($key)
    {
        return get_transient($key) ?: null;
    }
    
    private function setCachedResult($key, $data): void
    {
        set_transient($key, $data, $this->cacheExpiry);
    }
    
    private function getCountryName($countryCode): string
    {
        $countries = [
            'US' => 'United States', 'CA' => 'Canada', 'GB' => 'United Kingdom',
            'FR' => 'France', 'DE' => 'Germany', 'IT' => 'Italy', 'ES' => 'Spain',
            'NL' => 'Netherlands', 'JP' => 'Japan', 'KR' => 'South Korea',
            'CN' => 'China', 'IN' => 'India', 'AU' => 'Australia', 'BR' => 'Brazil',
            'MX' => 'Mexico', 'AR' => 'Argentina', 'RU' => 'Russia', 'TR' => 'Turkey',
            'SA' => 'Saudi Arabia', 'AE' => 'United Arab Emirates', 'SG' => 'Singapore',
            'TH' => 'Thailand', 'MY' => 'Malaysia', 'PH' => 'Philippines',
            'ID' => 'Indonesia', 'VN' => 'Vietnam', 'TW' => 'Taiwan', 'HK' => 'Hong Kong',
            'ZA' => 'South Africa', 'NG' => 'Nigeria', 'EG' => 'Egypt', 'IL' => 'Israel',
            'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark', 'FI' => 'Finland',
            'AT' => 'Austria', 'CH' => 'Switzerland', 'BE' => 'Belgium', 'PL' => 'Poland',
            'CZ' => 'Czech Republic', 'GR' => 'Greece', 'PT' => 'Portugal',
            'IE' => 'Ireland', 'NZ' => 'New Zealand'
        ];
        
        return $countries[$countryCode] ?? $countryCode;
    }
    
    private function getLocationFromPHPGeoIP($ip): ?array
    {
        try {
            $locationData = [];
            
            if (function_exists('geoip_country_code_by_name')) {
                $locationData['country_code'] = geoip_country_code_by_name($ip);
                $locationData['country'] = $this->getCountryName($locationData['country_code']);
            }
            
            if (function_exists('geoip_region_by_name')) {
                $region = geoip_region_by_name($ip);
                if ($region) {
                    $locationData['region_code'] = $region['region'] ?? null;
                    if (function_exists('geoip_region_name_by_code') && $locationData['country_code'] && $locationData['region_code']) {
                        $locationData['region'] = geoip_region_name_by_code($locationData['country_code'], $locationData['region_code']);
                    }
                }
            }
            
            if (function_exists('geoip_record_by_name')) {
                $record = geoip_record_by_name($ip);
                if ($record) {
                    $locationData['city'] = $record['city'] ?? null;
                    $locationData['postal_code'] = $record['postal_code'] ?? null;
                    $locationData['latitude'] = $record['latitude'] ?? null;
                    $locationData['longitude'] = $record['longitude'] ?? null;
                    $locationData['dma_code'] = $record['dma_code'] ?? null;
                    $locationData['area_code'] = $record['area_code'] ?? null;
                    
                    if (!empty($record['country_code'])) {
                        $locationData['country_code'] = $record['country_code'];
                        $locationData['country'] = $record['country_name'] ?? $this->getCountryName($record['country_code']);
                    }
                    
                    if (empty($locationData['region']) && !empty($record['region'])) {
                        $locationData['region'] = $record['region'];
                    }
                }
            }
            
            if (function_exists('geoip_time_zone_by_country_and_region')) {
                if (!empty($locationData['country_code'])) {
                    $regionCode = $locationData['region_code'] ?? null;
                    $timezone = geoip_time_zone_by_country_and_region($locationData['country_code'], $regionCode);
                    if ($timezone) {
                        $locationData['timezone'] = $timezone;
                    }
                }
            }
            
            if (function_exists('geoip_isp_by_name')) {
                $isp = geoip_isp_by_name($ip);
                if ($isp) {
                    $locationData['isp'] = $isp;
                }
            } elseif (function_exists('geoip_org_by_name')) {
                $org = geoip_org_by_name($ip);
                if ($org) {
                    $locationData['isp'] = $org;
                }
            }
            
            $dataPoints = 0;
            if (!empty($locationData['country_code'])) $dataPoints++;
            if (!empty($locationData['region'])) $dataPoints++;
            if (!empty($locationData['city'])) $dataPoints++;
            if (!empty($locationData['isp'])) $dataPoints++;
            
            $locationData['confidence'] = min(95, 70 + ($dataPoints * 6));
            $locationData['source'] = 'php-geoip';
            
            if (empty($locationData['country_code'])) {
                return null;
            }
            
            return $locationData;
            
        } catch (\Exception $e) {
            if (function_exists('error_log')) {
            }
            return null;
        }
    }
    
    public function checkGeoIPStatus(): array
    {
        $status = [
            'extension_loaded' => extension_loaded('geoip'),
            'databases' => [],
            'functions' => [],
            'recommendations' => []
        ];
        
        $geoipFunctions = [
            'geoip_country_code_by_name' => 'Country Database',
            'geoip_region_by_name' => 'Region Database',
            'geoip_record_by_name' => 'City Database',
            'geoip_isp_by_name' => 'ISP Database',
            'geoip_org_by_name' => 'Organization Database'
        ];
        
        foreach ($geoipFunctions as $function => $database) {
            $status['functions'][$function] = function_exists($function);
            if (function_exists($function)) {
                $status['databases'][] = $database;
            }
        }
        
        if ($status['extension_loaded'] && function_exists('geoip_db_avail')) {
            $dbTypes = [
                GEOIP_COUNTRY_EDITION => 'Country',
                GEOIP_REGION_EDITION_REV0 => 'Region Rev0',
                GEOIP_REGION_EDITION_REV1 => 'Region Rev1',
                GEOIP_CITY_EDITION_REV0 => 'City Rev0',
                GEOIP_CITY_EDITION_REV1 => 'City Rev1',
                GEOIP_ISP_EDITION => 'ISP',
                GEOIP_ORG_EDITION => 'Organization'
            ];
            
            foreach ($dbTypes as $type => $name) {
                if (defined(get_class($type)) && geoip_db_avail($type)) {
                    $status['databases'][] = $name . ' (Available)';
                }
            }
        }
        
        if (!$status['extension_loaded']) {
            $status['recommendations'][] = 'Install PHP GeoIP extension: sudo yum install php-geoip (CentOS/RHEL) or sudo apt-get install php-geoip (Ubuntu/Debian)';
            $status['recommendations'][] = 'Install GeoIP databases: sudo yum install GeoIP-data (CentOS/RHEL) or sudo apt-get install geoip-database (Ubuntu/Debian)';
        } elseif (empty($status['databases'])) {
            $status['recommendations'][] = 'GeoIP extension is installed but databases are missing';
            $status['recommendations'][] = 'Install databases: sudo yum install GeoIP-data GeoIP-data-extra (CentOS/RHEL)';
        } elseif (count($status['databases']) < 3) {
            $status['recommendations'][] = 'Consider installing additional GeoIP databases for better accuracy';
            $status['recommendations'][] = 'City database provides the most detailed information';
        }
        
        return $status;
    }
}