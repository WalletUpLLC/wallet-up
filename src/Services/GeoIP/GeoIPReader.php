<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Binary random access file handle required for MaxMind GeoIP2 database format.
namespace WalletUpPro\Services\GeoIP;

if (!defined('ABSPATH')) {
    exit;
}

class GeoIPReader
{
    private $fileHandle;
    private $databaseFile;
    private $metadata;
    private $searchTreeSize;
    private $nodeCount;
    private $recordSize;
    private $nodeByteSize;
    private $ipVersion;
    
    const METADATA_START_MARKER = "\xab\xcd\xefMaxMind.com";
    const DATA_SECTION_SEPARATOR_SIZE = 16;
    
    public function __construct($databaseFile)
    {
        if (!file_exists($databaseFile)) {
            throw new \Exception(esc_html("GeoIP database file not found: {$databaseFile}"));
        }
        
        $this->databaseFile = $databaseFile;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Binary file handle required for MaxMind GeoIP database random access.
        $this->fileHandle = fopen($databaseFile, 'rb');

        if (!$this->fileHandle) {
            throw new \Exception(esc_html("Cannot open GeoIP database file"));
        }

        $this->loadMetadata();
    }

    public function __destruct()
    {
        if ($this->fileHandle) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($this->fileHandle);
        }
    }
    
    public function getLocation($ip)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        
        $ipNum = $this->ipToNum($ip);
        $isIPv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        
        $record = $this->findRecord($ipNum, $isIPv6);
        
        if (!$record) {
            return null;
        }
        
        return $this->extractLocationData($record);
    }
    
    private function loadMetadata()
    {
        fseek($this->fileHandle, -3000, SEEK_END);
        $tail = fread($this->fileHandle, 3000);
        
        $metadataStart = strrpos($tail, self::METADATA_START_MARKER);
        if ($metadataStart === false) {
            throw new \Exception(esc_html("Invalid GeoIP database format - metadata not found"));
        }
        
        fseek($this->fileHandle, -3000 + $metadataStart + strlen(self::METADATA_START_MARKER), SEEK_END);
        $metadataBytes = fread($this->fileHandle, 1000);
        
        $this->metadata = $this->decodeMetadata($metadataBytes);
        
        $this->ipVersion = $this->metadata['ip_version'] ?? 4;
        $this->recordSize = $this->metadata['record_size'] ?? 24;
        $this->nodeCount = $this->metadata['node_count'] ?? 0;
        $this->nodeByteSize = $this->recordSize / 4;
        $this->searchTreeSize = $this->nodeCount * $this->nodeByteSize;
    }
    
    private function decodeMetadata($data)
    {
        $metadata = [
            'ip_version' => 4,
            'record_size' => 24,
            'node_count' => 0,
            'database_type' => 'GeoLite2-Country'
        ];
        
        if (preg_match('/node_count.{0,10}(\d+)/s', $data, $matches)) {
            $metadata['node_count'] = intval($matches[1]);
        }
        
        if (strpos($data, 'City') !== false) {
            $metadata['database_type'] = 'GeoLite2-City';
        }
        
        return $metadata;
    }
    
    private function ipToNum($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            $parts = unpack('N*', $packed);
            $num = 0;
            foreach ($parts as $part) {
                $num = $num * (1 << 32) + $part;
            }
            return $num;
        } else {
            return ip2long($ip);
        }
    }
    
    private function findRecord($ipNum, $isIPv6)
    {
        $node = 0;
        $bitCount = $isIPv6 ? 128 : 32;
        
        for ($i = $bitCount - 1; $i >= 0; $i--) {
            if ($node >= $this->nodeCount) {
                break;
            }
            
            $bit = ($ipNum >> $i) & 1;
            $node = $this->readNode($node, $bit);
            
            if ($node == $this->nodeCount) {
                return null; // Not found
            }
            
            if ($node > $this->nodeCount) {
                return $this->readData($node);
            }
        }
        
        return null;
    }
    
    private function readNode($nodeNumber, $bit)
    {
        $offset = $nodeNumber * $this->nodeByteSize;
        fseek($this->fileHandle, $offset);
        
        if ($this->recordSize == 24) {
            $bytes = fread($this->fileHandle, 6);
            if (strlen($bytes) < 6) {
                return $this->nodeCount;
            }
            
            $node = unpack('N', "\x00" . substr($bytes, $bit ? 3 : 0, 3))[1];
        } else {
            $bytes = fread($this->fileHandle, $this->nodeByteSize * 2);
            $offset = $bit ? $this->nodeByteSize : 0;
            $node = unpack('N', substr($bytes, $offset, 4))[1];
        }
        
        return $node;
    }
    
    private function readData($pointer)
    {
        $offset = $pointer - $this->nodeCount - self::DATA_SECTION_SEPARATOR_SIZE;
        $dataOffset = $this->searchTreeSize + self::DATA_SECTION_SEPARATOR_SIZE + $offset;
        
        fseek($this->fileHandle, $dataOffset);
        $data = fread($this->fileHandle, 1000); // Read enough for country/city data
        
        return $this->decodeData($data);
    }
    
    private function decodeData($data)
    {
        $result = [];
        
        if (preg_match('/iso_code.{0,10}([A-Z]{2})/s', $data, $matches)) {
            $result['country_code'] = $matches[1];
        }
        
        if (preg_match('/names.{0,50}en.{0,10}([A-Za-z\s]+)/s', $data, $matches)) {
            $countryName = trim($matches[1]);
            if (strlen($countryName) > 2 && strlen($countryName) < 50) {
                $result['country'] = $countryName;
            }
        }
        
        if (preg_match('/city.{0,50}names.{0,50}en.{0,10}([A-Za-z\s\-]+)/s', $data, $matches)) {
            $cityName = trim($matches[1]);
            if (strlen($cityName) > 2 && strlen($cityName) < 50) {
                $result['city'] = $cityName;
            }
        }
        
        return $result;
    }
    
    private function extractLocationData($record)
    {
        if (empty($record)) {
            return null;
        }
        
        $location = [
            'country_code' => $record['country_code'] ?? null,
            'country' => $record['country'] ?? null,
            'city' => $record['city'] ?? null,
            'confidence' => 90,
            'source' => 'geolite2'
        ];
        
        return array_filter($location, function($value) {
            return $value !== null;
        });
    }
}