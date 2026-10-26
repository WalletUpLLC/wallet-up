<?php
namespace WalletUpPro\Services\GeoIP;

if (!defined('ABSPATH')) {
    exit;
}

use GeoIp2\Database\Reader;
use MaxMind\Db\Reader\InvalidDatabaseException;
use Exception;

class BundledGeoIPService
{
    private $reader = null;
    private $databasePath;
    private $databaseType;
    
    const CACHE_KEY_PREFIX = 'walup_geoip_';
    const CACHE_DURATION = 3600; // 1 hour
    const DOWNLOAD_URL = 'https://download.maxmind.com/app/geoip_download';
    
    public function __construct()
    {
        $this->databasePath = plugin_dir_path(dirname(dirname(dirname(__DIR__)))) . 'data/geoip/';
        $this->initializeReader();
    }
    
    private function initializeReader(): void
    {
        $cityDb = $this->databasePath . 'GeoLite2-City.mmdb';
        $countryDb = $this->databasePath . 'GeoLite2-Country.mmdb';
        
        try {
            if (file_exists($cityDb)) {
                $this->reader = new Reader($cityDb);
                $this->databaseType = 'city';
            } elseif (file_exists($countryDb)) {
                $this->reader = new Reader($countryDb);
                $this->databaseType = 'country';
            } else {
                $this->reader = null;
            }
        } catch (InvalidDatabaseException $e) {
            $this->reader = null;
        } catch (Exception $e) {
            $this->reader = null;
        }
    }
    
    public function getLocation($ip): ?array
    {
        if (!$this->reader || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        
        $cacheKey = self::CACHE_KEY_PREFIX . md5($ip);
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            return $cached;
        }
        
        try {
            $locationData = [];
            
            if ($this->databaseType === 'city') {
                $record = $this->reader->city($ip);
                
                $locationData = [
                    'country_code' => $record->country->isoCode ?? null,
                    'country' => $record->country->name ?? null,
                    'region' => $record->subdivisions[0]->name ?? null,
                    'region_code' => $record->subdivisions[0]->isoCode ?? null,
                    'city' => $record->city->name ?? null,
                    'postal_code' => $record->postal->code ?? null,
                    'latitude' => $record->location->latitude ?? null,
                    'longitude' => $record->location->longitude ?? null,
                    'timezone' => $record->location->timeZone ?? null,
                    'accuracy_radius' => $record->location->accuracyRadius ?? null,
                    'confidence' => $this->calculateConfidence($record),
                    'source' => 'geolite2-city'
                ];
            } else {
                $record = $this->reader->country($ip);
                
                $locationData = [
                    'country_code' => $record->country->isoCode ?? null,
                    'country' => $record->country->name ?? null,
                    'confidence' => 85,
                    'source' => 'geolite2-country'
                ];
            }
            
            $locationData = array_filter($locationData, function($value) {
                return $value !== null;
            });
            
            set_transient($cacheKey, $locationData, self::CACHE_DURATION);
            
            return $locationData;
            
        } catch (Exception $e) {
            return null;
        }
    }
    
    private function calculateConfidence($record): int
    {
        $confidence = 70; // Base confidence
        
        if (!empty($record->country->isoCode)) $confidence += 5;
        if (!empty($record->subdivisions[0]->isoCode)) $confidence += 5;
        if (!empty($record->city->name)) $confidence += 10;
        if (!empty($record->postal->code)) $confidence += 5;
        if (!empty($record->location->accuracyRadius) && $record->location->accuracyRadius < 100) {
            $confidence += 5;
        }
        
        return min(95, $confidence);
    }
    
    /**
     * Download GeoLite2 database
     * Note: This requires a MaxMind license key
     */
    private function downloadDatabase(): bool
    {
        $licenseKey = get_option('walletup_maxmind_license_key');
        if (empty($licenseKey)) {
            return false;
        }
        
        if (!file_exists($this->databasePath)) {
            wp_mkdir_p($this->databasePath);
        }
        
        $cityUrl = self::DOWNLOAD_URL . '?edition_id=GeoLite2-City&license_key=' . $licenseKey . '&suffix=tar.gz';
        $cityFile = $this->databasePath . 'GeoLite2-City.tar.gz';
        
        $response = wp_remote_get($cityUrl, [
            'timeout' => 60,
            'stream' => true,
            'filename' => $cityFile
        ]);
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $this->extractDatabase($cityFile);
            wp_delete_file($cityFile); // Clean up archive
            
            $this->initializeReader();
            return true;
        }
        
        return false;
    }
    
    private function extractDatabase($archivePath): bool
    {
        if (!file_exists($archivePath)) {
            return false;
        }
        
        try {
            $phar = new \PharData($archivePath);
            
            $tempDir = $this->databasePath . 'temp/';
            $phar->extractTo($tempDir);
            
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tempDir)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'mmdb') {
                    $targetFile = $this->databasePath . $file->getBasename();
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic rename for GeoIP database extraction.
                    rename($file->getPathname(), $targetFile);
                    break;
                }
            }
            
            $this->removeDirectory($tempDir);
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    private function removeDirectory($dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : wp_delete_file($path);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing empty temp directory after extraction.
        rmdir($dir);
    }
    
    public function needsUpdate(): bool
    {
        $dbFile = $this->databasePath . 'GeoLite2-City.mmdb';
        if (!file_exists($dbFile)) {
            $dbFile = $this->databasePath . 'GeoLite2-Country.mmdb';
        }
        
        if (!file_exists($dbFile)) {
            return true;
        }
        
        $fileAge = time() - filemtime($dbFile);
        return $fileAge > (30 * 24 * 60 * 60);
    }
    
    public function getStatus(): array
    {
        $status = [
            'reader_initialized' => $this->reader !== null,
            'database_type' => $this->databaseType ?? 'none',
            'database_files' => [],
            'needs_update' => false,
            'has_license_key' => !empty(get_option('walletup_maxmind_license_key'))
        ];
        
        $files = ['GeoLite2-City.mmdb', 'GeoLite2-Country.mmdb'];
        foreach ($files as $file) {
            $path = $this->databasePath . $file;
            if (file_exists($path)) {
                $status['database_files'][] = [
                    'name' => $file,
                    'size' => filesize($path),
                    'modified' => gmdate('Y-m-d H:i:s', filemtime($path))
                ];
            }
        }
        
        $status['needs_update'] = $this->needsUpdate();
        
        return $status;
    }
}